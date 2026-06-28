<?php
/**
 * Native Facebook → gasf_event importer. Replaces the third-party
 * mec-advanced-importer plus MU Modules A–F and most of Module H.
 *
 * Gated OFF by default (`gasf_events_enable_sync`). Facebook is source of truth
 * EXCEPT for pinned events (`_gasf_sync_locked`). Upsert is keyed on
 * `_gasf_fb_event_id`; recurring events become flat occurrences in a series;
 * vanished events auto-draft after 2 misses; covers de-dup by SHA1.
 * See docs/ARCHITECTURE.md §6.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class FB_Importer {

	const OPT_ENABLE   = 'gasf_events_enable_sync';   // master gate (default off)
	const OPT_ACCOUNTS = 'gasf_events_fb_accounts';   // [ {id,label,page_id,access_token,expire_at} ]
	const OPT_LAST_RUN = 'gasf_events_last_run';      // audit + status
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

	/** Keep the cron registered iff the gate is on. */
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

	public static function enabled(): bool {
		return (bool) get_option( self::OPT_ENABLE, false );
	}

	public static function accounts(): array {
		return array_values( (array) get_option( self::OPT_ACCOUNTS, [] ) );
	}

	/* ---- The run ------------------------------------------------------ */

	/**
	 * @return array stats { created, updated, drafted, skipped, errors[], accounts[] }
	 */
	public static function run( bool $dry = false ): array {
		$stats = [ 'created' => 0, 'updated' => 0, 'drafted' => 0, 'skipped' => 0, 'errors' => [], 'accounts' => [], 'dry' => $dry, 'ts' => time() ];

		if ( ! $dry && get_transient( self::LOCK ) ) {
			$stats['errors'][] = 'already running';
			return $stats;
		}
		if ( ! $dry ) {
			set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		}

		$seen      = [];
		$ok_accts  = [];
		foreach ( self::accounts() as $account ) {
			$res = FB_Client::fetch_events( $account );
			$acct_stat = [ 'label' => $account['label'] ?? ( $account['page_id'] ?? '?' ), 'fetched' => count( $res['events'] ), 'error' => $res['error'] ];
			$stats['accounts'][] = $acct_stat;
			if ( '' !== $res['error'] ) {
				$stats['errors'][] = ( $acct_stat['label'] . ': ' . $res['error'] );
				continue; // don't run deletion for an account that failed to fetch
			}
			$ok_accts[ (string) ( $account['id'] ?? '' ) ] = true;
			foreach ( $res['events'] as $event ) {
				foreach ( $event['occurrences'] as $occ ) {
					$seen[ $occ['fb_id'] ] = true;
					$action = self::upsert( $occ, $dry );
					if ( isset( $stats[ $action ] ) ) {
						$stats[ $action ]++;
					}
				}
			}
		}

		// Deletion pass: upcoming FB events we manage but didn't see this run.
		$stats['drafted'] += self::draft_missing( $seen, array_keys( $ok_accts ), $dry );

		if ( ! $dry ) {
			update_option( self::OPT_LAST_RUN, $stats, false );
			delete_transient( self::LOCK );
			self::log( $stats );
		}
		return $stats;
	}

	/**
	 * Create or update one occurrence. Returns 'created'|'updated'|'skipped'.
	 */
	private static function upsert( array $occ, bool $dry ): string {
		$existing = self::find_by_fb_id( $occ['fb_id'] );

		// Pinned events: hands off content entirely (still counts as seen).
		if ( $existing && get_post_meta( $existing, Meta::SYNC_LOCKED, true ) ) {
			if ( ! $dry ) {
				update_post_meta( $existing, Meta::FB_MISSING, 0 );
			}
			return 'skipped';
		}

		if ( $dry ) {
			return $existing ? 'updated' : 'created';
		}

		$status = $occ['is_canceled'] ? 'cancelled' : '';
		$postarr = [
			'post_type'    => GASF_EVENTS_CPT,
			'post_status'  => 'publish',
			'post_title'   => wp_strip_all_tags( $occ['name'] ),
			'post_content' => $occ['description'],
		];
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$action = 'updated';
		} else {
			$action = 'created';
		}
		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 'skipped';
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, Meta::SOURCE, 'facebook' );
		update_post_meta( $post_id, Meta::FB_EVENT_ID, $occ['fb_id'] );
		update_post_meta( $post_id, Meta::FB_ACCOUNT, $occ['account_id'] );
		update_post_meta( $post_id, Meta::START, $occ['start'] );
		update_post_meta( $post_id, Meta::END, $occ['end'] ?: $occ['start'] );
		update_post_meta( $post_id, Meta::ALL_DAY, 0 );
		update_post_meta( $post_id, Meta::STATUS, $status );
		update_post_meta( $post_id, Meta::FB_MISSING, 0 );
		Meta::recompute_timestamps( $post_id );

		// Series membership (recurring FB event → flat occurrences sharing series id).
		if ( ! empty( $occ['is_series'] ) ) {
			update_post_meta( $post_id, Meta::SERIES_ID, $occ['series_id'] );
			update_post_meta( $post_id, Meta::SERIES_ROLE, 'occurrence' );
		} else {
			update_post_meta( $post_id, Meta::SERIES_ROLE, 'single' );
		}

		// Snapshot of FB-synced fields → lets the editor auto-pin on manual edits.
		update_post_meta( $post_id, Meta::FB_SNAPSHOT, self::snapshot( $occ['name'], $occ['description'], $occ['start'], $occ['end'] ) );

		// Cover (de-duped by SHA1).
		if ( ! empty( $occ['cover_url'] ) ) {
			Cover_Sideloader::sync( $post_id, $occ['cover_url'], $occ['cover_id'] );
		}

		return $action;
	}

	/**
	 * Increment the miss counter on upcoming managed FB events not seen this
	 * run; draft after 2 consecutive misses. Only considers events whose
	 * account fetched successfully.
	 */
	private static function draft_missing( array $seen, array $ok_account_ids, bool $dry ): int {
		if ( ! $ok_account_ids ) {
			return 0;
		}
		$ids = get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => [
				'relation' => 'AND',
				[ 'key' => Meta::FB_EVENT_ID, 'compare' => 'EXISTS' ],
				[ 'key' => Meta::END_TS, 'value' => time(), 'type' => 'NUMERIC', 'compare' => '>=' ],
			],
		] );

		$drafted = 0;
		foreach ( $ids as $id ) {
			$fb_id = (string) get_post_meta( $id, Meta::FB_EVENT_ID, true );
			if ( isset( $seen[ $fb_id ] ) ) {
				continue;
			}
			// Only manage events whose account actually fetched this run.
			$acct = (string) get_post_meta( $id, Meta::FB_ACCOUNT, true );
			if ( $acct && ! in_array( $acct, $ok_account_ids, true ) ) {
				continue;
			}
			if ( get_post_meta( $id, Meta::SYNC_LOCKED, true ) ) {
				continue; // pinned events are admin-owned.
			}
			$misses = (int) get_post_meta( $id, Meta::FB_MISSING, true ) + 1;
			if ( $dry ) {
				if ( $misses >= 2 ) {
					$drafted++;
				}
				continue;
			}
			if ( $misses >= 2 ) {
				wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
				$drafted++;
			} else {
				update_post_meta( $id, Meta::FB_MISSING, $misses );
			}
		}
		return $drafted;
	}

	/* ---- helpers ------------------------------------------------------ */

	public static function find_by_fb_id( string $fb_id ): int {
		if ( '' === $fb_id ) {
			return 0;
		}
		$ids = get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'meta_key'         => Meta::FB_EVENT_ID,
			'meta_value'       => $fb_id,
			'suppress_filters' => true,
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function snapshot( string $name, string $desc, string $start, string $end ): string {
		return md5( wp_strip_all_tags( $name ) . '|' . wp_strip_all_tags( $desc ) . '|' . $start . '|' . $end );
	}

	private static function log( array $stats ): void {
		$line = sprintf(
			'[%s] sync: created=%d updated=%d drafted=%d skipped=%d errors=%d',
			gmdate( 'c', $stats['ts'] ),
			$stats['created'], $stats['updated'], $stats['drafted'], $stats['skipped'], count( $stats['errors'] )
		);
		// Keep a short rolling tail in an option (no file writes in the web root).
		$log = (array) get_option( 'gasf_events_sync_log', [] );
		array_unshift( $log, $line );
		update_option( 'gasf_events_sync_log', array_slice( $log, 0, 50 ), false );
	}

	/** Days until the soonest token expiry (or null if unknown). For the status UI. */
	public static function soonest_expiry_days(): ?int {
		$soonest = null;
		foreach ( self::accounts() as $a ) {
			$exp = (int) ( $a['expire_at'] ?? 0 );
			if ( $exp > 0 ) {
				$d = (int) floor( ( $exp - time() ) / DAY_IN_SECONDS );
				$soonest = ( null === $soonest ) ? $d : min( $soonest, $d );
			}
		}
		return $soonest;
	}
}
