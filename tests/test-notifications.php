<?php
/**
 * Email notifications and the Brevo transport, against a real WordPress.
 *
 *   php tests/test-notifications.php /path/to/wordpress
 *
 * No real email is sent: messages are captured before the transport, and the
 * transport tests intercept the HTTP call to Brevo. The rules that matter:
 * every event reaches the right person, no supplier ever sees another
 * supplier's price, and a failed send is never reported as a success.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'TG_Notify' ) || ! class_exists( 'TG_Mailer' ) ) {
	fwrite( STDERR, "TG_Notify / TG_Mailer are not loaded.\n" );
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

// Keep every setting this suite touches, and put it back at the end.
$keep = array();
foreach ( array( 'tg_mail_api_key', 'tg_mail_from', 'tg_mail_from_name', 'tg_mail_reply_to', 'tg_mail_admin', 'tg_payment_instructions', 'tg_require_subscription', 'tg_mail_log' ) as $opt ) {
	$keep[ $opt ] = get_option( $opt, null );
}

update_option( 'tg_mail_admin', 'TEST-ops@example.invalid' );
update_option( 'tg_payment_instructions', 'TEST-BANK Bank Muscat account 0123' );
update_option( 'tg_require_subscription', 'yes' );
delete_option( 'tg_mail_api_key' );

/* ------------------------------------------------------------ Capture */

$outbox = array();
$capture = function ( $return, $atts ) use ( &$outbox ) {
	$outbox[] = array(
		'to'      => TG_Mailer::parse_recipients( $atts['to'] ),
		'subject' => (string) $atts['subject'],
		'body'    => (string) $atts['message'],
	);

	return true;
};
add_filter( 'pre_wp_mail', $capture, 5, 2 );

function mails_to( $email ) {
	global $outbox;

	return array_values( array_filter( $outbox, function ( $m ) use ( $email ) {
		return in_array( $email, $m['to'], true );
	} ) );
}

function sup( $slug, $verified = true ) {
	global $users;

	$id = wp_create_user( 'TEST-n-' . $slug, wp_generate_password( 20 ), "TEST-n-{$slug}@example.invalid" );
	( new WP_User( $id ) )->set_role( TG_Auth::ROLE );
	update_user_meta( $id, 'tg_company', 'TEST ' . ucfirst( $slug ) . ' LLC' );
	update_user_meta( $id, 'tg_contact', 'Contact ' . ucfirst( $slug ) );
	update_user_meta( $id, 'tg_sectors', array( 'TEST Sector' ) );
	update_user_meta( $id, 'tg_status', $verified ? 'verified' : 'pending' );
	// As at registration: a real supplier accepts the terms to sign up.
	if ( class_exists( 'TG_Terms' ) ) {
		TG_Terms::accept( $id, '127.0.0.1' );
	}
	$users[] = $id;

	return $id;
}

/* ---------------------------------------------------------- Suppliers */

echo "\n== Registration and verification ==\n";
$outbox = array();
$new    = sup( 'new', false );
do_action( 'tg_supplier_registered', $new );

$to_sup = mails_to( 'TEST-n-new@example.invalid' );
$to_ops = mails_to( 'TEST-ops@example.invalid' );
ok( 'the supplier is told their registration was received', 1 === count( $to_sup ) && false !== stripos( $to_sup[0]['subject'], 'Registration received' ) );
ok( 'and asked for their CR certificate', $to_sup && false !== stripos( $to_sup[0]['body'], 'Commercial Registration' ) );
ok( 'your team is alerted to verify them', 1 === count( $to_ops ) && false !== strpos( $to_ops[0]['subject'], 'TEST New LLC' ) );

$outbox = array();
do_action( 'tg_supplier_status_changed', $new, TG_Admin_Suppliers::VERIFIED );
$to_sup = mails_to( 'TEST-n-new@example.invalid' );
ok( 'verification is announced to the supplier', 1 === count( $to_sup ) && false !== stripos( $to_sup[0]['subject'], 'verified' ) );

$outbox = array();
do_action( 'tg_supplier_status_changed', $new, 'pending' );
ok( 'moving back to pending sends nothing', 0 === count( $outbox ) );

/* ------------------------------------------------------ Subscriptions */

echo "\n== Plan request and activation ==\n";
$plan    = TG_Subscription::save_plan( array( 'name' => 'TEST Notify Plan', 'price' => 20, 'currency' => 'OMR', 'period' => 'monthly', 'is_active' => 1 ) );
$plans[] = $plan;

