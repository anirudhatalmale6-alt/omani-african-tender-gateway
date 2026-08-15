<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$tender_id = class_exists( 'TG_Router' ) ? TG_Router::current_tender_id() : '';

// Screens that carry their own heading don't need the page banner on top of it.
$tg_pages   = get_option( 'tg_pages', array() );
$self_titled = array();
foreach ( array( 'login', 'register', 'dashboard' ) as $tg_slug ) {
	if ( ! empty( $tg_pages[ $tg_slug ] ) ) {
		$self_titled[] = (int) $tg_pages[ $tg_slug ];
	}
}
$show_head = ! $tender_id && ! in_array( get_queried_object_id(), $self_titled, true );
?>

<?php if ( $show_head ) : ?>
	<div class="tgt-pagehead">
		<div class="tgt-shell">
			<h1><?php the_title(); ?></h1>
		</div>
	</div>
<?php endif; ?>

<div class="tgt-shell tgt-pagebody">
	<?php
	while ( have_posts() ) :
		the_post();
		the_content();
	endwhile;
	?>
</div>

<?php get_footer(); ?>
