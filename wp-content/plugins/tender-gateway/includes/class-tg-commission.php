<?php
/**
 * The 2% commission: invoiced to the winning supplier when a tender is
 * completed, paid by bank transfer, recorded by the team.
 *
 * The platform never handles the transaction itself. The buyer pays the
 * supplier directly; this only bills the supplier for the platform's own
 * service. That is what keeps it outside payment-services regulation.
 *
 * Rules that matter:
 *  - One commission per tender, enforced by the table, so completing a tender
 *    twice can never bill twice.
 *  - Invoice numbers are sequential, gapless and never reused, with their own
 *    prefix, exactly as subscription invoices are.
 *  - Overdue is judged against the clock, not a stored flag, so a scheduler
 *    that has not run cannot let an overdue supplier keep bidding.
 *  - Unpaid commission blocks NEW bids only. Bids already placed stand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Commission {

	const DUE  = 'due';
	const PAID = 'paid';
	const VOID = 'void';

	public static function init() {
		add_action( 'tg_tender_status_changed', array( __CLASS__, 'on_status_changed' ), 30, 5 );
		add_action( 'tg_run_schedule', array( __CLASS__, 'run_reminders' ) );
	}

	/* ------------------------------------------------------------- Settings */

	public static function rate() {
		return (float) get_option( 'tg_commission_rate', 2 );
	}

	public static function due_days() {
		return max( 1, (int) get_option( 'tg_commission_due_days', 14 ) );
	}

	public static function prefix() {
		$p = (string) get_option( 'tg_commission_prefix', 'JT-COM-' );

		return '' !== $p ? $p : 'JT-COM-';
	}

	private static function now() {
		return strtotime( current_time( 'mysql' ) );
	}

	/* ------------------------------------------------------------- Issuing */

	public static function on_status_changed( $tender_id, $from, $to, $actor_id, $reason ) {
		if ( TG_Status::COMPLETED === $to ) {
			self::issue( (int) $tender_id );
		}
	}

	/**
	 * Same scheme as subscription invoices: a forward-only counter, with the
	 * table as a floor so a restored database cannot reissue a number.
	 */
	public static function next_invoice_no() {
		global $wpdb;

		$prefix  = self::prefix();
		$issued  = (int) get_option( 'tg_commission_seq', 0 );
		$highest = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(CAST(REPLACE(invoice_no, %s, '') AS UNSIGNED)) FROM " . TG_Store::commissions_table() . " WHERE invoice_no LIKE %s",
			$prefix,
			$wpdb->esc_like( $prefix ) . '%'
		) );

		$next = max( $issued, $highest ) + 1;
		update_option( 'tg_commission_seq', $next, false );

		return $prefix . str_pad( (string) $next, 5, '0', STR_PAD_LEFT );
	}

	/**
	 * Issue the commission invoice for a tender.
	 *
	 * @return int|WP_Error Commission id. An existing one is returned rather
	 *                      than a second being created.
	 */
	public static function issue( $tender_id ) {
		global $wpdb;

		$existing = self::for_tender( $tender_id );

		if ( $existing ) {
			return (int) $existing['id'];
		}

		$tender = TG_Tender::get( $tender_id );

		if ( ! $tender ) {
			return new WP_Error( 'tg_com_no_tender', 'That tender no longer exists.' );
		}

		if ( empty( $tender['awarded_bid_id'] ) || empty( $tender['awarded_supplier_id'] ) ) {
			return new WP_Error( 'tg_com_not_awarded', 'Commission is charged on an awarded tender, and this one has no award.' );
		}

		$value    = (float) $tender['awarded_value'];
		$rate     = self::rate();
		$amount   = round( $value * $rate / 100, 3 );
		$vat_rate = TG_Subscription::vat_rate();
		$vat      = round( $amount * $vat_rate / 100, 3 );
		$issued   = current_time( 'mysql' );
		$bid      = TG_Bid::get( $tender['awarded_bid_id'] );

		$row = array(
			'tender_id'     => (int) $tender_id,
			'supplier_id'   => (int) $tender['awarded_supplier_id'],
			'bid_id'        => (int) $tender['awarded_bid_id'],
			'awarded_value' => number_format( $value, 3, '.', '' ),
			'rate'          => number_format( $rate, 3, '.', '' ),
			'amount'        => number_format( $amount, 3, '.', '' ),
			'vat_rate'      => number_format( $vat_rate, 3, '.', '' ),
			'vat_amount'    => number_format( $vat, 3, '.', '' ),
			'total'         => number_format( $amount + $vat, 3, '.', '' ),
			'currency'      => $bid && $bid['currency'] ? $bid['currency'] : ( $tender['currency'] ? $tender['currency'] : 'OMR' ),
			'invoice_no'    => self::next_invoice_no(),
			'status'        => self::DUE,
			'issued_at'     => $issued,
			'due_at'        => date( 'Y-m-d H:i:s', strtotime( $issued ) + self::due_days() * DAY_IN_SECONDS ),
		);

		if ( ! $wpdb->insert( TG_Store::commissions_table(), $row ) ) {
			// A concurrent completion may have won the UNIQUE race.
			$again = self::for_tender( $tender_id );

			return $again ? (int) $again['id'] : new WP_Error( 'tg_com_insert', 'Could not record the commission.' );
		}

		$id = (int) $wpdb->insert_id;

		// The fee fields laid down on the tender in M3, for reporting.
		$wpdb->update( TG_Store::table(), array(
			'fee_percent' => $row['rate'],
			'fee_amount'  => $row['amount'],
			'fee_party'   => 'supplier',
		), array( 'id' => (int) $tender_id ) );

		do_action( 'tg_commission_issued', $id );

		return $id;
	}

	/* --------------------------------------------------------------- Reading */

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . TG_Store::commissions_table() . " WHERE id = %d", (int) $id ), ARRAY_A );

		return $row ? $row : null;
	}

	public static function for_tender( $tender_id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . TG_Store::commissions_table() . " WHERE tender_id = %d", (int) $tender_id ), ARRAY_A );

		return $row ? $row : null;
	}

	public static function for_supplier( $supplier_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::commissions_table() . " WHERE supplier_id = %d ORDER BY id DESC",
			(int) $supplier_id
		), ARRAY_A );
	}

	/**
	 * @param string $filter all | due | overdue | paid | void
	 */
	public static function query( $filter = 'all' ) {
		global $wpdb;

		$table = TG_Store::commissions_table();
		$now   = current_time( 'mysql' );

		switch ( $filter ) {
			case 'due':
				$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND due_at >= %s ORDER BY due_at ASC", self::DUE, $now );
				break;
			case 'overdue':
				$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s AND due_at < %s ORDER BY due_at ASC", self::DUE, $now );
				break;
			case 'paid':
				$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY paid_at DESC", self::PAID );
				break;
			case 'void':
				$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY id DESC", self::VOID );
				break;
			default:
				$sql = "SELECT * FROM {$table} ORDER BY id DESC";
		}

		return (array) $wpdb->get_results( $sql . ' LIMIT 1000', ARRAY_A );
	}

	public static function totals() {
		$out = array( 'due' => 0.0, 'overdue' => 0.0, 'paid' => 0.0, 'count_due' => 0, 'count_overdue' => 0, 'count_paid' => 0 );

		foreach ( array( 'due', 'overdue', 'paid' ) as $k ) {
			foreach ( self::query( $k ) as $row ) {
				$out[ $k ] += (float) $row['total'];
				$out[ 'count_' . $k ]++;
			}
		}

		return $out;
	}

	/**
	 * Overdue, judged against the clock.
	 */
	public static function is_overdue( $row ) {
		return is_array( $row )
			&& self::DUE === $row['status']
			&& ! empty( $row['due_at'] )
			&& strtotime( $row['due_at'] ) < self::now();
	}

	/**
	 * The supplier's overdue invoices, if any - the reason they cannot bid.
	 */
	public static function overdue_for( $supplier_id ) {
		return array_values( array_filter( self::for_supplier( $supplier_id ), array( __CLASS__, 'is_overdue' ) ) );
	}

	public static function label( $row ) {
		if ( self::is_overdue( $row ) ) {
			return 'Overdue';
		}

		$labels = array( self::DUE => 'Due', self::PAID => 'Paid', self::VOID => 'Cancelled' );

		return isset( $labels[ $row['status'] ] ) ? $labels[ $row['status'] ] : $row['status'];
	}

	/* --------------------------------------------------------------- Changes */

	public static function record_payment( $id, $args = array() ) {
		global $wpdb;

		$row = self::get( $id );

		if ( ! $row ) {
			return new WP_Error( 'tg_com_missing', 'That commission invoice no longer exists.' );
		}

		if ( self::DUE !== $row['status'] ) {
			return new WP_Error( 'tg_com_not_due', 'That invoice is not awaiting payment.' );
		}

		$wpdb->update( TG_Store::commissions_table(), array(
			'status'         => self::PAID,
			'paid_at'        => current_time( 'mysql' ),
			'payment_method' => isset( $args['payment_method'] ) && $args['payment_method'] ? sanitize_text_field( $args['payment_method'] ) : 'bank transfer',
			'payment_ref'    => isset( $args['payment_ref'] ) ? sanitize_text_field( $args['payment_ref'] ) : '',
			'recorded_by'    => get_current_user_id(),
		), array( 'id' => (int) $id ) );

		do_action( 'tg_commission_paid', (int) $id );

		return true;
	}

	/**
	 * Cancel an invoice, for example when a deal falls through. The number is
	 * kept and never reused, and a reason is required.
	 */
	public static function void( $id, $reason ) {
		global $wpdb;

		$row    = self::get( $id );
		$reason = trim( sanitize_textarea_field( (string) $reason ) );

		if ( ! $row ) {
			return new WP_Error( 'tg_com_missing', 'That commission invoice no longer exists.' );
		}

		if ( self::DUE !== $row['status'] ) {
			return new WP_Error( 'tg_com_not_due', 'Only an unpaid invoice can be cancelled.' );
		}

		if ( '' === $reason ) {
			return new WP_Error( 'tg_com_reason', 'Please give a reason for cancelling the invoice. It is kept on record.' );
		}

		$wpdb->update( TG_Store::commissions_table(), array(
			'status'      => self::VOID,
			'void_reason' => $reason,
			'voided_at'   => current_time( 'mysql' ),
			'recorded_by' => get_current_user_id(),
		), array( 'id' => (int) $id ) );

		do_action( 'tg_commission_voided', (int) $id );

		return true;
	}

	/* ------------------------------------------------------------ Reminders */

	/**
	 * Which reminder applies now: 'pre' (within 3 days of the deadline),
	 * 'due' (on the deadline day), then 'w1', 'w2'... one a week while
	 * overdue. Only the tightest band applies, as with renewal reminders.
	 */
	public static function reminder_due( $row ) {
		if ( ! is_array( $row ) || self::DUE !== $row['status'] || empty( $row['due_at'] ) ) {
			return '';
		}

		$secs = strtotime( $row['due_at'] ) - self::now();
		$sent = array_filter( array_map( 'trim', explode( ',', (string) $row['reminders_sent'] ) ) );

		if ( $secs > 3 * DAY_IN_SECONDS ) {
			$band = '';
		} elseif ( $secs > DAY_IN_SECONDS ) {
			$band = 'pre';
		} elseif ( $secs > 0 ) {
			$band = 'due';
		} else {
			$band = 'w' . ( 1 + (int) floor( abs( $secs ) / WEEK_IN_SECONDS ) );
		}

		return ( $band && ! in_array( $band, $sent, true ) ) ? $band : '';
	}

	/**
	 * Same delivery truth as renewal reminders: a reminder counts only if the
	 * email was actually accepted; a failure is retried daily, never marked.
	 */
	public static function run_reminders() {
		global $wpdb;

		$rows = array_merge( self::query( 'due' ), self::query( 'overdue' ) );
		$now  = current_time( 'mysql' );
		$sent = 0;

		foreach ( $rows as $row ) {
			$band = self::reminder_due( $row );

			if ( ! $band ) {
				continue;
			}

			if ( ! empty( $row['reminder_tried_at'] ) && strtotime( $row['reminder_tried_at'] ) > self::now() - DAY_IN_SECONDS ) {
				continue;
			}

			$ok     = (bool) apply_filters( 'tg_commission_send_reminder', false, $row, $band );
			$update = array( 'reminder_tried_at' => $now );

			if ( $ok ) {
				$keys                     = array_filter( array_map( 'trim', explode( ',', (string) $row['reminders_sent'] ) ) );
				$keys[]                   = $band;
				$update['reminders_sent'] = implode( ',', array_unique( $keys ) );
				$sent++;
			}

			$wpdb->update( TG_Store::commissions_table(), $update, array( 'id' => (int) $row['id'] ) );
		}

		return $sent;
	}

	/* ---------------------------------------------------------------- Export */

	/**
	 * Company names are typed by suppliers. A cell starting with = + - @ is
	 * run as a formula when the file is opened in a spreadsheet, so those are
	 * prefixed with an apostrophe and stay plain text.
	 */
	public static function csv_cell( $value ) {
		$value = (string) $value;

		return ( '' !== $value && false !== strpos( "=+-@\t\r", $value[0] ) ) ? "'" . $value : $value;
	}

	public static function csv( $rows ) {
		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, array( 'Invoice', 'Tender', 'Supplier', 'Awarded value', 'Rate %', 'Commission', 'VAT', 'Total', 'Currency', 'Status', 'Issued', 'Due', 'Paid', 'Payment ref', 'Cancel reason' ) );

		foreach ( $rows as $r ) {
			$tender = TG_Tender::get( $r['tender_id'] );
			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), array(
				$r['invoice_no'],
				$tender ? $tender['external_id'] : '',
				TG_Award::company_of( $r['supplier_id'] ),
				$r['awarded_value'],
				$r['rate'],
				$r['amount'],
				$r['vat_amount'],
				$r['total'],
				$r['currency'],
				self::label( $r ),
				$r['issued_at'],
				$r['due_at'],
				$r['paid_at'],
				$r['payment_ref'],
				$r['void_reason'],
			) ) );
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );

		return $csv;
	}
}
