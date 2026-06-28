<?php
/**
 * Minimal, dependency-free iCalendar (.ics) parser — line-unfolds, extracts
 * VEVENT blocks, and returns one record per event. Reused for inbound feed
 * ingest (→ GASF Calendar) and the outbound Google push. Ported from the
 * module-26 parser. See docs/ARCHITECTURE.md (multi-feed router).
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class ICS_Parser {

	/**
	 * @return array<int,array> rich VEVENT records (uid,summary,description,
	 *         location,url,dtstart,dtstart_allday,dtstart_tzid,dtend,…,rrule)
	 */
	public static function parse( string $raw ): array {
		$raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );
		// RFC 5545 line unfolding: a leading space/tab continues the prior line.
		$raw = preg_replace( '/\n[ \t]/', '', $raw );
		$lines = explode( "\n", $raw );

		$events = [];
		$cur    = null;
		$in_tz  = false;
		foreach ( $lines as $line ) {
			$u = strtoupper( $line );
			if ( 'BEGIN:VTIMEZONE' === $u ) { $in_tz = true; continue; }
			if ( 'END:VTIMEZONE' === $u )   { $in_tz = false; continue; }
			if ( $in_tz ) { continue; }
			if ( 'BEGIN:VEVENT' === $u ) { $cur = []; continue; }
			if ( 'END:VEVENT' === $u ) {
				if ( is_array( $cur ) && ! empty( $cur['uid'] ) ) {
					$events[] = $cur;
				}
				$cur = null;
				continue;
			}
			if ( null === $cur ) { continue; }

			$colon = strpos( $line, ':' );
			if ( false === $colon ) { continue; }
			$name_params = substr( $line, 0, $colon );
			$value       = substr( $line, $colon + 1 );
			$parts       = explode( ';', $name_params );
			$name        = strtoupper( array_shift( $parts ) );
			$params      = [];
			foreach ( $parts as $p ) {
				$kv = explode( '=', $p, 2 );
				if ( 2 === count( $kv ) ) {
					$params[ strtoupper( $kv[0] ) ] = $kv[1];
				}
			}

			switch ( $name ) {
				case 'UID':         $cur['uid'] = trim( $value ); break;
				case 'SUMMARY':     $cur['summary'] = self::unescape( $value ); break;
				case 'DESCRIPTION': $cur['description'] = self::unescape( $value ); break;
				case 'LOCATION':    $cur['location'] = self::unescape( $value ); break;
				case 'URL':         $cur['url'] = trim( $value ); break;
				case 'RRULE':       $cur['rrule'] = trim( $value ); break;
				case 'STATUS':      $cur['status'] = strtoupper( trim( $value ) ); break;
				case 'DTSTART':     self::set_dt( $cur, 'dtstart', $value, $params ); break;
				case 'DTEND':       self::set_dt( $cur, 'dtend', $value, $params ); break;
			}
		}
		return $events;
	}

	private static function set_dt( ?array &$cur, string $field, string $value, array $params ): void {
		$value   = trim( $value );
		$is_date = ( isset( $params['VALUE'] ) && 'DATE' === strtoupper( $params['VALUE'] ) ) || preg_match( '/^\d{8}$/', $value );
		if ( $is_date ) {
			$cur[ $field ]            = substr( $value, 0, 4 ) . '-' . substr( $value, 4, 2 ) . '-' . substr( $value, 6, 2 );
			$cur[ $field . '_allday' ] = true;
			return;
		}
		// Timed: YYYYMMDDTHHmmss[Z]
		$z = ( 'Z' === substr( $value, -1 ) );
		$v = rtrim( $value, 'Z' );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})$/', $v, $m ) ) {
			$cur[ $field ]             = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}" . ( $z ? 'Z' : '' );
			$cur[ $field . '_allday' ] = false;
			$cur[ $field . '_tzid' ]   = $z ? 'UTC' : ( $params['TZID'] ?? 'UTC' );
		}
	}

	private static function unescape( string $v ): string {
		return str_replace( [ '\\n', '\\N', '\\,', '\\;', '\\\\' ], [ "\n", "\n", ',', ';', '\\' ], trim( $v ) );
	}

	/* ---- Mapping to our normalized ingest shape ----------------------- */

	/**
	 * Convert a rich VEVENT record into the Event_Ingest normalized shape
	 * (start/end as site-local 'Y-m-d H:i:s'). Returns null if no usable start.
	 */
	public static function to_norm( array $rec ): ?array {
		$start = self::to_local( $rec['dtstart'] ?? '', ! empty( $rec['dtstart_allday'] ), $rec['dtstart_tzid'] ?? 'UTC' );
		if ( '' === $start ) {
			return null;
		}
		$all_day = ! empty( $rec['dtstart_allday'] );
		if ( ! empty( $rec['dtend'] ) ) {
			$end = self::to_local( $rec['dtend'], ! empty( $rec['dtend_allday'] ), $rec['dtend_tzid'] ?? 'UTC' );
			// ICS all-day DTEND is exclusive — pull back one second for our model.
			if ( $all_day && $end ) {
				try { $end = ( new \DateTimeImmutable( $end, wp_timezone() ) )->modify( '-1 second' )->format( 'Y-m-d H:i:s' ); } catch ( \Exception $e ) {}
			}
		} else {
			$end = $start;
		}

		$desc = (string) ( $rec['description'] ?? '' );
		if ( ! empty( $rec['url'] ) ) {
			$desc = trim( $desc . "\n\n" . $rec['url'] );
		}

		return [
			'uid'         => (string) $rec['uid'],
			'title'       => (string) ( $rec['summary'] ?? '(untitled)' ),
			'description' => $desc,
			'start'       => $start,
			'end'         => $end,
			'all_day'     => $all_day,
			'status'      => ( isset( $rec['status'] ) && 'CANCELLED' === $rec['status'] ) ? 'cancelled' : '',
			'cover_url'   => '',
			'cover_id'    => '',
			'is_series'   => false,
		];
	}

	private static function to_local( string $value, bool $all_day, string $tzid ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( $all_day ) {
			return $value . ' 00:00:00';
		}
		try {
			$tz = self::tz( $tzid );
			$dt = new \DateTimeImmutable( rtrim( $value, 'Z' ), $tz );
			return $dt->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' );
		} catch ( \Exception $e ) {
			return '';
		}
	}

	private static function tz( string $tzid ): \DateTimeZone {
		try {
			return new \DateTimeZone( $tzid ?: 'UTC' );
		} catch ( \Exception $e ) {
			return new \DateTimeZone( 'UTC' );
		}
	}
}
