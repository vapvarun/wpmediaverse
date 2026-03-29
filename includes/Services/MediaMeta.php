<?php
/**
 * Media metadata service.
 *
 * Reads/writes media attributes from custom tables instead of wp_postmeta.
 * Core fields go to mvs_media_index (one row per media).
 * Optional/sparse fields go to mvs_media_meta (key-value).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized media metadata access.
 */
class MediaMeta {

	/**
	 * Columns that live in mvs_media_index (core, queried frequently).
	 *
	 * @var string[]
	 */
	private static array $index_columns = array(
		'title',
		'slug',
		'description',
		'post_author',
		'status',
		'media_type',
		'privacy',
		'moderation_status',
		'file_url',
		'file_path',
		'file_type',
		'file_size',
		'file_hash',
		'width',
		'height',
		'duration',
		'album_id',
		'view_count',
		'reaction_count',
		'comment_count',
		'is_featured',
		'created_at',
		'updated_at',
	);

	/**
	 * Get a single field for a media item.
	 *
	 * Checks mvs_media_index first (core fields), then mvs_media_meta (sparse fields).
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name (without _mvs_ prefix).
	 * @return mixed|null Value or null if not found.
	 */
	public static function get( int $media_id, string $key ) {
		global $wpdb;

		if ( in_array( $key, self::$index_columns, true ) ) {
			return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT `{$key}` FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$media_id
				)
			);
		}

		return $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				$key
			)
		);
	}

	/**
	 * Set a single field for a media item.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name (without _mvs_ prefix).
	 * @param mixed  $value    Value to store.
	 */
	public static function set( int $media_id, string $key, $value ): void {
		global $wpdb;

		if ( in_array( $key, self::$index_columns, true ) ) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
					$media_id
				)
			);

			if ( $exists ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'mvs_media_index',
					array( $key => $value, 'updated_at' => current_time( 'mysql', true ) ),
					array( 'media_id' => $media_id )
				);
			} else {
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'mvs_media_index',
					array(
						'media_id'   => $media_id,
						$key         => $value,
						'created_at' => current_time( 'mysql', true ),
					)
				);
			}
			return;
		}

		// Meta table: upsert.
		$existing = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_id FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				$key
			)
		);

		$serialized = is_array( $value ) || is_object( $value ) ? wp_json_encode( $value ) : $value;

		if ( $existing ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_media_meta',
				array( 'meta_value' => $serialized ),
				array( 'meta_id' => $existing )
			);
		} else {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_media_meta',
				array(
					'media_id'   => $media_id,
					'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value' => $serialized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
		}
	}

	/**
	 * Set multiple fields at once for a media item.
	 *
	 * @param int   $media_id Media ID.
	 * @param array $data     Key-value pairs.
	 */
	public static function set_many( int $media_id, array $data ): void {
		global $wpdb;

		$index_data = array();
		$meta_data  = array();

		foreach ( $data as $key => $value ) {
			if ( in_array( $key, self::$index_columns, true ) ) {
				$index_data[ $key ] = $value;
			} else {
				$meta_data[ $key ] = $value;
			}
		}

		// Bulk update index columns in one query.
		if ( ! empty( $index_data ) ) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
					$media_id
				)
			);

			$index_data['updated_at'] = current_time( 'mysql', true );

			if ( $exists ) {
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'mvs_media_index',
					$index_data,
					array( 'media_id' => $media_id )
				);
			} else {
				$index_data['media_id']   = $media_id;
				$index_data['created_at'] = current_time( 'mysql', true );
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prefix . 'mvs_media_index',
					$index_data
				);
			}
		}

		// Meta fields one by one (upsert).
		foreach ( $meta_data as $key => $value ) {
			self::set( $media_id, $key, $value );
		}
	}

	/**
	 * Delete a field for a media item.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name.
	 */
	public static function delete( int $media_id, string $key ): void {
		global $wpdb;

		if ( in_array( $key, self::$index_columns, true ) ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_media_index',
				array( $key => null ),
				array( 'media_id' => $media_id )
			);
			return;
		}

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_meta',
			array( 'media_id' => $media_id, 'meta_key' => $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			array( '%d', '%s' )
		);
	}

	/**
	 * Get all fields for a media item (index + meta merged).
	 *
	 * @param int $media_id Media ID.
	 * @return array All fields as key-value pairs.
	 */
	public static function get_all( int $media_id ): array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$media_id
			),
			ARRAY_A
		);

		$data = $row ?: array();

		$metas = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d",
				$media_id
			)
		);

		foreach ( $metas as $meta ) {
			$data[ $meta->meta_key ] = $meta->meta_value;
		}

		return $data;
	}

	/**
	 * Check if a media item exists in mvs_media_index.
	 *
	 * @param int $media_id Media ID.
	 * @return bool
	 */
	public static function exists( int $media_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$media_id
			)
		);
	}

	/**
	 * Get the author (owner) of a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return int User ID or 0 if not found.
	 */
	public static function get_author( int $media_id ): int {
		$author = self::get( $media_id, 'post_author' );
		return $author ? (int) $author : 0;
	}

	/**
	 * Build a permalink for a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return string Full URL to the media single page.
	 */
	public static function get_permalink( int $media_id ): string {
		$slug = self::get( $media_id, 'slug' );
		if ( $slug ) {
			return home_url( '/media/' . $slug . '/' );
		}
		return home_url( '/media/' . $media_id . '/' );
	}

	/**
	 * Insert a new media item and return the auto-generated media_id.
	 *
	 * @param array $data Column-value pairs for mvs_media_index.
	 * @return int|false New media_id on success, false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$defaults = array(
			'status'            => 'publish',
			'privacy'           => 'public',
			'moderation_status' => 'approved',
			'created_at'        => current_time( 'mysql', true ),
		);

		$data = array_merge( $defaults, $data );

		// Generate slug if not provided.
		if ( empty( $data['slug'] ) && ! empty( $data['title'] ) ) {
			$data['slug'] = self::generate_unique_slug( $data['title'] );
		}

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			$data
		);

		if ( false === $result ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Generate a unique slug from a title.
	 *
	 * @param string $title Media title.
	 * @return string Unique slug.
	 */
	public static function generate_unique_slug( string $title ): string {
		global $wpdb;

		$slug = sanitize_title( $title );
		if ( empty( $slug ) ) {
			$slug = 'media-' . wp_generate_password( 8, false );
		}

		$base_slug = $slug;
		$counter   = 1;

		while ( true ) {
			$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s",
					$slug
				)
			);

			if ( ! $exists ) {
				break;
			}

			$slug = $base_slug . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	/**
	 * Delete all data for a media item (both index and meta).
	 *
	 * @param int $media_id Media ID.
	 */
	public static function delete_all( int $media_id ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_media_meta', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
