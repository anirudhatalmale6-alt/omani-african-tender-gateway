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
		<?php
		// Not every notice carries a reference number. Printing the word
		// "Reference" in front of nothing reads as a missing value rather than
		// as a field the source simply does not publish.
		$ref_bits = array();
		if ( ! empty( $tender['reference'] ) ) {
			$ref_bits[] = 'Reference <strong>' . esc_html( $tender['reference'] ) . '</strong>';
		}
		if ( ! empty( $tender['method'] ) ) {
			$ref_bits[] = esc_html( $tender['method'] );
		}
		?>
		<?php if ( $ref_bits ) : ?>
			<p class="tg-single__ref"><?php echo implode( ' &middot; ', $ref_bits ); // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
		<?php endif; ?>
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
					<?php $has_real_file = false; ?>
						<?php
						foreach ( (array) $tender['documents'] as $doc ) :
							// Sample records carry a label only. Live records carry the
							// real notice URL, so that one has to be a working link -
							// a dead "Download" in front of a buyer is worse than none.
							$label = '';
							foreach ( array( 'label', 'title' ) as $key ) {
								if ( ! empty( $doc[ $key ] ) ) {
									$label = $doc[ $key ];
									break;
								}
							}
							$url = isset( $doc['url'] ) ? trim( (string) $doc['url'] ) : '';
							$url = $url ? esc_url( $url ) : '';
							if ( $url ) {
								$has_real_file = true;
							}
							?>
							<li>
								<span class="tg-docs__icon" aria-hidden="true">
									<svg width="16" height="16" viewBox="0 0 16 16" focusable="false"><path d="M4 1.5h5l3 3v10H4z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M9 1.5v3h3" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
								</span>
								<span class="tg-docs__label"><?php echo esc_html( $label ); ?></span>
								<span class="tg-docs__size"><?php echo esc_html( isset( $doc['size'] ) ? $doc['size'] : '' ); ?></span>
								<?php if ( $url ) : ?>
									<a class="tg-docs__action" href="<?php echo $url; // phpcs:ignore WordPress.Security.EscapeOutput ?>" target="_blank" rel="noopener nofollow">Open document</a>
								<?php else : ?>
									<span class="tg-docs__action">Download</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( ! $has_real_file ) : ?>
						<p class="tg-note">In the live platform these files are served from the source procurement portal via the tender API.</p>
					<?php endif; ?>
				</section>
			<?php endif; ?>

			<?php
			// Build the rows first, then decide whether the panel is worth
			// drawing. Testing the raw contact array is not enough: a record
			// whose only detail is one this template does not print renders a
			// heading over an empty box, which is what the buyer sees.
			$contact_rows = array();
			foreach ( array(
				'name'    => 'Contact',
				'org'     => 'Organisation',
				'address' => 'Address',
				'email'   => 'Email',
				'phone'   => 'Telephone',
				'website' => 'Website',
			) as $contact_key => $contact_label ) {
				if ( empty( $tender['contact'][ $contact_key ] ) ) {
					continue;
				}
				$contact_rows[] = array(
					'label' => $contact_label,
					'value' => $tender['contact'][ $contact_key ],
					'is_url' => ( 'website' === $contact_key ),
				);
			}
			?>
			<?php if ( $contact_rows ) : ?>
				<section class="tg-panel">
					<h2>Buyer contact and submission</h2>
					<dl class="tg-datalist">
						<?php foreach ( $contact_rows as $row ) : ?>
							<div>
								<dt><?php echo esc_html( $row['label'] ); ?></dt>
								<dd>
									<?php if ( $row['is_url'] && esc_url( $row['value'] ) ) : ?>
										<a href="<?php echo esc_url( $row['value'] ); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html( $row['value'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $row['value'] ); ?>
									<?php endif; ?>
								</dd>
							</div>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endif; ?>
		</div>

		<aside class="tg-single__aside">
			<div class="tg-panel tg-panel--tight">
				<h3>Key dates and figures</h3>
				<ul class="tg-glance">
					<li><span>Estimated value</span><strong><?php echo esc_html( TG_API::format_value( $tender ) ); ?></strong></li>
					<?php if ( ! empty( $tender['published'] ) ) : ?>
						<li><span>Published</span><strong><?php echo esc_html( tg_format_date( $tender['published'] ) ); ?></strong></li>
					<?php endif; ?>
					<?php if ( ! empty( $tender['deadline'] ) ) : ?>
						<li><span>Deadline</span><strong><?php echo esc_html( tg_format_date( $tender['deadline'] ) ); ?></strong></li>
					<?php endif; ?>
					<?php if ( ! empty( $tender['method'] ) ) : ?>
						<li><span>Procurement method</span><strong><?php echo esc_html( $tender['method'] ); ?></strong></li>
					<?php endif; ?>
					<?php if ( ! empty( $tender['source_name'] ) ) : ?>
						<li><span>Source</span><strong><?php echo esc_html( $tender['source_name'] ); ?></strong></li>
					<?php endif; ?>
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

	<?php
	/**
	 * Bidding panel. Only fires for tenders the platform owns - records from
	 * the Ministry feed are read-only and have nothing to bid on.
	 */
	do_action( 'tg_after_tender_detail', $tender );
	?>
</div>
