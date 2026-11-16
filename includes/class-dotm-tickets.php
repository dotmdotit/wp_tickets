<?php
/**
 * Biglietti: generazione, sincronizzazione con lo stato dell'ordine, ingressi, query.
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

class Dotm_Tickets {

	const STATUS_VALID     = 'valid';
	const STATUS_CANCELLED = 'cancelled';

	/** Parametro dell'URL contenuto nel QR code. */
	const QUERY_VAR = 'dmt_ticket';

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dotmtickets_tickets';
	}

	public static function checkins_table() {
		global $wpdb;
		return $wpdb->prefix . 'dotmtickets_checkins';
	}

	public static function init() {
		// Gli hook "woocommerce_order_status_{stato}" scattano prima dell'invio delle email,
		// così i biglietti esistono già quando viene costruito l'allegato PDF.
		$statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', Dotm_Tickets_Approval::STATUS );
		foreach ( $statuses as $status ) {
			add_action( 'woocommerce_order_status_' . $status, array( __CLASS__, 'sync_order' ), 5 );
		}
		// Rimborsi parziali.
		add_action( 'woocommerce_order_refunded', array( __CLASS__, 'sync_order' ), 10 );
	}

	/**
	 * Allinea i biglietti all'ordine:
	 * - ordine pagato: un biglietto valido per ogni unità non rimborsata;
	 * - ordine non pagato/annullato/rimborsato: tutti i biglietti annullati (mai cancellati).
	 *
	 * @param int|WC_Order $order_id Ordine o ID ordine.
	 */
	public static function sync_order( $order_id ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( $order_id );
		if ( ! $order || $order instanceof WC_Order_Refund ) {
			return;
		}

		$is_paid = $order->has_status( wc_get_is_paid_statuses() );
		$created = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$product_id = $item->get_product_id();
			if ( ! $product_id || ! Dotm_Tickets_Product::is_ticket( $product_id ) ) {
				continue;
			}

			$wanted = 0;
			if ( $is_paid ) {
				$wanted = max( 0, (int) $item->get_quantity() - abs( (int) $order->get_qty_refunded_for_item( $item_id ) ) );
			}

			$created += self::sync_item( $order->get_id(), $item_id, $product_id, $wanted );
		}

		if ( $created > 0 ) {
			/* translators: %d: number of tickets */
			$order->add_order_note( sprintf( _n( '%d QR ticket generated.', '%d QR tickets generated.', $created, 'dotmtickets' ), $created ) );
		}

		do_action( 'dotmtickets_order_synced', $order, $created );
	}

	/**
	 * Porta i biglietti di una riga d'ordine al numero richiesto.
	 *
	 * @return int Numero di biglietti creati.
	 */
	private static function sync_item( $order_id, $item_id, $product_id, $wanted ) {
		global $wpdb;
		$table = self::table();

		$existing = array();
		foreach ( self::get_by_item( $item_id ) as $ticket ) {
			$existing[ (int) $ticket->ticket_index ] = $ticket;
		}

		$created = 0;
		for ( $i = 1; $i <= $wanted; $i++ ) {
			if ( ! isset( $existing[ $i ] ) ) {
				if ( self::insert( $order_id, $item_id, $product_id, $i ) ) {
					++$created;
				}
			} elseif ( self::STATUS_VALID !== $existing[ $i ]->status ) {
				$wpdb->update( $table, array( 'status' => self::STATUS_VALID ), array( 'id' => $existing[ $i ]->id ) );
			}
		}

		// Biglietti in eccesso (rimborso, annullamento, quantità ridotta): annullati, mai cancellati.
		foreach ( $existing as $index => $ticket ) {
			if ( $index > $wanted && self::STATUS_CANCELLED !== $ticket->status ) {
				$wpdb->update( $table, array( 'status' => self::STATUS_CANCELLED ), array( 'id' => $ticket->id ) );
			}
		}

		return $created;
	}

	private static function insert( $order_id, $item_id, $product_id, $index ) {
		global $wpdb;

		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$ok = $wpdb->insert(
				self::table(),
				array(
					'token'         => bin2hex( random_bytes( 16 ) ),
					'order_id'      => $order_id,
					'order_item_id' => $item_id,
					'product_id'    => $product_id,
					'ticket_index'  => $index,
					'status'        => self::STATUS_VALID,
					'created_at'    => current_time( 'mysql', true ),
				),
				array( '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
			);
			if ( $ok ) {
				return true;
			}
			// Se il biglietto esiste già (chiamata concorrente) non riprovare.
			if ( self::get_by_item_index( $item_id, $index ) ) {
				return false;
			}
		}
		return false;
	}

	/**
	 * Codice breve leggibile (primi 8 caratteri del token), utile per le verifiche a voce.
	 */
	public static function short_code( $token ) {
		return strtoupper( substr( $token, 0, 4 ) . ' ' . substr( $token, 4, 4 ) );
	}

	/**
	 * URL contenuto nel QR code: apre la pagina di verifica (riservata agli addetti).
	 */
	public static function verify_url( $token ) {
		return add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Registra l'ingresso di $people persone in modo atomico, occupando i posti liberi 1..$max
	 * del giorno: un posto già preso (anche da un altro addetto nello stesso istante) viene saltato.
	 *
	 * @return int Persone effettivamente registrate (0 se il biglietto era già esaurito).
	 */
	public static function check_in( $ticket_id, $user_id, $day, $people = 1, $max = 1 ) {
		global $wpdb;
		$checkins = self::checkins_table();
		$done     = 0;
		for ( $slot = 1; $slot <= $max && $done < $people; $slot++ ) {
			$done += (int) $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO $checkins (ticket_id, day, slot, checked_in_at, checked_in_by) VALUES (%d, %s, %d, %s, %d)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$ticket_id,
					$day,
					$slot,
					current_time( 'mysql', true ),
					$user_id
				)
			);
		}
		return $done;
	}

	public static function reset_check_in( $checkin_id ) {
		global $wpdb;
		return 1 === (int) $wpdb->delete( self::checkins_table(), array( 'id' => $checkin_id ), array( '%d' ) );
	}

	public static function get_checkin( $checkin_id ) {
		global $wpdb;
		$checkins = self::checkins_table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $checkins WHERE id = %d", $checkin_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_checkins( $ticket_id ) {
		global $wpdb;
		$checkins = self::checkins_table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $checkins WHERE ticket_id = %d ORDER BY day, id", $ticket_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Data odierna nel fuso orario del sito (Y-m-d).
	 */
	public static function today() {
		return wp_date( 'Y-m-d' );
	}

	/**
	 * Stato del biglietto rispetto a oggi:
	 * - cancelled:  biglietto annullato o ordine non pagato;
	 * - wrong_day:  biglietto a date e oggi non è una delle date valide;
	 * - used_today: biglietto a date, oggi sono già entrate tutte le persone ammesse;
	 * - used:       biglietto senza date, sono già entrate tutte le persone ammesse;
	 * - valid:      ingresso consentito per 'remaining' persone (da registrare con il giorno "today").
	 */
	public static function evaluate( $ticket, $order ) {
		$result = array(
			'state'     => 'valid',
			'today'     => self::today(),
			'dates'     => Dotm_Tickets_Product::valid_dates( $ticket->product_id ),
			'max'       => Dotm_Tickets_Product::people( $ticket->product_id ),
			'remaining' => 0,
			'checkins'  => self::get_checkins( $ticket->id ),
			'last'      => null,
		);

		if ( self::STATUS_VALID !== $ticket->status || ! $order || ! $order->has_status( wc_get_is_paid_statuses() ) ) {
			$result['state'] = 'cancelled';
			return $result;
		}

		if ( $result['dates'] && ! in_array( $result['today'], $result['dates'], true ) ) {
			$result['state'] = 'wrong_day';
			return $result;
		}

		// Con le date si contano solo gli ingressi di oggi, altrimenti tutti.
		$counted = $result['dates']
			? array_values( array_filter( $result['checkins'], fn( $c ) => $c->day === $result['today'] ) )
			: $result['checkins'];

		$result['remaining'] = max( 0, $result['max'] - count( $counted ) );
		$result['last']      = $counted ? end( $counted ) : null;
		if ( ! $result['remaining'] ) {
			$result['state'] = $result['dates'] ? 'used_today' : 'used';
		}
		return $result;
	}

	/**
	 * Es. "one entry per day", "up to 2 people per day", "single entry".
	 */
	public static function people_label( $has_dates, $people ) {
		if ( $has_dates ) {
			/* translators: %d: number of people */
			return $people > 1 ? sprintf( __( 'up to %d people per day', 'dotmtickets' ), $people ) : __( 'one entry per day', 'dotmtickets' );
		}
		/* translators: %d: number of people */
		return $people > 1 ? sprintf( __( 'entry for %d people', 'dotmtickets' ), $people ) : __( 'single entry', 'dotmtickets' );
	}

	/**
	 * "1 person" / "3 people".
	 */
	public static function people_text( $n ) {
		/* translators: %d: number of people */
		return sprintf( _n( '%d person', '%d people', $n, 'dotmtickets' ), $n );
	}

	/**
	 * Formati di data usati dal plugin, traducibili (es. giorno/mese in italiano, mese/giorno in inglese).
	 *
	 * @param string $type day | short_day | short | short_time | datetime | date.
	 */
	public static function date_format( $type ) {
		switch ( $type ) {
			case 'day':
				return _x( 'l, F j, Y', 'long date with weekday', 'dotmtickets' );
			case 'short_day':
				return _x( 'D m/d', 'short date with weekday', 'dotmtickets' );
			case 'short':
				return _x( 'm/d', 'day and month', 'dotmtickets' );
			case 'short_time':
				return _x( 'm/d H:i', 'day, month and time', 'dotmtickets' );
			case 'datetime':
				return get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			default:
				return get_option( 'date_format' );
		}
	}

	/**
	 * Formatta una data Y-m-d nel fuso del sito.
	 */
	public static function format_day( $ymd, $format = null ) {
		$date = date_create_immutable( $ymd, wp_timezone() );
		return $date ? wp_date( $format ?? self::date_format( 'day' ), $date->getTimestamp() ) : $ymd;
	}

	/**
	 * Formatta una data/ora GMT del database nel fuso del sito.
	 */
	public static function format_gmt( $mysql_gmt, $type = 'datetime' ) {
		return wp_date( self::date_format( $type ), strtotime( $mysql_gmt . ' UTC' ) );
	}

	/**
	 * Es. "Saturday, January 23, 2027 and Sunday, January 24, 2027".
	 */
	public static function format_dates( $dates ) {
		$labels = array_map( fn( $d ) => self::format_day( $d ), $dates );
		$last   = array_pop( $labels );
		/* translators: 1: list of dates, 2: last date */
		return $labels ? sprintf( __( '%1$s and %2$s', 'dotmtickets' ), implode( ', ', $labels ), $last ) : (string) $last;
	}

	/**
	 * Ricerca per codice breve (primi 8 caratteri, spazi ignorati) per l'inserimento manuale.
	 */
	public static function find_by_short_code( $code ) {
		global $wpdb;
		$code = strtolower( preg_replace( '/[^a-fA-F0-9]/', '', (string) $code ) );
		if ( 8 !== strlen( $code ) ) {
			return array();
		}
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE token LIKE %s LIMIT 2", $code . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_by_order( $order_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE order_id = %d ORDER BY order_item_id, ticket_index", $order_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_by_item( $item_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE order_item_id = %d ORDER BY ticket_index", $item_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_by_item_index( $item_id, $index ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE order_item_id = %d AND ticket_index = %d", $item_id, $index ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function get_by_token( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return null;
		}
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE token = %s", $token ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	public static function has_valid_tickets( WC_Order $order ) {
		foreach ( self::get_by_order( $order->get_id() ) as $ticket ) {
			if ( self::STATUS_VALID === $ticket->status ) {
				return true;
			}
		}
		return false;
	}
}
