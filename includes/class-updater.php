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

	const REPO      = 'flinchbot/GASF-Events';
	const RAW_MAIN  = 'https://raw.githubusercontent.com/flinchbot/GASF-Events/main/gasf-events.php';
	const PACKAGE   = 'https://github.com/flinchbot/GASF-Events/archive/refs/heads/main.zip';
	const CACHE_KEY = 'gasf_events_update_check';

	private string $file; // absolute path to gasf-events.php

	public function __construct( string $file ) {
		$this->file = $file;
	}

	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', [ $this, 'check' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_folder' ], 10, 4 );
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

	/** Latest Version: header on the main branch (cached 6 h). */
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
		set_transient( self::CACHE_KEY, $ver, 6 * HOUR_IN_SECONDS );
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
