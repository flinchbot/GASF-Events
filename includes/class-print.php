<?php
/**
 * Printable month view — the first-class fix for MEC's print pain.
 *
 * Serves a self-contained page (no theme chrome) at
 *   /<slug>/print/YYYY-MM/        (pretty rule), and
 *   ?gasf_print=YYYY-MM           (robust fallback if rewrites aren't flushed)
 * sized to one US-Letter landscape sheet. See docs/ARCHITECTURE.md §5.5.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Print_View {

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'add_rewrite' ] );
		add_filter( 'query_vars', [ $this, 'query_vars' ] );
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function add_rewrite(): void {
		add_rewrite_rule(
			'^' . GASF_EVENTS_REWRITE_SLUG . '/print/([0-9]{4})-([0-9]{2})/?$',
			'index.php?gasf_print=$matches[1]-$matches[2]',
			'top'
		);
	}

	public function query_vars( array $vars ): array {
		$vars[] = 'gasf_print';
		return $vars;
	}

	public function maybe_render(): void {
		$month = get_query_var( 'gasf_print' );
		if ( ! $month && isset( $_GET['gasf_print'] ) ) {
			$month = sanitize_text_field( wp_unslash( $_GET['gasf_print'] ) );
		}
		if ( ! $month || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			return;
		}
		$data = ( new Shortcodes() )->month_data( $month );
		$auto = ! empty( $_GET['auto'] );
		nocache_headers();
		status_header( 200 );
		require GASF_EVENTS_DIR . 'templates/print-month.php';
		exit;
	}
}
