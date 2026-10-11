<?php
/**
 * Subscription rules, against a real WordPress.
 *
 *   php tests/test-subscriptions.php /path/to/wordpress
 *
 * The three that matter: access is judged against the clock, invoice numbers
 * are sequential and never reused, and cancelling runs to the end of the paid
 * period rather than cutting access off immediately.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'TG_Subscription' ) ) {
	fwrite( STDERR, "TG_Subscription is not loaded.\n" );
	exit( 2 );
}

global $wpdb;

$pass = 0;
$fail = 0;
$users = array();
$plans = array();

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

function sup( $slug ) {
	global $users;

	$id = wp_create_user( 'TEST-sub-' . $slug, wp_generate_password( 20 ), "TEST-sub-{$slug}@example.invalid" );

	if ( is_wp_error( $id ) ) {
		return 0;
	}

	( new WP_User( $id ) )->set_role( TG_Auth::ROLE );
	update_user_meta( $id, 'tg_status', 'verified' );
	$users[] = $id;

	return $id;
}

// Start from the documented default rather than whatever the site is set to.
$restore_req = get_option( 'tg_require_subscription', false );
$restore_vat = get_option( 'tg_vat_rate', false );
delete_option( 'tg_require_subscription' );
update_option( 'tg_vat_rate', 5 );

$a = sup( 'a' );
$b = sup( 'b' );

$monthly = TG_Subscription::save_plan( array(
	'name' => 'TEST Monthly', 'price' => 25, 'currency' => 'OMR', 'period' => 'monthly', 'is_active' => 1,
) );
$annual = TG_Subscription::save_plan( array(
	'name' => 'TEST Annual', 'price' => 250, 'currency' => 'OMR', 'period' => 'annual', 'free_months' => 1, 'is_active' => 1,
) );
$plans[] = $monthly;
$plans[] = $annual;

echo "\n== Nobody is subscribed until they are ==\n";
ok( 'a fresh supplier is not subscribed', ! TG_Subscription::is_active( $a ) );
ok( 'and the access gate agrees', ! TG_Access::is_subscribed( $a ) );
ok( 'subscription is required by default', TG_Access::subscription_required() );
ok( 'a provider is now registered, unlike before M4', TG_Access::subscriptions_available() );

echo "\n== Starting a subscription ==\n";
$s1 = TG_Subscription::start( $a, $monthly, array( 'payment_method' => 'bank transfer', 'payment_ref' => 'TT-991' ) );
ok( 'subscription created', ! is_wp_error( $s1 ) );
$r1 = TG_Subscription::get( $s1 );
ok( 'supplier is now subscribed', TG_Subscription::is_active( $a ) );
ok( 'and the access gate agrees', TG_Access::is_subscribed( $a ) );
ok( 'ends about a month out, got ' . $r1['ends_at'],
	abs( strtotime( $r1['ends_at'] ) - strtotime( '+1 month' ) ) < 2 * DAY_IN_SECONDS );

echo "\n== VAT is calculated and stored, not left to the invoice template ==\n";
ok( 'net price 25.000, got ' . $r1['price'], 25.0 === (float) $r1['price'] );
ok( 'VAT at 5% is 1.250, got ' . $r1['vat_amount'], 1.25 === (float) $r1['vat_amount'] );
ok( 'total 26.250, got ' . $r1['total'], 26.25 === (float) $r1['total'] );
ok( 'VAT rate recorded on the row itself', 5.0 === (float) $r1['vat_rate'] );

echo "\n== Invoice numbers: sequential, and never reused ==\n";
$s2 = TG_Subscription::start( $b, $monthly );
$r2 = TG_Subscription::get( $s2 );
ok( 'two subscriptions have different invoice numbers', $r1['invoice_no'] !== $r2['invoice_no'] );
preg_match( '/(\d+)$/', $r1['invoice_no'], $m1 );
preg_match( '/(\d+)$/', $r2['invoice_no'], $m2 );
ok( 'and they are consecutive, ' . $r1['invoice_no'] . ' then ' . $r2['invoice_no'],
	(int) $m2[1] === (int) $m1[1] + 1 );

$kept = $r2['invoice_no'];
$wpdb->delete( TG_Store::subs_table(), array( 'id' => $s2 ) );
$s3 = TG_Subscription::start( $b, $monthly );
$r3 = TG_Subscription::get( $s3 );
ok( 'deleting a row does NOT free its invoice number, got ' . $r3['invoice_no'], $r3['invoice_no'] !== $kept );

echo "\n== A renewal extends the period rather than discarding paid days ==\n";
$before = TG_Subscription::current( $a );
$s4     = TG_Subscription::start( $a, $monthly );
$after  = TG_Subscription::get( $s4 );
ok( 'the new period starts when the old one ended', $after['starts_at'] === $before['ends_at'] );
ok( 'and ends about two months from now, got ' . $after['ends_at'],
	abs( strtotime( $after['ends_at'] ) - strtotime( '+2 months' ) ) < 3 * DAY_IN_SECONDS );

echo "\n== Free months are added on top ==\n";
$c  = sup( 'c' );
$s5 = TG_Subscription::start( $c, $annual );
$r5 = TG_Subscription::get( $s5 );
ok( 'annual plus one free month is about 13 months, got ' . $r5['ends_at'],
	abs( strtotime( $r5['ends_at'] ) - strtotime( '+13 months' ) ) < 3 * DAY_IN_SECONDS );

echo "\n== Access is judged against the clock, not the stored status ==\n";
$d  = sup( 'd' );
$s6 = TG_Subscription::start( $d, $monthly );
// Backdate it so it has lapsed, WITHOUT running the expiry sweep.
$wpdb->update( TG_Store::subs_table(), array( 'ends_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ), array( 'id' => $s6 ) );
ok( 'the row still says active', TG_Subscription::ACTIVE === TG_Subscription::get( $s6 )['status'] );
ok( 'but the supplier is NOT treated as subscribed', ! TG_Subscription::is_active( $d ) );
ok( 'and the access gate refuses as well', ! TG_Access::is_subscribed( $d ) );
TG_Subscription::expire_due();
ok( 'the sweep then marks it expired', TG_Subscription::EXPIRED === TG_Subscription::get( $s6 )['status'] );

echo "\n== Cancelling runs to the end of the paid period, with no refund ==\n";
$e  = sup( 'e' );
$s7 = TG_Subscription::start( $e, $monthly );
ok( 'cancel accepted', true === TG_Subscription::cancel( $s7, 'Supplier asked to stop.' ) );
ok( 'marked cancelled', TG_Subscription::CANCELLED === TG_Subscription::get( $s7 )['status'] );
ok( 'but access continues to the end of the period', TG_Subscription::is_active( $e ) );
$wpdb->update( TG_Store::subs_table(), array( 'ends_at' => gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) ) ), array( 'id' => $s7 ) );
ok( 'and stops once that period ends', ! TG_Subscription::is_active( $e ) );

echo "\n== An expired supplier keeps their history ==\n";
ok( 'history still lists their subscription', count( TG_Subscription::history( $e ) ) >= 1 );
ok( 'with the invoice number intact', '' !== TG_Subscription::get( $s7 )['invoice_no'] );

echo "\n== A plan in use is deactivated, not deleted ==\n";
$r = TG_Subscription::delete_plan( $monthly );
ok( 'deleting a used plan is refused', is_wp_error( $r ) && 'tg_plan_in_use' === $r->get_error_code() );
ok( 'and it has been deactivated instead', 0 === (int) TG_Subscription::plan( $monthly )['is_active'] );
ok( 'the historic subscription still names it', 'TEST Monthly' === TG_Subscription::get( $s1 )['plan_name'] );

// Clean up.
foreach ( $users as $id ) {
	$wpdb->delete( TG_Store::subs_table(), array( 'supplier_id' => $id ) );
	wp_delete_user( $id );
}

foreach ( $plans as $id ) {
	$wpdb->delete( TG_Store::plans_table(), array( 'id' => $id ) );
}

if ( false === $restore_req ) {
	delete_option( 'tg_require_subscription' );
} else {
	update_option( 'tg_require_subscription', $restore_req );
}

if ( false === $restore_vat ) {
	delete_option( 'tg_vat_rate' );
} else {
	update_option( 'tg_vat_rate', $restore_vat );
}

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
