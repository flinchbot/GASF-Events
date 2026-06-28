<?php
/**
 * Single-event page: routes `is_singular('gasf_event')` to our branded
 * template (guarded so it never affects any other post type). See §5.2.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Single {

	public function register_hooks(): void {
		add_filter( 'template_include', [ $this, 'template' ], 100 );
	}

	public function template( string $template ): string {
		if ( is_singular( GASF_EVENTS_CPT ) ) {
			Assets::mark_needed();
			$ours = GASF_EVENTS_DIR . 'templates/single-gasf_event.php';
			if ( is_readable( $ours ) ) {
				return $ours;
			}
		}
		return $template;
	}
}
