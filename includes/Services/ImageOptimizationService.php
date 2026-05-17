<?php
/**
 * Image optimization pipeline.
 *
 * Provides:
 *   1. Lossless re-encode of originals (PNG: re-encode at level 9; JPEG:
 *      re-encode with metadata stripped at a high-quality default).
 *   2. WebP sibling emission for originals and every thumbnail variant.
 *   3. A single `mvs_optimize_image` filter dispatched at every disk-write
 *      moment, so external compression plugins (EWWW, Imagify, Smush,
 *      ShortPixel, etc.) can hook in without us shipping per-plugin adapters.
 *
 * Spec: docs/architecture/specs/2026-05-17-image-optimization-pipeline.md.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Image optimization service.
 *
 * Stateless. Settings read live via get_option() so toggling in admin takes
 * effect on the next upload without a service rebuild.
 */
class ImageOptimizationService {

	public const SETTING_OPTIMIZE_ORIGINALS = 'mvs_optimize_originals';
	public const SETTING_GENERATE_WEBP      = 'mvs_generate_webp';
	public const SETTING_GENERATE_AVIF      = 'mvs_generate_avif';

	public const META_OPTIMIZED_AT    = '_mvs_optimized_at';
	public const META_OPTIMIZE_FAILED = '_mvs_optimize_failed';
	public const META_BYTES_BEFORE    = '_mvs_bytes_before';
	public const META_BYTES_AFTER     = '_mvs_bytes_after';
	public const META_ORIGINAL_WEBP   = 'original_webp';
	public const META_ORIGINAL_AVIF   = 'original_avif';

	/**
	 * MIMEs we know how to re-encode. Other image types pass through
	 * untouched (still get the filter dispatched, still get a WebP sibling
	 * if the editor supports the source MIME).
	 */
	private const LOSSLESS_MIMES = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * MIMEs we will source from when emitting a WebP sibling.
	 */
	private const WEBP_SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * MIMEs we will source from when emitting an AVIF sibling.
	 *
	 * Same shape as WEBP_SOURCE_MIMES. Kept as a separate constant so future
	 * additions to one format don't accidentally cascade to the other.
	 */
	private const AVIF_SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/gif' );

	/**
	 * In-process cache to avoid re-checking editor WebP capability per call.
	 *
	 * @var bool|null
	 */
	private static $webp_supported_cache = null;

	/**
	 * In-process cache for editor AVIF capability.
	 *
	 * @var bool|null
	 */
	private static $avif_supported_cache = null;

	/**
	 * Run the optimization pass on a file and dispatch the public filter.
	 *
	 * The filter is dispatched FIRST so external compressors get the file in
	 * its pristine state. Our internal lossless pass then runs as a fallback
	 * for sites without an external compressor. Running both is safe — our
	 * pass is a no-op on already-optimized files.
	 *
	 * @param string $file_path Absolute path to the file on local disk.
	 * @param array  $context   { media_id, variant, mime, user_id }.
	 *
	 * @return string Final file path (same path or replacement, guaranteed to exist on disk).
	 */
	public function optimize( string $file_path, array $context ): string {
		if ( ! file_exists( $file_path ) ) {
			return $file_path;
		}

		/**
		 * Filter the file path through any registered external optimizer.
		 *
		 * Listeners may modify the file in place and return the same path,
		 * or write a replacement file and return its path. Returning a
		 * WP_Error logs the failure and keeps the original.
		 *
		 * @since 1.2.2
		 *
		 * @param string $file_path Absolute path to the file on local disk.
		 * @param array  $context   { media_id, variant, mime, user_id }.
		 */
		$filtered = apply_filters( 'mvs_optimize_image', $file_path, $context );

		if ( is_wp_error( $filtered ) ) {
			LoggerService::warning(
				'optimize',
				'External optimizer returned WP_Error; keeping original',
				array(
					'media_id' => (int) ( $context['media_id'] ?? 0 ),
					'variant'  => (string) ( $context['variant'] ?? '' ),
					'code'     => $filtered->get_error_code(),
				)
			);
		} elseif ( is_string( $filtered ) && '' !== $filtered && file_exists( $filtered ) ) {
			$file_path = $filtered;
		}

		// Built-in lossless pass — only when the toggle is on AND the MIME is
		// one we understand. Variants are already minimal so we skip them.
		$mime    = (string) ( $context['mime'] ?? '' );
		$variant = (string) ( $context['variant'] ?? 'original' );
		if (
			'original' === $variant
			&& $this->is_enabled( 'originals' )
			&& in_array( $mime, self::LOSSLESS_MIMES, true )
			&& ! self::is_animated_gif( $file_path, $mime )
		) {
			$this->run_lossless_pass( $file_path, $mime, $context );
		}

		return $file_path;
	}

