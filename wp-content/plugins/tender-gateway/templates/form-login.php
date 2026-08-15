<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notices    = TG_Auth::get_notices();
$redirect   = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
$tender     = '';
$tender_id  = '';

// If the visitor was sent here from a tender, show them which one.
if ( $redirect && preg_match( '#/view/([^/?]+)#', $redirect, $m ) ) {
	$tender_id = rawurldecode( $m[1] );
	$tender    = TG_API::get( $tender_id );
}
?>
<div class="tg-auth">
	<div class="tg-auth__form">
		<h1>Sign in</h1>
		<p class="tg-auth__lede">Access the full tender file, your watchlist and your sector alerts.</p>

		<?php if ( $tender ) : ?>
			<div class="tg-auth__context">
				<span>You are signing in to view</span>
				<strong><?php echo esc_html( $tender['title'] ); ?></strong>
			</div>
		<?php endif; ?>

		<?php foreach ( $notices['errors'] as $error ) : ?>
			<div class="tg-notice tg-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>

		<form method="post" class="tg-form">
			<input type="hidden" name="tg_action" value="login">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
			<?php wp_nonce_field( 'tg_login', 'tg_login_nonce' ); ?>

			<label class="tg-field tg-field--block">
				<span>Email address</span>
				<input type="text" name="tg_user" autocomplete="username" required>
			</label>

			<label class="tg-field tg-field--block">
				<span>Password</span>
				<input type="password" name="tg_pass" autocomplete="current-password" required>
			</label>

			<label class="tg-check">
				<input type="checkbox" name="tg_remember" value="1" checked>
				<span>Keep me signed in</span>
			</label>

			<button type="submit" class="tg-btn tg-btn--accent tg-btn--block tg-btn--lg">Sign in</button>
		</form>

		<p class="tg-auth__switch">
			New to the gateway?
			<a href="<?php echo esc_url( $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), tg_page_url( 'register' ) ) : tg_page_url( 'register' ) ); ?>">Register as a supplier</a>
		</p>
	</div>

	<aside class="tg-auth__side">
		<h2>Registered suppliers get</h2>
		<ul class="tg-ticks">
			<li>Complete tender documents and buyer contacts</li>
			<li>Alerts matched to your declared sectors</li>
			<li>A watchlist of the opportunities you are tracking</li>
			<li>Guidance from the Ministry trade desk</li>
		</ul>
		<div class="tg-auth__demo">
			<h3>Demonstration account</h3>
			<p>For this prototype you can sign in with:</p>
			<p><code>supplier@demo.om</code> / <code>demo1234</code></p>
		</div>
	</aside>
</div>
