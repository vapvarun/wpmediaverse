<?php
/**
 * Upload service.
 *
 * Handles file validation, storage, and media record creation in custom tables.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Handles file uploads, validation, and media post creation.
 */
class UploadService {

	/**
	 * Storage service.
	 *
	 * @var StorageService
	 */
	private $storage;

	/**
	 * Existing media ID when the most recent upload tripped the "warn" duplicate action.
	 * Reset at the start of every handle() call. Consumed by the REST controller
	 * so the response can surface a duplicate notice to the client.
	 *
	 * @var int
	 */
	private $last_duplicate_warning = 0;

	/**
	 * Constructor.
	 *
	 * @param StorageService $storage Storage service instance.
	 */
	public function __construct( StorageService $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Get the existing media ID from the most recent warn-mode duplicate detection.
	 *
	 * @return int Existing media ID, or 0 when no duplicate was detected.
	 */
	public function get_last_duplicate_warning(): int {
		return $this->last_duplicate_warning;
	}

	/**
	 * Handle a file upload.
	 *
	 * @param array $file    $_FILES array element (tmp_name, name, size, type).
	 * @param int   $user_id Uploading user ID.
	 * @param array $args    Optional. title, privacy, description.
	 * @return int|WP_Error Media post ID on success.
	 */
	public function handle( array $file, int $user_id, array $args = array() ) {
		// Reset the duplicate warning flag at the start of every call.
		$this->last_duplicate_warning = 0;

		// Validate MIME type.
		$allowed = $this->get_allowed_types();
		$mime    = $this->detect_mime( $file['tmp_name'] );

		if ( ! in_array( $mime, $allowed, true ) ) {
			LoggerService::error(
				'upload',
				'Invalid file type rejected',
				array(
					'mime'    => $mime,
					'user_id' => $user_id,
				)
			);
			return new WP_Error(
				'mvs_invalid_type',
				__( 'This file type is not allowed.', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		// Check file size using server-side measurement (not client-reported).
		$max_size = (int) get_option( 'mvs_max_upload_size', 104857600 );

		/**
		 * Filters the maximum upload file size in bytes.
		 *
		 * @since 1.1.0
		 *
		 * @param int $max_size Maximum upload size in bytes.
		 * @param int $user_id  Uploading user ID.
		 */
		$max_size = (int) apply_filters( 'mvs_max_upload_size', $max_size, $user_id );

		$actual_size = filesize( $file['tmp_name'] );
		if ( false === $actual_size || $actual_size > $max_size ) {
			LoggerService::error(
				'upload',
				'File too large',
				array(
					'size'    => $actual_size,
					'max'     => $max_size,
					'user_id' => $user_id,
				)
			);
			return new WP_Error(
				'mvs_file_too_large',
				sprintf(
					/* translators: %s: max size in MB */
					__( 'File exceeds the maximum upload size of %s MB.', 'wpmediaverse' ),
					round( $max_size / 1048576 )
				),
				array( 'status' => 400 )
			);
		}

		// Compute SHA-256 hash.
		$hash = hash_file( 'sha256', $file['tmp_name'] );

		// Duplicate detection.
		$duplicate_action = get_option( 'mvs_duplicate_action', 'warn' );
		if ( 'allow' !== $duplicate_action ) {
			$existing = $this->find_by_hash( $hash );
			if ( $existing ) {
				if ( 'skip' === $duplicate_action ) {
					return new WP_Error(
						'mvs_duplicate',
						__( 'This file has already been uploaded.', 'wpmediaverse' ),
						array(
							'status'            => 409,
							'existing_media_id' => $existing,
						)
					);
				}
				// 'warn' — upload proceeds, but record the existing ID so the
				// REST response can surface a warning via $upload_service->get_last_duplicate_warning().
				$this->last_duplicate_warning = (int) $existing;
			}
		}

		// Strip EXIF GPS data from images.
		$exif_raw = array();
		if ( get_option( 'mvs_strip_exif', true ) && $this->is_image( $mime ) ) {
			$exif_raw = $this->extract_and_strip_exif( $file['tmp_name'] );
		}

		// Determine media type from MIME.
		$media_type = $this->get_media_type( $mime );

		// Block dangerous file extensions (defense-in-depth).
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

		$blocked_extensions = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'pht', 'phar', 'cgi', 'pl', 'py', 'jsp', 'asp', 'aspx', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'htaccess', 'htpasswd' );
		if ( in_array( $ext, $blocked_extensions, true ) ) {
			return new WP_Error(
				'mvs_blocked_extension',
				__( 'This file extension is not allowed.', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		// Also block double extensions (e.g. image.php.jpg).
		$name_parts = explode( '.', $file['name'] );
		if ( count( $name_parts ) > 2 ) {
			foreach ( array_slice( $name_parts, 1, -1 ) as $middle_ext ) {
				if ( in_array( strtolower( $middle_ext ), $blocked_extensions, true ) ) {
					return new WP_Error(
						'mvs_blocked_extension',
						__( 'This file extension is not allowed.', 'wpmediaverse' ),
						array( 'status' => 400 )
					);
				}
			}
		}

		/**
		 * Filters the upload arguments before processing.
		 *
		 * Pro uses this to enforce quota limits. Return a WP_Error to reject.
		 *
		 * @since 1.1.0
		 *
		 * @param array $upload_args {
		 *     @type string $mime       Detected MIME type.
		 *     @type string $media_type High-level type (image|video|audio|document).
		 *     @type int    $file_size  File size in bytes.
		 *     @type string $file_name  Original file name.
		 * }
		 * @param int $user_id Uploading user ID.
		 */
		$upload_args = apply_filters(
			'mvs_upload_args',
			array(
				'mime'       => $mime,
				'media_type' => $media_type,
				'file_size'  => $actual_size,
				'file_name'  => $file['name'],
			),
			$user_id
		);

		if ( is_wp_error( $upload_args ) ) {
			return $upload_args;
		}

		// Generate storage path.
		/**
		 * Filters the upload destination subdirectory.
		 *
		 * @since 1.1.0
		 *
		 * @param string $subdir    Subdirectory path (default: Y/m date format).
		 * @param int    $user_id   Uploading user ID.
		 * @param string $media_type Media type (image|video|audio|document).
		 */
		$dest_subdir = apply_filters( 'mvs_upload_directory', gmdate( 'Y/m' ), $user_id, $media_type );

		// Filename strategy — controlled by `mvs_filename_strategy` setting.
		// Fresh installs default to `hashed` (security + storage robustness);
		// upgrade installs default to `original_sanitized` (preserves prior
		// behaviour). Existing on-disk files are NEVER renamed.
		$filename_pick = FilenameStrategy::pick(
			(string) $file['name'],
			wp_upload_dir()['basedir'] . '/wpmediaverse/' . $dest_subdir,
			$user_id
		);
		$filename      = $filename_pick['stored'];
		$dest_path     = $dest_subdir . '/' . $filename;

		// Image optimization pass — runs on the temp file BEFORE store() so
		// cloud drivers receive the optimized bytes (no double upload). Also
		// dispatches the `mvs_optimize_image` filter so external compressors
		// (EWWW/Imagify/Smush/ShortPixel) can hook in. See spec:
		// docs/architecture/specs/2026-05-17-image-optimization-pipeline.md.
		// $hash above (line 123) intentionally stays pre-optimization — dup
		// detection should match on the user's source, not on the post-encode
		// bytes which can differ run-to-run on already-optimized inputs.
		$webp_local   = null;
		$bytes_before = (int) $actual_size;
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$image_opt   = \WPMediaVerse\Core\Plugin::container()->get( 'image_optimization' );
			$opt_context = array(
				'media_id' => 0, // not yet assigned — set after media insert.
				'variant'  => 'original',
				'mime'     => $mime,
				'user_id'  => $user_id,
			);
			$image_opt->optimize( $file['tmp_name'], $opt_context );
			clearstatcache( true, $file['tmp_name'] );
			$actual_size = (int) filesize( $file['tmp_name'] );
			$webp_local  = $image_opt->emit_webp_sibling( $file['tmp_name'], $opt_context );
		}

		// Store file.
		$driver = $this->storage->get_driver();
		$stored = $driver->store( $file['tmp_name'], $dest_path );

		if ( ! $stored ) {
			LoggerService::error(
				'upload',
				'Storage write failed',
				array(
					'path'    => $dest_path,
					'user_id' => $user_id,
				)
			);
			return new WP_Error(
				'mvs_storage_failed',
				__( 'Failed to store the uploaded file.', 'wpmediaverse' ),
				array( 'status' => 500 )
			);
		}

		// Store the WebP sibling, if optimization produced one. Same dir as
		// the original, same basename, .webp extension. Failure here is non
		// fatal — original is already stored, we just won't have a WebP URL.
		$webp_url = '';
		if ( null !== $webp_local && file_exists( $webp_local ) ) {
			$webp_dest = dirname( $dest_path ) . '/' . pathinfo( $dest_path, PATHINFO_FILENAME ) . '.webp';
			if ( $driver->store( $webp_local, $webp_dest ) ) {
				$webp_url = (string) $driver->url( $webp_dest );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			@unlink( $webp_local );
		}

		$file_url = $driver->url( $dest_path );

		// Privacy — honour the user's choice only when the admin setting allows
		// it. When mvs_allow_user_privacy is off, every upload is forced to the
		// configured default, so a client cannot bypass the admin control by
		// sending a crafted privacy field directly to the REST API.
		$allow_user_privacy = (bool) get_option( 'mvs_allow_user_privacy', true );
		$default_privacy    = (string) get_option( 'mvs_default_privacy', 'public' );
		if ( $allow_user_privacy && isset( $args['privacy'] ) ) {
			$privacy = sanitize_text_field( $args['privacy'] );
		} else {
			$privacy = $default_privacy;
		}
		// Reject unknown privacy values so a typo or hostile input cannot slip through.
		if ( ! in_array( $privacy, array( 'public', 'members', 'friends', 'private', 'group', 'custom' ), true ) ) {
			$privacy = $default_privacy;
		}

		$title = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );

		// Determine status.
		$status = 'publish';
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'draft', 'publish' ), true ) ) {
			$status = $args['status'];
		}

		// Scheduled publishing — store as 'scheduled' status with future created_at.
		$created_at = current_time( 'mysql', true );
		if ( ! empty( $args['publish_at'] ) && 'draft' === $status ) {
			$publish_time = strtotime( $args['publish_at'] );
			if ( $publish_time && $publish_time > time() ) {
				$status     = 'scheduled';
				$created_at = gmdate( 'Y-m-d H:i:s', $publish_time );
			}
		}

		$description = ! empty( $args['description'] ) ? wp_kses_post( $args['description'] ) : '';

		/**
		 * Fires just before the media record is created.
		 *
		 * @since 1.1.0
		 */
		do_action( 'mvs_before_media_insert' );

		// Insert directly into mvs_media_index — the authoritative media record.
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => $title,
				'description'       => $description,
				'post_author'       => $user_id,
				'status'            => $status,
				'media_type'        => $media_type,
				'privacy'           => $privacy,
				'moderation_status' => 'approved',
				'file_url'          => $file_url,
				'file_path'         => $dest_path,
				'file_type'         => $mime,
				'file_size'         => (int) $actual_size,
				'file_hash'         => $hash,
				'created_at'        => $created_at,
			)
		);

		if ( ! $media_id ) {
			$driver->delete( $dest_path );
			LoggerService::error( 'upload', 'Database insert failed', array( 'user_id' => $user_id ) );
			return new WP_Error(
				'mvs_insert_failed',
				__( 'Failed to create media record.', 'wpmediaverse' ),
				array( 'status' => 500 )
			);
		}

		// Store EXIF data in meta table (sparse data).
		if ( ! empty( $exif_raw ) ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'exif_raw', $exif_raw );
		}

