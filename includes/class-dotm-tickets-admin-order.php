<?php
/**
 * Riquadro "QR tickets" nella pagina dell'ordine e azione manuale di generazione.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Admin_Order {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'add_order_action' ) );
		add_action( 'woocommerce_order_action_dotmtickets_sync', array( __CLASS__, 'run_order_action' ) );
	}

	public static function add_meta_box() {
		// Schermata ordini classica (post) e HPOS.
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box( 'dotmtickets', __( 'QR tickets', 'dotmtickets' ), array( __CLASS__, 'render_meta_box' ), $screen, 'normal', 'default' );
		}
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WP_Post ? wc_get_order( $post_or_order->ID ) : $post_or_order;
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$tickets = Dotm_Tickets::get_by_order( $order->get_id() );
		if ( ! $tickets ) {
			echo '<p>' . esc_html__( 'No tickets for this order.', 'dotmtickets' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Product', 'dotmtickets' ) . '</th>';
		echo '<th>' . esc_html__( 'No.', 'dotmtickets' ) . '</th>';
		echo '<th>' . esc_html__( 'Code', 'dotmtickets' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'dotmtickets' ) . '</th>';
		echo '<th>' . esc_html__( 'Check-ins', 'dotmtickets' ) . '</th>';
		echo '</tr></thead><tbody>';

		// Numerazione progressiva sull'intero ordine (ticket_index è per riga d'ordine).
		$total = count( $tickets );
		foreach ( $tickets as $n => $ticket ) {
			$product = wc_get_product( $ticket->product_id );
			echo '<tr>';
			echo '<td>' . esc_html( $product ? $product->get_name() : '#' . $ticket->product_id ) . '</td>';
			/* translators: 1: ticket number, 2: total tickets */
			echo '<td>' . esc_html( sprintf( __( '%1$d of %2$d', 'dotmtickets' ), $n + 1, $total ) ) . '</td>';
			echo '<td><code title="' . esc_attr( $ticket->token ) . '">' . esc_html( Dotm_Tickets::short_code( $ticket->token ) ) . '</code></td>';
			echo '<td>' . esc_html( Dotm_Tickets::STATUS_VALID === $ticket->status ? __( 'Valid', 'dotmtickets' ) : __( 'Cancelled', 'dotmtickets' ) ) . '</td>';
			echo '<td>' . self::format_check_ins( $ticket ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- costruito con escape.
			echo '</tr>';
		}

		echo '</tbody></table>';

		if ( Dotm_Tickets::has_valid_tickets( $order ) ) {
			echo '<p><a class="button" target="_blank" href="' . esc_url( Dotm_Tickets_Emails::download_url( $order ) ) . '">' . esc_html__( 'Download PDF tickets', 'dotmtickets' ) . '</a></p>';
		}
		if ( ! extension_loaded( 'gd' ) ) {
			echo '<p style="color:#b32d2e;">' . esc_html__( 'Warning: the PHP GD extension is not active, the PDF may not include images.', 'dotmtickets' ) . '</p>';
		}
	}

	private static function format_check_ins( $ticket ) {
		$checkins = Dotm_Tickets::get_checkins( $ticket->id );
		if ( ! $checkins ) {
			return '—';
		}
		$confirm = esc_js( __( 'Cancel this check-in?', 'dotmtickets' ) );
		$lines   = array();
		foreach ( $checkins as $checkin ) {
			$user    = $checkin->checked_in_by ? get_userdata( $checkin->checked_in_by ) : null;
			$lines[] = esc_html( Dotm_Tickets::format_gmt( $checkin->checked_in_at ) . ( $user ? ' (' . $user->display_name . ')' : '' ) )
				. ' <a href="' . esc_url( Dotm_Tickets_Scanner::reset_url( $checkin ) ) . '" onclick="return confirm(\'' . $confirm . '\');">' . esc_html__( 'Cancel', 'dotmtickets' ) . '</a>';
		}
		return implode( '<br>', $lines );
	}

	public static function add_order_action( $actions ) {
		$actions['dotmtickets_sync'] = __( 'Generate / update QR tickets', 'dotmtickets' );
		return $actions;
	}

	public static function run_order_action( $order ) {
		Dotm_Tickets::sync_order( $order );
	}
}
