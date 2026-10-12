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
	// As at registration: a real supplier accepts the terms to sign up.
	if ( class_exists( 'TG_Terms' ) ) {
		TG_Terms::accept( $id, '127.0.0.1' );
	}
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

echo "\n== Expiry is judged in the site's own timezone ==\n";

// Every datetime in these tables is site-local. If "now" is read as a real UTC
// timestamp instead, a site four hours ahead of UTC hands every supplier four
// extra hours of paid access - and the SQL that marks rows expired disagrees
// with the PHP that grants access. This is the regression that catches it.
$restore_tz_s = get_option( 'timezone_string', false );
$restore_tz_o = get_option( 'gmt_offset', false );
update_option( 'timezone_string', 'Asia/Muscat' );
update_option( 'gmt_offset', 4 );

$offset_hours = (int) round( ( strtotime( current_time( 'mysql' ) ) - time() ) / HOUR_IN_SECONDS );
ok( 'the test site clock really does read four hours ahead of UTC, got ' . $offset_hours, 4 === $offset_hours );

$tz  = sup( 'tz' );
$s8  = TG_Subscription::start( $tz, $annual, array() );
$local_now = strtotime( current_time( 'mysql' ) );

$wpdb->update(
	TG_Store::subs_table(),
	array( 'ends_at' => date( 'Y-m-d H:i:s', $local_now - 2 * HOUR_IN_SECONDS ) ),
	array( 'id' => $s8 )
);
ok( 'a period that ended two local hours ago is not active', ! TG_Subscription::is_active( $tz ) );

TG_Subscription::expire_due();
ok( 'and the sweeper agrees it has expired', TG_Subscription::EXPIRED === TG_Subscription::get( $s8 )['status'] );

$wpdb->update(
	TG_Store::subs_table(),
	array( 'status' => TG_Subscription::ACTIVE, 'ends_at' => date( 'Y-m-d H:i:s', $local_now + 2 * HOUR_IN_SECONDS ) ),
	array( 'id' => $s8 )
);
ok( 'a period with two local hours left is still active', TG_Subscription::is_active( $tz ) );

TG_Subscription::expire_due();
ok( 'and the sweeper leaves it alone', TG_Subscription::ACTIVE === TG_Subscription::get( $s8 )['status'] );

if ( false === $restore_tz_s ) {
	delete_option( 'timezone_string' );
} else {
	update_option( 'timezone_string', $restore_tz_s );
}

if ( false === $restore_tz_o ) {
	delete_option( 'gmt_offset' );
} else {
	update_option( 'gmt_offset', $restore_tz_o );
}

echo "\n== Days left ==\n";

/**
 * A subscription row as the reminder engine sees it. Built from current_time()
 * so it lands in the same frame as everything in the tables.
 */
function mk_sub( $days, $sent = '', $status = 'active' ) {
	$base = strtotime( current_time( 'mysql' ) );

	return array(
		'id'             => 0,
		'supplier_id'    => 0,
		'plan_name'      => 'TEST',
		'invoice_no'     => 'TEST-0',
		'status'         => $status,
		'starts_at'      => current_time( 'mysql' ),
		'ends_at'        => date( 'Y-m-d H:i:s', $base + (int) ( $days * DAY_IN_SECONDS ) ),
		'reminders_sent' => $sent,
	);
}

ok( '14 days out reads as 14', 14 === TG_Subscription::days_left( mk_sub( 14 ) ) );
ok( 'tomorrow reads as 1', 1 === TG_Subscription::days_left( mk_sub( 1 ) ) );
ok( 'yesterday reads as negative', TG_Subscription::days_left( mk_sub( -1 ) ) < 0 );
ok( 'two hours before the end still reads as a day left, not none', 1 === TG_Subscription::days_left( mk_sub( 2 / 24 ) ) );
ok( 'two hours after the end reads as lapsed', TG_Subscription::days_left( mk_sub( -2 / 24 ) ) < 0 );
ok( 'a row with no end date reads as unknown, not zero', null === TG_Subscription::days_left( array( 'ends_at' => '' ) ) );

