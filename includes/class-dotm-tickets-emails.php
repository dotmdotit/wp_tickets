<?php
/**
 * Allegato PDF nelle email al cliente e download da "Il mio account" / pagina di ringraziamento.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Emails {

	const QUERY_VAR = 'dmt_pdf';

	public static function init() {
		add_filter( 'woocommerce_email_attachments', array( __CLASS__, 'attach_pdf' ), 10, 3 );
		add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email_notice' ), 6, 4 );
		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'download_button' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_pdf' ) );
	}

	/**
	 * Email al cliente che ricevono il PDF (customer_invoice = "Invia dettagli ordine al cliente").
	 * Modificabile con il filtro "dotmtickets_email_ids".
	 */
	public static function email_ids() {
		return (array) apply_filters( 'dotmtickets_email_ids', array( 'customer_processing_order', 'customer_completed_order', 'customer_invoice' ) );
	}

	public static function attach_pdf( $attachments, $email_id, $order ) {
		if ( ! in_array( $email_id, self::email_ids(), true ) || ! $order instanceof WC_Order ) {
			return $attachments;
		}
		Dotm_Tickets::sync_order( $order ); // Idempotente: garantisce che i biglietti esistano.
		$file = Dotm_Tickets_PDF::write_temp_file( $order );
		if ( $file ) {
			$attachments[] = $file;
		}
		return $attachments;
	}

	public static function email_notice( $order, $sent_to_admin, $plain_text, $email ) {
		if ( $sent_to_admin || ! $email || ! in_array( $email->id, self::email_ids(), true ) || ! Dotm_Tickets::has_valid_tickets( $order ) ) {
			return;
		}
		$url = self::download_url( $order );
		if ( $plain_text ) {
			/* translators: %s: download URL */
			echo esc_html( sprintf( __( 'Your tickets are attached to this email as a PDF. You can also download them here: %s', 'dotmtickets' ), esc_url_raw( $url ) ) ) . "\n\n";
			return;
		}
		$color = Dotm_Tickets_Settings::color();
		echo '<p style="margin:0 0 16px;padding:12px 16px;background:#f6f6f6;border-left:4px solid ' . esc_attr( $color ) . ';">'
			. '<strong>' . esc_html__( 'Your tickets are attached to this email as a PDF.', 'dotmtickets' ) . '</strong><br>'
			. esc_html__( 'Show them at the entrance, printed or on your smartphone.', 'dotmtickets' ) . ' '
			/* translators: %s: link */
			. sprintf( esc_html__( 'If you cannot see the attachment, you can %s.', 'dotmtickets' ), '<a href="' . esc_url( $url ) . '">' . esc_html__( 'download them here', 'dotmtickets' ) . '</a>' )
			. '</p>';
	}

	public static function download_url( WC_Order $order ) {
		return add_query_arg(
			array(
				self::QUERY_VAR => $order->get_id(),
				'key'           => $order->get_order_key(),
			),
			home_url( '/' )
		);
	}

	public static function download_button( $order ) {
		if ( ! $order instanceof WC_Order || ! Dotm_Tickets::has_valid_tickets( $order ) ) {
			return;
		}
		echo '<p class="dmt-download"><a class="button" href="' . esc_url( self::download_url( $order ) ) . '">' . esc_html__( 'Download tickets (PDF)', 'dotmtickets' ) . '</a></p>';
	}

	public static function maybe_serve_pdf() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- accesso protetto dalla chiave dell'ordine.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		$order = wc_get_order( absint( $_GET[ self::QUERY_VAR ] ) );
		$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		// phpcs:enable

		$title = __( 'Tickets', 'dotmtickets' );
		if ( ! $order instanceof WC_Order || ! $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'Invalid link.', 'dotmtickets' ), esc_html( $title ), array( 'response' => 403 ) );
		}

		try {
			$pdf = Dotm_Tickets_PDF::render( $order );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( 'PDF error, order ' . $order->get_id() . ': ' . $e->getMessage(), array( 'source' => 'dotmtickets' ) );
			wp_die( esc_html__( 'The tickets could not be generated. Please try again later.', 'dotmtickets' ), esc_html( $title ), array( 'response' => 500 ) );
		}
		if ( ! $pdf ) {
			wp_die( esc_html__( 'There are no valid tickets for this order.', 'dotmtickets' ), esc_html( $title ), array( 'response' => 404 ) );
		}

		Dotm_Tickets_PDF::output( $pdf, Dotm_Tickets_PDF::filename( $order ) );
	}
}
