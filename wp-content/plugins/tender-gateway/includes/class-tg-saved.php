<?php
/**
 * Saved ("watchlist") tenders for logged-in suppliers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Saved {

	const META = 'tg_saved_tenders';

	public static function init() {
		add_action( 'wp_ajax_tg_toggle_saved', array( __CLASS__, 'ajax_toggle' ) );
		add_action( 'wp_ajax_nopriv_tg_toggle_saved', array( __CLASS__, 'ajax_denied' ) );
	}

	public static function ids( $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return array();
		}

		$ids = get_user_meta( $user_id, self::META, true );

		return is_array( $ids ) ? $ids : array();
	}

	public static function has( $tender_id, $user_id = 0 ) {
		return in_array( (string) $tender_id, self::ids( $user_id ), true );
	}

	public static function toggle( $tender_id, $user_id = 0 ) {
		$user_id = $user_id ? $user_id : get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}

		$tender_id = (string) $tender_id;
		$ids       = self::ids( $user_id );
		$position  = array_search( $tender_id, $ids, true );

		if ( false !== $position ) {
			unset( $ids[ $position ] );
			$saved = false;
		} else {
			$ids[] = $tender_id;
			$saved = true;
		}

		update_user_meta( $user_id, self::META, array_values( $ids ) );

		return $saved;
	}

	/**
	 * Full tender records for everything the current supplier has saved.
	 */
	public static function tenders( $user_id = 0 ) {
		$all = TG_API::all();
		$out = array();

		foreach ( self::ids( $user_id ) as $id ) {
			if ( isset( $all[ $id ] ) ) {
				$out[] = $all[ $id ];
			}
		}

		return $out;
	}

	public static function ajax_toggle() {
		check_ajax_referer( 'tg_ajax', 'nonce' );

		$tender_id = isset( $_POST['tender'] ) ? sanitize_text_field( wp_unslash( $_POST['tender'] ) ) : '';
		if ( ! $tender_id || ! TG_API::get( $tender_id ) ) {
			wp_send_json_error( array( 'message' => 'Unknown tender.' ), 400 );
		}

		$saved = self::toggle( $tender_id );

		wp_send_json_success( array(
			'saved' => (bool) $saved,
			'count' => count( self::ids() ),
		) );
	}

	public static function ajax_denied() {
		wp_send_json_error( array(
			'message'   => 'Please sign in to save tenders.',
			'login_url' => TG_Auth::login_url(),
		), 401 );
	}
}
