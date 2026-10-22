<?php
/**
 * Buyers: organisations such as the Ministry that publish their own tenders.
 *
 * Accounts are created by the platform's administrators, never by public
 * registration - a buyer can put a tender in front of every supplier, so who
 * may do that is the platform's decision.
 *
 * The rules that matter, enforced here and not in the screens:
 *  - A buyer only ever sees and changes their OWN tenders.
 *  - A buyer edits a tender only while it is a Draft, or after the team has
 *    sent it back as Changes Required. Otherwise changes go through the team.
 *  - The buyer name on a tender is always the account's organisation. Nobody
 *    can publish in another organisation's name.
 *  - Every tender goes to the platform team for approval. Nothing a buyer
 *    creates reaches the website until the team approves it - the client's
 *    rule, with no per-account exception.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Buyer {

	const ROLE = 'tg_buyer';

	public static function init() {
		add_shortcode( 'tg_buyer_portal', array( __CLASS__, 'shortcode' ) );
		add_action( 'template_redirect', array( __CLASS__, 'route_dashboard' ), 2 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_forms' ), 5 );
	}

	public static function register_role() {
		add_role( self::ROLE, 'Buyer', array( 'read' => true ) );
	}

	public static function is_buyer( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		$user    = $user_id ? get_userdata( $user_id ) : null;

		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function organisation( $user_id ) {
		$org = trim( (string) get_user_meta( (int) $user_id, 'tg_buyer_org', true ) );

		if ( '' !== $org ) {
			return $org;
		}

		$user = get_userdata( (int) $user_id );

		return $user ? $user->display_name : '';
	}

	public static function owns( $tender, $user_id ) {
		return is_array( $tender ) && ! empty( $tender['owner_id'] ) && (int) $tender['owner_id'] === (int) $user_id;
	}

	/**
	 * A draft, or a tender the team sent back with comments.
	 */
	public static function is_editable( $tender ) {
		return is_array( $tender ) && in_array( $tender['status'], array( TG_Status::DRAFT, TG_Status::CHANGES_REQUIRED ), true );
	}

	public static function portal_url( $args = array() ) {
		return tg_page_url( 'buyer', $args );
	}

	/**
	 * @return array Rows from the tenders table, newest first.
	 */
	public static function tenders_for( $user_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::table() . " WHERE source = %s AND owner_id = %d ORDER BY id DESC",
			TG_Tender::SOURCE,
			(int) $user_id
		), ARRAY_A );
	}

	/* --------------------------------------------------------------- Rules */

	/**
	 * Create or update one of this buyer's draft tenders.
	 *
	 * @return int|WP_Error Tender id.
	 */
	public static function save_tender( $user_id, $data, $tender_id = 0 ) {
		if ( ! self::is_buyer( $user_id ) ) {
			return new WP_Error( 'tg_buyer_only', 'Only a buyer account can create tenders here.' );
		}

		$clean = array();

		foreach ( TG_Tender::editable_fields() as $field ) {
			$clean[ $field ] = isset( $data[ $field ] ) ? $data[ $field ] : '';
		}

		// The organisation is fixed by the account, whatever the form said.
		$clean['buyer'] = self::organisation( $user_id );

		if ( '' === trim( (string) $clean['currency'] ) ) {
			$clean['currency'] = 'OMR';
		}

		if ( $tender_id ) {
			$tender = TG_Tender::get( $tender_id );

			if ( ! $tender || ! self::owns( $tender, $user_id ) ) {
				return new WP_Error( 'tg_buyer_not_owner', 'That tender does not belong to your account.' );
			}

			if ( ! self::is_editable( $tender ) ) {
				return new WP_Error(
					'tg_buyer_locked',
					sprintf( 'This tender is %s, so it can no longer be changed here. Please contact the Jumla Tender team.', strtolower( TG_Status::label( $tender['status'] ) ) )
				);
			}

			$result = TG_Tender::update( $tender_id, $clean );

			return is_wp_error( $result ) ? $result : (int) $tender_id;
		}

		return TG_Tender::create( $clean, (int) $user_id );
	}

	/**
	 * What still has to be filled in before a tender can go out.
	 *
	 * @return string[] Problems, empty when ready.
	 */
	public static function missing_for_submission( $tender ) {
		$missing = array();
		$now     = strtotime( current_time( 'mysql' ) );

		if ( '' === trim( (string) $tender['title'] ) ) {
			$missing[] = 'a title';
		}

		if ( '' === trim( wp_strip_all_tags( (string) $tender['summary'] ) ) ) {
			$missing[] = 'a short summary';
		}

		if ( '' === trim( wp_strip_all_tags( (string) $tender['specifications'] ) ) ) {
			$missing[] = 'the specifications';
		}

		if ( '' === trim( (string) $tender['sector'] ) ) {
			$missing[] = 'a sector';
		}

		if ( empty( $tender['closing_at'] ) ) {
			$missing[] = 'a closing date and time';
		} elseif ( strtotime( $tender['closing_at'] ) <= $now + HOUR_IN_SECONDS ) {
			$missing[] = 'a closing date at least one hour from now';
		}

		if ( ! empty( $tender['opening_at'] ) && ! empty( $tender['closing_at'] )
			&& strtotime( $tender['opening_at'] ) >= strtotime( $tender['closing_at'] ) ) {
			$missing[] = 'an opening time before the closing time';
		}

		return $missing;
	}

	/**
	 * Send a draft to the platform team for approval. It stays off the website
	 * until they approve it.
	 *
	 * @return string|WP_Error 'pending'.
	 */
	public static function submit_tender( $user_id, $tender_id ) {
		$tender = TG_Tender::get( $tender_id );

		if ( ! self::is_buyer( $user_id ) || ! $tender || ! self::owns( $tender, $user_id ) ) {
			return new WP_Error( 'tg_buyer_not_owner', 'That tender does not belong to your account.' );
		}

		if ( ! self::is_editable( $tender ) ) {
			return new WP_Error( 'tg_buyer_locked', 'This tender has already been submitted.' );
		}

		$missing = self::missing_for_submission( $tender );

		if ( $missing ) {
			return new WP_Error( 'tg_buyer_incomplete', 'Before submitting, please add ' . implode( ', ', $missing ) . '.' );
		}

		$r = TG_Tender::move( $tender_id, TG_Status::PENDING, (int) $user_id, TG_Status::CHANGES_REQUIRED === $tender['status'] ? 'Resubmitted by the buyer after changes.' : 'Submitted by the buyer.' );

		return is_wp_error( $r ) ? $r : 'pending';
	}

	/* ------------------------------------------------------------- Routing */

	/**
	 * A buyer who lands on the supplier dashboard belongs in the portal.
	 */
	public static function route_dashboard() {
		if ( ! self::is_buyer() ) {
			return;
		}

		$pages = get_option( 'tg_pages', array() );

		if ( ! empty( $pages['dashboard'] ) && is_page( (int) $pages['dashboard'] ) ) {
			wp_safe_redirect( self::portal_url() );
			exit;
		}
	}

	/* ------------------------------------------------------------- Notices */

	private static function flash( $type, $message ) {
		set_transient( 'tg_buyer_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
	}

	public static function take_flash() {
		$key = 'tg_buyer_flash_' . get_current_user_id();
		$n   = get_transient( $key );

		if ( $n ) {
			delete_transient( $key );
		}

		return $n ? $n : null;
	}

	/* --------------------------------------------------------------- Forms */

	public static function handle_forms() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		$action = isset( $_POST['tg_buyer_action'] ) ? sanitize_key( wp_unslash( $_POST['tg_buyer_action'] ) ) : '';

		if ( ! in_array( $action, array( 'save', 'doc_delete' ), true ) ) {
			return;
		}

		$user_id   = get_current_user_id();
		$tender_id = isset( $_POST['tender_id'] ) ? (int) $_POST['tender_id'] : 0;

		if ( ! self::is_buyer( $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['tg_buyer_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_buyer_nonce'] ) ), 'tg_buyer_' . $action . '_' . $tender_id ) ) {
			self::flash( 'error', 'Your session expired. Please try again.' );
			wp_safe_redirect( self::portal_url( $tender_id ? array( 'edit' => $tender_id ) : array( 'new' => 1 ) ) );
			exit;
		}

		if ( 'doc_delete' === $action ) {
			self::do_doc_delete( $user_id, $tender_id );
		}

		self::do_save( $user_id, $tender_id );
	}

	private static function do_save( $user_id, $tender_id ) {
		$post   = wp_unslash( $_POST );
		$result = self::save_tender( $user_id, $post, $tender_id );

		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			wp_safe_redirect( self::portal_url( $tender_id ? array( 'edit' => $tender_id ) : array( 'new' => 1 ) ) );
			exit;
		}

		$tender_id = (int) $result;
		$problems  = self::store_uploads( $user_id, $tender_id );

		if ( empty( $post['submit_now'] ) ) {
			self::flash( $problems ? 'error' : 'success', 'Draft saved. Nothing is visible to suppliers until you submit it.' . ( $problems ? ' ' . implode( ' ', $problems ) : '' ) );
			wp_safe_redirect( self::portal_url( array( 'edit' => $tender_id ) ) );
			exit;
		}

		$outcome = self::submit_tender( $user_id, $tender_id );

		if ( is_wp_error( $outcome ) ) {
			self::flash( 'error', 'Your draft is saved, but it was not submitted. ' . $outcome->get_error_message() );
			wp_safe_redirect( self::portal_url( array( 'edit' => $tender_id ) ) );
			exit;
		}

		$tender = TG_Tender::get( $tender_id );

		$msg = 'Submitted. ' . $tender['external_id'] . ' is with the Jumla Tender team for approval - you will be emailed as soon as it is published.';

		self::flash( 'success', $msg . ( $problems ? ' ' . implode( ' ', $problems ) : '' ) );
		wp_safe_redirect( self::portal_url( array( 'tender' => $tender_id ) ) );
		exit;
	}

	/**
	 * @return string[] Messages for files that were not accepted.
	 */
	private static function store_uploads( $user_id, $tender_id ) {
		if ( empty( $_FILES['tg_docs'] ) || ! is_array( $_FILES['tg_docs']['name'] ) ) {
			return array();
		}

		$problems = array();

		foreach ( array_keys( $_FILES['tg_docs']['name'] ) as $i ) {
			if ( '' === $_FILES['tg_docs']['name'][ $i ] ) {
				continue;
			}

			$file = array(
				'name'     => $_FILES['tg_docs']['name'][ $i ],
				'type'     => $_FILES['tg_docs']['type'][ $i ],
				'tmp_name' => $_FILES['tg_docs']['tmp_name'][ $i ],
				'error'    => $_FILES['tg_docs']['error'][ $i ],
				'size'     => $_FILES['tg_docs']['size'][ $i ],
			);

			$stored = TG_Docs::store( $file, 'tender', $tender_id, '', $user_id );

			if ( is_wp_error( $stored ) ) {
				$problems[] = sanitize_file_name( $file['name'] ) . ' was not attached: ' . $stored->get_error_message();
			}
		}

		return $problems;
	}

	private static function do_doc_delete( $user_id, $tender_id ) {
		$doc_id = isset( $_POST['doc_id'] ) ? (int) $_POST['doc_id'] : 0;
		$doc    = TG_Docs::get( $doc_id );
		$tender = TG_Tender::get( $tender_id );

		if ( $doc && $tender && 'tender' === $doc['owner_type'] && (int) $doc['owner_id'] === (int) $tender_id
			&& self::owns( $tender, $user_id ) && self::is_editable( $tender ) ) {
			TG_Docs::delete( $doc_id );
			self::flash( 'success', 'Document removed.' );
		} else {
			self::flash( 'error', 'That document cannot be removed.' );
		}

		wp_safe_redirect( self::portal_url( array( 'edit' => $tender_id ) ) );
		exit;
	}

	/* ------------------------------------------------------------ Portal */

	public static function shortcode() {
		if ( ! is_user_logged_in() ) {
			return TG_Shortcodes::render( 'buyer-locked' );
		}

		$user_id = get_current_user_id();

		if ( ! self::is_buyer( $user_id ) ) {
			return '<div class="tg-notice">This area is for buyer accounts. '
				. ( TG_Access::is_supplier( $user_id ) ? 'Your supplier dashboard is <a href="' . esc_url( tg_page_url( 'dashboard' ) ) . '">here</a>.' : '' )
				. '</div>';
		}

		$flash = self::take_flash();

		if ( ! empty( $_GET['new'] ) ) {
			return TG_Shortcodes::render( 'buyer-tender-form', array( 'tender' => null, 'user_id' => $user_id, 'flash' => $flash ) );
		}

		foreach ( array( 'edit', 'tender' ) as $key ) {
			if ( empty( $_GET[ $key ] ) ) {
				continue;
			}

			$tender = TG_Tender::get( (int) $_GET[ $key ] );

			// Somebody else's tender is reported exactly like a missing one.
			if ( ! $tender || ! self::owns( $tender, $user_id ) ) {
				return TG_Shortcodes::render( 'buyer-portal', array( 'user_id' => $user_id, 'flash' => array( 'type' => 'error', 'message' => 'That tender was not found in your account.' ) ) );
			}

			if ( 'edit' === $key && self::is_editable( $tender ) ) {
				return TG_Shortcodes::render( 'buyer-tender-form', array( 'tender' => $tender, 'user_id' => $user_id, 'flash' => $flash ) );
			}

			return TG_Shortcodes::render( 'buyer-tender-view', array( 'tender' => $tender, 'user_id' => $user_id, 'flash' => $flash ) );
		}

		return TG_Shortcodes::render( 'buyer-portal', array( 'user_id' => $user_id, 'flash' => $flash ) );
	}

	/* ---------------------------------------------------------- Accounts */

	/**
	 * Create a buyer account. Used by the admin Buyers screen.
	 *
	 * @return int|WP_Error User id.
	 */
	public static function create_account( $org, $contact, $email, $phone = '' ) {
		$org     = sanitize_text_field( $org );
		$contact = sanitize_text_field( $contact );
		$email   = sanitize_email( $email );

		if ( '' === $org || '' === $contact || ! is_email( $email ) ) {
			return new WP_Error( 'tg_buyer_fields', 'Please enter the organisation, the contact name and a valid email address.' );
		}

		if ( email_exists( $email ) ) {
			return new WP_Error( 'tg_buyer_exists', 'An account already exists for that email address.' );
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24, true, true ),
			'display_name' => $org,
			'first_name'   => $contact,
			'role'         => self::ROLE,
		) );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, 'tg_buyer_org', $org );
		update_user_meta( $user_id, 'tg_buyer_contact', $contact );
		update_user_meta( $user_id, 'tg_buyer_phone', sanitize_text_field( $phone ) );

		return (int) $user_id;
	}

	/**
	 * A link that lets the buyer choose their own password. Nobody - including
	 * the administrator who created the account - ever knows it.
	 */
	public static function set_password_url( $user_id ) {
		$user = get_userdata( (int) $user_id );

		if ( ! $user ) {
			return '';
		}

		$key = get_password_reset_key( $user );

		if ( is_wp_error( $key ) ) {
			return '';
		}

		return network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' );
	}
}
