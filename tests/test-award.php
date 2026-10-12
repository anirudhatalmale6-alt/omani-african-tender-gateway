<?php
/**
 * Award rules, against a real WordPress.
 *
 *   php tests/test-award.php /path/to/wordpress
 *
 * The rules that matter here are the ones that REFUSE: awarding before a
 * tender closes, awarding a withdrawn bid, and choosing a higher bid without
 * recording why. Those are what a losing supplier would challenge.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'TG_Award' ) ) {
	fwrite( STDERR, "TG_Award is not loaded - is the plugin active and updated?\n" );
	exit( 2 );
}

global $wpdb;

$pass = 0;
$fail = 0;
$tenders = array();
$users   = array();

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

function sup( $slug, $company ) {
	global $users;

	$id = wp_create_user( 'TEST-' . $slug, wp_generate_password( 20 ), "TEST-{$slug}@example.invalid" );

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	( new WP_User( $id ) )->set_role( TG_Auth::ROLE );
	update_user_meta( $id, 'tg_status', 'verified' );
	// As at registration: a real supplier accepts the terms to sign up.
	if ( class_exists( 'TG_Terms' ) ) {
		TG_Terms::accept( $id, '127.0.0.1' );
	}
	update_user_meta( $id, 'tg_company', $company );
	$users[] = $id;

	return $id;
}

function open_tender( $closing = '+5 days' ) {
	global $tenders;

	$id = TG_Tender::create( array(
		'title'      => 'TEST- award fixture',
		'buyer'      => 'Test Buyer',
		'currency'   => 'OMR',
		'closing_at' => gmdate( 'Y-m-d H:i:s', strtotime( $closing ) ),
	), 0 );

	$tenders[] = $id;
	TG_Tender::move( $id, TG_Status::PENDING, 0 );
	TG_Tender::move( $id, TG_Status::APPROVED, 0 );

	return $id;
}

$restore = get_option( 'tg_require_subscription', false );
update_option( 'tg_require_subscription', 'no' );   // so fixtures can bid

$a = sup( 'alpha', 'Alpha Trading LLC' );
$b = sup( 'beta', 'Beta Supplies LLC' );
$c = sup( 'gamma', 'Gamma Foods LLC' );

echo "\n== A tender cannot be awarded before it closes ==\n";
$t1 = open_tender( '+5 days' );
$b1 = TG_Bid::submit( $t1, $a, array( 'price' => 1000 ) );
$r  = TG_Award::award( $t1, $b1, 0 );
ok( 'awarding an OPEN tender is refused', is_wp_error( $r ) && 'tg_award_not_closed' === $r->get_error_code() );

echo "\n== Lowest bid can be awarded with no reason ==\n";
$t2 = open_tender( '+5 days' );
$lo = TG_Bid::submit( $t2, $a, array( 'price' => 900 ) );
$mid= TG_Bid::submit( $t2, $b, array( 'price' => 1200 ) );
$hi = TG_Bid::submit( $t2, $c, array( 'price' => 1500 ) );
TG_Tender::move( $t2, TG_Status::CLOSED, 0 );
$cmp = TG_Award::comparison( $t2 );
ok( 'comparison returns all three bids', 3 === count( $cmp ) );
ok( 'cheapest first, got ' . $cmp[0]['price'], (float) $cmp[0]['price'] === 900.0 );
ok( 'the cheapest is flagged lowest', ! empty( $cmp[0]['is_lowest'] ) );
ok( 'the dearest is not flagged lowest', empty( $cmp[2]['is_lowest'] ) );
ok( 'company name resolved, got ' . $cmp[0]['company'], 'Alpha Trading LLC' === $cmp[0]['company'] );
ok( 'awarding the lowest needs no reason', true === TG_Award::award( $t2, $lo, 0 ) );

echo "\n== The award record and the bid outcomes ==\n";
$t2row = TG_Tender::get( $t2 );
ok( 'tender is now Awarded', TG_Status::AWARDED === $t2row['status'] );
$rec = TG_Award::record( $t2row );
ok( 'award record exists', (bool) $rec );
// Compare numerically, not as a string: MySQL renders DECIMAL(18,3) as
// "900.000" and SQLite as "900". The property that matters is that the value
// survives intact, which the three-decimal case below actually proves.
ok( 'records the winning value, got ' . ( $rec ? $rec['value'] : '?' ), $rec && 900.0 === (float) $rec['value'] );
ok( 'records the winning company', $rec && 'Alpha Trading LLC' === $rec['company'] );
ok( 'winner marked won', TG_Bid::WON === TG_Bid::get( $lo )['status'] );
ok( 'other bidder marked lost', TG_Bid::LOST === TG_Bid::get( $mid )['status'] );
ok( 'third bidder marked lost too', TG_Bid::LOST === TG_Bid::get( $hi )['status'] );

echo "\n== Three decimal places survive the whole round trip (OMR) ==\n";
$t2b = open_tender( '+5 days' );
$pb  = TG_Bid::submit( $t2b, $a, array( 'price' => 1234.567 ) );
TG_Tender::move( $t2b, TG_Status::CLOSED, 0 );
ok( 'bid stored to 3dp, got ' . TG_Bid::get( $pb )['price'], 1234.567 === (float) TG_Bid::get( $pb )['price'] );
ok( 'awarded', true === TG_Award::award( $t2b, $pb, 0 ) );
$recb = TG_Award::record( TG_Tender::get( $t2b ) );
ok( 'award value keeps 3dp, got ' . ( $recb ? $recb['value'] : '?' ), $recb && 1234.567 === (float) $recb['value'] );
ok( 'and is not silently rounded to 2dp', $recb && 1234.57 !== (float) $recb['value'] );

echo "\n== Choosing a higher bid REQUIRES a written reason ==\n";
$t3 = open_tender( '+5 days' );
$cheap = TG_Bid::submit( $t3, $a, array( 'price' => 500 ) );
$dear  = TG_Bid::submit( $t3, $b, array( 'price' => 800 ) );
TG_Tender::move( $t3, TG_Status::CLOSED, 0 );
$r = TG_Award::award( $t3, $dear, 0 );
ok( 'refused with no reason', is_wp_error( $r ) && 'tg_award_reason' === $r->get_error_code() );
ok( 'accepted with a reason', true === TG_Award::award( $t3, $dear, 0, 'Shorter delivery time and prior performance.' ) );
$rec3 = TG_Award::record( TG_Tender::get( $t3 ) );
ok( 'the reason is kept with the award', $rec3 && false !== stripos( $rec3['reason'], 'prior performance' ) );
ok( 'the cheaper bid is marked lost', TG_Bid::LOST === TG_Bid::get( $cheap )['status'] );

echo "\n== A withdrawn bid cannot win ==\n";
$t4 = open_tender( '+5 days' );
$w  = TG_Bid::submit( $t4, $a, array( 'price' => 700 ) );
$k  = TG_Bid::submit( $t4, $b, array( 'price' => 750 ) );
TG_Bid::withdraw( $w, $a );
TG_Tender::move( $t4, TG_Status::CLOSED, 0 );
$r = TG_Award::award( $t4, $w, 0 );
ok( 'awarding a withdrawn bid is refused', is_wp_error( $r ) && 'tg_award_bid_not_live' === $r->get_error_code() );
ok( 'the remaining bid is now the lowest and wins with no reason', true === TG_Award::award( $t4, $k, 0 ) );

echo "\n== A bid from another tender cannot be awarded ==\n";
$t5 = open_tender( '+5 days' );
TG_Bid::submit( $t5, $a, array( 'price' => 100 ) );
TG_Tender::move( $t5, TG_Status::CLOSED, 0 );
$r = TG_Award::award( $t5, $k, 0 );   // $k belongs to t4
ok( 'cross-tender award is refused', is_wp_error( $r ) && 'tg_award_no_bid' === $r->get_error_code() );

echo "\n== The status dropdown cannot be used to bypass the award ==\n";
echo "   (an awarded tender with no winner attached is the record a losing\n";
echo "    supplier would ask to see, and it would not exist)\n";
$t6 = open_tender( '+5 days' );
TG_Bid::submit( $t6, $a, array( 'price' => 300 ) );
TG_Tender::move( $t6, TG_Status::CLOSED, 0 );
$r = TG_Tender::move( $t6, TG_Status::AWARDED, 0, 'Trying to skip choosing a winner.' );
ok( 'moving straight to Awarded is refused', is_wp_error( $r ) && 'tg_award_via_panel' === $r->get_error_code() );
ok( 'the tender is still Closed', TG_Status::CLOSED === TG_Tender::get( $t6 )['status'] );

echo "\n== Awarding is recorded in the tender history ==\n";
$events = TG_Tender::events( $t3 );
$last   = end( $events );
ok( 'the final event is the award', TG_Status::AWARDED === $last['to_status'] );
ok( 'and carries the reason', false !== stripos( (string) $last['reason'], 'prior performance' ) );

// Clean up.
if ( false === $restore ) {
	delete_option( 'tg_require_subscription' );
} else {
	update_option( 'tg_require_subscription', $restore );
}

foreach ( $tenders as $id ) {
	$wpdb->delete( TG_Store::bids_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::events_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::table(), array( 'id' => $id ) );
}

foreach ( $users as $id ) {
	$wpdb->delete( TG_Store::bids_table(), array( 'supplier_id' => $id ) );
	wp_delete_user( $id );
}

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
