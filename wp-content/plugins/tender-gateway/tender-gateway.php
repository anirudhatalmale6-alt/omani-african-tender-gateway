<?php
/**
 * Plugin Name:  Tender Gateway
 * Description:  Tender feed, supplier registration and members-only tender access for the Omani-African Tender Gateway.
 * Version:      1.0.0
 * Author:       Anirudha Talmale
 * Text Domain:  tender-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TG_VERSION', '1.0.0' );
define( 'TG_FILE', __FILE__ );
define( 'TG_PATH', plugin_dir_path( __FILE__ ) );
define( 'TG_URL', plugin_dir_url( __FILE__ ) );

require_once TG_PATH . 'includes/class-tg-sample-data.php';
require_once TG_PATH . 'includes/class-tg-api.php';
require_once TG_PATH . 'includes/class-tg-router.php';
require_once TG_PATH . 'includes/class-tg-auth.php';
require_once TG_PATH . 'includes/class-tg-saved.php';
require_once TG_PATH . 'includes/class-tg-shortcodes.php';
require_once TG_PATH . 'includes/class-tg-admin.php';
require_once TG_PATH . 'includes/class-tg-install.php';

/**
 * Default settings. Everything the Ministry demo needs to switch from bundled
 * sample data to the live tender API lives here.
 */
function tg_default_settings() {
	return array(
		'source'          => 'sample',   // sample | remote
		'endpoint'        => '',
		'api_key'         => '',
		'auth_style'      => 'bearer',   // bearer | header | query
		'auth_header'     => 'X-API-Key',
		'auth_query_key'  => 'api_key',
		'results_path'    => '',         // dot path to the array inside the response, e.g. "data.tenders"
		'cache_minutes'   => 30,
		'map'             => array(
			'id'          => 'id',
			'title'       => 'title',
			'buyer'       => 'buyer',
			'country'     => 'country',
			'sector'      => 'sector',
			'summary'     => 'summary',
			'description' => 'description',
			'value'       => 'value',
			'currency'    => 'currency',
			'published'   => 'published',
			'deadline'    => 'deadline',
			'method'      => 'method',
			'reference'   => 'reference',
			'source_name' => 'source',
		),
	);
}

function tg_settings() {
	$saved = get_option( 'tg_settings', array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	$settings        = array_merge( tg_default_settings(), $saved );
	$settings['map'] = array_merge( tg_default_settings()['map'], isset( $saved['map'] ) && is_array( $saved['map'] ) ? $saved['map'] : array() );

	return $settings;
}

function tg_setting( $key, $fallback = '' ) {
	$settings = tg_settings();

	return isset( $settings[ $key ] ) ? $settings[ $key ] : $fallback;
}

/**
 * Permalink of one of the plugin's pages, e.g. tg_page_url( 'tenders' ).
 */
function tg_page_url( $slug, $args = array() ) {
	$page_ids = get_option( 'tg_pages', array() );
	$url      = '';

	if ( isset( $page_ids[ $slug ] ) ) {
		$permalink = get_permalink( $page_ids[ $slug ] );
		if ( $permalink ) {
			$url = $permalink;
		}
	}

	if ( ! $url ) {
		$url = home_url( '/' . $slug . '/' );
	}

	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Turn a string into redaction bars of the same shape.
 *
 * Used behind the members-only blur so the teaser looks like a real document
 * without any restricted value actually reaching the page source.
 */
function tg_redact( $text ) {
	return preg_replace( '/\S/u', "\u{2593}", $text );
}

/**
 * Format an ISO date for display, tolerating a missing value.
 */
function tg_format_date( $date ) {
	$date = trim( (string) $date );
	if ( '' === $date ) {
		return 'On request';
	}

	$time = strtotime( $date );

	return $time ? date_i18n( 'j F Y', $time ) : $date;
}

/**
 * Two-letter code used as the little country badge on tender cards.
 */
function tg_country_code( $country ) {
	$known = TG_Sample_Data::countries();

	if ( isset( $known[ $country ] ) ) {
		return $known[ $country ];
	}

	// Unknown country coming from the live API: derive something sensible.
	$letters = preg_replace( '/[^A-Za-z]/', '', $country );

	return strtoupper( substr( $letters ? $letters : '??', 0, 2 ) );
}

function tg_tender_url( $tender_id ) {
	return trailingslashit( tg_page_url( 'tenders' ) ) . 'view/' . rawurlencode( $tender_id ) . '/';
}

add_action( 'plugins_loaded', function () {
	TG_Router::init();
	TG_Auth::init();
	TG_Saved::init();
	TG_Shortcodes::init();
	TG_Admin::init();
} );

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_style( 'tender-gateway', TG_URL . 'assets/tender-gateway.css', array(), TG_VERSION );
	wp_enqueue_script( 'tender-gateway', TG_URL . 'assets/tender-gateway.js', array(), TG_VERSION, true );
	wp_localize_script( 'tender-gateway', 'TGConfig', array(
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'tg_ajax' ),
	) );
} );

register_activation_hook( __FILE__, array( 'TG_Install', 'activate' ) );
register_deactivation_hook( __FILE__, function () {
	flush_rewrite_rules();
} );
