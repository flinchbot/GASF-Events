<?php
/**
 * Public REST feed — the clean, owned contract the kiosk (and any consumer)
 * reads instead of scraping. `GET /wp-json/gasf-events/v1/events` with
 * from/to/limit/updated_since. Read-only, published events only, cache-friendly.
 * `updated_since` enables incremental kiosk sync. See docs/ARCHITECTURE.md §7.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NS = 'gasf-events/v1';

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
				'updated_since' => [ 'sanitize_callback' => 'sanitize_text_field' ],
			],
			'callback'            => [ $this, 'events' ],
		] );
		register_rest_route( self::NS, '/events/(?P<id>\d+)', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => [ $this, 'single' ],
		] );
	}

	public function events( \WP_REST_Request $req ): \WP_REST_Response {
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
			'order'               => 'ASC',
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
		$data = array_map( [ $this, 'to_json' ], array_map( static fn( $p ) => new Event( $p ), $q->posts ) );

		$resp = new \WP_REST_Response( $data );
		$resp->header( 'X-GASF-Count', (string) count( $data ) );
		$resp->header( 'Cache-Control', 'public, max-age=120' );
		return $resp;
	}

	public function single( \WP_REST_Request $req ) {
		$e = Event::get( (int) $req['id'] );
		if ( ! $e || 'publish' !== get_post_status( $e->id() ) ) {
			return new \WP_REST_Response( [ 'error' => 'not_found' ], 404 );
		}
		return new \WP_REST_Response( $this->to_json( $e ) );
	}

	/** Normalized event JSON — the stable contract. */
	public function to_json( Event $e ): array {
		return [
			'id'          => $e->id(),
			'title'       => $e->title(),
			'slug'        => $e->post()->post_name,
			'url'         => $e->permalink(),
			'start'       => $e->start_iso(),
			'end'         => $e->end_iso(),
			'all_day'     => $e->is_all_day(),
			'status'      => $e->status() ?: 'scheduled',
			'image'       => $e->cover_url( 'large' ),
			'description' => wp_strip_all_tags( $e->description() ),
			'organizer'   => $e->organizer(),
			'venue'       => $e->venue(),
			'series_id'   => $e->series_id(),
			'source'      => $e->source(),
			'modified'    => get_post_modified_time( 'c', true, $e->post() ),
		];
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
