<?php
/**
 * Provenance line above the tender listing.
 *
 * Makes the integration visible on the site itself: where these records came
 * from, when they last arrived, and how many are held.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_live     = TG_API::is_live();
$count       = count( TG_API::all() );
$last_synced = $is_live ? TG_Store::last_synced( 'live' ) : 0;
?>
<div class="tg-source <?php echo $is_live ? 'tg-source--live' : 'tg-source--demo'; ?>">
	<span class="tg-source__dot" aria-hidden="true"></span>
	<span class="tg-source__text">
		<?php if ( $is_live ) : ?>
			<strong>Live tender feed</strong>
			<span>
				<?php echo esc_html( number_format_i18n( $count ) ); ?> tenders received from TendersOnTime
				<?php if ( $last_synced ) : ?>
					&middot; last updated <?php echo esc_html( human_time_diff( $last_synced ) ); ?> ago
				<?php endif; ?>
			</span>
		<?php else : ?>
			<strong>Demonstration feed</strong>
			<span><?php echo esc_html( number_format_i18n( $count ) ); ?> sample tenders, served through the live API integration layer</span>
		<?php endif; ?>
	</span>
</div>
