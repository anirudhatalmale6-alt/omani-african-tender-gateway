<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="color-scheme" content="light">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="tgt-skip" href="#tgt-main">Skip to content</a>

<div class="tgt-topbar">
	<div class="tgt-shell tgt-topbar__inner">
		<span class="tgt-topbar__gov">An initiative of the Ministry of Commerce, Industry and Investment Promotion &middot; Sultanate of Oman</span>
		<span class="tgt-topbar__lang" aria-hidden="true">EN &middot; <span class="tgt-topbar__ar">العربية</span></span>
	</div>
</div>

<header class="tgt-header">
	<div class="tgt-shell tgt-header__inner">
		<a class="tgt-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<span class="tgt-brand__mark" aria-hidden="true">
				<svg width="34" height="34" viewBox="0 0 34 34" focusable="false">
					<rect width="34" height="34" rx="6" fill="#0b2545"/>
					<path d="M9 23.5V13l8-4.5 8 4.5v10.5" fill="none" stroke="#fff" stroke-width="1.9" stroke-linejoin="round"/>
					<path d="M13 23.5v-5.5h8v5.5" fill="none" stroke="#a8112e" stroke-width="1.9" stroke-linejoin="round"/>
				</svg>
			</span>
			<span class="tgt-brand__text">
				<strong><?php bloginfo( 'name' ); ?></strong>
				<em><?php echo esc_html( get_bloginfo( 'description' ) ? get_bloginfo( 'description' ) : 'Omani suppliers &middot; African public tenders' ); ?></em>
			</span>
		</a>

		<button class="tgt-burger" type="button" aria-expanded="false" aria-controls="tgt-nav">
			<span class="tgt-sr">Menu</span>
			<span class="tgt-burger__bars" aria-hidden="true"></span>
		</button>

		<nav class="tgt-nav" id="tgt-nav" aria-label="Primary">
			<?php tgt_primary_nav(); ?>

			<div class="tgt-nav__actions">
				<?php if ( is_user_logged_in() ) : ?>
					<a class="tgt-btn tgt-btn--ghost" href="<?php echo esc_url( tg_page_url( 'dashboard' ) ); ?>">My dashboard</a>
					<a class="tgt-btn tgt-btn--accent" href="<?php echo esc_url( TG_Auth::logout_url() ); ?>">Sign out</a>
				<?php else : ?>
					<a class="tgt-btn tgt-btn--ghost" href="<?php echo esc_url( tg_page_url( 'login' ) ); ?>">Sign in</a>
					<a class="tgt-btn tgt-btn--accent" href="<?php echo esc_url( tg_page_url( 'register' ) ); ?>">Register</a>
				<?php endif; ?>
			</div>
		</nav>
	</div>
</header>

<main id="tgt-main" class="tgt-main">
