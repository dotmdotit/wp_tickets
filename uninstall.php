<?php
/**
 * Disinstallazione: elimina i dati solo se nelle impostazioni è attiva l'opzione "Delete data".
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-dotm-tickets.php';
require_once __DIR__ . '/includes/class-dotm-tickets-settings.php';
require_once __DIR__ . '/includes/class-dotm-tickets-install.php';

$dotmtickets_uninstall = function () {
	$settings = (array) get_option( Dotm_Tickets_Settings::OPTION, array() );
	if ( ! empty( $settings['delete_data'] ) ) {
		Dotm_Tickets_Install::uninstall();
	}
};

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $dotmtickets_site ) {
		switch_to_blog( $dotmtickets_site );
		$dotmtickets_uninstall();
		restore_current_blog();
	}
} else {
	$dotmtickets_uninstall();
}
