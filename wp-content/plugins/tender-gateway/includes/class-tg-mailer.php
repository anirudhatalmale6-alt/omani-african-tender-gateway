<?php
/**
 * Outgoing email, sent through an external service rather than the server.
 *
 * The host has outgoing mail switched off entirely, so wp_mail() on its own
 * delivers nothing. Once an API key is entered, every wp_mail() on the site -
 * ours and WordPress's own (password resets included) - is handed to Brevo
 * over HTTPS, which the host does allow.
 *
 * Two rules:
 *  - wp_mail() returns true only when the service accepted the message. The
 *    renewal reminders rely on that to know whether to retry, so a failure
 *    must never be reported as a success.
 *  - Every attempt is logged with its real outcome, so "did that email go?"
 *    is answered by the log, not by hope.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Mailer {

	const SLUG     = 'tg-email';
	const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';
	const LOG_MAX  = 100;

	public static function init() {
		add_filter( 'pre_wp_mail', array( __CLASS__, 'send' ), 10, 2 );
		// When no key is set, WordPress tries the server's own mail system.
		// Record what actually happened rather than guessing in advance.
		add_action( 'wp_mail_failed', array( __CLASS__, 'on_server_failed' ) );
		add_action( 'wp_mail_succeeded', array( __CLASS__, 'on_server_succeeded' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 11 );
		add_action( 'admin_post_tg_mail_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_tg_mail_test', array( __CLASS__, 'handle_test' ) );
	}

	/* ------------------------------------------------------------- Settings */

	public static function api_key() {
		return trim( (string) get_option( 'tg_mail_api_key', '' ) );
	}

	public static function configured() {
		return '' !== self::api_key() && is_email( self::from_email() );
	}

	public static function from_name() {
		$name = trim( (string) get_option( 'tg_mail_from_name', '' ) );

		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	public static function from_email() {
		return trim( (string) get_option( 'tg_mail_from', '' ) );
	}

	public static function reply_to() {
		return trim( (string) get_option( 'tg_mail_reply_to', '' ) );
	}

	/**
	 * Where admin alerts go. Falls back to the WordPress admin address.
	 *
	 * @return string[]
	 */
	public static function admin_recipients() {
		$raw  = (string) get_option( 'tg_mail_admin', '' );
		$list = array_filter( array_map( 'trim', preg_split( '/[,;\s]+/', $raw ) ), 'is_email' );

		if ( ! $list ) {
			$fallback = get_option( 'admin_email' );
			$list     = is_email( $fallback ) ? array( $fallback ) : array();
		}

		return array_values( array_unique( $list ) );
	}

	/* ------------------------------------------------------------ Transport */

	/**
	 * pre_wp_mail: send through the service when configured.
	 *
	 * @param null|bool $return Null to let WordPress continue; anything else
	 *                          short-circuits wp_mail() with that result.
	 */
	public static function send( $return, $atts ) {
		// Another filter has already decided; respect it.
		if ( null !== $return ) {
			return $return;
		}

		$to      = self::parse_recipients( isset( $atts['to'] ) ? $atts['to'] : '' );
		$subject = isset( $atts['subject'] ) ? (string) $atts['subject'] : '';
		$message = isset( $atts['message'] ) ? (string) $atts['message'] : '';

		if ( ! self::configured() ) {
			// Not set up: let WordPress try its own route. The outcome is
			// logged by on_server_failed() / on_server_succeeded().
			return null;
		}

		if ( ! $to ) {
			self::log( $to, $subject, false, 'No valid recipient.' );

			return false;
		}

		$is_html = self::is_html( $atts, $message );

		$payload = array(
			'sender'  => array( 'name' => self::from_name(), 'email' => self::from_email() ),
			'to'      => array_map( function ( $email ) {
				return array( 'email' => $email );
			}, $to ),
			'subject' => $subject,
		);

		if ( $is_html ) {
			$payload['htmlContent'] = $message;
		} else {
			$payload['textContent'] = $message;
		}

		if ( is_email( self::reply_to() ) ) {
			$payload['replyTo'] = array( 'email' => self::reply_to() );
		}

		$response = wp_remote_post( self::ENDPOINT, array(
			'timeout' => 15,
			'headers' => array(
				'api-key'      => self::api_key(),
				'content-type' => 'application/json',
				'accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
		) );

		if ( is_wp_error( $response ) ) {
			self::log( $to, $subject, false, 'Could not reach the email service: ' . $response->get_error_message() );

			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code >= 200 && $code < 300 ) {
			self::log( $to, $subject, true, '' );

			return true;
		}

		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$error = is_array( $body ) && ! empty( $body['message'] ) ? $body['message'] : 'HTTP ' . $code;

		self::log( $to, $subject, false, 'Rejected by the email service: ' . $error );

		return false;
	}

	/**
	 * @return string[]
	 */
	public static function parse_recipients( $to ) {
		$list = is_array( $to ) ? $to : explode( ',', (string) $to );
		$out  = array();

		foreach ( $list as $item ) {
			$item = trim( (string) $item );

			// "Name <email@example.com>" as well as a bare address.
			if ( preg_match( '/<([^>]+)>/', $item, $m ) ) {
				$item = trim( $m[1] );
			}

			if ( is_email( $item ) ) {
				$out[] = $item;
			}
		}

		return array_values( array_unique( $out ) );
	}

	private static function is_html( $atts, $message ) {
		$headers = isset( $atts['headers'] ) ? $atts['headers'] : '';
		$headers = is_array( $headers ) ? implode( "\n", $headers ) : (string) $headers;

		if ( preg_match( '/content-type:\s*text\/html/i', $headers ) ) {
			return true;
		}

		return 'text/html' === apply_filters( 'wp_mail_content_type', 'text/plain' );
	}

	public static function on_server_failed( $error ) {
		$data = is_wp_error( $error ) ? (array) $error->get_error_data() : array();

		self::log(
			self::parse_recipients( isset( $data['to'] ) ? $data['to'] : '' ),
			isset( $data['subject'] ) ? $data['subject'] : '',
			false,
			'Email service not configured, and the server could not send it itself'
				. ( is_wp_error( $error ) ? ': ' . $error->get_error_message() : '.' )
		);
	}

	public static function on_server_succeeded( $data ) {
		self::log(
			self::parse_recipients( isset( $data['to'] ) ? $data['to'] : '' ),
			isset( $data['subject'] ) ? $data['subject'] : '',
			true,
			'Handed to the server\'s own mail system, not Brevo. On hosting with email disabled it may still never arrive.'
		);
	}

	/* ------------------------------------------------------------------ Log */

	private static function log( $to, $subject, $ok, $error ) {
		$log = get_option( 'tg_mail_log', array() );
		$log = is_array( $log ) ? $log : array();

		array_unshift( $log, array(
			'at'      => current_time( 'mysql' ),
			'to'      => implode( ', ', (array) $to ),
			'subject' => wp_strip_all_tags( (string) $subject ),
			'ok'      => $ok ? 1 : 0,
			'error'   => (string) $error,
		) );

		update_option( 'tg_mail_log', array_slice( $log, 0, self::LOG_MAX ), false );
	}

	public static function log_entries() {
		$log = get_option( 'tg_mail_log', array() );

		return is_array( $log ) ? $log : array();
	}

	/* ---------------------------------------------------------------- Admin */

	public static function menu() {
		add_submenu_page( 'tender-gateway', 'Email', 'Email', 'manage_options', self::SLUG, array( __CLASS__, 'screen' ) );
	}

	public static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function screen() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		$key  = self::api_key();
		$mask = $key ? str_repeat( '•', 12 ) . substr( $key, -4 ) : '';

		echo '<div class="wrap"><h1>Email</h1>';
		self::notice();

		if ( self::configured() ) {
			echo '<div class="notice notice-success inline"><p><strong>Email is switched on.</strong> Messages are sent through Brevo. Use "Send test email" to confirm it is working.</p></div>';
		} else {
			echo '<div class="notice notice-warning inline"><p><strong>Email is not switched on yet.</strong> Your hosting does not allow the server to send email itself, so nothing reaches anyone until a Brevo API key and a sender address are entered below.</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tg_mail_save' );
		echo '<input type="hidden" name="action" value="tg_mail_save">';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="tg_mail_api_key">Brevo API key</label></th><td>';
		echo '<input type="password" id="tg_mail_api_key" name="api_key" class="regular-text" autocomplete="new-password" value="" placeholder="' . esc_attr( $mask ? 'Saved: ' . $mask : 'Paste the key here' ) . '">';
		echo '<p class="description">From Brevo: SMTP &amp; API, then API Keys. Leave this empty to keep the saved key. ';
		echo 'The key is never shown again once saved.</p>';

		if ( $key ) {
			echo '<label><input type="checkbox" name="remove_key" value="1"> Remove the saved key</label>';
		}

		echo '</td></tr>';

		$field = function ( $id, $label, $value, $help, $placeholder = '' ) {
			echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			echo '<input type="text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '" class="regular-text" value="' . esc_attr( $value ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
			echo '<p class="description">' . esc_html( $help ) . '</p></td></tr>';
		};

		$field( 'from_name', 'Sender name', (string) get_option( 'tg_mail_from_name', '' ), 'The name suppliers see the email coming from.', get_bloginfo( 'name' ) );
		$field( 'from_email', 'Sender address', self::from_email(), 'Must be on the domain you verified in Brevo, for example noreply@jumlatender.om.', 'noreply@jumlatender.om' );
		$field( 'reply_to', 'Replies go to', self::reply_to(), 'A real inbox your team reads. When a supplier presses Reply, the message goes here.' );
		$field( 'admin_to', 'Admin alerts go to', (string) get_option( 'tg_mail_admin', '' ), 'One or more addresses, separated by commas. Leave empty to use the WordPress admin address (' . get_option( 'admin_email' ) . ').' );

		echo '</tbody></table>';
		submit_button( 'Save email settings' );
		echo '</form>';

		echo '<h2>Send a test</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'tg_mail_test' );
		echo '<input type="hidden" name="action" value="tg_mail_test">';
		echo '<p><input type="email" name="to" class="regular-text" required value="' . esc_attr( wp_get_current_user()->user_email ) . '"> ';
		submit_button( 'Send test email', 'secondary', 'submit', false );
		echo '</p><p class="description">The result below says whether Brevo actually accepted it, and why not if it did not.</p></form>';

		self::render_log();
		echo '</div>';
	}

	private static function render_log() {
		$log = self::log_entries();

		echo '<h2>Delivery log</h2>';

		if ( ! $log ) {
			echo '<p class="description">No email has been attempted yet.</p>';

			return;
		}

		echo '<table class="wp-list-table widefat striped" style="max-width:1100px;"><thead><tr>';
		echo '<th style="width:16%;">When</th><th style="width:24%;">To</th><th style="width:34%;">Subject</th><th style="width:26%;">Result</th>';
		echo '</tr></thead><tbody>';

		foreach ( array_slice( $log, 0, 40 ) as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( date_i18n( 'j M Y, H:i', strtotime( $row['at'] ) ) ) . '</td>';
			echo '<td>' . esc_html( $row['to'] ) . '</td>';
			echo '<td>' . esc_html( $row['subject'] ) . '</td>';
			echo '<td>' . ( $row['ok']
				? '<span style="color:#1d7a3f;font-weight:600;">Sent</span>'
				: '<span style="color:#8a2424;font-weight:600;">Not sent</span><br><span class="description">' . esc_html( $row['error'] ) . '</span>' ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	public static function handle_save() {
		self::guard( 'tg_mail_save' );

		$post = wp_unslash( $_POST );

		if ( ! empty( $post['remove_key'] ) ) {
			delete_option( 'tg_mail_api_key' );
		} elseif ( ! empty( $post['api_key'] ) ) {
			update_option( 'tg_mail_api_key', sanitize_text_field( $post['api_key'] ), false );
		}

		update_option( 'tg_mail_from_name', sanitize_text_field( isset( $post['from_name'] ) ? $post['from_name'] : '' ) );
		update_option( 'tg_mail_from', sanitize_email( isset( $post['from_email'] ) ? $post['from_email'] : '' ) );
		update_option( 'tg_mail_reply_to', sanitize_email( isset( $post['reply_to'] ) ? $post['reply_to'] : '' ) );
		update_option( 'tg_mail_admin', sanitize_text_field( isset( $post['admin_to'] ) ? $post['admin_to'] : '' ) );

		$warning = '';

		if ( '' !== self::from_email() && ! is_email( self::from_email() ) ) {
			$warning = ' The sender address is not a valid email address.';
		}

		self::finish( 'success', 'Email settings saved.' . $warning );
	}

	public static function handle_test() {
		self::guard( 'tg_mail_test' );

		$to = sanitize_email( isset( $_POST['to'] ) ? wp_unslash( $_POST['to'] ) : '' );

		if ( ! is_email( $to ) ) {
			self::finish( 'error', 'Please enter a valid address to send the test to.' );
		}

		$ok = wp_mail(
			$to,
			sprintf( '[%s] Test email', get_bloginfo( 'name' ) ),
			"This is a test from the Jumla Tender platform.\n\nIf you are reading this, supplier notifications and renewal reminders will reach their recipients.\n"
		);

		if ( $ok ) {
			self::finish( 'success', 'Brevo accepted the test email for ' . $to . '. Check that inbox - and the spam folder, until the domain records are in place.' );
		}

		$log  = self::log_entries();
		$why  = $log ? $log[0]['error'] : '';

		self::finish( 'error', 'The test email was NOT sent. ' . $why );
	}

	private static function guard( $nonce ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not permitted.' );
		}

		check_admin_referer( $nonce );
	}

	private static function finish( $type, $message ) {
		set_transient( 'tg_mail_notice_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 60 );
		wp_safe_redirect( self::url() );
		exit;
	}

	private static function notice() {
		$key    = 'tg_mail_notice_' . get_current_user_id();
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
