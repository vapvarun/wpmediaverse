<?php
/**
 * Upload service.
 *
 * Handles file validation, storage, CPT creation, and index entry.
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
	 * Constructor.
	 *
	 * @param StorageService $storage Storage service instance.
	 */
	public function __construct( StorageService $storage ) {
		$this->storage = $storage;
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
		// Validate MIME type.
		$allowed = $this->get_allowed_types();
		$mime    = $this->detect_mime( $file['tmp_name'] );

		if ( ! in_array( $mime, $allowed, true ) ) {
			return new WP_Error(
				'mvs_invalid_type',
				__( 'This file type is not allowed.', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		// Check file size using server-side measurement (not client-reported).
		$max_size    = (int) get_option( 'mvs_max_upload_size', 104857600 );
		$actual_size = filesize( $file['tmp_name'] );
		if ( false === $actual_size || $actual_size > $max_size ) {
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
				// 'warn' — continue but include notice.
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
		$filename  = wp_unique_filename(
			wp_upload_dir()['basedir'] . '/wpmediaverse/' . gmdate( 'Y/m' ),
			sanitize_file_name( $file['name'] )
		);
		$dest_path = gmdate( 'Y/m' ) . '/' . $filename;

		// Store file.
		$driver = $this->storage->get_driver();
		$stored = $driver->store( $file['tmp_name'], $dest_path );

		if ( ! $stored ) {
			return new WP_Error(
				'mvs_storage_failed',
				__( 'Failed to store the uploaded file.', 'wpmediaverse' ),
				array( 'status' => 500 )
			);
		}

		$file_url = $driver->url( $dest_path );
		$privacy  = isset( $args['privacy'] ) ? sanitize_text_field( $args['privacy'] ) : get_option( 'mvs_default_privacy', 'public' );
		$title    = ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) );

		// Determine post status.
		$status = 'publish';
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'draft', 'publish' ), true ) ) {
			$status = $args['status'];
		}

		// Create CPT post.
		$post_data = array(
			'post_type'   => 'mvs_media',
			'post_title'  => $title,
			'post_status' => $status,
			'post_author' => $user_id,
		);

		// Scheduled publishing.
		if ( ! empty( $args['publish_at'] ) && 'draft' === $status ) {
			$publish_time = strtotime( $args['publish_at'] );
			if ( $publish_time && $publish_time > time() ) {
				$post_data['post_status'] = 'future';
				$post_data['post_date']   = gmdate( 'Y-m-d H:i:s', $publish_time );
				$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $publish_time );
			}
		}

		if ( ! empty( $args['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $args['description'] );
		}

		/**
		 * Fires just before the mvs_media post is created.
		 *
		 * Used by integrations to set flags before publish hooks fire.
		 *
		 * @since 1.1.0
		 */
		do_action( 'mvs_before_media_insert' );

		$media_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $media_id ) ) {
			$driver->delete( $dest_path );
			return $media_id;
		}

		// Save post meta.
		update_post_meta( $media_id, '_mvs_file_url', $file_url );
		update_post_meta( $media_id, '_mvs_file_path', $dest_path );
		update_post_meta( $media_id, '_mvs_file_size', (int) $actual_size );
		update_post_meta( $media_id, '_mvs_file_type', $mime );
		update_post_meta( $media_id, '_mvs_file_hash', $hash );
		update_post_meta( $media_id, '_mvs_media_type', $media_type );
		update_post_meta( $media_id, '_mvs_privacy', $privacy );
		update_post_meta( $media_id, '_mvs_moderation_status', 'approved' );

		if ( ! empty( $exif_raw ) ) {
			update_post_meta( $media_id, '_mvs_exif_raw', $exif_raw );
		}

		// Extract and store media metadata (duration, dimensions, etc.).
		$this->extract_and_store_metadata( $media_id, $driver->get_full_path( $dest_path ), $media_type, $mime );

		// Insert into media index table.
		$this->insert_index( $media_id, $user_id, $media_type, $privacy );

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
	 * Get allowed MIME types from settings.
	 *
	 * @return string[]
	 */
	private function get_allowed_types(): array {
		$types = get_option( 'mvs_allowed_file_types', 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg' );
		return array_map( 'trim', explode( ',', $types ) );
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
						update_post_meta( $media_id, '_mvs_duration', $metadata['duration'] );
					}
					if ( ! empty( $video_meta['width'] ) ) {
						$metadata['width'] = (int) $video_meta['width'];
						update_post_meta( $media_id, '_mvs_width', $metadata['width'] );
					}
					if ( ! empty( $video_meta['height'] ) ) {
						$metadata['height'] = (int) $video_meta['height'];
						update_post_meta( $media_id, '_mvs_height', $metadata['height'] );
					}
					if ( ! empty( $video_meta['bitrate'] ) ) {
						$metadata['bitrate'] = (int) $video_meta['bitrate'];
						update_post_meta( $media_id, '_mvs_bitrate', $metadata['bitrate'] );
					}
					if ( ! empty( $video_meta['codec'] ) ) {
						$metadata['codec'] = sanitize_text_field( $video_meta['codec'] );
						update_post_meta( $media_id, '_mvs_codec', $metadata['codec'] );
					}
				}

				// Create WP attachment for poster/thumbnail generation.
				$this->create_wp_attachment( $media_id, $file_path, $mime );
				break;

			case 'audio':
				if ( ! function_exists( 'wp_read_audio_metadata' ) ) {
					require_once ABSPATH . 'wp-admin/includes/media.php';
				}
				$audio_meta = wp_read_audio_metadata( $file_path );
				if ( $audio_meta ) {
					if ( ! empty( $audio_meta['length'] ) ) {
						$metadata['duration'] = (float) $audio_meta['length'];
						update_post_meta( $media_id, '_mvs_duration', $metadata['duration'] );
					}
					if ( ! empty( $audio_meta['bitrate'] ) ) {
						$metadata['bitrate'] = (int) $audio_meta['bitrate'];
						update_post_meta( $media_id, '_mvs_bitrate', $metadata['bitrate'] );
					}
					if ( ! empty( $audio_meta['codec'] ) ) {
						$metadata['codec'] = sanitize_text_field( $audio_meta['codec'] );
						update_post_meta( $media_id, '_mvs_codec', $metadata['codec'] );
					}
					// ID3 tags.
					if ( ! empty( $audio_meta['artist'] ) ) {
						$metadata['artist'] = sanitize_text_field( $audio_meta['artist'] );
						update_post_meta( $media_id, '_mvs_artist', $metadata['artist'] );
					}
					if ( ! empty( $audio_meta['album'] ) ) {
						$metadata['album_name'] = sanitize_text_field( $audio_meta['album'] );
						update_post_meta( $media_id, '_mvs_album_name', $metadata['album_name'] );
					}
				}
				break;

			case 'image':
				$size = getimagesize( $file_path );
				if ( $size ) {
					$metadata['width']  = (int) $size[0];
					$metadata['height'] = (int) $size[1];
					update_post_meta( $media_id, '_mvs_width', $metadata['width'] );
					update_post_meta( $media_id, '_mvs_height', $metadata['height'] );
				}

				// Create WP attachment for thumbnail generation.
				$this->create_wp_attachment( $media_id, $file_path, $mime );
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
	 * Create a WordPress attachment for the media file.
	 *
	 * This enables WP to generate thumbnails for images and poster frames
	 * for videos (when server supports it).
	 *
	 * @since 1.1.0
	 *
	 * @param int    $media_id  Media post ID.
	 * @param string $file_path Absolute file path.
	 * @param string $mime      MIME type.
	 */
	private function create_wp_attachment( int $media_id, string $file_path, string $mime ): void {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $mime,
				'post_title'    => get_the_title( $media_id ),
				'post_status'   => 'inherit',
				'post_parent'   => $media_id,
			),
			$file_path,
			$media_id,
			true
		);

		if ( ! is_wp_error( $attachment_id ) ) {
			$attach_data = wp_generate_attachment_metadata( $attachment_id, $file_path );
			wp_update_attachment_metadata( $attachment_id, $attach_data );
			update_post_meta( $media_id, '_mvs_attachment_id', $attachment_id );
		}
	}

	/**
	 * Find existing media by file hash.
	 *
	 * @param string $hash SHA-256 hash.
	 * @return int|null Existing media ID or null.
	 */
	private function find_by_hash( string $hash ): ?int {
		global $wpdb;

		$post_id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mvs_file_hash' AND meta_value = %s LIMIT 1",
				$hash
			)
		);

		return $post_id ? (int) $post_id : null;
	}

	/**
	 * Insert a row into the media index table.
	 *
	 * @param int    $media_id   Media post ID.
	 * @param int    $user_id    Author user ID.
	 * @param string $media_type Media type.
	 * @param string $privacy    Privacy level.
	 */
	private function insert_index( int $media_id, int $user_id, string $media_type, string $privacy ): void {
		global $wpdb;

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'media_id'          => $media_id,
				'post_author'       => $user_id,
				'media_type'        => $media_type,
				'privacy'           => $privacy,
				'moderation_status' => 'approved',
				'created_at'        => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s', '%s' )
		);
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
