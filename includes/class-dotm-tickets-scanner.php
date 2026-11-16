<?php
/**
 * Verifica dei biglietti all'ingresso: pagina aperta dal QR (?dmt_ticket=TOKEN), pagina scanner
 * (?dmt_scanner=1), ruolo "Ticket checker" e registrazione degli ingressi.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Scanner {

	const QUERY_VAR = 'dmt_scanner';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'route' ), 1 );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_filter( 'gettext_with_context', array( __CLASS__, 'translate_role' ), 10, 4 );
		add_action( 'admin_post_dotmtickets_reset_checkin', array( __CLASS__, 'admin_reset_checkin' ) );
	}

	/**
	 * Traduce il nome del ruolo, salvato in inglese nel database.
	 */
	public static function translate_role( $translation, $text, $context, $domain ) {
		if ( 'User role' === $context && 'Ticket checker' === $text ) {
			return __( 'Ticket checker', 'dotmtickets' );
		}
		return $translation;
	}

	public static function scanner_url() {
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	private static function is_staff_only( $user ) {
		return $user && in_array( Dotm_Tickets_Install::ROLE, (array) $user->roles, true ) && ! $user->has_cap( 'edit_posts' );
	}

	public static function login_redirect( $redirect_to, $requested, $user ) {
		// Gli addetti senza destinazione specifica vanno direttamente allo scanner.
		if ( $user instanceof WP_User && self::is_staff_only( $user ) && ( ! $requested || false !== strpos( $requested, 'wp-admin' ) ) ) {
			return self::scanner_url();
		}
		return $redirect_to;
	}

	public static function hide_admin_bar( $show ) {
		return self::is_staff_only( wp_get_current_user() ) ? false : $show;
	}

	public static function route() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ Dotm_Tickets::QUERY_VAR ] ) ) {
			self::ticket_page( sanitize_text_field( wp_unslash( $_GET[ Dotm_Tickets::QUERY_VAR ] ) ) );
		}
		if ( isset( $_GET[ self::QUERY_VAR ] ) ) {
			self::scanner_page();
		}
		// phpcs:enable
	}

	private static function current_url() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return esc_url_raw( ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri );
	}

	/**
	 * Blocca chi non è addetto. Restituisce solo se l'utente può verificare.
	 */
	private static function require_staff() {
		if ( current_user_can( Dotm_Tickets_Install::CAP ) ) {
			return;
		}
		$login = '<a class="btn" href="' . esc_url( wp_login_url( self::current_url() ) ) . '">' . esc_html__( 'Log in as staff', 'dotmtickets' ) . '</a>';
		$msg   = is_user_logged_in()
			? '<p>' . esc_html__( 'Your account is not allowed to check tickets.', 'dotmtickets' ) . '</p>'
			: '<p>' . esc_html__( 'This QR code is an entry ticket. Checking it is reserved to staff.', 'dotmtickets' ) . '</p>' . $login;
		self::render( 'neutral', __( 'Ticket', 'dotmtickets' ), $msg );
	}

	private static function ticket_page( $token ) {
		self::require_staff();
		nocache_headers();

		$ticket = Dotm_Tickets::get_by_token( strtolower( trim( $token ) ) );
		if ( ! $ticket ) {
			self::render( 'error', __( 'NOT VALID', 'dotmtickets' ), '<p>' . esc_html__( 'This ticket code does not exist.', 'dotmtickets' ) . '</p>' . self::next_button() );
		}

		$order = wc_get_order( $ticket->order_id );
		$eval  = Dotm_Tickets::evaluate( $ticket, $order );

		// Conferma ingresso (POST). L'inserimento è atomico: mai oltre le persone ammesse per giorno.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['dmt_checkin'] ) ) {
			check_admin_referer( 'dotmtickets_checkin_' . $ticket->id );
			$people = min( max( 1, absint( $_POST['dmt_checkin'] ) ), $eval['remaining'] );
			$done   = 'valid' === $eval['state'] ? Dotm_Tickets::check_in( $ticket->id, get_current_user_id(), $eval['today'], $people, $eval['max'] ) : 0;
			if ( $done ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: ticket code, 2: number of people, 3: day, 4: user name */
						__( 'Ticket %1$s: check-in of %2$s on %3$s by %4$s.', 'dotmtickets' ),
						Dotm_Tickets::short_code( $ticket->token ),
						Dotm_Tickets::people_text( $done ),
						Dotm_Tickets::format_day( $eval['today'] ),
						wp_get_current_user()->display_name
					)
				);
				do_action( 'dotmtickets_checked_in', $ticket, $done, $order );
				wp_safe_redirect(
					add_query_arg(
						array(
							'dmt_result' => 'ok',
							'n'          => $done,
						),
						self::current_url()
					)
				);
				exit;
			}
			$eval = Dotm_Tickets::evaluate( $ticket, $order );
		}

		$details = self::details_html( $ticket, $order, $eval );
		$last    = $eval['last'];

		// Esito della conferma appena fatta da questo addetto (dopo il redirect).
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['dmt_result'] ) && 'ok' === $_GET['dmt_result'] && $last && (int) $last->checked_in_by === get_current_user_id() && $last->day === $eval['today'] && 'cancelled' !== $eval['state'] ) {
			$n    = isset( $_GET['n'] ) ? max( 1, absint( $_GET['n'] ) ) : 1;
			$body = self::day_label( $eval );
			if ( $eval['max'] > 1 ) {
				/* translators: %d: number of people */
				$body .= '<p class="day">' . esc_html( sprintf( _n( '%d person in', '%d people in', $n, 'dotmtickets' ), $n ) ) . '</p>'
					. '<p class="hint">' . esc_html( self::remaining_text( $eval ) ) . '</p>';
			}
			self::render( 'success', __( 'CHECKED IN', 'dotmtickets' ), $body . $details . self::next_button( true ) );
		}
		// phpcs:enable

		switch ( $eval['state'] ) {
			case 'cancelled':
				self::render( 'error', __( 'CANCELLED', 'dotmtickets' ), '<p>' . esc_html__( 'Ticket not valid: the order is cancelled, refunded or unpaid.', 'dotmtickets' ) . '</p>' . $details . self::next_button() );
				break;

			case 'wrong_day':
				/* translators: %s: list of dates */
				self::render( 'error', __( 'NOT VALID TODAY', 'dotmtickets' ), '<p>' . esc_html( sprintf( __( 'This ticket is only valid on %s.', 'dotmtickets' ), Dotm_Tickets::format_dates( $eval['dates'] ) ) ) . '</p>' . $details . self::next_button() );
				break;

			case 'used':
			case 'used_today':
				$title = 'used_today' === $eval['state'] ? __( 'ALREADY IN TODAY', 'dotmtickets' ) : __( 'ALREADY USED', 'dotmtickets' );
				if ( $eval['max'] > 1 ) {
					$title .= ' (' . $eval['max'] . '/' . $eval['max'] . ')';
				}
				/* translators: %s: date, time and staff member */
				$msg = $eval['max'] > 1 ? __( 'Last check-in %s.', 'dotmtickets' ) : __( 'Already checked in %s.', 'dotmtickets' );
				self::render( 'warning', $title, '<p>' . esc_html( sprintf( $msg, self::format_checkin( $last ) ) ) . '</p>' . $details . self::next_button() );
				break;
		}

		// Un pulsante per ogni numero di persone ancora ammesse (di solito solo "Confirm entry").
		$form = '<form method="post">' . wp_nonce_field( 'dotmtickets_checkin_' . $ticket->id, '_wpnonce', true, false );
		if ( $eval['max'] > 1 ) {
			$form .= '<p class="day">' . esc_html( self::remaining_text( $eval ) ) . '</p>';
			for ( $n = $eval['remaining']; $n >= 1; $n-- ) {
				/* translators: %d: number of people */
				$form .= '<button class="btn btn-big" type="submit" name="dmt_checkin" value="' . (int) $n . '">' . esc_html( sprintf( _n( 'Check in %d person', 'Check in %d people', $n, 'dotmtickets' ), $n ) ) . '</button>';
			}
		} else {
			$form .= '<button class="btn btn-big" type="submit" name="dmt_checkin" value="1">' . esc_html__( 'Confirm entry', 'dotmtickets' ) . '</button>';
		}
		$form .= '</form>';
		self::render( 'valid', __( 'VALID', 'dotmtickets' ), self::day_label( $eval ) . $details . $form . self::next_button() );
	}

	/**
	 * "2 people left today" / "No entries left".
	 */
	private static function remaining_text( $eval ) {
		if ( ! $eval['remaining'] ) {
			return $eval['dates'] ? __( 'No entries left for today.', 'dotmtickets' ) : __( 'No entries left.', 'dotmtickets' );
		}
		$n = (int) $eval['remaining'];
		return $eval['dates']
			/* translators: %d: number of people */
			? sprintf( _n( '%d person left today', '%d people left today', $n, 'dotmtickets' ), $n )
			/* translators: %d: number of people */
			: sprintf( _n( '%d person left', '%d people left', $n, 'dotmtickets' ), $n );
	}

	/**
	 * "Day 2 of 2" per i biglietti validi più giorni.
	 */
	private static function day_label( $eval ) {
		$pos = array_search( $eval['today'], $eval['dates'], true );
		if ( false === $pos ) {
			return '';
		}
		/* translators: 1: day number, 2: total days */
		return '<p class="day">' . esc_html( sprintf( __( 'Day %1$d of %2$d', 'dotmtickets' ), $pos + 1, count( $eval['dates'] ) ) ) . '</p>';
	}

	private static function format_checkin( $checkin ) {
		$who  = $checkin->checked_in_by ? get_userdata( $checkin->checked_in_by ) : null;
		$when = Dotm_Tickets::format_gmt( $checkin->checked_in_at );
		/* translators: 1: date and time, 2: staff member */
		return $who ? sprintf( __( 'on %1$s by %2$s', 'dotmtickets' ), $when, $who->display_name ) : sprintf( __( 'on %s', 'dotmtickets' ), $when ); // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
	}

	private static function details_html( $ticket, $order, $eval ) {
		if ( ! $order ) {
			return '';
		}
		$item     = $order->get_item( $ticket->order_item_id );
		$all      = Dotm_Tickets::get_by_order( $order->get_id() );
		$position = 0;
		foreach ( $all as $i => $t ) {
			if ( (int) $t->id === (int) $ticket->id ) {
				$position = $i + 1;
			}
		}

		$entries = array();
		foreach ( $eval['checkins'] as $checkin ) {
			$entries[] = Dotm_Tickets::format_gmt( $checkin->checked_in_at, 'short_time' );
		}

		$validity = $eval['dates']
			? ucfirst( Dotm_Tickets::format_dates( $eval['dates'] ) ) . ', ' . Dotm_Tickets::people_label( true, $eval['max'] )
			: ucfirst( Dotm_Tickets::people_label( false, $eval['max'] ) );

		$rows = array(
			__( 'Product', 'dotmtickets' )   => $item ? $item->get_name() : '',
			/* translators: 1: ticket number, 2: total tickets */
			__( 'Ticket', 'dotmtickets' )    => sprintf( __( '%1$d of %2$d', 'dotmtickets' ), $position, count( $all ) ) . ' · ' . Dotm_Tickets::short_code( $ticket->token ),
			__( 'Order', 'dotmtickets' )     => '#' . $order->get_order_number(),
			__( 'Buyer', 'dotmtickets' )     => $order->get_formatted_billing_full_name(),
			__( 'Validity', 'dotmtickets' )  => $validity,
			__( 'Check-ins', 'dotmtickets' ) => $entries ? implode( ' · ', $entries ) : __( 'None', 'dotmtickets' ),
		);

		$html = '<dl>';
		foreach ( $rows as $label => $value ) {
			if ( '' !== $value ) {
				$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
			}
		}
		return $html . '</dl>';
	}

	private static function next_button( $primary = false ) {
		return '<a class="btn' . ( $primary ? ' btn-big' : ' btn-light' ) . '" href="' . esc_url( self::scanner_url() ) . '">' . esc_html__( 'Scan next', 'dotmtickets' ) . '</a>';
	}

	private static function scanner_page() {
		self::require_staff();
		nocache_headers();

		$error = '';
		// Inserimento manuale del codice breve (es. "893F FCB7").
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['dmt_code'] ) ) {
			$matches = Dotm_Tickets::find_by_short_code( sanitize_text_field( wp_unslash( $_GET['dmt_code'] ) ) );
			if ( 1 === count( $matches ) ) {
				wp_safe_redirect( Dotm_Tickets::verify_url( $matches[0]->token ) );
				exit;
			}
			$error = $matches ? __( 'More than one ticket matches this code: use the QR code.', 'dotmtickets' ) : __( 'No ticket with this code.', 'dotmtickets' );
		}
		// phpcs:enable

		$js      = esc_url( DOTMTICKETS_URL . 'assets/html5-qrcode.min.js?ver=2.3.8' );
		$strings = array(
			'base'      => home_url( '/' ),
			'param'     => Dotm_Tickets::QUERY_VAR . '=',
			'checking'  => __( 'Checking…', 'dotmtickets' ),
			'unknown'   => __( 'QR code not recognised: it is not a ticket of this site.', 'dotmtickets' ),
			'noCamera'  => __( 'Cannot access the camera: allow camera access in the browser settings.', 'dotmtickets' ),
		);

		$body = '<div id="reader"></div>'
			. '<p id="scan-msg" class="hint">' . esc_html__( 'Point the camera at the ticket QR code.', 'dotmtickets' ) . '</p>'
			. '<form method="get" class="manual"><input type="hidden" name="' . esc_attr( self::QUERY_VAR ) . '" value="1">'
			. '<input type="text" name="dmt_code" placeholder="' . esc_attr__( 'Manual code (e.g. 893F FCB7)', 'dotmtickets' ) . '" autocomplete="off" autocapitalize="characters">'
			. '<button class="btn" type="submit">' . esc_html__( 'Check', 'dotmtickets' ) . '</button></form>'
			. ( $error ? '<p class="err">' . esc_html( $error ) . '</p>' : '' )
			/* translators: %s: user name */
			. '<p class="hint">' . esc_html( sprintf( __( 'Logged in as %s', 'dotmtickets' ), wp_get_current_user()->display_name ) ) . ' · <a href="' . esc_url( wp_logout_url( self::scanner_url() ) ) . '">' . esc_html__( 'Log out', 'dotmtickets' ) . '</a></p>'
			. '<script src="' . $js . '"></script>'
			. '<script>
