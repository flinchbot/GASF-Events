<?php
/**
 * Welton Brewing "open" blurb.
 *
 * The club shares its property with Welton Brewing Co. & Oyster Bar, so an event
 * page (or any page) can show a contextual note when the brewery is open. Native
 * and self-contained — no dependency on any legacy mu-plugin, snippet, or external
 * gate. The hours below are the single source of truth for GASF Events surfaces.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Welton {

	/**
	 * Welton hours as [open, close] minutes-of-day, keyed by ISO weekday
	 * (1=Mon … 7=Sun); false = closed. Edit here to keep the blurb accurate.
	 */
	private const HOURS = [
		1 => false,            // Mon closed
		2 => false,            // Tue closed
		3 => [ 16 * 60, 21 * 60 ], // Wed 4–9pm
		4 => [ 16 * 60, 21 * 60 ], // Thu 4–9pm
		5 => [ 11 * 60, 22 * 60 ], // Fri 11am–10pm
		6 => [ 11 * 60, 22 * 60 ], // Sat 11am–10pm
		7 => [ 12 * 60, 20 * 60 ], // Sun noon–8pm
	];

	private const LINK = '<a href="https://www.weltonbrewingcompany.com/" target="_blank" rel="noopener">Welton Brewing Co. &amp; Oyster Bar</a>';

	public function register_hooks(): void {
		add_shortcode( 'gasf_welton_status', [ $this, 'shortcode' ] );
	}

	/**
	 * [gasf_welton_status] — auto-detects the current gasf_event, or accepts
	 * event_start/event_end="Y-m-d H:i:s"; with no event it falls back to the
	 * generic "open now / opens at" message.
	 */
	public function shortcode( $atts ): string {
		$atts = shortcode_atts( [ 'event_start' => '', 'event_end' => '' ], $atts, 'gasf_welton_status' );
		$tz   = wp_timezone();

		if ( '' !== $atts['event_start'] ) {
			try {
				$start = new \DateTimeImmutable( $atts['event_start'], $tz );
				$end   = '' !== $atts['event_end'] ? new \DateTimeImmutable( $atts['event_end'], $tz ) : null;
				return self::render_event( $start, $end, $tz );
			} catch ( \Exception $e ) {
				return '';
			}
		}

		if ( is_singular( GASF_EVENTS_CPT ) ) {
			$event = Event::get( get_queried_object_id() );
			if ( $event ) {
				return self::blurb( $event );
			}
		}

		return self::render_generic( $tz );
	}

	/** Event-aware blurb (used directly by the single-event template). */
	public static function blurb( Event $event ): string {
		$start = $event->start();
		return $start ? self::render_event( $start, $event->end(), wp_timezone() ) : '';
	}

	/** Show a note if the event overlaps Welton's hours that day. */
	private static function render_event( \DateTimeImmutable $start, ?\DateTimeImmutable $end, \DateTimeZone $tz ): string {
		$now = new \DateTimeImmutable( 'now', $tz );
		$end = $end ?: $start->modify( '+2 hours' );
		if ( $end < $now ) {
			return ''; // event already over
		}

		$today = self::HOURS[ (int) $start->format( 'N' ) ] ?? false;
		if ( ! $today ) {
			return ''; // Welton closed that day
		}

		$w_open  = $start->setTime( intdiv( $today[0], 60 ), $today[0] % 60 );
		$w_close = $start->setTime( intdiv( $today[1], 60 ), $today[1] % 60 );
		if ( $end <= $w_open || $start >= $w_close ) {
			return ''; // no overlap with Welton's hours
		}

		$is_today = ( $start->format( 'Y-m-d' ) === $now->format( 'Y-m-d' ) );
		if ( $is_today && self::open_now( $now ) ) {
			$msg = self::LINK . ' is open! Swing by for a delicious meal or a craft brew!';
		} else {
			$msg = 'Good news! ' . self::LINK . ' &mdash; the on-site restaurant and brewery &mdash; will be open to enjoy a meal or a craft beer before or after the event.';
		}
		return '<div class="gasf-welton">' . $msg . '</div>';
	}

	/** Generic "open now / opens at" message when there's no event context. */
	private static function render_generic( \DateTimeZone $tz ): string {
		$now   = new \DateTimeImmutable( 'now', $tz );
		$today = self::HOURS[ (int) $now->format( 'N' ) ] ?? false;
		if ( ! $today ) {
			return '';
		}
		$now_min = (int) $now->format( 'H' ) * 60 + (int) $now->format( 'i' );
		if ( $now_min >= $today[1] ) {
			return ''; // already closed for the day
		}
		if ( $now_min >= $today[0] ) {
			$msg = self::LINK . ' is open on-site right now &mdash; fresh Maine oysters, lobster rolls &amp; craft beer. Stop in!';
		} else {
			$open     = $now->setTime( intdiv( $today[0], 60 ), $today[0] % 60 );
			$open_str = str_replace( ':00', '', $open->format( 'g:i a' ) );
			$msg      = self::LINK . ' opens at ' . $open_str . ' today, right here on the property. Plan a visit!';
		}
		return '<div class="gasf-welton">' . $msg . '</div>';
	}

	private static function open_now( \DateTimeImmutable $now ): bool {
		$today = self::HOURS[ (int) $now->format( 'N' ) ] ?? false;
		if ( ! $today ) {
			return false;
		}
		$min = (int) $now->format( 'H' ) * 60 + (int) $now->format( 'i' );
		return ( $min >= $today[0] && $min < $today[1] );
	}
}
