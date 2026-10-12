<?php
/**
 * Company documents a supplier provides for verification - the CR certificate,
 * trade licence and so on - and the verification status that goes with them.
 *
 * Storage is TG_Docs, the same private store tenders and bids use, so these
 * files are never in a public folder and are served only to the supplier who
 * uploaded them and to an administrator. TG_Access refuses every other reader
 * for owner type "supplier" by default.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Supplier_Docs {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_upload' ), 5 );
		// Above the subscription panel: an unverified supplier's first job is
		// to get verified, and that is what this tells them how to do.
		add_action( 'tg_dashboard_primary', array( __CLASS__, 'render_status' ), 1 );
		add_action( 'tg_dashboard_profile', array( __CLASS__, 'render_section' ) );
	}

	public static function types() {
		return array(
			'cr'      => 'Commercial Registration (CR) certificate',
			'licence' => 'Trade or municipality licence',
			'vat'     => 'VAT registration certificate',
			'other'   => 'Other supporting document',
		);
	}

	public static function for_supplier( $user_id ) {
		return TG_Docs::for_owner( 'supplier', (int) $user_id );
	}

	/* -------------------------------------------------------------- Notices */

	private static function notice( $type = null, $message = null ) {
		static $store = array();

		if ( null !== $type ) {
			$store[] = array( 'type' => $type, 'message' => $message );

			return array();
		}

		return $store;
	}

	/* --------------------------------------------------------------- Upload */

	public static function handle_upload() {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
			return;
		}

		if ( ! isset( $_POST['tg_sdoc_action'] ) || 'upload' !== sanitize_key( wp_unslash( $_POST['tg_sdoc_action'] ) ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( ! TG_Access::is_supplier( $user_id ) ) {
			return;
		}

		if ( ! isset( $_POST['tg_sdoc_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tg_sdoc_nonce'] ) ), 'tg_sdoc_' . $user_id ) ) {
			self::notice( 'error', 'Your session expired. Please try again.' );

			return;
		}

		$type  = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : 'other';
		$types = self::types();
		$label = isset( $types[ $type ] ) ? $types[ $type ] : $types['other'];

		if ( empty( $_FILES['tg_sdoc'] ) || empty( $_FILES['tg_sdoc']['name'] ) ) {
			self::notice( 'error', 'Please choose a file to upload.' );

			return;
		}

		$result = TG_Docs::store( $_FILES['tg_sdoc'], 'supplier', $user_id, $label, $user_id ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated inside TG_Docs::store().

		if ( is_wp_error( $result ) ) {
			self::notice( 'error', $result->get_error_message() );

			return;
		}

		do_action( 'tg_supplier_document_uploaded', (int) $result, $user_id );

		self::notice( 'success', $label . ' uploaded. Our team will review it as part of verifying your company.' );
	}

	/* ------------------------------------------------------------ Dashboard */

	/**
	 * A one-line verification status, only while there is something to do.
	 */
	public static function render_status( $user ) {
		$status = TG_Admin_Suppliers::status_of( $user->ID );

		if ( TG_Admin_Suppliers::VERIFIED === $status ) {
			return;
		}

		$has_docs = (bool) self::for_supplier( $user->ID );

		if ( TG_Admin_Suppliers::PENDING === $status ) {
			$text = $has_docs
				? '<strong>Your company is awaiting verification.</strong> We have your documents and will review them shortly. You can browse tenders in the meantime; bidding opens once you are verified.'
				: '<strong>Your company is awaiting verification.</strong> Please upload your CR certificate under Company documents below - verification is how we confirm who you are, and bidding opens once it is done.';
			$colour = '#2d6cb5';
			$bg     = '#eef4fb';
		} elseif ( TG_Admin_Suppliers::REJECTED === $status ) {
			$text   = '<strong>Your verification was not approved.</strong> Please contact us. You can upload corrected documents below.';
			$colour = '#8a2424';
			$bg     = '#fbeaea';
		} else {
			$text   = '<strong>Your account is suspended.</strong> Bidding is closed while it is suspended; bids you have already placed are unaffected. Please contact us.';
			$colour = '#50575e';
			$bg     = '#f0f0f1';
		}

		printf(
			'<div class="tg-notice" style="background:%s;border-left:3px solid %s;margin-bottom:18px;">%s</div>',
			esc_attr( $bg ),
			esc_attr( $colour ),
			wp_kses( $text, array( 'strong' => array() ) )
		);
	}

	public static function render_section( $user ) {
		$docs   = self::for_supplier( $user->ID );
		$status = TG_Admin_Suppliers::status_of( $user->ID );

		echo '<div class="tg-sdocs" style="margin-top:22px;">';
		echo '<h3 style="margin:0 0 6px;">Company documents</h3>';
		echo '<p class="tg-note" style="margin:0 0 12px;">Verification status: <strong>'
			. esc_html( TG_Admin_Suppliers::label( $status ) ) . '</strong>. '
			. 'Documents are stored privately - only you and our team can open them.</p>';

		foreach ( self::notice() as $n ) {
			printf(
				'<div class="tg-notice tg-notice--%s">%s</div>',
				'error' === $n['type'] ? 'error' : 'success',
				esc_html( $n['message'] )
			);
		}

		if ( $docs ) {
			echo '<ul class="tg-sdocs__list" style="margin:0 0 14px;padding-left:18px;">';

			foreach ( $docs as $doc ) {
				printf(
					'<li><a href="%s">%s</a> <span class="tg-note">- %s, uploaded %s</span></li>',
					esc_url( TG_Docs::url( $doc ) ),
					esc_html( $doc['filename'] ),
					esc_html( $doc['label'] ? $doc['label'] : 'Document' ),
					esc_html( date_i18n( 'j M Y', strtotime( $doc['created_at'] ) ) )
				);
			}

			echo '</ul>';
		} else {
			echo '<p class="tg-note" style="margin:0 0 12px;">No documents uploaded yet.</p>';
		}

		echo '<form method="post" enctype="multipart/form-data" class="tg-sdocs__form" '
			. 'style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';
		wp_nonce_field( 'tg_sdoc_' . $user->ID, 'tg_sdoc_nonce' );
		echo '<input type="hidden" name="tg_sdoc_action" value="upload">';
		echo '<select name="doc_type" aria-label="Document type">';

		foreach ( self::types() as $key => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $label ) );
		}

		echo '</select>';
		echo '<input type="file" name="tg_sdoc" required aria-label="Choose file">';
		echo '<button type="submit" class="tg-btn tg-btn--primary">Upload</button>';
		echo '</form>';
		echo '<p class="tg-note" style="margin:8px 0 0;">PDF, Word, Excel, JPG or PNG, up to 10 MB.</p>';
		echo '</div>';
	}
}
