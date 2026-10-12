<?php
/**
 * Supplier terms and the commission, against a real WordPress.
 *
 *   php tests/test-commission.php /path/to/wordpress
 *
 * What must hold: the amount is right to three decimals, a tender is billed
 * once, invoice numbers never repeat, overdue blocks NEW bids only, and no
 * bid goes in without the current terms accepted.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'TG_Commission' ) || ! class_exists( 'TG_Terms' ) ) {
	fwrite( STDERR, "TG_Commission / TG_Terms are not loaded.\n" );
	exit( 2 );
}

global $wpdb;

$pass    = 0;
$fail    = 0;
$users   = array();
$tenders = array();
$plans   = array();

function ok( $label, $cond ) {
	global $pass, $fail;

	if ( $cond ) {
		$pass++;
		echo "  PASS  {$label}\n";
	} else {
		$fail++;
		echo "  FAIL  {$label}\n";
	}
}

$keep = array();
foreach ( array( 'tg_terms_text', 'tg_terms_version', 'tg_terms_versions', 'tg_commission_rate', 'tg_commission_due_days', 'tg_commission_seq', 'tg_invoice_seq', 'tg_require_subscription', 'tg_vat_rate' ) as $opt ) {
	$keep[ $opt ] = get_option( $opt, null );
}
register_shutdown_function( 'tg_test_commission_cleanup' );

// Leftovers from an earlier crashed run would make every fixture collide.
foreach ( get_users( array( 'search' => 'TEST-c-*', 'search_columns' => array( 'user_login' ) ) ) as $old ) {
	$wpdb->delete( TG_Store::subs_table(), array( 'supplier_id' => $old->ID ) );
	wp_delete_user( $old->ID );
}

delete_option( 'tg_terms_text' );
delete_option( 'tg_commission_rate' );
delete_option( 'tg_commission_due_days' );
update_option( 'tg_vat_rate', 5 );
update_option( 'tg_require_subscription', 'yes' );

// Every email in this suite is swallowed; delivery is test-notifications' job.
add_filter( 'pre_wp_mail', '__return_true', 5 );

$plan    = TG_Subscription::save_plan( array( 'name' => 'TEST Com Plan', 'price' => 10, 'period' => 'monthly', 'is_active' => 1 ) );
$plans[] = $plan;

function sup( $slug, $accept = true ) {
	global $users, $plan;

	$id = wp_create_user( 'TEST-c-' . $slug, wp_generate_password( 20 ), "TEST-c-{$slug}@example.invalid" );
	( new WP_User( $id ) )->set_role( TG_Auth::ROLE );
	update_user_meta( $id, 'tg_company', 'TEST ' . ucfirst( $slug ) . ' LLC' );
	update_user_meta( $id, 'tg_sectors', array( 'TEST Sector' ) );
	update_user_meta( $id, 'tg_status', 'verified' );
	TG_Subscription::start( $id, $plan );

	if ( $accept ) {
		TG_Terms::accept( $id, '127.0.0.1' );
	}

	$users[] = $id;

	return $id;
}

function open_tender( $title ) {
	global $tenders;

	$id = TG_Tender::create( array(
		'title'      => 'TEST ' . $title,
		'sector'     => 'TEST Sector',
		'currency'   => 'OMR',
		'closing_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + 5 * DAY_IN_SECONDS ),
	), 0 );
	$tenders[] = $id;
	TG_Tender::move( $id, TG_Status::PENDING );
	TG_Tender::move( $id, TG_Status::APPROVED );

	return $id;
}

function code_of( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
}

/* ---------------------------------------------------------------- Terms */

echo "\n== The terms say what is charged ==\n";
$text = TG_Terms::text();
ok( 'the default terms state the 2% rate', false !== strpos( $text, '2% of the awarded value' ) );
ok( 'and the 14-day deadline', false !== strpos( $text, 'within 14 days' ) );
ok( 'and that late payment pauses new bids only', false !== stripos( $text, 'Bids already submitted are not affected' ) );
ok( 'no placeholder is left in the text', false === strpos( $text, '{' ) );

echo "\n== No bid without the current terms ==\n";
$t_open = open_tender( 'terms fixture' );
$t_row  = TG_Tender::get( $t_open );
$late   = sup( 'late', false );

ok( 'a supplier who has not accepted is refused', 'tg_bid_terms' === code_of( TG_Access::can_bid( $t_row, $late ) ) );
TG_Terms::accept( $late, '10.0.0.1' );
ok( 'after accepting, the same supplier may bid', true === TG_Access::can_bid( $t_row, $late ) );
ok( 'the version accepted is recorded', TG_Terms::version() === TG_Terms::accepted_version( $late ) );
ok( 'with the time', '' !== TG_Terms::accepted_at( $late ) );
$log = get_user_meta( $late, 'tg_terms_log', true );
ok( 'and a history entry with the IP address', is_array( $log ) && '10.0.0.1' === end( $log )['ip'] );
$versions = get_option( 'tg_terms_versions' );
ok( 'the exact wording of that version is kept', is_array( $versions ) && $versions[ TG_Terms::version() ] === TG_Terms::text() );

