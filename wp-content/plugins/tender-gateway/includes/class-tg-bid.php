<?php
/**
 * Supplier bids.
 *
 * Sealed: no supplier ever sees another supplier's bid, at any stage. The
 * buyer and the administrator see them all once the tender has closed.
 *
 * One live bid per supplier per tender, enforced by a unique key in the
 * database as well as in code - two competing prices from one company is the
 * kind of thing that gets a tender disputed.
 *
 * Every write re-checks eligibility and the closing time on the server. The
 * screens hide what cannot be done, but hiding is not enforcing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Bid {

	const SUBMITTED = 'submitted';
	const WITHDRAWN = 'withdrawn';
	const VOID      = 'void';
	const WON       = 'won';
	const LOST      = 'lost';

	public static function init() {
		// A cancelled tender voids its bids and tells every bidder why.
		add_action( 'tg_tender_status_changed', array( __CLASS__, 'on_tender_status_changed' ), 10, 5 );
	}

	public static function labels() {
		return array(
			self::SUBMITTED => 'Submitted',
			self::WITHDRAWN => 'Withdrawn',
			self::VOID      => 'Void - tender cancelled',
			self::WON       => 'Won',
			self::LOST      => 'Not selected',
		);
	}

	public static function label( $status ) {
		$labels = self::labels();

		return isset( $labels[ $status ] ) ? $labels[ $status ] : ucfirst( (string) $status );
	}

	/**
	 * Create or replace this supplier's bid on a tender.
	 *
	 * @return int|WP_Error Bid id.
	 */
	public static function submit( $tender_id, $supplier_id, $data ) {
		global $wpdb;

		$tender = TG_Tender::get( $tender_id );

		if ( ! $tender ) {
			return new WP_Error( 'tg_bid_no_tender', 'That tender no longer exists.' );
		}

		$allowed = TG_Access::can_bid( $tender, $supplier_id );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$price = isset( $data['price'] ) ? (float) $data['price'] : 0;

		if ( $price <= 0 ) {
			return new WP_Error( 'tg_bid_price', 'Please enter the price you are bidding.' );
		}

		$row = array(
			'tender_id'     => (int) $tender_id,
			'supplier_id'   => (int) $supplier_id,
			'price'         => number_format( $price, 3, '.', '' ),
			'currency'      => isset( $data['currency'] ) && $data['currency']
				? sanitize_text_field( $data['currency'] )
				: ( $tender['currency'] ? $tender['currency'] : 'OMR' ),
			'delivery_days' => isset( $data['delivery_days'] ) && '' !== $data['delivery_days'] ? max( 0, (int) $data['delivery_days'] ) : null,
			'validity_days' => isset( $data['validity_days'] ) && '' !== $data['validity_days'] ? max( 0, (int) $data['validity_days'] ) : null,
			'notes'         => isset( $data['notes'] ) ? wp_kses_post( $data['notes'] ) : '',
			'status'        => self::SUBMITTED,
			'status_reason' => '',
			'updated_at'    => current_time( 'mysql' ),
		);

		$existing = self::find( $tender_id, $supplier_id );

		if ( $existing ) {
			$wpdb->update( TG_Store::bids_table(), $row, array( 'id' => (int) $existing['id'] ) );

			do_action( 'tg_bid_updated', (int) $existing['id'], (int) $tender_id, (int) $supplier_id );

			return (int) $existing['id'];
		}

		$row['submitted_at'] = current_time( 'mysql' );

		if ( ! $wpdb->insert( TG_Store::bids_table(), $row ) ) {
			// The unique key is the backstop against a double submission racing
			// past the check above.
			$again = self::find( $tender_id, $supplier_id );

			if ( $again ) {
				return (int) $again['id'];
			}

			return new WP_Error( 'tg_bid_insert', 'Could not save your bid. Please try again.' );
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'tg_bid_submitted', $id, (int) $tender_id, (int) $supplier_id );

		return $id;
	}

	/**
	 * A supplier withdrawing their own bid while the tender is still open.
	 *
	 * Deliberately permitted for a supplier whose subscription has lapsed: the
	 * specification says expiry blocks new bids, not the ability to manage one
	 * already placed.
	 *
	 * @return true|WP_Error
	 */
	public static function withdraw( $bid_id, $user_id ) {
		global $wpdb;

		$bid = self::get( $bid_id );

		if ( ! $bid ) {
			return new WP_Error( 'tg_bid_missing', 'That bid no longer exists.' );
		}

		if ( (int) $bid['supplier_id'] !== (int) $user_id && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'tg_bid_not_yours', 'You can only withdraw your own bid.' );
		}

		if ( self::SUBMITTED !== $bid['status'] ) {
			return new WP_Error( 'tg_bid_not_live', 'That bid is no longer active.' );
		}

		$tender = TG_Tender::get( $bid['tender_id'] );

		if ( ! TG_Tender::accepts_bids( $tender ) ) {
			return new WP_Error(
				'tg_bid_closed',
				'This tender has closed, so bids can no longer be withdrawn.'
			);
		}

		$wpdb->update(
			TG_Store::bids_table(),
			array( 'status' => self::WITHDRAWN, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => (int) $bid_id )
		);

		do_action( 'tg_bid_withdrawn', (int) $bid_id, (int) $bid['tender_id'], (int) $bid['supplier_id'] );

		return true;
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::bids_table() . " WHERE id = %d",
			(int) $id
		), ARRAY_A );

		return $row ? $row : null;
	}

	public static function find( $tender_id, $supplier_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::bids_table() . " WHERE tender_id = %d AND supplier_id = %d",
			(int) $tender_id,
			(int) $supplier_id
		), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * Every bid on a tender. For the buyer and the administrator only - the
	 * caller is responsible for not handing this to a supplier.
	 */
	public static function for_tender( $tender_id, $include_withdrawn = false ) {
		global $wpdb;

		$sql = "SELECT * FROM " . TG_Store::bids_table() . " WHERE tender_id = %d";

		if ( ! $include_withdrawn ) {
			$sql .= $wpdb->prepare( " AND status <> %s", self::WITHDRAWN );
		}

		return (array) $wpdb->get_results(
			$wpdb->prepare( $sql . " ORDER BY price ASC, submitted_at ASC", (int) $tender_id ),
			ARRAY_A
		);
	}

	/**
	 * A supplier's own bid history. Remains readable after their subscription
	 * lapses - they paid to place these.
	 */
	public static function for_supplier( $supplier_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::bids_table() . " WHERE supplier_id = %d ORDER BY submitted_at DESC",
			(int) $supplier_id
		), ARRAY_A );
	}

	public static function count_for_tender( $tender_id ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . TG_Store::bids_table() . " WHERE tender_id = %d AND status <> %s",
			(int) $tender_id,
			self::WITHDRAWN
		) );
	}

	/**
	 * Counts for every tender in one query, so a list of 20 tenders does not
	 * become 20 extra queries.
	 */
	public static function counts_for_tenders( $tender_ids ) {
		global $wpdb;

		$ids = array_filter( array_map( 'intval', (array) $tender_ids ) );

		if ( empty( $ids ) ) {
			return array();
		}

		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT tender_id, COUNT(*) AS n FROM " . TG_Store::bids_table() . "
			 WHERE tender_id IN ({$in}) AND status <> %s GROUP BY tender_id",
			self::WITHDRAWN
		), ARRAY_A );

		$out = array_fill_keys( $ids, 0 );

		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['tender_id'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Cancelling a tender voids its bids rather than deleting them, records the
	 * reason, and leaves every bidder able to see what happened.
	 */
	public static function on_tender_status_changed( $tender_id, $from, $to, $actor_id, $reason ) {
		global $wpdb;

		if ( TG_Status::CANCELLED !== $to ) {
			return;
		}

		$wpdb->query( $wpdb->prepare(
			"UPDATE " . TG_Store::bids_table() . "
			 SET status = %s, status_reason = %s, updated_at = %s
			 WHERE tender_id = %d AND status = %s",
			self::VOID,
			$reason,
			current_time( 'mysql' ),
			(int) $tender_id,
			self::SUBMITTED
		) );

		do_action( 'tg_bids_voided', (int) $tender_id, $reason );
	}
}
