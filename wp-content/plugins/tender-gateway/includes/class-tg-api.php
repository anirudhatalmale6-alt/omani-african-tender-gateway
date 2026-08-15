<?php
/**
 * Tender feed client.
 *
 * The rest of the plugin only ever talks to this class, so switching the demo
 * from bundled sample data to the live tender API is a settings change and not
 * a code change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TG_API {

	const CACHE_KEY = 'tg_tender_feed';

	/**
	 * All tenders, normalised and cached.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array
	 */
	public static function all( $force = false ) {
		$settings = tg_settings();

		if ( 'remote' !== $settings['source'] || empty( $settings['endpoint'] ) ) {
			return self::index( TG_Sample_Data::tenders() );
		}

		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$fetched = self::fetch_remote( $settings );

		if ( is_wp_error( $fetched ) ) {
			// Never leave the Ministry looking at an error page: fall back to the
			// last good payload, then to the sample feed.
			$stale = get_option( 'tg_last_good_feed', array() );
			if ( is_array( $stale ) && $stale ) {
				return $stale;
			}

			return self::index( TG_Sample_Data::tenders() );
		}

		$fetched = self::index( $fetched );

		set_transient( self::CACHE_KEY, $fetched, max( 1, (int) $settings['cache_minutes'] ) * MINUTE_IN_SECONDS );
		update_option( 'tg_last_good_feed', $fetched, false );
		update_option( 'tg_last_sync', time(), false );

		return $fetched;
	}

	/**
	 * Call the configured endpoint and normalise the payload.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_remote( $settings ) {
		$url     = $settings['endpoint'];
		$headers = array( 'Accept' => 'application/json' );

		if ( ! empty( $settings['api_key'] ) ) {
			switch ( $settings['auth_style'] ) {
				case 'header':
					$header_name             = $settings['auth_header'] ? $settings['auth_header'] : 'X-API-Key';
					$headers[ $header_name ] = $settings['api_key'];
					break;
				case 'query':
					$query_key = $settings['auth_query_key'] ? $settings['auth_query_key'] : 'api_key';
					$url       = add_query_arg( $query_key, rawurlencode( $settings['api_key'] ), $url );
					break;
				case 'bearer':
				default:
					$headers['Authorization'] = 'Bearer ' . $settings['api_key'];
					break;
			}
		}

		$response = wp_remote_get( $url, array(
			'timeout' => 20,
			'headers' => $headers,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code > 299 ) {
			return new WP_Error( 'tg_http', sprintf( 'Tender API returned HTTP %d.', $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( null === $body ) {
			return new WP_Error( 'tg_json', 'Tender API response was not valid JSON.' );
		}

		$rows = self::dig( $body, $settings['results_path'] );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'tg_shape', 'Could not find a list of tenders in the API response. Check the "results path" setting.' );
		}

		// Some APIs return an object keyed by id rather than a plain list.
		$rows = array_values( $rows );

		$normalised = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$normalised[] = self::normalise( $row, $settings['map'] );
			}
		}

		return $normalised;
	}

	/**
	 * Walk a dot-separated path into a decoded JSON body.
	 */
	private static function dig( $body, $path ) {
		$path = trim( (string) $path );

		if ( '' === $path ) {
			// No path configured: accept either a bare list or the usual wrappers.
			if ( isset( $body[0] ) ) {
				return $body;
			}
			foreach ( array( 'data', 'results', 'items', 'tenders', 'records' ) as $guess ) {
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
	 * Map one raw API record onto the gateway's internal tender shape.
	 */
	public static function normalise( $row, $map ) {
		$get = function ( $field, $default = '' ) use ( $row, $map ) {
			$key = isset( $map[ $field ] ) ? $map[ $field ] : $field;
			if ( '' === $key ) {
				return $default;
			}

			$node = $row;
			foreach ( explode( '.', $key ) as $segment ) {
				if ( ! is_array( $node ) || ! array_key_exists( $segment, $node ) ) {
					return $default;
				}
				$node = $node[ $segment ];
			}

			return is_scalar( $node ) ? $node : $default;
		};

		$description = (string) $get( 'description' );
		$summary     = (string) $get( 'summary' );

		if ( '' === $summary && '' !== $description ) {
			$summary = wp_trim_words( wp_strip_all_tags( $description ), 32, '...' );
		}

		$tender = array(
			'id'          => (string) $get( 'id' ),
			'reference'   => (string) $get( 'reference' ),
			'title'       => (string) $get( 'title' ),
			'buyer'       => (string) $get( 'buyer' ),
			'country'     => (string) $get( 'country' ),
			'sector'      => (string) $get( 'sector' ),
			'summary'     => $summary,
			'description' => $description,
			'value'       => (float) $get( 'value', 0 ),
			'currency'    => (string) $get( 'currency', 'USD' ),
			'published'   => self::date( $get( 'published' ) ),
			'deadline'    => self::date( $get( 'deadline' ) ),
			'method'      => (string) $get( 'method' ),
			'source_name' => (string) $get( 'source_name' ),
			'contact'     => isset( $row['contact'] ) && is_array( $row['contact'] ) ? $row['contact'] : array(),
			'documents'   => isset( $row['documents'] ) && is_array( $row['documents'] ) ? $row['documents'] : array(),
			'eligibility' => isset( $row['eligibility'] ) && is_array( $row['eligibility'] ) ? $row['eligibility'] : array(),
		);

		if ( '' === $tender['id'] ) {
			$tender['id'] = substr( md5( $tender['title'] . $tender['reference'] . $tender['buyer'] ), 0, 12 );
		}

		return $tender;
	}

	private static function date( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}

		$time = strtotime( $value );

		return $time ? gmdate( 'Y-m-d', $time ) : '';
	}

	/**
	 * Re-key a list of tenders by id so lookups are O(1) and duplicates drop out.
	 */
	private static function index( $tenders ) {
		$out = array();
		foreach ( $tenders as $tender ) {
			if ( ! empty( $tender['id'] ) ) {
				$out[ $tender['id'] ] = $tender;
			}
		}

		return $out;
	}

	public static function get( $id ) {
		$all = self::all();

		return isset( $all[ $id ] ) ? $all[ $id ] : null;
	}

	/**
	 * Filtered, sorted list.
	 *
	 * @param array $args country, sector, search, closing_days, sort, per_page, page
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'country'      => '',
			'sector'       => '',
			'search'       => '',
			'closing_days' => 0,
			'sort'         => 'deadline',
			'per_page'     => 9,
			'page'         => 1,
		) );

		$today = strtotime( gmdate( 'Y-m-d' ) );
		$rows  = array();

		foreach ( self::all() as $tender ) {
			if ( $args['country'] && $tender['country'] !== $args['country'] ) {
				continue;
			}
			if ( $args['sector'] && $tender['sector'] !== $args['sector'] ) {
				continue;
			}
			if ( $args['search'] ) {
				$haystack = strtolower( $tender['title'] . ' ' . $tender['buyer'] . ' ' . $tender['summary'] . ' ' . $tender['reference'] . ' ' . $tender['country'] . ' ' . $tender['sector'] );
				if ( false === strpos( $haystack, strtolower( $args['search'] ) ) ) {
					continue;
				}
			}
			if ( $args['closing_days'] > 0 ) {
				$days = self::days_left( $tender );
				if ( null === $days || $days > (int) $args['closing_days'] || $days < 0 ) {
					continue;
				}
			}

			$rows[] = $tender;
		}

		usort( $rows, function ( $a, $b ) use ( $args, $today ) {
			switch ( $args['sort'] ) {
				case 'value':
					return ( $b['value'] <=> $a['value'] );
				case 'published':
					return ( strtotime( $b['published'] ) <=> strtotime( $a['published'] ) );
				case 'deadline':
				default:
					return ( strtotime( $a['deadline'] ) <=> strtotime( $b['deadline'] ) );
			}
		} );

		$total    = count( $rows );
		$per_page = max( 1, (int) $args['per_page'] );
		$pages    = max( 1, (int) ceil( $total / $per_page ) );
		$page     = min( $pages, max( 1, (int) $args['page'] ) );

		return array(
			'items' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		);
	}

	public static function days_left( $tender ) {
		if ( empty( $tender['deadline'] ) ) {
			return null;
		}

		$deadline = strtotime( $tender['deadline'] );
		$today    = strtotime( gmdate( 'Y-m-d' ) );

		if ( ! $deadline ) {
			return null;
		}

		return (int) floor( ( $deadline - $today ) / DAY_IN_SECONDS );
	}

	public static function facet( $field ) {
		$counts = array();
		foreach ( self::all() as $tender ) {
			$value = isset( $tender[ $field ] ) ? $tender[ $field ] : '';
			if ( '' === $value ) {
				continue;
			}
			$counts[ $value ] = isset( $counts[ $value ] ) ? $counts[ $value ] + 1 : 1;
		}
		ksort( $counts );

		return $counts;
	}

	public static function format_value( $tender ) {
		if ( empty( $tender['value'] ) ) {
			return 'Value not disclosed';
		}

		$value    = (float) $tender['value'];
		$currency = $tender['currency'] ? $tender['currency'] : 'USD';

		if ( $value >= 1000000 ) {
			$formatted = rtrim( rtrim( number_format( $value / 1000000, 1 ), '0' ), '.' ) . 'M';
		} elseif ( $value >= 1000 ) {
			$formatted = rtrim( rtrim( number_format( $value / 1000, 1 ), '0' ), '.' ) . 'K';
		} else {
			$formatted = number_format( $value );
		}

		return $currency . ' ' . $formatted;
	}
}
