<?php
/**
 * Pretty URLs for tender detail pages: /tenders/view/{tender-id}/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Router {

	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'tg_tender';

		return $vars;
	}

	public static function tenders_slug() {
		$pages = get_option( 'tg_pages', array() );

		if ( ! empty( $pages['tenders'] ) ) {
			$page = get_post( $pages['tenders'] );
			if ( $page && 'publish' === $page->post_status ) {
				// get_page_uri handles the case where the page is nested.
				return get_page_uri( $page );
			}
		}

		return 'tenders';
	}

	public static function rewrites() {
		$slug = self::tenders_slug();

		add_rewrite_rule(
			'^' . preg_quote( $slug, '#' ) . '/view/([^/]+)/?$',
			'index.php?pagename=' . $slug . '&tg_tender=$matches[1]',
			'top'
		);
	}

	/**
	 * The tender id currently being viewed, if any.
	 *
	 * Falls back to ?tender= so detail links still work on installs where
	 * pretty permalinks are switched off.
	 */
	public static function current_tender_id() {
		$id = get_query_var( 'tg_tender' );

		if ( ! $id && isset( $_GET['tender'] ) ) {
			$id = sanitize_text_field( wp_unslash( $_GET['tender'] ) );
		}

		return $id ? rawurldecode( $id ) : '';
	}
}
