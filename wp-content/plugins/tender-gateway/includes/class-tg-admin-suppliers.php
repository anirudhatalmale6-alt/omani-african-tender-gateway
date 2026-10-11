<?php
/**
 * Supplier management: who has registered, and whether they may bid.
 *
 * New suppliers arrive unverified and only verified suppliers can bid, so
 * without this screen a registered supplier would sit waiting for ever. The
 * verification status existed before this; the way to change it did not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin_Suppliers {

	const SLUG = 'tg-suppliers';

	const PENDING   = 'pending';
	const VERIFIED  = 'verified';
	const REJECTED  = 'rejected';
	const SUSPENDED = 'suspended';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_tg_supplier_status', array( __CLASS__, 'handle_status' ) );
	}

	public static function menu() {
		add_submenu_page(
			'tender-gateway',
			'Suppliers',
			'Suppliers',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'screen' )
		);
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function statuses() {
		return array(
			self::PENDING   => 'Awaiting approval',
			self::VERIFIED  => 'Verified',
			self::REJECTED  => 'Rejected',
			self::SUSPENDED => 'Suspended',
		);
	}

	public static function label( $status ) {
		$all = self::statuses();

		return isset( $all[ $status ] ) ? $all[ $status ] : 'Awaiting approval';
	}

	/**
	 * Status of one supplier, defaulting to pending.
	 *
	 * Accounts created before verification existed have no stored status. They
	 * are treated as verified rather than pending, because they were able to
	 * use the site yesterday and silently locking them out would be a worse
	 * surprise than an extra account to review.
	 */
	public static function status_of( $user_id ) {
		$stored = get_user_meta( $user_id, 'tg_status', true );

		if ( $stored ) {
			return $stored;
		}

		return self::VERIFIED;
	}

	public static function counts() {
		$out = array_fill_keys( array_keys( self::statuses() ), 0 );

		foreach ( get_users( array( 'role' => TG_Auth::ROLE, 'fields' => 'ID' ) ) as $id ) {
			$status = self::status_of( $id );

			if ( isset( $out[ $status ] ) ) {
				$out[ $status ]++;
			}
		}

		return $out;
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( $filter && ! isset( self::statuses()[ $filter ] ) ) {
			$filter = '';
		}

		$args = array( 'role' => TG_Auth::ROLE, 'orderby' => 'registered', 'order' => 'DESC', 'number' => 200 );

		if ( $search ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_email', 'user_login', 'display_name' );
		}

		$users  = get_users( $args );
		$counts = self::counts();
		$total  = array_sum( $counts );

		echo '<div class="wrap"><h1>Suppliers</h1>';
		self::notice();
		echo '<p class="description">Suppliers register themselves. They can browse tender headlines straight away, '
			. 'but only a verified supplier can see full specifications or submit a bid.</p>';

		// Status tabs.
		echo '<ul class="subsubsub">';
		$links   = array();
		$links[] = '<li><a href="' . esc_url( self::url() ) . '"' . ( '' === $filter ? ' class="current"' : '' )
			. '>All <span class="count">(' . (int) $total . ')</span></a></li>';

		foreach ( self::statuses() as $key => $label ) {
			$links[] = '<li><a href="' . esc_url( self::url( array( 'status' => $key ) ) ) . '"'
				. ( $filter === $key ? ' class="current"' : '' ) . '>' . esc_html( $label )
				. ' <span class="count">(' . (int) $counts[ $key ] . ')</span></a></li>';
		}

		echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput
		echo '</ul>';

		echo '<form method="get" style="float:right;margin:0 0 8px;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search suppliers">';
		echo '<button class="button">Search</button></form>';

		// Widths must total 100%: this is a fixed-layout table, so an
		// unsized column gets whatever is left over - which was nothing, and
		// squeezed "Sectors" into a one-character vertical strip.
		echo '<table class="wp-list-table widefat striped"><thead><tr>';
		echo '<th style="width:19%;">Company</th><th style="width:13%;">Contact</th><th style="width:17%;">Email</th>';
		echo '<th style="width:11%;">Phone / CR</th><th style="width:18%;">Sectors</th><th style="width:6%;">Bids</th>';
		echo '<th style="width:8%;">Status</th><th style="width:8%;">Action</th>';
		echo '</tr></thead><tbody>';

		$shown = 0;

		foreach ( $users as $user ) {
			$status = self::status_of( $user->ID );

			if ( $filter && $status !== $filter ) {
				continue;
			}

			$shown++;
			$meta = function ( $key ) use ( $user ) {
				$v = get_user_meta( $user->ID, $key, true );

				return $v ? $v : '';
			};

			$sectors = get_user_meta( $user->ID, 'tg_sectors', true );
			$sectors = is_array( $sectors ) ? array_filter( $sectors ) : array();
			$bids    = count( TG_Bid::for_supplier( $user->ID ) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( $meta( 'tg_company' ) ? $meta( 'tg_company' ) : $user->display_name ) . '</strong>';
			echo '<br><span class="description">Registered ' . esc_html( date_i18n( 'j M Y', strtotime( $user->user_registered ) ) ) . '</span></td>';
			echo '<td>' . esc_html( $meta( 'tg_contact' ) ) . '</td>';
			echo '<td><a href="mailto:' . esc_attr( $user->user_email ) . '">' . esc_html( $user->user_email ) . '</a></td>';
			echo '<td>' . esc_html( $meta( 'tg_phone' ) );

			if ( $meta( 'tg_cr' ) ) {
				echo '<br><span class="description">CR ' . esc_html( $meta( 'tg_cr' ) ) . '</span>';
			}

			echo '</td>';
			echo '<td>' . ( $sectors ? esc_html( implode( ', ', $sectors ) ) : '<span class="description">None declared</span>' ) . '</td>';
			echo '<td>' . (int) $bids . '</td>';
			echo '<td>' . self::badge( $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<td>' . self::actions( $user->ID, $status ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tr>';
		}

		if ( ! $shown ) {
			echo '<tr><td colspan="8">No suppliers' . ( $filter ? ' with that status' : ' have registered yet' ) . '.</td></tr>';
		}

		echo '</tbody></table>';
		echo '<p class="description" style="margin-top:12px;">Suspending a supplier stops them bidding but keeps every bid '
			. 'they have already placed. Nothing in a tendering record is ever deleted.</p>';
		echo '</div>';
	}

	private static function badge( $status ) {
		$colours = array(
			self::VERIFIED  => '#1d7a3f',
			self::PENDING   => '#b26200',
			self::REJECTED  => '#8a2424',
			self::SUSPENDED => '#50575e',
		);

		$colour = isset( $colours[ $status ] ) ? $colours[ $status ] : '#646970';

		return '<span style="display:inline-block;padding:2px 8px;border-radius:3px;background:' . esc_attr( $colour )
			. ';color:#fff;font-size:11px;font-weight:600;">' . esc_html( self::label( $status ) ) . '</span>';
	}

	private static function actions( $user_id, $status ) {
		$offer = array();

		if ( self::VERIFIED !== $status ) {
			$offer[ self::VERIFIED ] = 'Verify';
		}

		if ( self::VERIFIED === $status ) {
			$offer[ self::SUSPENDED ] = 'Suspend';
		}

		if ( self::PENDING === $status ) {
			$offer[ self::REJECTED ] = 'Reject';
		}

		$out = '';

		foreach ( $offer as $to => $label ) {
			$out .= '<a class="button button-small" style="margin:0 4px 4px 0;" href="'
				. esc_url( wp_nonce_url(
					add_query_arg(
						array( 'action' => 'tg_supplier_status', 'user' => $user_id, 'to' => $to ),
						admin_url( 'admin-post.php' )
					),
					'tg_supplier_' . $user_id
				) ) . '">' . esc_html( $label ) . '</a>';
		}

		return $out ? $out : '<span class="description">&mdash;</span>';
	}

	public static function handle_status() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$user_id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
		$to      = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';

		check_admin_referer( 'tg_supplier_' . $user_id );

		$user = get_userdata( $user_id );

		if ( ! $user || ! in_array( TG_Auth::ROLE, (array) $user->roles, true ) ) {
			self::redirect( 'error', 'That supplier no longer exists.' );
		}

		if ( ! isset( self::statuses()[ $to ] ) ) {
			self::redirect( 'error', 'Unknown status.' );
		}

		update_user_meta( $user_id, 'tg_status', $to );
		update_user_meta( $user_id, 'tg_status_changed', current_time( 'mysql' ) );
		update_user_meta( $user_id, 'tg_status_changed_by', get_current_user_id() );

		do_action( 'tg_supplier_status_changed', $user_id, $to );

		$company = get_user_meta( $user_id, 'tg_company', true );

		self::redirect( 'success', sprintf(
			'%s is now marked %s.',
			$company ? $company : $user->display_name,
			strtolower( self::label( $to ) )
		) );
	}

	private static function redirect( $type, $message ) {
		set_transient( 'tg_admin_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );

		wp_safe_redirect( self::url() );
		exit;
	}

	private static function notice() {
		$key    = 'tg_admin_notice_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			'error' === $notice['type'] ? 'error' : 'success',
			esc_html( $notice['message'] )
		);
	}
}