	/**
	 * Detect whether a file is an animated GIF.
	 *
	 * Re-encoding an animated GIF via WP_Image_Editor would flatten it to
	 * the first frame on most builds (GD has no native multi-frame writer,
	 * Imagick can but only when iterated explicitly). Skipping the lossless
	 * pass for animated GIFs preserves the animation. Static GIFs go through
	 * normally and benefit from the metadata-strip pass.
	 *
	 * Detection scans for multiple Graphic Control Extensions (GCE blocks)
	 * which only appear in animated GIFs. Bounded read (4KB) keeps it fast
	 * for the common case where the second GCE is in the file header.
	 */
	private static function is_animated_gif( string $file_path, string $mime ): bool {
		if ( 'image/gif' !== $mime ) {
			return false;
		}
		$fh = @fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $fh ) {
			return false;
		}
		$count = 0;
		while ( ! feof( $fh ) ) {
			$chunk = fread( $fh, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk ) {
				break;
			}
			// GCE sentinel: 0x21 0xF9 0x04. Animated GIFs have >= 2 GCEs
			// (one per frame). Once we see two, we have our answer and
			// can stop reading.
			$count += substr_count( $chunk, "\x21\xF9\x04" );
			if ( $count >= 2 ) {
				break;
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $count >= 2;
	}

	/**
	 * Emit a WebP sibling next to a source image and return its absolute path.
	 *
	 * Returns null when WebP is disabled, the editor can't produce WebP, or
	 * the source MIME isn't a known input format.
	 *
	 * @param string $file_path Absolute path to source image on local disk.
	 * @param array  $context   { media_id, variant, mime, user_id }.
	 *
	 * @return string|null Absolute path to the WebP sibling, or null on no-op / failure.
	 */
	public function emit_webp_sibling( string $file_path, array $context ): ?string {
		if ( ! $this->is_enabled( 'webp' ) ) {
			return null;
		}
		if ( ! $this->is_webp_supported() ) {
			return null;
		}
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		$mime = (string) ( $context['mime'] ?? '' );
		if ( '' === $mime ) {
			$mime = (string) wp_check_filetype( $file_path )['type'];
		}
		if ( ! in_array( $mime, self::WEBP_SOURCE_MIMES, true ) ) {
			return null;
		}

		self::require_image_editor();
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$dest = self::webp_sibling_path( $file_path );
		$editor->set_quality( (int) apply_filters( 'mvs_webp_quality', 82, $context ) );
		$saved = $editor->save( $dest, 'image/webp' );
		if ( is_wp_error( $saved ) ) {
			LoggerService::warning(
				'optimize',
				'WebP emit failed',
				array(
					'media_id' => (int) ( $context['media_id'] ?? 0 ),
					'variant'  => (string) ( $context['variant'] ?? '' ),
					'error'    => $saved->get_error_message(),
				)
			);
			return null;
		}

		// Some editors return an array with a relative `path` instead of
		// honouring the absolute destination. Normalize to an absolute path
		// the caller can read.
		$absolute = is_array( $saved ) && ! empty( $saved['path'] ) ? (string) $saved['path'] : $dest;
		if ( ! file_exists( $absolute ) ) {
			return null;
		}

		// Only keep the WebP if it is smaller than the source. For some
		// inputs (already-tiny thumbnails, photos with characteristics that
		// favour JPEG's DCT) WebP encodes larger; shipping it would cost
		// disk + bandwidth (browsers prefer WebP via Accept so they'd
		// download the larger file). Discard and return null so callers
		// don't record a meta URL that hurts users.
		$source_bytes = (int) filesize( $file_path );
		$webp_bytes   = (int) filesize( $absolute );
		if ( $source_bytes > 0 && $webp_bytes >= $source_bytes ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $absolute );
			LoggerService::info(
				'optimize',
				'WebP no smaller than source; discarding',
				array(
					'media_id'     => (int) ( $context['media_id'] ?? 0 ),
					'variant'      => (string) ( $context['variant'] ?? '' ),
					'source_bytes' => $source_bytes,
					'webp_bytes'   => $webp_bytes,
				)
			);
			return null;
		}

		// Run the filter for the WebP sibling as well so external optimizers
		// can post-process the WebP if they want (rare, but supported).
		$webp_context            = $context;
		$webp_context['mime']    = 'image/webp';
		$webp_context['variant'] = (string) ( $context['variant'] ?? 'original' ) . '-webp';

		/** This filter is documented in includes/Services/ImageOptimizationService.php */
		$final = apply_filters( 'mvs_optimize_image', $absolute, $webp_context );
		if ( is_string( $final ) && '' !== $final && file_exists( $final ) ) {
			$absolute = $final;
		}

		return $absolute;
	}

