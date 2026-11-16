<?php
/**
 * Generazione del PDF dei biglietti (un biglietto per pagina, ciascuno con il proprio QR code).
 *
 * Il template è templates/ticket-pdf.php; un tema può sostituirlo con
 * {tema}/dotmtickets/ticket-pdf.php (o con il filtro "dotmtickets_pdf_template").
 *
 * @package dotmtickets
 * @license MIT (vedi LICENSE)
 */

defined( 'ABSPATH' ) || exit;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use Dompdf\Dompdf;
use Dompdf\Options;

class Dotm_Tickets_PDF {

	private static $libs_loaded = false;

	public static function init_preview() {
		add_action( 'admin_post_dotmtickets_preview', array( __CLASS__, 'preview' ) );
	}

	private static function load_libs() {
		if ( self::$libs_loaded ) {
			return;
		}
		if ( ! class_exists( Dompdf::class ) ) {
			require_once DOTMTICKETS_DIR . 'lib/dompdf/autoload.inc.php';
		}
		spl_autoload_register(
			function ( $class ) {
				$map = array(
					'chillerlan\\QRCode\\'   => DOTMTICKETS_DIR . 'lib/php-qrcode/src/',
					'chillerlan\\Settings\\' => DOTMTICKETS_DIR . 'lib/php-settings-container/src/',
				);
				foreach ( $map as $prefix => $dir ) {
					if ( 0 === strpos( $class, $prefix ) ) {
						$file = $dir . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
						if ( is_readable( $file ) ) {
							require $file;
						}
						return;
					}
				}
			}
		);
		self::$libs_loaded = true;
	}

	/**
	 * Biglietti validi dell'ordine, pronti per il template.
	 */
	public static function get_ticket_data( WC_Order $order ) {
		$valid = array_values(
			array_filter(
				Dotm_Tickets::get_by_order( $order->get_id() ),
				fn( $t ) => Dotm_Tickets::STATUS_VALID === $t->status
			)
		);

		$data  = array();
		$total = count( $valid );
		foreach ( $valid as $n => $ticket ) {
			$item  = $order->get_item( $ticket->order_item_id );
			$qty   = $item ? max( 1, (int) $item->get_quantity() ) : 1;
			$price = $item ? ( (float) $item->get_total() + (float) $item->get_total_tax() ) / $qty : 0;
			$dates = Dotm_Tickets_Product::valid_dates( $ticket->product_id );

			$data[] = array(
				'token'        => $ticket->token,
				'short_code'   => Dotm_Tickets::short_code( $ticket->token ),
				'verify_url'   => Dotm_Tickets::verify_url( $ticket->token ),
				'product_name' => $item ? $item->get_name() : '',
				'event'        => Dotm_Tickets_Product::event( $ticket->product_id ),
				'valid_dates'  => $dates ? ucfirst( Dotm_Tickets::format_dates( $dates ) ) : '',
				'people'       => Dotm_Tickets_Product::people( $ticket->product_id ),
				'number'       => $n + 1,
				'total'        => $total,
				'price'        => self::price_label( $price, $order->get_currency() ),
			);
		}
		return apply_filters( 'dotmtickets_pdf_tickets', $data, $order );
	}

