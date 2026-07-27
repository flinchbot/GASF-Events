<?php
/**
 * GitHub-based plugin updates (WP 5.8+ `Update URI` mechanism).
 *
 * The repo is public, so no credentials are needed: we read the Version header
 * from the raw main branch and offer GitHub's branch zipball as the package.
 * WordPress's normal update + auto-update UI then works on any install that
 * runs this as a regular plugin. Version discipline: bump the Version header
 * in gasf-events.php or installs will never see the new code.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Updater {

	// Moved from the flinchbot personal account to the GermanTampaBay
	// organisation 2026-07-27. GitHub redirects the old URLs for now, but an
	// updater that fails silently (the version check stores '0' on any fetch
	// failure) should not lean on a redirect chain forever.
	const REPO      = 'GermanTampaBay/GASF-Events';
	const RAW_MAIN  = 'https://raw.githubusercontent.com/GermanTampaBay/GASF-Events/main/gasf-events.php';
	const PACKAGE   = 'https://github.com/GermanTampaBay/GASF-Events/archive/refs/heads/main.zip';
	const CACHE_KEY = 'gasf_events_update_check';

	private string $file; // absolute path to gasf-events.php

	public function __construct( string $file ) {
		$this->file = $file;
	}

	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder' ], 10, 4 );
		add_filter( 'upgrader_pre_install', [ $this, 'lock_and_backup' ], 10, 2 );
		add_filter( 'upgrader_install_package_result', [ $this, 'verify_or_restore' ], 10, 2 );
	}

	/* ------------------------------------------------------------------ */
	/* 2026-07-24 incident hardening.                                      */
	/*                                                                     */
	/* Two concurrent updates of this plugin (WP takes NO lock for manual/ */
	/* CLI updates) interleaved clear_destination/copy_dir and left the    */
	/* plugin folder EMPTY while both runs reported success. fix_folder's  */
	/* fail-closed guard can't catch that: it protects the staging step,   */
	/* not the destination step. Two new layers:                           */
	/*   1. lock_and_backup  — serialize via a WP_Upgrader lock (a second  */
	/*      concurrent run aborts BEFORE the destination is cleared) and   */
	/*      snapshot the installed plugin.                                 */
	/*   2. verify_or_restore — after install, if the result is an error   */
	/*      or gasf-events.php is missing from the plugin dir, restore the */
	/*      snapshot. Any failure mode heals back to the prior version.    */
	/* ------------------------------------------------------------------ */

	private function is_ours( $hook_extra ): bool {
		return ! empty( $hook_extra['plugin'] ) && plugin_basename( $this->file ) === $hook_extra['plugin'];
	}

	private function backup_dir(): string {
		return WP_CONTENT_DIR . '/upgrade/gasf-events-preupdate';
	}

	/** Plain-PHP recursive copy — deliberately independent of WP_Filesystem. */
	private static function copy_tree( string $from, string $to ): bool {
		if ( ! is_dir( $from ) ) {
			return false;
		}
		if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
			return false;
		}
		$ok = true;
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $from, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::SELF_FIRST
		) as $item ) {
			$dest = $to . '/' . substr( (string) $item, strlen( $from ) + 1 );
			if ( $item->isDir() ) {
				$ok = ( is_dir( $dest ) || mkdir( $dest, 0755, true ) ) && $ok;
			} else {
				$ok = copy( (string) $item, $dest ) && $ok;
			}
		}
		return $ok;
	}

	private static function delete_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		) as $item ) {
			$item->isDir() ? rmdir( (string) $item ) : unlink( (string) $item );
		}
		rmdir( $dir );
	}

	/** Abort a second concurrent run before it can clear the destination; snapshot the install. */
	public function lock_and_backup( $response, $hook_extra ) {
		if ( is_wp_error( $response ) || ! $this->is_ours( (array) $hook_extra ) ) {
			return $response;
		}
		if ( class_exists( '\WP_Upgrader' ) && ! \WP_Upgrader::create_lock( 'gasf_events_upgrade', 2 * MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'gasf_locked', 'Another gasf-events update is already running; skipped to avoid a destructive race.' );
		}
		$installed = dirname( $this->file );
		$backup    = $this->backup_dir();
		self::delete_tree( $backup );
		if ( is_dir( $installed ) && file_exists( $this->file ) ) {
			self::copy_tree( $installed, $backup );
		}
		return $response;
	}

	/** After install: verify the plugin dir is intact; restore the snapshot if not. */
	public function verify_or_restore( $result, $hook_extra ) {
		if ( ! $this->is_ours( (array) $hook_extra ) ) {
			return $result;
		}
		$installed = dirname( $this->file );
		$backup    = $this->backup_dir();
		$broken    = is_wp_error( $result ) || ! file_exists( trailingslashit( $installed ) . basename( $this->file ) );
		if ( $broken && is_dir( $backup ) && file_exists( trailingslashit( $backup ) . basename( $this->file ) ) ) {
			self::copy_tree( $backup, $installed );
			error_log( 'gasf-events updater: install left the plugin broken (' // phpcs:ignore
				. ( is_wp_error( $result ) ? $result->get_error_code() : 'main file missing' )
				. ') — restored the pre-update snapshot.' );
		}
		self::delete_tree( $backup );
		if ( class_exists( '\WP_Upgrader' ) ) {
			\WP_Upgrader::release_lock( 'gasf_events_upgrade' );
		}
		return $result;
	}

	/** Feed WP's update system when it asks about plugins with a github.com Update URI. */
	public function check( $update, array $plugin_data, string $plugin_file ) {
		if ( plugin_basename( $this->file ) !== $plugin_file ) {
			return $update;
		}
		$remote = $this->remote_version();
		if ( ! $remote || version_compare( $remote, (string) $plugin_data['Version'], '<=' ) ) {
			return $update; // up to date (or check failed — never downgrade/loop)
		}
		return [
			'id'      => 'https://github.com/' . self::REPO,
			'slug'    => dirname( $plugin_file ),
			'plugin'  => $plugin_file,
			'version' => $remote,
			'url'     => 'https://github.com/' . self::REPO,
			'package' => self::PACKAGE,
		];
	}

	/** Latest Version: header on the main branch (cached 15 min — was 6 h,
	 *  which made every fresh push invisible to `wp plugin update` for hours). */
	private function remote_version(): string {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$resp = wp_remote_get( self::RAW_MAIN, [ 'timeout' => 15 ] );
		$ver  = '';
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			if ( preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9a-z.\-]*)/mi', (string) wp_remote_retrieve_body( $resp ), $m ) ) {
				$ver = trim( $m[1] );
			}
		}
		set_transient( self::CACHE_KEY, $ver, 15 * MINUTE_IN_SECONDS );
		return $ver;
	}

	/**
	 * GitHub zipballs extract as GASF-Events-main/ — rename to the installed
	 * folder (GASF-Events/) so WordPress installs over the right plugin.
	 *
	 * Hardened to fail CLOSED: if it can't produce a correctly-named folder that
	 * actually contains the main plugin file, it returns a WP_Error. That aborts
	 * the upgrade in install_package() *before* WordPress deletes the currently
	 * installed version — so a flaky rename can never leave an empty, still-
	 * active plugin folder (the intermittent outage we kept hitting on Krampus).
	 */
	public function fix_folder( $source, $remote_source, $upgrader, $hook_extra ) {
		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || plugin_basename( $this->file ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source; // no filesystem to act on — leave WP's default behavior
		}
		$main = basename( plugin_basename( $this->file ) );                                    // gasf-events.php
		$want = trailingslashit( $remote_source ) . dirname( plugin_basename( $this->file ) ) . '/'; // …/GASF-Events/

		// Already correctly named: only accept it if it's a real package.
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) ) {
			return $wp_filesystem->exists( $want . $main )
				? $source
				: new \WP_Error( 'gasf_pkg_incomplete', sprintf( 'Update package is missing %s; keeping the installed version.', $main ) );
		}

		// Clear any stale target from a prior failed run, then overwrite-move so
		// move() can't early-return false on an existing destination.
		if ( $wp_filesystem->exists( $want ) ) {
			$wp_filesystem->delete( $want, true );
		}
		$moved = $wp_filesystem->move( $source, $want, true );

		// Verify before handing it back — never return a source that would copy
		// nothing into the plugin folder.
		if ( $moved && $wp_filesystem->exists( $want . $main ) ) {
			return $want;
		}
		return new \WP_Error( 'gasf_pkg_move', 'Could not prepare the update package (folder rename failed); keeping the installed version.' );
	}
}
