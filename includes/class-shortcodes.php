<?php
/**
 * Front-end shortcodes / views: month grid, upcoming list, signage tiles, and
 * the generic filtered upcoming list. Server-rendered HTML (prints + SEO),
 * themed via CSS variables, progressively enhanced with Alpine. See §5.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	public function register_hooks(): void {
		add_shortcode( 'gasf_events', [ $this, 'events' ] );
		add_shortcode( 'gasf_upcoming_dates', [ $this, 'upcoming_dates' ] );
		add_shortcode( 'gasf_dinner_events', [ $this, 'dinner' ] );
		add_action( 'init', [ $this, 'add_query_var' ] );
	}

	public function add_query_var(): void {
		add_rewrite_tag( '%gasf_m%', '([0-9]{4}-[0-9]{2})' );
	}

	/** [gasf_events view="month|list|tile" limit="" month="YYYY-MM"] */
	public function events( $atts ): string {
		$atts = shortcode_atts( [ 'view' => 'month', 'limit' => 0, 'month' => '' ], $atts, 'gasf_events' );
		switch ( $atts['view'] ) {
			case 'list':
				return $this->render( 'view-list', [ 'events' => Calendar::upcoming( (int) ( $atts['limit'] ?: 30 ) ) ] );
			case 'tile':
				return $this->render( 'view-tile', [ 'events' => Calendar::upcoming( (int) ( $atts['limit'] ?: 20 ) ) ] );
			case 'home': // compact upcoming agenda for the front page (matches old MEC 12976).
				return $this->render( 'view-home', [ 'events' => Calendar::upcoming( (int) ( $atts['limit'] ?: 5 ) ) ] );
			case 'month':
			default:
				return $this->render( 'view-month', $this->month_data( $atts['month'] ) );
		}
	}

	/** [gasf_upcoming_dates contains="Euchre" heading="..." icon="🃏" color="#EF9F27" limit="6"] */
	public function upcoming_dates( $atts ): string {
		$atts = shortcode_atts( [
			'contains' => '', 'heading' => '', 'icon' => '', 'color' => 'var(--gasf-accent)', 'limit' => 6,
		], $atts, 'gasf_upcoming_dates' );

		$events = Calendar::upcoming( 200 );
		if ( '' !== $atts['contains'] ) {
			$needle = mb_strtolower( $atts['contains'] );
			$events = array_values( array_filter( $events, static fn( $e ) => false !== mb_strpos( mb_strtolower( $e->title() ), $needle ) ) );
		}
		$events = array_slice( $events, 0, (int) $atts['limit'] );
		return $this->render( 'view-upcoming', [ 'events' => $events, 'atts' => $atts ] );
	}

	/** [gasf_dinner_events] — thin preset over the generic filter. */
	public function dinner( $atts ): string {
		$atts = shortcode_atts( [ 'heading' => __( 'Upcoming Dinner Nights', 'gasf-events' ), 'limit' => 6 ], $atts, 'gasf_dinner_events' );
		return $this->upcoming_dates( [ 'contains' => 'Dinner', 'heading' => $atts['heading'], 'icon' => '🍽️', 'limit' => $atts['limit'] ] );
	}

	/* ---- Month grid --------------------------------------------------- */

	/** Resolve year/month (atts → ?gasf_m → current) and build the grid. */
	public function month_data( string $month_att = '' ): array {
		$tz  = wp_timezone();
		$req = $month_att ?: ( isset( $_GET['gasf_m'] ) ? sanitize_text_field( wp_unslash( $_GET['gasf_m'] ) ) : '' );
		if ( preg_match( '/^(\d{4})-(\d{2})$/', $req, $m ) ) {
			$year = (int) $m[1];
			$mon  = max( 1, min( 12, (int) $m[2] ) );
		} else {
			$now  = new \DateTimeImmutable( 'now', $tz );
			$year = (int) $now->format( 'Y' );
			$mon  = (int) $now->format( 'n' );
		}

		$first = ( new \DateTimeImmutable( sprintf( '%04d-%02d-01 00:00:00', $year, $mon ), $tz ) );
		$last  = $first->modify( 'last day of this month' );
		$events = Calendar::in_month( $year, $mon );

		// Map events onto each day they cover (within the month).
		$by_day = [];
		foreach ( $events as $e ) {
			$s = $e->start();
			$en = $e->end() ?: $s;
			if ( ! $s ) {
				continue;
			}
			$cur  = $s < $first ? $first : $s;
			$stop = $en > $last ? $last : $en;
			$d    = $cur->setTime( 0, 0 );
			while ( $d <= $stop ) {
				$by_day[ $d->format( 'Y-m-d' ) ][] = $e;
				$d = $d->modify( '+1 day' );
			}
		}

		// Build weeks (Sunday-started grid).
		$start_dow = (int) $first->format( 'w' ); // 0=Sun
		$grid_start = $first->modify( '-' . $start_dow . ' day' );
		$weeks = [];
		$cursor = $grid_start;
		$today = ( new \DateTimeImmutable( 'now', $tz ) )->format( 'Y-m-d' );
		for ( $w = 0; $w < 6; $w++ ) {
			$row = [];
			for ( $i = 0; $i < 7; $i++ ) {
				$key = $cursor->format( 'Y-m-d' );
				$row[] = [
					'date'     => $cursor,
					'in_month' => ( (int) $cursor->format( 'n' ) === $mon ),
					'is_today' => ( $key === $today ),
					'events'   => $by_day[ $key ] ?? [],
				];
				$cursor = $cursor->modify( '+1 day' );
			}
			$weeks[] = $row;
			if ( $cursor > $last && (int) $cursor->format( 'n' ) !== $mon ) {
				break; // don't render a trailing all-next-month week
			}
		}

		$prev = $first->modify( '-1 month' );
		$next = $first->modify( '+1 month' );

		return [
			'year'  => $year,
			'month' => $mon,
			'label' => wp_date( 'F Y', $first->getTimestamp() ),
			'weeks' => $weeks,
			'prev'  => $prev->format( 'Y-m' ),
			'next'  => $next->format( 'Y-m' ),
		];
	}

	/* ---- Render helper ------------------------------------------------ */

	private function render( string $template, array $vars ): string {
		Assets::mark_needed();
		$file = GASF_EVENTS_DIR . 'templates/' . $template . '.php';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}
}
