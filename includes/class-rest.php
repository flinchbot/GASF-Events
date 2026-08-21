<?php
/**
 * Public REST feed — the clean, owned contract the kiosk (and any consumer)
 * reads instead of scraping. `GET /wp-json/gasf-events/v1/events` with
 * from/to/limit/order/fields/updated_since. Read-only, published events only,
 * cache-friendly. `updated_since` enables incremental kiosk sync.
 * See docs/ARCHITECTURE.md §7.
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
				'limit'         => [ 'default' => 100, 'sanitize_callback' => 'absint' ],
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
		$limit = min( 500, max( 1, (int) $req['limit'] ) );
		$from  = (string) $req['from'];
		$to    = (string) $req['to'];

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

		if ( ! empty( $req['updated_since'] ) ) {
			$ts = self::ts( (string) $req['updated_since'] );
			if ( $ts ) {
				$args['date_query'] = [ [ 'column' => 'post_modified_gmt', 'after' => gmdate( 'Y-m-d H:i:s', $ts ), 'inclusive' => false ] ];
			}
		}

		$q    = new \WP_Query( $args );
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