echo "\n== Which renewal warning is due ==\n";
ok( '20 days out: nothing yet', '' === TG_Subscription::reminder_due( mk_sub( 20 ) ) );
ok( '14 days out: the 14-day notice', 'd14' === TG_Subscription::reminder_due( mk_sub( 14 ) ) );
ok( '10 days out, 14 already sent: nothing', '' === TG_Subscription::reminder_due( mk_sub( 10, 'd14' ) ) );
ok( '7 days out, 14 already sent: the 7-day notice', 'd7' === TG_Subscription::reminder_due( mk_sub( 7, 'd14' ) ) );
ok( '1 day out, 14 and 7 sent: the 1-day notice', 'd1' === TG_Subscription::reminder_due( mk_sub( 1, 'd14,d7' ) ) );
ok( 'lapsed, all three sent: the lapse notice', 'lapsed' === TG_Subscription::reminder_due( mk_sub( -1, 'd14,d7,d1' ) ) );
ok( 'lapsed and already told: nothing', '' === TG_Subscription::reminder_due( mk_sub( -1, 'd14,d7,d1,lapsed' ) ) );

// A site whose cron has been asleep must not work through a fortnight of
// stale warnings - it should say the one thing that is still true.
ok( 'cron asleep for a fortnight: sends the lapse notice, not the 14-day one', 'lapsed' === TG_Subscription::reminder_due( mk_sub( -3 ) ) );
ok( 'cron asleep for a week: sends the 1-day notice, not the 14-day one', 'd1' === TG_Subscription::reminder_due( mk_sub( 1 ) ) );

// Cancelling is the supplier asking us to stop. Nagging them afterwards is
// the one thing they explicitly did not want.
ok( 'a cancelled period is never chased', '' === TG_Subscription::reminder_due( mk_sub( -1, '', TG_Subscription::CANCELLED ) ) );
ok( 'nor while it runs out its paid days', '' === TG_Subscription::reminder_due( mk_sub( 3, '', TG_Subscription::CANCELLED ) ) );

ok( 'milestones that have been sent are read back', array( 'd14', 'd7' ) === array_values( TG_Subscription::reminders_sent( mk_sub( 7, 'd14,d7' ) ) ) );

// Sold with six days to run, so the 14-day notice never applied and must not
// arrive after the 7-day one has already gone out.
ok( 'a short period never gets a 14-day notice after its 7-day one', '' === TG_Subscription::reminder_due( mk_sub( 6, 'd7' ) ) );
ok( 'nor a 7-day notice after its 1-day one', '' === TG_Subscription::reminder_due( mk_sub( 0.5, 'd1' ) ) );
ok( 'one band applies at a time: 6 days is the 7-day band', 'd7' === TG_Subscription::reminder_band( 6 ) );
ok( 'and 8 days is the 14-day band', 'd14' === TG_Subscription::reminder_band( 8 ) );

echo "\n== A reminder is only recorded as sent if it was actually sent ==\n";

// The live host has outgoing mail disabled. Marking the milestone sent anyway
// would swallow every reminder the platform owed a supplier, silently.
$rem        = sup( 'rem' );
$s9         = TG_Subscription::start( $rem, $monthly, array() );
ok( 'the delivery log is capped so it cannot grow without bound', count( TG_Subscription::reminder_log() ) <= 60 );

