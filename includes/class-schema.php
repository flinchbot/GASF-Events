<?php
/**
 * Event JSON-LD (schema.org/Event) for Google rich results.
 *
 * Single source of event structured data. Integrates into Yoast's @graph when
 * Yoast is active (so the Event node links to the WebPage/Organization nodes
 * and we avoid duplicate emission); falls back to a standalone <script> block
 * when Yoast is absent. Always carries organizer (name+url), image (cover →
 * default), offers (when priced), and eventStatus. See docs/ARCHITECTURE.md §5.7.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public function register_hooks(): void {
		add_filter( 'wpseo_schema_graph', [ $this, 'add_to_yoast_graph' ], 11, 2 );
		add_action( 'wp_footer', [ $this, 'fallback' ] );
	}

	/** Inject the Event node into Yoast's graph on single event pages. */
	public function add_to_yoast_graph( $graph, $context ) {
		if ( ! is_array( $graph ) || ! is_singular( GASF_EVENTS_CPT ) ) {
			return $graph;
		}
		$event = Event::get( get_queried_object_id() );
		if ( $event ) {
			$graph[] = self::node( $event );
		}
		return $graph;
	}

	/** Standalone JSON-LD when Yoast isn't present. */
	public function fallback(): void {
		if ( defined( 'WPSEO_VERSION' ) || ! is_singular( GASF_EVENTS_CPT ) ) {
			return; // Yoast handles it, or not an event page.
		}
		$event = Event::get( get_queried_object_id() );
		if ( ! $event ) {
			return;
		}
		echo "\n<script type=\"application/ld+json\">" .
			wp_json_encode( [ '@context' => 'https://schema.org', '@graph' => [ self::node( $event ) ] ] ) .
			"</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** Build the schema.org Event node. */
	public static function node( Event $event ): array {
		$start  = $event->start();
		$end    = $event->end();
		$status = $event->status();

		$node = [
			'@type'       => 'Event',
			'@id'         => $event->permalink() . '#event',
			'name'        => $event->title(),
			'description' => wp_strip_all_tags( $event->description() ),
			'url'         => $event->permalink(),
			'startDate'   => $event->start_iso(),
			'eventStatus' => self::status_url( $status ),
		];
		// Only emit a RASTER image — Google rejects SVG; omit rather than emit invalid.
		$img = $event->schema_image();
		if ( '' !== $img ) {
			$node['image'] = [ $img ];
		}
		if ( $end ) {
			$node['endDate'] = $event->end_iso();
		}

		// Attendance mode + location.
		if ( 'online_only' === $status ) {
			$node['eventAttendanceMode'] = 'https://schema.org/OnlineEventAttendanceMode';
			$node['location'] = [
				'@type' => 'VirtualLocation',
				'url'   => $event->online_link() ?: $event->permalink(),
			];
		} else {
			$node['eventAttendanceMode'] = 'https://schema.org/OfflineEventAttendanceMode';
			$v = $event->venue();
			$node['location'] = [
				'@type'   => 'Place',
				'name'    => $v['name'] ?? '',
				'address' => [
					'@type'           => 'PostalAddress',
					'streetAddress'   => $v['street'] ?? '',
					'addressLocality' => $v['city'] ?? '',
					'addressRegion'   => $v['state'] ?? '',
					'postalCode'      => $v['zip'] ?? '',
					'addressCountry'  => $v['country'] ?? 'US',
				],
			];
		}

		// Organizer — always name + URL.
		$o = $event->organizer();
		$node['organizer'] = [
			'@type' => 'Organization',
			'name'  => $o['name'] ?? '',
			'url'   => $o['url'] ?? home_url( '/' ),
		];

		// Offers — only when priced.
		$cost = $event->cost();
		if ( $cost > 0 ) {
			$node['offers'] = [
				'@type'         => 'Offer',
				'price'         => number_format( $cost, 2, '.', '' ),
				'priceCurrency' => 'USD',
				'availability'  => 'sold_out' === $status ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
				'url'           => $event->permalink(),
				'validFrom'     => get_post_time( 'c', true, $event->post() ),
			];
		}

		return $node;
	}

	private static function status_url( string $status ): string {
		switch ( $status ) {
			case 'cancelled':   return 'https://schema.org/EventCancelled';
			case 'postponed':   return 'https://schema.org/EventPostponed';
			case 'online_only': return 'https://schema.org/EventMovedOnline';
			default:            return 'https://schema.org/EventScheduled';
		}
	}
}
