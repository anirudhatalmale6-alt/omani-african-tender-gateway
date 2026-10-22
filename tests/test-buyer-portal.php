<?php
/**
 * The buyer portal, against a real WordPress.
 *
 *   php tests/test-buyer-portal.php /path/to/wordpress
 *
 * What must hold: a buyer only ever touches their own tenders, publishes only
 * in their own organisation's name, edits only drafts, cannot submit an
 * incomplete tender, and never reaches the website without the platform
 * team's approval. Emails go to the right people.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'TG_Buyer' ) ) {
	fwrite( STDERR, "TG_Buyer is not loaded.\n" );
	exit( 2 );
}

global $wpdb;

$pass = 0;
$fail = 0;
$users = array();

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

function code_of( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : ( is_string( $r ) ? $r : 'ok' );
}

register_shutdown_function( function () {
	global $wpdb, $users;

	foreach ( (array) $users as $id ) {
		foreach ( TG_Buyer::tenders_for( $id ) as $t ) {
			$wpdb->delete( TG_Store::events_table(), array( 'tender_id' => $t['id'] ) );
			$wpdb->delete( TG_Store::bids_table(), array( 'tender_id' => $t['id'] ) );
			$wpdb->delete( TG_Store::docs_table(), array( 'owner_type' => 'tender', 'owner_id' => $t['id'] ) );
			$wpdb->delete( TG_Store::table(), array( 'id' => $t['id'] ) );
		}
		wp_delete_user( $id );
	}
} );

// Leftovers from an earlier crashed run.
foreach ( get_users( array( 'search' => '*@buyer-test.invalid', 'search_columns' => array( 'user_email' ) ) ) as $old ) {
	wp_delete_user( $old->ID );
}

$outbox  = array();
$capture = function ( $return, $atts ) use ( &$outbox ) {
	$outbox[] = array( 'to' => TG_Mailer::parse_recipients( $atts['to'] ), 'subject' => (string) $atts['subject'], 'body' => (string) $atts['message'] );

	return true;
};
add_filter( 'pre_wp_mail', $capture, 5, 2 );

$keep_admin = get_option( 'tg_mail_admin', null );
update_option( 'tg_mail_admin', 'TEST-ops@buyer-test.invalid' );

function mail_with( $needle ) {
	global $outbox;

	return array_values( array_filter( $outbox, function ( $m ) use ( $needle ) {
		return false !== stripos( $m['subject'], $needle );
	} ) );
}

$good = array(
	'title'          => 'TEST Supply of office chairs',
	'summary'        => 'Two hundred ergonomic office chairs.',
	'specifications' => 'TESTSPEC-' . wp_generate_password( 6, false ) . ' Adjustable height, lumbar support.',
	'sector'         => 'TEST Sector',
	'country'        => 'Oman',
	'buyer'          => 'Someone Else Entirely',
	'closing_at'     => date( 'Y-m-d\TH:i', strtotime( current_time( 'mysql' ) ) + 5 * DAY_IN_SECONDS ),
);

/* ------------------------------------------------------------ Setup */

echo "\n== Upgrading creates the role and the portal page ==\n";
delete_option( 'tg_setup_version' );
TG_Install::upgrade();
ok( 'the Buyer role exists', null !== get_role( TG_Buyer::ROLE ) );
$pages = get_option( 'tg_pages', array() );
ok( 'the Buyer Portal page exists', ! empty( $pages['buyer'] ) && 'page' === get_post_type( $pages['buyer'] ) );
ok( 'and holds the portal shortcode', ! empty( $pages['buyer'] ) && false !== strpos( get_post_field( 'post_content', $pages['buyer'] ), '[tg_buyer_portal]' ) );
ok( 'it only runs once per release', TG_VERSION === get_option( 'tg_setup_version' ) );

