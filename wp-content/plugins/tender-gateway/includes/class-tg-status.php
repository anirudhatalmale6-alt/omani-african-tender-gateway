<?php
/**
 * Tender status lifecycle.
 *
 * The specification is explicit that only certain moves are allowed, so the
 * rules live in one place and every caller goes through can_move(). Nothing
 * writes `status` directly - see TG_Tender::move() - because a status change
 * that skips the rules is exactly the thing an audit trail cannot explain
 * afterwards.
 *
 * Phase 1 ships eight statuses. CHANGES_REQUIRED, UNDER_EVALUATION and
 * COMPLETED arrive in Phase 2; they are listed in the map below but marked
 * inactive so the transition table does not have to be rewritten later.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Status {

	const DRAFT            = 'draft';
	const PENDING          = 'pending';
	const APPROVED         = 'approved';
	const OPEN             = 'open';
	const CLOSED           = 'closed';
	const AWARDED          = 'awarded';
	const CANCELLED        = 'cancelled';
	const REJECTED         = 'rejected';

	// Phase 2.
	const CHANGES_REQUIRED = 'changes_required';
	const UNDER_EVALUATION = 'under_evaluation';
	const COMPLETED        = 'completed';

	/**
	 * label    - shown in the admin and to buyers/suppliers
	 * terminal - no further moves are possible
	 * phase    - 1 means live now; 2 means defined but not yet reachable
	 * public   - whether a tender in this status is visible to suppliers at all
	 */
	public static function map() {
		return array(
			self::DRAFT            => array( 'label' => 'Draft',             'terminal' => false, 'phase' => 1, 'public' => false ),
			self::PENDING          => array( 'label' => 'Pending Approval',  'terminal' => false, 'phase' => 1, 'public' => false ),
			self::CHANGES_REQUIRED => array( 'label' => 'Changes Required',  'terminal' => false, 'phase' => 2, 'public' => false ),
			self::APPROVED         => array( 'label' => 'Approved',          'terminal' => false, 'phase' => 1, 'public' => true ),
			self::OPEN             => array( 'label' => 'Open',              'terminal' => false, 'phase' => 1, 'public' => true ),
			self::CLOSED           => array( 'label' => 'Closed',            'terminal' => false, 'phase' => 1, 'public' => true ),
			self::UNDER_EVALUATION => array( 'label' => 'Under Evaluation',  'terminal' => false, 'phase' => 2, 'public' => true ),
			self::AWARDED          => array( 'label' => 'Awarded',           'terminal' => false, 'phase' => 1, 'public' => true ),
			self::COMPLETED        => array( 'label' => 'Completed',         'terminal' => true,  'phase' => 2, 'public' => true ),
			self::CANCELLED        => array( 'label' => 'Cancelled',         'terminal' => true,  'phase' => 1, 'public' => true ),
			self::REJECTED         => array( 'label' => 'Rejected',          'terminal' => true,  'phase' => 1, 'public' => false ),
		);
	}

	/**
	 * Statuses that exist in this phase, for dropdowns and filters.
	 */
	public static function active() {
		$out = array();

		foreach ( self::map() as $key => $meta ) {
			if ( 1 === $meta['phase'] ) {
				$out[ $key ] = $meta['label'];
			}
		}

		return $out;
	}

	public static function label( $status ) {
		$map = self::map();

		return isset( $map[ $status ] ) ? $map[ $status ]['label'] : ucfirst( (string) $status );
	}

	public static function exists( $status ) {
		$map = self::map();

		return isset( $map[ $status ] ) && 1 === $map[ $status ]['phase'];
	}

	public static function is_terminal( $status ) {
		$map = self::map();

		return isset( $map[ $status ] ) ? (bool) $map[ $status ]['terminal'] : false;
	}

	/**
	 * Statuses a supplier may see at all. Anything else is invisible to them
	 * regardless of subscription - a draft or rejected tender is nobody's
	 * business but the buyer's and the admin's.
	 */
	public static function supplier_visible() {
		$out = array();

		foreach ( self::map() as $key => $meta ) {
			if ( 1 === $meta['phase'] && $meta['public'] ) {
				$out[] = $key;
			}
		}

		return $out;
	}

	/**
	 * Permitted moves, as from => array of allowed to.
	 *
	 * Cancellation is deliberately not listed here. Any non-terminal status may
	 * move to CANCELLED, which is handled in can_move() so the table does not
	 * repeat itself eleven times.
	 */
	public static function transitions() {
		return array(
			self::DRAFT            => array( self::PENDING ),
			self::PENDING          => array( self::APPROVED, self::REJECTED, self::CHANGES_REQUIRED ),
			self::CHANGES_REQUIRED => array( self::PENDING ),
			// Approved waits for its opening time; the scheduler moves it to Open.
			self::APPROVED         => array( self::OPEN ),
			self::OPEN             => array( self::CLOSED ),
			self::CLOSED           => array( self::AWARDED, self::UNDER_EVALUATION ),
			self::UNDER_EVALUATION => array( self::AWARDED ),
			self::AWARDED          => array( self::COMPLETED ),
			self::COMPLETED        => array(),
			self::CANCELLED        => array(),
			self::REJECTED         => array(),
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public static function can_move( $from, $to ) {
		if ( ! self::exists( $to ) ) {
			return new WP_Error( 'tg_status_unknown', sprintf( 'Unknown status "%s".', $to ) );
		}

		if ( $from === $to ) {
			return new WP_Error( 'tg_status_same', 'The tender is already in that status.' );
		}

		if ( self::is_terminal( $from ) ) {
			return new WP_Error(
				'tg_status_terminal',
				sprintf( '%s is a final status and cannot be changed.', self::label( $from ) )
			);
		}

		// Any live tender can be cancelled, with a reason.
		if ( self::CANCELLED === $to ) {
			return true;
		}

		$allowed = self::transitions();
		$allowed = isset( $allowed[ $from ] ) ? $allowed[ $from ] : array();

		if ( ! in_array( $to, $allowed, true ) ) {
			return new WP_Error(
				'tg_status_not_allowed',
				sprintf( 'A tender cannot go from %s to %s.', self::label( $from ), self::label( $to ) )
			);
		}

		return true;
	}

	/**
	 * Moves that require a written reason. Rejecting or cancelling someone's
	 * tender without saying why is the kind of thing that gets disputed.
	 */
	public static function needs_reason( $to ) {
		return in_array( $to, array( self::REJECTED, self::CANCELLED, self::CHANGES_REQUIRED ), true );
	}
}
