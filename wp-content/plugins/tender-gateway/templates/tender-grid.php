<?php
/**
 * @var array $tenders
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tg-grid">
	<?php foreach ( $tenders as $tender ) : ?>
		<?php echo TG_Shortcodes::card( $tender ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<?php endforeach; ?>
</div>
