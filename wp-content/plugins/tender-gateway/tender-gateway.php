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
require_once TG_PATH . 'includes/class-tg-store.php';
require_once TG_PATH . 'includes/class-tg-status.php';
require_once TG_PATH . 'includes/class-tg-tender.php';
require_once TG_PATH . 'includes/class-tg-docs.php';
require_once TG_PATH . 'includes/class-tg-admin-tenders.php';
require_once TG_PATH . 'includes/class-tg-sync.php';
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
		// Some feeds (TendersOnTime among them) want a username alongside the
		// key, both in the query string.
		'auth_user_param' => 'username',
		'auth_user'       => '',
		'daily_call_cap'  => 25,        // hard stop; trial plans are metered
		'format'          => 'auto',     // auto | json | xml
		'results_path'    => '',         // dot path to the array inside the response, e.g. "data.tenders"
		'date_param'      => 'posting_date',
		'date_format'     => 'Y-m-d',
		'sync_days'       => 3,          // posting dates to walk on each sync
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
			// Flat alternatives to the nested contact/documents blocks. Left
			// empty by default so a feed that already nests them is unaffected.
			'contact_email'   => '',
			'contact_website' => '',
			'contact_address' => '',
			'document_url'    => '',
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
	TG_Sync::init();
	TG_Docs::init();
	TG_Admin_Tenders::init();

	// Schema changes ship with plugin updates, so apply them on load rather
	// than only on activation - an updated plugin is rarely reactivated.
	TG_Store::maybe_install();
} );

/**
 * Scheduled opening and automatic closing.
 *
 * Correctness does not depend on this running on time: TG_Tender::accepts_bids()
 * re-checks the closing time on every submission, so a late cron can delay the
 * status flip but can never let a late bid through. This keeps the displayed
 * status honest.
 */
add_filter( 'cron_schedules', function ( $schedules ) {
	if ( ! isset( $schedules['tg_five_minutes'] ) ) {
		$schedules['tg_five_minutes'] = array(
			'interval' => 300,
			'display'  => 'Every five minutes (Tender Gateway)',
		);
	}

	return $schedules;
} );

add_action( 'init', function () {
	if ( ! wp_next_scheduled( 'tg_run_schedule' ) ) {
		wp_schedule_event( time() + 60, 'tg_five_minutes', 'tg_run_schedule' );
	}
} );

add_action( 'tg_run_schedule', array( 'TG_Tender', 'run_schedule' ) );

// Safety net: if cron is disabled or unreliable on the host, an admin opening
// the tender screens still sees accurate statuses.
add_action( 'admin_init', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$last = (int) get_transient( 'tg_schedule_ran' );

	if ( $last ) {
		return;
	}

	set_transient( 'tg_schedule_ran', time(), 300 );
	TG_Tender::run_schedule();
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
	TG_Sync::clear_schedule();
	wp_clear_scheduled_hook( 'tg_run_schedule' );
	flush_rewrite_rules();
} );
