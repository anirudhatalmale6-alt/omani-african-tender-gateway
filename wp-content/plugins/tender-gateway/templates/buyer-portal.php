<?php
/**
 * @var int        $user_id
 * @var array|null $flash
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tenders = TG_Buyer::tenders_for( $user_id );
$org     = TG_Buyer::organisation( $user_id );
$colours = array(
	'draft' => '#646970', 'pending' => '#b26200', 'changes_required' => '#b5179e', 'approved' => '#2d6cb5', 'open' => '#1d7a3f', 'closed' => '#50575e',
	'awarded' => '#6c3bb5', 'completed' => '#1d2327', 'cancelled' => '#8a2424', 'rejected' => '#8a2424',
);
?>
<div class="tg-dash tg-buyer">
	<header class="tg-dash__head">
		<div>
			<p class="tg-dash__eyebrow">Buyer portal</p>
			<h1><?php echo esc_html( $org ); ?></h1>
			<p class="tg-dash__sub">Your tenders are checked by the Jumla Tender team before they are published.</p>
		</div>
		<div>
			<a class="tg-btn tg-btn--accent" href="<?php echo esc_url( TG_Buyer::portal_url( array( 'new' => 1 ) ) ); ?>">Create a tender</a>
			<a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( TG_Auth::logout_url() ); ?>">Sign out</a>
		</div>
	</header>

	<?php if ( $flash ) : ?>
		<div class="tg-notice tg-notice--<?php echo 'error' === $flash['type'] ? 'error' : 'success'; ?>"><?php echo esc_html( $flash['message'] ); ?></div>
	<?php endif; ?>

	<section class="tg-panel">
		<h2>Your tenders</h2>
		<?php if ( ! $tenders ) : ?>
			<div class="tg-empty">
				<h3>No tenders yet</h3>
				<p>Create your first tender. You can save it as a draft and come back to it before submitting.</p>
				<a class="tg-btn tg-btn--accent" href="<?php echo esc_url( TG_Buyer::portal_url( array( 'new' => 1 ) ) ); ?>">Create a tender</a>
			</div>
		<?php else : ?>
			<table class="tg-table" style="width:100%;border-collapse:collapse;">
				<thead><tr>
					<th style="text-align:left;padding:8px 6px;">Tender</th>
					<th style="text-align:left;padding:8px 6px;">Status</th>
					<th style="text-align:left;padding:8px 6px;">Closes</th>
					<th style="text-align:left;padding:8px 6px;">Bids</th>
					<th style="text-align:left;padding:8px 6px;"></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $tenders as $t ) : ?>
					<tr style="border-top:1px solid #e3e8ee;">
						<td style="padding:8px 6px;"><strong><?php echo esc_html( $t['title'] ); ?></strong><br><span class="tg-note"><?php echo esc_html( $t['external_id'] ); ?></span></td>
						<td style="padding:8px 6px;"><span style="display:inline-block;padding:2px 8px;border-radius:3px;color:#fff;font-size:11px;font-weight:600;background:<?php echo esc_attr( isset( $colours[ $t['status'] ] ) ? $colours[ $t['status'] ] : '#646970' ); ?>;"><?php echo esc_html( TG_Status::label( $t['status'] ) ); ?></span></td>
						<td style="padding:8px 6px;"><?php echo esc_html( $t['closing_at'] ? date_i18n( 'j M Y, H:i', strtotime( $t['closing_at'] ) ) : '-' ); ?></td>
						<td style="padding:8px 6px;"><?php echo (int) TG_Bid::count_for_tender( $t['id'] ); ?></td>
						<td style="padding:8px 6px;">
							<?php if ( TG_Buyer::is_editable( $t ) ) : ?>
								<a class="tg-link" href="<?php echo esc_url( TG_Buyer::portal_url( array( 'edit' => $t['id'] ) ) ); ?>"><?php echo TG_Status::CHANGES_REQUIRED === $t['status'] ? 'Make changes' : 'Edit draft'; ?></a>
							<?php else : ?>
								<a class="tg-link" href="<?php echo esc_url( TG_Buyer::portal_url( array( 'tender' => $t['id'] ) ) ); ?>">View</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="tg-note" style="margin-top:10px;">Bids are sealed: you see how many have arrived, but nobody can open them before the closing time.</p>
		<?php endif; ?>
	</section>
</div>
