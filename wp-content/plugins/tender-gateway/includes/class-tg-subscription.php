<?php
/**
 * Supplier subscriptions - the platform's only source of revenue.
 *
 * Buyers are free. A supplier registers and can see tender headlines, but the
 * full specification, the attached documents and the right to bid all require
 * an active subscription.
 *
 * Three things here are deliberate and should not be casually changed:
 *
 *  - Every period bought is its own row, never overwritten. A supplier's
 *    billing history has to be reconstructable years later.
 *  - Invoice numbers are sequential and gapless. That is a legal requirement
 *    in Oman, not a preference, so the counter only ever moves forward and a
 *    number is never reused even if the row behind it is cancelled.
 *  - Expiry blocks new bids. It does not touch bids already placed, and it
 *    does not hide a supplier's own history. They paid to place those.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Subscription {

	const ACTIVE    = 'active';
	const EXPIRED   = 'expired';
	const CANCELLED = 'cancelled';

	public static function init() {
		// This is what finally answers TG_Access::is_subscribed(). Until this
		// filter existed, nobody was subscribed and bidding was shut.
		add_filter( 'tg_is_subscribed', array( __CLASS__, 'filter_is_subscribed' ), 10, 2 );
		add_action( 'tg_run_schedule', array( __CLASS__, 'expire_due' ) );
	}

	/* ------------------------------------------------------------- Plans */

	public static function periods() {
		return array(
			'monthly' => 'Monthly',
			'annual'  => 'Annual',
		);
	}

	public static function period_months( $period ) {
		return 'annual' === $period ? 12 : 1;
	}

	public static function plans( $active_only = true ) {
		global $wpdb;

		$sql = "SELECT * FROM " . TG_Store::plans_table();

		if ( $active_only ) {
			$sql .= " WHERE is_active = 1";
		}

		return (array) $wpdb->get_results( $sql . " ORDER BY sort_order ASC, price ASC", ARRAY_A );
	}

	public static function plan( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::plans_table() . " WHERE id = %d",
			(int) $id
		), ARRAY_A );

		return $row ? $row : null;
	}

	public static function save_plan( $data, $id = 0 ) {
		global $wpdb;

		$name = trim( sanitize_text_field( isset( $data['name'] ) ? $data['name'] : '' ) );

		if ( '' === $name ) {
			return new WP_Error( 'tg_plan_name', 'Please give the plan a name.' );
		}

		$price = isset( $data['price'] ) ? (float) $data['price'] : 0;

		if ( $price < 0 ) {
			return new WP_Error( 'tg_plan_price', 'The price cannot be negative.' );
		}

		$period = isset( $data['period'] ) && isset( self::periods()[ $data['period'] ] ) ? $data['period'] : 'monthly';

		$row = array(
			'name'        => $name,
			'description' => isset( $data['description'] ) ? sanitize_textarea_field( $data['description'] ) : '',
			'price'       => number_format( $price, 3, '.', '' ),
			'currency'    => isset( $data['currency'] ) && $data['currency'] ? sanitize_text_field( $data['currency'] ) : 'OMR',
			'period'      => $period,
			'free_months' => isset( $data['free_months'] ) ? max( 0, (int) $data['free_months'] ) : 0,
			'is_active'   => ! empty( $data['is_active'] ) ? 1 : 0,
			'sort_order'  => isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0,
		);

		if ( $id ) {
			$wpdb->update( TG_Store::plans_table(), $row, array( 'id' => (int) $id ) );

			return (int) $id;
		}

		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( TG_Store::plans_table(), $row );

		return (int) $wpdb->insert_id;
	}

	/**
	 * A plan in use by any subscription is deactivated rather than deleted, so
	 * historic invoices keep pointing at something real.
	 */
	public static function delete_plan( $id ) {
		global $wpdb;

		$used = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . TG_Store::subs_table() . " WHERE plan_id = %d",
			(int) $id
		) );

		if ( $used ) {
			$wpdb->update( TG_Store::plans_table(), array( 'is_active' => 0 ), array( 'id' => (int) $id ) );

			return new WP_Error(
				'tg_plan_in_use',
				sprintf( 'That plan has been used by %d subscription(s), so it has been deactivated rather than deleted. Historic invoices still refer to it.', $used )
			);
		}

		$wpdb->delete( TG_Store::plans_table(), array( 'id' => (int) $id ) );

		return true;
	}

	/* ------------------------------------------------------- Subscriptions */

	public static function vat_rate() {
		return (float) get_option( 'tg_vat_rate', 5 );
	}

	public static function vat_number() {
		return (string) get_option( 'tg_vat_number', '' );
	}

	/**
	 * Next invoice number: sequential, gapless, never reused.
	 *
	 * Held in an option that only moves forward. Deriving it from the table
	 * would reissue a number as soon as a row was removed, and a reused
	 * invoice number is a real problem with a tax authority rather than a
	 * cosmetic one.
	 */
	public static function next_invoice_no() {
		global $wpdb;

		$prefix = (string) get_option( 'tg_invoice_prefix', 'JT-INV-' );
		$option = 'tg_invoice_seq';

		$issued = (int) get_option( $option, 0 );

		// Floor from the table, so a restored database that lost the counter
		// cannot start handing out numbers that already exist.
		$highest = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(CAST(REPLACE(invoice_no, %s, '') AS UNSIGNED)) FROM " . TG_Store::subs_table() . " WHERE invoice_no LIKE %s",
			$prefix,
			$wpdb->esc_like( $prefix ) . '%'
		) );

		$next = max( $issued, $highest ) + 1;
		update_option( $option, $next, false );

		return $prefix . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
	}

	/**
	 * Start a subscription period for a supplier.
	 *
	 * Recorded by an administrator against a payment they have seen. There is
	 * no gateway yet, and the platform never holds the money either way - it
	 * records that payment happened and what was invoiced for it.
	 *
	 * @return int|WP_Error Subscription id.
	 */
	public static function start( $supplier_id, $plan_id, $args = array() ) {
		global $wpdb;

		$supplier = get_userdata( $supplier_id );

		if ( ! $supplier || ! in_array( TG_Auth::ROLE, (array) $supplier->roles, true ) ) {
			return new WP_Error( 'tg_sub_no_supplier', 'That supplier does not exist.' );
		}

		$plan = self::plan( $plan_id );

		if ( ! $plan ) {
			return new WP_Error( 'tg_sub_no_plan', 'That plan does not exist.' );
		}

		$months = self::period_months( $plan['period'] ) + (int) $plan['free_months'];

		// A renewal bought before the current period ends extends it rather
		// than throwing away the days already paid for.
		$current = self::current( $supplier_id );
		$start   = ( $current && strtotime( $current['ends_at'] ) > time() )
			? $current['ends_at']
			: current_time( 'mysql' );

		$ends = gmdate( 'Y-m-d H:i:s', strtotime( $start . ' +' . $months . ' months' ) );

		$price    = (float) $plan['price'];
		$vat_rate = self::vat_rate();
		$vat      = round( $price * $vat_rate / 100, 3 );

		$row = array(
			'supplier_id'    => (int) $supplier_id,
			'plan_id'        => (int) $plan_id,
			'plan_name'      => $plan['name'],
			'status'         => self::ACTIVE,
			'starts_at'      => $start,
			'ends_at'        => $ends,
			'price'          => number_format( $price, 3, '.', '' ),
			'vat_rate'       => number_format( $vat_rate, 3, '.', '' ),
			'vat_amount'     => number_format( $vat, 3, '.', '' ),
			'total'          => number_format( $price + $vat, 3, '.', '' ),
			'currency'       => $plan['currency'],
			'invoice_no'     => self::next_invoice_no(),
			'payment_method' => isset( $args['payment_method'] ) ? sanitize_text_field( $args['payment_method'] ) : 'manual',
			'payment_ref'    => isset( $args['payment_ref'] ) ? sanitize_text_field( $args['payment_ref'] ) : '',
			'note'           => isset( $args['note'] ) ? sanitize_textarea_field( $args['note'] ) : '',
			'created_by'     => get_current_user_id(),
			'created_at'     => current_time( 'mysql' ),
		);

		if ( ! $wpdb->insert( TG_Store::subs_table(), $row ) ) {
			return new WP_Error( 'tg_sub_insert', 'Could not record the subscription.' );
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'tg_subscription_started', $id, (int) $supplier_id, $plan );

		return $id;
	}

	/**
	 * Cancel: access runs to the end of the paid period, and there is no
	 * refund. That is the rule the client set, and it is the simplest one to
	 * explain to a supplier.
	 */
	public static function cancel( $id, $reason = '' ) {
		global $wpdb;

		$sub = self::get( $id );

		if ( ! $sub ) {
			return new WP_Error( 'tg_sub_missing', 'That subscription no longer exists.' );
		}

		if ( self::ACTIVE !== $sub['status'] ) {
			return new WP_Error( 'tg_sub_not_active', 'That subscription is not active.' );
		}

		$wpdb->update(
			TG_Store::subs_table(),
			array(
				'status'       => self::CANCELLED,
				'cancelled_at' => current_time( 'mysql' ),
				'note'         => trim( $sub['note'] . "\n" . sanitize_textarea_field( $reason ) ),
			),
			array( 'id' => (int) $id )
		);

		do_action( 'tg_subscription_cancelled', (int) $id, (int) $sub['supplier_id'] );

		return true;
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::subs_table() . " WHERE id = %d",
			(int) $id
		), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * The supplier's latest subscription period, whatever its state.
	 */
	public static function current( $supplier_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::subs_table() . "
			 WHERE supplier_id = %d ORDER BY ends_at DESC, id DESC LIMIT 1",
			(int) $supplier_id
		), ARRAY_A );

		return $row ? $row : null;
	}

	public static function history( $supplier_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::subs_table() . " WHERE supplier_id = %d ORDER BY id DESC",
			(int) $supplier_id
		), ARRAY_A );
	}

	/**
	 * Whether a supplier may use paid features right now.
	 *
	 * Judged against the clock, not against the stored status, for the same
	 * reason bidding is: a scheduler that has not run yet must not hand
	 * somebody a day of access they have not paid for.
	 */
	public static function is_active( $supplier_id ) {
		$sub = self::current( $supplier_id );

		if ( ! $sub ) {
			return false;
		}

		if ( self::CANCELLED === $sub['status'] ) {
			// Cancelled still runs to the end of the paid period.
			return strtotime( $sub['ends_at'] ) > time();
		}

		if ( self::ACTIVE !== $sub['status'] ) {
			return false;
		}

		return strtotime( $sub['ends_at'] ) > time();
	}

	public static function filter_is_subscribed( $subscribed, $user_id ) {
		return self::is_active( $user_id );
	}

	/**
	 * Mark lapsed periods expired. Cosmetic only - is_active() already answers
	 * correctly without this - but it keeps the admin screens honest.
	 */
	public static function expire_due() {
		global $wpdb;

		$wpdb->query( $wpdb->prepare(
			"UPDATE " . TG_Store::subs_table() . " SET status = %s
			 WHERE status = %s AND ends_at IS NOT NULL AND ends_at <= %s",
			self::EXPIRED,
			self::ACTIVE,
			current_time( 'mysql' )
		) );
	}

	public static function counts() {
		global $wpdb;

		$now = current_time( 'mysql' );

		return array(
			'active'  => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM " . TG_Store::subs_table() . " WHERE status IN (%s,%s) AND ends_at > %s",
				self::ACTIVE,
				self::CANCELLED,
				$now
			) ),
			'expired' => (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM " . TG_Store::subs_table() . " WHERE ends_at <= %s",
				$now
			) ),
		);
	}

	/**
	 * Revenue recorded, net of VAT. Invoiced, not collected - the platform
	 * records payments, it does not process them.
	 */
	public static function revenue( $since = '' ) {
		global $wpdb;

		$sql    = "SELECT SUM(price) FROM " . TG_Store::subs_table();
		$params = array();

		if ( $since ) {
			$sql     .= " WHERE created_at >= %s";
			$params[] = $since;
		}

		$total = $params
			? $wpdb->get_var( $wpdb->prepare( $sql, $params ) )
			: $wpdb->get_var( $sql );

		return (float) $total;
	}
}
