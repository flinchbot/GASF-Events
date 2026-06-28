<?php
/**
 * Meta key registry + the canonical date/time derivation.
 *
 * The model stores human-local datetime strings (`_gasf_start`/`_gasf_end`, in
 * `Y-m-d H:i:s`, site timezone) as the source of truth, plus UTC unix
 * timestamps (`_gasf_start_ts`/`_gasf_end_ts`) derived from them for fast,
 * DST-correct range queries and sorting. See docs/ARCHITECTURE.md §4.2.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Meta {

	// Canonical date/time.
	const START      = '_gasf_start';        // string Y-m-d H:i:s (site-local)
	const END        = '_gasf_end';          // string Y-m-d H:i:s (site-local)
	const START_TS   = '_gasf_start_ts';     // int UTC unix
	const END_TS     = '_gasf_end_ts';       // int UTC unix
	const ALL_DAY    = '_gasf_all_day';      // bool
	const HIDE_TIME  = '_gasf_hide_time';    // bool
	const HIDE_END   = '_gasf_hide_end';     // bool

	// Status / details.
	const STATUS         = '_gasf_status';          // '' | cancelled | postponed | sold_out | online_only
	const STATUS_REASON  = '_gasf_status_reason';   // string
	const ONLINE_LINK    = '_gasf_online_link';     // url
	const MORE_INFO_URL   = '_gasf_more_info_url';  // url
	const MORE_INFO_TITLE = '_gasf_more_info_title';// string
	const COST           = '_gasf_cost';            // decimal (only when > 0)

	// Provenance & sync.
	const SOURCE       = '_gasf_source';        // manual | facebook | ics | eventbrite
	const SOURCE_UID   = '_gasf_source_uid';    // universal dedup key, namespaced "<source>|<uid>"
	const SOURCE_FEED  = '_gasf_source_feed';   // which feed config produced it
	const FB_EVENT_ID  = '_gasf_fb_event_id';   // FB mirror of the uid (kept for FB-specific UI)
	const FB_ACCOUNT   = '_gasf_fb_account';    // string
	const FB_COVER_ID  = '_gasf_fb_cover_id';   // string
	const FB_MISSING   = '_gasf_fb_missing';    // int — consecutive misses (any synced source)
	const SYNC_LOCKED  = '_gasf_sync_locked';   // bool (the "pin")
	const FB_SNAPSHOT  = '_gasf_fb_snapshot';   // hash of last synced fields (auto-pin detection)

	// Venue / organizer per-event overrides (defaults live in Settings).
	const VENUE_OVERRIDE     = '_gasf_venue_override';     // array
	const ORGANIZER_OVERRIDE = '_gasf_organizer_override'; // array

	// Series (recurring).
	const SERIES_ID   = '_gasf_series_id';   // string
	const SERIES_ROLE = '_gasf_series_role'; // single | master | occurrence
	const REPEAT       = '_gasf_repeat';       // '' | weekly | biweekly | monthly (on the master)
	const REPEAT_UNTIL = '_gasf_repeat_until'; // Y-m-d (ends-on) — mutually exclusive with count
	const REPEAT_COUNT = '_gasf_repeat_count'; // int (number of occurrences incl. first)

	/** Allowed repeat frequencies. */
	const REPEATS      = [ '', 'weekly', 'biweekly', 'monthly' ];
	/** Hard cap on generated occurrences per series (runaway guard). */
	const REPEAT_MAX   = 104;

	// Outbound publishing — generic, destination-agnostic (Eventbrite is the
	// first; more can register). Per-event state keyed by destination:
	// _gasf_published = [ '<dest>' => { id, url, status, synced_at, error } ].
	const PUBLISHED       = '_gasf_published';
	const PUBLISH_INTENT  = '_gasf_publish_intent'; // [ dest keys ] pending publish

	// Stats.
	const VIEWS       = '_gasf_views';        // int (total)
	const VIEWS_KIOSK = '_gasf_views_kiosk';  // int (kiosk taps subset)
	const VIEWS_DAILY = '_gasf_views_daily';  // [ Ymd => count ] (rolling)

	/** Allowed event statuses. */
	const STATUSES = [ '', 'cancelled', 'postponed', 'sold_out', 'online_only' ];

	const ON_ATTACHMENT_COVER_SHA1 = '_gasf_cover_sha1';

	/**
	 * Convert a site-local 'Y-m-d H:i:s' string to a UTC unix timestamp,
	 * DST-correctly, using the site timezone.
	 */
	public static function local_to_ts( string $local ): int {
		$local = trim( $local );
		if ( '' === $local ) {
			return 0;
		}
		try {
			$dt = new \DateTimeImmutable( $local, wp_timezone() );
			return $dt->getTimestamp();
		} catch ( \Exception $e ) {
			return 0;
		}
	}

	/**
	 * Recompute and persist `_gasf_start_ts`/`_gasf_end_ts` from the stored
	 * local datetime strings. Call after any write to start/end.
	 */
	public static function recompute_timestamps( int $post_id ): void {
		$start = (string) get_post_meta( $post_id, self::START, true );
		$end   = (string) get_post_meta( $post_id, self::END, true );
		update_post_meta( $post_id, self::START_TS, self::local_to_ts( $start ) );
		update_post_meta( $post_id, self::END_TS, self::local_to_ts( $end ) );
	}

	/**
	 * Register meta with the CPT (sanitization + single). Kept protected
	 * (underscore-prefixed); the public REST feed is a dedicated endpoint (P7),
	 * not raw meta exposure.
	 */
	public static function register(): void {
		$string_keys = [
			self::START, self::END, self::STATUS, self::STATUS_REASON, self::ONLINE_LINK,
			self::MORE_INFO_URL, self::MORE_INFO_TITLE, self::SOURCE, self::FB_EVENT_ID,
			self::FB_ACCOUNT, self::FB_COVER_ID, self::SERIES_ID, self::SERIES_ROLE,
			self::REPEAT, self::REPEAT_UNTIL,
		];
		foreach ( $string_keys as $key ) {
			register_post_meta( GASF_EVENTS_CPT, $key, [
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => static fn() => current_user_can( 'edit_gasf_events' ),
			] );
		}

		$int_keys = [ self::START_TS, self::END_TS, self::FB_MISSING, self::VIEWS, self::VIEWS_KIOSK, self::REPEAT_COUNT ];
		foreach ( $int_keys as $key ) {
			register_post_meta( GASF_EVENTS_CPT, $key, [
				'type'              => 'integer',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static fn() => current_user_can( 'edit_gasf_events' ),
			] );
		}

		$bool_keys = [ self::ALL_DAY, self::HIDE_TIME, self::HIDE_END, self::SYNC_LOCKED ];
		foreach ( $bool_keys as $key ) {
			register_post_meta( GASF_EVENTS_CPT, $key, [
				'type'              => 'boolean',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn( $v ) => (bool) $v,
				'auth_callback'     => static fn() => current_user_can( 'edit_gasf_events' ),
			] );
		}
	}
}