		// Persist optimization outcome on the media row. Done here (after the
		// media insert) because the meta keys need a media_id. The original
		// optimization itself already ran on the temp file pre-store().
		if ( '' !== $webp_url ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set(
				$media_id,
				\WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP,
				$webp_url
			);
		}
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
			$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_OPTIMIZED_AT, time() );
			$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_BYTES_BEFORE, $bytes_before );
			$repo->set( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_BYTES_AFTER, (int) $actual_size );
		}

		// Preserve the user-facing filename for display + Content-Disposition
		// when the strategy hashed the on-disk name. Old uploads (pre-1.2.1
		// or strategy=original_sanitized) skip this — readers fall back to
		// file_path basename when the meta is absent.
		if ( 'hashed' === $filename_pick['strategy'] && '' !== $filename_pick['original'] ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set(
				$media_id,
				'original_filename',
				$filename_pick['original']
			);
		}

		// Ensure a local copy of the source exists at the canonical uploads
		// path before metadata extraction + thumbnail generation. Cloud
		// storage drivers (BunnyCDN, S3) push the original to the CDN via
		// $driver->store() and may not retain a local copy — without this
		// guard, generate_thumbnails() finds no source file and the row
		// ends up with no thumb_* meta, no thumb_*_webp, and no dimensions.
		// The local copy is whatever the temp file holds at this point
		// (post-optimization, post-WebP-emit), so the thumb pipeline reads
		// the optimized bytes. cleanup-local CLI removes these locals later
		// if the admin opts in.
		$upload_dir   = wp_upload_dir();
		$local_source = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/' . $dest_path;
		if ( ! file_exists( $local_source ) && file_exists( $file['tmp_name'] ) ) {
			wp_mkdir_p( dirname( $local_source ) );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
			@copy( $file['tmp_name'], $local_source );
		}

		// Extract and store media metadata (duration, dimensions, etc.).
		// Prefer the guaranteed local copy over $driver->get_full_path() —
		// the latter returns the cloud-prefixed path on non-local drivers
		// which WP_Image_Editor can't open.
		$meta_source_path = file_exists( $local_source ) ? $local_source : $driver->get_full_path( $dest_path );
		$this->extract_and_store_metadata( $media_id, $meta_source_path, $media_type, $mime );

		// Initialize stats row.
		$this->init_stats( $media_id );

		// is_first is computed once per upload — cheap COUNT, gates "first upload" badges
		// in gamification/notification adapters without forcing every listener to repeat the query.
		$is_first_upload = 1 === \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->count_by_author( $user_id );

		/**
		 * Fires after a media file has been uploaded and processed.
		 *
		 * Positional args 3 + 4 ($user_id, $media_type) were added in 1.2.3 so
		 * gamification / activity-feed / external adapters can receive the actor
		 * and media-type directly instead of looking them up. Existing listeners
		 * registered with accepted_args=1 or =2 continue to work unchanged.
		 *
		 * @since 1.1.0
		 * @since 1.2.3 Added $user_id and $media_type positional args, and
		 *              user_id/is_first/mime keys on $file_data.
		 *
		 * @param int    $media_id   The created media post ID.
		 * @param array  $file_data  File metadata. Keys: file_url, file_path,
		 *                           file_size, file_type, file_hash, media_type,
		 *                           privacy, mime, user_id, is_first.
		 * @param int    $user_id    Uploader user ID.
		 * @param string $media_type Resolved media type ('photo' | 'video' | 'audio' | 'document').
		 */
		do_action(
			'mvs_media_uploaded',
			$media_id,
			array(
				'file_url'   => $file_url,
				'file_path'  => $dest_path,
				'file_size'  => $actual_size,
				'file_type'  => $mime,
				'file_hash'  => $hash,
				'media_type' => $media_type,
				'privacy'    => $privacy,
				'mime'       => $mime,
				'user_id'    => $user_id,
				'is_first'   => $is_first_upload,
			),
			$user_id,
			$media_type
		);

		return $media_id;
	}

	/**
	 * Get allowed MIME types (public accessor for other controllers).
	 *
	 * @return string[]
	 */
	public function get_allowed_types_public(): array {
		return $this->get_allowed_types();
	}

	/**
	 * Get allowed MIME types from settings.
	 *
	 * @return string[]
	 */
	private function get_allowed_types(): array {
		// Default matches SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES. PDF
		// dropped from the default in 1.2.3 — see scope decision note there.
		$types = get_option( 'mvs_allowed_file_types', 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg' );

		/**
		 * Filters the allowed file MIME types for upload.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $types Array of allowed MIME type strings.
		 */
		return apply_filters( 'mvs_allowed_file_types', array_map( 'trim', explode( ',', $types ) ) );
	}

	/**
	 * Detect real MIME type of a file.
	 *
	 * @param string $file_path File path.
	 * @return string MIME type.
	 */
	private function detect_mime( string $file_path ): string {
		$check = wp_check_filetype_and_ext( $file_path, $file_path );
		if ( ! empty( $check['type'] ) ) {
			return $check['type'];
		}
		// Fallback to finfo.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			$mime  = finfo_file( $finfo, $file_path );
			finfo_close( $finfo );
			return $mime ? $mime : '';
		}
		return '';
	}

	/**
	 * Check if MIME type is an image.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	private function is_image( string $mime ): bool {
		return 0 === strpos( $mime, 'image/' );
	}

	/**
	 * Determine high-level media type from MIME.
	 *
	 * @param string $mime MIME type.
	 * @return string image|video|audio|document.
	 */
	private function get_media_type( string $mime ): string {
		if ( 0 === strpos( $mime, 'image/' ) ) {
			return 'image';
		}
		if ( 0 === strpos( $mime, 'video/' ) ) {
			return 'video';
		}
		if ( 0 === strpos( $mime, 'audio/' ) ) {
			return 'audio';
		}
		return 'document';
	}

	/**
	 * Extract EXIF data and strip GPS from JPEG images.
	 *
	 * @param string $file_path File path.
	 * @return array Raw EXIF data (before stripping).
	 */
	private function extract_and_strip_exif( string $file_path ): array {
		if ( ! function_exists( 'exif_read_data' ) ) {
			return array();
		}

		$exif = @exif_read_data( $file_path, 'ANY_TAG', true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $exif ) ) {
			return array();
		}

		// Check for GPS presence before stripping.
		$has_gps = isset( $exif['GPS'] );

		// Strip sensitive EXIF sections before storing in meta.
		$sensitive_sections = array( 'GPS', 'MakerNote', 'UndefinedTag:0xEA1C', 'MAKERNOTE' );
		foreach ( $sensitive_sections as $section ) {
			unset( $exif[ $section ] );
		}
		$raw = $exif;

		// Strip GPS data from file using GD — re-save the image without EXIF.
		if ( $has_gps && extension_loaded( 'gd' ) ) {
			$info = getimagesize( $file_path );
			if ( $info ) {
				switch ( $info['mime'] ) {
					case 'image/jpeg':
						$img = imagecreatefromjpeg( $file_path );
						if ( $img ) {
							imagejpeg( $img, $file_path, 95 );
							imagedestroy( $img );
						}
						break;
					case 'image/png':
						$img = imagecreatefrompng( $file_path );
						if ( $img ) {
							imagepng( $img, $file_path );
							imagedestroy( $img );
						}
						break;
				}
			}
		}

		return $raw;
	}

	/**
	 * Extract metadata from the uploaded file and store as post meta.
	 *
	 * Uses WordPress core functions: wp_read_video_metadata() for video,
	 * wp_read_audio_metadata() for audio, getimagesize() for images.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $media_id   Media post ID.
	 * @param string $file_path  Absolute file path.
	 * @param string $media_type Media type (image|video|audio|document).
	 * @param string $mime       MIME type.
	 */
	private function extract_and_store_metadata( int $media_id, string $file_path, string $media_type, string $mime ): void {
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		$metadata = array();

		switch ( $media_type ) {
			case 'video':
				if ( ! function_exists( 'wp_read_video_metadata' ) ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
				}
				$video_meta = wp_read_video_metadata( $file_path );
				if ( $video_meta ) {
					if ( ! empty( $video_meta['length'] ) ) {
						$metadata['duration'] = (float) $video_meta['length'];
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'duration', $metadata['duration'] );
					}
					if ( ! empty( $video_meta['width'] ) ) {
						$metadata['width'] = (int) $video_meta['width'];
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'width', $metadata['width'] );
					}
					if ( ! empty( $video_meta['height'] ) ) {
						$metadata['height'] = (int) $video_meta['height'];
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'height', $metadata['height'] );
					}
					if ( ! empty( $video_meta['bitrate'] ) ) {
						$metadata['bitrate'] = (int) $video_meta['bitrate'];
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'bitrate', $metadata['bitrate'] );
					}
					if ( ! empty( $video_meta['codec'] ) ) {
						$metadata['codec'] = sanitize_text_field( $video_meta['codec'] );
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'codec', $metadata['codec'] );
					}
				}

				// Extract + save the embedded poster frame (phone-shot MP4s/MOVs
				// usually embed one). Falls back silently when the video has no
				// cover atom — MediaDisplayHelper renders the play-icon placeholder.
				$this->generate_video_poster_thumbnails( $media_id, $file_path, $video_meta );
				break;

			case 'audio':
				if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
				}
				$audio_meta = wp_read_audio_metadata( $file_path );
				if ( $audio_meta ) {
					if ( ! empty( $audio_meta['length'] ) ) {
						$metadata['duration'] = (float) $audio_meta['length'];
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'duration', $metadata['duration'] );
					}
					if ( ! empty( $audio_meta['bitrate'] ) ) {
						$metadata['bitrate'] = (int) $audio_meta['bitrate'];
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'bitrate', $metadata['bitrate'] );
					}
					if ( ! empty( $audio_meta['codec'] ) ) {
						$metadata['codec'] = sanitize_text_field( $audio_meta['codec'] );
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'codec', $metadata['codec'] );
					}
					// ID3 tags.
					if ( ! empty( $audio_meta['artist'] ) ) {
						$metadata['artist'] = sanitize_text_field( $audio_meta['artist'] );
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'artist', $metadata['artist'] );
					}
					if ( ! empty( $audio_meta['album'] ) ) {
						$metadata['album_name'] = sanitize_text_field( $audio_meta['album'] );
						\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'album_name', $metadata['album_name'] );
					}

					// Embedded album art (MP3 ID3v2 APIC, OGG METADATA_BLOCK_PICTURE).
					// Feed through the same thumbnail pipeline as videos so the
					// audio card in grids/feeds gets a real cover image plus
					// WebP siblings, instead of the music-note placeholder.
					if ( ! empty( $audio_meta['image']['data'] ) ) {
						$poster_path = $this->save_video_poster( $media_id, $audio_meta['image'] );
						if ( $poster_path && file_exists( $poster_path ) ) {
							$this->generate_thumbnails(
								$media_id,
								$poster_path,
								(string) ( $audio_meta['image']['mime'] ?? 'image/jpeg' )
							);
						}
					}
				}
				break;

			case 'image':
				$size = getimagesize( $file_path );
				if ( $size ) {
					$metadata['width']  = (int) $size[0];
					$metadata['height'] = (int) $size[1];
					\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'width', $metadata['width'] );
					\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'height', $metadata['height'] );
				}

				// Generate thumbnails via image editor.
				$this->generate_thumbnails( $media_id, $file_path, $mime );
				break;
		}

		/**
		 * Filters the extracted media metadata before storage.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $metadata   Extracted metadata.
		 * @param string $file_path  Absolute file path.
		 * @param int    $media_id   Media post ID.
		 */
		$metadata = apply_filters( 'mvs_media_metadata', $metadata, $file_path, $media_id );
	}

	/**
	 * Generate thumbnail sizes for an image and store URLs in mvs_media_meta.
	 *
	 * @param int    $media_id  Media ID.
	 * @param string $file_path Absolute path to the uploaded file.
	 * @param string $mime      MIME type.
	 */
	public function generate_thumbnails( int $media_id, string $file_path, string $mime ): bool {
		// Videos/audio never produce image thumbnails here. MediaDisplayHelper
		// renders a dark play-icon placeholder for videos, and audio renders
		// as a card — no fallback meta to write.
		if ( ! str_starts_with( $mime, 'image/' ) ) {
			return false;
		}

		if ( ! file_exists( $file_path ) ) {
			LoggerService::warning(
				'upload',
				sprintf( 'Thumbnail source missing for media #%d', $media_id ),
				array(
					'media_id'  => $media_id,
					'file_path' => $file_path,
				)
			);
			return $this->ensure_fallback_thumbs( $media_id );
		}

		if ( ! function_exists( 'wp_get_image_editor' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
			require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
			require_once ABSPATH . WPINC . '/class-wp-image-editor-imagick.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$editor = wp_get_image_editor( $file_path );
		if ( is_wp_error( $editor ) ) {
			LoggerService::warning(
				'upload',
				sprintf( 'Image editor unavailable for media #%d — using file_url fallback', $media_id ),
				array(
					'media_id' => $media_id,
					'error'    => $editor->get_error_message(),
				)
			);
			return $this->ensure_fallback_thumbs( $media_id );
		}

		$sizes = self::get_thumbnail_sizes();

		/**
		 * Fires before thumbnail generation begins.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $media_id  Media ID.
		 * @param string $file_path Absolute path to the source image.
		 * @param array  $sizes     Thumbnail size definitions.
		 */
		do_action( 'mvs_before_thumbnail_generation', $media_id, $file_path, $sizes );

		$generated = $editor->multi_resize( $sizes );
		if ( empty( $generated ) ) {
			LoggerService::warning(
				'upload',
				sprintf( 'multi_resize returned empty for media #%d — using file_url fallback', $media_id ),
				array( 'media_id' => $media_id )
			);
			return $this->ensure_fallback_thumbs( $media_id );
		}

		$upload_dir = wp_upload_dir();
		$rel_dir    = str_replace( $upload_dir['basedir'] . '/', '', dirname( $file_path ) );
		$base_url   = $upload_dir['baseurl'] . '/' . $rel_dir;
		$repo       = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		// Cloud-thumbnail strategy. When the active driver is non-local AND
		// the customer hasn't overridden via the filter (e.g. to delegate
		// thumbnail resizing to a CDN URL-parameter service like BunnyCDN
		// Optimizer), upload each size variant to cloud at a sibling path
		// next to the original (`2026/05/foo-large.jpg` etc). The stored
		// thumb_<size> meta then points at the CDN URL, not the local URL,
		// so the size-aware direct-CDN read path can short-circuit /serve.
		$driver_slug   = (string) get_option( 'mvs_storage_driver', 'local' );
		$cloud_driver  = null;
		if ( 'local' !== $driver_slug ) {
			$candidate = apply_filters( 'mvs_storage_driver', null, $driver_slug );
			if ( $candidate instanceof StorageDriverInterface ) {
				$cloud_driver = $candidate;
			}
		}
		// Cloud destination dir for variant siblings. MUST come from the
		// MEDIA's stable file_path column (e.g. "2026/05") — NOT from the
		// local $file_path. Backfill flows pass a temp-dir source path
		// (`/var/folders/.../T/.../foo.jpg`) which would otherwise produce
		// nonsense cloud paths like `wpmediaverse/var/folders/.../foo-300x179.jpg`.
		// Driver itself prepends `wpmediaverse/` so we don't include it here.
		$media_rel_path = (string) $repo->get_raw( $media_id, 'file_path' );
		$cloud_rel_dir  = dirname( $media_rel_path );
		if ( '.' === $cloud_rel_dir || '' === $cloud_rel_dir ) {
			$cloud_rel_dir = '';
		}

		$image_opt = \WPMediaVerse\Core\Plugin::container()->get( 'image_optimization' );

		foreach ( $generated as $size_name => $data ) {
			$local_thumb_url  = $base_url . '/' . $data['file'];
			$local_thumb_path = trailingslashit( dirname( $file_path ) ) . $data['file'];

			// Per-variant optimization pass — dispatches mvs_optimize_image so
			// external compressors can process the thumbnail. The internal
			// lossless re-encode skips variants (they're already minimal).
			$variant_ctx = array(
				'media_id' => $media_id,
				'variant'  => $size_name,
				'mime'     => $mime,
				'user_id'  => (int) $repo->get_raw( $media_id, 'post_author' ),
			);
			$image_opt->optimize( $local_thumb_path, $variant_ctx );
			$variant_webp_local = $image_opt->emit_webp_sibling( $local_thumb_path, $variant_ctx );

			$stored_url = $local_thumb_url;

			if ( $cloud_driver ) {
				$rel_thumb_path = ( '' === $cloud_rel_dir ? '' : trailingslashit( $cloud_rel_dir ) ) . $data['file'];

				/**
				 * Filter the thumbnail URL when on a cloud driver.
				 *
				 * Returning a non-empty string SHORT-CIRCUITS the per-size
				 * cloud upload — the URL you return is stored as the thumb
				 * meta directly. Use this to delegate resizing to a CDN
				 * URL-parameter service (BunnyCDN Optimizer, Cloudflare
				 * Images, CloudFront with Lambda@Edge resize, Imgix, etc.).
				 *
				 * The default URL we'd otherwise upload to is computed from
				 * the active driver's url() against the sibling path —
				 * e.g. `https://zone.b-cdn.net/wpmediaverse/2026/05/foo-large.jpg`.
				 *
				 * Example mu-plugin for BunnyCDN Optimizer customers:
				 *
				 *     add_filter( 'mvs_cloud_thumbnail_url', function( $url, $size, $media_id ) {
				 *         $widths = array( 'large' => 1024, 'medium' => 640, 'thumb' => 240 );
				 *         // Use the original's URL with width param instead of size-suffixed file.
				 *         $orig = ( new WPMediaVerse\Repository\MediaRepository() )->get_raw( $media_id, 'file_url' );
				 *         return $orig . '?width=' . ( $widths[ $size ] ?? 1024 );
				 *     }, 10, 3 );
				 *
				 * @since 1.2.2
				 *
				 * @param string $url      Empty string by default (= upload size variant).
				 * @param string $size     Size key: large / medium / thumb.
				 * @param int    $media_id Media ID.
				 */
				$filtered = (string) apply_filters( 'mvs_cloud_thumbnail_url', '', $size_name, $media_id );

				if ( '' !== $filtered ) {
					$stored_url = $filtered;
				} elseif ( $cloud_driver->store( $local_thumb_path, $rel_thumb_path ) ) {
					$stored_url = (string) $cloud_driver->url( $rel_thumb_path );
				} else {
					LoggerService::warning(
						'upload',
						sprintf( 'Cloud thumbnail upload failed for media #%d size %s — falling back to local URL', $media_id, $size_name ),
						array(
							'media_id' => $media_id,
							'size'     => $size_name,
							'driver'   => $driver_slug,
						)
					);
				}
			}

			$repo->set( $media_id, 'thumb_' . $size_name, $stored_url );

			// WebP sibling for this variant. For cloud drivers we upload it
			// next to the variant; for local we keep the file on disk and
			// just record the public URL. Failure is non-fatal.
			if ( null !== $variant_webp_local && file_exists( $variant_webp_local ) ) {
				$webp_filename = pathinfo( $data['file'], PATHINFO_FILENAME ) . '.webp';
				if ( $cloud_driver ) {
					$webp_rel = ( '' === $cloud_rel_dir ? '' : trailingslashit( $cloud_rel_dir ) ) . $webp_filename;
					if ( $cloud_driver->store( $variant_webp_local, $webp_rel ) ) {
						$repo->set( $media_id, 'thumb_' . $size_name . '_webp', (string) $cloud_driver->url( $webp_rel ) );
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
					@unlink( $variant_webp_local );
				} else {
					$repo->set( $media_id, 'thumb_' . $size_name . '_webp', $base_url . '/' . $webp_filename );
				}
			}
		}

		// WP's multi_resize() skips sizes that would upscale the source. For
		// example a 500px image never gets a 1024px "large" variant. That
		// leaves thumb_large meta empty, forcing every consumer to walk the
		// fallback chain. Backfill any missing size with the original file
		// URL — it IS the largest available version of the image.
		// Storage-internal: must be the raw stored URL — persisting a
		// signed URL into the meta table would freeze the token.
		$file_url = $repo->get_raw( $media_id, 'file_url' );
		if ( $file_url ) {
			foreach ( array_keys( $sizes ) as $size_name ) {
				if ( ! isset( $generated[ $size_name ] ) ) {
					$repo->set( $media_id, 'thumb_' . $size_name, $file_url );
				}
			}
		}

		/**
		 * Fires after thumbnails have been generated and stored.
		 *
		 * @since 1.1.0
		 *
		 * @param int    $media_id  Media ID.
		 * @param array  $generated Array of generated thumbnail data keyed by size name.
		 * @param string $file_path Absolute path to the source image.
		 */
		do_action( 'mvs_after_thumbnail_generation', $media_id, $generated, $file_path );

		return true;
	}

	/**
	 * Thumbnail size specification — single source of truth for Free + Pro.
	 *
	 * Filterable via `mvs_thumbnail_sizes` so themes/add-ons can tune the sizes
	 * without patching core. Keys become the meta suffix (`thumb_large` etc.);
	 * values are `width`, `height`, `crop` as consumed by WP_Image_Editor::multi_resize().
	 *
	 * @since 1.1.3
	 *
	 * @return array<string,array{width:int,height:int,crop:bool}>
	 */
	public static function get_thumbnail_sizes(): array {
		$sizes = array(
			'large'  => array(
				'width'  => 1024,
				'height' => 1024,
				'crop'   => false,
			),
			'medium' => array(
				'width'  => 300,
				'height' => 300,
				'crop'   => false,
			),
			'thumb'  => array(
				'width'  => 150,
				'height' => 150,
				'crop'   => true,
			),
		);

		/**
		 * Filters the thumbnail sizes generated during upload and import.
		 *
		 * @since 1.1.3
		 *
		 * @param array<string,array{width:int,height:int,crop:bool}> $sizes Size spec.
		 */
		return (array) apply_filters( 'mvs_thumbnail_sizes', $sizes );
	}

	/**
	 * Guarantee at least one thumbnail meta row exists for a media item.
	 *
	 * Called when `multi_resize()` fails, when GD/Imagick are unavailable, or
	 * when the source file is missing. Persists `file_url` as `thumb_large` so
	 * grids, BuddyPress activity, and the lightbox always resolve to a usable
	 * URL. Image mime only — video/audio URLs must never be written here.
	 *
	 * @param int $media_id Media ID.
	 * @return bool True if a fallback was written, false otherwise.
	 */
	private function ensure_fallback_thumbs( int $media_id ): bool {
		$media_type = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_type' );
		if ( 'image' !== $media_type ) {
			return false;
		}

		// Presence check — raw read, not URL emission.
		if ( \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'thumb_large' ) ) {
			return false;
		}

		// Storage-internal: backfill thumb_large with the raw stored
		// file_url so the read-side signs both consistently. Must be the
		// raw value — storing a signed URL here would persist a token in
		// the meta table and break re-signing on the next request.
		$file_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'file_url' );
		if ( '' === $file_url ) {
			return false;
		}

		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'thumb_large', $file_url );
		return true;
	}

	/**
	 * Extract a video's embedded poster frame and run the thumbnail pipeline.
	 *
	 * Shared by the Free upload path and Pro importers so Free uploads and Pro
	 * migrations/CLI imports behave identically. Uses WP core's getID3-powered
	 * `wp_read_video_metadata()` — no ffmpeg, no shell. Videos without an
	 * embedded cover (screen recordings, some webm) fall through to the
	 * play-icon placeholder in MediaDisplayHelper.
	 *
	 * @since 1.1.3
	 *
	 * @param int        $media_id    Media ID.
	 * @param string     $file_path   Absolute path to the video file.
	 * @param array|null $video_meta  Optional pre-fetched wp_read_video_metadata() result.
	 * @return bool True when real thumbnails were generated, false otherwise.
	 */
	public function generate_video_poster_thumbnails( int $media_id, string $file_path, ?array $video_meta = null ): bool {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		if ( null === $video_meta ) {
			if ( ! function_exists( 'wp_read_video_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/media.php';
			}
			$video_meta = wp_read_video_metadata( $file_path );
			if ( ! is_array( $video_meta ) ) {
				return false;
			}
		}

		// Path A — video has an embedded cover atom (phone-shot footage
		// usually does). Save the bytes to disk and feed through the normal
		// thumbnail pipeline.
		if ( ! empty( $video_meta['image']['data'] ) ) {
			$poster_path = $this->save_video_poster( $media_id, $video_meta['image'] );
			if ( $poster_path && file_exists( $poster_path ) ) {
				return $this->generate_thumbnails(
					$media_id,
					$poster_path,
					(string) ( $video_meta['image']['mime'] ?? 'image/jpeg' )
				);
			}
		}

		// Path B — no embedded poster (synthetic test files, some screen
		// recordings, certain webm encodes). Fall back to extracting the
		// first useful frame via ffmpeg. Skipped silently when ffmpeg is not
		// available on the server; admins on hosts without it keep the
		// play-icon placeholder and can run the per-row Repair thumbs action
		// later (which Pro hooks for ffmpeg-equipped backends).
		$poster_path = $this->extract_video_poster_via_ffmpeg( $media_id, $file_path );
		if ( $poster_path && file_exists( $poster_path ) ) {
			return $this->generate_thumbnails( $media_id, $poster_path, 'image/jpeg' );
		}

		return false;
	}

	/**
	 * Extract a poster frame from a video using ffmpeg, if available.
	 *
	 * Tries the 1-second mark first (avoids the all-black opening frame
	 * common in fade-in intros). Falls back to frame 0 when the video is
	 * shorter than 1s. Writes the JPEG to the same posters/ directory used
	 * by save_video_poster so the rest of the pipeline doesn't need to
	 * special-case it.
	 *
	 * @since 1.2.2
	 *
	 * @param int    $media_id   Media id (filename anchor).
	 * @param string $video_path Absolute path to the video on local disk.
	 *
	 * @return string|null Absolute poster path or null when ffmpeg is missing / extraction failed.
	 */
	private function extract_video_poster_via_ffmpeg( int $media_id, string $video_path ): ?string {
		/**
		 * Filter the ffmpeg binary path used for video poster extraction.
		 *
		 * @since 1.2.2
		 *
		 * @param string $binary Default 'ffmpeg' (relies on PATH). Filter to provide an absolute path.
		 */
		$ffmpeg = (string) apply_filters( 'mvs_ffmpeg_binary', 'ffmpeg' );

		if ( ! function_exists( 'proc_open' ) ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/posters';
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$dest = trailingslashit( $dir ) . $media_id . '.jpg';

		// Seek to 1s, take one frame at q=2 (visually lossless JPEG out of
		// ffmpeg). -y overwrites if a poster already exists.
		if ( ! $this->run_ffmpeg_extract( $ffmpeg, $video_path, $dest, true ) ) {
			return null;
		}

		if ( ! file_exists( $dest ) || filesize( $dest ) < 200 ) {
			// Some videos are shorter than 1s — retry from frame 0.
			$this->run_ffmpeg_extract( $ffmpeg, $video_path, $dest, false );
		}

		if ( ! file_exists( $dest ) || filesize( $dest ) < 200 ) {
			LoggerService::info(
				'upload',
				sprintf( 'ffmpeg poster extraction produced no usable frame for media #%d', $media_id ),
				array( 'media_id' => $media_id )
			);
			return null;
		}

		return $dest;
	}

	/**
	 * Invoke ffmpeg via proc_open with an arg array (no shell, no injection
	 * surface). Mirrors the pattern used in Pro's TranscodeService.
	 *
	 * @param string $binary     ffmpeg binary path or name.
	 * @param string $video_path Source video path.
	 * @param string $dest       Output JPEG path.
	 * @param bool   $seek_one_second  When true, pass `-ss 1` to skip opening fades.
	 * @return bool True when the process exited 0.
	 */
	private function run_ffmpeg_extract( string $binary, string $video_path, string $dest, bool $seek_one_second ): bool {
		$args = array( $binary, '-loglevel', 'error' );
		if ( $seek_one_second ) {
			$args[] = '-ss';
			$args[] = '1';
		}
		$args[] = '-i';
		$args[] = $video_path;
		$args[] = '-vframes';
		$args[] = '1';
		$args[] = '-q:v';
		$args[] = '2';
		$args[] = '-y';
		$args[] = $dest;

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// proc_open with array $cmd bypasses shell interpretation on PHP 7.4+
		// — each argument is passed to execve() directly. No string escaping
		// concerns even if $video_path / $dest contain shell metacharacters.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.PHP.ForbiddenFunctions.Found
		$process = @proc_open( $args, $descriptors, $pipes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! is_resource( $process ) ) {
			return false;
		}
		// Close stdin; drain stdout/stderr so the child can exit.
		if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
			fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( isset( $pipes[1] ) && is_resource( $pipes[1] ) ) {
			stream_get_contents( $pipes[1] );
			fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( isset( $pipes[2] ) && is_resource( $pipes[2] ) ) {
			stream_get_contents( $pipes[2] );
			fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$status = proc_close( $process );
		return 0 === $status;
	}

	/**
	 * Persist a video's embedded poster frame bytes to disk.
	 *
	 * Writes to `uploads/wpmediaverse/posters/<media_id>.<ext>` using WP's
	 * filesystem helpers. Returns the absolute path for the caller to feed
	 * back into `generate_thumbnails()`.
	 *
	 * @param int   $media_id Media ID.
	 * @param array $image    The `image` array from wp_read_video_metadata().
	 * @return string|null Absolute file path on success, null on failure.
	 */
	private function save_video_poster( int $media_id, array $image ): ?string {
		if ( empty( $image['data'] ) || ! is_string( $image['data'] ) ) {
			return null;
		}

		$mime = isset( $image['mime'] ) ? (string) $image['mime'] : 'image/jpeg';
		$ext  = 'image/png' === $mime ? 'png' : ( 'image/webp' === $mime ? 'webp' : 'jpg' );

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return null;
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/posters';
		if ( ! wp_mkdir_p( $dir ) ) {
			LoggerService::warning(
				'upload',
				sprintf( 'Could not create posters directory for media #%d', $media_id ),
				array(
					'media_id' => $media_id,
					'dir'      => $dir,
				)
			);
			return null;
		}

		$path  = trailingslashit( $dir ) . $media_id . '.' . $ext;
		$bytes = file_put_contents( $path, $image['data'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		if ( false === $bytes || 0 === $bytes ) {
			LoggerService::warning(
				'upload',
				sprintf( 'Failed to write video poster for media #%d', $media_id ),
				array( 'media_id' => $media_id )
			);
			return null;
		}

		return $path;
	}

	/**
	 * Find existing media by file hash.
	 *
	 * @param string $hash SHA-256 hash.
	 * @return int|null Existing media ID or null.
	 */
	private function find_by_hash( string $hash ): ?int {
		global $wpdb;

		$media_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE file_hash = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$hash
			)
		);

		return $media_id ? (int) $media_id : null;
	}

	/**
	 * Initialize a stats row for a media item.
	 *
	 * @param int $media_id Media post ID.
	 */
	private function init_stats( int $media_id ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_stats',
			array(
				'media_id'   => $media_id,
				'views'      => 0,
				'downloads'  => 0,
				'reactions'  => 0,
				'comments'   => 0,
				'shares'     => 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%d', '%d', '%d', '%d', '%s' )
		);
	}
}
