<?php
/**
 * Signed URL service.
 *
 * Generates time-limited, HMAC-signed URLs for gated media delivery.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;


/**
 * Generates and validates signed URLs for protected media files.
 */
class SignedUrlService {

	/**
	 * Default URL expiration in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEFAULT_TTL = 3600;

	/**
	 * Query parameter names.
	 */
	const PARAM_MEDIA_ID  = 'mvs_id';
	const PARAM_EXPIRES   = 'mvs_exp';
	const PARAM_SIGNATURE = 'mvs_sig';
	const PARAM_USER      = 'mvs_uid';
	const PARAM_DOWNLOAD  = 'mvs_dl';
	const PARAM_SIZE      = 'mvs_size';

	/**
	 * Access rules service.
	 *
	 * @var AccessRulesService
	 */
	private $access_rules;

	/**
	 * Privacy service.
	 *
	 * @var PrivacyService
	 */
	private $privacy;

	/**
	 * Constructor.
	 *
	 * @param AccessRulesService $access_rules Access rules service.
	 * @param PrivacyService     $privacy      Privacy service.
	 */
	public function __construct( AccessRulesService $access_rules, PrivacyService $privacy ) {
		$this->access_rules = $access_rules;
		$this->privacy      = $privacy;
	}

	/**
	 * Generate a signed URL for a media item.
	 *
	 * @param int  $media_id Media post ID.
	 * @param int  $user_id  User ID requesting the URL.
	 * @param int  $ttl      Time to live in seconds.
	 * @param bool $download  Whether this is a download (vs stream/view).
	 * @return string|false Signed URL or false if not authorized.
	 */
	public function generate( int $media_id, int $user_id, int $ttl = 0, bool $download = false ) {
		// Verify the user has access.
		if ( ! $this->privacy->can_view( $media_id, $user_id ) ) {
			return false;
		}

		if ( 0 === $ttl ) {
			$ttl = $this->get_ttl();
		}

		$expires = time() + $ttl;

		$params = array(
			self::PARAM_MEDIA_ID => $media_id,
			self::PARAM_USER     => $user_id,
			self::PARAM_EXPIRES  => $expires,
		);

		if ( $download ) {
			$params[ self::PARAM_DOWNLOAD ] = 1;
		}

		$signature                       = $this->sign( $params );
		$params[ self::PARAM_SIGNATURE ] = $signature;

		return add_query_arg( $params, $this->get_serve_endpoint() );
	}

	/**
	 * Generate a signed URL for a media thumbnail.
	 *
	 * @param int    $media_id           Media ID.
	 * @param int    $user_id            User ID requesting the URL.
	 * @param string $size               Thumbnail size: large, medium, thumbnail.
	 * @param int    $ttl                Time to live in seconds.
	 * @param bool   $skip_privacy_check Skip the privacy check (caller already verified access).
	 * @return string|false Signed URL or false if not authorized.
	 */
	public function generate_thumbnail( int $media_id, int $user_id, string $size = 'large', int $ttl = 0, bool $skip_privacy_check = false ) {
		if ( ! $skip_privacy_check && ! $this->privacy->can_view( $media_id, $user_id ) ) {
			return false;
		}

		$expires = time() + ( $ttl ?: $this->get_ttl() );

		$params = array(
			self::PARAM_MEDIA_ID => $media_id,
			self::PARAM_USER     => $user_id,
			self::PARAM_EXPIRES  => $expires,
			self::PARAM_SIZE     => $size,
		);

		$params[ self::PARAM_SIGNATURE ] = $this->sign( $params );

		return add_query_arg( $params, $this->get_serve_endpoint() );
	}

