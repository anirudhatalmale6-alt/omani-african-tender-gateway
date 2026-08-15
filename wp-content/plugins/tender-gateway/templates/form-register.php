<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$notices  = TG_Auth::get_notices();
$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
$old      = function ( $key ) {
	return isset( $_POST[ $key ] ) ? esc_attr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ) : '';
};
$checked  = isset( $_POST['tg_sectors'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tg_sectors'] ) ) : array();
?>
<div class="tg-auth tg-auth--wide">
	<div class="tg-auth__form">
		<h1>Register as a supplier</h1>
		<p class="tg-auth__lede">Free for Omani suppliers. Takes about a minute and unlocks every tender on the gateway.</p>

		<?php foreach ( $notices['errors'] as $error ) : ?>
			<div class="tg-notice tg-notice--error"><?php echo esc_html( $error ); ?></div>
		<?php endforeach; ?>

		<form method="post" class="tg-form">
			<input type="hidden" name="tg_action" value="register">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect ); ?>">
			<?php wp_nonce_field( 'tg_register', 'tg_register_nonce' ); ?>

			<fieldset class="tg-fieldset">
				<legend>Company</legend>
				<div class="tg-form__row">
					<label class="tg-field tg-field--block">
						<span>Company name <em>*</em></span>
						<input type="text" name="tg_company" value="<?php echo $old( 'tg_company' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" required>
					</label>
					<label class="tg-field tg-field--block">
						<span>Commercial registration (CR) number</span>
						<input type="text" name="tg_cr" value="<?php echo $old( 'tg_cr' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" placeholder="e.g. 1234567">
					</label>
				</div>
				<label class="tg-field tg-field--block">
					<span>Country of registration</span>
					<select name="tg_country">
						<?php
						$countries = array( 'Oman', 'United Arab Emirates', 'Saudi Arabia', 'Qatar', 'Kuwait', 'Bahrain', 'Other' );
						$selected  = $old( 'tg_country' ) ? $old( 'tg_country' ) : 'Oman';
						foreach ( $countries as $country ) :
							?>
							<option value="<?php echo esc_attr( $country ); ?>" <?php selected( $selected, $country ); ?>><?php echo esc_html( $country ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			</fieldset>

			<fieldset class="tg-fieldset">
				<legend>Primary contact</legend>
				<div class="tg-form__row">
					<label class="tg-field tg-field--block">
						<span>Full name <em>*</em></span>
						<input type="text" name="tg_contact" value="<?php echo $old( 'tg_contact' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" required>
					</label>
					<label class="tg-field tg-field--block">
						<span>Telephone</span>
						<input type="tel" name="tg_phone" value="<?php echo $old( 'tg_phone' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" placeholder="+968 ...">
					</label>
				</div>
				<div class="tg-form__row">
					<label class="tg-field tg-field--block">
						<span>Work email <em>*</em></span>
						<input type="email" name="tg_email" value="<?php echo $old( 'tg_email' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>" autocomplete="email" required>
					</label>
					<label class="tg-field tg-field--block">
						<span>Password <em>*</em></span>
						<input type="password" name="tg_pass" autocomplete="new-password" minlength="8" required>
						<small>At least 8 characters.</small>
					</label>
				</div>
			</fieldset>

			<fieldset class="tg-fieldset">
				<legend>Sectors you supply</legend>
				<p class="tg-fieldset__hint">We use these to match tender alerts to your business. Choose as many as apply.</p>
				<div class="tg-checks">
					<?php foreach ( TG_Sample_Data::sectors() as $sector ) : ?>
						<label class="tg-check tg-check--pill">
							<input type="checkbox" name="tg_sectors[]" value="<?php echo esc_attr( $sector ); ?>" <?php checked( in_array( $sector, $checked, true ) ); ?>>
							<span><?php echo esc_html( $sector ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>

			<button type="submit" class="tg-btn tg-btn--accent tg-btn--block tg-btn--lg">Create my supplier account</button>
			<p class="tg-form__legal">By registering you agree that your company details may be shared with the Ministry for verification purposes.</p>
		</form>

		<p class="tg-auth__switch">
			Already registered?
			<a href="<?php echo esc_url( $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), tg_page_url( 'login' ) ) : tg_page_url( 'login' ) ); ?>">Sign in</a>
		</p>
	</div>

	<aside class="tg-auth__side">
		<h2>What happens next</h2>
		<ol class="tg-steps">
			<li><strong>Register</strong><span>Your account is created immediately.</span></li>
			<li><strong>Unlock tenders</strong><span>Full documents, criteria and buyer contacts.</span></li>
			<li><strong>Get matched</strong><span>Alerts for new tenders in your sectors.</span></li>
			<li><strong>Bid with support</strong><span>Ministry trade desk guidance where needed.</span></li>
		</ol>
	</aside>
</div>
