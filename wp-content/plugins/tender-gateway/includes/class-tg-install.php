<?php
/**
 * Activation: create the supplier role, the pages the journey needs, and a
 * demonstration supplier account so the Ministry walkthrough can start from a
 * signed-in state without anyone having to register first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Install {

	public static function pages() {
		return array(
			'tenders'   => array(
				'title'   => 'Tender Opportunities',
				'content' => '[tg_tenders]',
			),
			'login'     => array(
				'title'   => 'Sign In',
				'content' => '[tg_login]',
			),
			'register'  => array(
				'title'   => 'Register',
				'content' => '[tg_register]',
			),
			'dashboard' => array(
				'title'   => 'My Dashboard',
				'content' => '[tg_dashboard]',
			),
		);
	}

	public static function activate() {
		TG_Auth::register_role();
		TG_Store::install();
		self::create_pages();
		self::create_demo_supplier();

		// Only schedule the pull once a live source is actually selected; there
		// is nothing to sync while the demonstration feed is in use.
		if ( 'remote' === tg_setting( 'source' ) ) {
			TG_Sync::activate_schedule();
		}

		TG_Router::rewrites();
		flush_rewrite_rules();
	}

	private static function create_pages() {
		$existing = get_option( 'tg_pages', array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		foreach ( self::pages() as $slug => $page ) {
			// Don't recreate a page the client has since renamed or edited.
			if ( ! empty( $existing[ $slug ] ) && 'page' === get_post_type( $existing[ $slug ] ) ) {
				continue;
			}

			$found = get_page_by_path( $slug );
			if ( $found ) {
				$existing[ $slug ] = $found->ID;
				continue;
			}

			$id = wp_insert_post( array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'post_title'     => $page['title'],
				'post_name'      => $slug,
				'post_content'   => $page['content'],
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			) );

			if ( $id && ! is_wp_error( $id ) ) {
				$existing[ $slug ] = $id;
			}
		}

		update_option( 'tg_pages', $existing );
	}

	/**
	 * The walkthrough account used for the Ministry demonstration.
	 *
	 * 🔒 Only created while the site is running on the bundled sample feed. Its
	 * password is published in this repository, which was harmless for a
	 * prototype but is not harmless on a live tendering platform - anyone
	 * reading the source could otherwise sign in as a supplier and read tender
	 * detail that subscribers pay for. Once a real source is selected the
	 * account is never created, and an existing one is demoted so it cannot be
	 * used to sign in.
	 */
	private static function create_demo_supplier() {
		$email = 'supplier@demo.om';

		if ( 'sample' !== tg_setting( 'source' ) ) {
			self::retire_demo_supplier( $email );

			return;
		}

		if ( email_exists( $email ) ) {
			return;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => 'demo1234',
			'display_name' => 'Al Bahja Trading & Contracting LLC',
			'first_name'   => 'Salim Al Harthy',
			'role'         => TG_Auth::ROLE,
		) );

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		update_user_meta( $user_id, 'tg_company', 'Al Bahja Trading & Contracting LLC' );
		update_user_meta( $user_id, 'tg_contact', 'Salim Al Harthy' );
		update_user_meta( $user_id, 'tg_phone', '+968 2200 0000' );
		update_user_meta( $user_id, 'tg_cr', '1284471' );
		update_user_meta( $user_id, 'tg_country', 'Oman' );
		update_user_meta( $user_id, 'tg_sectors', array( 'Energy & Power', 'Water & Sanitation', 'Construction & Infrastructure' ) );
		update_user_meta( $user_id, 'tg_status', 'verified' );
		update_user_meta( $user_id, TG_Saved::META, array( 'KE-MOEP-2026-0143', 'TZ-MOW-2026-0088' ) );
	}

	/**
	 * Make the published demo password useless without destroying the account,
	 * which would take its saved tenders and history with it. A random password
	 * nobody holds plus the role removed means it cannot sign in or see
	 * anything, and an administrator can still inspect the record.
	 */
	private static function retire_demo_supplier( $email ) {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return;
		}

		if ( get_user_meta( $user->ID, 'tg_demo_retired', true ) ) {
			return;
		}

		wp_set_password( wp_generate_password( 40, true, true ), $user->ID );

		$account = new WP_User( $user->ID );
		$account->remove_role( TG_Auth::ROLE );

		update_user_meta( $user->ID, 'tg_status', 'retired' );
		update_user_meta( $user->ID, 'tg_demo_retired', current_time( 'mysql' ) );
	}

	/**
	 * Run when the admin switches the tender source, so retirement does not
	 * have to wait for the next plugin activation.
	 */
	public static function source_changed( $source ) {
		if ( 'sample' !== $source ) {
			self::retire_demo_supplier( 'supplier@demo.om' );
		}
	}
}
