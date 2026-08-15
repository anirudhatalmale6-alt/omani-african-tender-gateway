<?php
/**
 * @var array $results
 * @var array $filters
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$countries = TG_API::facet( 'country' );
$sectors   = TG_API::facet( 'sector' );
$base      = tg_page_url( 'tenders' );
?>
<div class="tg-archive">

	<?php echo TG_Shortcodes::render( 'source-badge' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>

	<form class="tg-filters" method="get" action="<?php echo esc_url( $base ); ?>">
		<div class="tg-filters__search">
			<label class="tg-sr" for="tg-s">Search tenders</label>
			<svg class="tg-filters__icon" width="16" height="16" viewBox="0 0 16 16" aria-hidden="true" focusable="false"><circle cx="7" cy="7" r="4.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m10.5 10.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			<input type="search" id="tg-s" name="s_tender" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="Search by keyword, buyer or reference">
		</div>

		<div class="tg-filters__row">
			<label class="tg-field">
				<span>Country</span>
				<select name="country">
					<option value="">All countries</option>
					<?php foreach ( $countries as $country => $count ) : ?>
						<option value="<?php echo esc_attr( $country ); ?>" <?php selected( $filters['country'], $country ); ?>>
							<?php echo esc_html( $country . ' (' . $count . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="tg-field">
				<span>Sector</span>
				<select name="sector">
					<option value="">All sectors</option>
					<?php foreach ( $sectors as $sector => $count ) : ?>
						<option value="<?php echo esc_attr( $sector ); ?>" <?php selected( $filters['sector'], $sector ); ?>>
							<?php echo esc_html( $sector . ' (' . $count . ')' ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>

			<label class="tg-field">
				<span>Closing within</span>
				<select name="closing">
					<option value="0" <?php selected( $filters['closing'], 0 ); ?>>Any time</option>
					<option value="14" <?php selected( $filters['closing'], 14 ); ?>>14 days</option>
					<option value="30" <?php selected( $filters['closing'], 30 ); ?>>30 days</option>
					<option value="60" <?php selected( $filters['closing'], 60 ); ?>>60 days</option>
				</select>
			</label>

			<label class="tg-field">
				<span>Sort by</span>
				<select name="sort">
					<option value="deadline" <?php selected( $filters['sort'], 'deadline' ); ?>>Closing soonest</option>
					<option value="published" <?php selected( $filters['sort'], 'published' ); ?>>Most recent</option>
					<option value="value" <?php selected( $filters['sort'], 'value' ); ?>>Highest value</option>
				</select>
			</label>

			<button type="submit" class="tg-btn tg-btn--primary">Apply filters</button>
			<?php if ( $filters['search'] || $filters['country'] || $filters['sector'] || $filters['closing'] ) : ?>
				<a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( $base ); ?>">Reset</a>
			<?php endif; ?>
		</div>
	</form>

	<p class="tg-archive__count">
		<strong><?php echo esc_html( number_format_i18n( $results['total'] ) ); ?></strong>
		<?php echo esc_html( _n( 'open opportunity', 'open opportunities', $results['total'], 'tender-gateway' ) ); ?>
		<?php if ( $results['pages'] > 1 ) : ?>
			<span class="tg-archive__page">Page <?php echo esc_html( $results['page'] ); ?> of <?php echo esc_html( $results['pages'] ); ?></span>
		<?php endif; ?>
	</p>

	<?php if ( ! $results['items'] ) : ?>
		<div class="tg-empty">
			<h3>No tenders match those filters</h3>
			<p>Try widening the country or sector, or clear the filters to see every open opportunity.</p>
			<a class="tg-btn tg-btn--primary" href="<?php echo esc_url( $base ); ?>">Show all tenders</a>
		</div>
	<?php else : ?>
		<?php echo TG_Shortcodes::render( 'tender-grid', array( 'tenders' => $results['items'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endif; ?>

	<?php if ( $results['pages'] > 1 ) : ?>
		<nav class="tg-pagination" aria-label="Tender pages">
			<?php
			$query_base = array_filter( array(
				's_tender' => $filters['search'],
				'country'  => $filters['country'],
				'sector'   => $filters['sector'],
				'closing'  => $filters['closing'] ? $filters['closing'] : '',
				'sort'     => $filters['sort'],
			) );

			for ( $i = 1; $i <= $results['pages']; $i++ ) :
				$url = add_query_arg( array_merge( $query_base, array( 'tpage' => $i ) ), $base );
				?>
				<a class="tg-pagination__link <?php echo $i === $results['page'] ? 'is-current' : ''; ?>"
				   href="<?php echo esc_url( $url ); ?>"
				   <?php echo $i === $results['page'] ? 'aria-current="page"' : ''; ?>>
					<?php echo esc_html( $i ); ?>
				</a>
			<?php endfor; ?>
		</nav>
	<?php endif; ?>

	<?php if ( ! is_user_logged_in() ) : ?>
		<aside class="tg-cta-band">
			<div>
				<h3>Register to see the full tender file</h3>
				<p>Reference numbers, buyer contacts, eligibility criteria, bid security and downloadable documents are released to registered Omani suppliers.</p>
			</div>
			<a class="tg-btn tg-btn--accent" href="<?php echo esc_url( TG_Auth::register_url( $base ) ); ?>">Register free</a>
		</aside>
	<?php endif; ?>
</div>
