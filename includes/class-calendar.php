<?php
/**
 * Event query helpers — the one place views/feeds ask "which events?".
 * All queries use the numeric `_gasf_start_ts` (UTC) so they're DST-correct
 * and bounded. Returns Event objects.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Calendar {

	/** Upcoming events from now (or $from_ts), soonest first. */
	public static function upcoming( int $limit = 20, ?int $from_ts = null ): array {
		$from = $from_ts ?? time();
		return self::query(
			[ [ 'key' => Meta::END_TS, 'value' => $from, 'type' => 'NUMERIC', 'compare' => '>=' ] ],
			'ASC',
			$limit
		);
	}

	/** Events overlapping the given calendar month (multi-day aware). */
	public static function in_month( int $year, int $month ): array {
		$tz    = wp_timezone();
		try {
			$first = new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $month ), $tz );
		} catch ( \Exception $e ) {
			$first = new \DateTimeImmutable( 'first day of this month 00:00:00', $tz );
		}
		$last = $first->modify( 'last day of this month 23:59:59' );
		return self::range( $first->getTimestamp(), $last->getTimestamp() );
	}

	/** Events overlapping [from_ts, to_ts]. */
	public static function range( int $from_ts, int $to_ts ): array {
		return self::query( [
			'relation' => 'AND',
			[ 'key' => Meta::START_TS, 'value' => $to_ts, 'type' => 'NUMERIC', 'compare' => '<=' ],
			[ 'key' => Meta::END_TS,   'value' => $from_ts, 'type' => 'NUMERIC', 'compare' => '>=' ],
		], 'ASC', -1 );
	}

	/**
	 * @return Event[]
	 */
	private static function query( array $meta_query, string $order, int $limit ): array {
		$q = new \WP_Query( [
			'post_type'           => GASF_EVENTS_CPT,
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'meta_key'            => Meta::START_TS,
			'orderby'             => 'meta_value_num',
			'order'               => $order,
			'meta_query'          => $meta_query,
			'no_found_rows'       => true,
			'ignore_sticky_posts' => true,
		] );
		return array_map( static fn( $p ) => new Event( $p ), $q->posts );
	}

	/** Group a list of events by their start day (Y-m-d). */
	public static function group_by_day( array $events ): array {
		$out = [];
		foreach ( $events as $e ) {
			$s = $e->start();
			$key = $s ? $s->format( 'Y-m-d' ) : '0000-00-00';
			$out[ $key ][] = $e;
		}
		return $out;
	}
}
