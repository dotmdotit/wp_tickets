<?php
/**
 * Approvazione manuale (es. accrediti gratuiti per stampa o operatori): campi facoltativi al
 * checkout (tipo di attività, codice fiscale / partita IVA), ordine in stato "Awaiting approval"
 * finché non viene confermato dalla pagina Tickets; alla conferma passa a "Completato" e parte
 * la mail con il PDF.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Approval {

	const STATUS = 'dmt-approval'; // Stato ordine wc-dmt-approval.

	// Campi aggiuntivi del checkout a blocchi (salvati nei meta "_wc_other/<id>").
	const FIELD_ACTIVITY = 'dotmtickets/activity';
	const FIELD_TAX      = 'dotmtickets/tax-code';

	// Gli stessi campi nel checkout classico (shortcode), salvati nei meta "_billing_dmt_*".
	const CLASSIC_ACTIVITY = 'billing_dmt_activity';
	const CLASSIC_TAX      = 'billing_dmt_tax_code';

	const META_RECEIVED_SENT = '_dotmtickets_received_sent';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_action( 'woocommerce_init', array( __CLASS__, 'register_fields' ) );

		// Checkout classico [woocommerce_checkout].
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'classic_fields' ) );
		add_filter( 'woocommerce_process_checkout_field_' . self::CLASSIC_TAX, array( __CLASS__, 'normalize_tax' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order_data' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( __CLASS__, 'email_fields' ), 10, 3 );

		// Ordine pagato (o a costo zero) con un prodotto da approvare: resta in attesa di approvazione.
		add_filter( 'woocommerce_payment_complete_order_status', array( __CLASS__, 'payment_complete_status' ), 20, 3 );
		add_action( 'woocommerce_order_status_' . self::STATUS, array( __CLASS__, 'send_received_email' ), 20, 2 );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'thankyou_notice' ), 5 );

		// Il prodotto da approvare va ordinato da solo, senza altri prodotti nel carrello.
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 2 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );

		add_action( 'admin_post_dotmtickets_approval', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_head', array( __CLASS__, 'status_css' ) );
	}

	public static function register_status() {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => __( 'Awaiting approval', 'dotmtickets' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Awaiting approval <span class="count">(%s)</span>', 'Awaiting approval <span class="count">(%s)</span>', 'dotmtickets' ),
			)
		);
	}

	public static function add_status( $statuses ) {
		$label = __( 'Awaiting approval', 'dotmtickets' );
		$new   = array();
		foreach ( $statuses as $key => $value ) {
			$new[ $key ] = $value;
			if ( 'wc-pending' === $key ) {
				$new[ 'wc-' . self::STATUS ] = $label;
			}
		}
		if ( ! isset( $new[ 'wc-' . self::STATUS ] ) ) {
			$new[ 'wc-' . self::STATUS ] = $label;
		}
		return $new;
	}

	public static function status_css() {
		echo '<style>.order-status.status-' . esc_attr( self::STATUS ) . '{background:#fcf0d9;color:#8a5a00;}</style>';
	}

	private static function tax_mode() {
		return (string) Dotm_Tickets_Settings::get( 'approval_tax_field' );
	}

	/**
	 * Campi visibili (e obbligatori, se previsto) solo se il carrello contiene un prodotto da approvare.
	 */
	public static function register_fields() {
		$ids = Dotm_Tickets_Product::approval_ids();
		if ( ! $ids || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		$in_cart     = array( 'cart' => array( 'properties' => array( 'items' => array( 'contains' => array( 'enum' => $ids ) ) ) ) );
		$not_in_cart = array( 'cart' => array( 'properties' => array( 'items' => array( 'not' => array( 'contains' => array( 'enum' => $ids ) ) ) ) ) );

		$activities = Dotm_Tickets_Settings::activities();
		if ( $activities ) {
			$options = array();
			foreach ( $activities as $value => $label ) {
				$options[] = array(
					'value' => $value,
					'label' => $label,
				);
			}
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD_ACTIVITY,
					'label'    => Dotm_Tickets_Settings::text( 'approval_activity_label' ),
					'location' => 'contact',
					'type'     => 'select',
					'options'  => $options,
					'required' => $in_cart,
					'hidden'   => $not_in_cart,
				)
			);
		}

		if ( 'off' !== self::tax_mode() ) {
			woocommerce_register_additional_checkout_field(
				array(
					'id'                => self::FIELD_TAX,
					'label'             => Dotm_Tickets_Settings::text( 'approval_tax_label' ),
					'optionalLabel'     => Dotm_Tickets_Settings::text( 'approval_tax_label' ) . ' ' . __( '(optional)', 'dotmtickets' ),
					'location'          => 'contact',
					'type'              => 'text',
					'required'          => 'required' === self::tax_mode() ? $in_cart : false,
					'hidden'            => $not_in_cart,
					'sanitize_callback' => fn( $value ) => self::normalize_tax( $value ),
					'validate_callback' => array( __CLASS__, 'validate_tax' ),
				)
			);
		}
	}

	public static function classic_fields( $fields ) {
		if ( ! self::cart_requires_approval() ) {
			return $fields;
		}
		$activities = Dotm_Tickets_Settings::activities();
		if ( $activities ) {
			$fields['billing'][ self::CLASSIC_ACTIVITY ] = array(
				'type'     => 'select',
				'label'    => Dotm_Tickets_Settings::text( 'approval_activity_label' ),
				'required' => true,
				'class'    => array( 'form-row-wide' ),
				'options'  => array( '' => __( 'Select…', 'dotmtickets' ) ) + $activities,
				'priority' => 200,
			);
		}
		if ( 'off' !== self::tax_mode() ) {
			$fields['billing'][ self::CLASSIC_TAX ] = array(
				'type'     => 'text',
				'label'    => Dotm_Tickets_Settings::text( 'approval_tax_label' ),
				'required' => 'required' === self::tax_mode(),
				'class'    => array( 'form-row-wide' ),
				'priority' => 210,
			);
		}
		return $fields;
	}

	public static function classic_validate( $data, $errors ) {
		if ( ! self::cart_requires_approval() ) {
			return;
		}
		$activity = $data[ self::CLASSIC_ACTIVITY ] ?? '';
		if ( '' !== $activity && ! isset( Dotm_Tickets_Settings::activities()[ $activity ] ) ) {
			/* translators: %s: field label */
			$errors->add( 'validation', sprintf( __( '%s is not valid.', 'dotmtickets' ), '<strong>' . esc_html( Dotm_Tickets_Settings::text( 'approval_activity_label' ) ) . '</strong>' ) );
		}
		if ( 'off' !== self::tax_mode() ) {
			$valid = self::validate_tax( $data[ self::CLASSIC_TAX ] ?? '' );
			if ( is_wp_error( $valid ) ) {
				$errors->add( 'validation', $valid->get_error_message() );
			}
		}
	}

	public static function admin_order_data( $order ) {
		$data = self::get_data( $order );
		if ( ! $data['activity'] && ! $data['tax'] ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'Approval request', 'dotmtickets' ) . '</strong><br>';
		if ( $data['activity'] ) {
			echo esc_html( Dotm_Tickets_Settings::text( 'approval_activity_label' ) . ': ' . $data['activity'] ) . '<br>';
		}
		if ( $data['tax'] ) {
			echo esc_html( Dotm_Tickets_Settings::text( 'approval_tax_label' ) . ': ' . $data['tax'] );
		}
		echo '</p>';
	}

	public static function email_fields( $fields, $sent_to_admin, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $fields;
		}
		$data = self::get_data( $order );
		if ( $data['activity'] ) {
			$fields['dotmtickets_activity'] = array(
				'label' => Dotm_Tickets_Settings::text( 'approval_activity_label' ),
				'value' => $data['activity'],
			);
		}
		if ( $data['tax'] ) {
			$fields['dotmtickets_tax'] = array(
				'label' => Dotm_Tickets_Settings::text( 'approval_tax_label' ),
				'value' => $data['tax'],
			);
		}
		return $fields;
	}

	public static function normalize_tax( $value ) {
		$value = (string) $value;
		if ( 'it' === Dotm_Tickets_Settings::get( 'approval_tax_validation' ) ) {
			return strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
		}
		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Con la validazione "Italia" accetta codice fiscale, partita IVA italiana o partita IVA estera
	 * con prefisso del paese. Senza validazione accetta qualsiasi testo.
	 *
	 * @return true|WP_Error
	 */
	public static function validate_tax( $value ) {
		$value = self::normalize_tax( $value );
		if ( '' === $value || 'it' !== Dotm_Tickets_Settings::get( 'approval_tax_validation' ) ) {
			return true;
		}
		if ( self::is_valid_codice_fiscale( $value )
			|| self::is_valid_partita_iva( preg_replace( '/^IT/', '', $value ) )
			|| preg_match( '/^(?!IT)[A-Z]{2}[A-Z0-9]{2,13}$/', $value ) ) {
			return true;
		}
		return new WP_Error( 'dotmtickets_invalid_tax', __( 'The tax code or VAT number is not valid. Please check it.', 'dotmtickets' ) );
	}

	public static function is_valid_partita_iva( $piva ) {
		if ( ! preg_match( '/^\d{11}$/', $piva ) ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < 11; $i++ ) {
			$n = (int) $piva[ $i ];
			if ( 1 === $i % 2 ) {
				$n *= 2;
				if ( $n > 9 ) {
					$n -= 9;
				}
			}
			$sum += $n;
		}
		return 0 === $sum % 10;
	}

	public static function is_valid_codice_fiscale( $cf ) {
		if ( ! preg_match( '/^[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]$/', $cf ) ) {
			return false;
		}
		// Valori dei caratteri in posizione dispari (1ª, 3ª, ...) per il carattere di controllo.
		$odd = array( 1, 0, 5, 7, 9, 13, 15, 17, 19, 21, 2, 4, 18, 20, 11, 3, 6, 8, 12, 14, 16, 10, 22, 25, 24, 23 );
		$sum = 0;
		for ( $i = 0; $i < 15; $i++ ) {
			$c    = $cf[ $i ];
			$pos  = ctype_digit( $c ) ? (int) $c : ord( $c ) - 65;
			$sum += 0 === $i % 2 ? $odd[ $pos ] : $pos;
		}
		return chr( 65 + $sum % 26 ) === $cf[15];
	}

	public static function order_requires_approval( WC_Order $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof WC_Order_Item_Product && Dotm_Tickets_Product::requires_approval( $item->get_product_id() ) ) {
				return true;
			}
		}
		return false;
	}

	public static function payment_complete_status( $status, $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		return $order && self::order_requires_approval( $order ) ? self::STATUS : $status;
	}

	/**
	 * Dati del richiedente salvati nell'ordine (checkout classico o a blocchi).
	 *
	 * @return array{activity: string, tax: string}
	 */
	public static function get_data( WC_Order $order ) {
		$activity = (string) $order->get_meta( '_' . self::CLASSIC_ACTIVITY );
		$tax      = (string) $order->get_meta( '_' . self::CLASSIC_TAX );
		if ( '' === $activity && '' === $tax ) {
			$activity = (string) $order->get_meta( '_wc_other/' . self::FIELD_ACTIVITY );
			$tax      = (string) $order->get_meta( '_wc_other/' . self::FIELD_TAX );
		}
		$activities = Dotm_Tickets_Settings::activities();
		return array(
			'activity' => $activities[ $activity ] ?? $activity,
			'tax'      => $tax,
		);
	}

	/**
	 * Mail al richiedente: richiesta ricevuta, il biglietto arriverà dopo la conferma.
	 */
	public static function send_received_email( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! $order->get_billing_email() || $order->get_meta( self::META_RECEIVED_SENT ) ) {
			return;
		}

		$mailer  = WC()->mailer();
		$heading = __( 'Request received', 'dotmtickets' );
		/* translators: %s: customer first name */
		$body = '<p>' . sprintf( esc_html__( 'Hi %s,', 'dotmtickets' ), esc_html( $order->get_billing_first_name() ) ) . '</p>'
			/* translators: %s: order number */
			. '<p>' . sprintf( esc_html__( 'we have received your request (order #%s).', 'dotmtickets' ), esc_html( $order->get_order_number() ) ) . '</p>'
			. '<p>' . esc_html__( 'Once it has been reviewed by the organizers, you will receive a new email with your PDF ticket and the QR code to show at the entrance.', 'dotmtickets' ) . '</p>';

		$sent = $mailer->send(
			$order->get_billing_email(),
			/* translators: %s: site name */
			sprintf( __( 'Request received – %s', 'dotmtickets' ), wp_specialchars_decode( get_bloginfo( 'name', 'display' ), ENT_QUOTES ) ),
			$mailer->wrap_message( $heading, $body )
		);
		if ( $sent ) {
			$order->update_meta_data( self::META_RECEIVED_SENT, 1 );
			$order->save_meta_data();
		}
	}

	public static function thankyou_notice( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order && $order->has_status( self::STATUS ) ) {
			echo '<p class="woocommerce-info">' . esc_html__( 'Your request is awaiting approval. You will receive your ticket by email as soon as it is approved.', 'dotmtickets' ) . '</p>';
		}
	}

	private static function cart_requires_approval() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( Dotm_Tickets_Product::requires_approval( $item['product_id'] ) ) {
				return true;
			}
		}
		return false;
	}

	public static function validate_add_to_cart( $passed, $product_id ) {
		if ( ! $passed || ! WC()->cart || WC()->cart->is_empty() ) {
			return $passed;
		}
		$adding = Dotm_Tickets_Product::requires_approval( $product_id );
		$has    = self::cart_requires_approval();
		if ( ( $adding && ! $has ) || ( ! $adding && $has ) ) {
			wc_add_notice( __( 'Products that require approval must be ordered separately: complete or empty your cart first.', 'dotmtickets' ), 'error' );
			return false;
		}
		return $passed;
	}

	public static function check_cart_items() {
		if ( WC()->cart && self::cart_requires_approval() && count( WC()->cart->get_cart() ) > 1 ) {
			wc_add_notice( __( 'Products that require approval must be ordered separately: remove the other products from your cart.', 'dotmtickets' ), 'error' );
		}
	}

	public static function pending_orders() {
		return wc_get_orders(
			array(
				'status'  => array( self::STATUS ),
				'limit'   => -1,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);
	}

	public static function action_url( WC_Order $order, $do ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=dotmtickets_approval&do=' . $do . '&order=' . $order->get_id() ),
			'dotmtickets_approval_' . $order->get_id()
		);
	}

	/**
	 * Conferma (→ Completato: biglietto e mail con PDF) o rifiuta (→ Annullato, nessuna mail).
	 */
	public static function handle_action() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		check_admin_referer( 'dotmtickets_approval_' . $order_id );
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dotmtickets' ), '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( $order_id );
		$do    = isset( $_GET['do'] ) ? sanitize_key( $_GET['do'] ) : '';
		$user  = wp_get_current_user()->display_name;

		if ( $order && $order->has_status( self::STATUS ) ) {
			if ( 'confirm' === $do ) {
				/* translators: %s: user name */
				$order->update_status( 'completed', sprintf( __( 'Request approved by %s.', 'dotmtickets' ), $user ) );
			} elseif ( 'reject' === $do ) {
				/* translators: %s: user name */
				$order->update_status( 'cancelled', sprintf( __( 'Request rejected by %s.', 'dotmtickets' ), $user ) );
			}
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . Dotm_Tickets_Dashboard::PAGE ) );
		exit;
	}
}
