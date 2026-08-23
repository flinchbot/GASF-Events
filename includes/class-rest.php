<?php
/**
 * Public REST feed — the clean, owned contract the kiosk (and any consumer)
 * reads instead of scraping. `GET /wp-json/gasf-events/v1/events` with
 * from/to/limit/instance/event/contains/order/fields/updated_since. Read-only,
 * published events only, cache-friendly. `updated_since` enables incremental
 * kiosk sync. See docs/ARCHITECTURE.md §7 and docs/REST-API.md.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'gasf-events/v1';

	/**
	 * Every field the payload can carry, in output order. Omitting `fields`
	 * returns all of them — that default IS the locked contract the kiosk is
	 * being built against (§7), so this list only ever grows at the end.
	 */
	const FIELDS = [
		'id', 'title', 'slug', 'url', 'start', 'end', 'all_day', 'status',
		'image', 'description', 'organizer', 'venue', 'series_id', 'source', 'modified',
		'tv_input', 'tv_channel',
	];

	/**
	 * Named `?event=` shortcuts. Each resolves to the title text configured in
	 * Events → Settings — the SAME value [gasf_bayern_events] and
	 * [gasf_dinner_events] read — so renaming how match events are titled stays
	 * one edit in one place and the API follows the site instead of drifting
	 * from it.
	 */
	const EVENT_PRESETS = [ 'fcbayern', 'dinner' ];

	/** Spellings that should land on a preset. Keys are already normalised. */
	const PRESET_ALIASES = [
		'bayern'         => 'fcbayern',
		'fcb'            => 'fcbayern',
		'fcbayernmunich' => 'fcbayern',
		'bayernmunich'   => 'fcbayern',
		'dinnernight'    => 'dinner',
		'dinnernights'   => 'dinner',
	];

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'routes' ] );
	}

	public function routes(): void {
		register_rest_route( self::NS, '/events', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'from'          => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'to'            => [ 'sanitize_callback' => 'sanitize_text_field' ],
				// No registered default for limit/instance: the callback has to be
				// able to tell "not supplied" from "supplied as 100", because
				// instance and limit are mutually exclusive and a default would
				// make every instance request look like it also asked for a limit.
				'limit'         => [ 'sanitize_callback' => 'absint' ],
				// Deliberately NOT absint: it would fold instance=-3 into 3 and
				// silently serve the third match. The sign has to survive to the
				// callback so an out-of-range value can be told apart from a valid one.
				'instance'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'event'         => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'contains'      => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'order'         => [ 'default' => 'asc', 'sanitize_callback' => 'sanitize_text_field' ],
				'fields'        => [ 'sanitize_callback' => 'sanitize_text_field' ],
				'updated_since' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'events' ],
		] );
		register_rest_route( self::NS, '/events/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'args'                => [
				'fields' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'single' ],
		] );
	}

	public function events( \WP_REST_Request $req ) {
		$fields = $this->resolve_fields( (string) $req['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		// Case-insensitive, but a typo is a 400 rather than a silent fallback —
		// "order=descending" quietly returning ascending is a nasty surprise.
		$order = strtolower( trim( (string) $req['order'] ) ) ?: 'asc';
		if ( ! in_array( $order, [ 'asc', 'desc' ], true ) ) {
			return new \WP_Error(
				'gasf_order_invalid',
				__( 'The order parameter must be asc or desc.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}
		$contains = $this->resolve_contains( $req );
		if ( is_wp_error( $contains ) ) {
			return $contains;
		}

		// instance is 1-based and picks ONE event: instance=1 is the next match,
		// instance=2 the one after it. It is an offset, not a count, so pairing it
		// with limit is rejected rather than silently letting one win.
		$has_limit    = null !== $req['limit'] && '' !== (string) $req['limit'];
		$has_instance = null !== $req['instance'] && '' !== (string) $req['instance'];
		$offset       = 0;
		if ( $has_instance ) {
			if ( $has_limit ) {
				return new \WP_Error(
					'gasf_instance_with_limit',
					__( 'instance returns a single event, so it cannot be combined with limit. Drop limit, or use limit on its own.', 'gasf-events' ),
					[ 'status' => 400 ]
				);
			}
			$instance = (int) $req['instance'];
			if ( $instance < 1 ) {
				return new \WP_Error(
					'gasf_instance_invalid',
					__( 'instance counts from 1, where instance=1 is the next matching event.', 'gasf-events' ),
					[ 'status' => 400 ]
				);
			}
			$offset = $instance - 1;
			$limit  = 1;
		} else {
			$limit = $has_limit ? min( 500, max( 1, (int) $req['limit'] ) ) : 100;
		}

		$from = (string) $req['from'];
		$to   = (string) $req['to'];

		$meta_query = [ 'relation' => 'AND' ];
		if ( $from || $to ) {
			if ( $from ) {
				$meta_query[] = [ 'key' => Meta::END_TS, 'value' => self::ts( $from ), 'type' => 'NUMERIC', 'compare' => '>=' ];
			}
			if ( $to ) {
				$meta_query[] = [ 'key' => Meta::START_TS, 'value' => self::ts( $to ), 'type' => 'NUMERIC', 'compare' => '<=' ];
			}
		} else {
			// Default: upcoming.
			$meta_query[] = [ 'key' => Meta::END_TS, 'value' => time(), 'type' => 'NUMERIC', 'compare' => '>=' ];
		}

		$args = [
			'post_type'           => GASF_EVENTS_CPT,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'meta_key'            => Meta::START_TS,
			'orderby'             => 'meta_value_num',
			// asc = soonest first (so limit=1 is "the next event"); desc = most
			// recent first, which is what you want when reading past events.
			'order'               => strtoupper( $order ),
			'meta_query'          => $meta_query,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		];

		if ( $offset > 0 ) {
			$args['offset'] = $offset;
		}

		if ( ! empty( $req['updated_since'] ) ) {
			$ts = self::ts( (string) $req['updated_since'] );
			if ( $ts ) {
				$args['date_query'] = [ [ 'column' => 'post_modified_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $ts ), 'inclusive' => false ] ];
			}
		}

		$q    = $this->query( $args, $contains );
		$data = array_map( fn( $p ) => $this->to_json( new Event( $p ), $fields ), $q->posts );

		$resp = new \WP_REST_Response( $data );
		$resp->header( 'X-GASF-Count', (string) count( $data ) );
		$resp->header( 'Cache-Control', 'public, max-age=120' );
		return $resp;
	}

	public function single( \WP_REST_Request $req ) {
		$fields = $this->resolve_fields( (string) $req['fields'] );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$e = Event::get( (int) $req['id'] );
		if ( ! $e || 'publish' !== get_post_status( $e->id() ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		return new \WP_REST_Response( $this->to_json( $e, $fields ) );
	}

	/**
	 * Normalized event JSON — the stable contract.
	 *
	 * @param string[]|null $fields Field names to emit, or null for all of them.
	 */
	public function to_json( Event $e, ?array $fields = null ): array {
		$out = [];
		foreach ( self::FIELDS as $name ) {
			if ( null === $fields || in_array( $name, $fields, true ) ) {
				$out[ $name ] = $this->field( $e, $name );
			}
		}
		return $out;
	}

	/**
	 * One field's value. Built per field rather than all at once so an
	 * unrequested field costs nothing — `description` runs do_shortcode() and
	 * `image` hits the attachment tables, which is most of the work in a
	 * 100-event response that a title-and-date consumer never wanted.
	 *
	 * @return mixed
	 */
	private function field( Event $e, string $name ) {
		switch ( $name ) {
			case 'id':
				return $e->id();
			case 'title':
				return $e->title();
			case 'slug':
				return $e->post()->post_name;
			case 'url':
				return $e->permalink();
			case 'start':
				return $e->start_iso();
			case 'end':
				return $e->end_iso();
			case 'all_day':
				return $e->is_all_day();
			case 'status':
				return $e->status() ?: 'scheduled';
			case 'image':
				return $e->cover_url( 'large' );
			case 'description':
				return wp_strip_all_tags( $e->description() );
			// Explicit whitelist — keep internal flags (hide_map, etc.) out of the
			// public contract and make future admin-only fields additive-safe.
			case 'organizer':
				$o = $e->organizer();
				return [ 'name' => $o['name'] ?? '', 'url' => $o['url'] ?? '' ];
			case 'venue':
				$v = $e->venue();
				return [
					'name'    => $v['name'] ?? '',
					'street'  => $v['street'] ?? '',
					'city'    => $v['city'] ?? '',
					'state'   => $v['state'] ?? '',
					'zip'     => $v['zip'] ?? '',
					'country' => $v['country'] ?? '',
					'lat'     => $v['lat'] ?? '',
					'lng'     => $v['lng'] ?? '',
				];
			case 'series_id':
				return $e->series_id();
			case 'source':
				return $e->source();
			case 'modified':
				return get_post_modified_time( 'c', true, $e->post() );
			// "How to watch" override for the Bierstube kiosk game tiles —
			// written per event by GASF-Utilities' Game TV tab. '' = no
			// override (the kiosk falls back to its pinned-tile default).
			case 'tv_input':
				return $e->tv_input();
			case 'tv_channel':
				return $e->tv_channel();
		}
		return null; // unreachable: resolve_fields() rejects unknown names
	}

	/**
	 * Turn `?fields=` into the list of keys to emit.
	 *
	 * Two forms, because "give me only these two" and "give me everything but
	 * the huge one" are both common and each is clumsy to express in the other:
	 *
	 *   fields=title,start        → exactly those keys
	 *   fields=-description,-venue → everything except those
	 *
	 * Mixing the forms is rejected rather than guessed at — `title,-image`
	 * has two equally plausible readings, and picking one silently would send
	 * a consumer the wrong payload. Unknown names are rejected too, so a typo
	 * is a 400 and not a mysteriously absent field.
	 *
	 * Whatever you name is exactly what you get; nothing is force-included.
	 *
	 * @return string[]|null|\WP_Error Null means "everything" (the default).
	 */
	private function resolve_fields( string $raw ) {
		$names = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), 'strlen' ) );
		if ( ! $names ) {
			return null;
		}

		$negated = array_filter( $names, static fn( $n ) => str_starts_with( $n, '-' ) );
		if ( $negated && count( $negated ) !== count( $names ) ) {
			return new \WP_Error(
				'gasf_fields_mixed',
				__( 'The fields parameter takes either a list to include or a list of -excluded names, not both.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}

		$clean   = array_map( static fn( $n ) => ltrim( $n, '-' ), $names );
		$unknown = array_values( array_diff( $clean, self::FIELDS ) );
		if ( $unknown ) {
			return new \WP_Error(
				'gasf_fields_unknown',
				sprintf(
					/* translators: 1: unknown field names, 2: the valid field names */
					__( 'Unknown field(s): %1$s. Available fields: %2$s.', 'gasf-events' ),
					implode( ', ', $unknown ),
					implode( ', ', self::FIELDS )
				),
				[ 'status' => 400, 'unknown' => $unknown, 'available' => self::FIELDS ]
			);
		}

		return $negated ? array_values( array_diff( self::FIELDS, $clean ) ) : $clean;
	}

	/**
	 * Run the query, optionally narrowed to titles containing $contains.
	 *
	 * The title match is done in SQL rather than by fetching a page of events
	 * and filtering in PHP the way the shortcodes do. The shortcodes can get
	 * away with it because they read a fixed 200 and render at most a handful;
	 * here, filtering after the fact would make `instance` wrong the moment the
	 * Nth match sat past the fetch window, and would break `limit` besides.
	 *
	 * MySQL's default collation is case-insensitive, so LIKE matches the same
	 * events the shortcodes' mb_strtolower() comparison does.
	 */
	private function query( array $args, string $contains ): \WP_Query {
		if ( '' === $contains ) {
			return new \WP_Query( $args );
		}
		// Passed as a query var (not just captured) so the filter only touches
		// THIS query, even if something else runs one while it is attached.
		$args['gasf_title_contains'] = $contains;
		$cb = static function ( $where, $wp_q ) {
			global $wpdb;
			$needle = (string) $wp_q->get( 'gasf_title_contains' );
			if ( '' === $needle ) {
				return $where;
			}
			return $where . $wpdb->prepare(
				" AND {$wpdb->posts}.post_title LIKE %s",
				'%' . $wpdb->esc_like( $needle ) . '%'
			);
		};
		add_filter( 'posts_where', $cb, 10, 2 );
		try {
			return new \WP_Query( $args );
		} finally {
			remove_filter( 'posts_where', $cb, 10 );
		}
	}

	/**
	 * Work out the title text to filter on from `?event=` (a named preset) or
	 * `?contains=` (literal text). Empty string means "no title filter".
	 *
	 * @return string|\WP_Error
	 */
	private function resolve_contains( \WP_REST_Request $req ) {
		$event    = trim( (string) $req['event'] );
		$literal  = trim( (string) $req['contains'] );

		if ( '' !== $event && '' !== $literal ) {
			return new \WP_Error(
				'gasf_event_and_contains',
				__( 'Use either event (a named preset) or contains (literal title text), not both.', 'gasf-events' ),
				[ 'status' => 400 ]
			);
		}
		if ( '' !== $literal ) {
			return $literal;
		}
		if ( '' === $event ) {
			return '';
		}

		// `?event=FCBayern?instance=2` — a second "?" instead of "&". PHP hands us
		// the whole tail as the event name, which would otherwise surface as a
		// baffling "unknown preset: FCBayern?instance=2".
		if ( str_contains( $event, '?' ) ) {
			return new \WP_Error(
				'gasf_query_separator',
				sprintf(
					/* translators: %s: the corrected query string */
					__( 'Query parameters are separated by "&", not "?". Did you mean ?event=%s', 'gasf-events' ),
					str_replace( '?', '&', $event )
				),
				[ 'status' => 400 ]
			);
		}

		$key    = self::normalize_preset( $event );
		$filter = self::preset_filter( $key );
		if ( null === $filter ) {
			return new \WP_Error(
				'gasf_event_unknown',
				sprintf(
					/* translators: 1: the supplied name, 2: the valid preset names */
					__( 'Unknown event preset "%1$s". Available: %2$s. For any other title text use contains= instead.', 'gasf-events' ),
					$event,
					implode( ', ', self::EVENT_PRESETS )
				),
				[ 'status' => 400, 'available' => self::EVENT_PRESETS ]
			);
		}
		return $filter;
	}

	/**
	 * Fold a supplied preset name down to its canonical key. Everything that is
	 * not a letter or a digit is dropped, so "FC Bayern", "fc-bayern", "FCBayern"
	 * and "fc_bayern" are one name — the caller should not have to guess the
	 * punctuation we happened to pick.
	 */
	private static function normalize_preset( string $raw ): string {
		$key = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $raw ) );
		return self::PRESET_ALIASES[ $key ] ?? $key;
	}

	/** The configured title text for a preset key, or null if there is no such preset. */
	private static function preset_filter( string $key ): ?string {
		switch ( $key ) {
			case 'fcbayern':
				return Settings::bayern_filter();
			case 'dinner':
				return Settings::dinner_filter();
		}
		return null;
	}

	/** Parse a date/ISO/epoch string to a unix timestamp (site-tz aware for dates). */
	private static function ts( string $v ): int {
		$v = trim( $v );
		if ( '' === $v ) {
			return 0;
		}
		if ( ctype_digit( $v ) ) {
			return (int) $v;
		}
		// Plain Y-m-d → treat as site-local midnight.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return Meta::local_to_ts( $v . ' 00:00:00' );
		}
		$t = strtotime( $v );
		return $t ?: 0;
	}
}
