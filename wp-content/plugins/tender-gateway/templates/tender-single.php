<?php
/**
 * The unlocked tender file, shown to signed-in suppliers.
 *
 * @var array $tender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$days  = TG_API::days_left( $tender );
$saved = TG_Saved::has( $tender['id'] );
?>
<div class="tg-single">

	<nav class="tg-breadcrumb" aria-label="Breadcrumb">
		<a href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">Tenders</a>
		<span aria-hidden="true">/</span>
		<span><?php echo esc_html( $tender['country'] ); ?></span>
	</nav>

	<header class="tg-single__head">
		<div class="tg-chips">
			<span class="tg-chip tg-chip--country"><?php echo esc_html( $tender['country'] ); ?></span>
			<span class="tg-chip"><?php echo esc_html( $tender['sector'] ); ?></span>
			<?php if ( null !== $days && $days >= 0 ) : ?>
				<span class="tg-chip tg-chip--deadline"><?php echo esc_html( 0 === $days ? 'Closes today' : sprintf( _n( '%d day left', '%d days left', $days, 'tender-gateway' ), $days ) ); ?></span>
			<?php endif; ?>
			<span class="tg-chip tg-chip--unlocked">
				<svg width="12" height="12" viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path d="m2.5 7.4 3 3 6-6.4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
				Full access
			</span>
		</div>
		<h1><?php echo esc_html( $tender['title'] ); ?></h1>
		<p class="tg-single__ref">Reference <strong><?php echo esc_html( $tender['reference'] ); ?></strong> &middot; <?php echo esc_html( $tender['method'] ); ?></p>
	</header>

	<div class="tg-single__grid">
		<div class="tg-single__main">

			<section class="tg-panel">
				<h2>Scope of works</h2>
				<?php foreach ( preg_split( '/\n\s*\n/', (string) $tender['description'] ) as $paragraph ) : ?>
					<?php if ( trim( $paragraph ) ) : ?>
						<p><?php echo esc_html( trim( $paragraph ) ); ?></p>
					<?php endif; ?>
				<?php endforeach; ?>
			</section>

			<?php if ( ! empty( $tender['eligibility'] ) ) : ?>
				<section class="tg-panel">
					<h2>Eligibility and qualification criteria</h2>
					<ul class="tg-ticks">
						<?php foreach ( (array) $tender['eligibility'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $tender['documents'] ) ) : ?>
				<section class="tg-panel">
					<h2>Tender documents</h2>
					<ul class="tg-docs">
						<?php foreach ( (array) $tender['documents'] as $doc ) : ?>
							<li>
								<span class="tg-docs__icon" aria-hidden="true">
									<svg width="16" height="16" viewBox="0 0 16 16" focusable="false"><path d="M4 1.5h5l3 3v10H4z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M9 1.5v3h3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
								</span>
								<span class="tg-docs__label"><?php echo esc_html( isset( $doc['label'] ) ? $doc['label'] : '' ); ?></span>
								<span class="tg-docs__size"><?php echo esc_html( isset( $doc['size'] ) ? $doc['size'] : '' ); ?></span>
								<span class="tg-docs__action">Download</span>
							</li>
						<?php endforeach; ?>
					</ul>
					<p class="tg-note">In the live platform these files are served from the source procurement portal via the tender API.</p>
				</section>
			<?php endif; ?>

			<?php if ( ! empty( $tender['contact'] ) ) : ?>
				<section class="tg-panel">
					<h2>Buyer contact and submission</h2>
					<dl class="tg-datalist">
						<?php if ( ! empty( $tender['contact']['name'] ) ) : ?>
							<div><dt>Contact</dt><dd><?php echo esc_html( $tender['contact']['name'] ); ?></dd></div>
						<?php endif; ?>
						<?php if ( ! empty( $tender['contact']['org'] ) ) : ?>
							<div><dt>Address</dt><dd><?php echo esc_html( $tender['contact']['org'] ); ?></dd></div>
						<?php endif; ?>
						<?php if ( ! empty( $tender['contact']['email'] ) ) : ?>
							<div><dt>Email</dt><dd><?php echo esc_html( $tender['contact']['email'] ); ?></dd></div>
						<?php endif; ?>
						<?php if ( ! empty( $tender['contact']['phone'] ) ) : ?>
							<div><dt>Telephone</dt><dd><?php echo esc_html( $tender['contact']['phone'] ); ?></dd></div>
						<?php endif; ?>
					</dl>
				</section>
			<?php endif; ?>
		</div>

		<aside class="tg-single__aside">
			<div class="tg-panel tg-panel--tight">
				<h3>Key dates and figures</h3>
				<ul class="tg-glance">
					<li><span>Estimated value</span><strong><?php echo esc_html( TG_API::format_value( $tender ) ); ?></strong></li>
					<li><span>Published</span><strong><?php echo esc_html( tg_format_date( $tender['published'] ) ); ?></strong></li>
					<li><span>Deadline</span><strong><?php echo esc_html( tg_format_date( $tender['deadline'] ) ); ?></strong></li>
					<li><span>Procurement method</span><strong><?php echo esc_html( $tender['method'] ); ?></strong></li>
					<li><span>Source</span><strong><?php echo esc_html( $tender['source_name'] ); ?></strong></li>
				</ul>

				<button class="tg-btn tg-btn--ghost tg-btn--block tg-save <?php echo $saved ? 'is-saved' : ''; ?>"
						data-tender="<?php echo esc_attr( $tender['id'] ); ?>"
						aria-pressed="<?php echo $saved ? 'true' : 'false'; ?>">
					<span class="tg-save__on">Saved to your watchlist</span>
					<span class="tg-save__off">Save to watchlist</span>
				</button>

				<a class="tg-btn tg-btn--primary tg-btn--block" href="<?php echo esc_url( tg_page_url( 'dashboard' ) ); ?>">Back to my dashboard</a>
			</div>

			<div class="tg-panel tg-panel--tight tg-panel--muted">
				<h3>Need support?</h3>
				<p>The Ministry's trade desk can advise on eligibility, certificates of origin and joint-venture partners for this opportunity.</p>
				<a class="tg-link" href="<?php echo esc_url( tg_page_url( 'contact' ) ); ?>">Contact the trade desk</a>
			</div>
		</aside>
	</div>
</div>
