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

	/**
	 * All tenders, normalised and keyed by id.
	 *
	 * Nothing here ever calls the third-party API. Live records are pulled on a
	 * schedule by TG_Sync into TG_Store, so rendering a page never depends on
	 * TendersOnTime being reachable - which is the point, with a Ministry
	 * presentation to get through.
	 */
	public static function all() {
		$settings = tg_settings();

		if ( 'remote' !== $settings['source'] ) {
			return self::index( TG_Sample_Data::tenders() );
		}

		$live = TG_Store::all( 'live' );

		// A live source that has not completed its first sync yet would leave the
		// site empty; show the demonstration feed until real records land.
		if ( ! $live ) {
			return self::index( TG_Sample_Data::tenders() );
		}

		return $live;
	}

	/**
	 * True when the site is showing records that came from the live API.
	 */
	public static function is_live() {
		return 'remote' === tg_setting( 'source' ) && TG_Store::count( 'live' ) > 0;
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

		$raw_title       = (string) $get( 'title' );
		$raw_description = (string) $get( 'description' );

		// Feeds that answer from a search index hand back their own hit
		// highlighting. Tenders On Time double-escapes it, so a title arrives as
		// "Supply of &lt;em&gt;Medical&lt;/em&gt; Equipment" and would print those
		// tags on screen as literal text. Decode once, then strip.
		$description = self::plain( $raw_description );
		$summary     = self::plain( (string) $get( 'summary' ) );

		if ( '' === $summary && '' !== $description ) {
			$summary = wp_trim_words( $description, 32, '...' );
		}

		// That same highlighting is the only sector signal in the feed - the
		// account's keywords are configured at the vendor's end and the record
		// carries no category of its own (cpv comes back empty on every row).
		// The emphasised word IS the term their index matched, so it is the
		// source's classification rather than a guess of mine.
		$sector = (string) $get( 'sector' );
		if ( '' === $sector ) {
			$sector = self::highlighted_term( $raw_title . ' ' . $raw_description );
		}

		$tender = array(
			'id'          => (string) $get( 'id' ),
			'reference'   => (string) $get( 'reference' ),
			// Some records arrive with the whole scope of works in the title
			// field - 250+ characters that break a card layout. Trim for
			// display; the full text is still there in the description.
			'title'       => wp_trim_words( self::plain( $raw_title ), 18, '...' ),
			'buyer'       => self::plain( (string) $get( 'buyer' ) ),
			'country'     => (string) $get( 'country' ),
			'sector'      => $sector,
			'summary'     => $summary,
			'description' => $description,
			'value'       => self::number( $get( 'value', 0 ) ),
			'currency'    => (string) $get( 'currency', 'USD' ),
			'published'   => self::date( $get( 'published' ) ),
			'deadline'    => self::date( $get( 'deadline' ) ),
			'method'      => (string) $get( 'method' ),
			'source_name' => (string) $get( 'source_name' ),
			'contact'     => isset( $row['contact'] ) && is_array( $row['contact'] ) ? $row['contact'] : array(),
			'documents'   => isset( $row['documents'] ) && is_array( $row['documents'] ) ? $row['documents'] : array(),
			'eligibility' => isset( $row['eligibility'] ) && is_array( $row['eligibility'] ) ? $row['eligibility'] : array(),
		);

		// Most feeds do not nest the buyer's contact details or the notice file
		// under "contact"/"documents" - they sit as flat columns on the record.
		// Without these the members-only half of a tender page is empty, which
		// is exactly the part the access gate exists to protect.
		if ( ! $tender['contact'] ) {
			$contact = array(
				'email'   => self::plain( (string) $get( 'contact_email' ) ),
				'website' => (string) $get( 'contact_website' ),
				'address' => self::plain( (string) $get( 'contact_address' ) ),
			);
			$tender['contact'] = array_filter( $contact );
		}

		if ( ! $tender['documents'] ) {
			$doc = trim( (string) $get( 'document_url' ) );
			if ( '' !== $doc ) {
				$tender['documents'] = array(
					array(
						'title' => __( 'Official tender notice', 'tender-gateway' ),
						'url'   => $doc,
					),
				);
			}
		}

		if ( '' === $tender['id'] ) {
			$tender['id'] = substr( md5( $tender['title'] . $tender['reference'] . $tender['buyer'] ), 0, 12 );
		}

		return $tender;
	}

	/**
	 * Readable text out of a feed value that may carry escaped markup.
	 *
	 * Decoded once, not in a loop: one pass turns "&lt;em&gt;" into a tag that
	 * strip_all_tags removes, while a legitimate "R&amp;D" becomes "R&D" and
	 * stops there. Repeating the decode would start eating real ampersands.
	 */
	public static function plain( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = wp_strip_all_tags( $text );

		return trim( preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * The term a search-backed feed emphasised in its own hit highlighting.
	 * Returns '' when the feed did not highlight anything.
	 */
	public static function highlighted_term( $text ) {
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		if ( ! preg_match( '#<(em|strong|b)[^>]*>(.*?)</\1>#is', $text, $match ) ) {
			return '';
		}

		$term = self::plain( $match[2] );

		// Guard against a feed that highlights half a sentence.
		if ( '' === $term || str_word_count( $term ) > 3 ) {
			return '';
		}

		return function_exists( 'mb_convert_case' )
			? mb_convert_case( $term, MB_CASE_TITLE, 'UTF-8' )
			: ucwords( strtolower( $term ) );
	}

	/**
	 * Parse a contract value out of whatever the feed calls a number.
	 *
	 * Vendor feeds send these as strings: "3,120,000,000", "USD 48.5 million",
	 * "1.234.567,89". A bare (float) cast turns the first of those into 3.
	 */
	public static function number( $value ) {
		if ( is_int( $value ) || is_float( $value ) ) {
			return (float) $value;
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return 0.0;
		}

		$multiplier = 1;
		if ( preg_match( '/\b(million|mn|m)\b/i', $text ) ) {
			$multiplier = 1000000;
		} elseif ( preg_match( '/\b(billion|bn)\b/i', $text ) ) {
			$multiplier = 1000000000;
		}

		// Keep only the numeric run, dropping currency codes and words.
		if ( ! preg_match( '/-?[\d.,]+/', $text, $match ) ) {
			return 0.0;
		}
		$number = $match[0];

		$last_comma = strrpos( $number, ',' );
		$last_dot   = strrpos( $number, '.' );

		if ( false !== $last_comma && false !== $last_dot ) {
			// Whichever separator comes last is the decimal point.
			if ( $last_comma > $last_dot ) {
				$number = str_replace( '.', '', $number );
				$number = str_replace( ',', '.', $number );
			} else {
				$number = str_replace( ',', '', $number );
			}
		} elseif ( false !== $last_comma ) {
			// A lone comma is a decimal point only when it is not grouping
			// three digits, e.g. "1,5" is 1.5 but "1,500" is 1500.
			$number = preg_match( '/,\d{3}(?:\D|$)/', $number )
				? str_replace( ',', '', $number )
				: str_replace( ',', '.', $number );
		}

		return (float) $number * $multiplier;
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
	 * @param array $args country, sector, search, closing_days, published_on, sort, per_page, page
	 */
	public static function query( $args = array() ) {
		$args = wp_parse_args( $args, array(
			'country'      => '',
			'sector'       => '',
			'search'       => '',
			'closing_days' => 0,
			'published_on' => '',
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
			// An exact posting date, not a window: picking a day the feed has
			// nothing for has to return nothing. Both sides are already
			// normalised to Y-m-d by self::date(), so compare as strings and
			// avoid strtotime turning an unparseable value into "today".
			if ( '' !== $args['published_on'] ) {
				if ( $tender['published'] !== $args['published_on'] ) {
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

		if ( $value >= 1000000000 ) {
			$formatted = rtrim( rtrim( number_format( $value / 1000000000, 1 ), '0' ), '.' ) . 'B';
		} elseif ( $value >= 1000000 ) {
			$formatted = rtrim( rtrim( number_format( $value / 1000000, 1 ), '0' ), '.' ) . 'M';
		} elseif ( $value >= 1000 ) {
			$formatted = rtrim( rtrim( number_format( $value / 1000, 1 ), '0' ), '.' ) . 'K';
		} else {
			$formatted = number_format( $value );
		}

		return $currency . ' ' . $formatted;
	}
}
