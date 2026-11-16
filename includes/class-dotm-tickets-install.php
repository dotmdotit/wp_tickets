<?php
/**
 * Installazione e aggiornamento: tabelle, ruolo "Ticket checker", capability, versione del DB.
 *
 * Per modificare il database in una versione futura:
 * 1. aggiorna lo schema in create_tables() (dbDelta aggiunge colonne e indici mancanti);
 * 2. alza DB_VERSION;
 * 3. se servono migrazioni di dati, aggiungi un passo in upgrade() con version_compare().
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets_Install {

	const DB_VERSION     = '1';
	const OPTION_VERSION = 'dotmtickets_db_version';

	const CAP  = 'dotmtickets_check_tickets';
	const ROLE = 'dotmtickets_checker';

	public static function activate( $network_wide = false ) {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids' ) ) as $site_id ) {
				switch_to_blog( $site_id );
				self::install();
				restore_current_blog();
			}
			return;
		}
		self::install();
	}

	public static function maybe_upgrade() {
		if ( get_option( self::OPTION_VERSION ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function install() {
		$old_version = get_option( self::OPTION_VERSION );

		self::create_tables();
		self::install_roles();
		self::upgrade( $old_version );

		if ( false === get_option( Dotm_Tickets_Settings::OPTION ) ) {
			add_option( Dotm_Tickets_Settings::OPTION, Dotm_Tickets_Settings::defaults(), '', false );
		}
		update_option( self::OPTION_VERSION, self::DB_VERSION );
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$tickets  = Dotm_Tickets::table();
		$checkins = Dotm_Tickets::checkins_table();
		$charset  = $wpdb->get_charset_collate();

		$sql_tickets = "CREATE TABLE $tickets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token char(32) NOT NULL,
			order_id bigint(20) unsigned NOT NULL,
			order_item_id bigint(20) unsigned NOT NULL,
			product_id bigint(20) unsigned NOT NULL,
			ticket_index smallint(5) unsigned NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'valid',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY token (token),
			UNIQUE KEY item_index (order_item_id,ticket_index),
			KEY order_id (order_id),
			KEY product_id (product_id)
		) $charset;";

		// Un ingresso per persona (slot 1..N) per biglietto per giorno: il vincolo UNIQUE impedisce
		// ingressi oltre il limite anche con scansioni simultanee da parte di più addetti.
		$sql_checkins = "CREATE TABLE $checkins (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			ticket_id bigint(20) unsigned NOT NULL,
			day date NOT NULL,
			slot tinyint(3) unsigned NOT NULL DEFAULT 1,
			checked_in_at datetime NOT NULL,
			checked_in_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ticket_day_slot (ticket_id,day,slot),
			KEY day (day)
		) $charset;";

		dbDelta( array( $sql_tickets, $sql_checkins ) );
	}

	/**
	 * Migrazioni di dati tra versioni del DB.
	 *
	 * @param string|false $old_version Versione installata in precedenza (false = prima installazione).
	 */
	private static function upgrade( $old_version ) {
		if ( ! $old_version ) {
			return;
		}
		// Esempio per una versione futura:
		// if ( version_compare( $old_version, '2', '<' ) ) { ... }.
	}

	/**
	 * Ruolo dedicato agli addetti agli ingressi + capability per amministratori e shop manager.
	 */
	public static function install_roles() {
		if ( ! get_role( self::ROLE ) ) {
			// Nome non tradotto qui (l'attivazione avviene prima del caricamento delle traduzioni):
			// viene tradotto a video dal filtro in Dotm_Tickets_Scanner::translate_role().
			add_role(
				self::ROLE,
				'Ticket checker',
				array(
					'read'    => true,
					self::CAP => true,
				)
			);
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( self::CAP ) ) {
				$role->add_cap( self::CAP );
			}
		}
	}

	/**
	 * Rimozione completa (chiamata da uninstall.php solo se l'opzione "Elimina i dati" è attiva).
	 */
	public static function uninstall() {
		global $wpdb;

		$wpdb->query( 'DROP TABLE IF EXISTS ' . Dotm_Tickets::checkins_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Dotm_Tickets::table() );          // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_dotmtickets_' ) . '%' ) );

		delete_option( Dotm_Tickets_Settings::OPTION );
		delete_option( self::OPTION_VERSION );

		remove_role( self::ROLE );
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role ) {
				$role->remove_cap( self::CAP );
			}
		}
	}
}
