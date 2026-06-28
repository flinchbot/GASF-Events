<?php
/**
 * WP-CLI commands for the MEC → gasf_event migration.
 *
 *   wp gasf-events report
 *   wp gasf-events migrate [--mode=copy|convert] [--dry-run] [--limit=<n>] [--status=<csv>] [--yes]
 *   wp gasf-events cleanup-copies [--yes]
 *
 * `migrate` defaults to a DRY RUN — it reports what it would do and writes
 * nothing unless you pass --no-dry-run. `copy` mode is non-destructive parallel
 * validation; `convert` (in place) is reserved for cutover.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class CLI {

	/**
	 * Analyze the MEC dataset before migrating (read-only).
	 *
	 * ## OPTIONS
	 * [--status=<csv>]
	 * : Comma-separated post statuses to inspect. Default: publish,draft.
	 *
	 * @when after_wp_load
	 */
	public function report( $args, $assoc ): void {
		$statuses = self::statuses( $assoc );
		$ids = Migrator::mec_ids( $statuses );
		\WP_CLI::log( sprintf( 'MEC posts (%s): %d', implode( ',', $statuses ), count( $ids ) ) );

		$by_status = [];   // our mapped status
		$raw_status = [];  // MEC raw status values
		$flags = [];
		$with_fb = 0;
		$samples = [];

		foreach ( $ids as $i => $id ) {
			$d = Migrator::derive( (int) $id );
			$ms = $d['meta'][ Meta::STATUS ] ?: '(scheduled)';
			$by_status[ $ms ] = ( $by_status[ $ms ] ?? 0 ) + 1;
			if ( '' !== $d['raw_status'] ) {
				$raw_status[ $d['raw_status'] ] = ( $raw_status[ $d['raw_status'] ] ?? 0 ) + 1;
			}
			if ( ! empty( $d['meta'][ Meta::FB_EVENT_ID ] ) ) {
				$with_fb++;
			}
			foreach ( $d['flags'] as $f ) {
				$key = explode( ':', $f )[0];
				$flags[ $key ] = ( $flags[ $key ] ?? 0 ) + 1;
			}
			if ( count( $samples ) < 6 ) {
				$samples[] = [
					'mec_id' => $id,
					'title'  => mb_substr( get_the_title( $id ), 0, 40 ),
					'start'  => $d['meta'][ Meta::START ],
					'end'    => $d['meta'][ Meta::END ],
					'status' => $ms,
					'source' => $d['meta'][ Meta::SOURCE ],
				];
			}
		}

		\WP_CLI::log( sprintf( "\nSource: %d facebook / %d manual", $with_fb, count( $ids ) - $with_fb ) );

		\WP_CLI::log( "\nMapped status:" );
		\WP_CLI\Utils\format_items( 'table', self::kv( $by_status, 'status', 'count' ), [ 'status', 'count' ] );

		\WP_CLI::log( "\nRaw mec_event_status values (verify these map correctly):" );
		\WP_CLI\Utils\format_items( 'table', self::kv( $raw_status ?: [ '(none)' => count( $ids ) ], 'raw', 'count' ), [ 'raw', 'count' ] );

		\WP_CLI::log( "\nAnomaly flags:" );
		\WP_CLI\Utils\format_items( 'table', self::kv( $flags ?: [ '(none)' => 0 ], 'flag', 'count' ), [ 'flag', 'count' ] );

		\WP_CLI::log( "\nSample derivations:" );
		\WP_CLI\Utils\format_items( 'table', $samples, [ 'mec_id', 'title', 'start', 'end', 'status', 'source' ] );

		\WP_CLI::success( 'Report complete (no data written).' );
	}

	/**
	 * Migrate MEC events to gasf_event. DRY RUN by default.
	 *
	 * ## OPTIONS
	 * [--mode=<mode>]   : copy (parallel, non-destructive) | convert (in place, cutover). Default: copy.
	 * [--dry-run]       : Report only, write nothing. Default behavior; pass --no-dry-run to write.
	 * [--limit=<n>]     : Only process the first N (testing). Default: all.
	 * [--status=<csv>]  : Statuses to migrate. Default: publish,draft.
	 * [--yes]           : Skip the confirmation prompt for convert mode.
	 *
	 * @when after_wp_load
	 */
	public function migrate( $args, $assoc ): void {
		$mode    = ( $assoc['mode'] ?? 'copy' ) === 'convert' ? 'convert' : 'copy';
		$dry     = ! isset( $assoc['dry-run'] ) ? true : (bool) $assoc['dry-run']; // default dry
		$limit   = isset( $assoc['limit'] ) ? (int) $assoc['limit'] : -1;
		$statuses = self::statuses( $assoc );

		$ids = Migrator::mec_ids( $statuses, $limit );
		\WP_CLI::log( sprintf( '%s mode | %s | %d MEC posts (%s)',
			strtoupper( $mode ),
			$dry ? 'DRY RUN (no writes)' : 'WRITING',
			count( $ids ),
			implode( ',', $statuses )
		) );

		if ( ! $dry && 'convert' === $mode && ! isset( $assoc['yes'] ) ) {
			\WP_CLI::confirm( 'CONVERT changes post_type in place (cutover step). Proceed?' );
		}
		if ( $dry ) {
			$anom = 0;
			foreach ( $ids as $id ) {
				$d = Migrator::derive( (int) $id );
				if ( $d['flags'] ) {
					$anom++;
				}
			}
			\WP_CLI::success( sprintf( 'Dry run: would %s %d events (%d flagged — run `report` for detail). No data written.', $mode, count( $ids ), $anom ) );
			return;
		}

		$bar  = \WP_CLI\Utils\make_progress_bar( ucfirst( $mode ) . 'ing', count( $ids ) );
		$ok = 0; $fail = 0;
		foreach ( $ids as $id ) {
			$res = ( 'convert' === $mode ) ? Migrator::convert_one( (int) $id ) : (bool) Migrator::copy_one( (int) $id );
			$res ? $ok++ : $fail++;
			$bar->tick();
		}
		$bar->finish();
		\WP_CLI::success( sprintf( '%s: %d ok, %d failed.', ucfirst( $mode ), $ok, $fail ) );
		if ( 'copy' === $mode ) {
			\WP_CLI::log( 'Validate at /' . GASF_EVENTS_REWRITE_SLUG . '/ , then `wp gasf-events cleanup-copies` before cutover.' );
		}
	}

	/**
	 * Delete the parallel copy-mode gasf_event posts (does NOT touch MEC).
	 *
	 * ## OPTIONS
	 * [--yes] : Skip confirmation.
	 *
	 * @when after_wp_load
	 */
	public function cleanup_copies( $args, $assoc ): void {
		$ids = Migrator::copy_ids();
		if ( ! $ids ) {
			\WP_CLI::success( 'No copy-mode posts found.' );
			return;
		}
		if ( ! isset( $assoc['yes'] ) ) {
			\WP_CLI::confirm( sprintf( 'Permanently delete %d copy-mode gasf_event posts?', count( $ids ) ) );
		}
		$n = 0;
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$n++;
			}
		}
		\WP_CLI::success( sprintf( 'Deleted %d copy-mode posts.', $n ) );
	}

	/* ---- helpers ------------------------------------------------------ */

	private static function statuses( array $assoc ): array {
		$raw = (string) ( $assoc['status'] ?? 'publish,draft' );
		return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
	}

	private static function kv( array $map, string $k, string $v ): array {
		$out = [];
		arsort( $map );
		foreach ( $map as $key => $val ) {
			$out[] = [ $k => (string) $key, $v => $val ];
		}
		return $out;
	}
}
