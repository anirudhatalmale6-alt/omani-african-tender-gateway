<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="tgt-shell tgt-pagebody">
	<div class="tg-empty tg-empty--page">
		<h2>Page not found</h2>
		<p>The page you were looking for has moved or no longer exists.</p>
		<div class="tg-locked__actions">
			<a class="tg-btn tg-btn--primary" href="<?php echo esc_url( home_url( '/' ) ); ?>">Back to the homepage</a>
			<a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">Browse tenders</a>
		</div>
	</div>
</div>

<?php get_footer(); ?>
