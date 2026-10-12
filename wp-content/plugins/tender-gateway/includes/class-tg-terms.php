<?php
/**
 * Supplier terms, and the record of who accepted which version and when.
 *
 * The commission is only enforceable because the supplier agreed to it, so
 * acceptance is recorded per version with the time and IP address, and kept
 * as a history rather than overwritten. Changing the terms - including the
 * commission rate or the payment deadline, which are written into them -
 * creates a new version, and every supplier must accept it before their next
 * bid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Terms {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_accept' ), 5 );
		add_action( 'tg_dashboard_primary', array( __CLASS__, 'render_prompt' ), 2 );
	}

	public static function default_text() {
		return "1. These terms apply between Jumla Tender and the supplier registering on the platform.\n\n"
			. "2. Commission. When a tender awarded to the supplier is completed, Jumla Tender charges a commission of {rate}% of the awarded value, plus VAT at the applicable rate.\n\n"
			. "3. Invoicing and payment. The commission is invoiced through the platform when the tender is marked completed. It is payable by bank transfer within {days} days of the invoice date, quoting the invoice number.\n\n"
			. "4. Late payment. While any commission invoice remains unpaid after its due date, the supplier may not submit bids on new tenders. Bids already submitted are not affected.\n\n"
			. "5. Accurate information. The supplier confirms that its company details and documents are accurate, and will keep them up to date.\n\n"
			. "6. Bids. A submitted bid is a genuine offer at the price stated, valid for the period stated in the bid.\n\n"
			. "7. Changes. Jumla Tender may update these terms. Suppliers will be asked to accept the new version before their next bid.";
	}

	public static function raw_text() {
		$text = (string) get_option( 'tg_terms_text', '' );

		return '' !== trim( $text ) ? $text : self::default_text();
	}

	/**
	 * The terms as a supplier reads them, with the live rate and deadline
	 * filled in - so the words can never disagree with what is charged.
	 */
	public static function text() {
		return strtr( self::raw_text(), array(
			'{rate}' => self::number( TG_Commission::rate() ),
			'{days}' => (string) TG_Commission::due_days(),
		) );
	}

	private static function number( $n ) {
		return rtrim( rtrim( number_format( (float) $n, 3, '.', '' ), '0' ), '.' );
	}

	public static function version() {
		return max( 1, (int) get_option( 'tg_terms_version', 1 ) );
	}

	/**
	 * Save new terms. Any change to what a supplier agrees to - the wording,
	 * the rate or the deadline - is a new version.
	 */
	public static function save( $raw_text, $rate, $days ) {
		$before = self::text();

		update_option( 'tg_terms_text', trim( (string) $raw_text ) === trim( self::default_text() ) ? '' : (string) $raw_text );
		update_option( 'tg_commission_rate', max( 0, (float) $rate ) );
		update_option( 'tg_commission_due_days', max( 1, (int) $days ) );

		if ( self::text() !== $before ) {
			update_option( 'tg_terms_version', self::version() + 1 );

			return true;
		}

		return false;
	}

	public static function accepted_version( $user_id ) {
		return (int) get_user_meta( (int) $user_id, 'tg_terms_version', true );
	}

	public static function has_accepted( $user_id ) {
		return $user_id && self::accepted_version( $user_id ) >= self::version();
	}

	public static function accept( $user_id, $ip = '' ) {
		$user_id = (int) $user_id;
		$now     = current_time( 'mysql' );
		$ip      = $ip ? $ip : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

		update_user_meta( $user_id, 'tg_terms_version', self::version() );
		update_user_meta( $user_id, 'tg_terms_accepted_at', $now );

		// History, never overwritten: what they agreed to matters later.
		$log   = get_user_meta( $user_id, 'tg_terms_log', true );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'version' => self::version(),
			'at'      => $now,
			'ip'      => $ip,
			'hash'    => md5( self::text() ),
		);
		update_user_meta( $user_id, 'tg_terms_log', $log );

		// Keep the exact text of each version, so "what did version 3 say?"
		// always has an answer.
		$texts = get_option( 'tg_terms_versions', array() );
		$texts = is_array( $texts ) ? $texts : array();

		if ( ! isset( $texts[ self::version() ] ) ) {
			$texts[ self::version() ] = self::text();
			update_option( 'tg_terms_versions', $texts, false );
		}
	}

	public static function accepted_at( $user_id ) {
		return (string) get_user_meta( (int) $user_id, 'tg_terms_accepted_at', true );
	}

	/* ---------------------------------------------------------------- Front */

	public static function box_html() {
		return '<div class="tg-terms" style="max-height:220px;overflow:auto;border:1px solid #dcdcde;border-radius:4px;'
			. 'padding:12px 14px;background:#fafafa;font-size:0.92em;line-height:1.55;">'
			. wpautop( esc_html( self::text() ) ) . '</div>';
	}

	public static function handle_accept() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		if ( empty( $_POST['tg_terms_action'] ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! TG_Access::is_supplier( $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['tg_terms_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_terms_nonce'] ) ), 'tg_terms_' . $user_id )
			|| empty( $_POST['tg_terms_agree'] ) ) {
			return;
		}

		// The version shown on the page must still be the current one, or the
		// supplier would be recorded as agreeing to words they never saw.
		if ( (int) ( isset( $_POST['tg_terms_version'] ) ? $_POST['tg_terms_version'] : 0 ) !== self::version() ) {
			return;
		}

		self::accept( $user_id );
	}

	public static function render_prompt( $user ) {
		if ( self::has_accepted( $user->ID ) ) {
			return;
		}

		$updated = self::accepted_version( $user->ID ) > 0;

		echo '<div class="tg-notice" style="background:#fdf8e7;border-left:3px solid #8a6d1f;margin-bottom:18px;">';
		echo '<p style="margin:0 0 8px;"><strong>' . ( $updated ? 'Our supplier terms have been updated.' : 'Please accept our supplier terms.' )
			. '</strong> You need to accept them before you can submit a bid.</p>';
		echo self::box_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		echo '<form method="post" style="margin:10px 0 0;">';
		wp_nonce_field( 'tg_terms_' . $user->ID, 'tg_terms_nonce' );
		echo '<input type="hidden" name="tg_terms_action" value="accept">';
		echo '<input type="hidden" name="tg_terms_version" value="' . esc_attr( (string) self::version() ) . '">';
		echo '<label style="display:block;margin:0 0 8px;"><input type="checkbox" name="tg_terms_agree" value="1" required> '
			. 'I have read and accept these terms on behalf of my company.</label>';
		echo '<button type="submit" class="tg-btn tg-btn--accent">Accept terms</button>';
		echo '</form></div>';
	}
}