	private static function price_label( $price, $currency ) {
		if ( $price <= 0 ) {
			return __( 'Free', 'dotmtickets' );
		}
		return html_entity_decode( wp_strip_all_tags( wc_price( $price, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Restituisce il contenuto binario del PDF, oppure null se l'ordine non ha biglietti validi.
	 */
	public static function render( WC_Order $order ) {
		$tickets = self::get_ticket_data( $order );
		if ( ! $tickets ) {
			return null;
		}
		$date = $order->get_date_paid() ? $order->get_date_paid() : $order->get_date_created();
		return self::build(
			$tickets,
			array(
				'order_no' => $order->get_order_number(),
				'date'     => $date ? $date->date_i18n( get_option( 'date_format' ) ) : '',
				'buyer'    => $order->get_formatted_billing_full_name(),
				'order'    => $order,
			)
		);
	}

	/**
	 * Costruisce il PDF a partire dai dati dei biglietti.
	 *
	 * @param array $tickets Biglietti (vedi get_ticket_data()).
	 * @param array $info    order_no, date, buyer, order (WC_Order|null).
	 */
	private static function build( array $tickets, array $info ) {
		self::load_libs();

		foreach ( $tickets as &$ticket ) {
			$ticket['qr'] = self::qr_data_uri( $ticket['verify_url'] );
		}
		unset( $ticket );

		$color = Dotm_Tickets_Settings::color();
		$vars  = array(
			'tickets'   => $tickets,
			'order_no'  => $info['order_no'],
			'date'      => $info['date'],
			'buyer'     => $info['buyer'],
			'order'     => $info['order'],
			'logo'      => self::logo_data_uri(),
			'site_name' => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'band'      => self::image_data_uri( (int) Dotm_Tickets_Settings::get( 'band_id' ) ),
			'sponsors'  => self::sponsors(),
			'color'     => $color,
			'on_color'  => self::contrast_color( $color ),
			// Colore per testi su bianco: il colore stesso, oppure grigio scuro se è troppo chiaro.
			'ink'       => '#ffffff' === self::contrast_color( $color ) ? $color : '#2b2b2b',
			'tint'      => self::tint( $color, 0.92 ),
			'line'      => self::tint( $color, 0.6 ),
			'texts'     => array(
				'sponsors'          => Dotm_Tickets_Settings::text( 'sponsors_title' ),
				'how_to'            => Dotm_Tickets_Settings::text( 'text_how_to' ),
				'rules'             => Dotm_Tickets_Settings::text( 'text_rules' ),
				'rules_days'        => Dotm_Tickets_Settings::text( 'text_rules_days' ),
				'rules_people'      => Dotm_Tickets_Settings::text( 'text_rules_people' ),
				'rules_people_days' => Dotm_Tickets_Settings::text( 'text_rules_people_days' ),
				'footer'            => Dotm_Tickets_Settings::get( 'footer' ) ? Dotm_Tickets_Settings::get( 'footer' ) : wp_parse_url( home_url(), PHP_URL_HOST ),
			),
		);
		$vars = apply_filters( 'dotmtickets_pdf_vars', $vars );

		$html = self::render_template( $vars );

		$font_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'dotmtickets/fonts';
		wp_mkdir_p( $font_dir );

		$options = new Options();
		$options->setIsRemoteEnabled( false );
		$options->setIsPhpEnabled( false );
		$options->setDefaultFont( 'Helvetica' );
		$options->setChroot( array( DOTMTICKETS_DIR, $font_dir ) );
		$options->setTempDir( get_temp_dir() );
		$options->setFontDir( $font_dir );
		$options->setFontCache( $font_dir );

		$dompdf = new Dompdf( $options );
		$dompdf->loadHtml( $html, 'UTF-8' );
		$dompdf->setPaper( 'LETTER' === Dotm_Tickets_Settings::get( 'paper' ) ? 'letter' : 'A4', 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	private static function render_template( array $vars ) {
		$template = locate_template( 'dotmtickets/ticket-pdf.php' );
		if ( ! $template ) {
			$template = DOTMTICKETS_DIR . 'templates/ticket-pdf.php';
		}
		$template = apply_filters( 'dotmtickets_pdf_template', $template );

		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- variabili del template.
		ob_start();
		include $template;
		return ob_get_clean();
	}

	/**
	 * Logo delle impostazioni, altrimenti l'icona del sito, altrimenti vuoto (il template mostra il nome del sito).
	 */
	public static function logo_data_uri() {
		$id = (int) Dotm_Tickets_Settings::get( 'logo_id' );
		if ( ! $id ) {
			$id = (int) get_option( 'site_icon' );
		}
		return self::image_data_uri( $id );
	}

	/**
	 * Immagine della libreria media come data URI (dompdf non legge file fuori dalla chroot).
	 * Usa la versione "large" se esiste, per non appesantire il PDF.
	 */
	public static function image_data_uri( $attachment_id ) {
		$file = self::attachment_file( $attachment_id );
		if ( ! $file ) {
			return '';
		}
		$type = wp_check_filetype( $file );
		if ( ! in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
			return '';
		}
		return 'data:' . $type['type'] . ';base64,' . base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	private static function attachment_file( $attachment_id, $size = 'large' ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return '';
		}
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! empty( $meta['sizes'][ $size ]['file'] ) ) {
			$sized = path_join( dirname( $file ), $meta['sizes'][ $size ]['file'] );
			if ( is_readable( $sized ) ) {
				return $sized;
			}
		}
		return $file;
	}

	/**
	 * Loghi degli sponsor dalle impostazioni, già dimensionati in mm per stare nel riquadro
	 * (dompdf non gestisce bene max-width/max-height).
	 *
	 * @return array<int, array{src: string, w: float, h: float}>
	 */
	public static function sponsors() {
		$max_w = 36;   // mm
		$max_h = 14.5; // mm
		$list  = array();
		foreach ( Dotm_Tickets_Settings::sponsor_ids() as $id ) {
			$file = self::attachment_file( $id, 'medium' );
			$size = $file ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $size || ! $size[0] || ! $size[1] ) {
				continue;
			}
			$type = wp_check_filetype( $file );
			if ( ! in_array( $type['type'], array( 'image/png', 'image/jpeg', 'image/gif' ), true ) ) {
				continue;
			}
			$scale  = min( $max_w / $size[0], $max_h / $size[1] );
			$list[] = array(
				'src' => 'data:' . $type['type'] . ';base64,' . base64_encode( (string) file_get_contents( $file ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				'w'   => round( $size[0] * $scale, 2 ),
				'h'   => round( $size[1] * $scale, 2 ),
			);
		}
		return apply_filters( 'dotmtickets_pdf_sponsors', $list );
	}

	/**
	 * Mescola il colore con il bianco ($ratio = quota di bianco, 0..1).
	 */
	public static function tint( $hex, $ratio ) {
		list( $r, $g, $b ) = self::rgb( $hex );
		$mix               = fn( $c ) => (int) round( $c + ( 255 - $c ) * $ratio );
		return sprintf( '#%02x%02x%02x', $mix( $r ), $mix( $g ), $mix( $b ) );
	}

	/**
	 * Bianco o quasi nero, il più leggibile sul colore dato.
	 */
	public static function contrast_color( $hex ) {
		list( $r, $g, $b ) = self::rgb( $hex );
		$luminance         = ( 0.299 * $r + 0.587 * $g + 0.114 * $b ) / 255;
		return $luminance > 0.6 ? '#1a1a1a' : '#ffffff';
	}

	private static function rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	private static function qr_data_uri( $data ) {
		$options = new QROptions(
			array(
				// PNG se c'è GD, altrimenti SVG (dompdf li supporta entrambi).
				'outputInterface' => extension_loaded( 'gd' ) ? QRGdImagePNG::class : QRMarkupSVG::class,
				'eccLevel'        => 'M',
				'scale'           => 10,
				'quietzoneSize'   => 2,
				'outputBase64'    => true,
			)
		);
		return ( new QRCode( $options ) )->render( $data );
	}

	public static function filename( WC_Order $order ) {
		/* translators: %s: order number. Used in the PDF file name. */
		return sanitize_file_name( sprintf( __( 'tickets-order-%s', 'dotmtickets' ), $order->get_order_number() ) ) . '.pdf';
	}

	/**
	 * Invia il PDF al browser e termina la richiesta.
	 */
	public static function output( $pdf, $filename ) {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Robots-Tag: noindex' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Scrive il PDF in un file temporaneo (eliminato a fine richiesta) e ne restituisce il percorso.
	 */
	public static function write_temp_file( WC_Order $order ) {
		try {
			$pdf = self::render( $order );
		} catch ( \Throwable $e ) {
			wc_get_logger()->error( 'PDF error, order ' . $order->get_id() . ': ' . $e->getMessage(), array( 'source' => 'dotmtickets' ) );
			return null;
		}
		if ( ! $pdf ) {
			return null;
		}

		$dir = trailingslashit( get_temp_dir() ) . 'dotmtickets-' . wp_generate_password( 16, false, false );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$file = $dir . '/' . self::filename( $order );
		file_put_contents( $file, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		register_shutdown_function(
			function () use ( $file, $dir ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@rmdir( $dir );   // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			}
		);

		return $file;
	}

	/**
	 * Anteprima dalle impostazioni: un biglietto di esempio a ingresso singolo e uno su due giorni per due persone.
	 */
	public static function preview() {
		check_admin_referer( 'dotmtickets_preview' );
		if ( ! current_user_can( Dotm_Tickets_Settings::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'dotmtickets' ), '', array( 'response' => 403 ) );
		}

		$event = array(
			'name'  => Dotm_Tickets_Settings::get( 'event_name' ) ? Dotm_Tickets_Settings::get( 'event_name' ) : get_bloginfo( 'name' ),
			'date'  => Dotm_Tickets_Settings::get( 'event_date' ),
			'place' => Dotm_Tickets_Settings::get( 'event_place' ),
		);
		$days  = array( wp_date( 'Y-m-d', strtotime( '+7 days' ) ), wp_date( 'Y-m-d', strtotime( '+8 days' ) ) );
		$base  = array(
			'event' => $event,
			'total' => 2,
		);
		$token = str_repeat( '0', 32 );

		$tickets = array(
			$base + array(
				'token'        => $token,
				'short_code'   => Dotm_Tickets::short_code( $token ),
				'verify_url'   => Dotm_Tickets::verify_url( $token ),
				'product_name' => __( 'Sample ticket', 'dotmtickets' ),
				'valid_dates'  => '',
				'people'       => 1,
				'number'       => 1,
				'price'        => self::price_label( 10, get_woocommerce_currency() ),
			),
			$base + array(
				'token'        => $token,
				'short_code'   => Dotm_Tickets::short_code( $token ),
				'verify_url'   => Dotm_Tickets::verify_url( $token ),
				'product_name' => __( 'Two-day pass for two people', 'dotmtickets' ),
				'valid_dates'  => ucfirst( Dotm_Tickets::format_dates( $days ) ),
				'people'       => 2,
				'number'       => 2,
				'price'        => self::price_label( 0, get_woocommerce_currency() ),
			),
		);

		try {
			$pdf = self::build(
				$tickets,
				array(
					'order_no' => '1234',
					'date'     => wp_date( get_option( 'date_format' ) ),
					'buyer'    => wp_get_current_user()->display_name,
					'order'    => null,
				)
			);
		} catch ( \Throwable $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
		self::output( $pdf, 'tickets-preview.pdf' );
	}
}
