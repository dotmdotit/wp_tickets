<?php
/**
 * Impostazioni del plugin (scheda "Settings" della pagina WooCommerce → Tickets).
 * Tutto è salvato in un'unica opzione array, non caricata automaticamente.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Settings {

	const OPTION = 'dotmtickets_settings';
	const GROUP  = 'dotmtickets';
	const CAP    = 'manage_woocommerce';

	/** Colore predefinito: grigio scuro neutro. */
	const DEFAULT_COLOR = '#333333';

	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'option_page_capability_' . self::GROUP, fn() => self::CAP );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'update_option_' . self::OPTION, fn() => self::$cache = null );
	}

	/**
	 * Valori predefiniti. I testi vuoti usano i testi standard tradotti (vedi text()).
	 */
	public static function defaults() {
		return array(
			'event_name'               => '',
			'event_date'               => '',
			'event_place'              => '',
			'footer'                   => '',
			'color'                    => self::DEFAULT_COLOR,
			'logo_id'                  => 0,
			'band_id'                  => 0,
			'sponsor_ids'              => '',
			'sponsors_title'           => '',
			'paper'                    => 'A4',
			'text_how_to'              => '',
			'text_rules'               => '',
			'text_rules_days'          => '',
			'text_rules_people'        => '',
			'text_rules_people_days'   => '',
			'approval_activities'      => '',
			'approval_activity_label'  => '',
			'approval_tax_field'       => 'off',
			'approval_tax_label'       => '',
			'approval_tax_validation'  => 'none',
			'delete_data'              => 0,
		);
	}

	public static function all() {
		if ( null === self::$cache ) {
			self::$cache = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	/**
	 * Testi standard (tradotti) usati quando il campo corrispondente è vuoto.
	 */
	public static function default_texts() {
		return array(
			'sponsors_title'          => __( 'Partners and sponsors', 'dotmtickets' ),
			'text_how_to'             => __( 'Show this ticket at the entrance, printed or on your smartphone. Staff will scan the QR code to check it.', 'dotmtickets' ),
			'text_rules'              => __( 'Each QR code is valid for one entry only: after the first scan the ticket cannot be used again. Do not share or publish this ticket.', 'dotmtickets' ),
			'text_rules_days'         => __( 'The QR code allows one entry on each of the days shown on the ticket: after the scan it cannot be used again on the same day and it is not valid on other dates. Do not share or publish this ticket.', 'dotmtickets' ),
			/* translators: %d: number of people. Keep the %d placeholder. */
			'text_rules_people'       => __( 'The QR code allows entry for up to %d people: once the limit is reached the ticket cannot be used again. Do not share or publish this ticket.', 'dotmtickets' ),
			/* translators: %d: number of people. Keep the %d placeholder. */
			'text_rules_people_days'  => __( 'The QR code allows entry for up to %d people on each of the days shown on the ticket: once the limit is reached it cannot be used again on the same day and it is not valid on other dates. Do not share or publish this ticket.', 'dotmtickets' ),
			'approval_activity_label' => __( 'Type of activity', 'dotmtickets' ),
			'approval_tax_label'      => __( 'Tax code or VAT number', 'dotmtickets' ),
		);
	}

	/**
	 * Testo configurato, oppure il testo standard se il campo è vuoto.
	 */
	public static function text( $key ) {
		$value = trim( (string) self::get( $key ) );
		if ( '' !== $value ) {
			return $value;
		}
		$defaults = self::default_texts();
		return $defaults[ $key ] ?? '';
	}

	public static function color() {
		$color = sanitize_hex_color( (string) self::get( 'color' ) );
		return $color ? $color : self::DEFAULT_COLOR;
	}

	/**
	 * Tipi di attività per l'approvazione manuale: [slug => etichetta]. Vuoto = campo non mostrato.
	 */
	public static function activities() {
		$list = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) self::get( 'approval_activities' ) ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$list[ sanitize_title( $line ) ] = $line;
			}
		}
		return $list;
	}

	public static function sponsor_ids() {
		return array_values( array_filter( array_map( 'absint', explode( ',', (string) self::get( 'sponsor_ids' ) ) ) ) );
	}

	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array(); // options.php ha già tolto gli slash.
		$out   = self::defaults();

		foreach ( array( 'event_name', 'event_date', 'event_place', 'footer', 'sponsors_title', 'approval_activity_label', 'approval_tax_label' ) as $key ) {
			$out[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
		}
		foreach ( array( 'text_how_to', 'text_rules', 'text_rules_days', 'text_rules_people', 'text_rules_people_days', 'approval_activities' ) as $key ) {
			$out[ $key ] = sanitize_textarea_field( $input[ $key ] ?? '' );
		}

		$color        = sanitize_hex_color( $input['color'] ?? '' );
		$out['color'] = $color ? $color : self::DEFAULT_COLOR;

		$out['logo_id']     = absint( $input['logo_id'] ?? 0 );
		$out['band_id']     = absint( $input['band_id'] ?? 0 );
		$out['sponsor_ids'] = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) ( $input['sponsor_ids'] ?? '' ) ) ) ) );

		$out['paper']                   = in_array( $input['paper'] ?? '', array( 'A4', 'LETTER' ), true ) ? $input['paper'] : 'A4';
		$out['approval_tax_field']      = in_array( $input['approval_tax_field'] ?? '', array( 'off', 'optional', 'required' ), true ) ? $input['approval_tax_field'] : 'off';
		$out['approval_tax_validation'] = in_array( $input['approval_tax_validation'] ?? '', array( 'none', 'it' ), true ) ? $input['approval_tax_validation'] : 'none';
		$out['delete_data']             = empty( $input['delete_data'] ) ? 0 : 1;

		return $out;
	}

	public static function enqueue( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false === strpos( (string) $hook, Dotm_Tickets_Dashboard::PAGE ) || ( $_GET['tab'] ?? '' ) !== 'settings' ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'dotmtickets-admin', DOTMTICKETS_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker' ), DOTMTICKETS_VERSION, true );
		wp_localize_script(
			'dotmtickets-admin',
			'dotmticketsAdmin',
			array(
				'choose'   => __( 'Choose image', 'dotmtickets' ),
				'chooseMany' => __( 'Choose images', 'dotmtickets' ),
				'use'      => __( 'Use', 'dotmtickets' ),
			)
		);
	}

	/**
	 * Modulo delle impostazioni.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			echo '<p>' . esc_html__( 'You are not allowed to change these settings.', 'dotmtickets' ) . '</p>';
			return;
		}
		$s        = self::all();
		$defaults = self::default_texts();
		$name     = fn( $key ) => self::OPTION . '[' . $key . ']';
		$preview  = wp_nonce_url( admin_url( 'admin-post.php?action=dotmtickets_preview' ), 'dotmtickets_preview' );
		?>
		<form method="post" action="options.php" class="dmt-settings">
			<?php settings_fields( self::GROUP ); ?>

			<h2><?php esc_html_e( 'Event', 'dotmtickets' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Shown on every ticket. Each product can override name, date and place in its "General" tab.', 'dotmtickets' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				self::text_row( $name( 'event_name' ), __( 'Event name', 'dotmtickets' ), $s['event_name'], get_bloginfo( 'name' ) );
				self::text_row( $name( 'event_date' ), __( 'Date', 'dotmtickets' ), $s['event_date'], __( 'e.g. Saturday 23 and Sunday 24 January 2027', 'dotmtickets' ), __( 'Free text, shown on tickets without validity days.', 'dotmtickets' ) );
				self::text_row( $name( 'event_place' ), __( 'Place', 'dotmtickets' ), $s['event_place'] );
				self::text_row( $name( 'footer' ), __( 'Footer', 'dotmtickets' ), $s['footer'], wp_parse_url( home_url(), PHP_URL_HOST ), __( 'Small line at the bottom of each page (e.g. organizer and website).', 'dotmtickets' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Appearance', 'dotmtickets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="dmt-color"><?php esc_html_e( 'Color', 'dotmtickets' ); ?></label></th>
					<td>
						<input type="text" id="dmt-color" class="dmt-color" name="<?php echo esc_attr( $name( 'color' ) ); ?>" value="<?php echo esc_attr( $s['color'] ); ?>" data-default-color="<?php echo esc_attr( self::DEFAULT_COLOR ); ?>">
						<p class="description"><?php esc_html_e( 'Used for the ticket band, titles, buttons and the email notice. Everything else stays neutral grey.', 'dotmtickets' ); ?></p>
					</td>
				</tr>
				<?php
				self::image_row( $name( 'logo_id' ), __( 'Logo', 'dotmtickets' ), (int) $s['logo_id'], __( 'Top left on the ticket and on the scanner pages. PNG or JPG. If empty, the site icon or the site name is used.', 'dotmtickets' ) );
				self::image_row( $name( 'band_id' ), __( 'Band image', 'dotmtickets' ), (int) $s['band_id'], __( 'Background of the coloured band with the event and product name (about 184 × 30 mm, e.g. 1840 × 300 px). If empty, a plain band in the chosen colour.', 'dotmtickets' ) );
				?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sponsor logos', 'dotmtickets' ); ?></th>
					<td>
						<div class="dmt-gallery" data-input="dmt-sponsors">
							<?php foreach ( self::sponsor_ids() as $id ) : ?>
								<?php echo wp_get_attachment_image( $id, 'thumbnail' ); ?>
							<?php endforeach; ?>
						</div>
						<input type="hidden" id="dmt-sponsors" name="<?php echo esc_attr( $name( 'sponsor_ids' ) ); ?>" value="<?php echo esc_attr( $s['sponsor_ids'] ); ?>">
						<button type="button" class="button dmt-pick-gallery" data-target="dmt-sponsors"><?php esc_html_e( 'Choose logos', 'dotmtickets' ); ?></button>
						<button type="button" class="button-link dmt-clear" data-target="dmt-sponsors"><?php esc_html_e( 'Remove', 'dotmtickets' ); ?></button>
						<p class="description"><?php esc_html_e( 'Optional. Shown at the bottom of each ticket, four per row, in the order chosen in the media library (drag to reorder).', 'dotmtickets' ); ?></p>
					</td>
				</tr>
				<?php self::text_row( $name( 'sponsors_title' ), __( 'Sponsor section title', 'dotmtickets' ), $s['sponsors_title'], $defaults['sponsors_title'] ); ?>
				<tr>
					<th scope="row"><label for="dmt-paper"><?php esc_html_e( 'Paper size', 'dotmtickets' ); ?></label></th>
					<td>
						<select id="dmt-paper" name="<?php echo esc_attr( $name( 'paper' ) ); ?>">
							<option value="A4" <?php selected( $s['paper'], 'A4' ); ?>>A4</option>
							<option value="LETTER" <?php selected( $s['paper'], 'LETTER' ); ?>>Letter</option>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Ticket texts', 'dotmtickets' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Leave a field empty to use the standard text (shown in grey), translated in the site language.', 'dotmtickets' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				self::textarea_row( $name( 'text_how_to' ), __( 'How to use the ticket', 'dotmtickets' ), $s['text_how_to'], $defaults['text_how_to'] );
				self::textarea_row( $name( 'text_rules' ), __( 'Rules: single entry', 'dotmtickets' ), $s['text_rules'], $defaults['text_rules'] );
				self::textarea_row( $name( 'text_rules_days' ), __( 'Rules: one entry per day', 'dotmtickets' ), $s['text_rules_days'], $defaults['text_rules_days'] );
				self::textarea_row( $name( 'text_rules_people' ), __( 'Rules: more people', 'dotmtickets' ), $s['text_rules_people'], $defaults['text_rules_people'], __( '%d is replaced by the number of people.', 'dotmtickets' ) );
				self::textarea_row( $name( 'text_rules_people_days' ), __( 'Rules: more people per day', 'dotmtickets' ), $s['text_rules_people_days'], $defaults['text_rules_people_days'], __( '%d is replaced by the number of people.', 'dotmtickets' ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Manual approval', 'dotmtickets' ); ?></h2>
			<p class="description"><?php esc_html_e( 'For products with "Requires approval" checked (e.g. free accreditations for press or trade). The order waits in "Awaiting approval" until you confirm it from the Tickets page; only then the ticket is sent.', 'dotmtickets' ); ?></p>
			<table class="form-table" role="presentation">
				<?php
				self::textarea_row( $name( 'approval_activities' ), __( 'Types of activity', 'dotmtickets' ), $s['approval_activities'], __( "Journalist\nBlogger\nRetailer\nOther", 'dotmtickets' ), __( 'One per line. Customers must choose one at checkout. Leave empty to not ask.', 'dotmtickets' ) );
				self::text_row( $name( 'approval_activity_label' ), __( 'Activity field label', 'dotmtickets' ), $s['approval_activity_label'], $defaults['approval_activity_label'] );
				?>
				<tr>
					<th scope="row"><label for="dmt-tax"><?php esc_html_e( 'Tax code field', 'dotmtickets' ); ?></label></th>
					<td>
						<select id="dmt-tax" name="<?php echo esc_attr( $name( 'approval_tax_field' ) ); ?>">
							<option value="off" <?php selected( $s['approval_tax_field'], 'off' ); ?>><?php esc_html_e( 'Not asked', 'dotmtickets' ); ?></option>
							<option value="optional" <?php selected( $s['approval_tax_field'], 'optional' ); ?>><?php esc_html_e( 'Optional', 'dotmtickets' ); ?></option>
							<option value="required" <?php selected( $s['approval_tax_field'], 'required' ); ?>><?php esc_html_e( 'Required', 'dotmtickets' ); ?></option>
						</select>
					</td>
				</tr>
				<?php self::text_row( $name( 'approval_tax_label' ), __( 'Tax code field label', 'dotmtickets' ), $s['approval_tax_label'], $defaults['approval_tax_label'] ); ?>
				<tr>
					<th scope="row"><label for="dmt-tax-val"><?php esc_html_e( 'Tax code validation', 'dotmtickets' ); ?></label></th>
					<td>
						<select id="dmt-tax-val" name="<?php echo esc_attr( $name( 'approval_tax_validation' ) ); ?>">
							<option value="none" <?php selected( $s['approval_tax_validation'], 'none' ); ?>><?php esc_html_e( 'None (any text)', 'dotmtickets' ); ?></option>
							<option value="it" <?php selected( $s['approval_tax_validation'], 'it' ); ?>><?php esc_html_e( 'Italy: codice fiscale, partita IVA or foreign VAT number with country prefix', 'dotmtickets' ); ?></option>
						</select>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'dotmtickets' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Delete data', 'dotmtickets' ); ?></th>
					<td>
						<label><input type="checkbox" name="<?php echo esc_attr( $name( 'delete_data' ) ); ?>" value="1" <?php checked( $s['delete_data'], 1 ); ?>> <?php esc_html_e( 'When the plugin is deleted, also delete tickets, check-ins, settings and the "Ticket checker" role.', 'dotmtickets' ); ?></label>
						<p class="description"><?php esc_html_e( 'Off by default: deactivating or deleting the plugin keeps all data.', 'dotmtickets' ); ?></p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<?php submit_button( null, 'primary', 'submit', false ); ?>
				<a class="button" href="<?php echo esc_url( $preview ); ?>" target="_blank"><?php esc_html_e( 'Preview PDF ticket', 'dotmtickets' ); ?></a>
			</p>
		</form>
		<style>
			.dmt-settings .form-table textarea { width: 100%; max-width: 640px; }
			.dmt-settings .form-table input.regular-text { max-width: 640px; width: 100%; }
			.dmt-image img, .dmt-gallery img { max-height: 60px; width: auto; margin: 0 8px 8px 0; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; padding: 4px; vertical-align: middle; }
			.dmt-gallery:empty, .dmt-image:empty { display: none; }
			.dmt-settings .submit .button { margin-left: 8px; vertical-align: middle; }
		</style>
		<?php
	}

	private static function text_row( $name, $label, $value, $placeholder = '', $help = '' ) {
		$id = 'dmt-' . md5( $name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>">
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function textarea_row( $name, $label, $value, $placeholder = '', $help = '' ) {
		$id = 'dmt-' . md5( $name );
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<textarea rows="3" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( $help ) : ?>
					<p class="description"><?php echo esc_html( $help ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private static function image_row( $name, $label, $attachment_id, $help ) {
		$id = 'dmt-' . md5( $name );
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<div class="dmt-image" data-input="<?php echo esc_attr( $id ); ?>"><?php echo $attachment_id ? wp_get_attachment_image( $attachment_id, 'medium' ) : ''; ?></div>
				<input type="hidden" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $attachment_id; ?>">
				<button type="button" class="button dmt-pick-image" data-target="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Choose image', 'dotmtickets' ); ?></button>
				<button type="button" class="button-link dmt-clear" data-target="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Remove', 'dotmtickets' ); ?></button>
				<p class="description"><?php echo esc_html( $help ); ?></p>
			</td>
		</tr>
		<?php
	}
}
