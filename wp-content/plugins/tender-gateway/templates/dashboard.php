<?php
/**
 * @var WP_User $user
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$company  = get_user_meta( $user->ID, 'tg_company', true );
$contact  = get_user_meta( $user->ID, 'tg_contact', true );
$sectors  = (array) get_user_meta( $user->ID, 'tg_sectors', true );
$sectors  = array_filter( $sectors );
$saved    = TG_Saved::tenders( $user->ID );
$welcome  = ! empty( $_GET['welcome'] );

// Tenders matching the supplier's declared sectors.
$matched = array();
foreach ( TG_API::all() as $tender ) {
	if ( $sectors && in_array( $tender['sector'], $sectors, true ) ) {
		$matched[] = $tender;
	}
}
usort( $matched, function ( $a, $b ) {
	return strtotime( $a['deadline'] ) <=> strtotime( $b['deadline'] );
} );

$closing_soon = 0;
foreach ( TG_API::all() as $tender ) {
	$days = TG_API::days_left( $tender );
	if ( null !== $days && $days >= 0 && $days <= 14 ) {
		$closing_soon++;
	}
}
?>
<div class="tg-dash">

	<?php if ( $welcome ) : ?>
		<div class="tg-notice tg-notice--success">
			<strong>Welcome to the gateway.</strong> Your supplier account is active and every tender is now unlocked.
		</div>
	<?php endif; ?>

	<header class="tg-dash__head">
		<div>
			<p class="tg-dash__eyebrow">Supplier dashboard</p>
			<h1><?php echo esc_html( $company ? $company : $user->display_name ); ?></h1>
			<p class="tg-dash__sub">Signed in as <?php echo esc_html( $contact ? $contact : $user->user_email ); ?></p>
		</div>
		<a class="tg-btn tg-btn--ghost" href="<?php echo esc_url( TG_Auth::logout_url() ); ?>">Sign out</a>
	</header>

	<div class="tg-stats">
		<div class="tg-stat">
			<strong><?php echo esc_html( number_format_i18n( count( TG_API::all() ) ) ); ?></strong>
			<span>Open tenders on the gateway</span>
		</div>
		<div class="tg-stat">
			<strong><?php echo esc_html( number_format_i18n( count( $matched ) ) ); ?></strong>
			<span>Matched to your sectors</span>
		</div>
		<div class="tg-stat">
			<strong><?php echo esc_html( number_format_i18n( count( $saved ) ) ); ?></strong>
			<span>On your watchlist</span>
		</div>
		<div class="tg-stat">
			<strong><?php echo esc_html( number_format_i18n( $closing_soon ) ); ?></strong>
			<span>Closing within 14 days</span>
		</div>
	</div>

	<section class="tg-dash__section">
		<div class="tg-dash__sectionhead">
			<h2>Matched to your sectors</h2>
			<a class="tg-link" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">Browse all tenders</a>
		</div>

		<?php if ( ! $sectors ) : ?>
			<div class="tg-empty">
				<h3>You have not declared any sectors yet</h3>
				<p>Add the sectors your company supplies and we will match new tenders to you automatically.</p>
			</div>
		<?php elseif ( ! $matched ) : ?>
			<div class="tg-empty">
				<h3>Nothing open in your sectors right now</h3>
				<p>We will alert you as soon as a matching tender is published.</p>
			</div>
		<?php else : ?>
			<?php echo TG_Shortcodes::render( 'tender-grid', array( 'tenders' => array_slice( $matched, 0, 6 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
	</section>

	<section class="tg-dash__section">
		<h2>Your watchlist</h2>
		<?php if ( ! $saved ) : ?>
			<div class="tg-empty">
				<h3>Your watchlist is empty</h3>
				<p>Open any tender and choose "Save to watchlist" to keep track of the bids you are preparing.</p>
				<a class="tg-btn tg-btn--primary" href="<?php echo esc_url( tg_page_url( 'tenders' ) ); ?>">Find tenders</a>
			</div>
		<?php else : ?>
			<?php echo TG_Shortcodes::render( 'tender-grid', array( 'tenders' => $saved ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<?php endif; ?>
	</section>

	<section class="tg-dash__section tg-dash__section--profile">
		<h2>Company profile</h2>
		<div class="tg-profile">
			<dl class="tg-datalist">
				<div><dt>Company</dt><dd><?php echo esc_html( $company ? $company : '-' ); ?></dd></div>
				<div><dt>Contact</dt><dd><?php echo esc_html( $contact ? $contact : '-' ); ?></dd></div>
				<div><dt>Email</dt><dd><?php echo esc_html( $user->user_email ); ?></dd></div>
				<div><dt>Telephone</dt><dd><?php echo esc_html( get_user_meta( $user->ID, 'tg_phone', true ) ?: '-' ); ?></dd></div>
				<div><dt>CR number</dt><dd><?php echo esc_html( get_user_meta( $user->ID, 'tg_cr', true ) ?: '-' ); ?></dd></div>
				<div><dt>Country</dt><dd><?php echo esc_html( get_user_meta( $user->ID, 'tg_country', true ) ?: '-' ); ?></dd></div>
			</dl>
			<div class="tg-profile__sectors">
				<h3>Declared sectors</h3>
				<?php if ( $sectors ) : ?>
					<ul class="tg-taglist">
						<?php foreach ( $sectors as $sector ) : ?>
							<li><?php echo esc_html( $sector ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p>None declared.</p>
				<?php endif; ?>
			</div>
		</div>
		<p class="tg-note">Profile editing, document uploads and company verification are part of the full platform build.</p>
	</section>
</div>