$outbox = array();
TG_Subscription::request( $new, $plan );
$to_sup = mails_to( 'TEST-n-new@example.invalid' );
ok( 'the supplier gets the price with VAT and the total', $to_sup && false !== strpos( $to_sup[0]['body'], 'OMR 21.000' ) );
ok( 'and your bank details', $to_sup && false !== strpos( $to_sup[0]['body'], 'TEST-BANK' ) );
ok( 'your team is told a payment is coming', 1 === count( mails_to( 'TEST-ops@example.invalid' ) ) );

$outbox = array();
$sid    = TG_Subscription::start( $new, $plan, array( 'payment_ref' => 'TEST' ) );
$sub    = TG_Subscription::get( $sid );
$to_sup = mails_to( 'TEST-n-new@example.invalid' );
ok( 'activation is confirmed with the invoice number', $to_sup && false !== strpos( $to_sup[0]['subject'], $sub['invoice_no'] ) );
ok( 'and a link to the invoice', $to_sup && false !== strpos( $to_sup[0]['body'], 'tg_invoice=' . $sid ) );

/* --------------------------------------------------------------- Bids */

echo "\n== Bids are confirmed privately ==\n";
$cast = array( 'alpha' => 1001.111, 'beta' => 2002.222, 'gamma' => 3003.333 );
$ids  = array();
foreach ( $cast as $slug => $price ) {
	$ids[ $slug ] = sup( $slug );
	TG_Subscription::start( $ids[ $slug ], $plan );
}

$tid       = TG_Tender::create( array(
	'title'      => 'TEST notify fixture',
	'buyer'      => 'TEST Buyer',
	'sector'     => 'TEST Sector',
	'currency'   => 'OMR',
	'closing_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + 5 * DAY_IN_SECONDS ),
), 0 );
$tenders[] = $tid;
TG_Tender::move( $tid, TG_Status::PENDING );
TG_Tender::move( $tid, TG_Status::APPROVED );

$outbox = array();
$bid_ids = array();
foreach ( $cast as $slug => $price ) {
	$bid_ids[ $slug ] = TG_Bid::submit( $tid, $ids[ $slug ], array( 'price' => $price, 'currency' => 'OMR', 'delivery_days' => 7 ) );
}
ok( 'all three bids were accepted', 3 === count( array_filter( $bid_ids, 'is_int' ) ) );

$leak = false;
foreach ( $cast as $slug => $price ) {
	$mine = mails_to( "TEST-n-{$slug}@example.invalid" );

	ok( "{$slug} gets one confirmation with their own price", 1 === count( $mine ) && false !== strpos( $mine[0]['body'], number_format( $price, 3 ) ) );

	foreach ( $cast as $other => $other_price ) {
		if ( $other !== $slug && $mine && false !== strpos( $mine[0]['body'], number_format( $other_price, 3 ) ) ) {
			$leak = true;
		}
	}
}
ok( 'no confirmation contains another supplier\'s price', ! $leak );
ok( 'your team is not emailed each sealed bid', 0 === count( mails_to( 'TEST-ops@example.invalid' ) ) );

$outbox = array();
TG_Bid::withdraw( $bid_ids['gamma'], $ids['gamma'] );
$mine = mails_to( 'TEST-n-gamma@example.invalid' );
ok( 'a withdrawal is confirmed', 1 === count( $mine ) && false !== stripos( $mine[0]['subject'], 'withdrawn' ) );
TG_Bid::submit( $tid, $ids['gamma'], array( 'price' => $cast['gamma'], 'currency' => 'OMR' ) );

/* ------------------------------------------------------ Close + award */

echo "\n== Closing and the award ==\n";
$outbox = array();
TG_Tender::move( $tid, TG_Status::CLOSED );
$ops = mails_to( 'TEST-ops@example.invalid' );
ok( 'your team is told the tender closed, with the bid count', 1 === count( $ops ) && false !== strpos( $ops[0]['subject'], '3 bids' ) );

$outbox = array();
$fresh_beta = TG_Bid::find( $tid, $ids['beta'] );
$r = TG_Award::award( $tid, $fresh_beta['id'], 1, 'TEST reason: faster delivery' );
ok( 'the award went through', ! is_wp_error( $r ) );

$win = mails_to( 'TEST-n-beta@example.invalid' );
ok( 'the winner is told, with their awarded value', 1 === count( $win ) && false !== strpos( $win[0]['body'], number_format( $cast['beta'], 3 ) ) );