echo "\n== Accounts ==\n";
$a = TG_Buyer::create_account( 'TEST Ministry A', 'Contact A', 'a@buyer-test.invalid', '+968 1', false );
$b = TG_Buyer::create_account( 'TEST Ministry B', 'Contact B', 'b@buyer-test.invalid', '', false );
$d = TG_Buyer::create_account( 'TEST Third Org', 'Contact D', 'd@buyer-test.invalid', '' );
$users = array( $a, $b, $d );
ok( 'buyer accounts are created', is_int( $a ) && is_int( $b ) && is_int( $d ) );
ok( 'with the buyer role, not supplier', TG_Buyer::is_buyer( $a ) && ! TG_Access::is_supplier( $a ) );
ok( 'a second account for the same email is refused', 'tg_buyer_exists' === code_of( TG_Buyer::create_account( 'X', 'Y', 'a@buyer-test.invalid' ) ) );
ok( 'an account needs an organisation', 'tg_buyer_fields' === code_of( TG_Buyer::create_account( '', 'Y', 'z@buyer-test.invalid' ) ) );
ok( 'the set-password link is a one-time reset link', false !== strpos( TG_Buyer::set_password_url( $a ), 'action=rp&key=' ) );

$outbox = array();
ok( 'the invitation email is sent', true === apply_filters( 'tg_buyer_send_invite', false, $a, TG_Buyer::set_password_url( $a ) ) );
ok( 'to the buyer, with the link', $outbox && in_array( 'a@buyer-test.invalid', $outbox[0]['to'], true ) && false !== strpos( $outbox[0]['body'], 'action=rp' ) );

/* ------------------------------------------------------------ Drafts */

echo "\n== Drafts belong to their buyer ==\n";
$t = TG_Buyer::save_tender( $a, $good );
$row = TG_Tender::get( $t );
ok( 'a buyer can create a draft', is_int( $t ) && TG_Status::DRAFT === $row['status'] );
ok( 'owned by that buyer', (int) $row['owner_id'] === $a );
ok( 'in their own organisation\'s name, whatever the form said', 'TEST Ministry A' === $row['buyer'] );
ok( 'a draft is not on the public site', ! isset( TG_Tender::public_records()[ $row['external_id'] ] ) );

ok( 'another buyer cannot change it', 'tg_buyer_not_owner' === code_of( TG_Buyer::save_tender( $b, $good, $t ) ) );
ok( 'or submit it', 'tg_buyer_not_owner' === code_of( TG_Buyer::submit_tender( $b, $t ) ) );
ok( 'or see it in their list', 0 === count( array_filter( TG_Buyer::tenders_for( $b ), function ( $x ) use ( $t ) { return (int) $x['id'] === (int) $t; } ) ) );
ok( 'the owner sees it in theirs', 1 === count( array_filter( TG_Buyer::tenders_for( $a ), function ( $x ) use ( $t ) { return (int) $x['id'] === (int) $t; } ) ) );

$supplier = wp_create_user( 'TEST-bp-supplier', wp_generate_password( 20 ), 'supplier@buyer-test.invalid' );
( new WP_User( $supplier ) )->set_role( TG_Auth::ROLE );
$users[] = $supplier;
ok( 'a supplier cannot use the buyer portal', 'tg_buyer_only' === code_of( TG_Buyer::save_tender( $supplier, $good ) ) );

/* ---------------------------------------------------------- Submitting */

echo "\n== Submitting checks the tender is complete ==\n";
$thin = TG_Buyer::save_tender( $a, array( 'title' => 'TEST thin draft' ) );
$r    = TG_Buyer::submit_tender( $a, $thin );
ok( 'an incomplete draft is refused', 'tg_buyer_incomplete' === code_of( $r ) );
ok( 'and the message lists what is missing', is_wp_error( $r ) && false !== strpos( $r->get_error_message(), 'specifications' ) && false !== strpos( $r->get_error_message(), 'closing date' ) );
$past = TG_Buyer::save_tender( $a, array_merge( $good, array( 'closing_at' => date( 'Y-m-d\TH:i', strtotime( current_time( 'mysql' ) ) - DAY_IN_SECONDS ) ) ) );
ok( 'a closing date in the past is refused', 'tg_buyer_incomplete' === code_of( TG_Buyer::submit_tender( $a, $past ) ) );
$inverted = TG_Buyer::save_tender( $a, array_merge( $good, array( 'opening_at' => date( 'Y-m-d\TH:i', strtotime( current_time( 'mysql' ) ) + 6 * DAY_IN_SECONDS ) ) ) );
ok( 'opening after closing is refused', 'tg_buyer_incomplete' === code_of( TG_Buyer::submit_tender( $a, $inverted ) ) );

