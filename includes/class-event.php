<?php
/**
 * Event model — a typed wrapper around a `gasf_event` post.
 *
 * Everything downstream (display, schema, feeds, admin) reads events through
 * this object rather than poking raw meta, so date/time and fallback logic
 * live in exactly one place.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Event {

	/** @var \WP_Post */
	private $post;

	public function __construct( $post ) {
		$this->post = get_post( $post );
	}

	public static function get( $post ): ?Event {
		$p = get_post( $post );
		if ( ! $p || GASF_EVENTS_CPT !== $p->post_type ) {
			return null;
		}
		return new self( $p );
	}

	public function id(): int {
		return (int) $this->post->ID;
	}

	public function post(): \WP_Post {
		return $this->post;
	}

	public function title(): string {
		return html_entity_decode( get_the_title( $this->post ), ENT_QUOTES, 'UTF-8' );
	}

	public function description(): string {
		return (string) $this->post->post_content;
	}

	public function permalink(): string {
		return (string) get_permalink( $this->post );
	}

	private function meta( string $key ) {
		return get_post_meta( $this->post->ID, $key, true );
	}

	/* ---- Date / time -------------------------------------------------- */

	public function start(): ?\DateTimeImmutable {
		return Meta::to_datetime( (string) $this->meta( Meta::START ) );
	}

	public function end(): ?\DateTimeImmutable {
		return Meta::to_datetime( (string) $this->meta( Meta::END ) );
	}

	public function start_ts(): int {
		return (int) $this->meta( Meta::START_TS );
	}

	public function end_ts(): int {
		return (int) $this->meta( Meta::END_TS );
	}

	public function is_all_day(): bool {
		return (bool) $this->meta( Meta::ALL_DAY );
	}

	public function hide_time(): bool {
		return (bool) $this->meta( Meta::HIDE_TIME );
	}

	public function hide_end(): bool {
		return (bool) $this->meta( Meta::HIDE_END );
	}

	public function is_multiday(): bool {
		$s = $this->start();
		$e = $this->end();
		return $s && $e && $s->format( 'Y-m-d' ) !== $e->format( 'Y-m-d' );
	}

	public function is_past(): bool {
		$end = $this->end_ts() ?: $this->start_ts();
		return $end && $end < time();
	}

	/** ISO-8601 with timezone offset (for schema + iCal). */
	public function start_iso(): string {
		$s = $this->start();
		return $s ? $s->format( 'c' ) : '';
	}

	public function end_iso(): string {
		$e = $this->end();
		return $e ? $e->format( 'c' ) : '';
	}

	/* ---- Status ------------------------------------------------------- */

	public function status(): string {
		$s = (string) $this->meta( Meta::STATUS );
		return in_array( $s, Meta::STATUSES, true ) ? $s : '';
	}

	public function status_label(): string {
		switch ( $this->status() ) {
			case 'cancelled':   return __( 'Cancelled', 'gasf-events' );
			case 'postponed':   return __( 'Postponed', 'gasf-events' );
			case 'sold_out':    return __( 'Sold out', 'gasf-events' );
			case 'online_only': return __( 'Online only', 'gasf-events' );
			default:            return __( 'Scheduled', 'gasf-events' );
		}
	}

	/* ---- Provenance / series ------------------------------------------ */

	public function source(): string {
		$s = (string) $this->meta( Meta::SOURCE );
		return $s ?: 'manual';
	}

	/** True if this event came from a feed (facebook/ics/…), not a manual entry. */
	public function is_synced(): bool {
		$s = $this->source();
		return ( 'manual' !== $s ) || '' !== (string) $this->meta( Meta::SOURCE_UID ) || '' !== (string) $this->meta( Meta::FB_EVENT_ID );
	}

	public function series_id(): string {
		return (string) $this->meta( Meta::SERIES_ID );
	}

	public function is_recurring(): bool {
		return '' !== $this->series_id() && 'single' !== (string) $this->meta( Meta::SERIES_ROLE );
	}

	/* ---- Media -------------------------------------------------------- */

	/**
	 * Cover image URL, falling back to the site-wide default so an event is
	 * never image-less (Google schema requires `image`). See §4.3.
	 */
	public function cover_url( string $size = 'large' ): string {
		if ( has_post_thumbnail( $this->post ) ) {
			$url = get_the_post_thumbnail_url( $this->post, $size );
			if ( $url ) {
				return $url;
			}
		}
		return Settings::default_image_url( $size );
	}

	/**
	 * A RASTER image URL suitable for schema.org `image`, or '' if only the
	 * bundled SVG placeholder is available (Google rich results reject SVG —
	 * better to omit `image` than emit an invalid one). Admins should set a
	 * real default image in Settings.
	 */
	public function schema_image(): string {
		if ( has_post_thumbnail( $this->post ) ) {
			$url = get_the_post_thumbnail_url( $this->post, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		$id = Settings::default_image_id();
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		return '';
	}

	/* ---- Venue / organizer (settings + per-event override) ------------ */

	public function venue(): array {
		return $this->with_override( Meta::VENUE_OVERRIDE, Settings::venue() );
	}

	public function organizer(): array {
		return $this->with_override( Meta::ORGANIZER_OVERRIDE, Settings::organizer() );
	}

	/** A per-event override array merged over the defaults (only if it has a name). */
	private function with_override( string $meta_key, array $defaults ): array {
		$override = $this->meta( $meta_key );
		return is_array( $override ) && ! empty( $override['name'] )
			? wp_parse_args( $override, $defaults )
			: $defaults;
	}

	/* ---- Details ------------------------------------------------------ */

	public function status_reason(): string {
		return (string) $this->meta( Meta::STATUS_REASON );
	}

	public function more_info_url(): string {
		return (string) $this->meta( Meta::MORE_INFO_URL );
	}

	public function more_info_title(): string {
		$t = (string) $this->meta( Meta::MORE_INFO_TITLE );
		return $t ?: __( 'More info', 'gasf-events' );
	}

	public function cost(): float {
		return (float) $this->meta( Meta::COST );
	}

	public function online_link(): string {
		return (string) $this->meta( Meta::ONLINE_LINK );
	}

	/* ---- Syndication / stats ------------------------------------------ */

	/** Per-destination publish state: [ '<dest>' => {id,url,status,synced_at,error} ]. */
	public function published(): array {
		$p = $this->meta( Meta::PUBLISHED );
		return is_array( $p ) ? $p : [];
	}

	public function published_to( string $dest ): array {
		$p = $this->published();
		return is_array( $p[ $dest ] ?? null ) ? $p[ $dest ] : [];
	}

	public function eventbrite_url(): string {
		return (string) ( $this->published_to( 'eventbrite' )['url'] ?? '' );
	}

	public function views(): int {
		return (int) $this->meta( Meta::VIEWS );
	}

	public function views_kiosk(): int {
		return (int) $this->meta( Meta::VIEWS_KIOSK );
	}
}
