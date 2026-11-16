<?php
/**
 * Pagina WooCommerce → Tickets: richieste da approvare, riepilogo per prodotto/giorno, ultimi
 * ingressi, elenco filtrabile dei biglietti ed export CSV. Scheda "Settings" per le impostazioni.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Dashboard {

	const PAGE     = 'dotmtickets';
	const CAP      = 'edit_shop_orders';
	const PER_PAGE = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_dotmtickets_export_csv', array( __CLASS__, 'export_csv' ) );
	}

	public static function menu() {
		$pending = function_exists( 'wc_orders_count' ) ? (int) wc_orders_count( Dotm_Tickets_Approval::STATUS ) : 0;
		$label   = __( 'Tickets', 'dotmtickets' ) . ( $pending ? ' <span class="awaiting-mod">' . $pending . '</span>' : '' );
		add_submenu_page( 'woocommerce', __( 'Tickets', 'dotmtickets' ), $label, self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Filtri correnti dalla query string.
	 */
	private static function filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sola lettura.
		return array(
			'product' => isset( $_GET['product'] ) ? absint( $_GET['product'] ) : 0,
			'status'  => isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '',
			's'       => isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : '',
			'paged'   => isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1,
		);
		// phpcs:enable
	}

	/**
	 * Clausola WHERE (con parametri già preparati) per i filtri.
	 */
	private static function where( $filters ) {
		global $wpdb;
		$checkins = Dotm_Tickets::checkins_table();
		$where    = array( '1=1' );

		if ( $filters['product'] ) {
			$where[] = $wpdb->prepare( 't.product_id = %d', $filters['product'] );
		}

		switch ( $filters['status'] ) {
			case 'valid':
				$where[] = $wpdb->prepare( 't.status = %s', Dotm_Tickets::STATUS_VALID );
				break;
			case 'cancelled':
				$where[] = $wpdb->prepare( 't.status = %s', Dotm_Tickets::STATUS_CANCELLED );
				break;
			case 'today':
				$where[] = $wpdb->prepare( "EXISTS (SELECT 1 FROM $checkins c WHERE c.ticket_id = t.id AND c.day = %s)", Dotm_Tickets::today() ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				break;
			case 'entered':
				$where[] = "EXISTS (SELECT 1 FROM $checkins c WHERE c.ticket_id = t.id)";
				break;
			case 'never':
				$where[] = $wpdb->prepare( 't.status = %s', Dotm_Tickets::STATUS_VALID ) . " AND NOT EXISTS (SELECT 1 FROM $checkins c WHERE c.ticket_id = t.id)";
				break;
		}

		if ( '' !== $filters['s'] ) {
			$or   = array();
			$code = strtolower( preg_replace( '/[\s-]+/', '', $filters['s'] ) );
			if ( preg_match( '/^[a-f0-9]{4,32}$/', $code ) ) {
				$or[] = $wpdb->prepare( 't.token LIKE %s', $wpdb->esc_like( $code ) . '%' );
			}
			// Numero d'ordine, nome, email… tramite la ricerca ordini di WooCommerce.
			$order_ids = function_exists( 'wc_order_search' ) ? array_map( 'absint', (array) wc_order_search( $filters['s'] ) ) : array();
			if ( ctype_digit( $filters['s'] ) ) {
				$order_ids[] = absint( $filters['s'] );
			}
			$order_ids = array_filter( array_unique( $order_ids ) );
			if ( $order_ids ) {
				$or[] = 't.order_id IN (' . implode( ',', $order_ids ) . ')';
			}
			$where[] = $or ? '(' . implode( ' OR ', $or ) . ')' : '1=0';
		}

		return implode( ' AND ', $where );
	}

	private static function query_tickets( $filters, $limit = null, $offset = 0 ) {
		global $wpdb;
		$table = Dotm_Tickets::table();
		$where = self::where( $filters );
		$sql   = "SELECT t.* FROM $table t WHERE $where ORDER BY t.id DESC";
		if ( $limit ) {
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );
		}
		return $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- parti già preparate.
	}

	private static function count_tickets( $filters ) {
		global $wpdb;
		$table = Dotm_Tickets::table();
		$where = self::where( $filters );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table t WHERE $where" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Ingressi raggruppati per biglietto: [ticket_id => [checkin, ...]].
	 */
	private static function checkins_for( $tickets ) {
		global $wpdb;
		if ( ! $tickets ) {
			return array();
		}
		$ids      = implode( ',', array_map( fn( $t ) => (int) $t->id, $tickets ) );
		$checkins = Dotm_Tickets::checkins_table();
		$grouped  = array();
		foreach ( $wpdb->get_results( "SELECT * FROM $checkins WHERE ticket_id IN ($ids) ORDER BY day, id" ) as $c ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$grouped[ (int) $c->ticket_id ][] = $c;
		}
		return $grouped;
	}

	/**
	 * Statistiche per prodotto: venduti, annullati, ingressi per giorno.
	 */
	private static function stats() {
		global $wpdb;
		$table    = Dotm_Tickets::table();
		$checkins = Dotm_Tickets::checkins_table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nomi di tabella.
		$products = array();
		foreach ( $wpdb->get_results( "SELECT product_id, status, COUNT(*) n FROM $table GROUP BY product_id, status" ) as $row ) {
			$products[ (int) $row->product_id ][ $row->status ] = (int) $row->n;
		}

		$by_day = array();
		$used   = array();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.product_id, c.day, COUNT(*) n FROM $checkins c JOIN $table t ON t.id = c.ticket_id WHERE t.status = %s GROUP BY t.product_id, c.day",
				Dotm_Tickets::STATUS_VALID
			)
		);
		foreach ( $rows as $row ) {
			$by_day[ (int) $row->product_id ][ $row->day ] = (int) $row->n;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.product_id, COUNT(*) n FROM $checkins c JOIN $table t ON t.id = c.ticket_id WHERE t.status = %s GROUP BY t.product_id",
				Dotm_Tickets::STATUS_VALID
			)
		);
		foreach ( $rows as $row ) {
			$used[ (int) $row->product_id ] = (int) $row->n;
		}

		$today  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $checkins WHERE day = %s", Dotm_Tickets::today() ) );
		$latest = $wpdb->get_results( "SELECT c.*, t.token, t.product_id, t.order_id FROM $checkins c JOIN $table t ON t.id = c.ticket_id ORDER BY c.checked_in_at DESC, c.id DESC LIMIT 10" );
		// phpcs:enable

		return compact( 'products', 'by_day', 'used', 'today', 'latest' );
	}

	private static function product_name( $product_id ) {
		static $cache = array();
		if ( ! isset( $cache[ $product_id ] ) ) {
			$product              = wc_get_product( $product_id );
			$cache[ $product_id ] = $product ? $product->get_name() : '#' . $product_id;
		}
		return $cache[ $product_id ];
	}

	private static function user_name( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;
		return $user ? $user->display_name : '';
	}

	private static function bar( $done, $total ) {
		$pct = $total ? min( 100, round( $done / $total * 100 ) ) : 0;
		return '<div class="dmt-bar"><span style="width:' . (int) $pct . '%"></span></div>'
			. '<div class="dmt-bar-label"><strong>' . (int) $done . '</strong> / ' . (int) $total . ' <span>(' . (int) $pct . '%)</span></div>';
	}

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'overview';
		$base = admin_url( 'admin.php?page=' . self::PAGE );
		?>
		<div class="wrap dmt-dash">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Tickets', 'dotmtickets' ); ?></h1>
			<a class="page-title-action" href="<?php echo esc_url( Dotm_Tickets_Scanner::scanner_url() ); ?>" target="_blank"><?php esc_html_e( 'Open scanner', 'dotmtickets' ); ?></a>
			<?php if ( 'overview' === $tab ) : ?>
				<label class="dmt-refresh"><input type="checkbox" id="dmt-autorefresh"> <?php esc_html_e( 'Refresh automatically every 30 seconds', 'dotmtickets' ); ?></label>
			<?php endif; ?>
			<hr class="wp-header-end">

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $base ); ?>" class="nav-tab <?php echo 'overview' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Overview', 'dotmtickets' ); ?></a>
				<?php if ( current_user_can( Dotm_Tickets_Settings::CAP ) ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $base ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'dotmtickets' ); ?></a>
				<?php endif; ?>
			</nav>

			<?php
			if ( 'settings' === $tab ) {
				settings_errors();
				Dotm_Tickets_Settings::render();
			} else {
				self::render_overview( $base );
			}
			?>
		</div>
		<?php
	}

	private static function render_overview( $base ) {
		$filters = self::filters();
		$stats   = self::stats();
		$total   = self::count_tickets( $filters );
		$tickets = self::query_tickets( $filters, self::PER_PAGE, ( $filters['paged'] - 1 ) * self::PER_PAGE );
		$entries = self::checkins_for( $tickets );
		$today   = Dotm_Tickets::today();
		$color   = Dotm_Tickets_Settings::color();

		$pending   = Dotm_Tickets_Approval::pending_orders();
		$act_label = Dotm_Tickets_Settings::text( 'approval_activity_label' );
		$tax_label = Dotm_Tickets_Settings::text( 'approval_tax_label' );
		$sold      = 0;
		$cancelled = 0;
		foreach ( $stats['products'] as $counts ) {
			$sold      += $counts[ Dotm_Tickets::STATUS_VALID ] ?? 0;
			$cancelled += $counts[ Dotm_Tickets::STATUS_CANCELLED ] ?? 0;
		}
		?>
		<?php if ( $pending ) : ?>
			<?php /* translators: %d: number of requests */ ?>
			<h2><?php echo esc_html( sprintf( __( 'Requests awaiting approval (%d)', 'dotmtickets' ), count( $pending ) ) ); ?></h2>
			<table class="widefat striped dmt-table dmt-pending">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Order', 'dotmtickets' ); ?></th>
						<th><?php esc_html_e( 'Date', 'dotmtickets' ); ?></th>
						<th><?php esc_html_e( 'Applicant', 'dotmtickets' ); ?></th>
						<th><?php echo esc_html( $act_label ); ?></th>
						<th><?php echo esc_html( $tax_label ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
				<?php
				foreach ( $pending as $order ) :
					$data = Dotm_Tickets_Approval::get_data( $order );
					?>
					<tr>
						<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( Dotm_Tickets::date_format( 'datetime' ) ) : '' ); ?></td>
						<td>
							<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?><?php echo $order->get_billing_company() ? ' · ' . esc_html( $order->get_billing_company() ) : ''; ?><br>
							<span class="dmt-muted"><?php echo esc_html( $order->get_billing_email() ); ?><?php echo $order->get_billing_phone() ? ' · ' . esc_html( $order->get_billing_phone() ) : ''; ?></span>
						</td>
						<td><?php echo esc_html( $data['activity'] ); ?></td>
						<td><code><?php echo esc_html( $data['tax'] ); ?></code></td>
						<td class="dmt-actions">
							<a class="button button-primary" href="<?php echo esc_url( Dotm_Tickets_Approval::action_url( $order, 'confirm' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Approve the request? The email with the ticket will be sent.', 'dotmtickets' ) ); ?>');"><?php esc_html_e( 'Approve', 'dotmtickets' ); ?></a>
							<a class="button" href="<?php echo esc_url( Dotm_Tickets_Approval::action_url( $order, 'reject' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Reject the request? The order will be cancelled.', 'dotmtickets' ) ); ?>');"><?php esc_html_e( 'Reject', 'dotmtickets' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div class="dmt-cards">
			<div class="dmt-card dmt-card-hl">
				<div class="dmt-kpi"><?php echo (int) $stats['today']; ?></div>
				<?php /* translators: %s: today's date */ ?>
				<div class="dmt-kpi-label"><?php echo esc_html( sprintf( __( 'Check-ins today (%s)', 'dotmtickets' ), Dotm_Tickets::format_day( $today, Dotm_Tickets::date_format( 'short_day' ) ) ) ); ?></div>
			</div>
			<div class="dmt-card">
				<div class="dmt-kpi"><?php echo (int) $sold; ?></div>
				<div class="dmt-kpi-label"><?php esc_html_e( 'Valid tickets', 'dotmtickets' ); ?></div>
			</div>
			<div class="dmt-card">
				<div class="dmt-kpi"><?php echo (int) $cancelled; ?></div>
				<div class="dmt-kpi-label"><?php esc_html_e( 'Cancelled tickets', 'dotmtickets' ); ?></div>
			</div>
		</div>

		<div class="dmt-cols">
			<div class="dmt-col-main">
				<h2><?php esc_html_e( 'By product', 'dotmtickets' ); ?></h2>
				<?php if ( ! $stats['products'] ) : ?>
					<p><?php esc_html_e( 'No tickets issued yet. Check "QR ticket" on a simple product to start selling tickets.', 'dotmtickets' ); ?></p>
				<?php endif; ?>
				<div class="dmt-cards">
				<?php
				foreach ( $stats['products'] as $product_id => $counts ) :
					$valid  = $counts[ Dotm_Tickets::STATUS_VALID ] ?? 0;
					$dates  = Dotm_Tickets_Product::valid_dates( $product_id );
					$people = Dotm_Tickets_Product::people( $product_id );
					$parts  = array(
						/* translators: %d: number of tickets */
						sprintf( _n( '%d valid', '%d valid', $valid, 'dotmtickets' ), $valid ),
					);
					if ( ! empty( $counts[ Dotm_Tickets::STATUS_CANCELLED ] ) ) {
						/* translators: %d: number of tickets */
						$parts[] = sprintf( _n( '%d cancelled', '%d cancelled', $counts[ Dotm_Tickets::STATUS_CANCELLED ], 'dotmtickets' ), $counts[ Dotm_Tickets::STATUS_CANCELLED ] );
					}
					$parts[] = Dotm_Tickets::people_label( (bool) $dates, $people );
					?>
					<div class="dmt-card dmt-product">
						<h3><a href="<?php echo esc_url( add_query_arg( 'product', $product_id, $base ) ); ?>"><?php echo esc_html( self::product_name( $product_id ) ); ?></a></h3>
						<p class="dmt-muted"><?php echo esc_html( implode( ' · ', $parts ) ); ?></p>
						<?php if ( $dates ) : ?>
							<?php foreach ( $dates as $day ) : ?>
								<div class="dmt-day<?php echo $day === $today ? ' is-today' : ''; ?>">
									<div class="dmt-day-name"><?php echo esc_html( ucfirst( Dotm_Tickets::format_day( $day, Dotm_Tickets::date_format( 'short_day' ) ) ) ); ?><?php echo $day === $today ? ' · ' . esc_html__( 'today', 'dotmtickets' ) : ''; ?></div>
									<?php echo self::bar( $stats['by_day'][ $product_id ][ $day ] ?? 0, $valid * $people ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								</div>
							<?php endforeach; ?>
						<?php else : ?>
							<div class="dmt-day">
								<div class="dmt-day-name"><?php esc_html_e( 'Used', 'dotmtickets' ); ?></div>
								<?php echo self::bar( $stats['used'][ $product_id ] ?? 0, $valid * $people ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</div>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				</div>
			</div>

			<div class="dmt-col-side">
				<h2><?php esc_html_e( 'Latest check-ins', 'dotmtickets' ); ?></h2>
				<div class="dmt-card">
					<?php if ( ! $stats['latest'] ) : ?>
						<p class="dmt-muted"><?php esc_html_e( 'No check-ins yet.', 'dotmtickets' ); ?></p>
					<?php else : ?>
						<table class="dmt-latest">
							<?php foreach ( $stats['latest'] as $c ) : ?>
								<tr>
									<td class="dmt-time"><?php echo esc_html( $c->day === $today ? get_date_from_gmt( $c->checked_in_at, 'H:i' ) : Dotm_Tickets::format_gmt( $c->checked_in_at, 'short_time' ) ); ?></td>
									<td>
										<code><?php echo esc_html( Dotm_Tickets::short_code( $c->token ) ); ?></code>
										<?php echo esc_html( self::product_name( (int) $c->product_id ) ); ?><br>
										<span class="dmt-muted"><?php echo esc_html( self::user_name( $c->checked_in_by ) ); ?></span>
									</td>
								</tr>
							<?php endforeach; ?>
						</table>
					<?php endif; ?>
				</div>
			</div>
		</div>

		<h2><?php esc_html_e( 'All tickets', 'dotmtickets' ); ?></h2>
		<form method="get" class="dmt-filters">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
			<select name="product">
				<option value="0"><?php esc_html_e( 'All products', 'dotmtickets' ); ?></option>
				<?php foreach ( array_keys( $stats['products'] ) as $product_id ) : ?>
					<option value="<?php echo (int) $product_id; ?>" <?php selected( $filters['product'], $product_id ); ?>><?php echo esc_html( self::product_name( $product_id ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="status">
				<?php
				$statuses = array(
					''          => __( 'All statuses', 'dotmtickets' ),
					'valid'     => __( 'Valid', 'dotmtickets' ),
					'cancelled' => __( 'Cancelled', 'dotmtickets' ),
					'today'     => __( 'Checked in today', 'dotmtickets' ),
					'entered'   => __( 'Checked in at least once', 'dotmtickets' ),
					'never'     => __( 'Valid, never checked in', 'dotmtickets' ),
				);
				foreach ( $statuses as $value => $label ) :
					?>
					<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="search" name="s" value="<?php echo esc_attr( $filters['s'] ); ?>" placeholder="<?php esc_attr_e( 'Code, order number, name or email', 'dotmtickets' ); ?>">
			<button class="button"><?php esc_html_e( 'Filter', 'dotmtickets' ); ?></button>
			<?php if ( $filters['product'] || $filters['status'] || $filters['s'] ) : ?>
				<a class="button-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Reset filters', 'dotmtickets' ); ?></a>
			<?php endif; ?>
			<?php
			$export = wp_nonce_url(
				add_query_arg(
					array_filter(
						array(
							'action'  => 'dotmtickets_export_csv',
							'product' => $filters['product'],
							'status'  => $filters['status'],
							's'       => $filters['s'],
						)
					),
					admin_url( 'admin-post.php' )
				),
				'dotmtickets_export_csv'
			);
			?>
			<a class="button dmt-export" href="<?php echo esc_url( $export ); ?>"><?php esc_html_e( 'Export CSV', 'dotmtickets' ); ?></a>
		</form>

		<?php /* translators: %d: number of tickets */ ?>
		<p class="dmt-muted"><?php echo esc_html( sprintf( _n( '%d ticket', '%d tickets', $total, 'dotmtickets' ), $total ) ); ?></p>

		<table class="widefat striped dmt-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Code', 'dotmtickets' ); ?></th>
					<th><?php esc_html_e( 'Product', 'dotmtickets' ); ?></th>
					<th><?php esc_html_e( 'Order', 'dotmtickets' ); ?></th>
					<th><?php esc_html_e( 'Buyer', 'dotmtickets' ); ?></th>
					<th><?php esc_html_e( 'Status', 'dotmtickets' ); ?></th>
					<th><?php esc_html_e( 'Check-ins', 'dotmtickets' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( ! $tickets ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No tickets found.', 'dotmtickets' ); ?></td></tr>
			<?php endif; ?>
			<?php
			foreach ( $tickets as $ticket ) :
				$order          = wc_get_order( $ticket->order_id );
				$dates          = Dotm_Tickets_Product::valid_dates( $ticket->product_id );
				$ticket_entries = $entries[ (int) $ticket->id ] ?? array();
				?>
				<tr>
					<td><code title="<?php echo esc_attr( $ticket->token ); ?>"><?php echo esc_html( Dotm_Tickets::short_code( $ticket->token ) ); ?></code></td>
					<td><?php echo esc_html( self::product_name( (int) $ticket->product_id ) ); ?></td>
					<td>
						<?php if ( $order ) : ?>
							<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
							<br><span class="dmt-muted"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
						<?php else : ?>
							#<?php echo (int) $ticket->order_id; ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $order ) : ?>
							<?php echo esc_html( $order->get_formatted_billing_full_name() ); ?><br>
							<span class="dmt-muted"><?php echo esc_html( $order->get_billing_email() ); ?></span>
							<?php
							$data = Dotm_Tickets_Product::requires_approval( (int) $ticket->product_id ) ? Dotm_Tickets_Approval::get_data( $order ) : null;
							if ( $data && ( $data['activity'] || $data['tax'] ) ) :
								?>
								<br><span class="dmt-muted"><?php echo esc_html( implode( ' · ', array_filter( array( $data['activity'], $data['tax'] ) ) ) ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( Dotm_Tickets::STATUS_VALID === $ticket->status ) : ?>
							<span class="dmt-badge dmt-ok"><?php esc_html_e( 'Valid', 'dotmtickets' ); ?></span>
						<?php else : ?>
							<span class="dmt-badge dmt-ko"><?php esc_html_e( 'Cancelled', 'dotmtickets' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						$people = Dotm_Tickets_Product::people( (int) $ticket->product_id );
						if ( $dates ) {
							// Un "chip" per ciascun giorno di validità (con le persone entrate, se più di una ammessa).
							$by_day = array();
							foreach ( $ticket_entries as $c ) {
								$by_day[ $c->day ][] = $c;
							}
							foreach ( $dates as $day ) {
								$list  = $by_day[ $day ] ?? array();
								$c     = $list ? end( $list ) : null;
								$label = Dotm_Tickets::format_day( $day, Dotm_Tickets::date_format( 'short' ) )
									. ( $c ? ' ✓ ' . ( $people > 1 ? count( $list ) . '/' . $people . ' ' : '' ) . get_date_from_gmt( $c->checked_in_at, 'H:i' ) : '' );
								$title = $c ? implode( ', ', array_unique( array_map( fn( $e ) => self::user_name( $e->checked_in_by ), $list ) ) ) : __( 'Not checked in', 'dotmtickets' );
								echo '<span class="dmt-chip' . ( $c ? ' is-in' : '' ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span> ';
							}
						} elseif ( $ticket_entries ) {
							$c = end( $ticket_entries );
							echo '<span class="dmt-chip is-in" title="' . esc_attr( self::user_name( $c->checked_in_by ) ) . '">✓ ' . esc_html( ( $people > 1 ? count( $ticket_entries ) . '/' . $people . ' ' : '' ) . Dotm_Tickets::format_gmt( $c->checked_in_at, 'short_time' ) ) . '</span>';
						} else {
							echo '<span class="dmt-muted">—</span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'current' => $filters['paged'],
					'total'   => $pages,
				)
			);
			echo '</div></div>';
		}
		?>

		<style>
			.dmt-dash .dmt-refresh { margin-left: 12px; vertical-align: middle; }
			.dmt-dash .nav-tab-wrapper { margin-bottom: 8px; }
			.dmt-cards { display: flex; flex-wrap: wrap; gap: 16px; margin: 12px 0 20px; }
			.dmt-card { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 16px 20px; min-width: 180px; box-sizing: border-box; }
			.dmt-card-hl { border-color: <?php echo esc_attr( $color ); ?>; border-width: 2px; }
			.dmt-kpi { font-size: 36px; font-weight: 700; line-height: 1.1; }
			.dmt-kpi-label { color: #646970; margin-top: 4px; }
			.dmt-cols { display: flex; flex-wrap: wrap; gap: 24px; }
			.dmt-col-main { flex: 2 1 520px; }
			.dmt-col-side { flex: 1 1 280px; }
			.dmt-col-side .dmt-card { margin-top: 12px; }
			.dmt-product { flex: 1 1 280px; }
			.dmt-product h3 { margin: 0 0 4px; font-size: 15px; }
			.dmt-product h3 a { text-decoration: none; }
			.dmt-day { margin-top: 12px; }
			.dmt-day.is-today .dmt-day-name { color: #1e8e3e; font-weight: 700; }
			.dmt-day-name { font-weight: 600; margin-bottom: 4px; }
			.dmt-bar { height: 10px; background: #f0f0f1; border-radius: 5px; overflow: hidden; }
			.dmt-bar span { display: block; height: 100%; background: #1e8e3e; }
			.dmt-bar-label { font-size: 13px; margin-top: 3px; }
			.dmt-bar-label span, .dmt-muted { color: #646970; }
			.dmt-latest { width: 100%; border-collapse: collapse; }
			.dmt-latest td { padding: 6px 4px; border-bottom: 1px solid #f0f0f1; vertical-align: top; }
			.dmt-latest tr:last-child td { border-bottom: 0; }
			.dmt-time { font-weight: 700; white-space: nowrap; width: 1%; }
			.dmt-filters { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
			.dmt-filters input[type=search] { min-width: 240px; }
			.dmt-filters .dmt-export { margin-left: auto; }
			.dmt-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; font-weight: 600; }
			.dmt-ok { background: #e6f4ea; color: #1e6b34; }
			.dmt-ko { background: #fce8e6; color: #a50e0e; }
			.dmt-chip { display: inline-block; padding: 2px 8px; margin: 1px 0; border-radius: 10px; font-size: 12px; background: #f0f0f1; color: #646970; white-space: nowrap; }
			.dmt-chip.is-in { background: #e6f4ea; color: #1e6b34; font-weight: 600; }
			.dmt-pending { margin: 8px 0 24px; border-left: 4px solid #dba617; }
			.dmt-pending .dmt-actions { white-space: nowrap; text-align: right; }
		</style>
		<script>
			(function () {
				var box = document.getElementById('dmt-autorefresh'), key = 'dotmticketsAutoRefresh', timer;
				if (!box) { return; }
				function arm() { clearTimeout(timer); if (box.checked) { timer = setTimeout(function () { location.reload(); }, 30000); } }
				try { box.checked = localStorage.getItem(key) === '1'; } catch (e) {}
				box.addEventListener('change', function () { try { localStorage.setItem(key, box.checked ? '1' : '0'); } catch (e) {} arm(); });
				arm();
			})();
		</script>
		<?php
	}

	public static function export_csv() {
		check_admin_referer( 'dotmtickets_export_csv' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dotmtickets' ), '', array( 'response' => 403 ) );
		}

		$filters = self::filters();
		$tickets = self::query_tickets( $filters );
		$entries = self::checkins_for( $tickets );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tickets-' . wp_date( 'Y-m-d-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- BOM per Excel.
		fputcsv(
			$out,
			array(
				__( 'Code', 'dotmtickets' ),
				__( 'Token', 'dotmtickets' ),
				__( 'Product', 'dotmtickets' ),
				__( 'Order', 'dotmtickets' ),
				__( 'Order status', 'dotmtickets' ),
				__( 'Buyer', 'dotmtickets' ),
				__( 'Email', 'dotmtickets' ),
				Dotm_Tickets_Settings::text( 'approval_activity_label' ),
				Dotm_Tickets_Settings::text( 'approval_tax_label' ),
				__( 'Ticket status', 'dotmtickets' ),
				__( 'Check-ins', 'dotmtickets' ),
			),
			';'
		);

		foreach ( $tickets as $ticket ) {
			$order = wc_get_order( $ticket->order_id );
			$data  = $order && Dotm_Tickets_Product::requires_approval( (int) $ticket->product_id )
				? Dotm_Tickets_Approval::get_data( $order )
				: array(
					'activity' => '',
					'tax'      => '',
				);
			$list  = array();
			foreach ( $entries[ (int) $ticket->id ] ?? array() as $c ) {
				$who    = self::user_name( $c->checked_in_by );
				$list[] = Dotm_Tickets::format_gmt( $c->checked_in_at ) . ( $who ? ' (' . $who . ')' : '' );
			}
			fputcsv(
				$out,
				array(
					Dotm_Tickets::short_code( $ticket->token ),
					$ticket->token,
					self::product_name( (int) $ticket->product_id ),
					$order ? $order->get_order_number() : $ticket->order_id,
					$order ? wc_get_order_status_name( $order->get_status() ) : '',
					$order ? $order->get_formatted_billing_full_name() : '',
					$order ? $order->get_billing_email() : '',
					$data['activity'],
					$data['tax'],
					Dotm_Tickets::STATUS_VALID === $ticket->status ? __( 'Valid', 'dotmtickets' ) : __( 'Cancelled', 'dotmtickets' ),
					implode( ' | ', $list ),
				),
				';'
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
