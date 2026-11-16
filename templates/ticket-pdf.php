<?php
/**
 * Template PDF dei biglietti, una pagina per biglietto (HTML/CSS per dompdf).
 *
 * Per personalizzarlo copia questo file in {tema}/dotmtickets/ticket-pdf.php.
 * dompdf supporta CSS 2.1 con alcune estensioni: usa tabelle per l'impaginazione e misure in mm.
 *
 * Variabili disponibili:
 *
 * @var array         $tickets   Biglietti: token, short_code, verify_url, product_name, event (name, date, place),
 *                               valid_dates, people, number, total, price, qr (data URI).
 * @var string        $order_no  Numero d'ordine.
 * @var string        $date      Data dell'acquisto, già formattata.
 * @var string        $buyer     Nome dell'acquirente.
 * @var WC_Order|null $order     Ordine (null nell'anteprima).
 * @var string        $logo      Logo come data URI (vuoto = nessun logo, si mostra $site_name).
 * @var string        $site_name Nome del sito.
 * @var string        $band      Immagine della fascia come data URI (vuoto = fascia a tinta unita).
 * @var array         $sponsors  Loghi degli sponsor (src, w, h in mm).
 * @var string        $color     Colore principale (#hex).
 * @var string        $on_color  Colore del testo sopra $color (bianco o quasi nero).
 * @var string        $ink       Colore per testi su bianco ($color, o grigio scuro se $color è chiaro).
 * @var string        $tint      $color molto schiarito (sfondo delle note).
 * @var string        $line      $color schiarito (linea tratteggiata).
 * @var array         $texts     Testi: sponsors, how_to, rules, rules_days, rules_people, rules_people_days, footer.
 *
 * @package dotmtickets
 */