foreach ( array( 'alpha', 'gamma' ) as $slug ) {
	$lost = mails_to( "TEST-n-{$slug}@example.invalid" );
	ok( "{$slug} is told they were not selected", 1 === count( $lost ) && false !== stripos( $lost[0]['body'], 'not selected' ) );
	ok( "{$slug}'s result names neither the winner nor the winning price",
		$lost && false === strpos( $lost[0]['body'], 'TEST Beta' ) && false === strpos( $lost[0]['body'], number_format( $cast['beta'], 3 ) ) );
}

/* --------------------------------------------------------- Cancelled */

echo "\n== A cancelled tender ==\n";
$t2        = TG_Tender::create( array(
	'title' => 'TEST cancel fixture', 'sector' => 'TEST Sector', 'currency' => 'OMR',
	'closing_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + 5 * DAY_IN_SECONDS ),
), 0 );
$tenders[] = $t2;
TG_Tender::move( $t2, TG_Status::PENDING );
TG_Tender::move( $t2, TG_Status::APPROVED );
TG_Bid::submit( $t2, $ids['alpha'], array( 'price' => 50, 'currency' => 'OMR' ) );

$outbox = array();
TG_Tender::move( $t2, TG_Status::CANCELLED, 0, 'TEST budget withdrawn' );
$mine = mails_to( 'TEST-n-alpha@example.invalid' );
ok( 'the bidder is told the tender was cancelled', 1 === count( $mine ) && false !== stripos( $mine[0]['subject'], 'cancelled' ) );
ok( 'with the reason given', $mine && false !== strpos( $mine[0]['body'], 'TEST budget withdrawn' ) );
ok( 'and nobody who did not bid is emailed', 0 === count( mails_to( 'TEST-n-beta@example.invalid' ) ) );

remove_filter( 'pre_wp_mail', $capture, 5 );

/* ---------------------------------------------------------- Transport */

echo "\n== The Brevo transport ==\n";

ok( 'parses "Name <addr>" and plain addresses',
	array( 'a@example.com', 'b@example.com' ) === TG_Mailer::parse_recipients( 'A Person <a@example.com>, b@example.com, not-an-address' ) );

update_option( 'tg_mail_admin', '' );
ok( 'admin alerts fall back to the WordPress admin address', array( get_option( 'admin_email' ) ) === TG_Mailer::admin_recipients() || ! is_email( get_option( 'admin_email' ) ) );
update_option( 'tg_mail_admin', 'TEST-ops@example.invalid' );

ok( 'with no key it is not "configured"', ! TG_Mailer::configured() );

$secret = 'xkeysib-TESTKEY-' . wp_generate_password( 12, false );
update_option( 'tg_mail_api_key', $secret );
update_option( 'tg_mail_from', 'noreply@example.invalid' );
update_option( 'tg_mail_from_name', 'TEST Sender' );
update_option( 'tg_mail_reply_to', 'replies@example.invalid' );
ok( 'with a key and a sender it is configured', TG_Mailer::configured() );

$seen   = array();
$answer = array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'body' => '{"messageId":"<test>"}', 'headers' => array(), 'cookies' => array() );
$http   = function ( $pre, $args, $url ) use ( &$seen, &$answer ) {
	if ( false === strpos( $url, 'api.brevo.com' ) ) {
		return $pre;
	}
	$seen[] = array( 'url' => $url, 'args' => $args );

	return $answer;
};
add_filter( 'pre_http_request', $http, 10, 3 );

$ok   = wp_mail( 'Someone <to@example.invalid>', 'TEST subject', "TEST body\nline two" );
$sent = $seen ? json_decode( $seen[0]['args']['body'], true ) : array();
ok( 'an accepted message makes wp_mail() return true', true === $ok );
ok( 'it went to the Brevo send endpoint', $seen && TG_Mailer::ENDPOINT === $seen[0]['url'] );
ok( 'authenticated with the key in a header, not the body',
	$seen && $secret === $seen[0]['args']['headers']['api-key'] && false === strpos( $seen[0]['args']['body'], $secret ) );
ok( 'with the sender, recipient, subject and text',
	isset( $sent['sender']['email'], $sent['to'][0]['email'], $sent['textContent'] )
	&& 'noreply@example.invalid' === $sent['sender']['email']
	&& 'to@example.invalid' === $sent['to'][0]['email']
	&& 'TEST subject' === $sent['subject'] );
