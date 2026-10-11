<?php
/**
 * The managed list of sectors / categories.
 *
 * This is not only tidiness. A tender's sector is matched against the sectors
 * a supplier declared when they registered, and that match decides who is
 * eligible to bid. Typed by hand, "Food & Beverage" and "Food and Beverage"
 * are two different sectors, and a tender filed under the wrong one is
 * invisible to every supplier who should have seen it - with nothing on screen
 * to explain why.
 *
 * So the list is held in one place, the admin maintains it, and both the
 * tender form and supplier registration choose from it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Sectors {

	const OPTION = 'tg_sectors_list';
	const SLUG   = 'tg-sectors';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_tg_sector_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_tg_sector_rename', array( __CLASS__, 'handle_rename' ) );
		add_action( 'admin_post_tg_sector_delete', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * The current list.
	 *
	 * Seeded once from the sectors the site shipped with, then owned by the
	 * admin. Anything already recorded against a tender or a supplier is folded
	 * in as well, so upgrading cannot orphan existing data.
	 */
	public static function all() {
		$stored = get_option( self::OPTION, null );

		if ( null === $stored ) {
			$stored = array_values( array_unique( array_merge(
				TG_Sample_Data::sectors(),
				self::in_use()
			) ) );

			sort( $stored );
			update_option( self::OPTION, $stored, false );
		}

		return is_array( $stored ) ? $stored : array();
	}

	/**
	 * Sectors actually recorded somewhere, whether or not they are in the list.
	 */
	public static function in_use() {
		global $wpdb;

		$out = array();

		$tender_sectors = $wpdb->get_col(
			"SELECT DISTINCT sector FROM " . TG_Store::table() . " WHERE sector <> ''"
		);

		foreach ( (array) $tender_sectors as $s ) {
			$out[] = $s;
		}

		$supplier_meta = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s",
			'tg_sectors'
		) );

		foreach ( (array) $supplier_meta as $blob ) {
			$list = maybe_unserialize( $blob );

			if ( is_array( $list ) ) {
				foreach ( $list as $s ) {
					if ( trim( (string) $s ) !== '' ) {
						$out[] = $s;
					}
				}
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * How many tenders and suppliers reference a sector. Used before deleting,
	 * because removing one that is in use would orphan those records.
	 */
	public static function usage( $sector ) {
		global $wpdb;

		$tenders = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM " . TG_Store::table() . " WHERE sector = %s",
			$sector
		) );

		$suppliers = 0;

		foreach ( get_users( array( 'role' => TG_Auth::ROLE, 'fields' => 'ID' ) ) as $id ) {
			$list = get_user_meta( $id, 'tg_sectors', true );

			if ( is_array( $list ) && in_array( $sector, $list, true ) ) {
				$suppliers++;
			}
		}

		return array( 'tenders' => $tenders, 'suppliers' => $suppliers );
	}

	public static function add( $name ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );

		if ( '' === $name ) {
			return new WP_Error( 'tg_sector_empty', 'Please enter a sector name.' );
		}

		$list = self::all();

		foreach ( $list as $existing ) {
			if ( 0 === strcasecmp( $existing, $name ) ) {
				return new WP_Error( 'tg_sector_exists', sprintf( '"%s" is already on the list.', $existing ) );
			}
		}

		$list[] = $name;
		sort( $list );
		update_option( self::OPTION, $list, false );

		return true;
	}

	/**
	 * Rename, and carry every tender and supplier across with it.
	 *
	 * Renaming the label without moving the records would silently break
	 * eligibility for everyone filed under the old name.
	 */
	public static function rename( $from, $to ) {
		global $wpdb;

		$to = trim( wp_strip_all_tags( (string) $to ) );

		if ( '' === $to ) {
			return new WP_Error( 'tg_sector_empty', 'Please enter the new name.' );
		}

		$list = self::all();

		if ( ! in_array( $from, $list, true ) ) {
			return new WP_Error( 'tg_sector_missing', 'That sector is not on the list.' );
		}

		foreach ( $list as $existing ) {
			if ( $existing !== $from && 0 === strcasecmp( $existing, $to ) ) {
				return new WP_Error( 'tg_sector_exists', sprintf( '"%s" is already on the list.', $existing ) );
			}
		}

		$wpdb->update( TG_Store::table(), array( 'sector' => $to ), array( 'sector' => $from ) );

		foreach ( get_users( array( 'role' => TG_Auth::ROLE, 'fields' => 'ID' ) ) as $id ) {
			$current = get_user_meta( $id, 'tg_sectors', true );

			if ( ! is_array( $current ) || ! in_array( $from, $current, true ) ) {
				continue;
			}

			$current = array_values( array_unique( array_map(
				function ( $s ) use ( $from, $to ) {
					return $s === $from ? $to : $s;
				},
				$current
			) ) );

			update_user_meta( $id, 'tg_sectors', $current );
		}

		$list = array_map(
			function ( $s ) use ( $from, $to ) {
				return $s === $from ? $to : $s;
			},
			$list
		);

		sort( $list );
		update_option( self::OPTION, $list, false );

		return true;
	}

	public static function delete( $name ) {
		$usage = self::usage( $name );

		if ( $usage['tenders'] || $usage['suppliers'] ) {
			return new WP_Error(
				'tg_sector_in_use',
				sprintf(
					'"%s" is still used by %d tender(s) and %d supplier(s). Rename it instead, or move those records first.',
					$name,
					$usage['tenders'],
					$usage['suppliers']
				)
			);
		}

		$list = array_values( array_filter( self::all(), function ( $s ) use ( $name ) {
			return $s !== $name;
		} ) );

		update_option( self::OPTION, $list, false );

		return true;
	}

	/* ------------------------------------------------------------- Admin UI */

	public static function menu() {
		add_submenu_page(
			'tender-gateway',
			'Sectors',
			'Sectors',
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'screen' )
		);
	}

	public static function url() {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$list = self::all();

		echo '<div class="wrap"><h1>Sectors</h1>';
		self::notice();
		echo '<p class="description" style="max-width:760px;">These are the categories a tender can be filed '
			. 'under, and the ones a supplier chooses when registering. A tender is only offered to suppliers '
			. 'who declared its sector, so the two lists have to be the same list - which is why this is not a '
			. 'free-text field.</p>';

		echo '<table class="wp-list-table widefat striped" style="max-width:820px;"><thead><tr>';
		echo '<th style="width:45%;">Sector</th><th style="width:12%;">Tenders</th><th style="width:12%;">Suppliers</th>';
		echo '<th style="width:31%;">Actions</th></tr></thead><tbody>';

		foreach ( $list as $sector ) {
			$usage = self::usage( $sector );

			echo '<tr><td><strong>' . esc_html( $sector ) . '</strong></td>';
			echo '<td>' . (int) $usage['tenders'] . '</td><td>' . (int) $usage['suppliers'] . '</td>';
			echo '<td>';

			// Rename.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-flex;gap:4px;align-items:center;">';
			wp_nonce_field( 'tg_sector_rename' );
			echo '<input type="hidden" name="action" value="tg_sector_rename">';
			echo '<input type="hidden" name="from" value="' . esc_attr( $sector ) . '">';
			echo '<input type="text" name="to" value="' . esc_attr( $sector ) . '" style="width:180px;">';
			echo '<button class="button button-small">Rename</button></form> ';

			if ( ! $usage['tenders'] && ! $usage['suppliers'] ) {
				echo '<a class="button button-small" href="' . esc_url( wp_nonce_url(
					add_query_arg( array( 'action' => 'tg_sector_delete', 'sector' => rawurlencode( $sector ) ), admin_url( 'admin-post.php' ) ),
					'tg_sector_delete'
				) ) . '" onclick="return confirm(\'Remove this sector?\');">Remove</a>';
			}

			echo '</td></tr>';
		}

		if ( empty( $list ) ) {
			echo '<tr><td colspan="4">No sectors yet. Add the first one below.</td></tr>';
		}

		echo '</tbody></table>';

		echo '<h2 style="margin-top:24px;">Add a sector</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tg_sector_add' );
		echo '<input type="hidden" name="action" value="tg_sector_add">';
		echo '<input type="text" name="name" class="regular-text" placeholder="e.g. Packaging &amp; Containers" required> ';
		submit_button( 'Add sector', 'primary', 'submit', false );
		echo '</form>';

		echo '<p class="description" style="margin-top:16px;max-width:760px;">Renaming moves every tender and '
			. 'supplier across with it, so nothing is left filed under the old name. A sector still in use cannot '
			. 'be removed - rename it instead, or move those records first.</p>';
		echo '</div>';
	}

	public static function handle_add() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( 'tg_sector_add' );

		$result = self::add( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' );

		self::finish( $result, 'Sector added.' );
	}

	public static function handle_rename() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( 'tg_sector_rename' );

		$from = isset( $_POST['from'] ) ? wp_unslash( $_POST['from'] ) : '';
		$to   = isset( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : '';

		if ( $from === trim( $to ) ) {
			self::finish( true, 'Nothing changed.' );
		}

		self::finish( self::rename( $from, $to ), 'Sector renamed, and every tender and supplier moved with it.' );
	}

	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( 'tg_sector_delete' );

		$sector = isset( $_GET['sector'] ) ? rawurldecode( wp_unslash( $_GET['sector'] ) ) : '';

		self::finish( self::delete( $sector ), 'Sector removed.' );
	}

	private static function finish( $result, $success ) {
		set_transient(
			'tg_admin_notice_' . get_current_user_id(),
			is_wp_error( $result )
				? array( 'type' => 'error', 'message' => $result->get_error_message() )
				: array( 'type' => 'success', 'message' => $success ),
			60
		);

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
