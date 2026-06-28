<?php
/**
 * Plugin bootstrap: loads modules and wires activation/deactivation.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Require class files. Kept explicit (no autoloader) to match the club's
	 * existing plugin style and keep load order obvious.
	 */
	private function load(): void {
		$dir = GASF_EVENTS_DIR . 'includes/';
		require_once $dir . 'class-meta.php';
		require_once $dir . 'class-event.php';
		require_once $dir . 'class-post-type.php';
		require_once $dir . 'class-settings.php';
		require_once $dir . 'class-recurrence.php';
		require_once $dir . 'class-series.php';
		require_once $dir . 'class-migrator.php';
		if ( is_admin() ) {
			require_once $dir . 'class-meta-box.php';
			require_once $dir . 'class-admin-list.php';
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once $dir . 'class-cli.php';
			\WP_CLI::add_command( 'gasf-events', CLI::class );
		}
	}

	/**
	 * Wire runtime hooks. Idempotent.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		$this->load();

		( new Post_Type() )->register_hooks();
		( new Settings() )->register_hooks();

		if ( is_admin() ) {
			( new Meta_Box() )->register_hooks();
			( new Admin_List() )->register_hooks();
			( new Series() )->register_hooks();
		}

		load_plugin_textdomain( 'gasf-events', false, dirname( plugin_basename( GASF_EVENTS_FILE ) ) . '/languages' );
	}

	/**
	 * Activation: register the CPT then flush rewrites so /<slug>/ resolves.
	 */
	public static function activate(): void {
		require_once GASF_EVENTS_DIR . 'includes/class-meta.php';
		require_once GASF_EVENTS_DIR . 'includes/class-event.php';
		require_once GASF_EVENTS_DIR . 'includes/class-post-type.php';
		$pt = new Post_Type();
		$pt->register_post_type();
		Post_Type::grant_caps();
		flush_rewrite_rules();
	}

	/**
	 * Deactivation: flush rewrites so the CPT's rules are dropped cleanly.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
