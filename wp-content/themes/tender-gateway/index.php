<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>

<div class="tgt-pagehead">
	<div class="tgt-shell">
		<h1><?php echo esc_html( is_search() ? sprintf( 'Search results for "%s"', get_search_query() ) : wp_get_document_title() ); ?></h1>
	</div>
</div>

<div class="tgt-shell tgt-pagebody">
	<?php if ( have_posts() ) : ?>
		<div class="tgt-postlist">
			<?php
			while ( have_posts() ) :
				the_post();
				?>
				<article class="tgt-postcard">
					<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<p><?php echo esc_html( get_the_excerpt() ); ?></p>
				</article>
			<?php endwhile; ?>
		</div>
		<?php the_posts_pagination(); ?>
	<?php else : ?>
		<div class="tg-empty tg-empty--page">
			<h2>Nothing found</h2>
			<p>Try the tender search instead.</p>
			<a class="tg-btn tg-btn--primary" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">Browse tenders</a>
		</div>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
