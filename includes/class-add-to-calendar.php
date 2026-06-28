<?php
/**
 * "Add to Calendar" — one helper that builds Google / Outlook.com / Office 365 /
 * Yahoo deep links + a per-event .ics download (which also serves Apple
 * Calendar, since Apple has no web URL). Also serves the .ics via a lightweight
 * query endpoint. See docs/ARCHITECTURE.md §5.6. (Full subscribable feeds: P7.)
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Add_To_Calendar {

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_query_var' ] );
		add_action( 'template_redirect', [ $this, 'maybe_serve_ics' ] );
		add_action( 'init', [ $this, 'add_rewrite' ] );
	}

	public function add_query_var(): void {
		add_rewrite_tag( '%gasf_ics%', '([^&]+)' );
	}

	/** Pretty subscribe URL: /<slug>/calendar.ics → ?gasf_ics=all. */
	public function add_rewrite(): void {
		add_rewrite_rule( '^' . GASF_EVENTS_REWRITE_SLUG . '/calendar\.ics$', 'index.php?gasf_ics=all', 'top' );
	}

	/**
	 * Serve .ics:
	 *   ?gasf_ics=event&event=<id>  → one event (add-to-calendar / Apple)
	 *   ?gasf_ics=all               → the whole upcoming calendar (subscribable feed)
	 */
	public function maybe_serve_ics(): void {
		$mode = (string) ( get_query_var( 'gasf_ics' ) ?: ( $_GET['gasf_ics'] ?? '' ) );

		if ( 'event' === $mode ) {
			$event = Event::get( (int) ( $_GET['event'] ?? 0 ) );
			// Published only — don't leak a draft/private event's details via id guessing.
			if ( ! $event || 'publish' !== get_post_status( $event->id() ) ) {
				status_header( 404 );
				exit;
			}
			nocache_headers();
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="event-' . $event->id() . '.ics"' );
			echo self::ics_document( [ $event ] ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( 'all' === $mode ) {
			// Cache the built feed (unauthenticated 500-row build otherwise runs on
			// every poll). Invalidated by TTL; subscribers re-poll periodically.
			$body = get_transient( 'gasf_ics_all' );
			if ( false === $body ) {
				$body = self::ics_document( Calendar::upcoming( 500, time() - 7 * DAY_IN_SECONDS ) );
				set_transient( 'gasf_ics_all', $body, 15 * MINUTE_IN_SECONDS );
			}
			header( 'Content-Type: text/calendar; charset=utf-8' );
			header( 'Content-Disposition: inline; filename="gasf-calendar.ics"' );
			header( 'Cache-Control: public, max-age=900' );
			header( 'X-WR-CALNAME: ' . get_bloginfo( 'name' ) . ' Events' );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}
	}

	/** The subscribable feed URL (https) and its webcal:// form. */
	public static function subscribe_url( bool $webcal = false ): string {
		$url = home_url( '/' . GASF_EVENTS_REWRITE_SLUG . '/calendar.ics' );
		return $webcal ? preg_replace( '#^https?://#', 'webcal://', $url ) : $url;
	}

	/* ---- Provider links ----------------------------------------------- */

	/**
	 * @return array<int,array{key:string,label:string,url:string}>
	 */
	public static function links( Event $event ): array {
		$start = $event->start();
		$end   = $event->end() ?: $start;
		if ( ! $start ) {
			return [];
		}
		$title    = $event->title();
		$details  = self::plain( $event->description() ) . "\n\n" . $event->permalink();
		$location = self::location_string( $event );

		$g_dates  = self::utc( $start ) . '/' . self::utc( $end );
		$iso_s    = $start->format( 'Y-m-d\TH:i:s' );
		$iso_e    = $end->format( 'Y-m-d\TH:i:s' );

		return [
			[
				'key'   => 'google',
				'label' => __( 'Google Calendar', 'gasf-events' ),
				'url'   => add_query_arg( array_map( 'rawurlencode', [
					'action'  => 'TEMPLATE',
					'text'    => $title,
					'dates'   => $g_dates,
					'details' => $details,
					'location' => $location,
				] ), 'https://calendar.google.com/calendar/render' ),
			],
			[
				'key'   => 'outlook',
				'label' => __( 'Outlook.com', 'gasf-events' ),
				'url'   => self::outlook( 'https://outlook.live.com/calendar/0/deeplink/compose', $title, $iso_s, $iso_e, $details, $location ),
			],
			[
				'key'   => 'office365',
				'label' => __( 'Office 365', 'gasf-events' ),
				'url'   => self::outlook( 'https://outlook.office.com/calendar/0/deeplink/compose', $title, $iso_s, $iso_e, $details, $location ),
			],
			[
				'key'   => 'yahoo',
				'label' => __( 'Yahoo', 'gasf-events' ),
				'url'   => add_query_arg( array_map( 'rawurlencode', [
					'v'      => '60',
					'title'  => $title,
					'st'     => self::utc( $start ),
					'et'     => self::utc( $end ),
					'desc'   => $details,
					'in_loc' => $location,
				] ), 'https://calendar.yahoo.com/' ),
			],
			[
				'key'   => 'apple',
				'label' => __( 'Apple / Download .ics', 'gasf-events' ),
				'url'   => self::ics_url( $event ),
			],
		];
	}

	private static function outlook( string $base, string $title, string $iso_s, string $iso_e, string $details, string $location ): string {
		return add_query_arg( array_map( 'rawurlencode', [
			'path'    => '/calendar/action/compose',
			'rru'     => 'addevent',
			'subject' => $title,
			'startdt' => $iso_s,
			'enddt'   => $iso_e,
			'body'    => $details,
			'location' => $location,
		] ), $base );
	}

	public static function ics_url( Event $event ): string {
		return add_query_arg( [ 'gasf_ics' => 'event', 'event' => $event->id() ], home_url( '/' ) );
	}

	/* ---- .ics document ------------------------------------------------ */

	/**
	 * Build a VCALENDAR for one or more events (reused by the feeds in P7).
	 */
	public static function ics_document( array $events ): string {
		$lines = [
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//German American Society//GASF Events//EN',
			'CALSCALE:GREGORIAN',
		];
		foreach ( $events as $event ) {
			if ( ! $event instanceof Event ) {
				continue;
			}
			$start = $event->start();
			$end   = $event->end() ?: $start;
			if ( ! $start ) {
				continue;
			}
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:gasf-' . $event->id() . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
			$lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
			if ( $event->is_all_day() ) {
				$lines[] = 'DTSTART;VALUE=DATE:' . $start->format( 'Ymd' );
				$lines[] = 'DTEND;VALUE=DATE:' . $end->modify( '+1 day' )->format( 'Ymd' );
			} else {
				$lines[] = 'DTSTART:' . self::utc( $start );
				$lines[] = 'DTEND:' . self::utc( $end );
			}
			$lines[] = 'SUMMARY:' . self::esc( $event->title() );
			$lines[] = 'DESCRIPTION:' . self::esc( self::plain( $event->description() ) . ' ' . $event->permalink() );
			$lines[] = 'LOCATION:' . self::esc( self::location_string( $event ) );
			$lines[] = 'URL:' . $event->permalink();
			if ( 'cancelled' === $event->status() ) {
				$lines[] = 'STATUS:CANCELLED';
			}
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", array_map( [ self::class, 'fold' ], $lines ) ) . "\r\n";
	}

	/** RFC 5545 line folding: split content lines longer than 75 octets. */
	private static function fold( string $line ): string {
		if ( strlen( $line ) <= 75 ) {
			return $line;
		}
		$out = '';
		$pos = 0;
		$len = strlen( $line );
		while ( $pos < $len ) {
			$max = 0 === $pos ? 75 : 74;
			$max = min( $max, $len - $pos );
			// Don't split inside a multibyte UTF-8 sequence (ü/ö/ä/ß): back off the
			// cut while the next byte is a continuation byte (10xxxxxx).
			while ( $max > 1 && $pos + $max < $len && ( ord( $line[ $pos + $max ] ) & 0xC0 ) === 0x80 ) {
				$max--;
			}
			$out .= ( 0 === $pos ? '' : "\r\n " ) . substr( $line, $pos, $max );
			$pos += $max;
		}
		return $out;
	}

	/* ---- helpers ------------------------------------------------------ */

	private static function utc( \DateTimeImmutable $dt ): string {
		return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
	}

	public static function location_string( Event $event ): string {
		$v = $event->venue();
		$parts = array_filter( [ $v['name'] ?? '', $v['street'] ?? '', trim( ( $v['city'] ?? '' ) . ', ' . ( $v['state'] ?? '' ) . ' ' . ( $v['zip'] ?? '' ) ) ] );
		return implode( ', ', $parts );
	}

	private static function plain( string $html ): string {
		return trim( wp_strip_all_tags( $html ) );
	}

	/** Escape per RFC 5545 (commas, semicolons, newlines). */
	private static function esc( string $text ): string {
		$text = str_replace( [ "\\", "\n", "\r", ',', ';' ], [ '\\\\', '\\n', '', '\\,', '\\;' ], $text );
		return $text;
	}
}
