<?php
/**
 * Opzione "QR ticket" nella scheda prodotto (accanto a Virtuale / Scaricabile) e campi del biglietto.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Product {

	const META          = '_dotmtickets_is_ticket';
	const META_DATES    = '_dotmtickets_dates';       // Date Y-m-d separate da virgola.
	const META_PEOPLE   = '_dotmtickets_people';      // Persone ammesse per ingresso (al giorno, se il biglietto ha date).
	const META_APPROVAL = '_dotmtickets_approval';    // Approvazione manuale dell'ordine.
	const META_EVENT    = '_dotmtickets_event_name';  // Sostituiscono le impostazioni generali, se compilati.
	const META_DATE     = '_dotmtickets_event_date';
	const META_PLACE    = '_dotmtickets_event_place';

	const MAX_PEOPLE = 50;

	public static function init() {
		add_filter( 'product_type_options', array( __CLASS__, 'add_option' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'fields' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save' ) );
		add_action( 'admin_footer', array( __CLASS__, 'toggle_script' ) );
	}

	public static function add_option( $options ) {
		// Chiave senza "_" iniziale: WooCommerce legge il valore dal meta "_dotmtickets_is_ticket".
		$options['dotmtickets_is_ticket'] = array(
			'id'            => self::META,
			'wrapper_class' => 'show_if_simple',
			'label'         => __( 'QR ticket', 'dotmtickets' ),
			'description'   => __( 'A ticket with a unique QR code is generated for each unit purchased.', 'dotmtickets' ),
			'default'       => 'no',
		);
		return $options;
	}

	/**
	 * Formato con cui mostrare le date nel campo: giorno/mese/anno se il sito usa il giorno per primo.
	 */
	private static function input_format() {
		return preg_match( '/^[dj]/', (string) get_option( 'date_format' ) ) ? 'd/m/Y' : 'Y-m-d';
	}

	public static function fields() {
		global $product_object;
		$id    = $product_object ? $product_object->get_id() : 0;
		$dates = $id ? self::valid_dates( $id ) : array();
		$fmt   = self::input_format();

		echo '<div class="options_group show_if_simple dmt-ticket-fields">';
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_DATES,
				'label'       => __( 'Validity days', 'dotmtickets' ),
				'placeholder' => 'd/m/Y' === $fmt ? __( 'e.g. 23/01/2027, 24/01/2027', 'dotmtickets' ) : __( 'e.g. 2027-01-23, 2027-01-24', 'dotmtickets' ),
				'value'       => implode( ', ', array_map( fn( $d ) => Dotm_Tickets::format_day( $d, $fmt ), $dates ) ),
				'desc_tip'    => true,
				'description' => __( 'Days on which the ticket allows one entry per day. Formats: YYYY-MM-DD or DD/MM/YYYY (day first). Leave empty for a single-entry ticket (e.g. workshop, dinner).', 'dotmtickets' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => self::META_PEOPLE,
				'label'             => __( 'People per ticket', 'dotmtickets' ),
				'type'              => 'number',
				'value'             => $id ? self::people( $id ) : 1,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => self::MAX_PEOPLE,
					'step' => 1,
				),
				'desc_tip'          => true,
				'description'       => __( 'How many people can enter with the same QR code (on each validity day, if any). Usually 1.', 'dotmtickets' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_EVENT,
				'label'       => __( 'Event name', 'dotmtickets' ),
				'value'       => $id ? (string) get_post_meta( $id, self::META_EVENT, true ) : '',
				'placeholder' => Dotm_Tickets_Settings::get( 'event_name' ),
				'desc_tip'    => true,
				'description' => __( 'Optional: overrides the event name set in WooCommerce → Tickets → Settings.', 'dotmtickets' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_DATE,
				'label'       => __( 'Event date', 'dotmtickets' ),
				'value'       => $id ? (string) get_post_meta( $id, self::META_DATE, true ) : '',
				'placeholder' => Dotm_Tickets_Settings::get( 'event_date' ),
				'desc_tip'    => true,
				'description' => __( 'Optional free text, shown on tickets without validity days.', 'dotmtickets' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_PLACE,
				'label'       => __( 'Event place', 'dotmtickets' ),
				'value'       => $id ? (string) get_post_meta( $id, self::META_PLACE, true ) : '',
				'placeholder' => Dotm_Tickets_Settings::get( 'event_place' ),
				'desc_tip'    => true,
				'description' => __( 'Optional: overrides the place set in the settings.', 'dotmtickets' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => self::META_APPROVAL,
				'label'       => __( 'Requires approval', 'dotmtickets' ),
				'value'       => $id && self::requires_approval( $id ) ? 'yes' : 'no',
				'description' => __( 'The order stays "Awaiting approval" until you confirm it from WooCommerce → Tickets; the ticket is sent only then. Must be ordered on its own. Checkout fields are set in the settings.', 'dotmtickets' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Mostra i campi del biglietto solo quando "QR ticket" è spuntato.
	 */
	public static function toggle_script() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id ) {
			return;
		}
		?>
		<script>
		jQuery(function ($) {
			var box = $('#<?php echo esc_js( self::META ); ?>');
			function toggle() { $('.dmt-ticket-fields').toggleClass('hidden', !box.is(':checked')); }
			box.on('change', toggle);
			$(document.body).on('woocommerce-product-type-change', toggle);
			toggle();
		});
		</script>
		<?php
	}

	public static function save( $product ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verificato da WooCommerce.
		$product->update_meta_data( self::META, isset( $_POST[ self::META ] ) ? 'yes' : 'no' );
		$product->update_meta_data( self::META_APPROVAL, isset( $_POST[ self::META_APPROVAL ] ) ? 'yes' : 'no' );
		if ( isset( $_POST[ self::META_PEOPLE ] ) ) {
			$product->update_meta_data( self::META_PEOPLE, min( self::MAX_PEOPLE, max( 1, absint( $_POST[ self::META_PEOPLE ] ) ) ) );
		}
		foreach ( array( self::META_EVENT, self::META_DATE, self::META_PLACE ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$product->update_meta_data( $key, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
			}
		}

		if ( isset( $_POST[ self::META_DATES ] ) ) {
			$raw = sanitize_text_field( wp_unslash( $_POST[ self::META_DATES ] ) );
			// phpcs:enable
			$dates   = array();
			$invalid = array();
			foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
				// Accetta aaaa-mm-gg e gg/mm/aaaa (anche g/m/aaaa o con "." / "-").
				if ( preg_match( '#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $part, $m ) ) {
					list( , $y, $mo, $d ) = $m;
				} elseif ( preg_match( '#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $part, $m ) ) {
					list( , $d, $mo, $y ) = $m;
				} else {
					$d  = 0;
					$mo = 0;
					$y  = 0;
				}
				if ( checkdate( (int) $mo, (int) $d, (int) $y ) ) {
					$dates[] = sprintf( '%04d-%02d-%02d', $y, $mo, $d );
				} else {
					$invalid[] = $part;
				}
			}
			if ( $invalid ) {
				/* translators: %s: list of invalid values */
				WC_Admin_Meta_Boxes::add_error( sprintf( __( 'Validity days: dates not recognised (%s). Use YYYY-MM-DD or DD/MM/YYYY.', 'dotmtickets' ), implode( ', ', $invalid ) ) );
			}
			$dates = array_values( array_unique( $dates ) );
			sort( $dates );
			$product->update_meta_data( self::META_DATES, implode( ',', $dates ) );
		}
		// phpcs:enable
	}

	/**
	 * Date di validità del biglietto (Y-m-d, ordinate). Vuoto = ingresso singolo.
	 */
	public static function valid_dates( $product_id ) {
		$raw = (string) get_post_meta( $product_id, self::META_DATES, true );
		return '' === $raw ? array() : explode( ',', $raw );
	}

	public static function is_ticket( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META, true );
	}

	/**
	 * Persone ammesse con un biglietto, per ciascun giorno di validità (minimo 1).
	 */
	public static function people( $product_id ) {
		return max( 1, (int) get_post_meta( $product_id, self::META_PEOPLE, true ) );
	}

	public static function requires_approval( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_APPROVAL, true );
	}

	/**
	 * Dati dell'evento per il prodotto: valore del prodotto, altrimenti quello delle impostazioni.
	 *
	 * @return array{name: string, date: string, place: string}
	 */
	public static function event( $product_id ) {
		$pick = function ( $meta, $setting ) use ( $product_id ) {
			$value = trim( (string) get_post_meta( $product_id, $meta, true ) );
			return '' !== $value ? $value : trim( (string) Dotm_Tickets_Settings::get( $setting ) );
		};
		$name = $pick( self::META_EVENT, 'event_name' );
		return array(
			'name'  => '' !== $name ? $name : get_bloginfo( 'name' ),
			'date'  => $pick( self::META_DATE, 'event_date' ),
			'place' => $pick( self::META_PLACE, 'event_place' ),
		);
	}

	/**
	 * ID dei prodotti con approvazione manuale non cestinati.
	 */
	public static function approval_ids() {
		global $wpdb;
		static $ids = null;
		if ( null === $ids ) {
			$ids = array_map(
				'intval',
				$wpdb->get_col(
					$wpdb->prepare(
						"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						WHERE pm.meta_key = %s AND pm.meta_value = 'yes' AND p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')",
						self::META_APPROVAL
					)
				)
			);
		}
		return $ids;
	}
}
