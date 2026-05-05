<?php
/**
 * Centralized media data repository.
 *
 * Reads/writes media attributes from custom tables instead of wp_postmeta.
 * Core fields go to mvs_media_index (one row per media).
 * Optional/sparse fields go to mvs_media_meta (key-value).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Centralized media data access layer.
 *
 * Resolved via the service container under the `media_repository` key.
 * Pro callers obtain the instance through `Plugin::free_service('media_repository')`.
 * Phase 1c of the 1.2.0 refactor flipped this from static utilities to an
 * instance-methods class implementing MediaRepositoryInterface so the
 * implementation can evolve (caching, instrumentation, decorator wrappers)
 * without touching call sites.
 */
class MediaRepository implements MediaRepositoryInterface {

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
	 * Map of thumbnail meta keys to SignedUrlService size names.
	 *
	 * @var array<string,string>
	 */
	private static array $thumb_size_map = array(
		'thumb_large'  => 'large',
		'thumb_medium' => 'medium',
		'thumb_thumb'  => 'thumbnail',
	);

	/**
	 * Get a single field for a media item.
	 *
	 * Checks mvs_media_index first (core fields), then mvs_media_meta (sparse fields).
	 *
	 * URL fields are special-cased to return SIGNED URLs via SignedUrlService:
	 * - `file_url` — signed full-file URL via `SignedUrlService::generate`.
	 * - `thumb_large` / `thumb_medium` / `thumb_thumb` — signed thumbnail URLs
	 *   via `SignedUrlService::generate_thumbnail` (skip-privacy at sign time;
	 *   serve endpoint re-applies access control).
	 * - `watermark_url` — signed watermark URL (size=watermark variant) for
	 *   the Pro-generated preview file. Returns empty string when no
	 *   watermark has been generated yet.
	 *
	 * Every caller (REST controllers, templates, BP integration, blocks)
	 * automatically receives token-bearing URLs that flow through the gated
	 * uploads serve endpoint. Returns empty string when the signing service
	 * is unavailable.
	 *
	 * Internal callers that need the raw stored URL (the signing service
	 * itself, the upload pipeline backfilling thumb_large, presence checks,
	 * find_by_url reverse-lookup) MUST use `get_raw()`.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name (without _mvs_ prefix).
	 * @return mixed|null Value or null if not found. For URL fields: signed URL string or empty string.
	 */
	public function get( int $media_id, string $key ) {
		if ( 'file_url' === $key ) {
			return $this->sign_file_url( $media_id );
		}

		if ( isset( self::$thumb_size_map[ $key ] ) ) {
			return $this->sign_thumbnail_url( $media_id, self::$thumb_size_map[ $key ] );
		}

		if ( 'watermark_url' === $key ) {
			return $this->sign_watermark_url( $media_id );
		}

		return $this->get_raw( $media_id, $key );
	}

	/**
	 * Get the raw stored value of a field — bypasses URL signing.
	 *
	 * INTERNAL USE ONLY. Reserved for callers that need the literal stored
	 * value: SignedUrlService (signs URLs and serves files), filesystem-path
	 * resolution, find_by_url reverse lookup. Frontend / template / block /
	 * REST callers MUST use `get()` so URLs flow through SignedUrlService.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name (without _mvs_ prefix).
	 * @return mixed|null Raw value or null if not found.
	 */
	public function get_raw( int $media_id, string $key ) {
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
	 * Reverse-lookup a media_id from a stored file_url.
	 *
	 * Used by callers that have a raw URL string but no media_id — typically
	 * activity-feed integrations parsing legacy HTML, or schema migrations
	 * backfilling FK columns. Skips URLs outside the gated uploads directory
	 * (avatars, theme images, external CDN assets).
	 *
	 * @param string $url Raw URL.
	 * @return int Media ID, or 0 when the URL doesn't match any indexed media.
	 */
	public function find_by_url( string $url ): int {
		if ( '' === $url ) {
			return 0;
		}
		// Pass-through guard: only URLs inside the gated uploads dir map to media.
		if ( false === strpos( $url, '/wp-content/uploads/wpmediaverse/' ) ) {
			return 0;
		}

		global $wpdb;
		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT media_id FROM {$index} WHERE file_url = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$url
			)
		);
		// phpcs:enable

