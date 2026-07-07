<?php
/**
 * Uploads images to Eventbrite. Three-step flow:
 *   1. GET  /media/upload/?type=image-event-logo → presign info (S3 form + token)
 *   2. POST upload_url with the file as multipart form data
 *   3. POST /media/upload/ with the upload_token → returns Eventbrite media id
 *
 * Eventbrite accepts only PNG / JPEG. We transcode anything else (webp,
 * gif, avif) via GD before upload.
 *
 * Ported from GASF-EventCalendars\includes\class-image-uploader.php so the
 * Eventbrite publish destination can keep the listing's graphic in sync —
 * previously it only pushed name/dates/summary and never touched the image.
 *
 * @package GASF_Events
 */

namespace GASF_Events;

defined( 'ABSPATH' ) || exit;

final class Image_Uploader {

	const API = 'https://www.eventbriteapi.com/v3';

	private string $token;

	public function __construct( string $token ) {
		$this->token = $token;
	}

	/**
	 * Upload an image from a WP Media Library attachment id.
	 * Returns the Eventbrite media id, or null on any failure (caller logs
	 * + continues without an image).
	 */
	public function upload_from_attachment( int $attachment_id ): ?string {
		$path = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return null;
		}
		$bytes    = file_get_contents( $path );
		$ct       = get_post_mime_type( $attachment_id ) ?: 'image/jpeg';
		$filename = basename( $path );
		return $this->upload_bytes( $bytes, $ct, $filename );
	}

	private function upload_bytes( string $bytes, string $ct, string $filename ): ?string {
		// Transcode anything that isn't PNG/JPEG.
		if ( ! preg_match( '#^image/(png|jpe?g)$#i', $ct ) ) {
			$png = $this->transcode_to_png( $bytes );
			if ( null === $png ) {
				return null;
			}
			$bytes    = $png;
			$ct       = 'image/png';
			$filename = preg_replace( '/\.[a-z0-9]+$/i', '', $filename ) . '.png';
		}

		// Step 1 — GET presign info. Bare request (no Content-Type header).
		$resp = wp_remote_get( self::API . '/media/upload/?type=image-event-logo', [
			'timeout' => 30,
			'headers' => [ 'Authorization' => 'Bearer ' . $this->token ],
		] );
		if ( is_wp_error( $resp ) || 200 !== wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$presign = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $presign ) || empty( $presign['upload_url'] ) || empty( $presign['upload_token'] ) ) {
			return null;
		}

		// Step 2 — POST file bytes to the presigned URL with multipart form-data.
		$boundary = wp_generate_password( 24, false );
		$body     = $this->build_multipart_body(
			(array) ( $presign['upload_data'] ?? [] ),
			$filename,
			$ct,
			$bytes,
			$boundary
		);
		$method   = strtoupper( $presign['upload_method'] ?? 'POST' );
		$s3_resp  = wp_remote_request( $presign['upload_url'], [
			'method'  => $method,
			'timeout' => 60,
			'headers' => [
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			],
			'body'    => $body,
		] );
		if ( is_wp_error( $s3_resp ) ) {
			return null;
		}
		$s3_status = wp_remote_retrieve_response_code( $s3_resp );
		if ( $s3_status < 200 || $s3_status >= 300 ) {
			return null;
		}

		// Step 3 — finalize.
		$finalize = wp_remote_post( self::API . '/media/upload/', [
			'timeout' => 30,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->token,
				'Content-Type'  => 'application/json',
			],
			'body' => wp_json_encode( [
				'upload_token' => $presign['upload_token'],
				'crop_mask'    => [
					'top_left' => [ 'x' => 0, 'y' => 0 ],
					'width'    => 2160,
					'height'   => 1080,
				],
			] ),
		] );
		if ( is_wp_error( $finalize ) || 200 !== wp_remote_retrieve_response_code( $finalize ) ) {
			return null;
		}
		$data = json_decode( wp_remote_retrieve_body( $finalize ), true );
		return $data['id'] ?? null;
	}

	private function transcode_to_png( string $bytes ): ?string {
		if ( ! function_exists( 'imagecreatefromstring' ) ) {
			return null;
		}
		$img = @imagecreatefromstring( $bytes );
		if ( ! $img ) {
			return null;
		}
		ob_start();
		$ok  = imagepng( $img );
		$png = ob_get_clean();
		imagedestroy( $img );
		return $ok && $png ? $png : null;
	}

	private function build_multipart_body( array $fields, string $filename, string $ct, string $file_bytes, string $boundary ): string {
		$crlf = "\r\n";
		$body = '';
		foreach ( $fields as $key => $value ) {
			$body .= "--$boundary$crlf";
			$body .= 'Content-Disposition: form-data; name="' . $key . '"' . $crlf . $crlf;
			$body .= $value . $crlf;
		}
		$body .= "--$boundary$crlf";
		$body .= 'Content-Disposition: form-data; name="file"; filename="' . $filename . '"' . $crlf;
		$body .= 'Content-Type: ' . $ct . $crlf . $crlf;
		$body .= $file_bytes . $crlf;
		$body .= "--$boundary--$crlf";
		return $body;
	}
}
