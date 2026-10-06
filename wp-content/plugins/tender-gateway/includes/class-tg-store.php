<?php
/**
 * Local tender store.
 *
 * TendersOnTime accepts exactly one request filter - the tender posting date -
 * and expects integrators to pull on a schedule, keep the records themselves,
 * and filter in their own application. So tenders live in a table here and the
 * site never queries the third-party API to render a page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Store {

	const DB_VERSION = '1.2.0';

	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'tg_tenders';
	}

	/**
	 * Status changes, one row per move. Written by TG_Tender::move() only.
	 */
	public static function events_table() {
		global $wpdb;

		return $wpdb->prefix . 'tg_tender_events';
	}

	/**
	 * Uploaded files. Built once here and attached to tenders, suppliers and
	 * (from M2) bids, rather than three separate upload implementations.
	 */
	public static function docs_table() {
		global $wpdb;

		return $wpdb->prefix . 'tg_documents';
	}

	/**
	 * Supplier bids. One live bid per supplier per tender.
	 */
	public static function bids_table() {
		global $wpdb;

		return $wpdb->prefix . 'tg_bids';
	}

	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// `source` keeps demonstration records and live records apart, so the
		// sample feed can be switched off without deleting synced data.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source VARCHAR(32) NOT NULL DEFAULT 'sample',
			external_id VARCHAR(190) NOT NULL,
			reference VARCHAR(190) NOT NULL DEFAULT '',
			title TEXT NOT NULL,
			buyer VARCHAR(255) NOT NULL DEFAULT '',
			country VARCHAR(120) NOT NULL DEFAULT '',
			sector VARCHAR(160) NOT NULL DEFAULT '',
			summary TEXT NULL,
			description LONGTEXT NULL,
			value DOUBLE NOT NULL DEFAULT 0,
			currency VARCHAR(12) NOT NULL DEFAULT '',
			published DATE NULL,
			deadline DATE NULL,
			method VARCHAR(190) NOT NULL DEFAULT '',
			source_name VARCHAR(190) NOT NULL DEFAULT '',
			extra LONGTEXT NULL,
			synced_at DATETIME NULL,
			owner_id BIGINT UNSIGNED NULL,
			status VARCHAR(32) NOT NULL DEFAULT '',
			quantity VARCHAR(190) NOT NULL DEFAULT '',
			unit VARCHAR(60) NOT NULL DEFAULT '',
			specifications LONGTEXT NULL,
			delivery_location VARCHAR(255) NOT NULL DEFAULT '',
			opening_at DATETIME NULL,
			closing_at DATETIME NULL,
			status_reason TEXT NULL,
			created_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_external (source, external_id),
			KEY deadline (deadline),
			KEY country (country),
			KEY sector (sector),
			KEY published (published),
			KEY status (status),
			KEY owner_id (owner_id),
			KEY closing_at (closing_at),
			KEY opening_at (opening_at)
		) {$collate};";

		dbDelta( $sql );

		$events = self::events_table();

		$sql_events = "CREATE TABLE {$events} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tender_id BIGINT UNSIGNED NOT NULL,
			actor_id BIGINT UNSIGNED NULL,
			from_status VARCHAR(32) NOT NULL DEFAULT '',
			to_status VARCHAR(32) NOT NULL DEFAULT '',
			reason TEXT NULL,
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY tender_id (tender_id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql_events );

		$docs = self::docs_table();

		// owner_type keeps tender, supplier and bid attachments in one table
		// without three sets of upload code.
		//
		// `path` is relative to the protected upload directory, NOT a public
		// URL. CR certificates and tender specifications are confidential, so
		// files are served through a permission-checked download endpoint and
		// never by a guessable link into wp-content/uploads.
		$sql_docs = "CREATE TABLE {$docs} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			owner_type VARCHAR(20) NOT NULL DEFAULT '',
			owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			uploaded_by BIGINT UNSIGNED NULL,
			label VARCHAR(190) NOT NULL DEFAULT '',
			path VARCHAR(255) NOT NULL DEFAULT '',
			filename VARCHAR(255) NOT NULL DEFAULT '',
			mime VARCHAR(100) NOT NULL DEFAULT '',
			filesize BIGINT UNSIGNED NOT NULL DEFAULT 0,
			token VARCHAR(40) NOT NULL DEFAULT '',
			created_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY owner (owner_type, owner_id),
			KEY token (token)
		) {$collate};";

		dbDelta( $sql_docs );

		$bids = self::bids_table();

		// One live bid per supplier per tender, enforced by the unique key so a
		// double submission cannot create two competing prices from one company.
		// Amounts are DECIMAL rather than DOUBLE: a bid is money, and OMR runs
		// to three decimal places, so float rounding is not acceptable here.
		$sql_bids = "CREATE TABLE {$bids} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tender_id BIGINT UNSIGNED NOT NULL,
			supplier_id BIGINT UNSIGNED NOT NULL,
			price DECIMAL(18,3) NOT NULL DEFAULT 0.000,
			currency VARCHAR(12) NOT NULL DEFAULT 'OMR',
			delivery_days INT UNSIGNED NULL,
			validity_days INT UNSIGNED NULL,
			notes LONGTEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'submitted',
			status_reason TEXT NULL,
			submitted_at DATETIME NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tender_supplier (tender_id, supplier_id),
			KEY tender_id (tender_id),
			KEY supplier_id (supplier_id),
			KEY status (status)
		) {$collate};";

		dbDelta( $sql_bids );

		update_option( 'tg_db_version', self::DB_VERSION );
	}

	/**
	 * Run install() if the schema has never been laid down or is out of date.
	 */
	public static function maybe_install() {
		if ( get_option( 'tg_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Insert or update one tender. Keyed on (source, external_id), so a tender
	 * that TendersOnTime re-publishes with an amended deadline updates in place
	 * rather than appearing twice.
	 *
	 * @return string 'inserted' | 'updated' | 'skipped'
	 */
	public static function upsert( $tender, $source ) {
		global $wpdb;

		if ( empty( $tender['id'] ) ) {
			return 'skipped';
		}

		$table = self::table();

		$row = array(
			'source'      => $source,
			'external_id' => (string) $tender['id'],
			'reference'   => (string) $tender['reference'],
			'title'       => (string) $tender['title'],
			'buyer'       => (string) $tender['buyer'],
			'country'     => (string) $tender['country'],
			'sector'      => (string) $tender['sector'],
			'summary'     => (string) $tender['summary'],
			'description' => (string) $tender['description'],
			'value'       => (float) $tender['value'],
			'currency'    => (string) $tender['currency'],
			'published'   => $tender['published'] ? $tender['published'] : null,
			'deadline'    => $tender['deadline'] ? $tender['deadline'] : null,
			'method'      => (string) $tender['method'],
			'source_name' => (string) $tender['source_name'],
			'extra'       => wp_json_encode( array(
				'contact'     => isset( $tender['contact'] ) ? $tender['contact'] : array(),
				'documents'   => isset( $tender['documents'] ) ? $tender['documents'] : array(),
				'eligibility' => isset( $tender['eligibility'] ) ? $tender['eligibility'] : array(),
			) ),
			'synced_at'   => current_time( 'mysql', true ),
		);

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE source = %s AND external_id = %s",
			$source,
			$row['external_id']
		) );

		if ( $existing ) {
			$wpdb->update( $table, $row, array( 'id' => (int) $existing ) );

			return 'updated';
		}

		$wpdb->insert( $table, $row );

		return 'inserted';
	}

	/**
	 * @return array Tenders in the gateway's normalised shape, keyed by id.
	 */
	public static function all( $source ) {
		global $wpdb;

		$table = self::table();
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE source = %s",
			$source
		), ARRAY_A );

		$out = array();

		foreach ( (array) $rows as $row ) {
			$extra = json_decode( (string) $row['extra'], true );
			$extra = is_array( $extra ) ? $extra : array();

			$out[ $row['external_id'] ] = array(
				'id'          => $row['external_id'],
				'reference'   => $row['reference'],
				'title'       => $row['title'],
				'buyer'       => $row['buyer'],
				'country'     => $row['country'],
				'sector'      => $row['sector'],
				'summary'     => (string) $row['summary'],
				'description' => (string) $row['description'],
				'value'       => (float) $row['value'],
				'currency'    => $row['currency'],
				'published'   => $row['published'] ? $row['published'] : '',
				'deadline'    => $row['deadline'] ? $row['deadline'] : '',
				'method'      => $row['method'],
				'source_name' => $row['source_name'],
				'contact'     => isset( $extra['contact'] ) ? $extra['contact'] : array(),
				'documents'   => isset( $extra['documents'] ) ? $extra['documents'] : array(),
				'eligibility' => isset( $extra['eligibility'] ) ? $extra['eligibility'] : array(),
			);
		}

		return $out;
	}

	public static function count( $source ) {
		global $wpdb;

		$table = self::table();

		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE source = %s",
			$source
		) );
	}

	/**
	 * Newest synced_at across a source, as a unix timestamp.
	 */
	public static function last_synced( $source ) {
		global $wpdb;

		$table = self::table();
		$value = $wpdb->get_var( $wpdb->prepare(
			"SELECT MAX(synced_at) FROM {$table} WHERE source = %s",
			$source
		) );

		return $value ? strtotime( $value . ' UTC' ) : 0;
	}

	public static function clear( $source ) {
		global $wpdb;

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table() . " WHERE source = %s",
			$source
		) );
	}

	/**
	 * Drop tenders whose deadline passed more than $days ago, so the table does
	 * not grow without bound once the sync has been running for months.
	 */
	public static function prune( $source, $days = 60 ) {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . (int) $days . ' days' ) );

		return (int) $wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::table() . " WHERE source = %s AND deadline IS NOT NULL AND deadline < %s",
			$source,
			$cutoff
		) );
	}
}