defined( 'ABSPATH' ) || exit;
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	@page { margin: 11mm 13mm 10mm 13mm; }
	body { font-family: Helvetica, sans-serif; color: #2b2b2b; font-size: 10pt; margin: 0; }
	.page-break { page-break-before: always; }

	.head { width: 100%; border-collapse: collapse; }
	.head td { vertical-align: middle; padding: 0; }
	.head .logo img { height: 19mm; }
	.head .logo .name { font-size: 16pt; font-weight: bold; color: #2b2b2b; }
	.head .label { text-align: right; }
	.head .label .kicker { font-size: 8.5pt; letter-spacing: 2px; color: #888; text-transform: uppercase; }
	.head .label .count { font-size: 19pt; font-weight: bold; color: <?php echo esc_html( $ink ); ?>; }

	.ticket { margin-top: 6mm; border: 1.5px solid <?php echo esc_html( $color ); ?>; border-radius: 4mm; }
	.ticket .band { position: relative; height: 30mm; background: <?php echo esc_html( $color ); ?>; color: <?php echo esc_html( $on_color ); ?>; border-radius: 3.5mm 3.5mm 0 0; }
	.ticket .band img { position: absolute; top: 0; left: 0; width: 100%; height: 30mm; }
	.ticket .band .text { position: absolute; top: 7mm; left: 7mm; width: 150mm; }
	.ticket .band .event { font-size: 10.5pt; letter-spacing: 1.5px; text-transform: uppercase; }
	.ticket .band .product { font-size: 19pt; font-weight: bold; margin-top: 1.5mm; line-height: 1.15; }

	.body { width: 100%; border-collapse: collapse; }
	.body td { vertical-align: top; padding: 6mm 7mm; }
	.body .info { width: 55%; }
	.body .qr { width: 45%; text-align: center; border-left: 1.5px dashed <?php echo esc_html( $line ); ?>; }
	.body .qr img { width: 54mm; height: 54mm; }
	.body .qr .code { font-family: Courier, monospace; font-size: 13pt; font-weight: bold; letter-spacing: 2px; margin-top: 1.5mm; }
	.body .qr .hint { font-size: 8pt; color: #888; margin-top: 0.5mm; }

	dl { margin: 0; }
	dt { font-size: 7.5pt; color: #888; text-transform: uppercase; letter-spacing: 1px; margin-top: 3.5mm; }
	dt:first-child { margin-top: 0; }
	dd { margin: 0.5mm 0 0 0; font-size: 11.5pt; }
	dd small { font-size: 8.5pt; color: #888; }

	.notes { margin-top: 5mm; background: <?php echo esc_html( $tint ); ?>; border-radius: 3mm; padding: 4mm 7mm 2mm 7mm; }
	.notes h3 { margin: 0 0 1.5mm 0; font-size: 10pt; color: #2b2b2b; text-transform: uppercase; letter-spacing: 1px; }
	.notes p { margin: 0 0 2mm 0; line-height: 1.35; font-size: 9.5pt; }

	.sp-title { width: 100%; border-collapse: collapse; margin-top: 5mm; }
	.sp-title td { padding: 0; vertical-align: middle; }
	.sp-title .rule div { border-top: 1px solid #cccccc; height: 0; }
	.sp-title .label { width: 1%; white-space: nowrap; padding: 0 5mm; font-size: 11pt; font-weight: bold; color: #555555; }

	.sponsors { border-collapse: separate; border-spacing: 2.2mm; width: 188.4mm; margin: 0 0 0 -2.2mm; }
	.sponsors td { width: 25%; height: 17.5mm; padding: 0; text-align: center; vertical-align: middle; border: 1px solid #e4e4e4; border-radius: 2mm; background: #fff; }

	.footer { position: fixed; bottom: 0; left: 0; right: 0; text-align: center; font-size: 7.5pt; color: #999; }
</style>
</head>
<body>
<?php if ( $texts['footer'] ) : ?>
<div class="footer"><?php echo esc_html( $texts['footer'] ); ?></div>
<?php endif; ?>

<?php foreach ( $tickets as $i => $ticket ) : ?>
<div class="<?php echo $i > 0 ? 'page-break' : ''; ?>">
	<table class="head">
		<tr>
			<td class="logo">
				<?php if ( $logo ) : ?>
				<img src="<?php echo esc_attr( $logo ); ?>" alt="">
				<?php else : ?>
				<span class="name"><?php echo esc_html( $site_name ); ?></span>
				<?php endif; ?>
			</td>
			<td class="label">
				<div class="kicker"><?php esc_html_e( 'Ticket', 'dotmtickets' ); ?></div>
				<?php /* translators: 1: ticket number, 2: total tickets */ ?>
				<div class="count"><?php echo esc_html( sprintf( __( '%1$d of %2$d', 'dotmtickets' ), $ticket['number'], $ticket['total'] ) ); ?></div>
			</td>
		</tr>
	</table>

	<div class="ticket">
		<div class="band">
			<?php if ( $band ) : ?>
			<img src="<?php echo esc_attr( $band ); ?>" alt="">
			<?php endif; ?>
			<div class="text">
				<div class="event"><?php echo esc_html( $ticket['event']['name'] ); ?></div>
				<div class="product"><?php echo esc_html( $ticket['product_name'] ); ?></div>
			</div>
		</div>
		<table class="body">
			<tr>
				<td class="info">
					<dl>
						<?php if ( $ticket['valid_dates'] ) : ?>
						<dt><?php esc_html_e( 'Valid', 'dotmtickets' ); ?></dt>
						<dd><?php echo esc_html( $ticket['valid_dates'] ); ?><br><small><?php echo esc_html( ucfirst( Dotm_Tickets::people_label( true, $ticket['people'] ) ) ); ?></small></dd>
						<?php elseif ( $ticket['event']['date'] ) : ?>
						<dt><?php esc_html_e( 'Date', 'dotmtickets' ); ?></dt>
						<dd><?php echo esc_html( $ticket['event']['date'] ); ?></dd>
						<?php endif; ?>
						<?php if ( $ticket['people'] > 1 && ! $ticket['valid_dates'] ) : ?>
						<dt><?php esc_html_e( 'People', 'dotmtickets' ); ?></dt>
						<?php /* translators: %d: number of people */ ?>
						<dd><?php echo esc_html( sprintf( __( 'Valid for %d people', 'dotmtickets' ), $ticket['people'] ) ); ?></dd>
						<?php endif; ?>
						<?php if ( $ticket['event']['place'] ) : ?>
						<dt><?php esc_html_e( 'Place', 'dotmtickets' ); ?></dt>
						<dd><?php echo esc_html( $ticket['event']['place'] ); ?></dd>
						<?php endif; ?>
						<dt><?php esc_html_e( 'Price', 'dotmtickets' ); ?></dt>
						<dd><?php echo esc_html( $ticket['price'] ); ?></dd>
						<dt><?php esc_html_e( 'Order', 'dotmtickets' ); ?></dt>
						<?php /* translators: 1: order number, 2: order date */ ?>
						<dd><?php echo esc_html( $date ? sprintf( __( '#%1$s of %2$s', 'dotmtickets' ), $order_no, $date ) : '#' . $order_no ); ?></dd>
						<?php if ( $buyer ) : ?>
						<dt><?php esc_html_e( 'Purchased by', 'dotmtickets' ); ?></dt>
						<dd><?php echo esc_html( $buyer ); ?></dd>
						<?php endif; ?>
					</dl>
				</td>
				<td class="qr">
					<img src="<?php echo esc_attr( $ticket['qr'] ); ?>" alt="">
					<div class="code"><?php echo esc_html( $ticket['short_code'] ); ?></div>
					<div class="hint"><?php esc_html_e( 'Ticket code', 'dotmtickets' ); ?></div>
				</td>
			</tr>
		</table>
	</div>

	<div class="notes">
		<h3><?php esc_html_e( 'How to use your ticket', 'dotmtickets' ); ?></h3>
		<p><?php echo esc_html( $texts['how_to'] ); ?></p>
		<?php
		if ( $ticket['people'] > 1 ) {
			$dmt_rules = sprintf( $ticket['valid_dates'] ? $texts['rules_people_days'] : $texts['rules_people'], $ticket['people'] );
		} else {
			$dmt_rules = $ticket['valid_dates'] ? $texts['rules_days'] : $texts['rules'];
		}
		?>
		<p><?php echo esc_html( $dmt_rules ); ?></p>
	</div>

	<?php if ( $sponsors ) : ?>
	<table class="sp-title">
		<tr>
			<td class="rule"><div></div></td>
			<td class="label"><?php echo esc_html( $texts['sponsors'] ); ?></td>
			<td class="rule"><div></div></td>
		</tr>
	</table>
	<table class="sponsors">
		<?php foreach ( array_chunk( $sponsors, 4 ) as $dmt_row ) : ?>
		<tr>
			<?php foreach ( array_pad( $dmt_row, 4, null ) as $dmt_sp ) : ?>
				<?php if ( $dmt_sp ) : ?>
			<td><img src="<?php echo esc_attr( $dmt_sp['src'] ); ?>" style="width: <?php echo esc_attr( $dmt_sp['w'] ); ?>mm; height: <?php echo esc_attr( $dmt_sp['h'] ); ?>mm;" alt=""></td>
				<?php else : ?>
			<td style="border: 0; background: transparent;"></td>
				<?php endif; ?>
			<?php endforeach; ?>
		</tr>
		<?php endforeach; ?>
	</table>
	<?php endif; ?>
</div>
<?php endforeach; ?>
</body>
</html>
