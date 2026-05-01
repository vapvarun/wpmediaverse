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
use WPMediaVerse\Repository\MediaRepository;

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

		$filename  = wp_unique_filename(
			wp_upload_dir()['basedir'] . '/wpmediaverse/' . $dest_subdir,
			sanitize_file_name( $file['name'] )
		);
		$dest_path = $dest_subdir . '/' . $filename;

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
		$media_id = MediaRepository::insert(
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
			MediaRepository::set( $media_id, 'exif_raw', $exif_raw );
		}

		// Extract and store media metadata (duration, dimensions, etc.).
		$this->extract_and_store_metadata( $media_id, $driver->get_full_path( $dest_path ), $media_type, $mime );

		// Initialize stats row.
		$this->init_stats( $media_id );

		/**
		 * Fires after a media file has been uploaded and processed.
		 *
		 * @param int   $media_id  The created media post ID.
		 * @param array $file_data File metadata.
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
			)
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
						MediaRepository::set( $media_id, 'duration', $metadata['duration'] );
					}
					if ( ! empty( $video_meta['width'] ) ) {
						$metadata['width'] = (int) $video_meta['width'];
						MediaRepository::set( $media_id, 'width', $metadata['width'] );
					}
					if ( ! empty( $video_meta['height'] ) ) {
						$metadata['height'] = (int) $video_meta['height'];
						MediaRepository::set( $media_id, 'height', $metadata['height'] );
					}
					if ( ! empty( $video_meta['bitrate'] ) ) {
						$metadata['bitrate'] = (int) $video_meta['bitrate'];
						MediaRepository::set( $media_id, 'bitrate', $metadata['bitrate'] );
					}
					if ( ! empty( $video_meta['codec'] ) ) {
						$metadata['codec'] = sanitize_text_field( $video_meta['codec'] );
						MediaRepository::set( $media_id, 'codec', $metadata['codec'] );
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
						MediaRepository::set( $media_id, 'duration', $metadata['duration'] );
					}
					if ( ! empty( $audio_meta['bitrate'] ) ) {
						$metadata['bitrate'] = (int) $audio_meta['bitrate'];
						MediaRepository::set( $media_id, 'bitrate', $metadata['bitrate'] );
					}
					if ( ! empty( $audio_meta['codec'] ) ) {
						$metadata['codec'] = sanitize_text_field( $audio_meta['codec'] );
						MediaRepository::set( $media_id, 'codec', $metadata['codec'] );
					}
					// ID3 tags.
					if ( ! empty( $audio_meta['artist'] ) ) {
						$metadata['artist'] = sanitize_text_field( $audio_meta['artist'] );
						MediaRepository::set( $media_id, 'artist', $metadata['artist'] );
					}
					if ( ! empty( $audio_meta['album'] ) ) {
						$metadata['album_name'] = sanitize_text_field( $audio_meta['album'] );
						MediaRepository::set( $media_id, 'album_name', $metadata['album_name'] );
					}
				}
				break;

			case 'image':
				$size = getimagesize( $file_path );
				if ( $size ) {
					$metadata['width']  = (int) $size[0];
					$metadata['height'] = (int) $size[1];
					MediaRepository::set( $media_id, 'width', $metadata['width'] );
					MediaRepository::set( $media_id, 'height', $metadata['height'] );
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

		foreach ( $generated as $size_name => $data ) {
			MediaRepository::set( $media_id, 'thumb_' . $size_name, $base_url . '/' . $data['file'] );
		}

		// WP's multi_resize() skips sizes that would upscale the source. For
		// example a 500px image never gets a 1024px "large" variant. That
		// leaves thumb_large meta empty, forcing every consumer to walk the
		// fallback chain. Backfill any missing size with the original file
		// URL — it IS the largest available version of the image.
		// Storage-internal: must be the raw stored URL — persisting a
		// signed URL into the meta table would freeze the token.
		$file_url = MediaRepository::get_raw( $media_id, 'file_url' );
		if ( $file_url ) {
			foreach ( array_keys( $sizes ) as $size_name ) {
				if ( ! isset( $generated[ $size_name ] ) ) {
					MediaRepository::set( $media_id, 'thumb_' . $size_name, $file_url );
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
		$media_type = (string) MediaRepository::get( $media_id, 'media_type' );
		if ( 'image' !== $media_type ) {
			return false;
		}

		// Presence check — raw read, not URL emission.
		if ( MediaRepository::get_raw( $media_id, 'thumb_large' ) ) {
			return false;
		}

		// Storage-internal: backfill thumb_large with the raw stored
		// file_url so the read-side signs both consistently. Must be the
		// raw value — storing a signed URL here would persist a token in
		// the meta table and break re-signing on the next request.
		$file_url = (string) MediaRepository::get_raw( $media_id, 'file_url' );
		if ( '' === $file_url ) {
			return false;
		}

		MediaRepository::set( $media_id, 'thumb_large', $file_url );
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

		if ( empty( $video_meta['image']['data'] ) ) {
			return false;
		}

		$poster_path = $this->save_video_poster( $media_id, $video_meta['image'] );
		if ( ! $poster_path || ! file_exists( $poster_path ) ) {
			return false;
		}

		return $this->generate_thumbnails(
			$media_id,
			$poster_path,
			(string) ( $video_meta['image']['mime'] ?? 'image/jpeg' )
		);
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
