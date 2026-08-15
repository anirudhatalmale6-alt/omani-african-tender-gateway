<?php
/**
 * Tender Gateway theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TGT_VERSION', '1.0.0' );

require_once get_template_directory() . '/inc/setup.php';

add_action( 'after_setup_theme', function () {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'responsive-embeds' );

	register_nav_menus( array(
		'primary' => 'Primary navigation',
		'footer'  => 'Footer links',
	) );
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'tgt-style', get_stylesheet_uri(), array(), TGT_VERSION );
	wp_enqueue_script( 'tgt-nav', get_template_directory_uri() . '/assets/nav.js', array(), TGT_VERSION, true );
}, 5 );

/**
 * Primary navigation, falling back to the plugin's pages before any menu is
 * assigned - the demo should never render a bare, link-less header.
 */
function tgt_primary_nav() {
	if ( has_nav_menu( 'primary' ) ) {
		wp_nav_menu( array(
			'theme_location' => 'primary',
			'container'      => false,
			'menu_class'     => 'tgt-nav__list',
			'depth'          => 2,
		) );

		return;
	}

	$url = function ( $slug ) {
		return function_exists( 'tg_page_url' ) ? tg_page_url( $slug ) : home_url( '/' . $slug . '/' );
	};

	$items = array(
		home_url( '/' )            => 'Home',
		$url( 'tenders' )          => 'Tenders',
		$url( 'how-it-works' )     => 'How it works',
		$url( 'about' )            => 'About',
		$url( 'contact' )          => 'Contact',
	);

	echo '<ul class="tgt-nav__list">';
	foreach ( $items as $url => $label ) {
		printf(
			'<li class="menu-item"><a href="%s">%s</a></li>',
			esc_url( $url ),
			esc_html( $label )
		);
	}
	echo '</ul>';
}

/**
 * Pages that exist purely as static content for the demo.
 */
function tgt_content_pages() {
	return array(
		'how-it-works' => 'How It Works',
		'about'        => 'About the Gateway',
		'contact'      => 'Contact',
	);
}

/**
 * Body classes that let the stylesheet treat the plugin pages as full-width
 * application screens rather than narrow article pages.
 */
add_filter( 'body_class', function ( $classes ) {
	$pages = get_option( 'tg_pages', array() );

	if ( is_page() && in_array( get_queried_object_id(), array_map( 'intval', (array) $pages ), true ) ) {
		$classes[] = 'tgt-app-page';
	}

	if ( class_exists( 'TG_Router' ) && TG_Router::current_tender_id() ) {
		$classes[] = 'tgt-tender-page';
	}

	return $classes;
} );

/**
 * Use the tender title as the document title on tender detail URLs.
 */
add_filter( 'document_title_parts', function ( $parts ) {
	if ( ! class_exists( 'TG_Router' ) ) {
		return $parts;
	}

	$id = TG_Router::current_tender_id();
	if ( ! $id ) {
		return $parts;
	}

	$tender = TG_API::get( $id );
	if ( $tender ) {
		$parts['title'] = $tender['title'];
	}

	return $parts;
} );
