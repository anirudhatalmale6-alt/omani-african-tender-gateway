<?php
/**
 * Awarding a tender to a winning bid.
 *
 * Two deliberate rules, both from the specification:
 *
 *  - A tender can only be awarded once it has closed. Picking a winner while
 *    bids are still arriving is how a tender gets disputed.
 *  - Choosing anything other than the lowest bid requires a written reason.
 *    That is not bureaucracy; it is the record that answers "why did they not
 *    pick us" six months later.
 *
 * The fee fields are written here but nothing reads them yet. The commission
 * was removed from scope and may return, and laying the fields down now means
 * switching it on later does not mean migrating live award records.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Award {

	/**
	 * Bids on a closed tender, cheapest first, with the lowest one flagged.
	 *
	 * @return array
	 */
	public static function comparison( $tender_id ) {
		$bids = TG_Bid::for_tender( $tender_id );

		if ( empty( $bids ) ) {
			return array();
		}

		$lowest = null;

		foreach ( $bids as $bid ) {
			if ( TG_Bid::WITHDRAWN === $bid['status'] || TG_Bid::VOID === $bid['status'] ) {
				continue;
			}

			if ( null === $lowest || (float) $bid['price'] < (float) $lowest ) {
				$lowest = (float) $bid['price'];
			}
		}

		foreach ( $bids as &$bid ) {
			$bid['is_lowest'] = ( null !== $lowest && (float) $bid['price'] === $lowest );
			$supplier         = get_userdata( $bid['supplier_id'] );
			$bid['company']   = $supplier ? get_user_meta( $supplier->ID, 'tg_company', true ) : '';

			if ( ! $bid['company'] ) {
				$bid['company'] = $supplier ? $supplier->display_name : 'Unknown supplier';
			}

			$bid['documents'] = TG_Docs::for_owner( 'bid', $bid['id'] );
		}

		return $bids;
	}

	/**
	 * Award a tender to one bid.
	 *
	 * @return true|WP_Error
	 */
	public static function award( $tender_id, $bid_id, $actor_id, $reason = '' ) {
		global $wpdb;

		$tender = TG_Tender::get( $tender_id );

		if ( ! $tender ) {
			return new WP_Error( 'tg_award_no_tender', 'That tender no longer exists.' );
		}

		if ( TG_Status::CLOSED !== $tender['status'] ) {
			return new WP_Error(
				'tg_award_not_closed',
				sprintf(
					'A tender can only be awarded once it has closed. This one is %s.',
					strtolower( TG_Status::label( $tender['status'] ) )
				)
			);
		}

		$bid = TG_Bid::get( $bid_id );

		if ( ! $bid || (int) $bid['tender_id'] !== (int) $tender_id ) {
			return new WP_Error( 'tg_award_no_bid', 'That bid does not belong to this tender.' );
		}

		if ( TG_Bid::SUBMITTED !== $bid['status'] ) {
			return new WP_Error(
				'tg_award_bid_not_live',
				sprintf( 'That bid cannot be awarded - it is marked %s.', strtolower( TG_Bid::label( $bid['status'] ) ) )
			);
		}

		// Choosing other than the cheapest needs justifying, in writing.
		$comparison = self::comparison( $tender_id );
		$is_lowest  = false;

		foreach ( $comparison as $row ) {
			if ( (int) $row['id'] === (int) $bid_id ) {
				$is_lowest = ! empty( $row['is_lowest'] );
				break;
			}
		}

		$reason = trim( wp_strip_all_tags( (string) $reason ) );

		if ( ! $is_lowest && '' === $reason ) {
			return new WP_Error(
				'tg_award_reason',
				'This is not the lowest bid, so please record why it was chosen. That reason is kept with the award.'
			);
		}

		$now = current_time( 'mysql' );

		$wpdb->update( TG_Store::table(), array(
			'awarded_bid_id'      => (int) $bid_id,
			'awarded_supplier_id' => (int) $bid['supplier_id'],
			'awarded_value'       => $bid['price'],
			'award_reason'        => $reason,
			'selected_by'         => $actor_id ? (int) $actor_id : null,
			'selected_at'         => $now,
			'approved_by'         => $actor_id ? (int) $actor_id : null,
			'approved_at'         => $now,
		), array( 'id' => (int) $tender_id ) );

		// Winner and losers, so every bidder learns where they stand.
		$wpdb->update(
			TG_Store::bids_table(),
			array( 'status' => TG_Bid::WON, 'updated_at' => $now ),
			array( 'id' => (int) $bid_id )
		);

		$wpdb->query( $wpdb->prepare(
			"UPDATE " . TG_Store::bids_table() . "
			 SET status = %s, updated_at = %s
			 WHERE tender_id = %d AND id <> %d AND status = %s",
			TG_Bid::LOST,
			$now,
			(int) $tender_id,
			(int) $bid_id,
			TG_Bid::SUBMITTED
		) );

		$moved = TG_Tender::move(
			$tender_id,
			TG_Status::AWARDED,
			$actor_id,
			$reason ? $reason : sprintf( 'Awarded to %s.', self::company_of( $bid['supplier_id'] ) )
		);

		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		do_action( 'tg_tender_awarded', (int) $tender_id, (int) $bid_id, (int) $bid['supplier_id'] );

		return true;
	}

	public static function company_of( $user_id ) {
		$company = get_user_meta( $user_id, 'tg_company', true );

		if ( $company ) {
			return $company;
		}

		$user = get_userdata( $user_id );

		return $user ? $user->display_name : 'Unknown supplier';
	}

	/**
	 * The award record, for the admin screen and for reporting.
	 */
	public static function record( $tender ) {
		if ( empty( $tender['awarded_bid_id'] ) ) {
			return null;
		}

		return array(
			'bid_id'      => (int) $tender['awarded_bid_id'],
			'supplier_id' => (int) $tender['awarded_supplier_id'],
			'company'     => self::company_of( $tender['awarded_supplier_id'] ),
			'value'       => $tender['awarded_value'],
			'currency'    => $tender['currency'] ? $tender['currency'] : 'OMR',
			'reason'      => (string) $tender['award_reason'],
			'selected_by' => $tender['selected_by'] ? get_userdata( $tender['selected_by'] ) : null,
			'selected_at' => $tender['selected_at'],
			'approved_by' => $tender['approved_by'] ? get_userdata( $tender['approved_by'] ) : null,
			'approved_at' => $tender['approved_at'],
		);
	}
}
