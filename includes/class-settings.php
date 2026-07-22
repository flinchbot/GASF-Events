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

	const OPT_VENUE      = 'gasf_events_venue';
	const OPT_ORGANIZER  = 'gasf_events_organizer';
	const OPT_DEFAULT_IMG = 'gasf_events_default_image';
	const OPT_HEROES     = 'gasf_events_heroes_enabled';

	/**
	 * Whether the home-page Heroes feature (heroes.php + recurring-heroes.php) runs.
	 * First read migrates from GASF-Utilities' legacy gate `gasf_mec_enable_hero`,
	 * so moving the feature between plugins changes nothing on a live site. Once
	 * saved from the Settings form, the Events option is authoritative.
	 */
	public static function heroes_enabled(): bool {
		$v = get_option( self::OPT_HEROES, null );
		if ( null === $v ) {
			$v = get_option( 'gasf_mec_enable_hero', '0' ); // inherit legacy Utilities gate
		}
		return (bool) (int) $v;
	}

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		// Turning Heroes off should stop its recurring cache-purge cron
		// (recurring-heroes.php only ever schedules it while enabled).
		add_action( 'update_option_' . self::OPT_HEROES, static function ( $old, $new ) {
			if ( ! (int) $new && wp_next_scheduled( 'gasf_hero_recurring_cron' ) ) {
				wp_clear_scheduled_hook( 'gasf_hero_recurring_cron' );
			}
		}, 10, 2 );
	}

	public static function venue(): array {
		return wp_parse_args( (array) get_option( self::OPT_VENUE, [] ), [
			'name'    => 'German-American Society',
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
			'name' => 'German-American Society',
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
		// Last-resort placeholder bundled with the plugin (admins should set a real
		// default image in Settings; this guarantees an image always exists).
		return GASF_EVENTS_URL . 'assets/img/event-default.svg';
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
		// Media picker for the default image, only on this page. admin_enqueue_scripts
		// is the canonical hook — it fires early enough that wp.media loads reliably
		// (the old admin_print_scripts-{hook} could land media in the footer, after
		// the settings view's inline script had already run).
		add_action( 'admin_enqueue_scripts', static function ( $screen ) use ( $hook ) {
			if ( $screen === $hook ) {
				wp_enqueue_media();
			}
		} );
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
		register_setting( 'gasf_events_settings', Alerts::OPT_EMAIL, [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_email',
		] );
		register_setting( 'gasf_events_settings', self::OPT_HEROES, [
			'type'              => 'boolean',
			// Unchecked box submits nothing → store '0'; checked → '1'.
			'sanitize_callback' => static fn( $v ) => ! empty( $v ) ? '1' : '0',
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
		$venue       = self::venue();
		$organizer   = self::organizer();
		$img_id      = self::default_image_id();
		$img_url     = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
		$alert_email = Alerts::email();
		require GASF_EVENTS_DIR . 'admin/views/settings.php';
	}
}
