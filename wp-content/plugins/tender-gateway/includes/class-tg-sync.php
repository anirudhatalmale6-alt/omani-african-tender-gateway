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
	const PURGE_KEY = 'tg_purge_token';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'schedule' ) );

		// Early, so a purge ping costs one bootstrap and no page render.
		add_action( 'init', array( __CLASS__, 'maybe_serve_purge' ), 1 );
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
		$failed      = array();
		$consecutive = 0;

		for ( $offset = 0; $offset < $days; $offset++ ) {
			// Metered plans bill per call and simply stop answering once the cap
			// is hit, so stop ourselves first and say so rather than burning the
			// allowance and looking like an outage.
			if ( self::calls_remaining() < 1 ) {
				$error = sprintf(
					'Daily API call limit reached (%d calls). Sync stopped early; it will resume tomorrow.',
					(int) $settings['daily_call_cap']
				);
				break;
			}

			$date   = gmdate( $settings['date_format'], strtotime( "-{$offset} days" ) );
			$result = self::fetch_date( $settings, $date );

			$requests[] = $result['request'];

			if ( ! empty( $result['error'] ) ) {
				// A refused key or a wrong endpoint fails identically on every date,
				// so stop at once and keep the remaining allowance. A timeout or a
				// 5xx is worth stepping over instead: losing thirteen good dates to
				// one blip is the worse outcome when the demo needs a backfill and
				// the trial is only two days long.
				if ( ! empty( $result['fatal'] ) ) {
					$error = $result['error'];
					break;
				}

				$failed[ $date ] = $result['error'];

				if ( ++$consecutive >= 2 ) {
					$error = 'Two dates in a row failed (' . $result['error'] . '). Stopped rather than spending the rest of the daily API allowance.';
					break;
				}

				continue;
			}

			$consecutive = 0;

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

		// A run that stepped over a bad date still has a hole in it. Say so by
		// name - a partial backfill that reports success is how a demo ends up
		// missing a day nobody thought to check.
		if ( ! $error && $failed ) {
			$error = sprintf(
				'Fetched %d of %d dates. %d failed (%s). Run the sync again to fill the gaps.',
				$days - count( $failed ),
				$days,
				count( $failed ),
				implode( ', ', array_keys( $failed ) )
			);
		}

		$pruned = 0;
		if ( ! $error ) {
			$pruned = TG_Store::prune( 'live', 60 );
		}

		// The store being right is only half of it. This site sits behind a
		// full-page cache, so a visitor on a plain URL keeps being handed the
		// HTML that was built before this run - the counts, the sector tiles and
		// the "last updated" line all freeze at whatever they were, while any
		// URL carrying a query string misses the cache and shows the new total.
		// That mismatch is not a data bug and no amount of re-syncing clears it;
		// the cache has to be told the pages are stale.
		$purge = array( 'skipped' => 'nothing changed' );
		if ( $totals['inserted'] || $totals['updated'] || $pruned ) {
			$purge = self::purge_page_cache();
		}

		return self::log( array(
			'ok'        => ! $error,
			'trigger'   => $trigger,
			'error'     => $error,
			'failed'    => $failed,
			'days'      => $days,
			'requests'  => $requests,
			'totals'    => $totals,
			'first_raw' => $first_raw,
			'stored'    => TG_Store::count( 'live' ),
			'pruned'    => $pruned,
			'purge'     => $purge,
		) );
	}

	/**
	 * Ask every cache plugin we might be sitting behind to drop its pages.
	 *
	 * Fired by hook name rather than by calling a plugin class directly: the
	 * host can swap its caching layer without this file needing to know, and a
	 * do_action for a plugin that is not installed is simply a no-op. The object
	 * cache is left alone - it holds the query results we have just rewritten
	 * correctly, and flushing it would only make the next visitor slower.
	 *
	 * @return array Names of the purge hooks that had a listener attached.
	 */
	private static function fire_purge_hooks() {
		$fired = array();

		// LiteSpeed (this host), WP Rocket, W3 Total Cache, Cache Enabler -
		// each exposes its own purge-everything action.
		foreach ( array(
			'litespeed_purge_all',
			'rocket_clean_domain',
			'w3tc_flush_posts',
			'cache_enabler_clear_complete_cache',
		) as $hook ) {
			if ( has_action( $hook ) ) {
				$fired[] = $hook;
			}
			do_action( $hook );
		}

		// WP Super Cache has no action, only a function.
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$fired[] = 'wp_cache_clear_cache';
		}

		return $fired;
	}

	/**
	 * The shared secret that lets the sync ask the front end to purge itself.
	 *
	 * Generated once and kept in the options table rather than derived from a
	 * salt, so it can be rotated by deleting one row if it ever leaks. Worst
	 * case for a leak is an unauthenticated cache flush - no data is exposed.
	 */
	private static function purge_token() {
		$token = (string) get_option( self::PURGE_KEY, '' );

		if ( '' === $token ) {
			$token = wp_generate_password( 32, false, false );
			update_option( self::PURGE_KEY, $token, false );
		}

		return $token;
	}

	/**
	 * Serve the purge ping described in purge_page_cache().
	 *
	 * A wrong or missing token falls through to the normal request instead of
	 * answering, so this URL cannot be used to test tokens.
	 */
	public static function maybe_serve_purge() {
		if ( empty( $_GET['tg_purge'] ) ) {
			return;
		}

		$given = (string) wp_unslash( $_GET['tg_purge'] );
		if ( ! hash_equals( self::purge_token(), $given ) ) {
			return;
		}

		$fired = self::fire_purge_hooks();

		// This is the part that only works from inside a real response: the
		// LiteSpeed server process reads the purge header off its way out.
		header( 'X-LiteSpeed-Purge: *' );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );

		echo 'purged ' . esc_html( implode( ',', $fired ) ) . "\n";
		exit;
	}

	/**
	 * Drop the cached pages so the new tenders are actually visible.
	 *
	 * The cache in front of this site is the web server itself, and it is told
	 * to purge by an X-LiteSpeed-Purge header on an outgoing response. That is
	 * the catch: the sync runs from cron under the CLI, where there is no
	 * response to attach a header to, so calling the purge hooks here alone
	 * clears nothing at all - which is exactly what was happening, and why the
	 * stored count kept moving while the home page stayed frozen.
	 *
	 * So we do both. The hooks run in case a purge-capable plugin is handling
	 * things in-process, and then we make one ordinary HTTP request back to the
	 * site carrying the purge token. That request is a genuine response passing
	 * through the web server, so its header lands. It is blocking on purpose -
	 * a second of cron time buys us a recorded status code in the log instead
	 * of a fire-and-forget we could never prove ran.
	 *
	 * @return array Diagnostics for the log entry.
	 */
	public static function purge_page_cache() {
		$result = array(
			'sapi'  => php_sapi_name(),
			'hooks' => self::fire_purge_hooks(),
		);

		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' );
			$result['header'] = 'sent';
		}

		$url  = add_query_arg( 'tg_purge', self::purge_token(), home_url( '/' ) );
		$args = array(
			'timeout'     => 10,
			'redirection' => 0,
			'blocking'    => true,
			'headers'     => array( 'Cache-Control' => 'no-cache' ),
		);

		$response = wp_remote_get( $url, $args );

		// Shared hosts often cannot verify their own certificate on a loopback
		// (the request resolves to the local IP and lands on the wrong vhost
		// certificate). That is worth one retry before giving up on the purge.
		if ( is_wp_error( $response ) ) {
			$result['ping_error'] = $response->get_error_message();

			$args['sslverify'] = false;
			$response          = wp_remote_get( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			$result['ping'] = 'failed: ' . $response->get_error_message();
		} else {
			$result['ping'] = (int) wp_remote_retrieve_response_code( $response );
			$result['body'] = trim( wp_remote_retrieve_body( $response ) );
		}

		return $result;
	}

	/**
	 * One request for one posting date.
	 *
	 * `fatal` says whether the failure would repeat for every other date, which
	 * decides if the caller stops the walk or steps over this one.
	 *
	 * @return array{request:array, tenders:array, raw_rows:array, error:string, fatal:bool}
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
		self::count_call();
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
			$request['error']  = $response->get_error_message();

			// A dropped connection or a timeout says nothing about the next date.
			return array( 'request' => $request, 'tenders' => array(), 'raw_rows' => array(), 'error' => $request['error'], 'fatal' => false );
		}

		$code              = (int) wp_remote_retrieve_response_code( $response );
		$body              = (string) wp_remote_retrieve_body( $response );
		$request['status'] = $code;
		$request['bytes']  = strlen( $body );

		if ( $code < 200 || $code > 299 ) {
			// Server-side wobbles and request timeouts are worth retrying on the
			// next date. A 401/403 (key refused), 404 (wrong endpoint) or 429
			// (allowance spent) will answer the same way for every date, so those
			// stop the walk.
			$transient = in_array( $code, array( 408, 425, 500, 502, 503, 504 ), true );

			$request['error'] = sprintf( 'Tender API returned HTTP %d for %s.', $code, $date );

			return array(
				'request'  => $request,
				'tenders'  => array(),
				'raw_rows' => array(),
				'error'    => $request['error'],
				'fatal'    => ! $transient,
			);
		}

		$parsed = self::parse( $body, $settings );

		if ( is_wp_error( $parsed ) ) {
			$request['excerpt'] = substr( $body, 0, 400 );
			$request['error']   = $parsed->get_error_message();

			// An expired key, an unreadable body or a wrong results path is a
			// configuration answer, not a bad moment - every remaining date would
			// fail the same way, so stop instead of burning the allowance.
			return array( 'request' => $request, 'tenders' => array(), 'raw_rows' => array(), 'error' => $request['error'], 'fatal' => true );
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

		if ( 'query' === $settings['auth_style'] ) {
			if ( ! empty( $settings['auth_user'] ) && ! empty( $settings['auth_user_param'] ) ) {
				$url = add_query_arg( $settings['auth_user_param'], rawurlencode( $settings['auth_user'] ), $url );
			}

			if ( ! empty( $settings['api_key'] ) ) {
				$key = $settings['auth_query_key'] ? $settings['auth_query_key'] : 'api_key';
				$url = add_query_arg( $key, rawurlencode( $settings['api_key'] ), $url );
			}
		}

		return $url;
	}

	/* ------------------------------------------------------- call metering */

	private static function calls_key() {
		return 'tg_api_calls_' . gmdate( 'Y-m-d' );
	}

	public static function calls_today() {
		return (int) get_option( self::calls_key(), 0 );
	}

	private static function count_call() {
		update_option( self::calls_key(), self::calls_today() + 1, false );
	}

	public static function calls_remaining() {
		$cap = (int) tg_setting( 'daily_call_cap', 25 );

		return $cap > 0 ? max( 0, $cap - self::calls_today() ) : PHP_INT_MAX;
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

		if ( 'json' === $format || 'auto' === $format ) {
			// Checked before anything else: TendersOnTime answers HTTP 200 and puts
			// the failure in the body ({"status":"failed","message":"Api Expired!",
			// "data":[]}). Read only the status code and an expired key looks
			// exactly like a quiet day with no tenders.
			$peek = json_decode( $body, true );

			if ( is_array( $peek ) ) {
				$envelope = self::envelope_error( $peek );

				if ( $envelope ) {
					return new WP_Error( 'tg_api_said_no', $envelope );
				}
			}
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

	/**
	 * Spot an application-level failure carried inside a 200 response.
	 *
	 * @return string Message to report, or '' when the payload looks healthy.
	 */
	private static function envelope_error( $body ) {
		$failed = array( 'failed', 'fail', 'error', 'false', '0', 'denied' );

		foreach ( array( 'status', 'result', 'success' ) as $field ) {
			if ( ! isset( $body[ $field ] ) || ! is_scalar( $body[ $field ] ) ) {
				continue;
			}

			$value = strtolower( trim( (string) $body[ $field ] ) );

			// A literal boolean false in "success" reads as an empty string.
			if ( 'success' === $field && false === $body[ $field ] ) {
				$value = 'false';
			}

			if ( in_array( $value, $failed, true ) ) {
				$message = '';
				foreach ( array( 'message', 'error', 'msg', 'description' ) as $key ) {
					if ( ! empty( $body[ $key ] ) && is_scalar( $body[ $key ] ) ) {
						$message = (string) $body[ $key ];
						break;
					}
				}

				return $message ? 'The tender API refused the request: ' . $message : 'The tender API reported a failure without a message.';
			}
		}

		return '';
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
		// The username is half of the credential pair on some feeds, so mask it
		// too - this log is designed to be shown on a projector.
		$secrets = array_filter( array( $key, tg_setting( 'auth_user' ) ) );

		foreach ( $secrets as $secret ) {
			$text = str_replace( rawurlencode( $secret ), '***', str_replace( $secret, '***', $text ) );
		}

		return $text;
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
