<?php
/**
 * File uploads - built once here and reused for supplier registration
 * documents, tender requirement documents and (from M2) bid attachments.
 *
 * Confidentiality drove the design. The specification says tender information
 * is confidential and only subscribed suppliers may see full details, so
 * uploads must not land in wp-content/uploads where anyone holding the URL can
 * read them. Instead files go to a protected directory that the web server is
 * told to deny, and every download runs through check_access() first.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Docs {

	const DIR         = 'tg-secure';
	const MAX_BYTES   = 10485760; // 10 MB
	const OWNER_TYPES = array( 'tender', 'supplier', 'bid' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'protect_directory' ) );
		add_action( 'admin_post_tg_download', array( __CLASS__, 'serve' ) );
		add_action( 'admin_post_nopriv_tg_download', array( __CLASS__, 'deny' ) );
	}

	public static function allowed_types() {
		return array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'xls'  => 'application/vnd.ms-excel',
			'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
		);
	}

	public static function base_dir() {
		$uploads = wp_upload_dir();

		return trailingslashit( $uploads['basedir'] ) . self::DIR;
	}

	/**
	 * Deny-all rules, written once. Apache reads .htaccess; nginx does not, so
	 * the download endpoint is the real protection and this is defence in
	 * depth rather than the only lock.
	 */
	public static function protect_directory() {
		$dir = self::base_dir();

		if ( file_exists( $dir . '/.htaccess' ) ) {
			return;
		}

		wp_mkdir_p( $dir );

		file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" );
		file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}

	/**
	 * Store one uploaded file.
	 *
	 * @param array  $file       One entry from $_FILES.
	 * @param string $owner_type tender|supplier|bid
	 * @return int|WP_Error Document id.
	 */
	public static function store( $file, $owner_type, $owner_id, $label = '', $user_id = 0 ) {
		global $wpdb;

		if ( ! in_array( $owner_type, self::OWNER_TYPES, true ) ) {
			return new WP_Error( 'tg_doc_owner', 'Unknown document owner.' );
		}

		if ( ! isset( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'tg_doc_upload', 'No file was received.' );
		}

		if ( ! empty( $file['error'] ) ) {
			return new WP_Error( 'tg_doc_upload', 'The file did not upload correctly. Please try again.' );
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			return new WP_Error( 'tg_doc_size', 'That file is larger than 10 MB. Please upload a smaller file.' );
		}

		// Trust the file, not the browser: wp_check_filetype_and_ext inspects
		// the contents rather than believing the submitted name or mime type.
		$checked = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], self::allowed_types() );

		if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
			return new WP_Error(
				'tg_doc_type',
				'That file type is not accepted. Please upload a PDF, Word or Excel document, or a JPG or PNG image.'
			);
		}

		self::protect_directory();

		$sub = $owner_type . '/' . gmdate( 'Y/m' );
		$dir = trailingslashit( self::base_dir() ) . $sub;

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'tg_doc_dir', 'Could not prepare the upload folder.' );
		}

		// Random stored name: the original filename is kept for display only,
		// so nothing about the file is guessable on disk.
		$stored = wp_generate_password( 24, false, false ) . '.' . $checked['ext'];

		if ( ! @move_uploaded_file( $file['tmp_name'], trailingslashit( $dir ) . $stored ) ) {
			return new WP_Error( 'tg_doc_move', 'Could not save the uploaded file.' );
		}

		@chmod( trailingslashit( $dir ) . $stored, 0640 );

		$wpdb->insert( TG_Store::docs_table(), array(
			'owner_type'  => $owner_type,
			'owner_id'    => (int) $owner_id,
			'uploaded_by' => $user_id ? (int) $user_id : null,
			'label'       => sanitize_text_field( $label ),
			'path'        => $sub . '/' . $stored,
			'filename'    => sanitize_file_name( $file['name'] ),
			'mime'        => $checked['type'],
			'filesize'    => (int) $file['size'],
			'token'       => wp_generate_password( 32, false, false ),
			'created_at'  => current_time( 'mysql' ),
		) );

		return (int) $wpdb->insert_id;
	}

	public static function get( $id ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::docs_table() . " WHERE id = %d",
			(int) $id
		), ARRAY_A );

		return $row ? $row : null;
	}

	public static function for_owner( $owner_type, $owner_id ) {
		global $wpdb;

		return (array) $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . TG_Store::docs_table() . " WHERE owner_type = %s AND owner_id = %d ORDER BY id ASC",
			$owner_type,
			(int) $owner_id
		), ARRAY_A );
	}

	public static function url( $doc ) {
		return add_query_arg( array(
			'action' => 'tg_download',
			'doc'    => (int) $doc['id'],
			'k'      => $doc['token'],
		), admin_url( 'admin-post.php' ) );
	}

	public static function delete( $id ) {
		global $wpdb;

		$doc = self::get( $id );

		if ( ! $doc ) {
			return false;
		}

		$path = trailingslashit( self::base_dir() ) . $doc['path'];

		if ( file_exists( $path ) ) {
			@unlink( $path );
		}

		return (bool) $wpdb->delete( TG_Store::docs_table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Who may read a given document.
	 *
	 * Deliberately conservative for Phase 1: administrators, the person who
	 * uploaded it, and the owner of the tender it belongs to. Supplier access
	 * to tender documents is gated on an active subscription, which arrives in
	 * M2 - until then the filter below simply refuses, which is the safe
	 * direction to be wrong in.
	 */
	public static function check_access( $doc, $user_id ) {
		if ( ! $doc ) {
			return false;
		}

		if ( user_can( $user_id, 'manage_options' ) ) {
			return true;
		}

		if ( $user_id && (int) $doc['uploaded_by'] === (int) $user_id ) {
			return true;
		}

		if ( 'tender' === $doc['owner_type'] ) {
			$tender = TG_Tender::get( $doc['owner_id'] );

			if ( $tender && $user_id && (int) $tender['owner_id'] === (int) $user_id ) {
				return true;
			}
		}

		/**
		 * M2 hooks subscription checks on here. Default false - a document is
		 * refused unless something explicitly allows it.
		 */
		return (bool) apply_filters( 'tg_doc_can_read', false, $doc, $user_id );
	}

	public static function serve() {
		$id    = isset( $_GET['doc'] ) ? (int) $_GET['doc'] : 0;
		$token = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
		$doc   = self::get( $id );

		if ( ! $doc || ! hash_equals( (string) $doc['token'], $token ) ) {
			self::deny();
		}

		if ( ! self::check_access( $doc, get_current_user_id() ) ) {
			self::deny();
		}

		$path = trailingslashit( self::base_dir() ) . $doc['path'];

		if ( ! file_exists( $path ) ) {
			wp_die( 'That file is no longer available.', 'File missing', array( 'response' => 404 ) );
		}

		nocache_headers();
		header( 'Content-Type: ' . $doc['mime'] );
		header( 'Content-Length: ' . (int) $doc['filesize'] );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( $doc['filename'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path );
		exit;
	}

	public static function deny() {
		wp_die(
			'You do not have permission to open this document.',
			'Not permitted',
			array( 'response' => 403 )
		);
	}
}
