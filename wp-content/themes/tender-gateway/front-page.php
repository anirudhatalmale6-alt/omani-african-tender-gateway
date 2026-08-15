<?php
/**
 * Homepage - the concept walkthrough the Ministry sees first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$featured = TG_API::query( array( 'per_page' => 6, 'sort' => 'deadline' ) );
$sectors  = TG_API::facet( 'sector' );
$markets  = TG_API::facet( 'country' );
arsort( $sectors );
?>

<section class="tgt-hero">
	<div class="tgt-shell tgt-hero__inner">
		<div class="tgt-hero__copy">
			<p class="tgt-hero__eyebrow">Oman &ndash; Africa Trade Programme</p>
			<h1>African government tenders,<br>delivered to Omani suppliers.</h1>
			<p class="tgt-hero__lede">
				A single, verified gateway to public procurement opportunities across African markets.
				Browse live tenders by country and sector, unlock the full tender file, and bid with
				support from the Ministry's trade desk.
			</p>
			<div class="tgt-hero__actions">
				<a class="tgt-btn tgt-btn--accent tgt-btn--lg" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">View tenders</a>
				<a class="tgt-btn tgt-btn--outline tgt-btn--lg" href="<?php echo esc_url( tg_page_url( 'register' ) ); ?>">Register as a supplier</a>
			</div>
			<p class="tgt-hero__trust">Free for Omani suppliers &middot; Registration takes about a minute</p>
		</div>

		<div class="tgt-hero__panel" aria-hidden="true">
			<div class="tgt-hero__panelhead">
				<span class="tgt-hero__dot"></span>
				Live tender feed
			</div>
			<ul class="tgt-hero__feed">
				<?php foreach ( array_slice( $featured['items'], 0, 4 ) as $tender ) : ?>
					<?php $days = TG_API::days_left( $tender ); ?>
					<li>
						<span class="tgt-hero__flag"><?php echo esc_html( tg_country_code( $tender['country'] ) ); ?></span>
						<span class="tgt-hero__feedtext">
							<strong><?php echo esc_html( wp_trim_words( $tender['title'], 8, '...' ) ); ?></strong>
							<em><?php echo esc_html( $tender['sector'] ); ?> &middot; <?php echo esc_html( TG_API::format_value( $tender ) ); ?></em>
						</span>
						<span class="tgt-hero__days"><?php echo esc_html( null === $days ? '-' : max( 0, $days ) . 'd' ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
	</div>
</section>

<section class="tgt-section tgt-section--band">
	<div class="tgt-shell">
		<?php echo do_shortcode( '[tg_stats]' ); ?>
	</div>
</section>

<section class="tgt-section">
	<div class="tgt-shell">
		<header class="tgt-sectionhead">
			<h2>How the gateway works</h2>
			<p>Four steps from a public opportunity to a submitted bid.</p>
		</header>

		<ol class="tgt-flow">
			<li>
				<span class="tgt-flow__num">1</span>
				<h3>Tenders arrive automatically</h3>
				<p>Opportunities are pulled from African government procurement portals through the gateway's tender API and published here within minutes.</p>
			</li>
			<li>
				<span class="tgt-flow__num">2</span>
				<h3>Anyone can browse</h3>
				<p>The country, sector, buyer, estimated value and deadline of every tender are public, so suppliers can see immediately whether it is worth pursuing.</p>
			</li>
			<li>
				<span class="tgt-flow__num">3</span>
				<h3>Registered suppliers unlock the file</h3>
				<p>Reference numbers, full scope of works, eligibility criteria, buyer contacts and tender documents are released to verified Omani suppliers.</p>
			</li>
			<li>
				<span class="tgt-flow__num">4</span>
				<h3>Alerts keep them ahead</h3>
				<p>Each supplier declares the sectors they serve and is matched to new tenders as they are published.</p>
			</li>
		</ol>
	</div>
</section>

<section class="tgt-section tgt-section--alt">
	<div class="tgt-shell">
		<header class="tgt-sectionhead">
			<h2>Key sectors</h2>
			<p>Where African public spending and Omani supply capability meet.</p>
		</header>

		<div class="tgt-sectors">
			<?php
			$icons = array(
				'Energy & Power'                => 'M13 2 4 14h6l-1 8 9-12h-6z',
				'Water & Sanitation'            => 'M12 3s6 6.6 6 10.4A6 6 0 0 1 6 13.4C6 9.6 12 3 12 3z',
				'Health & Medical Supplies'     => 'M9 3h6v6h6v6h-6v6H9v-6H3V9h6z',
				'Construction & Infrastructure' => 'M3 20h18M5 20V9l7-5 7 5v11M10 20v-6h4v6',
				'ICT & Digital Services'        => 'M3 5h18v11H3zM8 20h8M12 16v4',
				'Agriculture & Food Security'   => 'M12 21c0-7 4-11 9-11 0 7-4 11-9 11zm0 0c0-6-3.5-9-8-9 0 6 3.5 9 8 9z',
				'Transport & Logistics'         => 'M3 16V7h11v9M14 10h4l3 3v3M6.5 19a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3zm11 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3z',
				'Education & Training'          => 'M12 4 2 9l10 5 10-5zM6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5',
				'Oil, Gas & Petrochemicals'     => 'M6 21V8l6-5 6 5v13M10 21v-6h4v6',
				'Mining & Minerals'             => 'm3 12 6-9 6 9-6 9zM15 12l3-4.5L21 12l-3 4.5z',
			);

			foreach ( array_slice( $sectors, 0, 8, true ) as $sector => $count ) :
				$path = isset( $icons[ $sector ] ) ? $icons[ $sector ] : 'M4 4h16v16H4z';
				?>
				<a class="tgt-sector" href="<?php echo esc_url( add_query_arg( 'sector', rawurlencode( $sector ), tg_page_url( 'tenders' ) ) ); ?>">
					<span class="tgt-sector__icon" aria-hidden="true">
						<svg width="24" height="24" viewBox="0 0 24 24" focusable="false"><path d="<?php echo esc_attr( $path ); ?>" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round" stroke-linecap="round"/></svg>
					</span>
					<strong><?php echo esc_html( $sector ); ?></strong>
					<em><?php echo esc_html( sprintf( _n( '%d open tender', '%d open tenders', $count, 'tender-gateway' ), $count ) ); ?></em>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="tgt-section">
	<div class="tgt-shell">
		<header class="tgt-sectionhead tgt-sectionhead--split">
			<div>
				<h2>Closing soonest</h2>
				<p>A live sample of what registered suppliers are seeing today.</p>
			</div>
			<a class="tgt-btn tgt-btn--ghost" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">View all tenders</a>
		</header>

		<?php echo do_shortcode( '[tg_latest_tenders count="6"]' ); ?>
	</div>
</section>

<section class="tgt-section tgt-section--alt">
	<div class="tgt-shell">
		<header class="tgt-sectionhead">
			<h2>Markets covered</h2>
			<p>Public buyers currently publishing to the gateway.</p>
		</header>

		<ul class="tgt-markets">
			<?php foreach ( $markets as $market => $count ) : ?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'country', rawurlencode( $market ), tg_page_url( 'tenders' ) ) ); ?>">
						<span class="tgt-markets__code"><?php echo esc_html( tg_country_code( $market ) ); ?></span>
						<span class="tgt-markets__name"><?php echo esc_html( $market ); ?></span>
						<span class="tgt-markets__count"><?php echo esc_html( $count ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</section>

<section class="tgt-cta">
	<div class="tgt-shell tgt-cta__inner">
		<div>
			<h2>Ready to bid?</h2>
			<p>Register your company, declare the sectors you supply, and unlock every tender on the gateway.</p>
		</div>
		<div class="tgt-cta__actions">
			<a class="tgt-btn tgt-btn--accent tgt-btn--lg" href="<?php echo esc_url( tg_page_url( 'register' ) ); ?>">Register free</a>
			<a class="tgt-btn tgt-btn--outline tgt-btn--lg" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">Browse tenders first</a>
		</div>
	</div>
</section>

<?php get_footer(); ?>
