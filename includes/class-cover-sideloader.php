<?php
/**
 * Downloads a Facebook cover and attaches it, de-duplicating by file SHA1 so
 * the ~175 recurring Biergartens (which share one cover) reuse a single master
 * attachment instead of storing hundreds of copies. Ports the proven Module H
 * pattern. See docs/ARCHITECTURE.md §6.4.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Cover_Sideloader {

	/**
	 * Ensure $post_id's thumbnail reflects the FB cover at $url. Returns the
	 * attachment id used (existing master or newly created), or 0 on failure.
	 */
	public static function sync( int $post_id, string $url, string $cover_id ): int {
		$url = trim( $url );
		if ( '' === $url ) {
			return 0;
		}

		// Skip if we already synced this exact cover id and the thumb still exists.
		$current_cover = (string) get_post_meta( $post_id, Meta::FB_COVER_ID, true );
		$thumb_id      = (int) get_post_thumbnail_id( $post_id );
		if ( $cover_id && $cover_id === $current_cover && $thumb_id && self::file_exists( $thumb_id ) ) {
			return $thumb_id;
		}

		// SSRF guard: the cover URL comes from the (untrusted) Graph response, and
		// download_url() does not apply reject_unsafe_urls. Covers always live on
		// Facebook's CDN, so require https + an fbcdn/facebook host before fetching.
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$ok   = ( 'https' === wp_parse_url( $url, PHP_URL_SCHEME ) ) && (
			str_ends_with( $host, '.fbcdn.net' ) || str_ends_with( $host, '.facebook.com' ) || str_ends_with( $host, '.fbsbx.com' )
		);
		if ( ! $ok || ! wp_http_validate_url( $url ) ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 30 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$sha = sha1_file( $tmp );

		// Reuse an existing master with the same bytes.
		$master = $sha ? self::find_master( $sha ) : 0;
		if ( $master ) {
			@unlink( $tmp ); // phpcs:ignore
			set_post_thumbnail( $post_id, $master );
			if ( $cover_id ) {
				update_post_meta( $post_id, Meta::FB_COVER_ID, $cover_id );
			}
			return $master;
		}

		// Otherwise sideload a new attachment and tag it as a master.
		$name = 'gasf-event-cover-' . ( $cover_id ?: substr( (string) $sha, 0, 12 ) ) . '.jpg';
		$file = [ 'name' => $name, 'tmp_name' => $tmp, 'error' => 0, 'size' => (int) @filesize( $tmp ) ];
		$att_id = media_handle_sideload( $file, $post_id, null );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp ); // phpcs:ignore
			return 0;
		}
		if ( $sha ) {
			update_post_meta( $att_id, Meta::ON_ATTACHMENT_COVER_SHA1, $sha );
		}
		set_post_thumbnail( $post_id, $att_id );
		if ( $cover_id ) {
			update_post_meta( $post_id, Meta::FB_COVER_ID, $cover_id );
		}
		return (int) $att_id;
	}

	/** Lowest-id attachment tagged with this SHA1 whose file still exists. */
	private static function find_master( string $sha ): int {
		$ids = get_posts( [
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'numberposts'      => 10,
			'fields'           => 'ids',
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'meta_key'         => Meta::ON_ATTACHMENT_COVER_SHA1,
			'meta_value'       => $sha,
			'suppress_filters' => true,
		] );
		foreach ( $ids as $id ) {
			if ( self::file_exists( (int) $id ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private static function file_exists( int $att_id ): bool {
		$path = get_attached_file( $att_id );
		return $path && file_exists( $path );
	}
}
