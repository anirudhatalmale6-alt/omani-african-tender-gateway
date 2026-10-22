<?php
/**
 * Standalone test of the tender status machine - no WordPress required.
 * Stubs only what TG_Status touches.
 */

define( 'ABSPATH', true );

class WP_Error {
	public $code; public $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_message() { return $this->message; }
}

require_once '/var/lib/freelancer/projects/40648513/repo/wp-content/plugins/tender-gateway/includes/class-tg-status.php';

$pass = 0; $fail = 0;

function ok( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) { $pass++; echo "  PASS  $label\n"; }
	else { $fail++; echo "  FAIL  $label\n"; }
}

function allowed( $from, $to ) {
	return true === TG_Status::can_move( $from, $to );
}

function refused( $from, $to ) {
	$r = TG_Status::can_move( $from, $to );
	return $r instanceof WP_Error;
}

echo "\n== The happy path must work ==\n";
ok( 'draft -> pending',            allowed( 'draft', 'pending' ) );
ok( 'pending -> approved',         allowed( 'pending', 'approved' ) );
ok( 'approved -> open',            allowed( 'approved', 'open' ) );
ok( 'open -> closed',              allowed( 'open', 'closed' ) );
ok( 'closed -> awarded',           allowed( 'closed', 'awarded' ) );

echo "\n== Skipping steps must be refused ==\n";
ok( 'draft -/-> open (no approval)',    refused( 'draft', 'open' ) );
ok( 'draft -/-> awarded',               refused( 'draft', 'awarded' ) );
ok( 'pending -/-> open (not approved)', refused( 'pending', 'open' ) );
ok( 'open -/-> awarded (not closed)',   refused( 'open', 'awarded' ) );
ok( 'approved -/-> closed',             refused( 'approved', 'closed' ) );

echo "\n== Going backwards must be refused ==\n";
ok( 'open -/-> approved',   refused( 'open', 'approved' ) );
ok( 'closed -/-> open',     refused( 'closed', 'open' ) );
ok( 'awarded -/-> open',    refused( 'awarded', 'open' ) );
ok( 'awarded -/-> closed',  refused( 'awarded', 'closed' ) );

echo "\n== Terminal statuses are final ==\n";
ok( 'cancelled is terminal',      TG_Status::is_terminal( 'cancelled' ) );
ok( 'rejected is terminal',       TG_Status::is_terminal( 'rejected' ) );
ok( 'cancelled -/-> open',        refused( 'cancelled', 'open' ) );
ok( 'cancelled -/-> draft',       refused( 'cancelled', 'draft' ) );
ok( 'rejected -/-> pending',      refused( 'rejected', 'pending' ) );
ok( 'rejected -/-> approved',     refused( 'rejected', 'approved' ) );

echo "\n== Cancellation is allowed from any live status ==\n";
foreach ( array( 'draft', 'pending', 'approved', 'open', 'closed' ) as $s ) {
	ok( "$s -> cancelled", allowed( $s, 'cancelled' ) );
}
ok( 'awarded -> cancelled (admin may still cancel)', allowed( 'awarded', 'cancelled' ) );

echo "\n== Nonsense input is refused ==\n";
ok( 'unknown target refused',        refused( 'open', 'banana' ) );
ok( 'empty target refused',          refused( 'open', '' ) );
ok( 'same status refused',           refused( 'open', 'open' ) );
ok( 'pending -> changes_required (M6)', allowed( 'pending', 'changes_required' ) );
ok( 'changes_required -> pending (resubmit)', allowed( 'changes_required', 'pending' ) );
ok( 'changes_required -/-> approved (must be resubmitted first)', refused( 'changes_required', 'approved' ) );
ok( 'changes_required -/-> open', refused( 'changes_required', 'open' ) );
ok( 'changes_required -> cancelled', allowed( 'changes_required', 'cancelled' ) );
ok( 'open -/-> changes_required (too late once live)', refused( 'open', 'changes_required' ) );
ok( 'phase-2 completed not reachable', refused( 'awarded', 'completed' ) );

echo "\n== Reasons are compulsory where they matter ==\n";
ok( 'rejected needs a reason',   TG_Status::needs_reason( 'rejected' ) );
ok( 'cancelled needs a reason',  TG_Status::needs_reason( 'cancelled' ) );
ok( 'changes_required needs a reason (it is what the buyer must fix)', TG_Status::needs_reason( 'changes_required' ) );
ok( 'approved needs no reason',  ! TG_Status::needs_reason( 'approved' ) );
ok( 'open needs no reason',      ! TG_Status::needs_reason( 'open' ) );

echo "\n== Supplier visibility ==\n";
$visible = TG_Status::supplier_visible();
ok( 'suppliers cannot see drafts',      ! in_array( 'draft', $visible, true ) );
ok( 'suppliers cannot see pending',     ! in_array( 'pending', $visible, true ) );
ok( 'suppliers cannot see rejected',    ! in_array( 'rejected', $visible, true ) );
ok( 'suppliers cannot see changes_required', ! in_array( 'changes_required', $visible, true ) );
ok( 'suppliers can see open',           in_array( 'open', $visible, true ) );
ok( 'suppliers can see closed',         in_array( 'closed', $visible, true ) );
ok( 'suppliers can see awarded',        in_array( 'awarded', $visible, true ) );
ok( 'suppliers can see cancelled',      in_array( 'cancelled', $visible, true ) );

echo "\n== Nine statuses are live (Phase 1 + Changes Required) ==\n";
$active = TG_Status::active();
ok( 'nine active statuses, got ' . count( $active ), 9 === count( $active ) );
ok( 'changes_required present', isset( $active['changes_required'] ) );
ok( 'under_evaluation absent', ! isset( $active['under_evaluation'] ) );
ok( 'completed absent',        ! isset( $active['completed'] ) );

echo "\n----------------------------------------\n";
echo "  $pass passed, $fail failed\n";
echo "----------------------------------------\n";

exit( $fail > 0 ? 1 : 0 );
