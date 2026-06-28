<?php
/**
 * Recurrence date math — pure, no WordPress side effects.
 *
 * Expands a start datetime + a simple rule (weekly / biweekly / monthly-by-
 * weekday) into a flat list of occurrence datetimes. The series is then
 * MATERIALIZED as individual posts (see class-series.php) — we never expand
 * on read. See docs/ARCHITECTURE.md §4.7.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Recurrence {

	/**
	 * Build the full list of occurrence datetimes, INCLUDING the first ($start).
	 *
	 * @param \DateTimeImmutable $start Anchor (carries the time-of-day).
	 * @param string             $freq  weekly|biweekly|monthly.
	 * @param string             $until Y-m-d inclusive end, or '' for none.
	 * @param int                $count Number of occurrences incl. first, or 0 for none.
	 * @return \DateTimeImmutable[]
	 */
	public static function occurrences( \DateTimeImmutable $start, string $freq, string $until = '', int $count = 0 ): array {
		if ( ! in_array( $freq, [ 'weekly', 'biweekly', 'monthly' ], true ) ) {
			return [ $start ];
		}

		// Resolve the stopping conditions. If neither given, cap at one year.
		$until_dt = '' !== $until
			? self::end_of_day( $until )
			: $start->modify( '+1 year' );
		$limit = $count > 0 ? min( $count, Meta::REPEAT_MAX ) : Meta::REPEAT_MAX;

		$dates  = [];
		$cursor = $start;
		$weekday = (int) $start->format( 'N' );          // 1..7
		$nth     = (int) ceil( (int) $start->format( 'j' ) / 7 ); // 1..5 (for monthly)
		$i       = 0;

		while ( count( $dates ) < $limit ) {
			if ( $cursor instanceof \DateTimeImmutable ) {
				if ( $cursor > $until_dt ) {
					break;
				}
				$dates[] = $cursor;
			}
			$i++;
			if ( 'weekly' === $freq ) {
				$cursor = $start->modify( '+' . $i . ' week' );
			} elseif ( 'biweekly' === $freq ) {
				$cursor = $start->modify( '+' . ( $i * 2 ) . ' week' );
			} else { // monthly: same Nth weekday in each subsequent month.
				$month  = $start->modify( 'first day of +' . $i . ' month' );
				// Honor `until` even when months are skipped (no Nth weekday): once the
				// candidate month itself is past `until`, stop — don't spin to the cap.
				if ( $month > $until_dt ) {
					break;
				}
				$cursor = self::nth_weekday( (int) $month->format( 'Y' ), (int) $month->format( 'n' ), $weekday, $nth, $start->format( 'H:i:s' ) );
				// $cursor may be null when that month has no Nth weekday (e.g. 5th) — skip it.
			}
			// Safety: never loop past the runaway cap regardless of conditions.
			if ( $i > Meta::REPEAT_MAX * 2 ) {
				break;
			}
		}

		return $dates;
	}

	/** Occurrences AFTER the anchor (everything except the first). */
	public static function after_first( \DateTimeImmutable $start, string $freq, string $until = '', int $count = 0 ): array {
		$all = self::occurrences( $start, $freq, $until, $count );
		array_shift( $all );
		return $all;
	}

	/**
	 * The Nth occurrence of a weekday in a given month, at a given time.
	 * Returns null if the month has no such Nth weekday.
	 */
	private static function nth_weekday( int $year, int $month, int $weekday, int $nth, string $time ): ?\DateTimeImmutable {
		$first = ( new \DateTimeImmutable( sprintf( '%04d-%02d-01 %s', $year, $month, $time ), wp_timezone() ) );
		$first_weekday = (int) $first->format( 'N' );
		$offset = ( $weekday - $first_weekday + 7 ) % 7;
		$day = 1 + $offset + ( $nth - 1 ) * 7;
		$dim = (int) $first->format( 't' );
		if ( $day > $dim ) {
			return null;
		}
		return $first->setDate( $year, $month, $day );
	}

	private static function end_of_day( string $ymd ): \DateTimeImmutable {
		try {
			return new \DateTimeImmutable( $ymd . ' 23:59:59', wp_timezone() );
		} catch ( \Exception $e ) {
			return ( new \DateTimeImmutable( 'now', wp_timezone() ) )->modify( '+1 year' );
		}
	}

	/** Human label for a frequency. */
	public static function label( string $freq ): string {
		switch ( $freq ) {
			case 'weekly':   return __( 'Weekly', 'gasf-events' );
			case 'biweekly': return __( 'Every 2 weeks', 'gasf-events' );
			case 'monthly':  return __( 'Monthly (same weekday)', 'gasf-events' );
			default:         return __( 'Does not repeat', 'gasf-events' );
		}
	}
}
