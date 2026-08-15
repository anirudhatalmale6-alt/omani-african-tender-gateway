<?php
/**
 * Front-end supplier registration, login and the members-only gate.
 *
 * Suppliers never see wp-login.php or wp-admin - the whole journey stays on
 * the public site so it can be demonstrated end to end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Auth {

	const ROLE = 'tg_supplier';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_forms' ), 1 );
		add_action( 'wp_logout', array( __CLASS__, 'after_logout' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'hide_admin_bar' ) );
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ) );
	}

	public static function register_role() {
		add_role( self::ROLE, 'Supplier', array( 'read' => true ) );
	}

	public static function is_supplier( $user = null ) {
		$user = $user ? $user : wp_get_current_user();

		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function hide_admin_bar( $show ) {
		return self::is_supplier() ? false : $show;
	}

	/**
	 * Suppliers have no business in wp-admin; send them to their dashboard.
	 */
	public static function block_admin() {
		if ( wp_doing_ajax() || ! self::is_supplier() ) {
			return;
		}

		wp_safe_redirect( tg_page_url( 'dashboard' ) );
		exit;
	}

	public static function after_logout() {
		// Nothing to clean up yet; kept as the hook point for session teardown.
	}

	/**
	 * Errors/notices raised while processing a form, rendered by the templates.
	 */
	public static function add_error( $message ) {
		$notices             = &self::notices_ref();
		$notices['errors'][] = $message;
	}

	public static function add_success( $message ) {
		$notices              = &self::notices_ref();
		$notices['success'][] = $message;
	}

	private static function &notices_ref() {
		static $store = array( 'errors' => array(), 'success' => array() );

		return $store;
	}

	public static function get_notices() {
		return self::notices_ref();
	}

	public static function handle_forms() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			self::handle_logout();

			return;
		}

		$action = isset( $_POST['tg_action'] ) ? sanitize_key( wp_unslash( $_POST['tg_action'] ) ) : '';

		if ( 'login' === $action ) {
			self::do_login();
		} elseif ( 'register' === $action ) {
			self::do_register();
		}
	}

	private static function handle_logout() {
		if ( empty( $_GET['tg_logout'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['tg_logout'] ) ), 'tg_logout' ) ) {
			return;
		}

		wp_logout();
		wp_safe_redirect( home_url( '/' ) );
		exit;
	}

	private static function safe_redirect_target() {
		$raw = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
		$raw = esc_url_raw( $raw );

		if ( ! $raw ) {
			return tg_page_url( 'dashboard' );
		}

		// wp_validate_redirect keeps us on-site even if the parameter is tampered with.
		return wp_validate_redirect( $raw, tg_page_url( 'dashboard' ) );
	}

	private static function do_login() {
		if ( ! isset( $_POST['tg_login_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_login_nonce'] ) ), 'tg_login' ) ) {
			self::add_error( 'Your session expired. Please try again.' );

			return;
		}

		$user = wp_signon( array(
			'user_login'    => isset( $_POST['tg_user'] ) ? sanitize_text_field( wp_unslash( $_POST['tg_user'] ) ) : '',
			'user_password' => isset( $_POST['tg_pass'] ) ? (string) wp_unslash( $_POST['tg_pass'] ) : '',
			'remember'      => ! empty( $_POST['tg_remember'] ),
		), is_ssl() );

		if ( is_wp_error( $user ) ) {
			self::add_error( 'We could not sign you in. Check your email address and password and try again.' );

			return;
		}

		wp_set_current_user( $user->ID );
		wp_safe_redirect( self::safe_redirect_target() );
		exit;
	}

	private static function do_register() {
		if ( ! isset( $_POST['tg_register_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_register_nonce'] ) ), 'tg_register' ) ) {
			self::add_error( 'Your session expired. Please try again.' );

			return;
		}

		$field = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		};

		$company  = $field( 'tg_company' );
		$contact  = $field( 'tg_contact' );
		$email    = sanitize_email( $field( 'tg_email' ) );
		$phone    = $field( 'tg_phone' );
		$cr       = $field( 'tg_cr' );
		$country  = $field( 'tg_country' );
		$password = isset( $_POST['tg_pass'] ) ? (string) wp_unslash( $_POST['tg_pass'] ) : '';
		$sectors  = isset( $_POST['tg_sectors'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tg_sectors'] ) ) : array();

		if ( ! $company || ! $contact || ! $email || ! $password ) {
			self::add_error( 'Please complete every required field.' );

			return;
		}
		if ( ! is_email( $email ) ) {
			self::add_error( 'That email address does not look valid.' );

			return;
		}
		if ( email_exists( $email ) ) {
			self::add_error( 'An account already exists for that email address. Please sign in instead.' );

			return;
		}
		if ( strlen( $password ) < 8 ) {
			self::add_error( 'Please choose a password of at least 8 characters.' );

			return;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $company,
			'first_name'   => $contact,
			'role'         => self::ROLE,
		) );

		if ( is_wp_error( $user_id ) ) {
			self::add_error( 'We could not create the account: ' . $user_id->get_error_message() );

			return;
		}

		update_user_meta( $user_id, 'tg_company', $company );
		update_user_meta( $user_id, 'tg_contact', $contact );
		update_user_meta( $user_id, 'tg_phone', $phone );
		update_user_meta( $user_id, 'tg_cr', $cr );
		update_user_meta( $user_id, 'tg_country', $country ? $country : 'Oman' );
		update_user_meta( $user_id, 'tg_sectors', $sectors );
		update_user_meta( $user_id, 'tg_status', 'verified' );

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );

		wp_safe_redirect( add_query_arg( 'welcome', '1', self::safe_redirect_target() ) );
		exit;
	}

	/**
	 * URL of the login page with a return path baked in.
	 */
	public static function login_url( $return_to = '' ) {
		if ( ! $return_to ) {
			$return_to = home_url( add_query_arg( array() ) );
		}

		return add_query_arg( 'redirect_to', rawurlencode( $return_to ), tg_page_url( 'login' ) );
	}

	public static function register_url( $return_to = '' ) {
		if ( ! $return_to ) {
			$return_to = home_url( add_query_arg( array() ) );
		}

		return add_query_arg( 'redirect_to', rawurlencode( $return_to ), tg_page_url( 'register' ) );
	}

	public static function logout_url() {
		return add_query_arg( 'tg_logout', wp_create_nonce( 'tg_logout' ), home_url( '/' ) );
	}
}
