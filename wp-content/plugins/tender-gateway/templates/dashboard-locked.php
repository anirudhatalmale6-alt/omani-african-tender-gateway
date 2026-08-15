<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tg-empty tg-empty--page">
	<h2>Please sign in to view your dashboard</h2>
	<p>Your watchlist, matched tenders and company profile are available to registered suppliers.</p>
	<div class="tg-locked__actions">
		<a class="tg-btn tg-btn--accent" href="<?php echo esc_url( TG_Auth::login_url( tg_page_url( 'dashboard' ) ) ); ?>">Sign in</a>
		<a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( TG_Auth::register_url( tg_page_url( 'dashboard' ) ) ); ?>">Register</a>
	</div>
</div>