	/**
	 * Emit an AVIF sibling next to a source image and return its absolute path.
	 *
	 * Same contract as `emit_webp_sibling()`: no-op + null return when the
	 * pass is disabled, the editor can't produce AVIF, the source MIME isn't
	 * one we'll re-encode, or the encoded AVIF didn't end up smaller than the
	 * source (size-compare guard, identical to the WebP path).
	 *
	 * @since 1.3.0
	 *
	 * @param string $file_path Absolute path to source image on local disk.
	 * @param array  $context   { media_id, variant, mime, user_id }.
	 *
	 * @return string|null Absolute path to the AVIF sibling, or null on no-op / failure.
	 */
	public function emit_avif_sibling( string $file_path, array $context ): ?string {
		if ( ! $this->is_enabled( 'avif' ) ) {
			return null;
		}
		if ( ! $this->is_avif_supported() ) {
			return null;
		}
		if ( ! file_exists( $file_path ) ) {
			return null;
		}

		$mime = (string) ( $context['mime'] ?? '' );
		if ( '' === $mime ) {
			$mime = (string) wp_check_filetype( $file_path )['type'];
		}
		if ( ! in_array( $mime, self::AVIF_SOURCE_MIMES, true ) ) {
			return null;
		}

		self::require_image_editor();
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return null;
		}

		$dest = self::avif_sibling_path( $file_path );

		/**
		 * Filter the AVIF encoding quality (0-100).
		 *
		 * Default 50 is a typical "looks indistinguishable from source at
		 * normal viewing distance" setting for AVIF — the codec achieves
		 * dramatic compression at quality levels well below JPEG's equivalent.
		 *
		 * @since 1.3.0
		 *
		 * @param int   $quality Quality value passed to the image editor (0-100).
		 * @param array $context Encoder context — { media_id, variant, mime, user_id }.
		 */
		$editor->set_quality( (int) apply_filters( 'mvs_avif_quality', 50, $context ) );
		$saved = $editor->save( $dest, 'image/avif' );
		if ( is_wp_error( $saved ) ) {
			LoggerService::warning(
				'optimize',
				'AVIF emit failed',
				array(
					'media_id' => (int) ( $context['media_id'] ?? 0 ),
					'variant'  => (string) ( $context['variant'] ?? '' ),
					'error'    => $saved->get_error_message(),
				)
			);
			return null;
		}

		$absolute = is_array( $saved ) && ! empty( $saved['path'] ) ? (string) $saved['path'] : $dest;
		if ( ! file_exists( $absolute ) ) {
			return null;
		}

