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

		// Create CPT post.
		$post_data = array(
			'post_type'   => 'mvs_media',
			'post_title'  => $title,
			'post_status' => 'publish',
			'post_author' => $user_id,
		);

		if ( ! empty( $args['description'] ) ) {
			$post_data['post_content'] = wp_kses_post( $args['description'] );
		}

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

		// Strip sensitive EXIF sections before storing in meta.
		$sensitive_sections = array( 'GPS', 'MakerNote', 'UndefinedTag:0xEA1C', 'MAKERNOTE' );
		foreach ( $sensitive_sections as $section ) {
			unset( $exif[ $section ] );
		}
		$raw = $exif;

		// Strip GPS data from file using GD — re-save the image without EXIF.
		if ( isset( $exif['GPS'] ) && extension_loaded( 'gd' ) ) {
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
