<?php
/**
 * @var array      $tender
 * @var int        $user_id
 * @var array|null $flash
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$docs   = TG_Docs::for_owner( 'tender', (int) $tender['id'] );
$events = TG_Tender::events( (int) $tender['id'] );
$bids   = (int) TG_Bid::count_for_tender( (int) $tender['id'] );
$public = in_array( $tender['status'], TG_Status::supplier_visible(), true );
?>
<div class="tg-dash tg-buyer">
	<p><a class="tg-link" href="<?php echo esc_url( TG_Buyer::portal_url() ); ?>">&larr; Your tenders</a></p>

	<?php if ( $flash ) : ?>
		<div class="tg-notice tg-notice--<?php echo 'error' === $flash['type'] ? 'error' : 'success'; ?>"><?php echo esc_html( $flash['message'] ); ?></div>
	<?php endif; ?>

	<p class="tg-dash__eyebrow"><?php echo esc_html( $tender['external_id'] ); ?> &middot; <?php echo esc_html( TG_Status::label( $tender['status'] ) ); ?></p>
	<h1><?php echo esc_html( $tender['title'] ); ?></h1>

	<?php if ( TG_Status::PENDING === $tender['status'] ) : ?>
		<div class="tg-notice" style="background:#fdf8e7;border-left:3px solid #8a6d1f;">With the Jumla Tender team for approval. You will receive an email as soon as it is published.</div>
	<?php elseif ( TG_Status::REJECTED === $tender['status'] ) : ?>
		<div class="tg-notice tg-notice--error">Not approved. <?php echo esc_html( (string) $tender['status_reason'] ); ?></div>
	<?php elseif ( TG_Status::APPROVED === $tender['status'] && ! empty( $tender['opening_at'] ) ) : ?>
		<div class="tg-notice tg-notice--success">Approved. Opens for bidding on <?php echo esc_html( date_i18n( 'j F Y, H:i', strtotime( $tender['opening_at'] ) ) ); ?>.</div>
	<?php elseif ( TG_Status::OPEN === $tender['status'] ) : ?>
		<div class="tg-notice tg-notice--success">Open for bidding until <?php echo esc_html( date_i18n( 'j F Y, H:i', strtotime( $tender['closing_at'] ) ) ); ?>. <?php echo (int) $bids; ?> bid(s) received so far - the bids stay sealed until the tender closes.</div>
	<?php elseif ( TG_Status::CLOSED === $tender['status'] ) : ?>
		<div class="tg-notice">Closed with <?php echo (int) $bids; ?> bid(s). The Jumla Tender team is reviewing them.</div>
	<?php endif; ?>

	<?php if ( $public ) : ?>
		<p><a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( tg_tender_url( $tender['external_id'] ) ); ?>" target="_blank">See it on the website</a></p>
	<?php endif; ?>

	<section class="tg-panel">
		<h2>Details</h2>
		<dl class="tg-datalist">
			<div><dt>Sector</dt><dd><?php echo esc_html( $tender['sector'] ? $tender['sector'] : '-' ); ?></dd></div>
			<div><dt>Country</dt><dd><?php echo esc_html( $tender['country'] ? $tender['country'] : '-' ); ?></dd></div>
			<div><dt>Quantity</dt><dd><?php echo esc_html( trim( $tender['quantity'] . ' ' . $tender['unit'] ) ?: '-' ); ?></dd></div>
			<div><dt>Delivery location</dt><dd><?php echo esc_html( $tender['delivery_location'] ? $tender['delivery_location'] : '-' ); ?></dd></div>
			<div><dt>Estimated value</dt><dd><?php echo (float) $tender['value'] > 0 ? esc_html( $tender['currency'] . ' ' . number_format( (float) $tender['value'], 3 ) ) : 'Not disclosed'; ?></dd></div>
			<div><dt>Closes</dt><dd><?php echo esc_html( $tender['closing_at'] ? date_i18n( 'j F Y, H:i', strtotime( $tender['closing_at'] ) ) : '-' ); ?></dd></div>
		</dl>
		<h3 style="margin-top:22px;">Summary</h3>
		<?php echo wp_kses_post( wpautop( (string) $tender['summary'] ) ); ?>
		<h3>Specifications</h3>
		<?php echo wp_kses_post( wpautop( (string) $tender['specifications'] ) ); ?>
		<?php if ( $docs ) : ?>
			<h3>Documents</h3>
			<ul>
				<?php foreach ( $docs as $d ) : ?>
					<li><a href="<?php echo esc_url( TG_Docs::url( $d ) ); ?>"><?php echo esc_html( $d['filename'] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</section>

	<section class="tg-panel">
		<h2>History</h2>
		<table class="tg-table" style="width:100%;border-collapse:collapse;">
			<tbody>
			<?php foreach ( $events as $e ) : ?>
				<tr style="border-top:1px solid #e3e8ee;">
					<td style="padding:6px;white-space:nowrap;"><?php echo esc_html( date_i18n( 'j M Y, H:i', strtotime( $e['created_at'] ) ) ); ?></td>
					<td style="padding:6px;"><?php echo esc_html( ( $e['from_status'] ? TG_Status::label( $e['from_status'] ) . ' → ' : '' ) . TG_Status::label( $e['to_status'] ) ); ?></td>
					<td style="padding:6px;" class="tg-note"><?php echo esc_html( (string) $e['reason'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>
</div>