echo "\n== Normal path: submit, then the team approves ==\n";
$outbox = array();
ok( 'a complete draft is submitted for approval', 'pending' === code_of( TG_Buyer::submit_tender( $a, $t ) ) );
ok( 'it is now Pending Approval', TG_Status::PENDING === TG_Tender::get( $t )['status'] );
$appr = mail_with( 'submitted for approval' );
ok( 'the team is emailed to approve it', 1 === count( $appr ) && in_array( 'TEST-ops@buyer-test.invalid', $appr[0]['to'], true ) );
ok( 'the buyer can no longer edit it', 'tg_buyer_locked' === code_of( TG_Buyer::save_tender( $a, $good, $t ) ) );
ok( 'or submit it twice', 'tg_buyer_locked' === code_of( TG_Buyer::submit_tender( $a, $t ) ) );

$outbox = array();
TG_Tender::move( $t, TG_Status::APPROVED, 1 );
ok( 'approved without an opening time, it opens at once', TG_Status::OPEN === TG_Tender::get( $t )['status'] );
$live = mail_with( 'is live' );
ok( 'the buyer is told it is live', 1 === count( $live ) && in_array( 'a@buyer-test.invalid', $live[0]['to'], true ) );
ok( 'it is now on the public site', isset( TG_Tender::public_records()[ TG_Tender::get( $t )['external_id'] ] ) );

echo "\n== Nothing a buyer does gets past approval ==\n";
$outbox = array();
$sneak  = TG_Buyer::save_tender( $d, array_merge( $good, array( 'status' => TG_Status::OPEN, 'owner_id' => $a ) ) );
$srow   = TG_Tender::get( $sneak );
ok( 'a status in the form is ignored - it is a Draft', TG_Status::DRAFT === $srow['status'] );
ok( 'an owner in the form is ignored', (int) $srow['owner_id'] === $d );
update_user_meta( $d, 'tg_buyer_direct', 'yes' );
ok( 'submitting only ever asks for approval', 'pending' === code_of( TG_Buyer::submit_tender( $d, $sneak ) ) );
ok( 'it is Pending Approval, not Open', TG_Status::PENDING === TG_Tender::get( $sneak )['status'] );
ok( 'and it is not on the public site', ! isset( TG_Tender::public_records()[ $srow['external_id'] ] ) );
ok( 'suppliers cannot see it in a listing either', 0 === count( array_filter( TG_Tender::query( array( 'statuses' => TG_Status::supplier_visible(), 'per_page' => 500 ) )['rows'], function ( $x ) use ( $sneak ) { return (int) $x['id'] === (int) $sneak; } ) ) );
ok( 'the team is asked to approve it', 1 === count( mail_with( 'submitted for approval' ) ) );
ok( 'the buyer is NOT told it is live', 0 === count( mail_with( 'is live' ) ) );
$dt = $sneak;
TG_Tender::move( $dt, TG_Status::APPROVED, 1 );

