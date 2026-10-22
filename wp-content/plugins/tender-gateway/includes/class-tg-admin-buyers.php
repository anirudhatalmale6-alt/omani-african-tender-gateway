<?php
/**
 * Buyer accounts, created by the platform team.
 *
 * The administrator never chooses or sees a buyer's password: the buyer is
 * emailed a link to set their own. If email fails, the screen hands the
 * administrator that one-time link to pass on instead.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Admin_Buyers {

	const SLUG = 'tg-buyers';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_tg_buyer_create', array( __CLASS__, 'handle_create' ) );
		add_action( 'admin_post_tg_buyer_invite', array( __CLASS__, 'handle_invite' ) );
	}

	public static function menu() {
		add_submenu_page( 'tender-gateway', 'Buyers', 'Buyers', 'manage_options', self::SLUG, array( __CLASS__, 'screen' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$buyers = get_users( array( 'role' => TG_Buyer::ROLE, 'orderby' => 'registered', 'order' => 'DESC' ) );

		echo '<div class="wrap"><h1>Buyers</h1>';
		self::notice();
		echo '<p class="description">Organisations that publish their own tenders through the buyer portal (' . esc_html( TG_Buyer::portal_url() ) . '). '
			. 'A buyer sees and edits only their own tenders. Every tender they submit comes to you as "Pending Approval" (Tender Gateway, Tenders) and stays off the website until you approve it.</p>';

		echo '<table class="wp-list-table widefat striped" style="max-width:1100px;margin-top:12px;"><thead><tr>';
		echo '<th style="width:34%;">Organisation</th><th style="width:30%;">Contact</th><th style="width:14%;">Tenders</th>';
		echo '<th style="width:22%;"></th>';
		echo '</tr></thead><tbody>';

		if ( ! $buyers ) {
			echo '<tr><td colspan="4">No buyer accounts yet. Add the first one below.</td></tr>';
		}

		foreach ( $buyers as $b ) {
			$count  = count( TG_Buyer::tenders_for( $b->ID ) );

			echo '<tr>';
			echo '<td><strong>' . esc_html( TG_Buyer::organisation( $b->ID ) ) . '</strong></td>';
			echo '<td>' . esc_html( (string) get_user_meta( $b->ID, 'tg_buyer_contact', true ) ) . '<br><span class="description">' . esc_html( $b->user_email ) . '</span>';
			$phone = (string) get_user_meta( $b->ID, 'tg_buyer_phone', true );
			if ( $phone ) {
				echo '<br><span class="description">' . esc_html( $phone ) . '</span>';
			}
			echo '</td>';
			echo '<td>' . (int) $count . '</td>';
			echo '<td><a class="button button-small" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'tg_buyer_invite', 'user' => $b->ID ), admin_url( 'admin-post.php' ) ), 'tg_buyer_invite_' . $b->ID ) ) . '">Resend sign-in email</a></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';

		echo '<h2 style="margin-top:26px;">Add a buyer</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tg_buyer_create' );
		echo '<input type="hidden" name="action" value="tg_buyer_create">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">Organisation</th><td><input name="org" class="regular-text" required placeholder="e.g. Ministry of Commerce, Industry and Investment Promotion">';
		echo '<p class="description">Shown as the buyer on every tender this account publishes. It cannot be changed by the buyer.</p></td></tr>';
		echo '<tr><th scope="row">Contact name</th><td><input name="contact" class="regular-text" required></td></tr>';
		echo '<tr><th scope="row">Email</th><td><input type="email" name="email" class="regular-text" required>';
		echo '<p class="description">They will receive an email with a link to set their own password.</p></td></tr>';
		echo '<tr><th scope="row">Telephone</th><td><input name="phone" class="regular-text"></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Create buyer account' );
		echo '</form></div>';
	}

	public static function handle_create() {
		self::guard( 'tg_buyer_create' );
		$post = wp_unslash( $_POST );

		$id = TG_Buyer::create_account(
			isset( $post['org'] ) ? $post['org'] : '',
			isset( $post['contact'] ) ? $post['contact'] : '',
			isset( $post['email'] ) ? $post['email'] : '',
			isset( $post['phone'] ) ? $post['phone'] : ''
		);

		if ( is_wp_error( $id ) ) {
			self::finish( 'error', $id->get_error_message() );
		}

		self::send_invite( $id, 'Buyer account created for ' . TG_Buyer::organisation( $id ) . '.' );
	}

	public static function handle_invite() {
		$id = isset( $_GET['user'] ) ? (int) $_GET['user'] : 0;
		self::guard( 'tg_buyer_invite_' . $id );

		if ( ! TG_Buyer::is_buyer( $id ) ) {
			self::finish( 'error', 'That buyer no longer exists.' );
		}

		self::send_invite( $id, '' );
	}

	/**
	 * Email the set-password link. If it cannot be sent, show the link once so
	 * the administrator can pass it on another way.
	 */
	private static function send_invite( $id, $prefix ) {
		$link = TG_Buyer::set_password_url( $id );
		$ok   = $link && (bool) apply_filters( 'tg_buyer_send_invite', false, $id, $link );

		if ( $ok ) {
			$user = get_userdata( $id );
			self::finish( 'success', trim( $prefix . ' A sign-in email with a link to set their password has been sent to ' . $user->user_email . '.' ) );
		}

		self::finish( 'error', trim( $prefix . ' The sign-in email could NOT be sent. Send them this one-time link yourself so they can set a password (it works once): ' . $link ) );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( $nonce );
	}

	private static function finish( $type, $message ) {
		set_transient( 'tg_buyers_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function notice() {
		$key = 'tg_buyers_notice_' . get_current_user_id();
		$n   = get_transient( $key );

		if ( ! $n ) {
			return;
		}

		delete_transient( $key );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $n['type'] ? 'error' : 'success', esc_html( $n['message'] ) );
	}
}
