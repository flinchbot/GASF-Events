<?php
/**
 * Plugin settings: the single venue, the organizer, and the default cover
 * image — the three globals that replace MEC's location/organizer taxonomies
 * and guarantee a schema `image`. See docs/ARCHITECTURE.md §4.3.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Settings {

	const OPT_VENUE      = 'gas_events_venue';
	const OPT_ORGANIZER  = 'gas_events_organizer';
	const OPT_DEFAULT_IMG = 'gas_events_default_image';

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public static function venue(): array {
		return wp_parse_args( (array) get_option( self::OPT_VENUE, [] ), [
			'name'    => 'German American Society',
			'street'  => '8098 66th Street North',
			'city'    => 'Pinellas Park',
			'state'   => 'FL',
			'zip'     => '33781',
			'country' => 'US',
			'lat'     => '',
			'lng'     => '',
			'hide_map' => false,
		] );
	}

	public static function organizer(): array {
		return wp_parse_args( (array) get_option( self::OPT_ORGANIZER, [] ), [
			'name' => 'German American Society',
			'url'  => home_url( '/' ),
		] );
	}

	public static function default_image_id(): int {
		return (int) get_option( self::OPT_DEFAULT_IMG, 0 );
	}

	public static function default_image_url( string $size = 'large' ): string {
		$id = self::default_image_id();
		if ( $id ) {
			$url = wp_get_attachment_image_url( $id, $size );
			if ( $url ) {
				return $url;
			}
		}
		// Last-resort placeholder bundled with the plugin (added in P4 display phase).
		return GASF_EVENTS_URL . 'assets/img/event-default.jpg';
	}

	public function add_settings_page(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=' . GASF_EVENTS_CPT,
			__( 'Event Settings', 'gasf-events' ),
			__( 'Settings', 'gasf-events' ),
			'manage_options',
			'gasf-events-settings',
			[ $this, 'render_settings_page' ]
		);
		// Media picker for the default image, only on this page.
		add_action( 'admin_print_scripts-' . $hook, 'wp_enqueue_media' );
	}

	public function register_settings(): void {
		register_setting( 'gasf_events_settings', self::OPT_VENUE, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_venue' ],
		] );
		register_setting( 'gasf_events_settings', self::OPT_ORGANIZER, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize_organizer' ],
		] );
		register_setting( 'gasf_events_settings', self::OPT_DEFAULT_IMG, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
		] );
	}

	public function sanitize_venue( $input ): array {
		$input = (array) $input;
		return [
			'name'     => sanitize_text_field( $input['name'] ?? '' ),
			'street'   => sanitize_text_field( $input['street'] ?? '' ),
			'city'     => sanitize_text_field( $input['city'] ?? '' ),
			'state'    => sanitize_text_field( $input['state'] ?? '' ),
			'zip'      => sanitize_text_field( $input['zip'] ?? '' ),
			'country'  => sanitize_text_field( $input['country'] ?? 'US' ),
			'lat'      => sanitize_text_field( $input['lat'] ?? '' ),
			'lng'      => sanitize_text_field( $input['lng'] ?? '' ),
			'hide_map' => ! empty( $input['hide_map'] ),
		];
	}

	public function sanitize_organizer( $input ): array {
		$input = (array) $input;
		return [
			'name' => sanitize_text_field( $input['name'] ?? '' ),
			'url'  => esc_url_raw( $input['url'] ?? '' ),
		];
	}

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$venue     = self::venue();
		$organizer = self::organizer();
		$img_id    = self::default_image_id();
		$img_url   = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
		require GASF_EVENTS_DIR . 'admin/views/settings.php';
	}
}
