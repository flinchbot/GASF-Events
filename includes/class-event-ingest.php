<?php
/**
 * Event_Ingest — the ONE path that writes external events into the GASF
 * Calendar. Facebook, ICS feeds, (later) Eventbrite-inbound, and manual edits
 * all upsert through here, so dedup, provenance, the pin rules, cover de-dup,
 * series grouping, and the auto-pin snapshot live in exactly one place.
 *
 * Dedup is namespaced per source via `_gasf_source_uid = "<source>|<uid>"`,
 * so feed A's events can never collide with feed B's. See docs/ARCHITECTURE.md
 * §6 + the multi-feed router section.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Event_Ingest {

	/**
	 * Normalized event shape produced by every feed driver:
	 *   uid, title, description, start (Y-m-d H:i:s local), end, all_day(bool),
	 *   status(''|cancelled|…), cover_url, cover_id, series_id, is_series(bool)
	 *
	 * @return string 'created'|'updated'|'skipped'
	 */
	public static function upsert( array $norm, string $source, string $feed_id, bool $dry = false ): string {
		$uid = (string) ( $norm['uid'] ?? '' );
		if ( '' === $uid || '' === (string) ( $norm['start'] ?? '' ) ) {
			return 'skipped';
		}
		$key      = self::key( $source, $uid );
		$existing = self::find( $source, $uid );

		// Pinned events are admin-owned: hands off content, just keep them alive.
		if ( $existing && get_post_meta( $existing, Meta::SYNC_LOCKED, true ) ) {
			if ( ! $dry ) {
				update_post_meta( $existing, Meta::FB_MISSING, 0 );
			}
			return 'skipped';
		}
		if ( $dry ) {
			return $existing ? 'updated' : 'created';
		}

		$postarr = [
			'post_type'    => GASF_EVENTS_CPT,
			'post_status'  => 'publish',
			'post_title'   => wp_strip_all_tags( (string) $norm['title'] ),
			// Remote (Facebook/ICS) HTML: sanitize here — cron has no user context so
			// WP's own kses pass is unreliable; never store unfiltered remote markup.
			'post_content' => wp_kses_post( (string) ( $norm['description'] ?? '' ) ),
		];
		$action = 'created';
		if ( $existing ) {
			$postarr['ID'] = $existing;
			$action        = 'updated';
		}
		$post_id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 'skipped';
		}
		$post_id = (int) $post_id;

		update_post_meta( $post_id, Meta::SOURCE, $source );
		update_post_meta( $post_id, Meta::SOURCE_UID, $key );
		update_post_meta( $post_id, Meta::SOURCE_FEED, $feed_id );
		if ( 'facebook' === $source ) {
			update_post_meta( $post_id, Meta::FB_EVENT_ID, $uid ); // FB-specific UI relies on this.
		}

		$start = (string) $norm['start'];
		$end   = (string) ( $norm['end'] ?? '' ) ?: $start;
		update_post_meta( $post_id, Meta::START, $start );
		update_post_meta( $post_id, Meta::END, $end );
		update_post_meta( $post_id, Meta::ALL_DAY, ! empty( $norm['all_day'] ) ? 1 : 0 );
		update_post_meta( $post_id, Meta::STATUS, in_array( $norm['status'] ?? '', Meta::STATUSES, true ) ? $norm['status'] : '' );
		update_post_meta( $post_id, Meta::FB_MISSING, 0 );
		Meta::recompute_timestamps( $post_id );

		if ( ! empty( $norm['is_series'] ) && ! empty( $norm['series_id'] ) ) {
			update_post_meta( $post_id, Meta::SERIES_ID, (string) $norm['series_id'] );
			update_post_meta( $post_id, Meta::SERIES_ROLE, 'occurrence' );
		} else {
			update_post_meta( $post_id, Meta::SERIES_ROLE, 'single' );
		}

		// Snapshot from the STORED values (post-kses/slashing), reading exactly what
		// the editor's auto-pin will read back — otherwise every synced event would
		// self-pin on the first manual save due to entity/normalization drift.
		update_post_meta( $post_id, Meta::FB_SNAPSHOT, self::snapshot_post( $post_id ) );

		if ( ! empty( $norm['cover_url'] ) ) {
			Cover_Sideloader::sync( $post_id, (string) $norm['cover_url'], (string) ( $norm['cover_id'] ?? '' ) );
		}

		return $action;
	}

	/** Find an existing event by source + external uid. */
	public static function find( string $source, string $uid ): int {
		return Meta::find_id_by( Meta::SOURCE_UID, self::key( $source, $uid ) );
	}

	/**
	 * Snapshot hash from a post's STORED title/content/start/end — used by the
	 * editor's auto-pin so both sides hash identical (post-kses) bytes.
	 */
	public static function snapshot_post( int $post_id ): string {
		return self::snapshot(
			(string) get_post_field( 'post_title', $post_id ),
			(string) get_post_field( 'post_content', $post_id ),
			(string) get_post_meta( $post_id, Meta::START, true ),
			(string) get_post_meta( $post_id, Meta::END, true )
		);
	}

	/**
	 * Increment the miss counter on upcoming events from $feed_id not seen this
	 * run; draft after 2 misses. Pinned events are exempt. Returns # drafted.
	 *
	 * @param string[] $seen_keys SOURCE_UID values seen this run.
	 */
	public static function prune_missing( string $feed_id, array $seen_keys, bool $dry = false ): int {
		$seen = array_fill_keys( $seen_keys, true );
		$ids  = get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'publish',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => [
				'relation' => 'AND',
				[ 'key' => Meta::SOURCE_FEED, 'value' => $feed_id ],
				[ 'key' => Meta::END_TS, 'value' => time(), 'type' => 'NUMERIC', 'compare' => '>=' ],
			],
		] );

		$drafted = 0;
		foreach ( $ids as $id ) {
			if ( isset( $seen[ (string) get_post_meta( $id, Meta::SOURCE_UID, true ) ] ) ) {
				continue;
			}
			if ( get_post_meta( $id, Meta::SYNC_LOCKED, true ) ) {
				continue;
			}
			$misses = (int) get_post_meta( $id, Meta::FB_MISSING, true ) + 1;
			if ( $misses >= 2 ) {
				$drafted++;
				if ( ! $dry ) {
					wp_update_post( [ 'ID' => $id, 'post_status' => 'draft' ] );
				}
			} elseif ( ! $dry ) {
				update_post_meta( $id, Meta::FB_MISSING, $misses );
			}
		}
		return $drafted;
	}

	public static function key( string $source, string $uid ): string {
		return $source . '|' . $uid;
	}

	public static function snapshot( string $name, string $desc, string $start, string $end ): string {
		return md5( wp_strip_all_tags( $name ) . '|' . wp_strip_all_tags( $desc ) . '|' . $start . '|' . $end );
	}
}
