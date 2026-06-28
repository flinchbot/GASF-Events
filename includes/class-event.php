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
		return $this->datetime( (string) $this->meta( Meta::START ) );
	}

	public function end(): ?\DateTimeImmutable {
		return $this->datetime( (string) $this->meta( Meta::END ) );
	}

	private function datetime( string $local ): ?\DateTimeImmutable {
		$local = trim( $local );
		if ( '' === $local ) {
			return null;
		}
		try {
			return new \DateTimeImmutable( $local, wp_timezone() );
		} catch ( \Exception $e ) {
			return null;
		}
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

	public function is_facebook(): bool {
		return 'facebook' === $this->source() || '' !== (string) $this->meta( Meta::FB_EVENT_ID );
	}

	public function is_sync_locked(): bool {
		return (bool) $this->meta( Meta::SYNC_LOCKED );
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
		$override = $this->meta( Meta::VENUE_OVERRIDE );
		return is_array( $override ) && ! empty( $override['name'] )
			? wp_parse_args( $override, Settings::venue() )
			: Settings::venue();
	}

	public function organizer(): array {
		$override = $this->meta( Meta::ORGANIZER_OVERRIDE );
		return is_array( $override ) && ! empty( $override['name'] )
			? wp_parse_args( $override, Settings::organizer() )
			: Settings::organizer();
	}

	/* ---- Details ------------------------------------------------------ */

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
