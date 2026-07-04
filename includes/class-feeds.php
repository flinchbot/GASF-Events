<?php
/**
 * Feeds — the multi-feed ingest/route engine. Each feed has a TYPE
 * (facebook | ics) and one or more DESTINATIONS (GASF Calendar and/or Google
 * Calendar). One 15-min cron runs every enabled feed: fetch → upsert into the
 * GASF Calendar via Event_Ingest, and/or push to Google via Google_Calendar.
 *
 * Absorbs both the P5 Facebook importer and the legacy "Calendar Sync" MU
 * module into one home. Master-gated OFF by default. See ARCHITECTURE.md.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Feeds {

	const OPT_ENABLE   = 'gasf_events_enable_sync'; // master gate (default off)
	const OPT_FEEDS    = 'gasf_events_feeds';       // [ feed configs ]
	const OPT_GCAL     = 'gasf_events_gcal';        // { calendar_id }
	const OPT_LAST_RUN = 'gasf_events_last_run';
	const OPT_LOG      = 'gasf_events_sync_log';
	const CRON_HOOK    = 'gasf_events_sync';
	const SCHEDULE     = 'gasf_events_15min';
	const LOCK         = 'gasf_events_sync_lock';

	public function register_hooks(): void {
		add_filter( 'cron_schedules', [ $this, 'schedule' ] );
		add_action( 'init', [ $this, 'sync_cron_state' ] );
		add_action( self::CRON_HOOK, [ $this, 'run_cron' ] );
	}

	public function schedule( array $s ): array {
		$s[ self::SCHEDULE ] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Every 15 minutes (GASF)', 'gasf-events' ) ];
		return $s;
	}

	public function sync_cron_state(): void {
		$scheduled = (bool) wp_next_scheduled( self::CRON_HOOK );
		if ( self::enabled() && ! $scheduled ) {
			wp_schedule_event( time() + 60, self::SCHEDULE, self::CRON_HOOK );
		} elseif ( ! self::enabled() && $scheduled ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	public function run_cron(): void {
		if ( self::enabled() ) {
			self::run( false );
		}
	}

	/* ---- config ------------------------------------------------------- */

	public static function enabled(): bool {
		return (bool) get_option( self::OPT_ENABLE, false );
	}

	public static function feeds(): array {
		return array_values( (array) get_option( self::OPT_FEEDS, [] ) );
	}

	public static function gcal(): array {
		return wp_parse_args( (array) get_option( self::OPT_GCAL, [] ), [ 'calendar_id' => '' ] );
	}

	public static function soonest_expiry_days(): ?int {
		$soonest = null;
		foreach ( self::feeds() as $f ) {
			$exp = (int) ( $f['expire_at'] ?? 0 );
			if ( $exp > 0 ) {
				$d = (int) floor( ( $exp - time() ) / DAY_IN_SECONDS );
				$soonest = ( null === $soonest ) ? $d : min( $soonest, $d );
			}
		}
		return $soonest;
	}

	/* ---- run ---------------------------------------------------------- */

	public static function run( bool $dry = false, string $only_feed = '' ): array {
		$stats = [ 'created' => 0, 'updated' => 0, 'drafted' => 0, 'skipped' => 0, 'google' => [ 'inserted' => 0, 'updated' => 0, 'deleted' => 0 ], 'errors' => [], 'feeds' => [], 'dry' => $dry, 'ts' => time() ];

		// Atomic acquire (GET_LOCK) instead of the old check-then-set transient:
		// an overlapping cron tick + manual "Sync now" can no longer both pass.
		// The lock is connection-scoped, so it auto-releases at request end even
		// if a fetch/upsert throws (the old transient stayed stuck for 30 min).
		if ( ! $dry && ! Meta::acquire_lock( 'feeds', 0 ) ) {
			$stats['errors'][] = 'already running';
			return $stats;
		}
		$gcal = self::gcal();

		foreach ( self::feeds() as $feed ) {
			if ( empty( $feed['enabled'] ) || ( $only_feed && ( $feed['id'] ?? '' ) !== $only_feed ) ) {
				continue;
			}
			$fetch = self::fetch_feed( $feed );
			$fstat = [ 'label' => $feed['label'] ?? '?', 'type' => $feed['type'] ?? '?', 'fetched' => count( $fetch['events'] ), 'error' => $fetch['error'] ];
			if ( '' !== $fetch['error'] ) {
				$stats['errors'][] = ( $fstat['label'] . ': ' . $fetch['error'] );
				$stats['feeds'][]  = $fstat;
				continue; // never prune/delete when a fetch failed
			}

			// Optional per-feed title filter (case-insensitive CONTAINS) — e.g. the
			// Krampus site subscribes to the main calendar.ics with filter "Krampus".
			// Applied before both destinations, so prune_missing() stays consistent:
			// only filtered-in events ever carry this feed id.
			$filter = trim( (string) ( $feed['filter'] ?? '' ) );
			if ( '' !== $filter ) {
				$needle          = mb_strtolower( $filter );
				$fetch['events'] = array_values( array_filter(
					$fetch['events'],
					static fn( $ev ) => false !== mb_strpos( mb_strtolower( (string) ( $ev['title'] ?? '' ) ), $needle )
				) );
				$fstat['filter']  = $filter;
				$fstat['fetched'] = count( $fetch['events'] );
			}

			$source = ( 'ics' === ( $feed['type'] ?? '' ) ) ? 'ics' : 'facebook';

			// Destination: GASF Calendar.
			if ( ! empty( $feed['dest_gasf'] ) ) {
				$seen = [];
				foreach ( $fetch['events'] as $ev ) {
					$action = Event_Ingest::upsert( $ev, $source, (string) $feed['id'], $dry );
					if ( isset( $stats[ $action ] ) ) {
						$stats[ $action ]++;
					}
					$seen[] = Event_Ingest::key( $source, (string) $ev['uid'] );
				}
				// Fail-safe: a successful-but-EMPTY fetch is inconclusive, never a
				// signal to draft the whole feed. Only prune when we saw events.
				if ( $fetch['events'] ) {
					$stats['drafted'] += Event_Ingest::prune_missing( (string) $feed['id'], $seen, $dry );
				}
			}

			// Destination: Google Calendar. Scope on the STABLE feed id (not the
			// editable label) so renaming a feed doesn't orphan + duplicate.
			if ( ! empty( $feed['dest_google'] ) && $gcal['calendar_id'] && Google_Calendar::available() ) {
				$g = Google_Calendar::sync_source( (string) $feed['id'], (string) ( $feed['label'] ?? $feed['id'] ), $gcal['calendar_id'], $fetch['events'], $dry );
				if ( '' !== $g['error'] ) {
					$stats['errors'][] = ( $fstat['label'] . ' (google): ' . $g['error'] );
				} else {
					$stats['google']['inserted'] += $g['inserted'];
					$stats['google']['updated']  += $g['updated'];
					$stats['google']['deleted']  += $g['deleted'];
				}
			}

			$stats['feeds'][] = $fstat;
		}

		if ( ! $dry ) {
			update_option( self::OPT_LAST_RUN, $stats, false );
			Meta::release_lock( 'feeds' );
			self::log( $stats );
		}
		return $stats;
	}

	/**
	 * Fetch one feed into a uniform "superset event" list consumable by both
	 * destinations. @return array{events:array,error:string}
	 */
	private static function fetch_feed( array $feed ): array {
		if ( 'ics' === ( $feed['type'] ?? '' ) ) {
			// reject_unsafe_urls blocks redirects to private/loopback hosts (SSRF) on
			// the admin-supplied feed URL.
			$resp = wp_remote_get( (string) ( $feed['url'] ?? '' ), [ 'timeout' => 30, 'reject_unsafe_urls' => true ] );
			if ( is_wp_error( $resp ) ) {
				return [ 'events' => [], 'error' => $resp->get_error_message() ];
			}
			if ( 200 !== wp_remote_retrieve_response_code( $resp ) ) {
				return [ 'events' => [], 'error' => 'HTTP ' . wp_remote_retrieve_response_code( $resp ) ];
			}
			$events = [];
			foreach ( ICS_Parser::parse( wp_remote_retrieve_body( $resp ) ) as $rec ) {
				$norm = ICS_Parser::to_norm( $rec );
				if ( $norm ) {
					$norm['location'] = (string) ( $rec['location'] ?? '' );
					$norm['rrule']    = (string) ( $rec['rrule'] ?? '' );
					$events[]         = $norm;
				}
			}
			return [ 'events' => $events, 'error' => '' ];
		}

		// Facebook.
		$account = [
			'id'           => $feed['id'] ?? '',
			'label'        => $feed['label'] ?? '',
			'page_id'      => $feed['page_id'] ?? '',
			'access_token' => $feed['access_token'] ?? '',
		];
		$res = FB_Client::fetch_events( $account );
		if ( '' !== $res['error'] ) {
			return [ 'events' => [], 'error' => $res['error'] ];
		}
		$events = [];
		foreach ( $res['events'] as $fb_event ) {
			foreach ( $fb_event['occurrences'] as $occ ) {
				$events[] = [
					'uid'         => $occ['fb_id'],
					'title'       => $occ['name'],
					'description' => $occ['description'],
					'start'       => $occ['start'],
					'end'         => $occ['end'],
					'all_day'     => false,
					'status'      => $occ['is_canceled'] ? 'cancelled' : '',
					'cover_url'   => $occ['cover_url'],
					'cover_id'    => $occ['cover_id'],
					'is_series'   => ! empty( $occ['is_series'] ),
					'series_id'   => $occ['series_id'],
					'location'    => '',
					'rrule'       => '',
				];
			}
		}
		return [ 'events' => $events, 'error' => '' ];
	}

	/* ---- persistence helpers (used by the admin page) ----------------- */

	public static function save_feeds( array $feeds ): void {
		update_option( self::OPT_FEEDS, array_values( $feeds ), false );
	}

	public static function new_id(): string {
		return 'f' . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	private static function log( array $stats ): void {
		$line = sprintf(
			'[%s] created=%d updated=%d drafted=%d skipped=%d | google +%d/~%d/-%d | errors=%d',
			gmdate( 'c', $stats['ts'] ), $stats['created'], $stats['updated'], $stats['drafted'], $stats['skipped'],
			$stats['google']['inserted'], $stats['google']['updated'], $stats['google']['deleted'], count( $stats['errors'] )
		);
		$log = (array) get_option( self::OPT_LOG, [] );
		array_unshift( $log, $line );
		update_option( self::OPT_LOG, array_slice( $log, 0, 50 ), false );
	}
}
