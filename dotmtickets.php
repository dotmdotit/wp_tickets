<?php
/**
 * Plugin Name:          dotm Tickets
 * Plugin URI:           https://github.com/dotmdotit/wp_tickets
 * Description:          QR code tickets for WooCommerce: PDF tickets attached to the order emails, mobile check-in scanner for staff, manual approval of free accreditations and an admin dashboard.
 * Version:              2.0.7
 * Author:               dotm.it
 * Author URI:           https://dotm.it
 * License:              MIT
 * License URI:          https://opensource.org/licenses/MIT
 * Text Domain:          dotmtickets
 * Domain Path:          /languages
 * Requires at least:    6.5
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 *
 * @package dotmtickets
 * @author  dotm.it
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

define( 'DOTMTICKETS_VERSION', '2.0.7' );
define( 'DOTMTICKETS_FILE', __FILE__ );
define( 'DOTMTICKETS_DIR', plugin_dir_path( __FILE__ ) );
define( 'DOTMTICKETS_URL', plugin_dir_url( __FILE__ ) );

require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-install.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-settings.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-product.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-approval.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-admin-order.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-dashboard.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-pdf.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-emails.php';
require_once DOTMTICKETS_DIR . 'includes/class-dotm-tickets-scanner.php';

register_activation_hook( __FILE__, array( 'Dotm_Tickets_Install', 'activate' ) );

// Compatibilità con HPOS (tabelle ordini personalizzate) e con il checkout a blocchi.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DOTMTICKETS_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DOTMTICKETS_FILE, true );
		}
	}
);

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'dotmtickets', false, dirname( plugin_basename( DOTMTICKETS_FILE ) ) . '/languages' );
	},
	-1
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'dotm Tickets requires WooCommerce to be installed and active.', 'dotmtickets' ) . '</p></div>';
				}
			);
			return;
		}
		Dotm_Tickets_Install::maybe_upgrade();
		Dotm_Tickets::init();
		Dotm_Tickets_Settings::init();
		Dotm_Tickets_Product::init();
		Dotm_Tickets_Approval::init();
		Dotm_Tickets_Admin_Order::init();
		Dotm_Tickets_Dashboard::init();
		Dotm_Tickets_Emails::init();
		Dotm_Tickets_PDF::init_preview();
		Dotm_Tickets_Scanner::init();
	}
);

add_filter(
	'plugin_action_links_' . plugin_basename( __FILE__ ),
	function ( $links ) {
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . Dotm_Tickets_Dashboard::PAGE . '&tab=settings' ) ) . '">' . esc_html__( 'Settings', 'dotmtickets' ) . '</a>'
		);
		return $links;
	}
);
