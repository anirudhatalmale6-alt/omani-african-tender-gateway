<?php
/**
 * Access gate and bidding rules, run against a real WordPress.
 *
 *   php tests/test-access-and-bidding.php /path/to/wordpress
 *
 * This is the security-critical half of M2: who may read paid tender detail,
 * and who may place a bid. Every check here is one that, if it broke, would
 * either give away the product or let an invalid bid into a live tender.
 *
 * Creates TEST- fixtures and users and removes them again at the end.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'TG_Access' ) ) {
	fwrite( STDERR, "The Tender Gateway plugin is not active.\n" );
	exit( 2 );
}

global $wpdb;

$pass = 0;
$fail = 0;
$made_tenders = array();
$made_users   = array();

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

function supplier( $slug, $verified, $sectors = array() ) {
	global $made_users;

	$email = "TEST-{$slug}@example.invalid";
	$id    = wp_create_user( "TEST-{$slug}", wp_generate_password( 20 ), $email );

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	( new WP_User( $id ) )->set_role( TG_Auth::ROLE );
	update_user_meta( $id, 'tg_status', $verified ? 'verified' : 'pending' );
	update_user_meta( $id, 'tg_sectors', $sectors );
	$made_users[] = $id;

	return $id;
}

function tender( $sector = '', $closing = '+10 days', $status = TG_Status::OPEN ) {
	global $made_tenders;

	$id = TG_Tender::create( array(
		'title'      => 'TEST- access fixture',
		'buyer'      => 'Test Buyer',
		'sector'     => $sector,
		'currency'   => 'OMR',
		'closing_at' => gmdate( 'Y-m-d H:i:s', strtotime( $closing ) ),
	), 0 );

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	$made_tenders[] = $id;

	if ( TG_Status::DRAFT !== $status ) {
		TG_Tender::move( $id, TG_Status::PENDING, 0 );

		if ( TG_Status::PENDING !== $status ) {
			TG_Tender::move( $id, TG_Status::APPROVED, 0 );
		}
	}

	return $id;
}

$restore_requirement = get_option( 'tg_require_subscription', false );

$verified   = supplier( 'verified', true );
$unverified = supplier( 'unverified', false );
$narrow     = supplier( 'narrow', true, array( 'Food & Beverage' ) );

echo "\n== Subscription is required by DEFAULT ==\n";
echo "   (shipping with it off would give the whole product away, so the\n";
echo "    option is REMOVED here - setting it to 'yes' first would mean this\n";
echo "    test passed whatever the default actually was)\n";
delete_option( 'tg_require_subscription' );
ok( 'with no option stored at all, subscription is still required', TG_Access::subscription_required() );
ok( 'no subscription provider is registered yet', ! TG_Access::subscriptions_available() );
ok( 'so nobody counts as subscribed', ! TG_Access::is_subscribed( $verified ) );

echo "\n== Visibility levels ==\n";
ok( 'signed out is public',                 TG_Access::PUBLIC_LEVEL === TG_Access::level( 0 ) );
ok( 'a verified supplier is only registered, not subscribed', TG_Access::REGISTERED_LEVEL === TG_Access::level( $verified ) );
ok( 'a registered supplier cannot see full detail', ! TG_Access::can_see_full_detail( $verified ) );
ok( 'signed out cannot see full detail',    ! TG_Access::can_see_full_detail( 0 ) );

echo "\n== Which tenders are visible at all ==\n";
$draft    = TG_Tender::get( tender( '', '+10 days', TG_Status::DRAFT ) );
$pending  = TG_Tender::get( tender( '', '+10 days', TG_Status::PENDING ) );
$open     = TG_Tender::get( tender( '', '+10 days' ) );
ok( 'a draft tender is hidden from suppliers',   ! TG_Access::can_see_tender( $draft, $verified ) );
ok( 'a pending tender is hidden from suppliers', ! TG_Access::can_see_tender( $pending, $verified ) );
ok( 'an open tender is visible',                 TG_Access::can_see_tender( $open, $verified ) );
ok( 'a draft tender is hidden from the public',  ! TG_Access::can_see_tender( $draft, 0 ) );

echo "\n== Who may bid ==\n";
$err = function ( $r ) { return is_wp_error( $r ) ? $r->get_error_code() : 'allowed'; };

ok( 'signed out cannot bid',        'tg_bid_signed_out' === $err( TG_Access::can_bid( $open, 0 ) ) );
ok( 'unverified supplier cannot bid', 'tg_bid_unverified' === $err( TG_Access::can_bid( $open, $unverified ) ) );
ok( 'verified but unsubscribed cannot bid', 'tg_bid_unsubscribed' === $err( TG_Access::can_bid( $open, $verified ) ) );

echo "\n== Eligibility by category ==\n";
$food  = TG_Tender::get( tender( 'Food & Beverage' ) );
$steel = TG_Tender::get( tender( 'Metals & Steel' ) );
ok( 'a Food supplier is eligible for a Food tender',  TG_Access::is_eligible( $food, $narrow ) );
ok( 'a Food supplier is NOT eligible for a Steel tender', ! TG_Access::is_eligible( $steel, $narrow ) );
ok( 'a supplier with no categories is eligible for everything', TG_Access::is_eligible( $steel, $verified ) );
ok( 'a tender with no category is open to everyone',  TG_Access::is_eligible( $open, $narrow ) );

echo "\n== With subscriptions switched off, the gate opens ==\n";
update_option( 'tg_require_subscription', 'no' );
ok( 'verified supplier now counts as subscribed', TG_Access::SUBSCRIBED_LEVEL === TG_Access::level( $verified ) );
ok( 'and may now bid',                            true === TG_Access::can_bid( $food, $narrow ) );
ok( 'but an unverified supplier still may not',   'tg_bid_unverified' === $err( TG_Access::can_bid( $food, $unverified ) ) );
ok( 'and an ineligible supplier still may not',   'tg_bid_ineligible' === $err( TG_Access::can_bid( $steel, $narrow ) ) );

echo "\n== Bidding ==\n";
$bid = TG_Bid::submit( $food['id'], $narrow, array( 'price' => 1234.567, 'delivery_days' => 14, 'validity_days' => 30, 'notes' => 'TEST bid' ) );
ok( 'a valid bid is accepted', ! is_wp_error( $bid ) );
$row = TG_Bid::get( $bid );
ok( 'the price keeps three decimal places (OMR), got ' . ( $row ? $row['price'] : '?' ), $row && '1234.567' === (string) $row['price'] );

$zero = TG_Bid::submit( $food['id'], $narrow, array( 'price' => 0 ) );
ok( 'a zero price is refused', is_wp_error( $zero ) );

$again = TG_Bid::submit( $food['id'], $narrow, array( 'price' => 999 ) );
ok( 're-bidding replaces rather than duplicates', ! is_wp_error( $again ) && (int) $again === (int) $bid );
ok( 'one bid on the tender, not two', 1 === TG_Bid::count_for_tender( $food['id'] ) );

echo "\n== The closing time is enforced on the server ==\n";
echo "   (a late scheduler must never let a late bid through)\n";
$late = TG_Tender::get( tender( '', '-5 minutes' ) );
ok( 'the tender still displays as Open', TG_Status::OPEN === $late['status'] );
ok( 'but a bid on it is refused as closed', 'tg_bid_closed' === $err( TG_Access::can_bid( $late, $narrow ) ) );
$late_bid = TG_Bid::submit( $late['id'], $narrow, array( 'price' => 500 ) );
ok( 'and TG_Bid::submit() refuses it too, not just the gate', is_wp_error( $late_bid ) );

echo "\n== Withdrawing ==\n";
$other = supplier( 'other', true );
$deny  = TG_Bid::withdraw( $bid, $other );
ok( 'a supplier cannot withdraw somebody else\'s bid', is_wp_error( $deny ) && 'tg_bid_not_yours' === $deny->get_error_code() );
ok( 'a supplier can withdraw their own', true === TG_Bid::withdraw( $bid, $narrow ) );
ok( 'a withdrawn bid is no longer counted', 0 === TG_Bid::count_for_tender( $food['id'] ) );

echo "\n== Cancelling a tender voids its bids rather than deleting them ==\n";
$cancelled = TG_Tender::get( tender( '' ) );
TG_Bid::submit( $cancelled['id'], $narrow, array( 'price' => 777 ) );
TG_Tender::move( $cancelled['id'], TG_Status::CANCELLED, 0, 'Buyer withdrew the requirement.' );
$voided = TG_Bid::find( $cancelled['id'], $narrow );
ok( 'the bid still exists', (bool) $voided );
ok( 'marked void, got ' . ( $voided ? $voided['status'] : '?' ), $voided && TG_Bid::VOID === $voided['status'] );
ok( 'with the cancellation reason recorded', $voided && false !== stripos( (string) $voided['status_reason'], 'withdrew the requirement' ) );

echo "\n== Sealed bids ==\n";
$t  = TG_Tender::get( tender( '' ) );
$s1 = supplier( 'seal1', true );
$s2 = supplier( 'seal2', true );
TG_Bid::submit( $t['id'], $s1, array( 'price' => 100 ) );
TG_Bid::submit( $t['id'], $s2, array( 'price' => 200 ) );
ok( 'the buyer side sees both bids', 2 === count( TG_Bid::for_tender( $t['id'] ) ) );
$mine = TG_Bid::for_supplier( $s1 );
$ids  = array_map( function ( $b ) { return (int) $b['tender_id']; }, $mine );
ok( 'a supplier\'s own history returns only their own bids', 1 === count( array_filter( $mine, function ( $b ) use ( $s1 ) { return (int) $b['supplier_id'] === (int) $s1; } ) ) );
ok( 'and nothing belonging to the other supplier', 0 === count( array_filter( $mine, function ( $b ) use ( $s2 ) { return (int) $b['supplier_id'] === (int) $s2; } ) ) );

// Clean up.
if ( false === $restore_requirement ) {
	delete_option( 'tg_require_subscription' );
} else {
	update_option( 'tg_require_subscription', $restore_requirement );
}

foreach ( $made_tenders as $id ) {
	$wpdb->delete( TG_Store::bids_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::events_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::table(), array( 'id' => $id ) );
}

foreach ( $made_users as $id ) {
	$wpdb->delete( TG_Store::bids_table(), array( 'supplier_id' => $id ) );
	wp_delete_user( $id );
}

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