	/**
	 * Validate a signed URL's parameters.
	 *
	 * @param array $params URL query parameters.
	 * @return int|false Media ID if valid, false otherwise.
	 */
	public function validate( array $params ) {
		$required = array( self::PARAM_MEDIA_ID, self::PARAM_USER, self::PARAM_EXPIRES, self::PARAM_SIGNATURE );

		foreach ( $required as $key ) {
			// Use isset check — empty() rejects 0 which is valid for anonymous user ID.
			if ( ! isset( $params[ $key ] ) || '' === $params[ $key ] ) {
				return false;
			}
		}

		$media_id  = (int) $params[ self::PARAM_MEDIA_ID ];
		$user_id   = (int) $params[ self::PARAM_USER ];
		$expires   = (int) $params[ self::PARAM_EXPIRES ];
		$signature = $params[ self::PARAM_SIGNATURE ];

		// Check expiration.
		if ( time() > $expires ) {
			return false;
		}

		// Rebuild params without signature for verification.
		$verify_params = array(
			self::PARAM_MEDIA_ID => $media_id,
			self::PARAM_USER     => $user_id,
			self::PARAM_EXPIRES  => $expires,
		);

		if ( ! empty( $params[ self::PARAM_DOWNLOAD ] ) ) {
			$verify_params[ self::PARAM_DOWNLOAD ] = 1;
		}

		if ( ! empty( $params[ self::PARAM_SIZE ] ) ) {
			$verify_params[ self::PARAM_SIZE ] = sanitize_text_field( $params[ self::PARAM_SIZE ] );
		}

		$expected_sig = $this->sign( $verify_params );

		if ( ! hash_equals( $expected_sig, $signature ) ) {
			return false;
		}

		return $media_id;
	}

	/**
	 * Serve a file for a validated signed URL request.
	 *
	 * @param array $params Validated URL parameters.
	 */
	public function serve( array $params ): void {
		$media_id = $this->validate( $params );

		if ( ! $media_id ) {
			status_header( 403 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Invalid or expired signed URL.' );
			exit;
		}

		// Dispatch thumbnail requests to a dedicated handler.
		$size = ! empty( $params[ self::PARAM_SIZE ] ) ? sanitize_text_field( $params[ self::PARAM_SIZE ] ) : '';
		if ( $size ) {
			$this->serve_thumbnail( $media_id, $size );
			return;
		}

		// Resolve absolute filesystem path with traversal containment baked in.
		$full_path = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_filesystem_path( $media_id );
		$file_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'file_type' );

		if ( null === $full_path ) {
			status_header( 404 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'File not found.' );
			exit;
		}

		$is_download = ! empty( $params[ self::PARAM_DOWNLOAD ] );
		$filename    = sanitize_file_name( basename( $full_path ) );
		$filename    = str_replace( array( "\r", "\n" ), '', $filename );

		// Record download event if applicable.
		if ( $is_download ) {
			$this->record_download( $media_id, (int) $params[ self::PARAM_USER ] );
		}

		// Validate Content-Type against safe MIME types.
		$safe_types   = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
			'image/svg+xml',
			'image/bmp',
			'video/mp4',
			'video/webm',
			'video/ogg',
			'video/quicktime',
			'audio/mpeg',
			'audio/ogg',
			'audio/wav',
			'audio/webm',
			'audio/mp4',
			'audio/flac',
			'application/pdf',
		);
		$content_type = ( $file_type && in_array( $file_type, $safe_types, true ) ) ? $file_type : 'application/octet-stream';