		return (int) $id;
	}

	/**
	 * Long-lived signed URL for "broadcast" surfaces (BP activity feed,
	 * notification emails, embeds) where the URL is baked into HTML that
	 * may be read days or months after emission.
	 *
	 * Bypasses the short `mvs_signed_url_ttl` (default 1h) which would make
	 * activity feeds older than 1 hour render with broken images. Signed
	 * with `user_id = 0` (anonymous viewer) so the URL works for everyone
	 * who can see the activity — privacy is enforced at sign time
	 * (`PrivacyService::can_view`) so private media silently returns ''
	 * and the caller falls back to no image, which is correct: private
	 * media should not be broadcast in a public feed.
	 *
	 * @param int $media_id Media ID.
	 * @return string Signed URL valid for ~1 year, or empty string when the
	 *                media is non-public or signing service is unavailable.
	 */
	public function get_broadcast_url( int $media_id ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		$url = $signed->generate( $media_id, 0, YEAR_IN_SECONDS );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Long-lived signed thumbnail URL for "broadcast" surfaces.
	 *
	 * Same TTL + user_id semantics as `get_broadcast_url`. Privacy is
	 * enforced at sign time so private media never gets a broadcast URL.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     Thumbnail meta key: 'thumb_large' | 'thumb_medium' | 'thumb_thumb'.
	 *                         Or the SignedUrlService size: 'large' | 'medium' | 'thumbnail'.
	 * @return string Signed URL valid for ~1 year, or empty string.
	 */
	public function get_broadcast_thumbnail_url( int $media_id, string $size = 'thumb_large' ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		// Accept either thumb_* meta key or the SignedUrlService size name.
		$svc_size = self::$thumb_size_map[ $size ] ?? $size;
		// $skip_privacy_check = false — broadcast emission MUST verify privacy
		// at sign time so private media never gets a broadcast URL.
		$url = $signed->generate_thumbnail( $media_id, 0, $svc_size, YEAR_IN_SECONDS, false );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Resolve the absolute filesystem path for a media file.
	 *
	 * Internal API for callers that need to read the source file directly:
	 * Whisper transcription, watermark generation, EXIF strip, video
	 * transcoding, thumbnail regeneration, the signed-URL serve endpoint.
	 * Frontend / template / REST callers MUST use `get($id, 'file_url')` so
	 * URLs flow through SignedUrlService — `get_filesystem_path` returns the
	 * raw on-disk path with no signing applied.
	 *
	 * Stored `file_path` is normally relative to `{uploads}/wpmediaverse/`,
	 * but legacy / imported records may store an absolute path; both are
	 * handled. Path-traversal containment ensures the resolved path stays
	 * inside the uploads tree — returns null on out-of-tree paths or missing
	 * files. Cloud-stored media (S3, BunnyCDN) returns null because there is
	 * no local file; callers must handle that case explicitly.
	 *
	 * @param int $media_id Media ID.
	 * @return string|null Absolute filesystem path, or null when no valid file is reachable.
	 */
	public function get_filesystem_path( int $media_id ): ?string {
		if ( $media_id <= 0 ) {
			return null;
		}
		$rel = (string) $this->get_raw( $media_id, 'file_path' );
		if ( '' === $rel ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse';

		// Stored path may be absolute (legacy) or relative-to-{uploads}/wpmediaverse/.
		if ( 0 === strpos( $rel, ABSPATH ) || 0 === strpos( $rel, '/' ) ) {
			$full = $rel;
		} else {
			$full = $base_dir . '/' . ltrim( $rel, '/' );
		}

		if ( ! file_exists( $full ) ) {
			return null;
		}

		// Containment: resolved path must stay inside the uploads tree.
		$real_path = realpath( $full );
		$real_base = realpath( $base_dir );
		if ( false === $real_path || false === $real_base
			|| 0 !== strpos( $real_path, $real_base . DIRECTORY_SEPARATOR ) ) {
			return null;
		}

		return $real_path;
	}

	/**
	 * Resolve the SignedUrlService instance from the container.
	 *
	 * Returns null during early bootstrap or in unit tests without an
	 * initialized container — callers fail-quiet by returning empty string.
	 *
	 * @return \WPMediaVerse\Services\SignedUrlService|null
	 */
	private function signed_urls_service() {
		if ( ! class_exists( '\\WPMediaVerse\\Core\\Plugin' ) ) {
			return null;
		}
		$container = \WPMediaVerse\Core\Plugin::container();
		if ( ! $container->has( 'signed_urls' ) ) {
			return null;
		}
		return $container->get( 'signed_urls' );
	}

	/**
	 * Sign the file URL for a media item via SignedUrlService.
	 *
	 * Returns empty string when signing service is unavailable. Fail-quiet
	 * semantics — callers that emit the URL into HTML render empty src
	 * rather than a 403-bound raw URL.
	 *
	 * @param int $media_id Media ID.
	 * @return string Signed URL or empty string.
	 */
	private function sign_file_url( int $media_id ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		$url = $signed->generate( $media_id, get_current_user_id() );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Sign the watermark URL for a media item via SignedUrlService.
	 *
	 * Returns empty string when no watermark has been generated yet —
	 * `watermark_url` meta is the cache marker. Skip-privacy at sign time
	 * is correct because the watermark is the degraded preview shown to
	 * viewers WITHOUT access to the original; the serve endpoint validates
	 * the HMAC signature regardless.
	 *
	 * @param int $media_id Media ID.
	 * @return string Signed URL or empty string.
	 */
	private function sign_watermark_url( int $media_id ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		// Cache marker — Pro's Watermarker writes the raw URL into meta only
		// after generating the preview file. No meta = no file to serve.
		if ( ! $this->get_raw( $media_id, 'watermark_url' ) ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		$url = $signed->generate_thumbnail( $media_id, get_current_user_id(), 'watermark', 0, true );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Sign a thumbnail URL for a media item via SignedUrlService.
	 *
	 * Skip-privacy at sign time: grid queries already filter by privacy
	 * upstream and the serve endpoint re-applies access control regardless.
	 * Long-lived broadcast emissions (BP activity feed, notification emails)
	 * use `get_broadcast_thumbnail_url()` which enforces privacy at sign
	 * time — that helper is the right tool when the URL is baked into
	 * persisted HTML read days or months later.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     SignedUrlService size: 'large' | 'medium' | 'thumbnail'.
	 * @return string Signed URL or empty string.
	 */
	private function sign_thumbnail_url( int $media_id, string $size ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		$url = $signed->generate_thumbnail( $media_id, get_current_user_id(), $size, 0, true );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Set a single field for a media item.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name (without _mvs_ prefix).
	 * @param mixed  $value    Value to store.
	 */
	public function set( int $media_id, string $key, $value ): void {
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
					array(
						$key         => $value,
						'updated_at' => current_time( 'mysql', true ),
					),
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

		// Meta table: upsert via composite primary key (media_id, meta_key).
		// `$wpdb->replace()` handles the INSERT-or-UPDATE atomically without needing
		// a non-existent `meta_id` column — the table's composite primary key is the
		// natural uniqueness constraint.
		$serialized = is_array( $value ) || is_object( $value ) ? wp_json_encode( $value ) : $value;

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_meta',
			array(
				'media_id'   => $media_id,
				'meta_key'   => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $serialized, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Set multiple fields at once for a media item.
	 *
	 * @param int   $media_id Media ID.
	 * @param array $data     Key-value pairs.
	 */
	public function set_many( int $media_id, array $data ): void {
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
			$this->set( $media_id, $key, $value );
		}
	}

	/**
	 * Delete a field for a media item.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name.
	 */
	public function delete( int $media_id, string $key ): void {
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
			array(
				'media_id' => $media_id,
				'meta_key' => $key,
			), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			array( '%d', '%s' )
		);
	}

	/**
	 * Get all fields for a media item (index + meta merged).
	 *
	 * @param int $media_id Media ID.
	 * @return array All fields as key-value pairs.
	 */
	public function get_all( int $media_id ): array {
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
	public function exists( int $media_id ): bool {
		global $wpdb;

		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
				$media_id
			)
		);
	}

	/**
	 * Look up a media item by its slug.
	 *
	 * @param string $slug   Media slug.
	 * @param string $status Post status to match.
	 * @return array|null Row as associative array or null.
	 */
	public function get_by_slug( string $slug, string $status = 'publish' ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s AND status = %s LIMIT 1",
				$slug,
				$status
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Fetch multiple media items by their IDs.
	 *
	 * @param array $media_ids Array of media IDs.
	 * @return array Associative array keyed by media_id.
	 */
	public function get_batch( array $media_ids ): array {
		global $wpdb;

		if ( empty( $media_ids ) ) {
			return array();
		}

		$media_ids    = array_map( 'absint', $media_ids );
		$placeholders = implode( ',', array_fill( 0, count( $media_ids ), '%d' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$media_ids
			),
			ARRAY_A
		);

		$result = array();
		foreach ( $rows as $row ) {
			$result[ $row['media_id'] ] = $row;
		}

		return $result;
	}

	/**
	 * Find a media ID by a specific meta key-value pair.
	 *
	 * @param string $meta_key   Meta key to search.
	 * @param string $meta_value Meta value to match.
	 * @return int|null Media ID or null if not found.
	 */
	public function find_by_meta( string $meta_key, string $meta_value ): ?int {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$meta_key,
				$meta_value
			)
		);

		return $id ? (int) $id : null;
	}

	/*
	---------------------------------------------------------------
	 * Count Methods
	 * ------------------------------------------------------------- */

	/**
	 * Count all published media items.
	 *
	 * @return int
	 */
	public function count_published(): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = %s",
				'publish'
			)
		);
	}

	/**
	 * Count media items by a specific author.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Post status to match.
	 * @return int
	 */
	public function count_by_author( int $user_id, string $status = 'publish' ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d AND status = %s",
				$user_id,
				$status
			)
		);
	}

	/**
	 * Count media items by moderation status.
	 *
	 * @param string $status Moderation status.
	 * @return int
	 */
	public function count_by_moderation( string $status ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE moderation_status = %s",
				$status
			)
		);
	}

	/**
	 * Get counts grouped by moderation status.
	 *
	 * @return array Associative array of status => count.
	 */
	public function get_moderation_counts(): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT moderation_status, COUNT(*) as cnt FROM {$wpdb->prefix}mvs_media_index GROUP BY moderation_status",
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ $row['moderation_status'] ] = (int) $row['cnt'];
		}

		return $counts;
	}

	/**
	 * Count published media items belonging to a BuddyPress group.
	 *
	 * @param string $group_id Group ID stored in meta.
	 * @return int
	 */
	public function count_by_group( string $group_id ): int {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';
		$meta_table  = $wpdb->prefix . 'mvs_media_meta';

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index_table} mi
				JOIN {$meta_table} mm ON mi.media_id = mm.media_id
				WHERE mm.meta_key = 'group_id' AND mm.meta_value = %s AND mi.status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$group_id
			)
		);
	}

	/*
	---------------------------------------------------------------
	 * Stats Methods
	 * ------------------------------------------------------------- */

	/**
	 * Get aggregated statistics for a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return array|null Stats row or null.
	 */
	public function get_stats( int $media_id ): ?array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT views, downloads, reactions, comments, shares, updated_at FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$media_id
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	/**
	 * Get aggregated statistics for a user across all their published media.
	 *
	 * @param int $user_id User ID.
	 * @return array Stats with total_media, total_views, total_downloads, total_reactions, total_comments, total_shares.
	 */
	public function get_user_stats( int $user_id ): array {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';
		$stats_table = $wpdb->prefix . 'mvs_media_stats';

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_media,
					COALESCE(SUM(s.views), 0) as total_views,
					COALESCE(SUM(s.downloads), 0) as total_downloads,
					COALESCE(SUM(s.reactions), 0) as total_reactions,
					COALESCE(SUM(s.comments), 0) as total_comments,
					COALESCE(SUM(s.shares), 0) as total_shares
				FROM {$index_table} i
				LEFT JOIN {$stats_table} s ON i.media_id = s.media_id
				WHERE i.post_author = %d AND i.status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id
			),
			ARRAY_A
		);

		$defaults = array(
			'total_media'     => 0,
			'total_views'     => 0,
			'total_downloads' => 0,
			'total_reactions' => 0,
			'total_comments'  => 0,
			'total_shares'    => 0,
		);

		return $row ? array_map( 'intval', $row ) : $defaults;
	}

	/**
	 * Initialize a stats row for a media item with all zeros.
	 *
	 * @param int $media_id Media ID.
	 * @return bool True on success.
	 */
	public function init_stats( int $media_id ): bool {
		global $wpdb;

		$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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

		return false !== $result;
	}

	/**
	 * Increment a single stat column by 1.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $column   Column name (views, downloads, reactions, comments, shares).
	 * @return bool True on success.
	 */
	public function increment_stat( int $media_id, string $column ): bool {
		global $wpdb;

		$allowed = array( 'views', 'downloads', 'reactions', 'comments', 'shares' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return false;
		}

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_media_stats SET `{$column}` = `{$column}` + 1, updated_at = %s WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				current_time( 'mysql', true ),
				$media_id
			)
		);

		return false !== $result;
	}

	/**
	 * Set a single stat column to a specific value.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $column   Column name (views, downloads, reactions, comments, shares).
	 * @param int    $value    Value to set.
	 * @return bool True on success.
	 */
	public function set_stat( int $media_id, string $column, int $value ): bool {
		global $wpdb;

		$allowed = array( 'views', 'downloads', 'reactions', 'comments', 'shares' );
		if ( ! in_array( $column, $allowed, true ) ) {
			return false;
		}

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_media_stats SET `{$column}` = %d, updated_at = %s WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$value,
				current_time( 'mysql', true ),
				$media_id
			)
		);

		return false !== $result;
	}

	/**
	 * Record a view/event for analytics tracking.
	 *
	 * @param int    $media_id   Media ID.
	 * @param int    $user_id    User ID (0 for guests).
	 * @param string $ip_hash    Hashed IP address.
	 * @param string $event_type Event type (default: 'view').
	 */
	public function record_event( int $media_id, int $user_id, string $ip_hash, string $event_type = 'view' ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_views',
			array(
				'media_id'   => $media_id,
				'user_id'    => $user_id,
				'ip_hash'    => $ip_hash,
				'event_type' => $event_type,
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Prune old view/event records beyond a given age.
	 *
	 * @param int $days_old Number of days to retain (default: 90).
	 * @return int Number of deleted rows.
	 */
	public function prune_events( int $days_old = 90 ): int {
		global $wpdb;

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_media_views WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_old
			)
		);

		return (int) $deleted;
	}

	/**
	 * Get the author (owner) of a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return int User ID or 0 if not found.
	 */
	public function get_author( int $media_id ): int {
		$author = $this->get( $media_id, 'post_author' );
		return $author ? (int) $author : 0;
	}

	/**
	 * Build a permalink for a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return string Full URL to the media single page.
	 */
	public function get_permalink( int $media_id ): string {
		$slug = $this->get( $media_id, 'slug' );
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
	public function insert( array $data ) {
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
			$data['slug'] = $this->generate_unique_slug( $data['title'] );
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
	 * Pass `$exclude_media_id` when re-slugging an existing media item — the
	 * uniqueness check then ignores that media's current row, so saving the
	 * same title twice doesn't bump `unite-4-india` → `unite-4-india-1`.
	 * Without the exclude, an edit-then-save loop steadily appends suffixes
	 * to the slug on every save and the URL the user just left in their
	 * address bar 404s on the next page-load.
	 *
	 * @param string $title            Media title.
	 * @param int    $exclude_media_id Optional. media_id to exclude from the
	 *                                 collision check. Default 0 (no exclude;
	 *                                 used for inserts).
	 * @return string Unique slug.
	 */
	public function generate_unique_slug( string $title, int $exclude_media_id = 0 ): string {
		global $wpdb;

		$slug = sanitize_title( $title );
		if ( empty( $slug ) ) {
			$slug = 'media-' . wp_generate_password( 8, false );
		}

		$base_slug = $slug;
		$counter   = 1;

		while ( true ) {
			if ( $exclude_media_id > 0 ) {
				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s AND media_id != %d",
						$slug,
						$exclude_media_id
					)
				);
			} else {
				$exists = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE slug = %s",
						$slug
					)
				);
			}

			if ( ! $exists ) {
				break;
			}

			$slug = $base_slug . '-' . $counter;
			++$counter;
		}

		return $slug;
	}

	/**
	 * Delete all data for a media item (delegates to delete_cascade).
	 *
	 * @param int $media_id Media ID.
	 */
	public function delete_all( int $media_id ): void {
		$this->delete_cascade( $media_id );
	}

	/*
	---------------------------------------------------------------
	 * Lifecycle Methods
	 * ------------------------------------------------------------- */

	/**
	 * Soft-delete a media item by setting its status to 'trash'.
	 *
	 * @param int $media_id Media ID.
	 * @return bool True on success.
	 */
	public function trash( int $media_id ): bool {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'status'     => 'trash',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'media_id' => $media_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Restore a trashed media item back to 'publish' status.
	 *
	 * @param int $media_id Media ID.
	 * @return bool True on success.
	 */
	public function restore( int $media_id ): bool {
		global $wpdb;

		$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'status'     => 'publish',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'media_id' => $media_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Permanently delete a media item and all related data.
	 *
	 * Removes rows from stats, views, meta, and index tables (in that order).
	 *
	 * @param int $media_id Media ID.
	 * @return bool Always true.
	 */
	public function delete_cascade( int $media_id ): bool {
		global $wpdb;

		$where  = array( 'media_id' => $media_id );
		$format = array( '%d' );

		$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_media_views', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_media_meta', $where, $format );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_reactions', $where, $format );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_favorites', $where, $format );   // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_mentions', $where, $format );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_album_items', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_notifications', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_activity', $where, $format );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_access_rules', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $wpdb->prefix . 'mvs_access_grants', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = 'mvs_comment'", $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		return true;
	}
}