(function(){
	var s = ' . wp_json_encode( $strings ) . ', done = false, msg = document.getElementById("scan-msg");
	var qr = new Html5Qrcode("reader");
	function onScan(text){
		if (done) return;
		if (text.indexOf(s.base) === 0 && text.indexOf(s.param) !== -1) {
			done = true;
			msg.textContent = s.checking;
			qr.stop().catch(function(){}).finally(function(){ location.href = text; });
		} else {
			msg.textContent = s.unknown;
		}
	}
	qr.start({ facingMode: "environment" }, { fps: 10, qrbox: function(w, h){ var x = Math.floor(Math.min(w, h) * 0.75); return { width: x, height: x }; } }, onScan)
		.catch(function(e){ msg.textContent = s.noCamera + " (" + e + ")"; });
})();
</script>';

		self::render( 'neutral', __( 'Ticket scanner', 'dotmtickets' ), $body );
	}

	/**
	 * URL del logo per le pagine dello scanner: impostazioni, poi icona del sito, poi nessuno.
	 */
	private static function logo_url() {
		$id = (int) Dotm_Tickets_Settings::get( 'logo_id' );
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'medium' );
			if ( $url ) {
				return $url;
			}
		}
		return (string) get_site_icon_url( 128 );
	}

	/**
	 * Pagina minimale ottimizzata per smartphone. Termina la richiesta.
	 */
	private static function render( $state, $title, $body ) {
		$accent = Dotm_Tickets_Settings::color();
		$colors = array(
			'valid'   => '#1e8e3e',
			'success' => '#1e8e3e',
			'warning' => '#e37400',
			'error'   => '#c5221f',
			'neutral' => $accent,
		);
		$color    = $colors[ $state ] ?? $accent;
		$on_color = 'neutral' === $state ? Dotm_Tickets_PDF::contrast_color( $accent ) : '#ffffff';
		$on_btn   = Dotm_Tickets_PDF::contrast_color( $accent );
		$logo     = self::logo_url();
		$site     = get_bloginfo( 'name' );

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php echo esc_html( $title . ' · ' . $site ); ?></title>
<style>
	* { box-sizing: border-box; }
	body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background: #f2f2f2; color: #222; }
	header { background: #fff; padding: 10px 16px; text-align: center; min-height: 64px; display: flex; align-items: center; justify-content: center; }
	header img { height: 44px; width: auto; }
	header .site { font-weight: 700; font-size: 18px; }
	.status { background: <?php echo esc_attr( $color ); ?>; color: <?php echo esc_attr( $on_color ); ?>; text-align: center; padding: <?php echo 'neutral' === $state ? '14px' : '34px'; ?> 16px; font-weight: 800; font-size: <?php echo 'neutral' === $state ? '20px' : '34px'; ?>; letter-spacing: 1px; }
	main { padding: 16px; max-width: 520px; margin: 0 auto; }
	dl { background: #fff; border-radius: 10px; padding: 12px 16px; margin: 0 0 16px; }
	dt { font-size: 12px; text-transform: uppercase; color: #888; margin-top: 10px; }
	dt:first-child { margin-top: 0; }
	dd { margin: 2px 0 0; font-size: 18px; font-weight: 600; }
	p { font-size: 17px; line-height: 1.4; }
	.btn { display: block; width: 100%; text-align: center; padding: 14px; margin: 0 0 12px; border: 0; border-radius: 10px; background: <?php echo esc_attr( $accent ); ?>; color: <?php echo esc_attr( $on_btn ); ?>; font-size: 17px; font-weight: 700; text-decoration: none; cursor: pointer; }
	.btn-big { background: #1e8e3e; color: #fff; padding: 22px; font-size: 22px; }
	.btn-light { background: #fff; color: #333; border: 1px solid #ccc; }
	#reader { width: 100%; background: #000; border-radius: 10px; overflow: hidden; }
	.manual { display: flex; gap: 8px; margin-top: 8px; }
	.manual input { flex: 1; min-width: 0; padding: 12px; font-size: 16px; border: 1px solid #ccc; border-radius: 10px; }
	.manual .btn { width: auto; margin: 0; padding: 12px 16px; }
	.hint { color: #666; font-size: 14px; text-align: center; }
	.err { color: #c5221f; font-weight: 600; text-align: center; }
	.day { text-align: center; font-size: 22px; font-weight: 800; margin: 0 0 14px; }
</style>
</head>
<body>
<header>
	<?php if ( $logo ) : ?>
		<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $site ); ?>">
	<?php else : ?>
		<span class="site"><?php echo esc_html( $site ); ?></span>
	<?php endif; ?>
</header>
<div class="status"><?php echo esc_html( $title ); ?></div>
<main><?php echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML costruito con escape. ?></main>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Admin: annulla un ingresso registrato per errore.
	 */
	public static function admin_reset_checkin() {
		$checkin_id = isset( $_GET['checkin'] ) ? absint( $_GET['checkin'] ) : 0;
		check_admin_referer( 'dotmtickets_reset_checkin_' . $checkin_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dotmtickets' ), '', array( 'response' => 403 ) );
		}
		$checkin = Dotm_Tickets::get_checkin( $checkin_id );
		$ticket  = $checkin ? Dotm_Tickets::get( $checkin->ticket_id ) : null;
		if ( $ticket && Dotm_Tickets::reset_check_in( $checkin_id ) ) {
			$order = wc_get_order( $ticket->order_id );
			if ( $order ) {
				/* translators: 1: ticket code, 2: day, 3: user name */
				$order->add_order_note( sprintf( __( 'Ticket %1$s: check-in of %2$s cancelled by %3$s.', 'dotmtickets' ), Dotm_Tickets::short_code( $ticket->token ), Dotm_Tickets::format_day( $checkin->day ), wp_get_current_user()->display_name ) );
			}
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	public static function reset_url( $checkin ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=dotmtickets_reset_checkin&checkin=' . (int) $checkin->id ), 'dotmtickets_reset_checkin_' . (int) $checkin->id );
	}
}
