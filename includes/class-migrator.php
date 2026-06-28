<?php
/**
 * MEC → gasf_event migration.
 *
 * READ-ONLY against MEC: it never modifies `mec-events` posts or `mec_*` meta
 * (so the live calendar keeps running and rollback stays trivial). It only
 * derives `_gasf_*` values and either:
 *   - copy mode:    creates parallel `gasf_event` posts (validation/dev), or
 *   - convert mode: changes a post's type in place (reserved for cutover, P8).
 *
 * Crosswalk: docs/ARCHITECTURE.md Appendix A. Recurrence is intentionally NOT
 * expanded — each MEC post becomes one flat event (the club's data is already
 * flat: 876 no-repeat / 1 repeat).
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Migrator {

	const MEC_CPT       = 'mec-events';
	const MIGRATED_FROM = '_gasf_migrated_from'; // source MEC post id (copy mode dedup key)

	/**
	 * Derive the `_gasf_*` meta map + anomaly flags from a MEC post. Pure read.
	 *
	 * @return array{meta:array<string,mixed>,flags:string[],raw_status:string}
	 */
	public static function derive( int $mec_id ): array {
		$g = static fn( $k ) => get_post_meta( $mec_id, $k, true );

		$start_date = trim( (string) $g( 'mec_start_date' ) );
		$end_date   = trim( (string) $g( 'mec_end_date' ) ) ?: $start_date;
		$all_day    = (bool) $g( 'mec_allday' );

		[ $start, $end ] = self::build_datetimes(
			$start_date,
			$end_date,
			$all_day,
			(int) $g( 'mec_start_time_hour' ), (int) $g( 'mec_start_time_minutes' ), (string) $g( 'mec_start_time_ampm' ),
			(int) $g( 'mec_end_time_hour' ), (int) $g( 'mec_end_time_minutes' ), (string) $g( 'mec_end_time_ampm' )
		);

		$raw_status = (string) $g( 'mec_event_status' );
		$fb_id      = (string) $g( 'mec_advimp_facebook_event_id' );

		$meta = [
			Meta::START          => $start,
			Meta::END            => $end,
			Meta::START_TS       => Meta::local_to_ts( $start ),
			Meta::END_TS         => Meta::local_to_ts( $end ),
			Meta::ALL_DAY        => $all_day ? 1 : 0,
			Meta::HIDE_TIME      => $g( 'mec_hide_time' ) ? 1 : 0,
			Meta::HIDE_END       => $g( 'mec_hide_end_time' ) ? 1 : 0,
			Meta::STATUS         => self::map_status( $raw_status ),
			Meta::MORE_INFO_URL  => esc_url_raw( (string) $g( 'mec_more_info' ) ),
			Meta::MORE_INFO_TITLE => sanitize_text_field( (string) $g( 'mec_more_info_title' ) ),
			Meta::SERIES_ROLE    => 'single',
			Meta::SOURCE         => $fb_id ? 'facebook' : 'manual',
		];

		$cost = (float) $g( 'mec_cost' );
		if ( $cost > 0 ) {
			$meta[ Meta::COST ] = $cost;
		}
		if ( $fb_id ) {
			$meta[ Meta::FB_EVENT_ID ] = $fb_id;
		}

		$flags = [];
		if ( '' === $start_date ) {
			$flags[] = 'no_start_date';
		}
		if ( '1' === (string) $g( 'mec_repeat_status' ) ) {
			$flags[] = 'mec_recurring';
		}
		if ( $g( 'mec_hourly_schedules' ) ) {
			$flags[] = 'has_hourly_schedule';
		}
		if ( $raw_status && '' === $meta[ Meta::STATUS ] ) {
			$flags[] = 'unmapped_status:' . $raw_status;
		}

		return [ 'meta' => $meta, 'flags' => $flags, 'raw_status' => $raw_status ];
	}

	/**
	 * Build local 'Y-m-d H:i:s' start/end strings from MEC's split fields.
	 *
	 * @return array{0:string,1:string}
	 */
	private static function build_datetimes(
		string $start_date, string $end_date, bool $all_day,
		int $sh, int $smin, string $sampm,
		int $eh, int $emin, string $eampm
	): array {
		if ( '' === $start_date ) {
			return [ '', '' ];
		}
		if ( $all_day ) {
			return [ $start_date . ' 00:00:00', ( $end_date ?: $start_date ) . ' 23:59:59' ];
		}
		$start = $start_date . ' ' . self::to_24h( $sh, $smin, $sampm );
		$end   = ( $end_date ?: $start_date ) . ' ' . self::to_24h( $eh, $emin, $eampm );
		return [ $start, $end ];
	}

	private static function to_24h( int $hour, int $min, string $ampm ): string {
		$ampm = strtoupper( trim( $ampm ) );
		$h    = $hour % 12;            // 12 -> 0
		if ( 'PM' === $ampm ) {
			$h += 12;                 // 12 PM -> 12, 1 PM -> 13
		}
		return sprintf( '%02d:%02d:00', max( 0, min( 23, $h ) ), max( 0, min( 59, $min ) ) );
	}

	/** Map MEC's status to our enum, defensively (raw values vary by MEC build). */
	private static function map_status( string $raw ): string {
		$r = strtolower( trim( $raw ) );
		if ( '' === $r || in_array( $r, [ 'eventscheduled', 'scheduled', '0' ], true ) ) {
			return '';
		}
		if ( false !== strpos( $r, 'cancel' ) ) {
			return 'cancelled';
		}
		if ( false !== strpos( $r, 'postpon' ) ) {
			return 'postponed';
		}
		if ( false !== strpos( $r, 'sold' ) ) {
			return 'sold_out';
		}
		if ( false !== strpos( $r, 'online' ) || false !== strpos( $r, 'moved' ) ) {
			return 'online_only';
		}
		return '';
	}

	/* ---- Writers ------------------------------------------------------ */

	/**
	 * Copy a MEC post into a parallel gasf_event (idempotent by MIGRATED_FROM).
	 * Returns the gasf_event id, or 0 on failure.
	 */
	public static function copy_one( int $mec_id ): int {
		$src = get_post( $mec_id );
		if ( ! $src || self::MEC_CPT !== $src->post_type ) {
			return 0;
		}
		$derived = self::derive( $mec_id );
		$existing = self::find_copy( $mec_id );

		$postarr = [
			'post_type'    => GASF_EVENTS_CPT,
			'post_status'  => $src->post_status,
			'post_title'   => $src->post_title,
			'post_content' => $src->post_content,
			'post_excerpt' => $src->post_excerpt,
			'post_name'    => $src->post_name,   // same slug (unique per post_type)
			'post_date'    => $src->post_date,
			'post_author'  => $src->post_author,
		];
		if ( $existing ) {
			$postarr['ID'] = $existing;
		}
		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		self::apply_meta( (int) $id, $derived['meta'] );
		update_post_meta( (int) $id, self::MIGRATED_FROM, $mec_id );
		$thumb = get_post_thumbnail_id( $mec_id );
		if ( $thumb ) {
			set_post_thumbnail( (int) $id, $thumb );
		}
		return (int) $id;
	}

	/**
	 * Convert a MEC post IN PLACE to gasf_event (reserved for cutover, P8).
	 * Preserves ID/slug/dates/thumbnail; leaves mec_* meta intact for rollback.
	 */
	public static function convert_one( int $mec_id ): bool {
		$src = get_post( $mec_id );
		if ( ! $src || self::MEC_CPT !== $src->post_type ) {
			return false;
		}
		$derived = self::derive( $mec_id );
		set_post_type( $mec_id, GASF_EVENTS_CPT );
		self::apply_meta( $mec_id, $derived['meta'] );
		return true;
	}

	private static function apply_meta( int $post_id, array $meta ): void {
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
	}

	public static function find_copy( int $mec_id ): int {
		return Meta::find_id_by( self::MIGRATED_FROM, $mec_id );
	}

	/* ---- Inventory ---------------------------------------------------- */

	/** All MEC post ids (optionally by status). */
	public static function mec_ids( array $statuses = [ 'publish', 'draft' ], int $limit = -1 ): array {
		return get_posts( [
			'post_type'        => self::MEC_CPT,
			'post_status'      => $statuses,
			'numberposts'      => $limit,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
		] );
	}

	/** All copy-mode gasf_event ids (those that carry MIGRATED_FROM). */
	public static function copy_ids(): array {
		return get_posts( [
			'post_type'        => GASF_EVENTS_CPT,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MIGRATED_FROM,
			'suppress_filters' => true,
		] );
	}
}
