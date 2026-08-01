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

		// Opt-in: skip the gated proxy and return the active driver's direct
		// URL for public media on cloud drivers. Browsers hit the CDN edge
		// instead of WordPress for every <img>. Download requests stay on
		// the gated path (Content-Disposition headers + download counters).
		if ( ! $download ) {
			$direct = $this->maybe_direct_cloud_url( $media_id );
			if ( '' !== $direct ) {
				return $direct;
			}
		}

		if ( 0 === $ttl ) {
			$ttl = $this->get_ttl();
		}

		$expires = $this->resolve_expiry( $media_id, $ttl );

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

		// Direct CDN path — size-aware. If thumbnails for this media were
		// pushed to cloud at upload time (UploadService Phase 1.2.2 work),
		// the stored thumb_<size> meta IS already the cloud URL — return
		// it directly so the browser hits the CDN edge instead of WP.
		$direct_thumb = $this->maybe_direct_cloud_thumbnail_url( $media_id, $size );
		if ( '' !== $direct_thumb ) {
			return $direct_thumb;
		}

		// Fallback: full-file direct-CDN. Only valid for images — an MP4 / MP3
		// / WebM served as <img src> renders as a broken image (the browser
		// cannot decode media bytes as a still). Videos must fall through to
		// the <video preload="metadata"> render path, and audio to the audio
		// placeholder card — both in TemplateHelpers::media_thumbnail(). This
		// mirrors the file_type gate already present in has_resolvable_thumbnail()
		// and serve_thumbnail().
		$repo      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$file_type = (string) $repo->get_raw( $media_id, 'file_type' );
		if ( 0 === strpos( $file_type, 'image/' ) ) {
			$direct = $this->maybe_direct_cloud_url( $media_id );
			if ( '' !== $direct ) {
				return $direct;
			}
		}

		// Existence gate (Basecamp #9871025511). For videos with no embedded
		// poster frame, no thumb_<size> meta was ever written. Pre-1.2.2 this
		// function still returned a valid-looking signed URL — the template
		// rendered <img src=signed-url> which then 404'd at serve time,
		// producing a blank/broken poster instead of triggering the template's
		// <video>/placeholder fallback. Mirror serve_thumbnail()'s fallback
		// chain here so we only sign URLs that will actually resolve to bytes.
		if ( ! $this->has_resolvable_thumbnail( $media_id ) ) {
			return false;
		}

		$expires = $this->resolve_expiry( $media_id, $ttl ?: $this->get_ttl() );

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
	 * Will serve_thumbnail() be able to find any byte for this media?
	 *
	 * Mirrors the fallback chain inside serve_thumbnail() exactly:
	 *   1. Any of thumb_large / thumb_medium / thumb_thumb meta exists
	 *   2. OR (image only) file_url exists
	 *
	 * Returning false here lets generate_thumbnail() return false, which lets
	 * the calling template render its placeholder/<video> fallback instead of
	 * shipping a signed URL that's guaranteed to 404 (Basecamp #9871025511).
	 *
	 * @since 1.2.1
	 *
	 * @param int $media_id Media ID.
	 * @return bool True when at least one resolvable bytes source exists.
	 */
	private function has_resolvable_thumbnail( int $media_id ): bool {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Check _path keys first (1.4.0+ source of truth), then fall back to
		// the legacy URL keys for pre-migration rows.
		foreach ( array( 'thumb_large_path', 'thumb_medium_path', 'thumb_thumb_path' ) as $key ) {
			$value = $repo->get_raw( $media_id, $key );
			if ( is_string( $value ) && '' !== $value ) {
				return true;
			}
		}
		foreach ( array( 'thumb_large', 'thumb_medium', 'thumb_thumb' ) as $thumb_key ) {
			$value = $repo->get_raw( $media_id, $thumb_key );
			if ( is_string( $value ) && '' !== $value ) {
				return true;
			}
		}

		// Images can use the original file as a poster — videos cannot
		// (serving a .mp4 with image headers produces a black poster).
		$file_type = (string) $repo->get_raw( $media_id, 'file_type' );
		if ( 0 === strpos( $file_type, 'image/' ) ) {
			$file_path = $repo->get_raw( $media_id, 'file_path' );
			if ( is_string( $file_path ) && '' !== $file_path ) {
				return true;
			}
			$file_url = $repo->get_raw( $media_id, 'file_url' );
			if ( is_string( $file_url ) && '' !== $file_url ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Validate a signed URL's parameters.
	 *
	 * @param array $params URL query parameters.
	 * @return int|false Media ID if valid, false otherwise.
	 */
	public function validate( array $params ) {
		$expired = false;
		$result  = $this->validate_signature( $params, $expired );

		return ( false === $result || $expired ) ? false : $result;
	}

	/**
	 * Verify a signed URL's HMAC and report expiry separately.
	 *
	 * Signature verification and expiry are distinct concerns: a correct HMAC
	 * proves the URL was minted by this site for exactly these params; expiry
	 * only bounds how long a non-public URL works as a bearer token. serve()
	 * needs them apart so public media behind a full-page cache (whose cached
	 * HTML outlives the TTL) keeps rendering — the privacy gate, not the
	 * clock, is what protects public files.
	 *
	 * @param array $params  URL query parameters.
	 * @param bool  $expired Set to true when the HMAC is valid but past expiry.
	 * @return int|false Media ID when the HMAC verifies, false otherwise.
	 */
	private function validate_signature( array $params, bool &$expired ) {
		$expired  = false;
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

		$expired = time() > $expires;

		return $media_id;
	}

	/**
	 * Serve a file for a validated signed URL request.
	 *
	 * @param array $params Validated URL parameters.
	 */
	public function serve( array $params ): void {
		$expired  = false;
		$media_id = $this->validate_signature( $params, $expired );

		if ( ! $media_id ) {
			status_header( 403 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Invalid or expired signed URL.' );
			exit;
		}

		// Restricted media: re-check view access for the token's signed viewer.
		// Browsers fetch <img src> without the X-WP-Nonce header, so
		// get_current_user_id() returns 0 even for the owner and every non-public
		// privacy level denies. The HMAC verified in validate_signature() above
		// guarantees mvs_uid was not tampered with, and the token's expiry bounds
		// the bearer-style transferability window. Prefer the live session id
		// when one is present (cookie-authenticated tab fetches), otherwise fall
		// back to the signed viewer id.
		$privacy        = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'privacy' );
		$session_uid    = (int) get_current_user_id();
		$token_uid      = (int) ( $params[ self::PARAM_USER ] ?? 0 );
		$viewer_user_id = $session_uid > 0 ? $session_uid : $token_uid;

		if ( $expired ) {
			// An expired-but-correctly-signed URL is only a problem for
			// non-public media, where expiry bounds the bearer window. Public
			// media is protected by the privacy gate, not the clock — and
			// full-page caches (Batcache, CDN page caches) routinely serve
			// HTML older than the signed-URL TTL, which 403'd every thumbnail
			// for anonymous visitors on page-cached hosts. Disable via
			// add_filter( 'mvs_serve_expired_public_urls', '__return_false' ).
			$serve_expired_public = apply_filters( 'mvs_serve_expired_public_urls', true, $media_id );

			if ( 'public' !== $privacy || ! $serve_expired_public ) {
				status_header( 403 );
				header( 'Content-Type: text/plain' );
				echo esc_html( 'Invalid or expired signed URL.' );
				exit;
			}
		}

		if ( 'public' !== $privacy && ! $this->privacy->can_view( $media_id, $viewer_user_id ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Access denied.' );
			exit;
		}

		// Dispatch thumbnail requests to a dedicated handler.
		$size = ! empty( $params[ self::PARAM_SIZE ] ) ? sanitize_text_field( $params[ self::PARAM_SIZE ] ) : '';
		if ( $size ) {
			$this->serve_thumbnail( $media_id, $size, $privacy );
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
		// Prefer the original (user-provided) filename when present — keeps
		// downloads recognisable for end users even when the on-disk basename
		// is a 1.2.1+ hash. Falls back to the on-disk basename for older
		// uploads where original_filename meta is absent.
		$repo              = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$original_filename = (string) $repo->get( $media_id, 'original_filename' );
		$filename          = '' !== $original_filename
			? sanitize_file_name( $original_filename )
			: sanitize_file_name( basename( $full_path ) );
		$filename          = str_replace( array( "\r", "\n", '"' ), '', $filename );

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

		// Modern-format content negotiation: prefer AVIF, then WebP, then the
		// original. The signed URL still validates the JPEG/PNG path; we just
		// substitute the response body so HTTP caches and the browser only
		// ever see one surface per signed URL. Vary: Accept keeps shared
		// caches honest. AVIF/WebP since 1.3.0.
		$avif_path = $this->local_avif_variant_path( $media_id, '', $content_type, $is_download );
		if ( '' !== $avif_path ) {
			$full_path    = $avif_path;
			$content_type = 'image/avif';
		} else {
			$webp_path = $this->local_webp_variant_path( $media_id, '', $content_type, $is_download );
			if ( '' !== $webp_path ) {
				$full_path    = $webp_path;
				$content_type = 'image/webp';
			}
		}

		// Drain output buffers + disable compression BEFORE sending headers
		// so the Content-Length on the wire matches the body bytes exactly.
		// Without this, php.ini output_buffering / zlib.output_compression /
		// stray plugin output produces ERR_CONTENT_LENGTH_MISMATCH (Chrome)
		// for HTML5 <video> range requests.
		$this->prepare_binary_stream();

		// Send appropriate headers. Public media is cacheable (privacy gate, not
		// the clock, protects it); private/restricted stays no-store. (1.7.0)
		$this->emit_cache_headers( $privacy );
		header( 'Vary: Accept' );
		header( 'Content-Type: ' . $content_type );

		// SVG defense in depth (WMV-03, Basecamp #9919403906): force
		// attachment disposition + restrictive CSP for SVG so an unsanitized
		// SVG carrying inline <script> cannot execute in the site origin even
		// if an admin enables image/svg+xml uploads. The proper long-term
		// fix is integrating a sanitizer on upload; this prevents the worst-
		// case execution today.
		$is_svg = ( 'image/svg+xml' === $content_type );
		if ( $is_svg ) {
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
			header( "Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox" );
			header( 'X-Content-Type-Options: nosniff' );
		} elseif ( $is_download ) {
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		} else {
			header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		}

		// handle_range_request sets the correct Content-Length for both
		// the full-file (200) and partial (206) branches.
		$this->handle_range_request( $full_path );
	}

	/**
	 * Serve a thumbnail file for a validated signed request.
	 *
	 * @param int    $media_id Validated media ID.
	 * @param string $size     Requested size (large|medium|thumbnail).
	 */
	/**
	 * First candidate that points at an image (a valid poster), skipping empties
	 * and any video/audio variant. Pre-1.6.0 video rows wrote the source .mp4
	 * into thumb_<size>_path for upscale-skipped sizes; serving that as a
	 * thumbnail produces a broken poster (Basecamp #9952600334).
	 *
	 * @param string[] $candidates Ordered paths or URLs.
	 * @return string First image candidate, or '' when none qualify.
	 */
	private static function first_image_path( array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			if ( '' === $candidate ) {
				continue;
			}
			$path = (string) ( wp_parse_url( $candidate, PHP_URL_PATH ) ?: $candidate );
			$ext  = strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ), true ) ) {
				return $candidate;
			}
		}
		return '';
	}

	private function serve_thumbnail( int $media_id, string $size, string $privacy = '' ): void {
		// Internal: signing service serves the underlying file from disk —
		// must use the raw stored URL, not a signed-URL re-emission.
		$rel_path  = '';
		$thumb_url = '';

		$size_map = array(
			'large'     => 'thumb_large',
			'medium'    => 'thumb_medium',
			'thumbnail' => 'thumb_thumb',
		);
		$meta_key = $size_map[ $size ] ?? 'thumb_large';
		$repo     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Path-meta first (1.4.0+, driver-agnostic). Fall back through
		// image sizes the same way the URL chain did. NEVER fall back
		// to file_url for videos — serving a .mp4 with image/jpeg
		// headers produces a black <video poster> instead of a 404 the
		// browser can recover from. Skip any variant that points at a
		// non-image file: pre-1.6.0 video rows wrote the .mp4 itself into
		// thumb_large_path for upscale-skipped sizes, so the requested size
		// must fall through to a real poster (Basecamp #9952600334).
		$rel_path = self::first_image_path(
			array(
				(string) $repo->get_raw( $media_id, $meta_key . '_path' ),
				(string) $repo->get_raw( $media_id, 'thumb_medium_path' ),
				(string) $repo->get_raw( $media_id, 'thumb_thumb_path' ),
			)
		);

		if ( '' === $rel_path ) {
			$file_type = (string) $repo->get_raw( $media_id, 'file_type' );
			if ( 0 === strpos( $file_type, 'image/' ) ) {
				$rel_path = (string) $repo->get_raw( $media_id, 'file_path' );
			}
		}

		// Legacy URL fallback for pre-migration rows. Same image-only guard:
		// a video URL stored in thumb_large must not win over the medium /
		// thumb poster URLs.
		if ( '' === $rel_path ) {
			$thumb_url = self::first_image_path(
				array(
					(string) $repo->get_raw( $media_id, $meta_key ),
					(string) $repo->get_raw( $media_id, 'thumb_medium' ),
					(string) $repo->get_raw( $media_id, 'thumb_thumb' ),
				)
			);
			if ( '' === $thumb_url ) {
				$file_type = (string) $repo->get_raw( $media_id, 'file_type' );
				if ( 0 === strpos( $file_type, 'image/' ) ) {
					$thumb_url = (string) $repo->get_raw( $media_id, 'file_url' );
				}
			}
		}

		$upload_dir = wp_upload_dir();
		$base_url   = trailingslashit( set_url_scheme( $upload_dir['baseurl'], 'http' ) );

		// When we have a clean rel path, derive the on-disk filename from it
		// directly. This is the new preferred path — it cannot fail the
		// uploads-base containment check the way a stranded CDN URL did,
		// because we never trust an absolute URL stored in meta. The
		// realpath() containment check below still applies.
		if ( '' !== $rel_path ) {
			$full_path = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . ltrim( $rel_path, '/' );
		} else {
			if ( ! $thumb_url ) {
				status_header( 404 );
				header( 'Content-Type: text/plain' );
				echo 'Thumbnail not found.';
				exit;
			}

			$thumb_url_http = set_url_scheme( $thumb_url, 'http' );

			if ( 0 !== strpos( $thumb_url_http, $base_url ) ) {
				// Stored thumb URL points at a different host/path (staging
				// URL after a migration, retired CDN). We never serve the
				// foreign URL — leave $full_path empty so the realpath
				// missing-file fallback below degrades to the original file.
				$full_path = '';
			} else {
				$full_path = trailingslashit( $upload_dir['basedir'] ) . substr( $thumb_url_http, strlen( $base_url ) );
			}
		}

		$real_path = realpath( $full_path );
		$real_base = realpath( trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse' );

		// Variant file missing on disk (meta points at a file that was never
		// generated or was lost, or only a stale foreign-host URL is stored):
		// for image media, degrade to the original file instead of breaking
		// the grid — a full-size image beats a broken thumbnail on every
		// surface. Distinct from the containment check below, which guards
		// traversal on files that DO exist.
		if ( false === $real_path || ! is_file( $real_path ) ) {
			$repo      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
			$file_type = (string) $repo->get_raw( $media_id, 'file_type' );
			if ( 0 === strpos( $file_type, 'image/' ) ) {
				$original_path = (string) $repo->get_filesystem_path( $media_id );
				$original_real = '' !== $original_path ? realpath( $original_path ) : false;
				if ( false !== $original_real && is_file( $original_real ) ) {
					$real_path = $original_real;
					$full_path = $original_path;
				}
			}
		}

		if ( false === $real_path || false === $real_base || ! is_file( $real_path ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Thumbnail not found.' );
			exit;
		}

		if ( 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Access denied.' );
			exit;
		}

		$ext      = strtolower( pathinfo( $full_path, PATHINFO_EXTENSION ) );
		$mime_map = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);

		// Refuse to serve non-image bytes from the thumbnail endpoint —
		// otherwise a misconfigured fallback could ship a video file with
		// `Content-Type: image/jpeg` and the browser silently renders a
		// black <video poster>.
		if ( ! isset( $mime_map[ $ext ] ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain' );
			echo esc_html( 'Thumbnail not found.' );
			exit;
		}

		$mime_type = $mime_map[ $ext ];
		$filename  = sanitize_file_name( basename( $full_path ) );

		// Modern-format content negotiation for thumbnails (1.3.0+) — AVIF
		// first, then WebP, then the original. Same pattern as serve().
		$avif_path = $this->local_avif_variant_path( $media_id, $size, $mime_type, false );
		if ( '' !== $avif_path ) {
			$full_path = $avif_path;
			$mime_type = 'image/avif';
		} else {
			$webp_path = $this->local_webp_variant_path( $media_id, $size, $mime_type, false );
			if ( '' !== $webp_path ) {
				$full_path = $webp_path;
				$mime_type = 'image/webp';
			}
		}

		$this->prepare_binary_stream();

		// Public thumbnails are cacheable; private/restricted stay no-store. The
		// privacy level is resolved once in serve() and passed down. (1.7.0)
		$this->emit_cache_headers( $privacy );
		header( 'Vary: Accept' );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Disposition: inline; filename="' . $filename . '"' );
		$this->handle_range_request( $full_path );
	}

	/**
	 * Resolve the `mvs_exp` value for a signed URL.
	 *
	 * Private/restricted media gets a rolling `now + ttl` bearer window. PUBLIC
	 * media instead gets a coarse, render-STABLE far-future value so the signed
	 * URL (and therefore its browser/CDN cache key) is identical across renders.
	 * Without this every render mints a unique `mvs_exp`/`mvs_sig`, so no client
	 * can ever cache a public image and each request pays a full WP bootstrap.
	 * Public access is gated by privacy, not this clock (see validate_signature()
	 * + serve()), so a long stable expiry is safe. Opt out per-site with
	 * add_filter( 'mvs_stable_public_urls', '__return_false' ).
	 *
	 * @since 1.7.0
	 *
	 * @param int $media_id Media ID.
	 * @param int $ttl      Rolling TTL (seconds) for non-public media.
	 * @return int Unix timestamp for the mvs_exp param.
	 */
	private function resolve_expiry( int $media_id, int $ttl ): int {
		$repo      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$is_public = ( 'public' === (string) $repo->get_raw( $media_id, 'privacy' ) );

		/**
		 * Whether public media gets a render-stable (cacheable) signed URL.
		 *
		 * @since 1.7.0
		 *
		 * @param bool $stable   Default true.
		 * @param int  $media_id Media ID.
		 */
		if ( $is_public && (bool) apply_filters( 'mvs_stable_public_urls', true, $media_id ) ) {
			// Bucket to the start of the current month so the value is stable for
			// roughly a month (real caching) then rotates; + 1 year keeps it
			// comfortably in the future so it never trips the expiry branch in
			// serve(). MONTH/YEAR constants are WordPress core.
			$bucket = (int) ( time() / MONTH_IN_SECONDS ) * MONTH_IN_SECONDS;
			return $bucket + YEAR_IN_SECONDS;
		}

		return time() + $ttl;
	}

	/**
	 * Emit cache-control headers for a /serve response based on privacy.
	 *
	 * Public media is protected by the privacy gate, not the signed-URL clock,
	 * so the historical unconditional nocache_headers() was pure overhead — it
	 * forced a full WP bootstrap per image and blocked browser/CDN caching even
	 * on scroll-back or repeat visits. Public media now gets a long, cacheable
	 * max-age (paired with the render-stable URL from resolve_expiry());
	 * private/restricted media keeps the no-store bearer-token behaviour.
	 *
	 * @since 1.7.0
	 *
	 * @param string $privacy Stored privacy level for the media.
	 */
	private function emit_cache_headers( string $privacy ): void {
		if ( 'public' === $privacy ) {
			/**
			 * Cache lifetime (seconds) for public media served through /serve.
			 * Return 0 to keep public media on no-store too.
			 *
			 * @since 1.7.0
			 *
			 * @param int    $max_age Default one week.
			 * @param string $privacy Media privacy level.
			 */
			$max_age = (int) apply_filters( 'mvs_public_media_max_age', WEEK_IN_SECONDS, $privacy );
			if ( $max_age > 0 ) {
				header( 'Cache-Control: public, max-age=' . $max_age );
				header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + $max_age ) . ' GMT' );
				return;
			}
		}

		nocache_headers();
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
	 * Direct CDN URL for a public media's size-specific thumbnail, if its file
	 * lives on cloud.
	 *
	 * Display URLs are derived from where the file ACTUALLY lives, not from the
	 * active driver or any global toggle. Storage is either local or a single
	 * cloud at a time, and a media's stored `thumb_<size>` URL is the source of
	 * truth for its location (set at upload time, rewritten by
	 * CloudOps::migrate_one on a verified migration). When that stored URL is an
	 * absolute cloud URL, it is the ONLY working source — the `/serve` proxy can
	 * only stream local files and 403s a cloud-hosted thumbnail. So we serve it
	 * directly.
	 *
	 * Returns a non-empty URL only when ALL of:
	 *   - media privacy = 'public' (restricted media must stay on /serve so the
	 *     per-request access check fires)
	 *   - no active access rules
	 *   - the stored `thumb_<size>` URL is cloud-hosted (not under local uploads)
	 *
	 * Else returns '' so the caller falls through to the full-file direct path
	 * or the gated /serve proxy (which works for local files).
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     Size key: large / medium / thumbnail.
	 * @return string Cloud URL for the size-specific thumbnail or empty string.
	 */
	private function maybe_direct_cloud_thumbnail_url( int $media_id, string $size ): string {
		if ( ! $this->public_cloud_direct_allowed( $media_id ) ) {
			return '';
		}

		$size_map = array(
			'large'     => 'thumb_large',
			'medium'    => 'thumb_medium',
			'thumbnail' => 'thumb_thumb',
		);
		$meta_key = $size_map[ $size ] ?? null;
		if ( null === $meta_key ) {
			return '';
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// 1.4.0+ — the meta is a relative path (`2026/05/foo-1024.jpg`).
		// Resolve via the CURRENTLY-ACTIVE driver so switching CDN/storage
		// providers does not strand stored URLs (card 9925110293 + the
		// "service-specific CDN in meta" architectural fix). The legacy
		// `thumb_*` URL meta is consulted only as a fallback when no _path
		// is present yet (pre-migration rows). Skip non-image variants so a
		// pre-1.6.0 video row (.mp4 stored in thumb_large_path) falls through
		// to a real poster instead of emitting the video as a CDN <img src>
		// (Basecamp #9952600334).
		$rel_path = self::first_image_path(
			array(
				(string) $repo->get_raw( $media_id, $meta_key . '_path' ),
				(string) $repo->get_raw( $media_id, 'thumb_medium_path' ),
				(string) $repo->get_raw( $media_id, 'thumb_thumb_path' ),
			)
		);
		if ( '' !== $rel_path ) {
			$storage = \WPMediaVerse\Core\Plugin::container()->get( 'storage' );
			// Resolve by where the file ACTUALLY lives, not the active driver:
			// a public file still on local disk (cloud enabled but not migrated
			// yet) must not be served from a cloud URL that 404s. (BC #10029395885)
			$driver  = $storage->get_driver_for_location( $media_id );
			if ( $driver instanceof LocalDriver ) {
				// File is local. By default /serve streams it, but let operators
				// route public, ungated local-storage thumbnails to a cacheable
				// static/CDN URL (e.g. a reverse proxy in front of
				// wp-content/uploads) WITHOUT a cloud driver — the cloud filters
				// above can't help on local storage because we'd otherwise return
				// '' first. Return a non-empty URL to bypass the signed /serve
				// proxy; default '' keeps current behaviour. (1.7.0)
				return (string) apply_filters( 'mvs_public_local_thumbnail_url', '', $media_id, $size, $rel_path );
			}
			$thumb_url = (string) $driver->url( $rel_path );
			if ( '' === $thumb_url || ! $this->is_cloud_hosted_url( $thumb_url ) ) {
				return '';
			}
			/** This filter is documented below in the legacy branch. */
			return (string) apply_filters( 'mvs_public_cloud_thumbnail_url', $thumb_url, $media_id, $size );
		}

		// Legacy fallback — pre-1.4.0 rows have only the URL meta. The
		// Migrator v14 backfill writes `_path` for these, so this branch
		// shrinks toward zero as the migration completes. Image-only guard
		// (Basecamp #9952600334): skip a video URL stored in thumb_large.
		$thumb_url = self::first_image_path(
			array(
				(string) $repo->get_raw( $media_id, $meta_key ),
				(string) $repo->get_raw( $media_id, 'thumb_medium' ),
				(string) $repo->get_raw( $media_id, 'thumb_thumb' ),
			)
		);

		if ( ! $this->is_cloud_hosted_url( $thumb_url ) ) {
			// Empty, or still on local disk — let /serve stream the local file.
			return '';
		}

		/**
		 * Filter the direct public URL for a media's cloud-hosted thumbnail.
		 *
		 * Lets operators rewrite the emitted URL (e.g. a custom CDN domain) or
		 * return '' to force the request back through the gated /serve proxy.
		 *
		 * @param string $thumb_url Cloud thumbnail URL.
		 * @param int    $media_id  Media ID.
		 * @param string $size      Size key.
		 */
		return (string) apply_filters( 'mvs_public_cloud_thumbnail_url', $thumb_url, $media_id, $size );
	}

	/**
	 * Direct CDN URL for a public media's original file, if it lives on cloud.
	 *
	 * Companion to maybe_direct_cloud_thumbnail_url for full-file reads. Serves
	 * from the media's actual stored `file_url` (the source of truth for its
	 * location) when the file is cloud-hosted and the media is public + ungated.
	 *
	 * **Privacy caveat:** the direct URL is on the CDN's public domain. Anyone
	 * with the URL can keep viewing, including after the media is later flipped
	 * to private (until the bucket/object is also restricted). This is inherent
	 * to public CDN hosting and only applies to media that WAS public.
	 *
	 * @since 1.2.1
	 *
	 * @param int $media_id Media ID.
	 * @return string Direct CDN URL or empty string.
	 */
	private function maybe_direct_cloud_url( int $media_id ): string {
		if ( ! $this->public_cloud_direct_allowed( $media_id ) ) {
			return '';
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// 1.4.0+ — resolve via the CURRENTLY-ACTIVE driver against the
		// canonical `file_path` column (driver-agnostic). Switching CDNs no
		// longer requires URL meta rewrites; the active driver knows its
		// own base URL.
		$rel_path = (string) $repo->get_raw( $media_id, 'file_path' );
		if ( '' !== $rel_path ) {
			$storage = \WPMediaVerse\Core\Plugin::container()->get( 'storage' );
			// Resolve by where the file ACTUALLY lives, not the active driver, so a
			// public file still on local disk (cloud enabled but not migrated yet)
			// is served locally instead of from a 404-ing cloud URL. (BC #10029395885)
			$driver  = $storage->get_driver_for_location( $media_id );
			if ( $driver instanceof LocalDriver ) {
				// See maybe_direct_cloud_thumbnail_url(): same local-storage
				// public-URL escape hatch for the full file. Default '' keeps the
				// gated /serve path; a non-empty return routes public local media
				// to a cacheable static/CDN URL without a cloud driver. (1.7.0)
				return (string) apply_filters( 'mvs_public_local_file_url', '', $media_id, $rel_path );
			}
			$file_url = (string) $driver->url( $rel_path );
			if ( '' === $file_url || ! $this->is_cloud_hosted_url( $file_url ) ) {
				return '';
			}
			/** This filter is documented in maybe_direct_cloud_thumbnail_url(). */
			return (string) apply_filters( 'mvs_public_cloud_file_url', $file_url, $media_id, '' );
		}

		// Legacy fallback — rows missing `file_path` (extremely rare).
		$file_url = (string) $repo->get_raw( $media_id, 'file_url' );
		if ( ! $this->is_cloud_hosted_url( $file_url ) ) {
			return '';
		}
		/** This filter is documented in maybe_direct_cloud_thumbnail_url(). */
		return (string) apply_filters( 'mvs_public_cloud_file_url', $file_url, $media_id, '' );
	}

	/**
	 * Whether a media may be served directly from its cloud location.
	 *
	 * Gate shared by the thumbnail and full-file resolvers. Only PUBLIC media
	 * with no active access rules may bypass the gated /serve proxy — restricted
	 * media must flow through /serve so the per-request privacy check fires. The
	 * decision is independent of the active storage driver: it depends only on
	 * the media's privacy and (in the callers) where its file actually lives.
	 *
	 * @param int $media_id Media ID.
	 * @return bool True when direct cloud serving is permitted for this media.
	 */
	private function public_cloud_direct_allowed( int $media_id ): bool {
		/**
		 * Escape hatch: return false to force public media back through the
		 * gated /serve proxy instead of emitting a direct cloud URL. Note that
		 * /serve can only stream LOCAL files, so forcing it for cloud-only media
		 * will 404 — intended for sites that keep a local copy and want every
		 * request proxied (e.g. for access logging).
		 *
		 * @param bool $allowed  Whether direct cloud serving is permitted.
		 * @param int  $media_id Media ID.
		 */
		if ( ! (bool) apply_filters( 'mvs_serve_public_cloud_direct', true, $media_id ) ) {
			return false;
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		if ( 'public' !== (string) $repo->get_raw( $media_id, 'privacy' ) ) {
			return false;
		}

		return ! $this->access_rules->has_active_rules( $media_id );
	}

	/**
	 * Whether a stored URL is a directly-servable PUBLIC cloud location.
	 *
	 * The media's stored `file_url` / `thumb_<size>` is the source of truth for
	 * where the bytes live. We only emit it to the browser when it is genuinely
	 * publicly readable; otherwise the caller falls through to the /serve proxy
	 * (which streams the local copy). Returns false when the URL is:
	 *   - empty or not absolute http(s) (treated as local),
	 *   - under the local uploads base (the file is local — let /serve stream it),
	 *   - a known NON-public storage API host. R2's S3 API endpoint
	 *     ({account}.r2.cloudflarestorage.com) is never publicly readable; a
	 *     correctly-configured R2 stores the r2.dev / custom-domain URL instead.
	 *     Emitting the raw API host guarantees a broken image, so we decline it
	 *     and serve the local copy via /serve — never switch a media to a remote
	 *     URL that does not actually work.
	 * Scheme-insensitive so an https stored URL still matches an http uploads
	 * base (and vice versa) on dev/staging.
	 *
	 * @param string $url Stored URL.
	 * @return bool True when the URL is an absolute, publicly-servable cloud URL.
	 */
	private function is_cloud_hosted_url( string $url ): bool {
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}

		// Known non-public storage API hosts — see docblock. The R2 S3 API
		// endpoint is private by design; without a configured public domain
		// there is no working remote URL, so fall back to the local /serve copy.
		if ( preg_match( '#^https?://[^/]*\.r2\.cloudflarestorage\.com/#i', $url ) ) {
			return false;
		}

		$upload_dir = wp_upload_dir();
		if ( is_array( $upload_dir ) && ! empty( $upload_dir['baseurl'] ) ) {
			$base = (string) preg_replace( '#^https?://#i', '', (string) $upload_dir['baseurl'] );
			$bare = (string) preg_replace( '#^https?://#i', '', $url );
			if ( '' !== $base && 0 === strpos( $bare, $base ) ) {
				return false;
			}
		}

		return true;
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
		// StatsService owns this write. This method used to carry a
		// byte-identical copy of the same insert + downloads counter, so a
		// change to how a download is recorded had to be made in two files or
		// the two paths would disagree.
		//
		// Resolved lazily at call time, matching how this class already reaches
		// media_repository. StatsService holds no reference back here, so there
		// is no cycle.
		$stats = \WPMediaVerse\Core\Plugin::container()->get( 'stats' );
		if ( $stats && method_exists( $stats, 'record_download' ) ) {
			$stats->record_download( $media_id, $user_id );
		}
	}

	/**
	 * Resolve the absolute local path of the WebP sibling for the given
	 * media + size, or '' to fall through to the JPEG/PNG bytes.
	 *
	 * Returns '' when ANY of:
	 *   - the request is a download (downloads preserve the original format)
	 *   - the source is already image/webp
	 *   - the source is not an image (`image/*`)
	 *   - the client's Accept header doesn't advertise image/webp
	 *   - no `original_webp` / `thumb_<size>_webp` meta is present
	 *   - the resolved WebP URL is not under the local uploads tree
	 *     (cloud-only WebP — out of scope for H1; the gated /serve currently
	 *     requires a local file anyway, see Repository::get_filesystem_path)
	 *   - path-traversal containment fails
	 *
	 * @since 1.3.0
	 *
	 * @param int    $media_id          Media ID.
	 * @param string $size              Size key ('' / 'large' / 'medium' / 'thumbnail').
	 * @param string $current_mime_type The Content-Type the caller would have used
	 *                                  without negotiation.
	 * @param bool   $is_download       True when the request asked for download
	 *                                  semantics — never substitute in that case.
	 * @return string Absolute FS path of a readable WebP file, or '' to fall through.
	 */
	private function local_webp_variant_path( int $media_id, string $size, string $current_mime_type, bool $is_download ): string {
		if ( $is_download || $media_id <= 0 ) {
			return '';
		}
		if ( 'image/webp' === $current_mime_type ) {
			return '';
		}
		if ( 0 !== strpos( $current_mime_type, 'image/' ) ) {
			return '';
		}
		if ( ! $this->client_accepts_webp() ) {
			return '';
		}

		switch ( $size ) {
			case '':
				$meta_key = \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP;
				break;
			case 'large':
			case 'medium':
				$meta_key = 'thumb_' . $size . '_webp';
				break;
			case 'thumbnail':
				$meta_key = 'thumb_thumb_webp';
				break;
			default:
				return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! is_array( $upload_dir ) || empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Prefer the rel-path meta (driver-agnostic, 1.4.0+); fall back to URL
		// parsing for legacy rows the Migrator v14 backfill hasn't reached.
		$rel_path = (string) $repo->get_raw( $media_id, $meta_key . '_path' );
		if ( '' !== $rel_path ) {
			$candidate = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . ltrim( $rel_path, '/' );
		} else {
			$webp_url = (string) $repo->get_raw( $media_id, $meta_key );
			if ( '' === $webp_url ) {
				return '';
			}

			// Normalize both sides to a single scheme so HTTPS-vs-HTTP doesn't
			// break the prefix match.
			$base_url      = trailingslashit( set_url_scheme( $upload_dir['baseurl'], 'http' ) );
			$webp_url_http = set_url_scheme( $webp_url, 'http' );
			if ( 0 !== strpos( $webp_url_http, $base_url ) ) {
				// Cloud-stored WebP: out of scope for H1. See method docblock.
				return '';
			}

			$candidate = trailingslashit( $upload_dir['basedir'] ) . substr( $webp_url_http, strlen( $base_url ) );
		}

		$real_path = realpath( $candidate );
		$real_base = realpath( trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse' );
		if ( false === $real_path || false === $real_base ) {
			return '';
		}
		if ( 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return '';
		}
		if ( ! is_readable( $real_path ) ) {
			return '';
		}

		return $real_path;
	}

	/**
	 * Inspect the client's `Accept` header for `image/webp` advertisement.
	 *
	 * Returns true for explicit `image/webp`. Anything else (legacy
	 * `image/jpeg,image/png`, or generic `image/*` / `*\/*` which doesn't
	 * actually guarantee WebP support) returns false so we don't ship WebP
	 * bytes to a client that didn't ask for them.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	private function client_accepts_webp(): bool {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}
		$accept = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) );
		return ( false !== strpos( $accept, 'image/webp' ) );
	}

	/**
	 * Inspect the client's `Accept` header for `image/avif` advertisement.
	 *
	 * Same conservative rule as `client_accepts_webp()`: explicit advertisement
	 * only. Modern browsers send `image/avif` in the Accept list when they
	 * have decoder support; we won't ship AVIF to anything else.
	 *
	 * @since 1.3.0
	 *
	 * @return bool
	 */
	private function client_accepts_avif(): bool {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}
		$accept = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) );
		return ( false !== strpos( $accept, 'image/avif' ) );
	}

	/**
	 * AVIF analogue of `local_webp_variant_path()`. See that method's docblock
	 * for the full contract — this differs only in the Accept-header check,
	 * the source-mime short-circuit (image/avif), and the meta key map.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $media_id          Media ID.
	 * @param string $size              Size key ('' / 'large' / 'medium' / 'thumbnail').
	 * @param string $current_mime_type Content-Type the caller would have used.
	 * @param bool   $is_download       True when the request is a download.
	 * @return string Absolute FS path of a readable AVIF file, or '' to fall through.
	 */
	private function local_avif_variant_path( int $media_id, string $size, string $current_mime_type, bool $is_download ): string {
		if ( $is_download || $media_id <= 0 ) {
			return '';
		}
		if ( 'image/avif' === $current_mime_type ) {
			return '';
		}
		if ( 0 !== strpos( $current_mime_type, 'image/' ) ) {
			return '';
		}
		if ( ! $this->client_accepts_avif() ) {
			return '';
		}

		switch ( $size ) {
			case '':
				$meta_key = \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_AVIF;
				break;
			case 'large':
			case 'medium':
				$meta_key = 'thumb_' . $size . '_avif';
				break;
			case 'thumbnail':
				$meta_key = 'thumb_thumb_avif';
				break;
			default:
				return '';
		}

		$upload_dir = wp_upload_dir();
		if ( ! is_array( $upload_dir ) || empty( $upload_dir['baseurl'] ) || empty( $upload_dir['basedir'] ) ) {
			return '';
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Prefer the rel-path meta (1.4.0+); URL parse is the legacy fallback.
		$rel_path = (string) $repo->get_raw( $media_id, $meta_key . '_path' );
		if ( '' !== $rel_path ) {
			$candidate = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . ltrim( $rel_path, '/' );
		} else {
			$avif_url = (string) $repo->get_raw( $media_id, $meta_key );
			if ( '' === $avif_url ) {
				return '';
			}

			$base_url      = trailingslashit( set_url_scheme( $upload_dir['baseurl'], 'http' ) );
			$avif_url_http = set_url_scheme( $avif_url, 'http' );
			if ( 0 !== strpos( $avif_url_http, $base_url ) ) {
				return '';
			}

			$candidate = trailingslashit( $upload_dir['basedir'] ) . substr( $avif_url_http, strlen( $base_url ) );
		}

		$real_path = realpath( $candidate );
		$real_base = realpath( trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse' );
		if ( false === $real_path || false === $real_base ) {
			return '';
		}
		if ( 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return '';
		}
		if ( ! is_readable( $real_path ) ) {
			return '';
		}

		return $real_path;
	}

	/**
	 * Drain output buffers and disable transports that would corrupt a
	 * binary stream's byte count.
	 *
	 * Must run before any header() / echo of file bytes. Fixes
	 * ERR_CONTENT_LENGTH_MISMATCH for HTML5 <video> range requests when the
	 * host has output_buffering or zlib.output_compression enabled, or a
	 * plugin echoes a stray byte during shutdown.
	 */
	private function prepare_binary_stream(): void {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( function_exists( 'ini_set' ) ) {
			@ini_set( 'zlib.output_compression', '0' );
			@ini_set( 'output_buffering', '0' );
		}
		// header_remove() doesn't exist below PHP 5.3; we target 7.4+, so safe.
		header_remove( 'Content-Encoding' );
		header_remove( 'Content-Length' );
		ignore_user_abort( true );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Generic.PHP.NoSilencedErrors.Discouraged
		@set_time_limit( 0 );
	}

	/**
	 * Handle HTTP range requests for media streaming.
	 *
	 * Sets the authoritative Content-Length for both branches so callers
	 * never double-emit the header.
	 *
	 * @param string $file_path Full file path.
	 */
	private function handle_range_request( string $file_path ): void {
		// Bust PHP stat cache so filesize is fresh (relevant when the file
		// was just written by the upload pipeline / watermarker).
		clearstatcache( true, $file_path );
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
				if ( false === $fp ) {
					status_header( 500 );
					exit;
				}
				fseek( $fp, $start );
				// Stream in 8KB chunks + flush so very large ranges don't
				// blow PHP memory and so bytes hit the socket promptly.
				$remaining  = $length;
				$chunk_size = 8192;
				while ( $remaining > 0 && ! feof( $fp ) ) {
					$read = ( $remaining > $chunk_size ) ? $chunk_size : $remaining;
					// Binary file data — escaping not applicable.
					echo fread( $fp, $read ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.Security.EscapeOutput.OutputNotEscaped
					flush();
					$remaining -= $read;
				}
				fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				exit;
			}
		}

		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . $file_size );
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