$wpdb->update(
	TG_Store::subs_table(),
	array( 'ends_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + 6 * DAY_IN_SECONDS ) ),
	array( 'id' => $s9 )
);

$mail_fails = function () { return false; };
add_filter( 'pre_wp_mail', $mail_fails, 99 );
TG_Subscription::run_reminders();
remove_filter( 'pre_wp_mail', $mail_fails, 99 );

$row = TG_Subscription::get( $s9 );
ok( 'a send that failed is not recorded as sent', '' === trim( (string) $row['reminders_sent'] ) );
ok( 'but the attempt is stamped', ! empty( $row['reminder_tried_at'] ) );
ok( 'the warning is still shown as due', 'd7' === TG_Subscription::reminder_due( $row ) );

function log_for( $sub_id ) {
	$mine = array();

	foreach ( TG_Subscription::reminder_log() as $entry ) {
		if ( (int) $entry['sub'] === (int) $sub_id ) {
			$mine[] = $entry;
		}
	}

	return $mine;
}

$mine = log_for( $s9 );
ok( 'and the failure is logged as a failure', 1 === count( $mine ) && 0 === (int) $mine[0]['ok'] );

// Five-minute cron must not retry a dead mail server twelve times an hour.
add_filter( 'pre_wp_mail', $mail_fails, 99 );
TG_Subscription::run_reminders();
remove_filter( 'pre_wp_mail', $mail_fails, 99 );
ok( 'an immediate re-run does not try again', 1 === count( log_for( $s9 ) ) );

// A day later, with mail working.
$wpdb->update(
	TG_Store::subs_table(),
	array( 'reminder_tried_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) - 2 * DAY_IN_SECONDS ) ),
	array( 'id' => $s9 )
);

$mail_works = function () { return true; };
add_filter( 'pre_wp_mail', $mail_works, 99 );
TG_Subscription::run_reminders();
remove_filter( 'pre_wp_mail', $mail_works, 99 );

$row = TG_Subscription::get( $s9 );
ok( 'once it sends, the milestone is recorded', in_array( 'd7', TG_Subscription::reminders_sent( $row ), true ) );
ok( 'and it is no longer due', '' === TG_Subscription::reminder_due( $row ) );

$count_after = count( log_for( $s9 ) );

add_filter( 'pre_wp_mail', $mail_works, 99 );
TG_Subscription::run_reminders();
remove_filter( 'pre_wp_mail', $mail_works, 99 );
ok( 'the same warning is never sent twice', count( log_for( $s9 ) ) === $count_after );

echo "\n== A supplier asking for a plan ==\n";
$req_sup = sup( 'req' );
$reqplan = TG_Subscription::save_plan( array(
	'name' => 'TEST Request', 'price' => 30, 'currency' => 'OMR', 'period' => 'monthly', 'is_active' => 1,
) );
$plans[] = $reqplan;

$r1 = TG_Subscription::request( $req_sup, $reqplan );
ok( 'a request is recorded', is_int( $r1 ) && $r1 > 0 );
ok( 'and found as pending', TG_Subscription::pending_request( $req_sup ) );
ok( 'requesting does NOT grant access', ! TG_Subscription::is_active( $req_sup ) );

$r2 = TG_Subscription::request( $req_sup, $annual );
ok( 'a second request is refused while one waits', is_wp_error( $r2 ) && 'tg_req_duplicate' === $r2->get_error_code() );

$r3 = TG_Subscription::request( $req_sup, 999999 );
ok( 'a request for a plan that does not exist is refused', is_wp_error( $r3 ) );

$r4 = TG_Subscription::request( 1, $reqplan );
ok( 'only a supplier can request a plan', is_wp_error( $r4 ) && 'tg_req_no_supplier' === $r4->get_error_code() );

$s10 = TG_Subscription::start( $req_sup, $reqplan, array( 'payment_ref' => 'TEST-REF' ) );
ok( 'recording the payment grants access', TG_Subscription::is_active( $req_sup ) );
ok( 'and closes the request automatically', ! TG_Subscription::pending_request( $req_sup ) );

global $wpdb;
$closed = $wpdb->get_row( $wpdb->prepare(
	"SELECT * FROM " . TG_Store::sub_requests_table() . " WHERE id = %d",
	$r1
), ARRAY_A );
ok( 'the request is marked fulfilled, not deleted', $closed && TG_Subscription::FULFILLED === $closed['status'] );
ok( 'and points at the subscription that answered it', $closed && (int) $closed['subscription_id'] === (int) $s10 );
ok( 'with the price it was asked for at the time', $closed && abs( (float) $closed['price'] - 30.0 ) < 0.0005 );

// An inactive plan is not on offer, so it cannot be requested either.
TG_Subscription::save_plan( array( 'name' => 'TEST Request', 'price' => 30, 'period' => 'monthly', 'is_active' => 0 ), $reqplan );
$r5 = TG_Subscription::request( sup( 'req2' ), $reqplan );
ok( 'a withdrawn plan cannot be requested', is_wp_error( $r5 ) && 'tg_req_no_plan' === $r5->get_error_code() );

$again = TG_Subscription::close_request( $r1, TG_Subscription::DECLINED );
ok( 'a closed request cannot be closed again', is_wp_error( $again ) && 'tg_req_closed' === $again->get_error_code() );

// Clean up.
foreach ( $users as $id ) {
	$wpdb->delete( TG_Store::sub_requests_table(), array( 'supplier_id' => $id ) );
}

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
