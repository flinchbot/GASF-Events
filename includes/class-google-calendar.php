<?php
/**
 * Google Calendar destination — pushes a feed's events into a Google Calendar
 * with idempotent upsert (insert/update/delete keyed on extendedProperties).
 * Service-account JWT (RS256) auth. Reimplemented from module 26 so Calendar
 * Sync can be retired. Reuses the club's existing service-account key file.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Google_Calendar {

	const TOKEN_URL   = 'https://oauth2.googleapis.com/token';
	const API_BASE    = 'https://www.googleapis.com/calendar/v3';
	const SCOPE       = 'https://www.googleapis.com/auth/calendar';
	const MGR         = 'gasf-events'; // our marker (distinct from the old module's "calsync")
	const TOKEN_CACHE = 'gasf_events_gcal_token';

	/** Default service-account key path (outside web root); override via constant. */
	public static function key_path(): string {
		return defined( 'GASF_CALSYNC_KEY' ) ? GASF_CALSYNC_KEY : ( dirname( ABSPATH ) . '/gasf-calsync-key.json' );
	}

	public static function available(): bool {
		return is_readable( self::key_path() ) && function_exists( 'openssl_sign' );
	}

	/**
	 * Push $events (Event_Ingest normalized shape, + optional tzid/rrule/location)
	 * to $calendar_id, tagged with $label. Returns stats.
	 *
	 * $prefix controls the title prepend: '' = default "[label] " (back-compat,
	 * matches the old Calendar Sync tags), any text = that text + space,
	 * '-' = no prefix at all. $color is a Google event colorId '1'–'11'
	 * ('' = calendar default) — the old Calendar Sync per-source colors.
	 *
	 * @return array{inserted:int,updated:int,deleted:int,error:string}
	 */
	public static function sync_source( string $feed_id, string $label, string $calendar_id, array $events, bool $dry = false, string $prefix = '', string $color = '' ): array {
		$out = [ 'inserted' => 0, 'updated' => 0, 'deleted' => 0, 'error' => '' ];
		if ( '' === $calendar_id ) {
			$out['error'] = 'no calendar_id';
			return $out;
		}
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			$out['error'] = $token->get_error_message();
			return $out;
		}

		$managed = self::list_managed( $calendar_id, $feed_id, $token ); // uid => google event
		$seen    = [];
		foreach ( $events as $ev ) {
			$uid = (string) ( $ev['uid'] ?? '' );
			if ( '' === $uid ) {
				continue;
			}
			$seen[ $uid ] = true;
			$body = self::build_body( $feed_id, $label, $ev, $prefix, $color );
			if ( isset( $managed[ $uid ] ) ) {
				if ( self::differs( $managed[ $uid ], $body ) ) {
					$out['updated']++;
					if ( ! $dry ) {
						self::api( 'PUT', "/calendars/" . rawurlencode( $calendar_id ) . "/events/" . rawurlencode( $managed[ $uid ]['id'] ), $body, $token );
					}
				}
			} else {
				$out['inserted']++;
				if ( ! $dry ) {
					self::api( 'POST', "/calendars/" . rawurlencode( $calendar_id ) . "/events", $body, $token );
				}
			}
		}
		// Delete managed events no longer in the feed (fail-safe: only if we have events).
		if ( $events ) {
			foreach ( $managed as $uid => $g ) {
				if ( ! isset( $seen[ $uid ] ) ) {
					$out['deleted']++;
					if ( ! $dry ) {
						self::api( 'DELETE', "/calendars/" . rawurlencode( $calendar_id ) . "/events/" . rawurlencode( $g['id'] ), null, $token );
					}
				}
			}
		}
		return $out;
	}

	/* ---- Auth --------------------------------------------------------- */

	private static function token() {
		$cached = get_transient( self::TOKEN_CACHE );
		if ( $cached ) {
			return $cached;
		}
		$key = json_decode( (string) @file_get_contents( self::key_path() ), true ); // phpcs:ignore
		if ( ! is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) ) {
			return new \WP_Error( 'gcal_key', 'service-account key missing/invalid' );
		}
		$now    = time();
		$claims = [
			'iss'   => $key['client_email'],
			'scope' => self::SCOPE,
			'aud'   => self::TOKEN_URL,
			'iat'   => $now,
			'exp'   => $now + 3600,
		];
		$jwt_unsigned = self::b64( wp_json_encode( [ 'alg' => 'RS256', 'typ' => 'JWT' ] ) ) . '.' . self::b64( wp_json_encode( $claims ) );
		$sig = '';
		if ( ! openssl_sign( $jwt_unsigned, $sig, $key['private_key'], OPENSSL_ALGO_SHA256 ) ) {
			return new \WP_Error( 'gcal_sign', 'JWT signing failed' );
		}
		$jwt  = $jwt_unsigned . '.' . self::b64( $sig );
		$resp = wp_remote_post( self::TOKEN_URL, [
			'timeout' => 30,
			'body'    => [ 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt ],
		] );
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( empty( $data['access_token'] ) ) {
			return new \WP_Error( 'gcal_token', 'token exchange failed: ' . ( $data['error_description'] ?? 'unknown' ) );
		}
		$ttl = max( 60, (int) ( $data['expires_in'] ?? 3600 ) - 60 );
		set_transient( self::TOKEN_CACHE, $data['access_token'], $ttl );
		return $data['access_token'];
	}

	/* ---- API ---------------------------------------------------------- */

	/**
	 * Authenticated GET for read-side consumers — e.g. the GASF-Utilities
	 * internal-calendar print view, which lists the destination calendar
	 * (including staff-added events). Returns decoded array or WP_Error.
	 */
	public static function api_get( string $path ) {
		$token = self::token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		return self::api( 'GET', $path, null, $token );
	}

	private static function api( string $method, string $path, ?array $body, string $token ) {
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ],
		];
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		// Bounded retry: 3 attempts, backoff capped at 4s, and no sleep after the
		// final attempt. sync_source() calls this serially per event, so an
		// unbounded loop (was 5 attempts + 1+2+4+8+16s sleeps) could blow past
		// max_execution_time mid-run on a flapping API.
		$delay = 1;
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
			$resp = wp_remote_request( self::API_BASE . $path, $args );
			if ( is_wp_error( $resp ) ) {
				return $resp;
			}
			$code = wp_remote_retrieve_response_code( $resp );
			if ( $code < 400 ) {
				return json_decode( wp_remote_retrieve_body( $resp ), true ) ?: [];
			}
			if ( ! in_array( $code, [ 403, 429, 500, 502, 503, 504 ], true ) ) {
				return new \WP_Error( 'gcal_api', 'HTTP ' . $code );
			}
			if ( $attempt < 2 ) {
				usleep( (int) ( $delay * 1e6 ) );
				$delay = min( $delay * 2, 4 );
			}
		}
		return new \WP_Error( 'gcal_api', 'exhausted retries' );
	}

	private static function list_managed( string $calendar_id, string $feed_id, string $token ): array {
		$out  = [];
		$page = '';
		do {
			$args = [
				// Google expects this parameter REPEATED (…&privateExtendedProperty=a&privateExtendedProperty=b).
				// http_build_query() would emit privateExtendedProperty[0]=…, which Google
				// silently ignores — list_managed() would then return EVERY gasf_uid event
				// in the calendar and the delete pass would eat other feeds' events.
				'privateExtendedProperty' => [ 'gasf_mgr=' . self::MGR, 'gasf_src=' . $feed_id ],
				'showDeleted'             => 'false',
				'singleEvents'            => 'false',
				'maxResults'              => 250,
			];
			if ( $page ) {
				$args['pageToken'] = $page;
			}
			$pairs = [];
			foreach ( $args as $k => $v ) {
				foreach ( (array) $v as $vv ) {
					$pairs[] = rawurlencode( $k ) . '=' . rawurlencode( (string) $vv );
				}
			}
			$url  = "/calendars/" . rawurlencode( $calendar_id ) . "/events?" . implode( '&', $pairs );
			$resp = self::api( 'GET', $url, null, $token );
			if ( is_wp_error( $resp ) ) {
				break;
			}
			foreach ( (array) ( $resp['items'] ?? [] ) as $item ) {
				$uid = $item['extendedProperties']['private']['gasf_uid'] ?? '';
				if ( $uid ) {
					$out[ $uid ] = [ 'id' => $item['id'], 'hash' => (string) ( $item['extendedProperties']['private']['gasf_hash'] ?? '' ) ];
				}
			}
			$page = $resp['nextPageToken'] ?? '';
		} while ( $page );
		return $out;
	}

	private static function build_body( string $feed_id, string $label, array $ev, string $prefix = '', string $color = '' ): array {
		$all_day = ! empty( $ev['all_day'] );
		$tz      = wp_timezone_string();
		$pfx     = ( '-' === $prefix ) ? '' : ( '' !== $prefix ? $prefix . ' ' : '[' . $label . '] ' );
		$body    = [
			'summary'     => $pfx . wp_strip_all_tags( (string) ( $ev['title'] ?? '' ) ),
			'description' => (string) ( $ev['description'] ?? '' ),
			'location'    => (string) ( $ev['location'] ?? '' ),
		];
		if ( '' !== $color && (int) $color >= 1 && (int) $color <= 11 ) {
			$body['colorId'] = (string) (int) $color;
		}
		if ( $all_day ) {
			// Google's all-day end.date is EXCLUSIVE, but our stored end is the
			// inclusive last day. Add one day, or a single-day all-day event comes
			// out zero-length (start==end) and multi-day ones land a day short.
			$end_day = substr( (string) ( $ev['end'] ?: $ev['start'] ), 0, 10 );
			$end_dt  = $end_day ? date_create( $end_day ) : false;
			$body['start'] = [ 'date' => substr( (string) $ev['start'], 0, 10 ) ];
			$body['end']   = [ 'date' => $end_dt ? $end_dt->modify( '+1 day' )->format( 'Y-m-d' ) : $end_day ];
		} else {
			$body['start'] = [ 'dateTime' => str_replace( ' ', 'T', (string) $ev['start'] ), 'timeZone' => $tz ];
			$body['end']   = [ 'dateTime' => str_replace( ' ', 'T', (string) ( $ev['end'] ?: $ev['start'] ) ), 'timeZone' => $tz ];
		}
		if ( ! empty( $ev['rrule'] ) ) {
			$body['recurrence'] = [ 'RRULE:' . $ev['rrule'] ];
		}
		// Content hash over everything we sync, so updates to description/location/
		// rrule/color (not just summary/time) are detected. Scope on the stable feed id.
		$hash = md5( wp_json_encode( [ $body['summary'], $body['description'], $body['location'], $body['start'], $body['end'], $body['recurrence'] ?? [], $body['colorId'] ?? '' ] ) );
		$body['extendedProperties'] = [ 'private' => [ 'gasf_mgr' => self::MGR, 'gasf_src' => $feed_id, 'gasf_uid' => (string) ( $ev['uid'] ?? '' ), 'gasf_hash' => $hash ] ];
		return $body;
	}

	private static function differs( array $existing, array $body ): bool {
		$new_hash = $body['extendedProperties']['private']['gasf_hash'] ?? '';
		return ( $existing['hash'] ?? '' ) !== $new_hash;
	}

	private static function b64( string $s ): string {
		return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
	}
}