		// Send appropriate headers.
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . filesize( $full_path ) );

		if ( $is_download ) {
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		} else {
			header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		}

		// Support range requests for streaming.
		$this->handle_range_request( $full_path );
	}

	/**
	 * Serve a thumbnail or watermarked file for a validated signed request.
	 *
	 * The 'watermark' size points at the per-media preview generated by Pro's
	 * Watermarker (stored at `wpmediaverse/previews/{media_id}-preview.jpg`).
	 * Watermarks intentionally have no fallback chain: if Pro never wrote the
	 * preview file, we 404 — the caller should fall back to the lock-overlay
	 * UI rather than leaking the original.
	 *
	 * @param int    $media_id Validated media ID.
	 * @param string $size     Requested size (large|medium|thumbnail|watermark).
	 */
	private function serve_thumbnail( int $media_id, string $size ): void {
		// Internal: signing service serves the underlying file from disk —
		// must use the raw stored URL, not a signed-URL re-emission.
		if ( 'watermark' === $size ) {
			$thumb_url = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'watermark_url' );
		} else {
			$size_map  = array(
				'large'     => 'thumb_large',
				'medium'    => 'thumb_medium',
				'thumbnail' => 'thumb_thumb',
			);
			$meta_key  = $size_map[ $size ] ?? 'thumb_large';
			$thumb_url = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, $meta_key )
				?: \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'thumb_medium' )
				?: \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'thumb_thumb' )
				?: \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'file_url' );
		}

		if ( ! $thumb_url ) {
			status_header( 404 );
			header( 'Content-Type: text/plain' );
			echo esc_html(
				'watermark' === $size ? 'Watermark not found.' : 'Thumbnail not found.'
			);
			exit;
		}

		$upload_dir     = wp_upload_dir();
		$base_url       = trailingslashit( set_url_scheme( $upload_dir['baseurl'], 'http' ) );
		$thumb_url_http = set_url_scheme( $thumb_url, 'http' );

		if ( 0 !== strpos( $thumb_url_http, $base_url ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Access denied.' );
			exit;
		}

		$full_path = trailingslashit( $upload_dir['basedir'] ) . substr( $thumb_url_http, strlen( $base_url ) );
		$real_path = realpath( $full_path );
		$real_base = realpath( trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse' );

		if ( false === $real_path || false === $real_base || 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Access denied.' );
			exit;
		}

		$ext       = strtolower( pathinfo( $full_path, PATHINFO_EXTENSION ) );
		$mime_map  = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
		$mime_type = $mime_map[ $ext ] ?? 'image/jpeg';
		$filename  = sanitize_file_name( basename( $full_path ) );

		nocache_headers();
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $full_path ) );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		$this->handle_range_request( $full_path );
	}

	/**
	 * Check if a media item requires signed URLs.
	 *
	 * Media with active access rules should use signed URLs.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	public function requires_signed_url( int $media_id ): bool {
		return $this->access_rules->has_active_rules( $media_id );
	}

	/**
	 * Get the configured TTL for signed URLs.
	 *
	 * @return int TTL in seconds.
	 */
	private function get_ttl(): int {
		$ttl = (int) get_option( 'mvs_signed_url_ttl', self::DEFAULT_TTL );
		return max( 60, $ttl ); // Minimum 60 seconds.
	}

	/**
	 * Get the signing secret key.
	 *
	 * @return string
	 */
	private function get_secret(): string {
		$secret = get_option( 'mvs_signed_url_secret' );

		if ( ! $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'mvs_signed_url_secret', $secret, false );
		}

		return $secret;
	}

	/**
	 * Generate HMAC-SHA256 signature for URL parameters.
	 *
	 * @param array $params Parameters to sign.
	 * @return string Hex-encoded signature.
	 */
	private function sign( array $params ): string {
		ksort( $params );
		$payload = http_build_query( $params );
		return hash_hmac( 'sha256', $payload, $this->get_secret() );
	}

	/**
	 * Get the serve endpoint URL.
	 *
	 * @return string
	 */
	private function get_serve_endpoint(): string {
		return rest_url( 'mvs/v1/serve' );
	}

	/**
	 * Record a download event in the stats.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 */
	private function record_download( int $media_id, int $user_id ): void {
		global $wpdb;

		$ip_hash = hash( 'sha256', $this->get_client_ip() . wp_salt() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_views',
			array(
				'media_id'   => $media_id,
				'user_id'    => $user_id ? $user_id : null,
				'ip_hash'    => $ip_hash,
				'event_type' => 'download',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"UPDATE {$wpdb->prefix}mvs_media_stats SET downloads = downloads + 1, updated_at = %s WHERE media_id = %d",
				current_time( 'mysql', true ),
				$media_id
			)
		);
	}

	/**
	 * Handle HTTP range requests for media streaming.
	 *
	 * @param string $file_path Full file path.
	 */
	private function handle_range_request( string $file_path ): void {
		$file_size = filesize( $file_path );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( isset( $_SERVER['HTTP_RANGE'] ) ) {
			$range = sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) );

			if ( preg_match( '/bytes=(\d+)-(\d*)/', $range, $matches ) ) {
				$start = (int) $matches[1];
				$end   = ! empty( $matches[2] ) ? (int) $matches[2] : $file_size - 1;

				if ( $start > $end || $start >= $file_size ) {
					status_header( 416 );
					header( "Content-Range: bytes */{$file_size}" );
					exit;
				}

				$length = $end - $start + 1;

				status_header( 206 );
				header( "Content-Range: bytes {$start}-{$end}/{$file_size}" );
				header( "Content-Length: {$length}" );
				header( 'Accept-Ranges: bytes' );

				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				$fp = fopen( $file_path, 'rb' );
				fseek( $fp, $start );
				// Binary file data — escaping not applicable.
				echo fread( $fp, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped
				fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				exit;
			}
		}

		header( 'Accept-Ranges: bytes' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}

	/**
	 * Get client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}
		return '127.0.0.1';
	}
}
