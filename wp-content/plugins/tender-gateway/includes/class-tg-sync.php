<?php
/**
 * Scheduled pull from the tender API into the local store.
 *
 * TendersOnTime exposes one request filter - the tender posting date - and
 * refreshes its own backend every 3 hours, so this runs on a 3-hourly schedule
 * and walks a small window of dates rather than trying to query live.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_Sync {

	const HOOK      = 'tg_sync_tenders';
	const LOG_KEY   = 'tg_sync_log';
	const LOCK_KEY  = 'tg_sync_running';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule( $schedules ) {
		$schedules['tg_three_hours'] = array(
			'interval' => 3 * HOUR_IN_SECONDS,
			'display'  => 'Every 3 hours (Tender Gateway)',
		);

		return $schedules;
	}

	public static function activate_schedule() {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'tg_three_hours', self::HOOK );
		}
	}

	public static function clear_schedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	public static function next_run() {
		return (int) wp_next_scheduled( self::HOOK );
	}

	public static function run_scheduled() {
		$settings = tg_settings();

		if ( 'remote' !== $settings['source'] || empty( $settings['endpoint'] ) ) {
			return;
		}

		self::run( (int) $settings['sync_days'] );
	}

	/**
	 * Pull the last $days days of postings (today inclusive) and upsert them.
	 *
	 * @return array Log entry describing what happened.
	 */
	public static function run( $days = 1, $trigger = 'scheduled' ) {
		$settings = tg_settings();

		// A slow API plus an impatient "Sync now" click must not run twice at once.
		if ( get_transient( self::LOCK_KEY ) ) {
			return self::log( array(
				'ok'      => false,
				'trigger' => $trigger,
				'error'   => 'A sync is already running. Try again in a moment.',
			) );
		}
		set_transient( self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS );

		TG_Store::maybe_install();

		$days     = max( 1, min( 30, (int) $days ) );
		$requests = array();
		$totals   = array( 'received' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0 );
		$error    = '';
		$first_raw = null;

		for ( $offset = 0; $offset < $days; $offset++ ) {
			$date   = gmdate( $settings['date_format'], strtotime( "-{$offset} days" ) );
			$result = self::fetch_date( $settings, $date );

			$requests[] = $result['request'];

			if ( ! empty( $result['error'] ) ) {
				$error = $result['error'];
				break;
			}

			if ( null === $first_raw && ! empty( $result['raw_rows'] ) ) {
				$first_raw = $result['raw_rows'][0];
			}

			foreach ( $result['tenders'] as $tender ) {
				$totals['received']++;
				$outcome = TG_Store::upsert( $tender, 'live' );
				$totals[ $outcome === 'skipped' ? 'skipped' : $outcome ]++;
			}
		}

		delete_transient( self::LOCK_KEY );

		if ( ! $error ) {
			TG_Store::prune( 'live', 60 );
		}

		return self::log( array(
			'ok'        => ! $error,
			'trigger'   => $trigger,
			'error'     => $error,
			'days'      => $days,
			'requests'  => $requests,
			'totals'    => $totals,
			'first_raw' => $first_raw,
			'stored'    => TG_Store::count( 'live' ),
		) );
	}

	/**
	 * One request for one posting date.
	 *
	 * @return array{request:array, tenders:array, raw_rows:array, error:string}
	 */
	public static function fetch_date( $settings, $date ) {
		$url     = self::build_url( $settings, $date );
		$headers = array( 'Accept' => 'json' === $settings['format'] ? 'application/json' : '*/*' );

		if ( ! empty( $settings['api_key'] ) && 'query' !== $settings['auth_style'] ) {
			if ( 'header' === $settings['auth_style'] ) {
				$name             = $settings['auth_header'] ? $settings['auth_header'] : 'X-API-Key';
				$headers[ $name ] = $settings['api_key'];
			} else {
				$headers['Authorization'] = 'Bearer ' . $settings['api_key'];
			}
		}

		$started  = microtime( true );
		$response = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => $headers ) );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		$request = array(
			'url'     => self::mask( $url, $settings['api_key'] ),
			'date'    => $date,
			'headers' => self::mask_headers( $headers, $settings['api_key'] ),
			'ms'      => $elapsed,
		);

		if ( is_wp_error( $response ) ) {
			$request['status'] = 0;

			return array( 'request' => $request, 'tenders' => array(), 'raw_rows' => array(), 'error' => $response->get_error_message() );
		}

		$code              = (int) wp_remote_retrieve_response_code( $response );
		$body              = (string) wp_remote_retrieve_body( $response );
		$request['status'] = $code;
		$request['bytes']  = strlen( $body );

		if ( $code < 200 || $code > 299 ) {
			return array(
				'request'  => $request,
				'tenders'  => array(),
				'raw_rows' => array(),
				'error'    => sprintf( 'Tender API returned HTTP %d for %s.', $code, $date ),
			);
		}

		$parsed = self::parse( $body, $settings );

		if ( is_wp_error( $parsed ) ) {
			$request['excerpt'] = substr( $body, 0, 400 );

			return array( 'request' => $request, 'tenders' => array(), 'raw_rows' => array(), 'error' => $parsed->get_error_message() );
		}

		$tenders = array();
		foreach ( $parsed as $row ) {
			if ( is_array( $row ) ) {
				$tenders[] = TG_API::normalise( $row, $settings['map'] );
			}
		}

		$request['records'] = count( $tenders );

		return array( 'request' => $request, 'tenders' => $tenders, 'raw_rows' => $parsed, 'error' => '' );
	}

	/**
	 * Build the request URL for a posting date.
	 *
	 * A `{date}` placeholder anywhere in the endpoint wins; otherwise the date
	 * is appended as a query parameter. Both shapes turn up in the wild and we
	 * do not yet know which one this account is issued.
	 */
	public static function build_url( $settings, $date ) {
		$url = $settings['endpoint'];

		if ( false !== strpos( $url, '{date}' ) ) {
			$url = str_replace( '{date}', rawurlencode( $date ), $url );
		} elseif ( ! empty( $settings['date_param'] ) ) {
			$url = add_query_arg( $settings['date_param'], rawurlencode( $date ), $url );
		}

		if ( ! empty( $settings['api_key'] ) && 'query' === $settings['auth_style'] ) {
			$key = $settings['auth_query_key'] ? $settings['auth_query_key'] : 'api_key';
			$url = add_query_arg( $key, rawurlencode( $settings['api_key'] ), $url );
		}

		return $url;
	}

	/**
	 * Decode a JSON or XML body down to the list of tender records.
	 *
	 * @return array|WP_Error
	 */
	public static function parse( $body, $settings ) {
		$format = $settings['format'];
		$body   = trim( $body );

		if ( 'auto' === $format ) {
			$format = ( '' !== $body && '<' === $body[0] ) ? 'xml' : 'json';
		}

		if ( 'xml' === $format ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NOCDATA );
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );

			if ( false === $xml ) {
				return new WP_Error( 'tg_xml', 'The API response was not valid XML.' );
			}

			$decoded = json_decode( wp_json_encode( $xml ), true );
		} else {
			$decoded = json_decode( $body, true );

			if ( null === $decoded ) {
				return new WP_Error( 'tg_json', 'The API response was not valid JSON.' );
			}
		}

		$rows = self::dig( $decoded, $settings['results_path'] );

		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'tg_shape', 'Could not find a list of tenders in the response. Check the "results path" setting.' );
		}

		// A single-record XML document decodes to one associative array rather
		// than a list of them; wrap it so the caller always gets a list.
		if ( $rows && ! isset( $rows[0] ) ) {
			$rows = array( $rows );
		}

		return array_values( $rows );
	}

	private static function dig( $body, $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			if ( isset( $body[0] ) ) {
				return $body;
			}

			foreach ( array( 'tenders', 'tender', 'data', 'results', 'items', 'records', 'response' ) as $guess ) {
				if ( isset( $body[ $guess ] ) && is_array( $body[ $guess ] ) ) {
					return $body[ $guess ];
				}
			}

			return is_array( $body ) ? $body : null;
		}

		$node = $body;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
				return null;
			}
			$node = $node[ $segment ];
		}

		return $node;
	}

	/**
	 * Never write a live API key into the log that the demo screen renders.
	 */
	private static function mask( $text, $key ) {
		if ( ! $key ) {
			return $text;
		}

		return str_replace( rawurlencode( $key ), '***', str_replace( $key, '***', $text ) );
	}

	private static function mask_headers( $headers, $key ) {
		$out = array();

		foreach ( $headers as $name => $value ) {
			$out[ $name ] = self::mask( $value, $key );
		}

		return $out;
	}

	private static function log( $entry ) {
		$entry['time'] = time();

		update_option( self::LOG_KEY, $entry, false );

		return $entry;
	}

	public static function last_log() {
		$log = get_option( self::LOG_KEY, array() );

		return is_array( $log ) ? $log : array();
	}
}