ok( 'and replies routed to your inbox', isset( $sent['replyTo']['email'] ) && 'replies@example.invalid' === $sent['replyTo']['email'] );
$log = TG_Mailer::log_entries();
ok( 'the log records it as sent', $log && 1 === (int) $log[0]['ok'] );

$answer = array( 'response' => array( 'code' => 401, 'message' => 'Unauthorized' ), 'body' => '{"code":"unauthorized","message":"Key not found"}', 'headers' => array(), 'cookies' => array() );
$ok     = wp_mail( 'to@example.invalid', 'TEST rejected', 'body' );
$log    = TG_Mailer::log_entries();
ok( 'a rejected message makes wp_mail() return FALSE', false === $ok );
ok( 'and the log says why, in Brevo\'s words', $log && 0 === (int) $log[0]['ok'] && false !== strpos( $log[0]['error'], 'Key not found' ) );

$answer = new WP_Error( 'http_request_failed', 'TEST connection timed out' );
$ok     = wp_mail( 'to@example.invalid', 'TEST unreachable', 'body' );
$log    = TG_Mailer::log_entries();
ok( 'an unreachable service makes wp_mail() return FALSE', false === $ok );
ok( 'and is logged as unreachable', $log && false !== strpos( $log[0]['error'], 'Could not reach' ) );

// Another filter earlier in the chain (a test harness, or another plugin
// deciding a message must not go) has already answered. The transport must
// not send it anyway.
$answer = array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'body' => '{}', 'headers' => array(), 'cookies' => array() );
$calls  = count( $seen );
$veto   = function () { return false; };
add_filter( 'pre_wp_mail', $veto, 5 );
$ok = wp_mail( 'to@example.invalid', 'TEST vetoed', 'body' );
remove_filter( 'pre_wp_mail', $veto, 5 );
ok( 'an earlier "do not send" is respected', false === $ok && count( $seen ) === $calls );

$in_log = false;
foreach ( TG_Mailer::log_entries() as $row ) {
	if ( false !== strpos( wp_json_encode( $row ), $secret ) ) {
		$in_log = true;
	}
}
ok( 'the API key never appears in the delivery log', ! $in_log );

echo "\n== Renewal reminders ride on the same truth ==\n";
$answer = array( 'response' => array( 'code' => 201, 'message' => 'Created' ), 'body' => '{}', 'headers' => array(), 'cookies' => array() );
$rem    = sup( 'rem' );
$rs     = TG_Subscription::start( $rem, $plan );
$wpdb->update( TG_Store::subs_table(), array( 'ends_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + 6 * DAY_IN_SECONDS ), 'reminders_sent' => '', 'reminder_tried_at' => null ), array( 'id' => $rs ) );
TG_Subscription::run_reminders();
ok( 'when Brevo accepts the reminder it is recorded as sent', in_array( 'd7', TG_Subscription::reminders_sent( TG_Subscription::get( $rs ) ), true ) );

$answer = array( 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'body' => '{"message":"TEST sender not verified"}', 'headers' => array(), 'cookies' => array() );
$rs2    = TG_Subscription::start( sup( 'rem2' ), $plan );
$wpdb->update( TG_Store::subs_table(), array( 'ends_at' => date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) ) + 6 * DAY_IN_SECONDS ), 'reminders_sent' => '', 'reminder_tried_at' => null ), array( 'id' => $rs2 ) );
TG_Subscription::run_reminders();
ok( 'when Brevo rejects it, it is NOT recorded as sent', '' === trim( (string) TG_Subscription::get( $rs2 )['reminders_sent'] ) );

remove_filter( 'pre_http_request', $http, 10 );

/* ------------------------------------------------------------ Cleanup */

foreach ( $tenders as $id ) {
	$wpdb->delete( TG_Store::bids_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::events_table(), array( 'tender_id' => $id ) );
	$wpdb->delete( TG_Store::table(), array( 'id' => $id ) );
}
foreach ( $users as $id ) {
	$wpdb->delete( TG_Store::subs_table(), array( 'supplier_id' => $id ) );
	$wpdb->delete( TG_Store::sub_requests_table(), array( 'supplier_id' => $id ) );
	wp_delete_user( $id );
}
foreach ( $plans as $id ) {
	$wpdb->delete( TG_Store::plans_table(), array( 'id' => $id ) );
}
foreach ( $keep as $opt => $value ) {
	if ( null === $value ) {
		delete_option( $opt );
	} else {
		update_option( $opt, $value );
	}
}

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
