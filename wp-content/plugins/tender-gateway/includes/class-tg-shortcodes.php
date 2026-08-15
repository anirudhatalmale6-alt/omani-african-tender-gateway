<?php
/**
 * Shortcodes that render the supplier journey. Each one loads a template from
 * templates/, and a theme can override any of them by dropping a file of the
 * same name into a tender-gateway/ folder inside the theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Shortcodes {

	public static function init() {
		add_shortcode( 'tg_tenders', array( __CLASS__, 'tenders' ) );
		add_shortcode( 'tg_login', array( __CLASS__, 'login' ) );
		add_shortcode( 'tg_register', array( __CLASS__, 'register' ) );
		add_shortcode( 'tg_dashboard', array( __CLASS__, 'dashboard' ) );
		add_shortcode( 'tg_latest_tenders', array( __CLASS__, 'latest' ) );
		add_shortcode( 'tg_stats', array( __CLASS__, 'stats' ) );
	}

	/**
	 * Render a template, preferring a theme override.
	 */
	public static function render( $template, $vars = array() ) {
		$override = locate_template( array( 'tender-gateway/' . $template . '.php' ) );
		$path     = $override ? $override : TG_PATH . 'templates/' . $template . '.php';

		if ( ! file_exists( $path ) ) {
			return '';
		}

		if ( $vars ) {
			extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		}

		ob_start();
		include $path;

		return ob_get_clean();
	}

	public static function tenders( $atts ) {
		$tender_id = TG_Router::current_tender_id();

		if ( $tender_id ) {
			$tender = TG_API::get( $tender_id );

			if ( ! $tender ) {
				return self::render( 'tender-missing' );
			}

			// The gate: everything beyond the public summary needs an account.
			if ( ! is_user_logged_in() ) {
				return self::render( 'tender-locked', array( 'tender' => $tender ) );
			}

			return self::render( 'tender-single', array( 'tender' => $tender ) );
		}

		$atts = shortcode_atts( array( 'per_page' => 9 ), $atts, 'tg_tenders' );

		$get     = function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
		};
		$results = TG_API::query( array(
			'country'      => $get( 'country' ),
			'sector'       => $get( 'sector' ),
			'search'       => $get( 's_tender' ),
			'closing_days' => (int) $get( 'closing', 0 ),
			'sort'         => $get( 'sort', 'deadline' ),
			'per_page'     => (int) $atts['per_page'],
			'page'         => max( 1, (int) $get( 'tpage', 1 ) ),
		) );

		return self::render( 'tenders-archive', array(
			'results' => $results,
			'filters' => array(
				'country' => $get( 'country' ),
				'sector'  => $get( 'sector' ),
				'search'  => $get( 's_tender' ),
				'closing' => (int) $get( 'closing', 0 ),
				'sort'    => $get( 'sort', 'deadline' ),
			),
		) );
	}

	public static function login() {
		if ( is_user_logged_in() ) {
			return self::render( 'already-signed-in' );
		}

		return self::render( 'form-login' );
	}

	public static function register() {
		if ( is_user_logged_in() ) {
			return self::render( 'already-signed-in' );
		}

		return self::render( 'form-register' );
	}

	public static function dashboard() {
		if ( ! is_user_logged_in() ) {
			return self::render( 'dashboard-locked' );
		}

		return self::render( 'dashboard', array( 'user' => wp_get_current_user() ) );
	}

	public static function latest( $atts ) {
		$atts = shortcode_atts( array( 'count' => 6, 'sort' => 'deadline' ), $atts, 'tg_latest_tenders' );

		$results = TG_API::query( array(
			'per_page' => (int) $atts['count'],
			'sort'     => $atts['sort'],
		) );

		return self::render( 'tender-grid', array( 'tenders' => $results['items'] ) );
	}

	public static function stats() {
		return self::render( 'stats-bar' );
	}

	/**
	 * Shared card markup, used by the archive, the homepage and the dashboard.
	 */
	public static function card( $tender, $compact = false ) {
		return self::render( 'tender-card', array( 'tender' => $tender, 'compact' => $compact ) );
	}
}
