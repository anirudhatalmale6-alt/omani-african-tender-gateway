<?php
/**
 * Tenders owned by the platform - the ones Jumla Tender creates and publishes
 * itself, as opposed to the read-only records pulled from the Ministry feed.
 *
 * They live in the same table under source = 'own'. That is deliberate: the
 * listing, cards, detail page, filters and pretty URLs already work against
 * that table, so owned tenders inherit the whole front end instead of needing
 * a parallel one. The API records are never touched by anything in here.
 *
 * `external_id` carries the human tender reference (JT-2026-0001), which the
 * existing UNIQUE KEY (source, external_id) then enforces for free.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Tender {

	const SOURCE = 'own';

	/**
	 * Fields a buyer or admin can set. Status is NOT here - it only ever
	 * changes through move().
	 */
	public static function editable_fields() {
		return array(
			'title', 'buyer', 'country', 'sector', 'summary', 'description',
			'quantity', 'unit', 'specifications', 'delivery_location',
			'value', 'currency', 'opening_at', 'closing_at',
		);
	}

	/**
	 * Next reference in the JT-{year}-{sequence} series.
	 *
	 * Derived from the highest existing reference for the year rather than from
	 * a row count, so deleting a tender cannot cause a reference to be reused.
	 */
	public static function next_reference() {
		global $wpdb;

		$year   = (int) current_time( 'Y' );
		$prefix = 'JT-' . $year . '-';

		$highest = $wpdb->get_var( $wpdb->prepare(
			"SELECT external_id FROM " . TG_Store::table() . "
			 WHERE source = %s AND external_id LIKE %s
			 ORDER BY LENGTH(external_id) DESC, external_id DESC LIMIT 1",
			self::SOURCE,
			$wpdb->esc_like( $prefix ) . '%'
		) );

		$next = 1;

		if ( $highest && preg_match( '/(\d+)$/', $highest, $m ) ) {
			$next = (int) $m[1] + 1;
		}

		return $prefix . str_pad( (string) $next, 4, '0', STR_PAD_LEFT );
	}

	/**
	 * @return int|WP_Error Row id.
	 */
	public static function create( $data, $actor_id = 0 ) {
		global $wpdb;

		$data = self::sanitise( $data );

		if ( '' === $data['title'] ) {
			return new WP_Error( 'tg_tender_title', 'A tender needs a title.' );
		}

		$now = current_time( 'mysql' );

		$row = array_merge( $data, array(
			'source'      => self::SOURCE,
			'external_id' => self::next_reference(),
			'status'      => TG_Status::DRAFT,
			'owner_id'    => $actor_id ? (int) $actor_id : null,
			'created_at'  => $now,
			'updated_at'  => $now,
			// Keep the legacy date column in step so the existing listing,
			// "closing within 30 days" filter and prune rule keep working.
			'deadline'    => $data['closing_at'] ? substr( $data['closing_at'], 0, 10 ) : null,
		) );

		$ok = $wpdb->insert( TG_Store::table(), $row );

		if ( ! $ok ) {
			return new WP_Error( 'tg_tender_insert', 'Could not save the tender.' );
		}

		$id = (int) $wpdb->insert_id;

		self::log( $id, $actor_id, '', TG_Status::DRAFT, 'Tender created.' );

		return $id;
	}

	/**
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;

		$tender = self::get( $id );

		if ( ! $tender ) {
			return new WP_Error( 'tg_tender_missing', 'That tender no longer exists.' );
		}

		if ( TG_Status::is_terminal( $tender['status'] ) ) {
			return new WP_Error(
				'tg_tender_locked',
				sprintf( 'A %s tender cannot be edited.', strtolower( TG_Status::label( $tender['status'] ) ) )
			);
		}

		$data = self::sanitise( $data );

		if ( '' === $data['title'] ) {
			return new WP_Error( 'tg_tender_title', 'A tender needs a title.' );
		}

		$data['updated_at'] = current_time( 'mysql' );
		$data['deadline']   = $data['closing_at'] ? substr( $data['closing_at'], 0, 10 ) : null;

		$wpdb->update( TG_Store::table(), $data, array( 'id' => (int) $id ) );

		return true;
	}

	/**
	 * The only way a status ever changes.
	 *
	 * @return true|WP_Error
	 */
	public static function move( $id, $to, $actor_id = 0, $reason = '' ) {
		global $wpdb;

		$tender = self::get( $id );

		if ( ! $tender ) {
			return new WP_Error( 'tg_tender_missing', 'That tender no longer exists.' );
		}

		$from  = $tender['status'];
		$check = TG_Status::can_move( $from, $to );

		if ( is_wp_error( $check ) ) {
			return $check;
		}

		$reason = trim( wp_strip_all_tags( (string) $reason ) );

		if ( TG_Status::needs_reason( $to ) && '' === $reason ) {
			return new WP_Error(
				'tg_status_reason',
				sprintf( 'Please give a reason when moving a tender to %s.', TG_Status::label( $to ) )
			);
		}

		$update = array(
			'status'        => $to,
			'status_reason' => $reason,
			'updated_at'    => current_time( 'mysql' ),
		);

		// The day a tender first becomes visible is its publication date, which
		// the existing card and detail templates already display.
		if ( TG_Status::OPEN === $to && empty( $tender['published'] ) ) {
			$update['published'] = current_time( 'Y-m-d' );
		}

		$wpdb->update( TG_Store::table(), $update, array( 'id' => (int) $id ) );

		self::log( $id, $actor_id, $from, $to, $reason );

		/**
		 * Hook point for the notifications built alongside each milestone -
		 * bid alerts in M2, award mails in M3, and so on.
		 */
		do_action( 'tg_tender_status_changed', (int) $id, $from, $to, (int) $actor_id, $reason );

		return true;
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::table() . " WHERE id = %d AND source = %s",
			(int) $id,
			self::SOURCE
		), ARRAY_A );

		return $row ? $row : null;
	}

	public static function get_by_reference( $reference ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::table() . " WHERE source = %s AND external_id = %s",
			self::SOURCE,
			(string) $reference
		), ARRAY_A );

		return $row ? $row : null;
	}

	/**
	 * @param array $args status|statuses, owner_id, search, orderby, order, per_page, page
	 * @return array{rows: array, total: int}
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$args = wp_parse_args( $args, array(
			'status'   => '',
			'statuses' => array(),
			'owner_id' => 0,
			'search'   => '',
			'orderby'  => 'updated_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		) );

		$where  = array( 'source = %s' );
		$params = array( self::SOURCE );

		if ( $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		} elseif ( ! empty( $args['statuses'] ) ) {
			$in       = implode( ', ', array_fill( 0, count( $args['statuses'] ), '%s' ) );
			$where[]  = "status IN ({$in})";
			$params   = array_merge( $params, array_values( $args['statuses'] ) );
		}

		if ( $args['owner_id'] ) {
			$where[]  = 'owner_id = %d';
			$params[] = (int) $args['owner_id'];
		}

		if ( '' !== trim( (string) $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( trim( $args['search'] ) ) . '%';
			$where[]  = '( title LIKE %s OR external_id LIKE %s OR buyer LIKE %s )';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$allowed_orderby = array( 'updated_at', 'created_at', 'closing_at', 'title', 'external_id', 'status' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'updated_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

		$clause = 'WHERE ' . implode( ' AND ', $where );
		$table  = TG_Store::table();

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} {$clause}", $params ) );

		$per_page = max( 1, (int) $args['per_page'] );
		$offset   = max( 0, ( (int) $args['page'] - 1 ) ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} {$clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
			array_merge( $params, array( $per_page, $offset ) )
		), ARRAY_A );

		return array(
			'rows'  => (array) $rows,
			'total' => $total,
		);
	}

	/**
	 * Counts per status, for the admin overview panel. One grouped query
	 * rather than one query per status.
	 */
	public static function counts_by_status() {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT status, COUNT(*) AS n FROM " . TG_Store::table() . " WHERE source = %s GROUP BY status",
			self::SOURCE
		), ARRAY_A );

		$out = array_fill_keys( array_keys( TG_Status::active() ), 0 );

		foreach ( (array) $rows as $row ) {
			$out[ $row['status'] ] = (int) $row['n'];
		}

		return $out;
	}

	/**
	 * Tenders closing within the next N days, for the admin overview panel.
	 */
	public static function closing_within( $days = 7 ) {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . TG_Store::table() . "
			 WHERE source = %s AND status = %s AND closing_at IS NOT NULL
			 AND closing_at BETWEEN %s AND %s",
			self::SOURCE,
			TG_Status::OPEN,
			current_time( 'mysql' ),
			gmdate( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' +' . (int) $days . ' days' ) )
		) );
	}

	public static function events( $tender_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::events_table() . " WHERE tender_id = %d ORDER BY id ASC",
			(int) $tender_id
		), ARRAY_A );
	}

	/**
	 * Opens tenders whose scheduled opening time has arrived, and closes those
	 * whose closing time has passed.
	 *
	 * Runs on cron, and also on admin page loads as a safety net, because a
	 * tender that silently stays open past its closing time is a dispute.
	 */
	public static function run_schedule() {
		global $wpdb;

		$now   = current_time( 'mysql' );
		$table = TG_Store::table();
		$moved = array( 'opened' => 0, 'closed' => 0 );

		// Approved and due to open. A tender with no opening time opens as soon
		// as it is approved, which is handled at approval rather than here.
		$to_open = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source = %s AND status = %s
			 AND opening_at IS NOT NULL AND opening_at <= %s LIMIT 50",
			self::SOURCE,
			TG_Status::APPROVED,
			$now
		) );

		foreach ( $to_open as $id ) {
			if ( true === self::move( (int) $id, TG_Status::OPEN, 0, 'Opened automatically at the scheduled time.' ) ) {
				$moved['opened']++;
			}
		}

		$to_close = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source = %s AND status = %s
			 AND closing_at IS NOT NULL AND closing_at <= %s LIMIT 50",
			self::SOURCE,
			TG_Status::OPEN,
			$now
		) );

		foreach ( $to_close as $id ) {
			if ( true === self::move( (int) $id, TG_Status::CLOSED, 0, 'Closed automatically at the closing time.' ) ) {
				$moved['closed']++;
			}
		}

		return $moved;
	}

	/**
	 * Whether bids may be submitted right now. Used by the bid form in M2 and
	 * checked server-side on submission, never trusted from the page.
	 */
	public static function accepts_bids( $tender ) {
		if ( ! is_array( $tender ) || TG_Status::OPEN !== $tender['status'] ) {
			return false;
		}

		if ( empty( $tender['closing_at'] ) ) {
			return true;
		}

		return strtotime( $tender['closing_at'] ) > strtotime( current_time( 'mysql' ) );
	}

	private static function log( $tender_id, $actor_id, $from, $to, $reason ) {
		global $wpdb;

		$wpdb->insert( TG_Store::events_table(), array(
			'tender_id'   => (int) $tender_id,
			'actor_id'    => $actor_id ? (int) $actor_id : null,
			'from_status' => (string) $from,
			'to_status'   => (string) $to,
			'reason'      => (string) $reason,
			'created_at'  => current_time( 'mysql' ),
		) );
	}

	private static function sanitise( $data ) {
		$out = array();

		$text = array( 'title', 'buyer', 'country', 'sector', 'quantity', 'unit', 'delivery_location', 'currency' );

		foreach ( $text as $key ) {
			$out[ $key ] = isset( $data[ $key ] ) ? sanitize_text_field( (string) $data[ $key ] ) : '';
		}

		foreach ( array( 'summary', 'description', 'specifications' ) as $key ) {
			$out[ $key ] = isset( $data[ $key ] ) ? wp_kses_post( (string) $data[ $key ] ) : '';
		}

		$out['value'] = isset( $data['value'] ) ? (float) $data['value'] : 0;

		foreach ( array( 'opening_at', 'closing_at' ) as $key ) {
			$out[ $key ] = self::datetime_or_null( isset( $data[ $key ] ) ? $data[ $key ] : '' );
		}

		return $out;
	}

	/**
	 * Accepts both the HTML datetime-local format and plain MySQL datetimes.
	 */
	private static function datetime_or_null( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return null;
		}

		$value = str_replace( 'T', ' ', $value );
		$time  = strtotime( $value );

		return $time ? gmdate( 'Y-m-d H:i:s', $time ) : null;
	}
}
