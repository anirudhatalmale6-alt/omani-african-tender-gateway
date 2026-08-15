<?php
/**
 * The gate. A visitor who clicks a tender without an account sees the public
 * summary, a blurred preview of the restricted detail, and the two ways in.
 *
 * @var array $tender
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$days      = TG_API::days_left( $tender );
$return_to = tg_tender_url( $tender['id'] );
?>
<div class="tg-single tg-single--locked">

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
		</div>
		<h1><?php echo esc_html( $tender['title'] ); ?></h1>
		<p class="tg-single__summary"><?php echo esc_html( $tender['summary'] ); ?></p>
	</header>

	<div class="tg-single__grid">
		<div class="tg-single__main">

			<section class="tg-panel">
				<h2>Publicly available information</h2>
				<dl class="tg-datalist">
					<div>
						<dt>Contracting authority</dt>
						<dd><?php echo esc_html( $tender['buyer'] ); ?></dd>
					</div>
					<div>
						<dt>Country</dt>
						<dd><?php echo esc_html( $tender['country'] ); ?></dd>
					</div>
					<div>
						<dt>Sector</dt>
						<dd><?php echo esc_html( $tender['sector'] ); ?></dd>
					</div>
					<div>
						<dt>Estimated value</dt>
						<dd><?php echo esc_html( TG_API::format_value( $tender ) ); ?></dd>
					</div>
					<div>
						<dt>Published</dt>
						<dd><?php echo esc_html( tg_format_date( $tender['published'] ) ); ?></dd>
					</div>
					<div>
						<dt>Submission deadline</dt>
						<dd><?php echo esc_html( tg_format_date( $tender['deadline'] ) ); ?></dd>
					</div>
				</dl>
			</section>

			<section class="tg-panel tg-locked">
				<?php
				/*
				 * Decorative only. The restricted values are deliberately NOT
				 * rendered here - a blur in CSS is not access control, and the
				 * real reference, contacts and criteria would otherwise sit in
				 * the page source for anyone who opened dev tools.
				 */
				?>
				<div class="tg-locked__blur" aria-hidden="true">
					<h3>Tender reference</h3>
					<p><?php echo esc_html( tg_redact( 'XXX/ICB/0000/000' ) ); ?></p>
					<h3>Full scope of works</h3>
					<p><?php echo esc_html( tg_redact( str_repeat( 'Restricted content available to registered suppliers only. ', 6 ) ) ); ?></p>
					<h3>Eligibility and qualification criteria</h3>
					<ul>
						<?php for ( $i = 0; $i < 3; $i++ ) : ?>
							<li><?php echo esc_html( tg_redact( 'Qualification criterion released to registered suppliers' ) ); ?></li>
						<?php endfor; ?>
					</ul>
					<h3>Buyer contact</h3>
					<p><?php echo esc_html( tg_redact( 'Contracting authority contact details' ) ); ?></p>
				</div>

				<div class="tg-locked__overlay">
					<span class="tg-locked__icon" aria-hidden="true">
						<svg width="26" height="26" viewBox="0 0 24 24" focusable="false"><path d="M7 10V7a5 5 0 0 1 10 0v3" fill="none" stroke="currentColor" stroke-width="2"/><rect x="4.5" y="10" width="15" height="10.5" rx="2" fill="currentColor"/></svg>
					</span>
					<h2>This tender is available to registered suppliers</h2>
					<p>Create a free supplier account to unlock the full tender file:</p>
					<ul class="tg-locked__list">
						<li>Official tender reference and procurement method</li>
						<li>Complete scope of works and technical requirements</li>
						<li>Eligibility, bid security and qualification criteria</li>
						<li>Named buyer contact, address and submission channel</li>
						<li>Downloadable tender documents and drawings</li>
					</ul>
					<div class="tg-locked__actions">
						<a class="tg-btn tg-btn--accent tg-btn--lg" href="<?php echo esc_url( TG_Auth::register_url( $return_to ) ); ?>">Register as a supplier</a>
						<a class="tg-btn tg-btn--ghost tg-btn--lg" href="<?php echo esc_url( TG_Auth::login_url( $return_to ) ); ?>">I already have an account</a>
					</div>
					<p class="tg-locked__note">Registration is free for Omani suppliers and takes about a minute.</p>
				</div>
			</section>
		</div>

		<aside class="tg-single__aside">
			<div class="tg-panel tg-panel--tight">
				<h3>At a glance</h3>
				<ul class="tg-glance">
					<li><span>Value</span><strong><?php echo esc_html( TG_API::format_value( $tender ) ); ?></strong></li>
					<li><span>Closes</span><strong><?php echo esc_html( tg_format_date( $tender['deadline'] ) ); ?></strong></li>
					<li><span>Source</span><strong><?php echo esc_html( $tender['source_name'] ); ?></strong></li>
				</ul>
			</div>
			<div class="tg-panel tg-panel--tight tg-panel--muted">
				<h3>Why register?</h3>
				<p>The gateway is operated to help Omani suppliers reach African public buyers. Registered suppliers receive tender alerts matched to their declared sectors.</p>
			</div>
		</aside>
	</div>
</div>
