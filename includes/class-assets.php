<?php
/**
 * Front-end asset loading: our CSS, our JS (Alpine components + helpers),
 * vendored Alpine.js, and the QR generator. Loaded only on pages that need
 * them. Execution order is enforced via dependencies so our `alpine:init`
 * listener is registered before Alpine boots.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private static $needed = false;

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'register' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ], 20 );
		add_filter( 'script_loader_tag', [ $this, 'defer' ], 10, 2 );
	}

	public function register(): void {
		$v = GASF_EVENTS_VERSION;
		wp_register_style( 'gasf-events', GASF_EVENTS_URL . 'assets/css/gasf-events.css', [], $v );
		wp_register_script( 'gasf-events-qr', GASF_EVENTS_URL . 'assets/js/vendor/qrcode.min.js', [], '1.4.4', true );
		wp_register_script( 'gasf-events', GASF_EVENTS_URL . 'assets/js/gasf-events.js', [ 'gasf-events-qr' ], $v, true );
		// Alpine depends on our script so our alpine:init listener is registered first.
		wp_register_script( 'gasf-events-alpine', GASF_EVENTS_URL . 'assets/js/vendor/alpine.min.js', [ 'gasf-events' ], '3.14.8', true );
	}

	/** Auto-enqueue on event pages / pages containing our shortcodes. */
	public function maybe_enqueue(): void {
		if ( $this->should_load() ) {
			self::mark_needed();
		}
	}

	private function should_load(): bool {
		if ( is_singular( GASF_EVENTS_CPT ) || is_post_type_archive( GASF_EVENTS_CPT ) ) {
			return true;
		}
		$post = get_post();
		if ( $post && is_singular() ) {
			foreach ( [ 'gasf_events', 'gasf_upcoming_dates', 'gasf_dinner_events', 'gasf_bayern_events' ] as $sc ) {
				if ( has_shortcode( (string) $post->post_content, $sc ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Enqueue on demand (also works when called from a shortcode render). */
	public static function mark_needed(): void {
		if ( self::$needed ) {
			return;
		}
		self::$needed = true;
		wp_enqueue_style( 'gasf-events' );
		wp_enqueue_script( 'gasf-events-qr' );
		wp_enqueue_script( 'gasf-events' );
		wp_enqueue_script( 'gasf-events-alpine' );
	}

	/** Add defer to our scripts (keeps document parse non-blocking). */
	public function defer( string $tag, string $handle ): string {
		if ( in_array( $handle, [ 'gasf-events-qr', 'gasf-events', 'gasf-events-alpine' ], true ) && false === strpos( $tag, ' defer' ) ) {
			$tag = str_replace( ' src=', ' defer src=', $tag );
		}
		return $tag;
	}
}