echo "\n== Changes Required: the team sends it back with comments ==\n";
$ct = TG_Buyer::save_tender( $b, $good );
TG_Buyer::submit_tender( $b, $ct );
ok( 'asking for changes needs comments', 'tg_status_reason' === code_of( TG_Tender::move( $ct, TG_Status::CHANGES_REQUIRED, 1, '' ) ) );
$outbox = array();
TG_Tender::move( $ct, TG_Status::CHANGES_REQUIRED, 1, 'TEST please attach the bill of quantities' );
ok( 'the tender is now Changes Required', TG_Status::CHANGES_REQUIRED === TG_Tender::get( $ct )['status'] );
$chg = mail_with( 'Changes needed' );
ok( 'the buyer is emailed the comments', 1 === count( $chg ) && in_array( 'b@buyer-test.invalid', $chg[0]['to'], true ) && false !== strpos( $chg[0]['body'], 'TEST please attach the bill of quantities' ) );
ok( 'with a link straight to editing it', 1 === count( $chg ) && false !== strpos( $chg[0]['body'], 'edit=' . $ct ) );
ok( 'it is still not on the public site', ! isset( TG_Tender::public_records()[ TG_Tender::get( $ct )['external_id'] ] ) );
ok( 'another buyer still cannot touch it', 'tg_buyer_not_owner' === code_of( TG_Buyer::save_tender( $a, $good, $ct ) ) );
ok( 'the owner can edit it again', $ct === TG_Buyer::save_tender( $b, array_merge( $good, array( 'title' => 'TEST chairs, with BoQ' ) ), $ct ) );
ok( 'saving keeps it in Changes Required (not silently resubmitted)', TG_Status::CHANGES_REQUIRED === TG_Tender::get( $ct )['status'] );
$outbox = array();
ok( 'resubmitting sends it back for approval', 'pending' === code_of( TG_Buyer::submit_tender( $b, $ct ) ) );
ok( 'it is Pending Approval again', TG_Status::PENDING === TG_Tender::get( $ct )['status'] );
ok( 'the team is told it was resubmitted after changes', 1 === count( mail_with( 'resubmitted after changes' ) ) );
ok( 'and it is locked again', 'tg_buyer_locked' === code_of( TG_Buyer::save_tender( $b, $good, $ct ) ) );
ok( 'it still needs approval to go live', ! isset( TG_Tender::public_records()[ TG_Tender::get( $ct )['external_id'] ] ) );

echo "\n== Rejection ==\n";
$rt = TG_Buyer::save_tender( $b, $good );
TG_Buyer::submit_tender( $b, $rt );
$outbox = array();
TG_Tender::move( $rt, TG_Status::REJECTED, 1, 'TEST duplicate of an earlier tender' );
$rej = mail_with( 'not approved' );
ok( 'the buyer is told, with the reason', 1 === count( $rej ) && false !== strpos( $rej[0]['body'], 'TEST duplicate of an earlier tender' ) );

/* ----------------------------------------------------------- Boundaries */

echo "\n== What a buyer can and cannot do elsewhere ==\n";
$open_row = TG_Tender::get( $dt );
$bid      = TG_Access::can_bid( $open_row, $a );
ok( 'a buyer cannot bid on tenders', is_wp_error( $bid ) && 'tg_bid_not_supplier' === $bid->get_error_code() );

$spec = $good['specifications'];
wp_set_current_user( $a );
$_GET['tender'] = TG_Tender::get( $t )['external_id'];
ok( 'the owner sees their own tender in full on the site', false !== strpos( (string) TG_Shortcodes::tenders( array() ), $spec ) );
wp_set_current_user( $b );
ok( 'another buyer sees the locked view', false === strpos( (string) TG_Shortcodes::tenders( array() ), $spec ) );
unset( $_GET['tender'] );
wp_set_current_user( 0 );

$doc = array( 'id' => 0, 'owner_type' => 'tender', 'owner_id' => $t, 'uploaded_by' => $a, 'token' => 'x', 'path' => '', 'filename' => 'x.pdf', 'mime' => 'application/pdf' );
ok( 'the owner can open their tender\'s documents', TG_Docs::check_access( $doc, $a ) );

$bounced = function ( $user ) {
	$keep = $_SERVER;
	$_SERVER['SCRIPT_NAME'] = '/wp-admin/index.php';
	wp_set_current_user( $user );
	$to   = '';
	$trap = function ( $location ) use ( &$to ) {
		$to = $location;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $trap, 1 );
	try {
		TG_Auth::block_admin();
	} catch ( RuntimeException $e ) {
		// expected
	}
	remove_filter( 'wp_redirect', $trap, 1 );
	wp_set_current_user( 0 );
	$_SERVER = $keep;

	return $to;
};
$to = $bounced( $a );
ok( 'a buyer opening wp-admin is sent to the buyer portal', '' !== $to && 0 === strpos( $to, TG_Buyer::portal_url() ) );

/* ------------------------------------------------------------- Done */

remove_filter( 'pre_wp_mail', $capture, 5 );
if ( null === $keep_admin ) {
	delete_option( 'tg_mail_admin' );
} else {
	update_option( 'tg_mail_admin', $keep_admin );
}

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
