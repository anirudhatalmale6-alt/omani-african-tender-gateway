<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="tg-empty tg-empty--page">
	<h2>Buyer portal</h2>
	<p>Organisations publishing tenders on Jumla Tender sign in here to create tenders and follow them through to award.</p>
	<div class="tg-locked__actions">
		<a class="tg-btn tg-btn--accent" href="<?php echo esc_url( TG_Auth::login_url( TG_Buyer::portal_url() ) ); ?>">Sign in</a>
	</div>
	<p class="tg-note" style="margin-top:14px;">Buyer accounts are set up by the Jumla Tender team. If your organisation would like to publish tenders, please contact us.</p>
</div>
