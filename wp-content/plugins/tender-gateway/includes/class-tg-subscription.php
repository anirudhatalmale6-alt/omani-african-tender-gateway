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

	const PENDING   = 'pending';
	const FULFILLED = 'fulfilled';
	const DECLINED  = 'declined';

	public static function init() {
		// This is what finally answers TG_Access::is_subscribed(). Until this
		// filter existed, nobody was subscribed and bidding was shut.
		add_filter( 'tg_is_subscribed', array( __CLASS__, 'filter_is_subscribed' ), 10, 2 );
		add_action( 'tg_run_schedule', array( __CLASS__, 'expire_due' ) );
		add_action( 'tg_run_schedule', array( __CLASS__, 'run_reminders' ) );
	}

	/**
	 * Now, as a timestamp, read the same way every stored datetime is written.
	 *
	 * Every datetime in these tables is written with current_time( 'mysql' ),
	 * which is site-local. WordPress pins PHP's own timezone to UTC, so
	 * strtotime() on one of those strings yields a timestamp that is *not* the
	 * real instant unless the site is itself on UTC - and comparing that
	 * against time() would hand a supplier the timezone offset as free access.
	 * Parsing "now" through the same route keeps both sides of every comparison
	 * in the same frame, which is what actually matters.
	 */
	private static function now() {
		return strtotime( current_time( 'mysql' ) );
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
		$start   = ( $current && strtotime( $current['ends_at'] ) > self::now() )
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

		// If this supplier had asked for a plan, that request is now answered.
		// Leaving it pending would keep it on the admin's to-do list for ever.
		$request = self::pending_request( (int) $supplier_id );

		if ( $request ) {
			self::close_request( (int) $request['id'], self::FULFILLED, $id );
		}

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
			return strtotime( $sub['ends_at'] ) > self::now();
		}

		if ( self::ACTIVE !== $sub['status'] ) {
			return false;
		}

		return strtotime( $sub['ends_at'] ) > self::now();
	}

	/**
	 * Days left on a period. Negative once it has lapsed, null when there is
	 * nothing to measure.
	 *
	 * Part of a day counts as a day while there is still time on the clock.
	 * A period bought on the 11th and running to the 16th has five days left
	 * on the 11th, not four - rounding down tells a supplier their access ends
	 * a day before it does, and that number sits next to the actual end date
	 * on their dashboard where the contradiction is obvious.
	 *
	 * Once the end time has passed the sign has to stay negative, so the lapse
	 * side rounds the other way.
	 */
	public static function days_left( $sub ) {
		if ( ! is_array( $sub ) || empty( $sub['ends_at'] ) ) {
			return null;
		}

		$ends = strtotime( $sub['ends_at'] );

		if ( ! $ends ) {
			return null;
		}

		$diff = $ends - self::now();

		return (int) ( $diff >= 0 ? ceil( $diff / DAY_IN_SECONDS ) : floor( $diff / DAY_IN_SECONDS ) );
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

	/* ----------------------------------------------------------- Requests */

	/**
	 * A supplier asking to be put on a plan.
	 *
	 * There is no gateway, so this is the honest shape of "subscribe": the
	 * supplier says which plan they want, somebody invoices them, and the
	 * payment is recorded. The request is not access and never grants any.
	 *
	 * @return int|WP_Error Request id.
	 */
	public static function request( $supplier_id, $plan_id, $note = '' ) {
		global $wpdb;

		if ( ! TG_Access::is_supplier( $supplier_id ) ) {
			return new WP_Error( 'tg_req_no_supplier', 'Only a registered supplier can request a plan.' );
		}

		$plan = self::plan( $plan_id );

		if ( ! $plan || ! (int) $plan['is_active'] ) {
			return new WP_Error( 'tg_req_no_plan', 'That plan is not available.' );
		}

		// One open request at a time. Without this, a supplier clicking twice
		// puts the same job on the admin's list twice.
		if ( self::pending_request( $supplier_id ) ) {
			return new WP_Error( 'tg_req_duplicate', 'You already have a request waiting. Our team will be in touch to arrange payment.' );
		}

		$row = array(
			'supplier_id' => (int) $supplier_id,
			'plan_id'     => (int) $plan_id,
			'plan_name'   => $plan['name'],
			'price'       => number_format( (float) $plan['price'], 3, '.', '' ),
			'currency'    => $plan['currency'],
			'period'      => $plan['period'],
			'status'      => self::PENDING,
			'note'        => sanitize_textarea_field( $note ),
			'created_at'  => current_time( 'mysql' ),
		);

		if ( ! $wpdb->insert( TG_Store::sub_requests_table(), $row ) ) {
			return new WP_Error( 'tg_req_insert', 'Could not record your request. Please try again.' );
		}

		$id = (int) $wpdb->insert_id;

		do_action( 'tg_subscription_requested', $id, (int) $supplier_id, $plan );

		return $id;
	}

	public static function pending_request( $supplier_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::sub_requests_table() . "
			 WHERE supplier_id = %d AND status = %s ORDER BY id DESC LIMIT 1",
			(int) $supplier_id,
			self::PENDING
		), ARRAY_A );

		return $row ? $row : null;
	}

	public static function requests( $status = '' ) {
		global $wpdb;

		$table = TG_Store::sub_requests_table();

		if ( $status ) {
			return (array) $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC LIMIT 200",
				$status
			), ARRAY_A );
		}

		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A );
	}

	public static function request_count( $status = 'pending' ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . TG_Store::sub_requests_table() . " WHERE status = %s",
			$status
		) );
	}

	/**
	 * Close a request. Rows are never deleted - "who asked for what, and what
	 * we did about it" is the only record of a sales conversation this platform
	 * keeps.
	 */
	public static function close_request( $id, $status, $subscription_id = 0, $note = '' ) {
		global $wpdb;

		if ( ! in_array( $status, array( self::FULFILLED, self::DECLINED ), true ) ) {
			return new WP_Error( 'tg_req_status', 'Unknown request status.' );
		}

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::sub_requests_table() . " WHERE id = %d",
			(int) $id
		), ARRAY_A );

		if ( ! $row ) {
			return new WP_Error( 'tg_req_missing', 'That request no longer exists.' );
		}

		if ( self::PENDING !== $row['status'] ) {
			return new WP_Error( 'tg_req_closed', 'That request has already been dealt with.' );
		}

		$update = array(
			'status'     => $status,
			'handled_by' => get_current_user_id(),
			'handled_at' => current_time( 'mysql' ),
		);

		if ( $subscription_id ) {
			$update['subscription_id'] = (int) $subscription_id;
		}

		if ( '' !== trim( (string) $note ) ) {
			$update['note'] = trim( $row['note'] . "\n" . sanitize_textarea_field( $note ) );
		}

		$wpdb->update( TG_Store::sub_requests_table(), $update, array( 'id' => (int) $id ) );

		return true;
	}

	/* ---------------------------------------------------------- Reminders */

	/**
	 * When a supplier is warned, and what each warning is called.
	 *
	 * Keyed by the label stored in reminders_sent, so renaming a key would
	 * re-send history. Add to this list, do not renumber it.
	 */
	public static function reminder_milestones() {
		return array(
			'd14'    => 14,
			'd7'     => 7,
			'd1'     => 1,
			'lapsed' => 0,
		);
	}

	/**
	 * Which warning, if any, this period is due.
	 *
	 * Exactly one milestone applies at any moment: the tightest band the
	 * remaining days fall into. Six days left is the seven-day band, not the
	 * fourteen-day one - so a subscription sold with less than a fortnight to
	 * run never receives a "fourteen days left" notice after its seven-day
	 * one, which is what walking the list looking for anything unsent does.
	 *
	 * It also means a site whose cron has been asleep says the one thing that
	 * is still true rather than replaying a fortnight of stale warnings.
	 *
	 * @return string Milestone key, or '' when nothing is due.
	 */
	public static function reminder_due( $sub ) {
		if ( ! is_array( $sub ) || empty( $sub['ends_at'] ) ) {
			return '';
		}

		// An expired period that was never renewed still earns its lapse
		// notice; a cancelled one does not, because they asked us to stop.
		if ( self::CANCELLED === $sub['status'] ) {
			return '';
		}

		$days = self::days_left( $sub );

		if ( null === $days ) {
			return '';
		}

		$milestone = self::reminder_band( $days );

		if ( ! $milestone ) {
			return '';
		}

		return in_array( $milestone, self::reminders_sent( $sub ), true ) ? '' : $milestone;
	}

	/**
	 * The single milestone that applies to this many days remaining.
	 *
	 * @return string Milestone key, or '' when it is too early to warn.
	 */
	public static function reminder_band( $days ) {
		if ( $days < 0 ) {
			return 'lapsed';
		}

		// Tightest band first.
		foreach ( array( 'd1' => 1, 'd7' => 7, 'd14' => 14 ) as $key => $threshold ) {
			if ( $days <= $threshold ) {
				return $key;
			}
		}

		return '';
	}

	public static function reminders_sent( $sub ) {
		$raw = isset( $sub['reminders_sent'] ) ? (string) $sub['reminders_sent'] : '';

		return array_filter( array_map( 'trim', explode( ',', $raw ) ) );
	}

	/**
	 * Send the renewal warnings that are due.
	 *
	 * A milestone is recorded as sent only when the mail actually went out. The
	 * host this runs on has outgoing mail disabled, so pretending otherwise
	 * would quietly swallow every reminder the platform ever owed a supplier.
	 * A failed attempt is stamped instead and retried tomorrow, and the admin
	 * screen shows the failure so somebody can pick up the phone.
	 */
	public static function run_reminders() {
		global $wpdb;

		$now = current_time( 'mysql' );

		// Candidates: anything ending within a fortnight, or already lapsed in
		// the last month. Older than that and a reminder is just noise.
		$rows = (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::subs_table() . "
			 WHERE ends_at IS NOT NULL
			   AND ends_at <= DATE_ADD(%s, INTERVAL 14 DAY)
			   AND ends_at >= DATE_SUB(%s, INTERVAL 30 DAY)
			   AND status <> %s
			 ORDER BY ends_at ASC LIMIT 200",
			$now,
			$now,
			self::CANCELLED
		), ARRAY_A );

		$sent = 0;

		foreach ( $rows as $sub ) {
			$milestone = self::reminder_due( $sub );

			if ( ! $milestone ) {
				continue;
			}

			// A renewal already bought means the supplier is not lapsing at
			// all - this row is simply an older period in their history.
			$current = self::current( (int) $sub['supplier_id'] );

			if ( $current && (int) $current['id'] !== (int) $sub['id'] ) {
				continue;
			}

			// Retry a failed send once a day, not every five minutes.
			if ( ! empty( $sub['reminder_tried_at'] )
				&& strtotime( $sub['reminder_tried_at'] ) > self::now() - DAY_IN_SECONDS ) {
				continue;
			}

			$ok = self::send_reminder( $sub, $milestone );

			$update = array( 'reminder_tried_at' => $now );

			if ( $ok ) {
				$keys                     = self::reminders_sent( $sub );
				$keys[]                   = $milestone;
				$update['reminders_sent'] = implode( ',', array_unique( $keys ) );
				$sent++;
			}

			$wpdb->update( TG_Store::subs_table(), $update, array( 'id' => (int) $sub['id'] ) );

			self::log_reminder( $sub, $milestone, $ok );
		}

		return $sent;
	}

	/**
	 * @return bool Whether the mail was handed off successfully.
	 */
	private static function send_reminder( $sub, $milestone ) {
		$supplier = get_userdata( (int) $sub['supplier_id'] );

		if ( ! $supplier || ! is_email( $supplier->user_email ) ) {
			return false;
		}

		$days    = self::days_left( $sub );
		$company = get_user_meta( $supplier->ID, 'tg_company', true );
		$company = $company ? $company : $supplier->display_name;
		$ends    = date_i18n( 'j F Y', strtotime( $sub['ends_at'] ) );
		$site    = get_bloginfo( 'name' );

		if ( 'lapsed' === $milestone ) {
			$subject = sprintf( '[%s] Your subscription has expired', $site );
			$opening = sprintf(
				'Your %s subscription expired on %s. You can still sign in and see your bid history, but tender specifications, documents and new bids are closed until it is renewed.',
				$sub['plan_name'],
				$ends
			);
		} else {
			$subject = sprintf( '[%s] Your subscription ends in %d day(s)', $site, max( 0, (int) $days ) );
			$opening = sprintf(
				'Your %s subscription runs until %s - %d day(s) from now. Renew before then and the new period is added on top of the days you have left, so nothing is lost.',
				$sub['plan_name'],
				$ends,
				max( 0, (int) $days )
			);
		}

		$body = sprintf(
			"Dear %s,\n\n%s\n\nTo renew, reply to this message or contact us and we will raise the invoice.\n\nYour subscription: %s\nInvoice: %s\nPeriod: %s to %s\n\n%s\n%s\n",
			$company,
			$opening,
			$sub['plan_name'],
			$sub['invoice_no'],
			date_i18n( 'j F Y', strtotime( $sub['starts_at'] ) ),
			$ends,
			$site,
			tg_page_url( 'dashboard' )
		);

		return (bool) wp_mail( $supplier->user_email, $subject, $body );
	}

	/**
	 * Keep the last 60 attempts so the admin screen can show what actually
	 * happened rather than what was intended.
	 */
	private static function log_reminder( $sub, $milestone, $ok ) {
		$log = get_option( 'tg_reminder_log', array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift( $log, array(
			'at'        => current_time( 'mysql' ),
			'sub'       => (int) $sub['id'],
			'supplier'  => (int) $sub['supplier_id'],
			'milestone' => $milestone,
			'ok'        => $ok ? 1 : 0,
		) );

		update_option( 'tg_reminder_log', array_slice( $log, 0, 60 ), false );
	}

	public static function reminder_log() {
		$log = get_option( 'tg_reminder_log', array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Periods ending soon or lapsed recently, newest expiry first. This is the
	 * list a human works through when email is not available.
	 */
	public static function renewals_due( $within_days = 14, $lapsed_days = 30 ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::subs_table() . "
			 WHERE ends_at IS NOT NULL
			   AND ends_at <= DATE_ADD(%s, INTERVAL %d DAY)
			   AND ends_at >= DATE_SUB(%s, INTERVAL %d DAY)
			 ORDER BY ends_at ASC LIMIT 100",
			$now,
			(int) $within_days,
			$now,
			(int) $lapsed_days
		), ARRAY_A );
	}
}
