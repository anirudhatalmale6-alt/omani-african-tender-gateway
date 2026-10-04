<?php
/**
 * Integration checks for the tender lifecycle, run against a real WordPress.
 *
 *   php tests/test-lifecycle-integration.php /path/to/wordpress
 *
 * These cover behaviour the standalone status test cannot reach, because it
 * involves the database and the model rather than the transition table alone.
 *
 * Creates tenders prefixed TEST- and deletes them again at the end.
 */

$root = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : dirname( __DIR__ );

if ( ! file_exists( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Could not find wp-load.php in {$root}\nUsage: php tests/test-lifecycle-integration.php /path/to/wordpress\n" );
	exit( 2 );
}

require_once $root . '/wp-load.php';

if ( ! class_exists( 'TG_Tender' ) ) {
	fwrite( STDERR, "The Tender Gateway plugin is not active.\n" );
	exit( 2 );
}

global $wpdb;

$pass = 0;
$fail = 0;
$made = array();

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

function make( $closing = '+10 days', $opening = '' ) {
	global $made;

	$id = TG_Tender::create( array(
		'title'      => 'TEST- lifecycle fixture',
		'buyer'      => 'Test Buyer',
		'country'    => 'Oman',
		'closing_at' => gmdate( 'Y-m-d H:i:s', strtotime( $closing ) ),
		'opening_at' => $opening ? gmdate( 'Y-m-d H:i:s', strtotime( $opening ) ) : '',
	), 0 );

	if ( ! is_wp_error( $id ) ) {
		$made[] = $id;
	}

	return $id;
}

function status_of( $id ) {
	$t = TG_Tender::get( $id );

	return $t ? $t['status'] : '(gone)';
}

echo "\n== Approval with no opening time opens the tender immediately ==\n";
echo "   (the rule must live in the model, so every route into approval behaves alike)\n";
$a = make( '+10 days' );
TG_Tender::move( $a, TG_Status::PENDING, 0 );
TG_Tender::move( $a, TG_Status::APPROVED, 0 );
ok( 'approved with no opening time lands on Open, got ' . status_of( $a ), TG_Status::OPEN === status_of( $a ) );

echo "\n== Approval with a future opening time waits ==\n";
$b = make( '+10 days', '+2 days' );
TG_Tender::move( $b, TG_Status::PENDING, 0 );
TG_Tender::move( $b, TG_Status::APPROVED, 0 );
ok( 'stays Approved until its opening time, got ' . status_of( $b ), TG_Status::APPROVED === status_of( $b ) );

echo "\n== The scheduler opens what is due and closes what has expired ==\n";
$c = make( '+10 days', '-1 hour' );
TG_Tender::move( $c, TG_Status::PENDING, 0 );
TG_Tender::move( $c, TG_Status::APPROVED, 0 );
TG_Tender::run_schedule();
ok( 'opening time already passed -> Open, got ' . status_of( $c ), TG_Status::OPEN === status_of( $c ) );

$d = make( '-1 hour' );
TG_Tender::move( $d, TG_Status::PENDING, 0 );
TG_Tender::move( $d, TG_Status::APPROVED, 0 );
TG_Tender::run_schedule();
ok( 'closing time already passed -> Closed, got ' . status_of( $d ), TG_Status::CLOSED === status_of( $d ) );

echo "\n== Bids are refused on time, independently of the scheduler ==\n";
echo "   (a late cron must never be able to let a late bid through)\n";
$e = make( '-5 minutes' );
TG_Tender::move( $e, TG_Status::PENDING, 0 );
TG_Tender::move( $e, TG_Status::APPROVED, 0 );
// deliberately do NOT run the scheduler: the tender still says Open
$row = TG_Tender::get( $e );
ok( 'tender still shows Open before the scheduler runs', TG_Status::OPEN === $row['status'] );
ok( 'but accepts_bids() already refuses it', false === TG_Tender::accepts_bids( $row ) );

echo "\n== References are unique and never reused ==\n";
$f = make();
$g = make();
$rf = TG_Tender::get( $f );
$rg = TG_Tender::get( $g );
ok( 'two tenders get different references', $rf['external_id'] !== $rg['external_id'] );
$kept = $rg['external_id'];
TG_Tender::move( $g, TG_Status::CANCELLED, 0, 'Deleting for the reuse test.' );
$wpdb->delete( TG_Store::table(), array( 'id' => $g ) );
$h = make();
$rh = TG_Tender::get( $h );
ok( 'a deleted tender does not free its reference, got ' . $rh['external_id'], $rh['external_id'] !== $kept );

echo "\n== Status history is written for every move ==\n";
$events = TG_Tender::events( $a );
ok( 'history recorded create, submit, approve and auto-open (' . count( $events ) . ' rows)', count( $events ) >= 4 );
$last = end( $events );
ok( 'the automatic opening records why it happened', false !== stripos( (string) $last['reason'], 'no scheduled opening time' ) );

echo "\n== Terminal tenders cannot be edited ==\n";
$i = make();
TG_Tender::move( $i, TG_Status::CANCELLED, 0, 'Testing the edit lock.' );
$edit = TG_Tender::update( $i, array( 'title' => 'TEST- should not stick' ) );
ok( 'editing a cancelled tender is refused', is_wp_error( $edit ) );

// Clean up.
foreach ( $made as $id ) {
	$wpdb->delete( TG_Store::table(), array( 'id' => $id ) );
	$wpdb->delete( TG_Store::events_table(), array( 'tender_id' => $id ) );
}

echo "\n----------------------------------------\n";
echo "  {$pass} passed, {$fail} failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