$v_before = TG_Terms::version();
ok( 'saving identical terms is not a new version', false === TG_Terms::save( TG_Terms::raw_text(), 2, 14 ) && TG_Terms::version() === $v_before );
TG_Terms::save( TG_Terms::raw_text() . "\n\n8. TEST added clause.", 2, 14 );
ok( 'changing the wording is a new version', TG_Terms::version() === $v_before + 1 );
ok( 'and the supplier must accept again before bidding', 'tg_bid_terms' === code_of( TG_Access::can_bid( $t_row, $late ) ) );
TG_Terms::accept( $late );
$v2 = TG_Terms::version();
TG_Terms::save( TG_Terms::raw_text(), 2.5, 14 );
ok( 'changing the RATE is also a new version - it is in the terms', TG_Terms::version() === $v2 + 1 && false !== strpos( TG_Terms::text(), '2.5%' ) );
TG_Terms::save( TG_Terms::default_text(), 2, 14 );
foreach ( $users as $u ) {
	TG_Terms::accept( $u );
}

// Accepting through the dashboard form must refuse a page showing an old
// version - otherwise a supplier is recorded agreeing to words never shown.
$stale = sup( 'stale', false );
wp_set_current_user( $stale );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	'tg_terms_action'  => 'accept',
	'tg_terms_agree'   => '1',
	'tg_terms_version' => TG_Terms::version() - 1,
	'tg_terms_nonce'   => wp_create_nonce( 'tg_terms_' . $stale ),
);
TG_Terms::handle_accept();
ok( 'a form showing an OLD version does not record acceptance', ! TG_Terms::has_accepted( $stale ) );
$_POST['tg_terms_version'] = TG_Terms::version();
unset( $_POST['tg_terms_agree'] );
TG_Terms::handle_accept();
ok( 'nor does a form without the box ticked', ! TG_Terms::has_accepted( $stale ) );
$_POST['tg_terms_agree'] = '1';
TG_Terms::handle_accept();
ok( 'the current version, ticked, does', TG_Terms::has_accepted( $stale ) );
$_POST = array();
$_SERVER['REQUEST_METHOD'] = 'GET';
wp_set_current_user( 0 );

/* ------------------------------------------------------------ Issuing */

echo "\n== A completed tender is billed once, correctly ==\n";
$win   = sup( 'win' );
$lose  = sup( 'lose' );
$t1    = open_tender( 'commission fixture' );
TG_Bid::submit( $t1, $win, array( 'price' => 1234.567, 'currency' => 'OMR' ) );
TG_Bid::submit( $t1, $lose, array( 'price' => 1300, 'currency' => 'OMR' ) );
TG_Tender::move( $t1, TG_Status::CLOSED );
$wbid = TG_Bid::find( $t1, $win );
TG_Award::award( $t1, $wbid['id'], 1, '' );

$no = TG_Commission::issue( open_tender( 'never awarded' ) );
ok( 'a tender with no award cannot be billed', 'tg_com_not_awarded' === code_of( $no ) );

ok( 'nothing is billed at award', null === TG_Commission::for_tender( $t1 ) );

$sub_seq_before = (int) get_option( 'tg_invoice_seq', 0 );

// Completion - fired the way TG_Tender::move() fires it once M6 opens the
// Completed status.
do_action( 'tg_tender_status_changed', $t1, TG_Status::AWARDED, TG_Status::COMPLETED, 1, '' );
$c1 = TG_Commission::for_tender( $t1 );

ok( 'completion issues a commission invoice', is_array( $c1 ) );
ok( 'to the winning supplier', $c1 && (int) $c1['supplier_id'] === $win );
ok( '2% of 1234.567 is 24.691 - three decimals, not two', $c1 && abs( (float) $c1['amount'] - 24.691 ) < 0.0005 );
ok( 'VAT at 5% on the commission is 1.235', $c1 && abs( (float) $c1['vat_amount'] - 1.235 ) < 0.0005 );
ok( 'total 25.926', $c1 && abs( (float) $c1['total'] - 25.926 ) < 0.0005 );
ok( 'with its own numbering', $c1 && 0 === strpos( $c1['invoice_no'], 'JT-COM-' ) );
ok( 'which does not consume a subscription invoice number', (int) get_option( 'tg_invoice_seq', 0 ) === $sub_seq_before );
ok( 'due in 14 days', $c1 && 14 * DAY_IN_SECONDS === strtotime( $c1['due_at'] ) - strtotime( $c1['issued_at'] ) );
$t1row = TG_Tender::get( $t1 );
ok( 'the tender records the fee', abs( (float) $t1row['fee_amount'] - 24.691 ) < 0.0005 && 'supplier' === $t1row['fee_party'] );