		// Same size-compare guard as the WebP path — discard AVIF that doesn't
		// beat the source. Modern AVIF encoders almost always win for photo
		// content, but tiny pre-quantized thumbnails can encode larger.
		$source_bytes = (int) filesize( $file_path );
		$avif_bytes   = (int) filesize( $absolute );
		if ( $source_bytes > 0 && $avif_bytes >= $source_bytes ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $absolute );
			LoggerService::info(
				'optimize',
				'AVIF no smaller than source; discarding',
				array(
					'media_id'     => (int) ( $context['media_id'] ?? 0 ),
					'variant'      => (string) ( $context['variant'] ?? '' ),
					'source_bytes' => $source_bytes,
					'avif_bytes'   => $avif_bytes,
				)
			);
			return null;
		}

		// Dispatch the public filter against the AVIF sibling too so external
		// compressors can post-process it.
		$avif_context            = $context;
		$avif_context['mime']    = 'image/avif';
		$avif_context['variant'] = (string) ( $context['variant'] ?? 'original' ) . '-avif';

		/** This filter is documented in includes/Services/ImageOptimizationService.php */
		$final = apply_filters( 'mvs_optimize_image', $absolute, $avif_context );
		if ( is_string( $final ) && '' !== $final && file_exists( $final ) ) {
			$absolute = $final;
		}

		return $absolute;
	}

	/**
	 * Bulk-friendly: optimize one media row end-to-end (original + variants).
	 *
	 * Used by the CLI commands and the admin row action.
	 *
	 * @param int   $media_id Media id.
	 * @param array $opts     { include_variants?: bool, force?: bool, dry_run?: bool }.
	 *
	 * @return array Result: { media_id, bytes_before, bytes_after, saved_pct, variants_processed, errors[] }.
	 */
	public function optimize_media( int $media_id, array $opts = array() ): array {
		$include_variants = ! empty( $opts['include_variants'] );
		$force            = ! empty( $opts['force'] );
		$dry_run          = ! empty( $opts['dry_run'] );

		$result = array(
			'media_id'           => $media_id,
			'bytes_before'       => 0,
			'bytes_after'        => 0,
			'saved_pct'          => 0.0,
			'variants_processed' => 0,
			'errors'             => array(),
		);

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$mime = (string) $repo->get_raw( $media_id, 'file_type' );
		if ( '' === $mime || 0 !== strpos( $mime, 'image/' ) ) {
			$result['errors'][] = 'not_an_image';
			return $result;
		}

		if ( ! $force && (int) $repo->get_raw( $media_id, self::META_OPTIMIZED_AT ) > 0 ) {
			$result['errors'][] = 'already_optimized';
			return $result;
		}

		$abs_path = $this->resolve_local_path( $media_id );
		if ( '' === $abs_path || ! file_exists( $abs_path ) ) {
			$result['errors'][] = 'file_missing';
			$repo->set( $media_id, self::META_OPTIMIZE_FAILED, 'file_missing' );
			return $result;
		}

		$user_id              = (int) $repo->get_raw( $media_id, 'post_author' );
		$result['bytes_before'] = (int) filesize( $abs_path );

		if ( $dry_run ) {
			$result['bytes_after'] = $result['bytes_before'];
			return $result;
		}

		// Original pass.
		$context = array(
			'media_id' => $media_id,
			'variant'  => 'original',
			'mime'     => $mime,
			'user_id'  => $user_id,
		);
		$this->optimize( $abs_path, $context );
		clearstatcache( true, $abs_path );
		$result['bytes_after'] = (int) filesize( $abs_path );

		// WebP sibling for the original. When a cloud driver is active we
		// publish the WebP to cloud so it sits alongside the original on the
		// CDN and the WP server's local disk does not collect WebP copies
		// (that defeats the point of cloud storage). For local driver we
		// just keep the on-disk file and record its public URL.
		$webp_path = $this->emit_webp_sibling( $abs_path, $context );
		if ( null !== $webp_path ) {
			$rel_path     = (string) $repo->get_raw( $media_id, 'file_path' );
			$published_url = $this->publish_webp_to_active_driver( $webp_path, $rel_path );
			if ( '' !== $published_url ) {
				$repo->set( $media_id, self::META_ORIGINAL_WEBP, $published_url );
			}
		}

		// AVIF sibling for the original — same plumbing as WebP, gated on a
		// separate setting (default off) because AVIF encoding is slow.
		$avif_path = $this->emit_avif_sibling( $abs_path, $context );
		if ( null !== $avif_path ) {
			$rel_path     = (string) $repo->get_raw( $media_id, 'file_path' );
			$published_url = $this->publish_avif_to_active_driver( $avif_path, $rel_path );
			if ( '' !== $published_url ) {
				$repo->set( $media_id, self::META_ORIGINAL_AVIF, $published_url );
			}
		}

		// Variants — only when explicitly requested. Bulk runs include them;
		// new uploads do not because variants are optimized at thumbnail
		// generation time inside UploadService.
		if ( $include_variants ) {
			$file_path_rel = (string) $repo->get_raw( $media_id, 'file_path' );
			$file_path_dir = dirname( $file_path_rel );
			$uploads_root  = trailingslashit( wp_upload_dir()['basedir'] ) . 'wpmediaverse/';

			foreach ( self::variant_keys() as $size ) {
				$variant_url = (string) $repo->get_raw( $media_id, 'thumb_' . $size );
				if ( '' === $variant_url ) {
					continue;
				}

				// Resolve a local path for the variant. Two cases:
				// 1. Local driver: variant URL is a wp-uploads URL.
				// 2. Cloud driver with local copies still on disk (pre
				// cleanup-local): variant URL is a CDN URL, but the
				// physical file lives at uploads/wpmediaverse/<dir>/<basename>.
				$variant_local = self::url_to_local_path( $variant_url );
				if ( '' === $variant_local || ! file_exists( $variant_local ) ) {
					$basename      = basename( (string) wp_parse_url( $variant_url, PHP_URL_PATH ) );
					$variant_local = '' !== $basename ? $uploads_root . trailingslashit( $file_path_dir ) . $basename : '';
				}
				if ( '' === $variant_local || ! file_exists( $variant_local ) ) {
					continue;
				}

				$variant_ctx = array(
					'media_id' => $media_id,
					'variant'  => $size,
					'mime'     => $mime,
					'user_id'  => $user_id,
				);
				$this->optimize( $variant_local, $variant_ctx );

				$variant_basename = basename( (string) wp_parse_url( $variant_url, PHP_URL_PATH ) );
				$variant_rel      = ( '' === $file_path_dir || '.' === $file_path_dir ) ? $variant_basename : trailingslashit( $file_path_dir ) . $variant_basename;

				$variant_webp = $this->emit_webp_sibling( $variant_local, $variant_ctx );
				if ( null !== $variant_webp ) {
					// Cloud driver: publish next to the variant on CDN and
					// drop the local sibling so disk usage stays flat.
					// Local driver: keep on disk and record its public URL.
					$published_url    = $this->publish_webp_to_active_driver( $variant_webp, $variant_rel );
					if ( '' !== $published_url ) {
						$repo->set( $media_id, 'thumb_' . $size . '_webp', $published_url );
					}
				}

				$variant_avif = $this->emit_avif_sibling( $variant_local, $variant_ctx );
				if ( null !== $variant_avif ) {
					$published_url = $this->publish_avif_to_active_driver( $variant_avif, $variant_rel );
					if ( '' !== $published_url ) {
						$repo->set( $media_id, 'thumb_' . $size . '_avif', $published_url );
					}
				}
				++$result['variants_processed'];
			}
		}

		if ( $result['bytes_before'] > 0 ) {
			$saved              = max( 0, $result['bytes_before'] - $result['bytes_after'] );
			$result['saved_pct'] = round( $saved / $result['bytes_before'] * 100, 2 );
		}

		$repo->set( $media_id, self::META_BYTES_BEFORE, $result['bytes_before'] );
		$repo->set( $media_id, self::META_BYTES_AFTER, $result['bytes_after'] );
		$repo->set( $media_id, self::META_OPTIMIZED_AT, time() );
		// Successful run clears any prior failure flag.
		$repo->set( $media_id, self::META_OPTIMIZE_FAILED, '' );

		return $result;
	}

	/**
	 * Whether the active editor can produce image/webp.
	 *
	 * Cached for the request.
	 */
	public function is_webp_supported(): bool {
		if ( null !== self::$webp_supported_cache ) {
			return self::$webp_supported_cache;
		}
		self::require_image_editor();
		// wp_image_editor_supports() picks Imagick or GD and asks the chosen
		// implementation. Returns false if neither can write image/webp.
		self::$webp_supported_cache = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		return self::$webp_supported_cache;
	}

	/**
	 * Whether the active editor can produce image/avif.
	 *
	 * Cached for the request. Returns false on most cheap shared hosts (libavif
	 * is uncommon in pre-built Imagick/GD packages) — callers must treat AVIF
	 * as best-effort.
	 *
	 * @since 1.3.0
	 */
	public function is_avif_supported(): bool {
		if ( null !== self::$avif_supported_cache ) {
			return self::$avif_supported_cache;
		}
		self::require_image_editor();
		self::$avif_supported_cache = (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
		return self::$avif_supported_cache;
	}

	/**
	 * Whether the named pass is enabled via the corresponding option.
	 *
	 * @param string $type 'originals' | 'webp' | 'avif'.
	 */
	public function is_enabled( string $type ): bool {
		if ( 'originals' === $type ) {
			return (bool) get_option( self::SETTING_OPTIMIZE_ORIGINALS, true );
		}
		if ( 'webp' === $type ) {
			return (bool) get_option( self::SETTING_GENERATE_WEBP, true );
		}
		if ( 'avif' === $type ) {
			// Default OFF — AVIF encoding is 10x+ slower than WebP on Imagick,
			// so opting in is the right default. Sites with strong CPU and
			// bandwidth-cost concerns can flip it on in Settings.
			return (bool) get_option( self::SETTING_GENERATE_AVIF, false );
		}
		return false;
	}

	/**
	 * Run the built-in lossless re-encode pass.
	 *
	 * PNG: re-encode (drops metadata, applies WP's default compression).
	 * JPEG: re-encode at high quality with metadata stripped. The
	 *       `mvs_optimize_jpeg_quality` filter (default 92) lets sites tune
	 *       the quality / size tradeoff. Setting to 100 produces a near
	 *       lossless re-encode that still drops EXIF.
	 * GIF: re-encode (frame data preserved; metadata dropped).
	 *
	 * @param string $file_path Absolute path to source image.
	 * @param string $mime      Source MIME.
	 * @param array  $context   Filter context.
	 */
	private function run_lossless_pass( string $file_path, string $mime, array $context ): void {
		self::require_image_editor();
		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			return;
		}

		$bytes_before = (int) filesize( $file_path );

		if ( 'image/jpeg' === $mime ) {
			$editor->set_quality( (int) apply_filters( 'mvs_optimize_jpeg_quality', 92, $context ) );
		}

		// Save to a sibling temp path FIRST so we can compare sizes before
		// committing. Re-encoding sometimes inflates the file (already-low-
		// quality JPEGs, atypical PNG palettes). If the new file is not
		// smaller than the original we keep the original on disk and discard
		// the temp.
		$temp_dest = $file_path . '.mvs-opt.tmp';
		$saved     = $editor->save( $temp_dest, $mime );
		if ( is_wp_error( $saved ) ) {
			LoggerService::warning(
				'optimize',
				'Lossless pass failed',
				array(
					'media_id' => (int) ( $context['media_id'] ?? 0 ),
					'mime'     => $mime,
					'error'    => $saved->get_error_message(),
				)
			);
			if ( file_exists( $temp_dest ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				@unlink( $temp_dest );
			}
			return;
		}

		// Some editors return an array with a relative `path` instead of
		// honouring the absolute destination we passed in.
		$temp_path = is_array( $saved ) && ! empty( $saved['path'] ) ? (string) $saved['path'] : $temp_dest;
		if ( ! file_exists( $temp_path ) ) {
			return;
		}

		$bytes_after = (int) filesize( $temp_path );

		if ( $bytes_after > 0 && $bytes_after < $bytes_before ) {
			// Re-encode is smaller — commit it. Atomic rename so a partial
			// write can never leave the source in a half-state.
			if ( ! @rename( $temp_path, $file_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				// Fall back to copy+unlink if rename fails (e.g. cross-device).
				if ( copy( $temp_path, $file_path ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $temp_path );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $temp_path );
				}
			}
			clearstatcache( true, $file_path );
			return;
		}

		// Re-encode is the same size or larger — discard and keep the source.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $temp_path );
		LoggerService::info(
			'optimize',
			'Re-encode produced no gain; keeping original',
			array(
				'media_id'     => (int) ( $context['media_id'] ?? 0 ),
				'bytes_before' => $bytes_before,
				'bytes_after'  => $bytes_after,
			)
		);
	}

	/**
	 * Resolve a media row to an absolute local filesystem path. For cloud
	 * drivers, downloads to a temp file. Returns '' on failure.
	 *
	 * @param int $media_id Media id.
	 *
	 * @return string Absolute path on local disk or '' on failure.
	 */
	private function resolve_local_path( int $media_id ): string {
		$repo      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$rel_path  = (string) $repo->get_raw( $media_id, 'file_path' );
		if ( '' === $rel_path ) {
			return '';
		}

		$upload_dir = wp_upload_dir();
		$local_abs  = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . ltrim( $rel_path, '/' );
		if ( file_exists( $local_abs ) ) {
			return $local_abs;
		}

		// Cloud-driver fallback: download to local upload dir at the same
		// relative path. Mirrors the cloud-thumbs-backfill behaviour.
		$driver_slug = (string) get_option( 'mvs_storage_driver', 'local' );
		if ( 'local' === $driver_slug ) {
			return '';
		}
		$driver = apply_filters( 'mvs_storage_driver', null, $driver_slug );
		if ( ! $driver instanceof StorageDriverInterface ) {
			return '';
		}
		wp_mkdir_p( dirname( $local_abs ) );
		if ( ! method_exists( $driver, 'download' ) ) {
			return '';
		}
		$ok = (bool) $driver->download( $rel_path, $local_abs );
		return $ok && file_exists( $local_abs ) ? $local_abs : '';
	}

	/**
	 * Publish a locally-generated WebP file via the active storage driver.
	 *
	 * Pro / cloud driver philosophy: when an S3 / BunnyCDN / R2 / future
	 * driver is active, every artifact (originals, size variants, WebP
	 * variants) lives on the CDN. The WP server's disk does not accumulate
	 * the optimized copies — that defeats the point of paying for object
	 * storage and breaks `wp mvs cleanup-local`'s reclaim guarantees.
	 *
	 * For the local driver we keep the file on disk and return its public URL.
	 * For cloud drivers we upload to the sibling path (same dir as the source,
	 * `.webp` extension) and delete the local copy.
	 *
	 * @param string $local_webp_path Absolute path of the WebP just emitted by emit_webp_sibling().
	 * @param string $source_rel_path Relative path of the source file (e.g. "2026/05/foo.jpg" or
	 *                                "2026/05/foo-300x225.jpg"). Used to derive the cloud
	 *                                destination — driver prepends its own root.
	 *
	 * @return string Public URL of the published WebP, or '' on failure.
	 */
	private function publish_webp_to_active_driver( string $local_webp_path, string $source_rel_path ): string {
		if ( '' === $source_rel_path || ! file_exists( $local_webp_path ) ) {
			return '';
		}

		$driver = \WPMediaVerse\Core\Plugin::container()->get( 'storage' )->get_driver();
		if ( ! $driver instanceof StorageDriverInterface ) {
			return '';
		}

		$webp_dest = self::sibling_rel_path( $source_rel_path );
		$driver_slug = (string) get_option( 'mvs_storage_driver', 'local' );

		if ( 'local' === $driver_slug ) {
			// Local driver — emit_webp_sibling() already wrote next to the
			// source on disk, so the on-disk file IS the published artifact.
			// The driver's url() resolves to the same path.
			return (string) $driver->url( $webp_dest );
		}

		// Cloud driver — push to remote and drop the local copy.
		if ( ! $driver->store( $local_webp_path, $webp_dest ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $local_webp_path );
		return (string) $driver->url( $webp_dest );
	}

	/**
	 * Publish a locally-generated AVIF file via the active storage driver.
	 *
	 * Mirrors `publish_webp_to_active_driver()` exactly — see that method's
	 * docblock for the rationale (cloud drivers own every artifact so disk
	 * usage stays flat; local driver leaves the file on disk and returns its
	 * public URL).
	 *
	 * @since 1.3.0
	 *
	 * @param string $local_avif_path Absolute path of the AVIF just emitted by emit_avif_sibling().
	 * @param string $source_rel_path Relative path of the source file.
	 *
	 * @return string Public URL of the published AVIF, or '' on failure.
	 */
	private function publish_avif_to_active_driver( string $local_avif_path, string $source_rel_path ): string {
		if ( '' === $source_rel_path || ! file_exists( $local_avif_path ) ) {
			return '';
		}

		$driver = \WPMediaVerse\Core\Plugin::container()->get( 'storage' )->get_driver();
		if ( ! $driver instanceof StorageDriverInterface ) {
			return '';
		}

		$avif_dest   = self::avif_rel_sibling_path( $source_rel_path );
		$driver_slug = (string) get_option( 'mvs_storage_driver', 'local' );

		if ( 'local' === $driver_slug ) {
			return (string) $driver->url( $avif_dest );
		}

		if ( ! $driver->store( $local_avif_path, $avif_dest ) ) {
			return '';
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $local_avif_path );
		return (string) $driver->url( $avif_dest );
	}

	/**
	 * Derive `<dir>/<basename>.webp` from a relative source path.
	 */
	private static function sibling_rel_path( string $source_rel_path ): string {
		$dir  = dirname( $source_rel_path );
		$base = pathinfo( $source_rel_path, PATHINFO_FILENAME );
		return ( '' === $dir || '.' === $dir ) ? $base . '.webp' : trailingslashit( $dir ) . $base . '.webp';
	}

	/**
	 * Sibling WebP path for a given image — same dir, same basename, .webp ext.
	 */
	private static function webp_sibling_path( string $file_path ): string {
		$dir  = dirname( $file_path );
		$base = pathinfo( $file_path, PATHINFO_FILENAME );
		return trailingslashit( $dir ) . $base . '.webp';
	}

	/**
	 * Sibling AVIF path for a given image — same dir, same basename, .avif ext.
	 *
	 * @since 1.3.0
	 */
	private static function avif_sibling_path( string $file_path ): string {
		$dir  = dirname( $file_path );
		$base = pathinfo( $file_path, PATHINFO_FILENAME );
		return trailingslashit( $dir ) . $base . '.avif';
	}

	/**
	 * Derive `<dir>/<basename>.avif` from a relative source path.
	 *
	 * @since 1.3.0
	 */
	private static function avif_rel_sibling_path( string $source_rel_path ): string {
		$dir  = dirname( $source_rel_path );
		$base = pathinfo( $source_rel_path, PATHINFO_FILENAME );
		return ( '' === $dir || '.' === $dir ) ? $base . '.avif' : trailingslashit( $dir ) . $base . '.avif';
	}

	/**
	 * Convert a public uploads URL back to an absolute local path. Returns ''
	 * for URLs that don't live under the WP uploads dir (e.g. cloud CDN URLs).
	 */
	private static function url_to_local_path( string $url ): string {
		$upload_dir = wp_upload_dir();
		$baseurl    = trailingslashit( $upload_dir['baseurl'] );
		if ( 0 !== strpos( $url, $baseurl ) ) {
			return '';
		}
		return trailingslashit( $upload_dir['basedir'] ) . substr( $url, strlen( $baseurl ) );
	}

	/**
	 * Ensure WP image editor classes are loaded outside of admin context.
	 */
	private static function require_image_editor(): void {
		if ( function_exists( 'wp_get_image_editor' ) ) {
			return;
		}
		require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
	}

	/**
	 * Variant size keys we know about. Mirrors UploadService::get_thumbnail_sizes().
	 *
	 * @return string[]
	 */
	public static function variant_keys(): array {
		return array( 'large', 'medium', 'thumb' );
	}
}
