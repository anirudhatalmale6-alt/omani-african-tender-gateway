<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$user = wp_get_current_user();
?>
<div class="tg-empty tg-empty--page">
	<h2>You are already signed in</h2>
	<p>Signed in as <strong><?php echo esc_html( $user->display_name ); ?></strong>.</p>
	<div class="tg-locked__actions">
		<a class="tg-btn tg-btn--accent" href="<?php echo esc_url( tg_page_url( 'dashboard' ) ); ?>">Go to my dashboard</a>
		<a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( TG_Auth::logout_url() ); ?>">Sign out</a>
	</div>
</div>
