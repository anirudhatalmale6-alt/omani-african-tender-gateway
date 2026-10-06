<?php
/**
 * Who may see what, and who may bid.
 *
 * The specification defines three levels of visibility rather than the two the
 * site shipped with:
 *
 *   public      - not signed in. Sees the tender list and headlines only.
 *   registered  - signed in as a supplier. Same headline: title, category,
 *                 quantity, closing date. Nothing more.
 *   subscribed  - an active paid subscription. Full specifications, attached
 *                 documents, buyer details, and the right to bid.
 *
 * Subscriptions themselves are M4. Until that lands there is no way to be
 * subscribed, so bidding is closed and the screens say so plainly. That is the
 * safe direction to be wrong in: the alternative default would hand away paid
 * tender detail to anyone who registered, which is the entire product.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Access {

	const PUBLIC_LEVEL     = 'public';
	const REGISTERED_LEVEL = 'registered';
	const SUBSCRIBED_LEVEL = 'subscribed';

	public static function init() {
		// Tender documents follow the same rule as tender detail.
		add_filter( 'tg_doc_can_read', array( __CLASS__, 'can_read_document' ), 10, 3 );
	}

	/**
	 * Whether a paid subscription is required to see detail and to bid.
	 *
	 * Defaults to true. An administrator can turn it off - which is useful on a
	 * staging site, and the settings screen says in terms what it gives away.
	 */
	public static function subscription_required() {
		return (bool) apply_filters( 'tg_subscription_required', 'no' !== get_option( 'tg_require_subscription', 'yes' ) );
	}

	/**
	 * True once the supplier holds an active subscription.
	 *
	 * M4 attaches the real answer to this filter. With nothing attached the
	 * answer is no, so nobody reaches paid detail by default.
	 */
	public static function is_subscribed( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}

		return (bool) apply_filters( 'tg_is_subscribed', false, (int) $user_id );
	}

	/**
	 * Whether a subscription system exists at all yet. Used to explain the
	 * difference between "you need to subscribe" and "subscriptions are not
	 * available yet", which are very different messages to show a supplier.
	 */
	public static function subscriptions_available() {
		return (bool) has_filter( 'tg_is_subscribed' );
	}

	public static function is_supplier( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}

		$user = get_userdata( $user_id );

		return $user && in_array( TG_Auth::ROLE, (array) $user->roles, true );
	}

	/**
	 * Admin vetting. Suppliers register freely but are not verified until an
	 * administrator says so, and only verified suppliers may bid.
	 */
	public static function is_verified( $user_id ) {
		if ( ! $user_id ) {
			return false;
		}

		return 'verified' === get_user_meta( $user_id, 'tg_status', true );
	}

	public static function level( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		if ( ! $user_id ) {
			return self::PUBLIC_LEVEL;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return self::SUBSCRIBED_LEVEL;
		}

		if ( ! self::is_supplier( $user_id ) ) {
			return self::PUBLIC_LEVEL;
		}

		if ( ! self::subscription_required() || self::is_subscribed( $user_id ) ) {
			return self::SUBSCRIBED_LEVEL;
		}

		return self::REGISTERED_LEVEL;
	}

	/**
	 * Full specifications, documents and buyer contact details.
	 */
	public static function can_see_full_detail( $user_id = null ) {
		return self::SUBSCRIBED_LEVEL === self::level( $user_id );
	}

	/**
	 * Whether a tender is visible to this viewer at all.
	 *
	 * Draft, Pending Approval and Rejected tenders are nobody's business but
	 * the buyer's and the administrator's, whatever anyone pays.
	 */
	public static function can_see_tender( $tender, $user_id = null ) {
		if ( ! is_array( $tender ) ) {
			return false;
		}

		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( $user_id && (int) $tender['owner_id'] === $user_id ) {
			return true;
		}

		// Records from the Ministry feed carry no status and keep their old
		// behaviour, which is public.
		if ( '' === (string) $tender['status'] ) {
			return true;
		}

		return in_array( $tender['status'], TG_Status::supplier_visible(), true );
	}

	/**
	 * May this supplier bid on this tender right now?
	 *
	 * Returns WP_Error with a reason rather than a bare false, because every
	 * one of these needs a different message on screen - "subscribe" and "your
	 * account is awaiting approval" are not the same problem.
	 *
	 * @return true|WP_Error
	 */
	public static function can_bid( $tender, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;

		if ( ! $user_id ) {
			return new WP_Error( 'tg_bid_signed_out', 'Please sign in to submit a bid.' );
		}

		if ( ! self::is_supplier( $user_id ) ) {
			return new WP_Error( 'tg_bid_not_supplier', 'Only registered suppliers can submit bids.' );
		}

		if ( ! self::is_verified( $user_id ) ) {
			return new WP_Error(
				'tg_bid_unverified',
				'Your supplier account is waiting to be approved. You will be able to bid once it has been verified.'
			);
		}

		// Closing is checked against the clock, not against the displayed
		// status, so a scheduler that has not run yet cannot let a late bid in.
		if ( ! TG_Tender::accepts_bids( $tender ) ) {
			return new WP_Error( 'tg_bid_closed', 'This tender is closed and is no longer accepting bids.' );
		}

		if ( ! self::is_eligible( $tender, $user_id ) ) {
			return new WP_Error(
				'tg_bid_ineligible',
				'This tender is outside the categories registered on your supplier account.'
			);
		}

		if ( self::subscription_required() && ! self::is_subscribed( $user_id ) ) {
			return new WP_Error(
				'tg_bid_unsubscribed',
				self::subscriptions_available()
					? 'An active subscription is required to bid on tenders.'
					: 'Bidding opens when subscription plans go live.'
			);
		}

		return true;
	}

	/**
	 * Category eligibility.
	 *
	 * A supplier with no categories recorded is treated as eligible for
	 * everything rather than for nothing - otherwise every existing account
	 * would silently stop seeing work the day this shipped.
	 */
	public static function is_eligible( $tender, $user_id ) {
		$sector = trim( (string) ( isset( $tender['sector'] ) ? $tender['sector'] : '' ) );

		if ( '' === $sector ) {
			return true;
		}

		$sectors = get_user_meta( $user_id, 'tg_sectors', true );
		$sectors = is_array( $sectors ) ? array_filter( array_map( 'trim', $sectors ) ) : array();

		if ( empty( $sectors ) ) {
			return true;
		}

		foreach ( $sectors as $candidate ) {
			if ( 0 === strcasecmp( $candidate, $sector ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Tender documents are paid detail, so they follow can_see_full_detail().
	 * Bid attachments belong to whoever submitted them.
	 */
	public static function can_read_document( $allowed, $doc, $user_id ) {
		if ( $allowed ) {
			return true;
		}

		if ( 'tender' === $doc['owner_type'] ) {
			$tender = TG_Tender::get( $doc['owner_id'] );

			if ( ! $tender || ! self::can_see_tender( $tender, $user_id ) ) {
				return false;
			}

			return self::can_see_full_detail( $user_id );
		}

		if ( 'bid' === $doc['owner_type'] ) {
			$bid = TG_Bid::get( $doc['owner_id'] );

			if ( ! $bid ) {
				return false;
			}

			// The bidder, and the buyer who owns the tender once it has closed.
			if ( (int) $bid['supplier_id'] === (int) $user_id ) {
				return true;
			}

			$tender = TG_Tender::get( $bid['tender_id'] );

			return $tender
				&& (int) $tender['owner_id'] === (int) $user_id
				&& ! TG_Tender::accepts_bids( $tender );
		}

		return false;
	}
}