do_action( 'tg_tender_status_changed', $t1, TG_Status::AWARDED, TG_Status::COMPLETED, 1, '' );
$again = TG_Commission::issue( $t1 );
$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . TG_Store::commissions_table() . ' WHERE tender_id = %d', $t1 ) );
ok( 'completing it again does not bill twice', 1 === $count && (int) $again === (int) $c1['id'] );

/* ------------------------------------------------------------ Overdue */

echo "\n== Overdue blocks NEW bids, and only new bids ==\n";
$t2 = open_tender( 'next tender' );
$t2row = TG_Tender::get( $t2 );
$t3 = open_tender( 'already bid' );
TG_Bid::submit( $t3, $win, array( 'price' => 500, 'currency' => 'OMR' ) );

ok( 'while not yet due, the winner may still bid', true === TG_Access::can_bid( $t2row, $win ) );

$wpdb->update( TG_Store::commissions_table(), array( 'due_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - HOUR_IN_SECONDS ) ), array( 'id' => $c1['id'] ) );
$c1 = TG_Commission::get( $c1['id'] );
ok( 'an hour past the deadline it is overdue', TG_Commission::is_overdue( $c1 ) );
ok( 'and labelled Overdue', 'Overdue' === TG_Commission::label( $c1 ) );
ok( 'a new bid is refused', 'tg_bid_commission_overdue' === code_of( TG_Access::can_bid( $t2row, $win ) ) );
$refusal = TG_Access::can_bid( $t2row, $win );
ok( 'with the invoice number in the message', is_wp_error( $refusal ) && false !== strpos( $refusal->get_error_message(), $c1['invoice_no'] ) );
$placed = TG_Bid::find( $t3, $win );
ok( 'the bid already placed elsewhere stands', $placed && TG_Bid::SUBMITTED === $placed['status'] );
ok( 'the other supplier is unaffected', true === TG_Access::can_bid( $t2row, $lose ) );

$r = TG_Commission::record_payment( $c1['id'], array( 'payment_ref' => 'TEST-TT-1' ) );
ok( 'recording payment succeeds', true === $r );
ok( 'and the supplier may bid again at once', true === TG_Access::can_bid( $t2row, $win ) );
ok( 'paying twice is refused', 'tg_com_not_due' === code_of( TG_Commission::record_payment( $c1['id'] ) ) );
ok( 'a paid invoice cannot be cancelled', 'tg_com_not_due' === code_of( TG_Commission::void( $c1['id'], 'x' ) ) );

/* --------------------------------------------------------------- Void */

echo "\n== Cancelling keeps the number ==\n";
$t4 = open_tender( 'falls through' );
TG_Bid::submit( $t4, $lose, array( 'price' => 1000, 'currency' => 'OMR' ) );
TG_Tender::move( $t4, TG_Status::CLOSED );
$lb = TG_Bid::find( $t4, $lose );
TG_Award::award( $t4, $lb['id'], 1, '' );
$c4 = TG_Commission::get( TG_Commission::issue( $t4 ) );

ok( 'cancelling without a reason is refused', 'tg_com_reason' === code_of( TG_Commission::void( $c4['id'], '  ' ) ) );
ok( 'with a reason it is cancelled', true === TG_Commission::void( $c4['id'], 'TEST buyer withdrew' ) );
$c4 = TG_Commission::get( $c4['id'] );
ok( 'the reason is on record', 'TEST buyer withdrew' === $c4['void_reason'] && TG_Commission::VOID === $c4['status'] );
ok( 'a cancelled invoice never blocks bidding', true === TG_Access::can_bid( $t2row, $lose ) );

$t5 = open_tender( 'after void' );
TG_Bid::submit( $t5, $lose, array( 'price' => 200, 'currency' => 'OMR' ) );
TG_Tender::move( $t5, TG_Status::CLOSED );
$b5 = TG_Bid::find( $t5, $lose );
TG_Award::award( $t5, $b5['id'], 1, '' );
$c5 = TG_Commission::get( TG_Commission::issue( $t5 ) );
$n4 = (int) str_replace( TG_Commission::prefix(), '', $c4['invoice_no'] );
$n5 = (int) str_replace( TG_Commission::prefix(), '', $c5['invoice_no'] );
ok( 'the next invoice gets the next number - the cancelled one is not reused', $n5 === $n4 + 1 );

$wpdb->delete( TG_Store::commissions_table(), array( 'id' => $c5['id'] ) );
$t6 = open_tender( 'after delete' );
TG_Bid::submit( $t6, $lose, array( 'price' => 300, 'currency' => 'OMR' ) );
TG_Tender::move( $t6, TG_Status::CLOSED );
$b6 = TG_Bid::find( $t6, $lose );
TG_Award::award( $t6, $b6['id'], 1, '' );
$c6 = TG_Commission::get( TG_Commission::issue( $t6 ) );
ok( 'even a deleted row does not free its number', (int) str_replace( TG_Commission::prefix(), '', $c6['invoice_no'] ) === $n5 + 1 );

/* ---------------------------------------------------------- Reminders */

echo "\n== Reminders ==\n";
$mk = function ( $secs, $sent = '' ) {
	return array( 'status' => 'due', 'reminders_sent' => $sent, 'due_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + $secs ) );
};
ok( '10 days before: nothing yet', '' === TG_Commission::reminder_due( $mk( 10 * DAY_IN_SECONDS ) ) );
ok( '2 days before: the advance reminder', 'pre' === TG_Commission::reminder_due( $mk( 2 * DAY_IN_SECONDS ) ) );
ok( 'on the day: the due-today reminder', 'due' === TG_Commission::reminder_due( $mk( 6 * HOUR_IN_SECONDS ) ) );
ok( 'a day late: the first overdue reminder', 'w1' === TG_Commission::reminder_due( $mk( -DAY_IN_SECONDS ) ) );
ok( 'eight days late: the second', 'w2' === TG_Commission::reminder_due( $mk( -8 * DAY_IN_SECONDS ) ) );
ok( 'a reminder already sent is not repeated', '' === TG_Commission::reminder_due( $mk( -DAY_IN_SECONDS, 'pre,due,w1' ) ) );
ok( 'a paid invoice is never chased', '' === TG_Commission::reminder_due( array( 'status' => 'paid', 'reminders_sent' => '', 'due_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ) ) ) );

$wpdb->update( TG_Store::commissions_table(), array( 'due_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ), 'reminders_sent' => '', 'reminder_tried_at' => null ), array( 'id' => $c6['id'] ) );
$no_send = function () { return false; };
add_filter( 'tg_commission_send_reminder', $no_send, 99 );
TG_Commission::run_reminders();
remove_filter( 'tg_commission_send_reminder', $no_send, 99 );
$c6 = TG_Commission::get( $c6['id'] );
ok( 'a reminder that failed to send is not recorded', '' === trim( (string) $c6['reminders_sent'] ) && ! empty( $c6['reminder_tried_at'] ) );
$wpdb->update( TG_Store::commissions_table(), array( 'reminder_tried_at' => null ), array( 'id' => $c6['id'] ) );
$yes_send = function () { return true; };
add_filter( 'tg_commission_send_reminder', $yes_send, 99 );
TG_Commission::run_reminders();
remove_filter( 'tg_commission_send_reminder', $yes_send, 99 );
ok( 'one that sent is recorded', 'w1' === TG_Commission::get( $c6['id'] )['reminders_sent'] );

/* -------------------------------------------------------------- Export */

echo "\n== Export ==\n";
update_user_meta( $lose, 'tg_company', '=HYPERLINK("http://example.invalid","x")' );
$csv = TG_Commission::csv( array( TG_Commission::get( $c6['id'] ) ) );
ok( 'the export has the invoice', false !== strpos( $csv, $c6['invoice_no'] ) );
ok( 'a company name that looks like a formula is neutralised', false !== strpos( $csv, "'=HYPERLINK" ) && false === strpos( $csv, ',=HYPERLINK' ) && false === strpos( $csv, ',"=HYPERLINK' ) );

/* ------------------------------------------------------------- Cleanup */

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );

/**
 * Registered at the top, so it runs even if a check crashes half-way - a
 * crashed run must not leave fixtures behind to poison the next one.
 */
function tg_test_commission_cleanup() {
	global $wpdb, $tenders, $users, $plans, $keep;

	remove_filter( 'pre_wp_mail', '__return_true', 5 );

foreach ( (array) $tenders as $id ) {
	$wpdb->delete( TG_Store::commissions_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::bids_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::events_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::table(), array( 'id' => $id ) );
}
foreach ( (array) $users as $id ) {
	$wpdb->delete( TG_Store::subs_table(), array( 'supplier_id' => $id ) );
	wp_delete_user( $id );
}
foreach ( (array) $plans as $id ) {
	$wpdb->delete( TG_Store::plans_table(), array( 'id' => $id ) );
}
foreach ( (array) $keep as $opt => $value ) {
	// The counters only move forward, even after a test run.
	if ( in_array( $opt, array( 'tg_commission_seq', 'tg_invoice_seq' ), true ) ) {
		continue;
	}
	if ( null === $value ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $value );
	}
}
}
