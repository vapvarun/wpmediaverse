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

use WPMediaVerse\Core\MediaTypes;

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
		// Added by Migrator v27 for the document library. It MUST be listed here:
		// without it `set( $id, 'folder_id', … )` writes to mvs_media_meta
		// instead of the column, so the column stays 0, `KEY doc_listing`
		// matches nothing, and every drive listing and privacy cascade silently
		// finds no documents. Caught by the P3.8 cascade tests.
		'folder_id',
		// Added by Migrator v29 for Space drives (Phase 11 G1), and listed here
		// for exactly the reason the comment above gives: without it,
		// `set( $id, 'drive_id', … )` would write to mvs_media_meta instead of
		// the column, `KEY drive_listing` would match nothing, and every
		// drive-scoped listing would silently come back empty.
		'drive_type',
		'drive_id',
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
	 * Per-request value cache — `[media_id => [key => value]]`.
	 *
	 * Populated lazily by `get_raw()` and eagerly by `prefetch()`. Cleared
	 * for a given media_id whenever `set()` / `set_many()` / `delete()`
	 * mutates that row. NOT a cross-request cache — request lifetime only,
	 * so process restarts and CLI invocations always read fresh.
	 *
	 * Why this matters: render paths (BP activity, lightbox, dashboard
	 * cards) call `get($id, 'title')`, then `get($id, 'file_url')`, then
	 * `get($id, 'privacy')` — three round-trips per media. At 20 activities
	 * × 6 media each that's 360 queries. With this cache, each media is
	 * one query for the index columns + one for any meta lookups, regardless
	 * of how many times it's read in the same request.
	 *
	 * @since 1.2.1
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private static array $row_cache = array();

	/**
	 * Tracks media_ids that have had ALL their meta loaded via prefetch.
	 * Without this, a meta-miss in `$row_cache` could mean either "not
	 * loaded yet" or "loaded and confirmed absent." Indexed columns are
	 * fully covered by the row insert in `prefetch_index()`, so the gap
	 * is meta-only.
	 *
	 * @since 1.2.1
	 *
	 * @var array<int, true>
	 */
	private static array $meta_fully_loaded = array();

	/**
	 * Clear the in-process row/meta caches.
	 *
	 * WordPress's normal request lifecycle never needs this — one PHP process
	 * serves one request, so a stale row is never possible. PHPUnit is
	 * different: `WP_UnitTestCase` runs every test in a class in ONE process,
	 * and several test classes `TRUNCATE TABLE mvs_media_index` in their own
	 * `tear_down()` to reset state between tests. `TRUNCATE` resets
	 * `AUTO_INCREMENT`, so a later test can legitimately get the SAME
	 * `media_id` an earlier test used and already deleted — but `$row_cache`
	 * and `$meta_fully_loaded` are keyed by that id and were never told the
	 * row is gone, so the later test silently reads the EARLIER test's
	 * cached data (wrong author, wrong media_type, etc.) instead of its own.
	 *
	 * Found 2026-08-11 chasing `ChallengeServiceTest` failures that looked
	 * like real validation bugs (a valid submission refused as
	 * `mvs_challenge_invalid_media`) but reproduced as correct in every
	 * standalone repro — only failing inside the full test class, after an
	 * earlier test (`test_submit_entry_with_others_media_returns_error`)
	 * had already populated the cache for a media_id a later test's insert
	 * went on to reuse. See plan/2026-08-11-pro-competitions-test-triage-plan.md.
	 *
	 * Call from a test class's `tear_down()`, after truncating
	 * `mvs_media_index`, not before — order doesn't matter to the cache
	 * itself, but calling it after keeps the two "reset everything" steps
	 * adjacent and easy to read as one unit.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function reset_test_cache(): void {
		self::$row_cache         = array();
		self::$meta_fully_loaded = array();
	}

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
		// Tier 1: per-request cache hit.
		if ( isset( self::$row_cache[ $media_id ] ) && array_key_exists( $key, self::$row_cache[ $media_id ] ) ) {
			return self::$row_cache[ $media_id ][ $key ];
		}

		// Tier 1b: prefetch()/get_all() loaded ALL meta for this media, so a
		// meta key absent from the cache is confirmed-absent — return null with
		// no query. Without this, absent-key reads (e.g. has_resolvable_thumbnail
		// probing thumb_*_path sizes that were never generated) re-query once per
		// key per tile and defeat the prefetch. Index columns are excluded: they
		// are fully covered by the cached index row, so a missing index key means
		// the row genuinely isn't cached yet and must fall through to the query.
		if ( isset( self::$meta_fully_loaded[ $media_id ] ) && ! in_array( $key, self::$index_columns, true ) ) {
			return null;
		}

		global $wpdb;

		if ( in_array( $key, self::$index_columns, true ) ) {
			// Index miss for this media → load the whole row at once. Reads
			// of subsequent index columns become free.
			$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$media_id
				),
				ARRAY_A
			);
			if ( ! is_array( $row ) ) {
				// Don't cache the absent state — a subsequent insert under
				// the same media_id (rare but valid) would see stale.
				return null;
			}
			if ( ! isset( self::$row_cache[ $media_id ] ) ) {
				self::$row_cache[ $media_id ] = array();
			}
			foreach ( $row as $col => $val ) {
				self::$row_cache[ $media_id ][ $col ] = $val;
			}
			return self::$row_cache[ $media_id ][ $key ] ?? null;
		}

		// Meta miss — single key fetch. Caching the result skips repeat
		// fetches in the same request without firing a wasted query for
		// keys that genuinely don't exist.
		$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				$key
			)
		);
		if ( ! isset( self::$row_cache[ $media_id ] ) ) {
			self::$row_cache[ $media_id ] = array();
		}
		self::$row_cache[ $media_id ][ $key ] = $value;
		return $value;
	}

	/**
	 * Eagerly load index rows + ALL meta for a list of media IDs.
	 *
	 * Render paths (BP activity stream, lightbox, dashboard) typically need
	 * 4-6 keys per media item across N items. Without prefetch that's 4N-6N
	 * queries (one per key per item). With prefetch it's 2 queries total:
	 * one for index columns, one for meta — both keyed `WHERE media_id IN
	 * (…)`. Subsequent `get()` calls hit the request cache (free).
	 *
	 * Idempotent — re-prefetching already-cached IDs is a no-op.
	 * Safe to call with mixed (cached + uncached) IDs; the SQL filters down
	 * to the uncached subset.
	 *
	 * @since 1.2.1
	 *
	 * @param int[] $media_ids Media IDs to prefetch.
	 */
	public function prefetch( array $media_ids ): void {
		if ( empty( $media_ids ) ) {
			return;
		}

		$ids = array();
		foreach ( $media_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}
			// Index already cached AND meta already fully loaded? Skip.
			if ( isset( self::$row_cache[ $id ] ) && isset( self::$meta_fully_loaded[ $id ] ) ) {
				continue;
			}
			$ids[ $id ] = true;
		}
		if ( empty( $ids ) ) {
			return;
		}
		$id_list      = array_keys( $ids );
		$placeholders = implode( ',', array_fill( 0, count( $id_list ), '%d' ) );

		global $wpdb;

		// Pull every index row in one query.
		$index_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE media_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$id_list
			),
			ARRAY_A
		);
		foreach ( $index_rows as $row ) {
			$mid = (int) $row['media_id'];
			if ( ! isset( self::$row_cache[ $mid ] ) ) {
				self::$row_cache[ $mid ] = array();
			}
			foreach ( $row as $col => $val ) {
				self::$row_cache[ $mid ][ $col ] = $val;
			}
		}

		// Pull every meta row for these media in one query.
		$meta_rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, meta_key, meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$id_list
			),
			ARRAY_A
		);
		foreach ( $meta_rows as $row ) {
			$mid = (int) $row['media_id'];
			if ( ! isset( self::$row_cache[ $mid ] ) ) {
				self::$row_cache[ $mid ] = array();
			}
			self::$row_cache[ $mid ][ $row['meta_key'] ] = $row['meta_value'];
		}

		// Mark every requested ID as fully loaded — even those with zero
		// meta rows. Subsequent get() calls for absent keys can return null
		// without an extra DB hit.
		foreach ( $id_list as $mid ) {
			self::$meta_fully_loaded[ $mid ] = true;
		}
	}

	/**
	 * Drop a media's cached row. Called on every set / set_many / delete.
	 *
	 * @since 1.2.1
	 */
	private static function invalidate_row_cache( int $media_id ): void {
		unset( self::$row_cache[ $media_id ], self::$meta_fully_loaded[ $media_id ] );
	}

	/**
	 * Purge the mvs_media_index + mvs_media_meta rows for an id and drop its
	 * request-scope row cache.
	 *
	 * Albums and collections get an mvs_media_index row purely to store their
	 * privacy (media_type left empty). This is the targeted row/meta purge their
	 * delete handlers call on `before_delete_post` so a deleted album/collection
	 * doesn't leave a dead tile on Explore (Basecamp 10073671889). Media items use
	 * delete_cascade() instead — it also clears the downstream reaction / stat /
	 * view / album-item tables that albums and collections never touch.
	 *
	 * @param int $media_id The mvs_media_index PK (media / album / collection id).
	 * @return void
	 */
	public function purge_index_record( int $media_id ): void {
		if ( $media_id <= 0 ) {
			return;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', array( 'media_id' => $media_id ), array( '%d' ) );
		$wpdb->delete( $wpdb->prefix . 'mvs_media_meta', array( 'media_id' => $media_id ), array( '%d' ) );
		// phpcs:enable
		self::invalidate_row_cache( $media_id );
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
	 * @return string Signed URL with a short TTL, or empty string.
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
		/**
		 * Filter the broadcast thumbnail TTL (seconds).
		 *
		 * Default 1 hour. Used to be YEAR_IN_SECONDS (WMV-05) — a stale
		 * bearer-token horizon for thumbnails embedded in BP activity,
		 * notifications, RSS, etc. The serve() path already re-checks
		 * privacy at request time for non-public media, but minting
		 * year-long URLs left an unnecessarily long credential lifetime
		 * after a privacy downgrade. Filter to widen for high-cache sites.
		 *
		 * @since 1.4.0
		 *
		 * @param int $ttl      TTL in seconds.
		 * @param int $media_id Media ID.
		 * @param string $size  Thumbnail size key.
		 */
		$ttl = (int) apply_filters( 'mvs_broadcast_thumbnail_ttl', HOUR_IN_SECONDS, $media_id, $size );
		if ( $ttl <= 0 ) {
			$ttl = HOUR_IN_SECONDS;
		}
		// $skip_privacy_check = false — broadcast emission MUST verify privacy
		// at sign time so private media never gets a broadcast URL.
		$url = $signed->generate_thumbnail( $media_id, 0, $svc_size, $ttl, false );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Viewer-aware signed thumbnail URL.
	 *
	 * Unlike get_broadcast_thumbnail_url() — which resolves anonymously for
	 * broadcast emission and therefore returns '' for any non-public media —
	 * this resolves against a specific viewer: it returns a signed thumbnail URL
	 * when that viewer is allowed to see the media (owner / admin / permitted
	 * audience) and '' otherwise. Lets app-layer consumers (BuddyNext galleries,
	 * profile feeds) show the real poster to authorized viewers of
	 * Members/Friends/Only-Me media instead of falling back to a generic poster.
	 *
	 * Privacy is enforced at sign time via the same viewer-aware access model
	 * query_by_author() uses (PrivacyService::can_view( $media_id, $viewer_id ))
	 * and re-verified at request time by the /serve endpoint, so the minted URL
	 * is never a bearer token past a privacy downgrade.
	 *
	 * @since 1.8.1
	 *
	 * @param int      $media_id  Media ID.
	 * @param string   $size      Thumbnail meta key ('thumb_large'|'thumb_medium'|'thumb_thumb')
	 *                            or SignedUrlService size ('large'|'medium'|'thumbnail').
	 * @param int|null $viewer_id Viewer to authorize against. Null = current user.
	 * @return string Signed URL when the viewer may view the media, else ''.
	 */
	public function get_thumbnail_url_for_viewer( int $media_id, string $size = 'thumb_large', ?int $viewer_id = null ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		$viewer_id = ( null === $viewer_id ) ? get_current_user_id() : (int) $viewer_id;
		// Accept either thumb_* meta key or the SignedUrlService size name.
		$svc_size = self::$thumb_size_map[ $size ] ?? $size;
		/**
		 * Filter the viewer-aware thumbnail TTL (seconds). Default 1 hour; the
		 * /serve endpoint re-checks privacy per request, so this is only a cache
		 * horizon, not a credential lifetime.
		 *
		 * @since 1.8.1
		 *
		 * @param int    $ttl       TTL in seconds.
		 * @param int    $media_id  Media ID.
		 * @param int    $viewer_id Viewer the URL is minted for.
		 * @param string $size      Thumbnail size key.
		 */
		$ttl = (int) apply_filters( 'mvs_viewer_thumbnail_ttl', HOUR_IN_SECONDS, $media_id, $viewer_id, $size );
		if ( $ttl <= 0 ) {
			$ttl = HOUR_IN_SECONDS;
		}
		// $skip_privacy_check = false — SignedUrlService verifies can_view( $media_id,
		// $viewer_id ) at sign time; an unauthorized viewer gets '' from here.
		$url = $signed->generate_thumbnail( $media_id, $viewer_id, $svc_size, $ttl, false );
		return is_string( $url ) ? $url : '';
	}

	/**
	 * Signed, viewer-aware URL for the FULL / original media file.
	 *
	 * The counterpart to get_thumbnail_url_for_viewer() for the original file
	 * rather than a thumbnail size. Non-public media (Members / Friends / Only Me)
	 * has no anonymous broadcast URL, so a caller that needs to DISPLAY the full
	 * media to an authorized viewer — a lightbox, a single-media stage — had no
	 * URL to render and showed a broken slot. Privacy is enforced at sign time
	 * (SignedUrlService::generate() returns false unless PrivacyService::can_view(
	 * $media_id, $viewer_id )) and re-verified per request by the /serve endpoint,
	 * so the minted URL is never a bearer token past a privacy downgrade.
	 *
	 * For public media on a cloud driver this returns the direct CDN URL (same as
	 * the broadcast path); for local/non-public media it returns the gated /serve
	 * URL. Returns '' when the viewer may not view the media.
	 *
	 * @since 2.0.0
	 *
	 * @param int      $media_id  Media ID.
	 * @param int|null $viewer_id Viewer to authorize against. Null = current user.
	 * @return string Signed URL when the viewer may view the media, else ''.
	 */
	public function get_url_for_viewer( int $media_id, ?int $viewer_id = null ): string {
		if ( $media_id <= 0 ) {
			return '';
		}
		$signed = $this->signed_urls_service();
		if ( ! $signed ) {
			return '';
		}
		$viewer_id = ( null === $viewer_id ) ? get_current_user_id() : (int) $viewer_id;
		/**
		 * Filter the viewer-aware full-file URL TTL (seconds). Default 1 hour; the
		 * /serve endpoint re-checks privacy per request, so this is only a cache
		 * horizon, not a credential lifetime.
		 *
		 * @since 2.0.0
		 *
		 * @param int $ttl       TTL in seconds.
		 * @param int $media_id  Media ID.
		 * @param int $viewer_id Viewer the URL is minted for.
		 */
		$ttl = (int) apply_filters( 'mvs_viewer_url_ttl', HOUR_IN_SECONDS, $media_id, $viewer_id );
		if ( $ttl <= 0 ) {
			$ttl = HOUR_IN_SECONDS;
		}
		// $download = false — display URL; privacy is verified inside generate().
		$url = $signed->generate( $media_id, $viewer_id, $ttl, false );
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
	 * Per-request memo for is_cpt_id() so the guard costs at most one lookup per ID.
	 *
	 * @since 2.4.0
	 * @var array<int,bool>
	 */
	private static $cpt_id_memo = array();

	/**
	 * Refuse a write keyed on a wp_posts ID.
	 *
	 * THE INVARIANT: mvs_media_index holds media, one row per media item, and an
	 * album or collection ID must never appear in media_id. Albums are wp_posts rows
	 * that media POINT AT via the album_id column; their own attributes (privacy,
	 * type, import markers) belong in post meta.
	 *
	 * Before 2.4.0 albums stored privacy by calling set()/set_many() with their post
	 * ID. media_id is AUTO_INCREMENT for media, so on any site where uploads have
	 * outrun post IDs — most of them, since members upload far more than a site
	 * publishes — that write landed on a real photo and overwrote its slug and
	 * privacy. It is a silent corruption on every album creation, which is why the
	 * refusal lives here at the choke point rather than at each caller.
	 *
	 * Refuses rather than throws: a fatal on a mis-keyed write would take a site
	 * down, and the correct outcome is simply that the bad write does not happen.
	 *
	 * Basecamp 10183850886. Plan: plan/2026-08-08-cpt-id-collision-fix-plan.md §4.0.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $media_id Candidate row key.
	 * @param string $context  Calling method, for the notice.
	 * @return bool True when the write must be refused.
	 */
	private function refuses_cpt_id( int $media_id, string $context ): bool {
		if ( $media_id <= 0 ) {
			return false;
		}

		if ( ! isset( self::$cpt_id_memo[ $media_id ] ) ) {
			$type                           = get_post_type( $media_id );
			self::$cpt_id_memo[ $media_id ] = ( 'mvs_album' === $type || 'mvs_collection' === $type );
		}

		if ( ! self::$cpt_id_memo[ $media_id ] ) {
			return false;
		}

		_doing_it_wrong(
			esc_html( $context ),
			'mvs_media_index is keyed on media IDs. Album and collection attributes belong in post meta — see AlbumService::set_privacy().',
			'2.4.0'
		);

		return true;
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

		if ( $this->refuses_cpt_id( $media_id, __METHOD__ ) ) {
			return;
		}

		if ( in_array( $key, self::$index_columns, true ) ) {
			$old_privacy = null;
			if ( 'privacy' === $key ) {
				$old_privacy = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
						$media_id
					)
				);
			}

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

			// Fire only on UPDATE (old value existed) and when value actually changes.
			// Inserts skip — uploaders set privacy at activity-creation time directly.
			if ( 'privacy' === $key && null !== $old_privacy && (string) $old_privacy !== (string) $value ) {
				/**
				 * Fires when a media item's privacy changes.
				 *
				 * @since 1.2.1
				 *
				 * @param int    $media_id    Media ID.
				 * @param string $new_privacy New privacy value.
				 * @param string $old_privacy Previous privacy value.
				 */
				do_action( 'mvs_media_privacy_changed', $media_id, (string) $value, (string) $old_privacy );
			}
			self::invalidate_row_cache( $media_id );
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

		self::invalidate_row_cache( $media_id );
	}

	/**
	 * Privacy levels ordered from most open to most closed.
	 *
	 * Used to decide what "tightening" means. `dm` is absent deliberately — it is
	 * a messaging scope, not a position on this scale.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const PRIVACY_ORDER = array( 'public', 'members', 'loggedin', 'friends', 'space', 'group', 'private' );

	/**
	 * How closed a privacy value is. Higher is more restrictive.
	 *
	 * @since 2.4.0
	 *
	 * @param string $privacy Privacy value.
	 * @return int -1 when unrecognised.
	 */
	public static function privacy_rank( string $privacy ): int {
		$index = array_search( $privacy, self::PRIVACY_ORDER, true );

		return false === $index ? -1 : (int) $index;
	}

	/**
	 * Media carrying a set of meta conditions, paginated, with a total.
	 *
	 * DOMAIN-NEUTRAL ON PURPOSE. Pro's stories bar is the first caller and needs
	 * "media whose `is_story` meta is 1 and whose `story_expires_at` is still in
	 * the future" — but `mvs_media_index` is this class's to query (architecture
	 * invariant 6) and Free has no business knowing what a story IS. So the
	 * caller supplies the meta keys and this supplies the join, the privacy
	 * scoping and the paging.
	 *
	 * Each condition is INNER JOINed, so they are ANDed: media missing any one
	 * of them does not appear.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type array $meta      List of {key, compare, value, select_as}. `compare`
	 *                            is allowlisted to = != > >= < <=; anything else
	 *                            becomes `=`. `select_as` returns that condition's
	 *                            meta_value in the row under the given name.
	 *     @type int[] $authors   Restrict to these authors. Empty means any.
	 *     @type int   $viewer_id Apply privacy for this viewer. 0 = public only.
	 *     @type bool  $privacy   Whether to apply privacy at all. Default true.
	 *     @type array $types     media_type allowlist. Default the media library.
	 *     @type int   $per_page  1-100. Default 20.
	 *     @type int   $page      1-based. Default 1.
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int}
	 */
	public function media_with_meta( array $args = array() ): array {
		global $wpdb;

		$meta_args = isset( $args['meta'] ) && is_array( $args['meta'] ) ? $args['meta'] : array();

		if ( empty( $meta_args ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$index    = $wpdb->prefix . 'mvs_media_index';
		$meta_tbl = $wpdb->prefix . 'mvs_media_meta';
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$allowed_compare = array( '=', '!=', '>', '>=', '<', '<=' );

		$joins   = array();
		$where   = array( "idx.status = 'publish'" );
		$params  = array();
		$selects = array( 'idx.media_id', 'idx.post_author', 'idx.title', 'idx.media_type', 'idx.privacy', 'idx.created_at' );

		foreach ( array_values( $meta_args ) as $i => $condition ) {
			$alias   = 'm' . (int) $i;
			$key     = isset( $condition['key'] ) ? (string) $condition['key'] : '';
			$compare = isset( $condition['compare'] ) && in_array( $condition['compare'], $allowed_compare, true )
				? (string) $condition['compare']
				: '=';

			if ( '' === $key ) {
				continue;
			}

			// The alias is ours, the key and value are the caller's and both are
			// bound. The COMPARE cannot be bound — hence the allowlist above.
			$joins[]  = $wpdb->prepare( "INNER JOIN {$meta_tbl} {$alias} ON {$alias}.media_id = idx.media_id AND {$alias}.meta_key = %s", $key ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where[]  = "{$alias}.meta_value {$compare} %s";
			$params[] = (string) ( $condition['value'] ?? '' );

			if ( ! empty( $condition['select_as'] ) ) {
				$as        = preg_replace( '/[^a-zA-Z0-9_]/', '', (string) $condition['select_as'] );
				$selects[] = "{$alias}.meta_value AS {$as}";
			}
		}

		if ( empty( $joins ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$types                          = isset( $args['types'] ) && is_array( $args['types'] ) ? $args['types'] : MediaTypes::MEDIA_LIBRARY;
		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types, 'idx.media_type' );
		$where                          = array_merge( $where, array( $type_sql ) );
		$params                         = array_merge( $params, $type_params );

		$authors = isset( $args['authors'] ) && is_array( $args['authors'] ) ? array_map( 'intval', $args['authors'] ) : array();

		if ( $authors ) {
			$where[] = 'idx.post_author IN (' . implode( ',', array_fill( 0, count( $authors ), '%d' ) ) . ')';
			$params  = array_merge( $params, $authors );
		}

		$apply_privacy = ! isset( $args['privacy'] ) || (bool) $args['privacy'];

		if ( $apply_privacy ) {
			$viewer_id = isset( $args['viewer_id'] ) ? (int) $args['viewer_id'] : 0;

			if ( $viewer_id > 0 ) {
				$where[]  = "( idx.post_author = %d OR idx.privacy = 'public' OR idx.privacy = 'members' )";
				$params[] = $viewer_id;
			} else {
				$where[] = "idx.privacy = 'public'";
			}
		}

		$from = "FROM {$index} idx " . implode( ' ', $joins ) . ' WHERE ' . implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$from}", ...$params ) );

		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT ' . implode( ', ', $selects ) . " {$from} ORDER BY idx.created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => $rows,
			'total' => $total,
		);
	}

	/**
	 * Distinct viewers per media since a per-media timestamp held in meta.
	 *
	 * The owner is never counted: a member refreshing their own upload would
	 * otherwise inflate its view count, which is the number other people are
	 * shown.
	 *
	 * The "since" bound is per media rather than global — it comes from each
	 * row's own meta value, so items that started at different times are each
	 * counted from their own start. Domain-neutral for the same reason as
	 * `media_with_meta()`: Pro's stories bar passes `story_started_at`, and this
	 * class does not need to know why.
	 *
	 * @since 2.4.0
	 *
	 * @param int[]  $media_ids      Media to count.
	 * @param string $since_meta_key Meta key holding each row's start timestamp.
	 * @return array<int, int> media_id => distinct viewers. Absent means zero.
	 */
	public function viewer_counts_since_meta( array $media_ids, string $since_meta_key ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $media_ids ) ) ) );

		if ( empty( $ids ) || '' === $since_meta_key ) {
			return array();
		}

		$views        = $wpdb->prefix . 'mvs_media_views';
		$meta_tbl     = $wpdb->prefix . 'mvs_media_meta';
		$index        = $wpdb->prefix . 'mvs_media_index';
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT v.media_id, COUNT(DISTINCT v.user_id) AS c
				   FROM {$views} v
			 INNER JOIN {$meta_tbl} sm ON sm.media_id = v.media_id AND sm.meta_key = %s
			 INNER JOIN {$index} idx ON idx.media_id = v.media_id
				  WHERE v.media_id IN ({$placeholders})
				    AND v.event_type = 'view'
				    AND v.user_id IS NOT NULL
				    AND v.user_id > 0
				    AND v.user_id != idx.post_author
				    AND v.created_at >= sm.meta_value
			   GROUP BY v.media_id",
				...array_merge( array( $since_meta_key ), $ids )
			),
			ARRAY_A
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$counts[ (int) $row['media_id'] ] = (int) $row['c'];
		}

		return $counts;
	}

	/**
	 * The condition every author-ranking query shares.
	 *
	 * Public, approved, published media with a real author, narrowed to the
	 * MEDIA library and optionally to a recent window. Built once so the page
	 * query, its total, and a single member's rank cannot drift apart — three
	 * queries that must agree on what "ranked" means or the rank a member is
	 * shown does not match the board they are looking at.
	 *
	 * @since 2.4.0
	 *
	 * @param string $window all|30d|7d.
	 * @return string SQL condition against alias `i`.
	 */
	private function ranking_condition( string $window ): string {
		global $wpdb;

		$cond = "i.status = 'publish' AND i.moderation_status = 'approved' AND i.privacy = 'public' AND i.post_author > 0";

		// The board ranks the MEDIA library. Documents are cheap to produce in
		// bulk, so counting them would let a member top a public ranking by
		// uploading a few hundred files nobody browses.
		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::MEDIA_LIBRARY, 'i.media_type' );

		$cond .= ' AND ' . (string) $wpdb->prepare( $type_sql, $type_params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$days = array(
			'30d' => 30,
			'7d'  => 7,
		);

		if ( isset( $days[ $window ] ) ) {
			$cond .= $wpdb->prepare( ' AND i.created_at >= DATE_SUB( NOW(), INTERVAL %d DAY )', $days[ $window ] );
		}

		return $cond;
	}

	/**
	 * One page of the author leaderboard, plus how many members are ranked.
	 *
	 * `$metric` and `$window` are ALLOWLISTED here rather than interpolated:
	 * both reach this method from a URL. Anything unrecognised falls back to the
	 * safe default instead of being passed to SQL.
	 *
	 * @since 2.4.0
	 *
	 * @param string $metric   reactions|media_count.
	 * @param string $window   all|30d|7d.
	 * @param int    $per_page Rows per page (1-100).
	 * @param int    $page     1-based page.
	 * @return array{rows: array<int, array{user_id:int, score:int}>, total: int}
	 */
	public function author_leaderboard( string $metric, string $window, int $per_page = 20, int $page = 1 ): array {
		global $wpdb;

		$metric   = 'media_count' === $metric ? 'media_count' : 'reactions';
		$window   = in_array( $window, array( 'all', '30d', '7d' ), true ) ? $window : 'all';
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( max( 1, $page ) - 1 ) * $per_page;

		$index = $wpdb->prefix . 'mvs_media_index';
		$cond  = $this->ranking_condition( $window );

		if ( 'media_count' === $metric ) {
			$score_expr = 'COUNT(*)';
			$having     = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT i.post_author) FROM {$index} i WHERE {$cond}" );
		} else {
			$score_expr = 'SUM(i.reaction_count)';
			// A member with zero reactions is not ON the board; without this they
			// would appear at the bottom with a score of 0 and inflate the total.
			$having = ' HAVING score > 0';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM ( SELECT i.post_author FROM {$index} i WHERE {$cond} GROUP BY i.post_author HAVING SUM(i.reaction_count) > 0 ) t"
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.post_author AS user_id, {$score_expr} AS score
				   FROM {$index} i
				  WHERE {$cond}
			   GROUP BY i.post_author{$having}
			   ORDER BY score DESC, i.post_author ASC
				  LIMIT %d OFFSET %d",
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$clean = array();

		foreach ( $rows as $row ) {
			$clean[] = array(
				'user_id' => (int) ( $row['user_id'] ?? 0 ),
				'score'   => (int) ( $row['score'] ?? 0 ),
			);
		}

		return array(
			'rows'  => $clean,
			'total' => $total,
		);
	}

	/**
	 * Where one member stands on that same board.
	 *
	 * Rank is "how many members score HIGHER, plus one" rather than a row offset,
	 * so it does not require paging to the member to find them, and ties share a
	 * rank instead of depending on which row the database returned first.
	 *
	 * @since 2.4.0
	 *
	 * @param string $metric  reactions|media_count.
	 * @param string $window  all|30d|7d.
	 * @param int    $user_id Member to locate.
	 * @return array{rank: int|null, score: int} Rank is null when they are not ranked at all.
	 */
	public function author_leaderboard_rank( string $metric, string $window, int $user_id ): array {
		global $wpdb;

		$none = array(
			'rank'  => null,
			'score' => 0,
		);

		if ( $user_id <= 0 ) {
			return $none;
		}

		$metric = 'media_count' === $metric ? 'media_count' : 'reactions';
		$window = in_array( $window, array( 'all', '30d', '7d' ), true ) ? $window : 'all';

		$index = $wpdb->prefix . 'mvs_media_index';
		$cond  = $this->ranking_condition( $window );

		if ( 'media_count' === $metric ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$score = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$index} i WHERE {$cond} AND i.post_author = %d", $user_id )
			);

			if ( $score <= 0 ) {
				return $none;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$higher = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM ( SELECT i.post_author, COUNT(*) c FROM {$index} i WHERE {$cond} GROUP BY i.post_author HAVING c > %d ) t",
					$score
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$score = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT SUM(i.reaction_count) FROM {$index} i WHERE {$cond} AND i.post_author = %d", $user_id )
			);

			if ( $score <= 0 ) {
				return $none;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$higher = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM ( SELECT i.post_author, SUM(i.reaction_count) s FROM {$index} i WHERE {$cond} GROUP BY i.post_author HAVING s > %d ) t",
					$score
				)
			);
		}

		return array(
			'rank'  => $higher + 1,
			'score' => $score,
		);
	}

	/**
	 * Published document ids whose TITLE matches a phrase, in title order.
	 *
	 * For the names an extractor never sees. Document search runs FULLTEXT over
	 * extracted text, and a file whose text is empty — a PDF, an image-only
	 * scan, anything not yet extracted — is findable only by what it is called.
	 *
	 * A `LIKE '%term%'` cannot use an index and is deliberately bounded by
	 * `$limit` for that reason: it is a candidate list feeding a ranked search,
	 * not a listing anyone pages through.
	 *
	 * @since 2.4.0
	 *
	 * @param string $query Search phrase. Escaped for LIKE here, not by callers.
	 * @param int    $limit Maximum ids to return.
	 * @return int[]
	 */
	public function document_title_candidates( string $query, int $limit = 50 ): array {
		global $wpdb;

		$query = trim( $query );

		if ( '' === $query ) {
			return array();
		}

		$limit = max( 1, min( 500, $limit ) );
		$like  = '%' . $wpdb->esc_like( $query ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT media_id
				   FROM {$wpdb->prefix}mvs_media_index
				  WHERE media_type = 'document'
				    AND status = 'publish'
				    AND title LIKE %s
				  ORDER BY title ASC
				  LIMIT %d",
				$like,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Document ids after a cursor, in id order.
	 *
	 * Keyset pagination for background sweeps: an `OFFSET` walk over a large
	 * library re-reads everything it has already passed, and drifts when rows
	 * are inserted mid-run. A cursor on the primary key does neither.
	 *
	 * @since 2.4.0
	 *
	 * @param int $cursor Exclusive lower bound on media_id.
	 * @param int $limit  Maximum ids to return.
	 * @return int[]
	 */
	public function document_ids_after( int $cursor, int $limit = 100 ): array {
		global $wpdb;

		$limit = max( 1, min( 1000, $limit ) );

		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::DOCUMENTS );

		$index  = $wpdb->prefix . 'mvs_media_index';
		$params = array_merge( $type_params, array( $cursor, $limit ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$ids = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT media_id FROM {$index}
				  WHERE {$type_sql} AND media_id > %d
				  ORDER BY media_id ASC
				  LIMIT %d",
				...$params
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * How many documents exist.
	 *
	 * A dedicated `COUNT(*)`, never `count()` of a listing — the number is used
	 * to say how much of the library has been indexed, and a count taken from a
	 * page would understate it on every site with more than one page.
	 *
	 * UNSCOPED BY DEFAULT, and that is right for its original caller: the
	 * extraction health check asks how much of the SITE's library is indexed.
	 * It is wrong for anything drawn beside a member's own name — the dashboard
	 * rail read 6 next to "Media 0" for a member who owned three documents,
	 * because one counter answered "on this site" and the one above it answered
	 * "yours". Two numbers in one list have to answer the same question.
	 *
	 * @since 2.4.0
	 * @since 2.4.0 `$args` adds author and status scoping.
	 *
	 * @param bool  $include_legacy Whether quarantined legacy documents count.
	 * @param array $args {
	 *     @type int    $author Owner to scope to, or 0 for the whole site.
	 *     @type string $status publish|trash|any. Default publish.
	 * }
	 * @return int
	 */
	public function count_documents( bool $include_legacy = false, array $args = array() ): int {
		global $wpdb;

		$types = $include_legacy ? MediaTypes::DOCUMENT_LIBRARY : MediaTypes::DOCUMENTS;

		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types );

		$where  = array( $type_sql );
		$params = $type_params;

		$author = isset( $args['author'] ) ? (int) $args['author'] : 0;

		if ( $author > 0 ) {
			$where[]  = 'post_author = %d';
			$params[] = $author;
		}

		// `any` is the pre-existing behaviour — trashed rows included — kept as
		// the default so the health check keeps counting what it always counted.
		$status = ( isset( $args['status'] ) && in_array( $args['status'], array( 'publish', 'trash', 'any' ), true ) )
			? (string) $args['status']
			: 'any';

		if ( 'any' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$index     = $wpdb->prefix . 'mvs_media_index';
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE {$where_sql}", ...$params )
		);
	}

	/**
	 * Fetch documents by id, preserving the order they were asked for.
	 *
	 * Search hands back ids ranked by relevance; re-sorting them by date here
	 * would throw that ranking away, and a second query per id would be the N+1
	 * the whole design avoids.
	 *
	 * @since 2.4.0
	 *
	 * @param int[] $ids Document ids.
	 * @return array<int, array<string, mixed>>
	 */
	public function documents_by_ids( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

		if ( ! $ids ) {
			return array();
		}

		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::DOCUMENTS );

		$index        = $wpdb->prefix . 'mvs_media_index';
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		$params       = array_merge( $type_params, $ids );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// `drive_type` / `drive_id` for the same reason as
				// `drive_documents()`: Pro's privacy ladder cannot place a
				// document at a drive root without them, and search feeds that
				// ladder directly.
				"SELECT media_id, title, slug, post_author, media_type, file_type, file_size, privacy, status, folder_id, drive_type, drive_id, created_at
				   FROM {$index}
				  WHERE {$type_sql} AND media_id IN ( {$placeholders} )",
				...$params
			),
			ARRAY_A
		);

		$by_id = array();
		foreach ( $rows as $row ) {
			$by_id[ (int) $row['media_id'] ] = $row;
		}

		$ordered = array();
		foreach ( $ids as $id ) {
			if ( isset( $by_id[ $id ] ) ) {
				$ordered[] = $by_id[ $id ];
			}
		}

		return $ordered;
	}

	/**
	 * Direct document counts for a set of folders, in ONE query.
	 *
	 * The display contract is specific about this: "counts are direct children —
	 * one GROUP BY per page, never recursive". Both halves matter. A count per
	 * folder row would be an N+1 on the one surface a member opens most, and a
	 * RECURSIVE count would need the subtree on every row — which on a drive
	 * with 30k documents is the whole table, to render a number.
	 *
	 * Folders absent from the result have no documents; the caller defaults to 0
	 * rather than this padding the array with zeroes.
	 *
	 * @since 2.4.0
	 *
	 * @param int[] $folder_ids Folder ids.
	 * @return array<int, int> folder_id => direct document count.
	 */
	public function count_documents_in_folders( array $folder_ids ): array {
		global $wpdb;

		$folder_ids = array_values( array_unique( array_filter( array_map( 'intval', $folder_ids ) ) ) );

		if ( ! $folder_ids ) {
			return array();
		}

		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::DOCUMENTS );

		$index        = $wpdb->prefix . 'mvs_media_index';
		$placeholders = implode( ', ', array_fill( 0, count( $folder_ids ), '%d' ) );
		$params       = array_merge( $type_params, $folder_ids, array( 'publish' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT folder_id, COUNT(*) AS total
				   FROM {$index}
				  WHERE {$type_sql} AND folder_id IN ( {$placeholders} ) AND status = %s
				  GROUP BY folder_id",
				...$params
			),
			ARRAY_A
		);

		$counts = array();
		foreach ( $rows as $row ) {
			$counts[ (int) $row['folder_id'] ] = (int) $row['total'];
		}

		return $counts;
	}

	/**
	 * Which document types are actually present, and how many of each.
	 *
	 * A filter row built from every type this plugin CAN store offers chips that
	 * are guaranteed to return nothing — a site with three PDFs still showed
	 * PowerPoint, ODF Slides and RTF, each one a dead end. The media tag cloud
	 * has always worked the other way round: it lists the tags in use. This is
	 * the same idea for documents.
	 *
	 * One grouped query. The MIME-to-type fold happens in PHP because the
	 * mapping lives in `DocumentTypes` and duplicating it in SQL would give the
	 * database its own opinion about what a Word file is.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type bool   $public_only Restrict to publicly visible documents.
	 *     @type int    $author      Restrict to one uploader, 0 for all.
	 *     @type bool   $legacy      Include quarantined legacy documents.
	 * }
	 * @return array<string, int> Named type => count, highest first, no zeroes.
	 */
	public function document_type_counts( array $args = array() ): array {
		global $wpdb;

		$public_only = ! empty( $args['public_only'] );
		$author      = isset( $args['author'] ) ? (int) $args['author'] : 0;
		$types       = ! empty( $args['legacy'] ) ? MediaTypes::DOCUMENT_LIBRARY : MediaTypes::DOCUMENTS;

		list( $type_sql, $params ) = MediaTypes::in_clause( $types );

		$where    = array( $type_sql, 'status = %s' );
		$params[] = 'publish';

		if ( $public_only ) {
			$where[]  = 'privacy = %s';
			$params[] = 'public';
			$where[]  = 'moderation_status = %s';
			$params[] = 'approved';
		}

		if ( $author > 0 ) {
			$where[]  = 'post_author = %d';
			$params[] = $author;
		}

		$index     = $wpdb->prefix . 'mvs_media_index';
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT file_type, COUNT(*) AS total
				   FROM {$index}
				  WHERE {$where_sql}
				  GROUP BY file_type",
				...$params
			),
			ARRAY_A
		);

		$counts = array();

		foreach ( $rows as $row ) {
			$group = \WPMediaVerse\Core\DocumentTypes::group_for_mime( (string) $row['file_type'] );

			if ( null === $group ) {
				// A stored MIME this build does not name. Counting it under a
				// chip nobody could click would be worse than leaving it out.
				continue;
			}

			$counts[ $group ] = ( $counts[ $group ] ?? 0 ) + (int) $row['total'];
		}

		arsort( $counts );

		return $counts;
	}

	/**
	 * SQL clause narrowing a document listing to one named type.
	 *
	 * A `doc_type` is a display group, not a stored column — `file_type` holds
	 * the validated MIME. So the filter expands to the MIME types that map to
	 * that group, which keeps the clause a positive list on an indexed column
	 * instead of a `LIKE` that no index can serve.
	 *
	 * An unknown type matches NOTHING rather than everything. Failing open would
	 * show every document under a label the caller chose in order to narrow.
	 *
	 * @since 2.4.0
	 *
	 * @param string $doc_type Named document type.
	 * @return array{0: string, 1: array<int, string>} Clause and its params.
	 */
	private function document_type_clause( string $doc_type ): array {
		if ( ! class_exists( '\WPMediaVerse\Core\DocumentTypes' ) ) {
			return array( '1 = 0', array() );
		}

		$mimes = array();
		foreach ( \WPMediaVerse\Core\DocumentTypes::allowed_mimes() as $mime ) {
			if ( \WPMediaVerse\Core\DocumentTypes::group_for_mime( $mime ) === $doc_type ) {
				$mimes[] = $mime;
			}
		}

		if ( ! $mimes ) {
			return array( '1 = 0', array() );
		}

		return array(
			'file_type IN ( ' . implode( ', ', array_fill( 0, count( $mimes ), '%s' ) ) . ' )',
			$mimes,
		);
	}

	/**
	 * Every document on the site, for the admin screen.
	 *
	 * The site owner's view, so it is NOT privacy-scoped — unlike every
	 * member-facing document query. It is still paginated, still counted with a
	 * dedicated `COUNT(*)`, and still filtered and sorted on indexed columns:
	 * an admin screen is exactly where a site with 50,000 documents shows up.
	 *
	 * Sorting is restricted to a fixed allowlist because the column name cannot
	 * be a prepared parameter — anything outside it falls back to recency rather
	 * than being interpolated.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type int    $per_page Rows per page. Default 20, max 100.
	 *     @type int    $page     1-based page. Default 1.
	 *     @type string $doc_type Optional named-type filter.
	 *     @type string $privacy  Optional privacy filter.
	 *     @type int    $author   Optional author filter.
	 *     @type string $search   Optional title search.
	 *     @type string $orderby  created_at|title|file_size. Default created_at.
	 *     @type string $order    ASC|DESC. Default DESC.
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function admin_documents( array $args = array() ): array {
		global $wpdb;

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$doc_type = isset( $args['doc_type'] ) ? (string) $args['doc_type'] : '';
		$privacy  = isset( $args['privacy'] ) ? (string) $args['privacy'] : '';
		$author   = isset( $args['author'] ) ? (int) $args['author'] : 0;
		$search   = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::DOCUMENT_LIBRARY );

		$where  = array( $type_sql );
		$params = $type_params;

		if ( '' !== $doc_type ) {
			list( $mime_sql, $mime_params ) = $this->document_type_clause( $doc_type );

			$where[] = $mime_sql;
			$params  = array_merge( $params, $mime_params );
		}

		if ( '' !== $privacy ) {
			$where[]  = 'privacy = %s';
			$params[] = $privacy;
		}

		if ( $author > 0 ) {
			$where[]  = 'post_author = %d';
			$params[] = $author;
		}

		if ( '' !== $search ) {
			$where[]  = 'title LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// The column cannot be prepared, so it comes from a fixed allowlist.
		$sortable = array( 'created_at', 'title', 'file_size' );
		$orderby  = isset( $args['orderby'] ) && in_array( $args['orderby'], $sortable, true )
			? (string) $args['orderby']
			: 'created_at';
		$order    = isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$index     = $wpdb->prefix . 'mvs_media_index';
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE {$where_sql}", ...$params ) );

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT media_id, title, slug, post_author, media_type, file_type, file_size, privacy, status, created_at
				   FROM {$index}
				  WHERE {$where_sql}
				  ORDER BY {$orderby} {$order}
				  LIMIT %d OFFSET %d",
				...$page_params
			),
			ARRAY_A
		);

		return array(
			'items' => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Public documents, for the document listing page.
	 *
	 * Uses `MediaTypes::DOCUMENT_LIBRARY`, so quarantined `legacy_document` rows
	 * appear here — this is the surface that renders them correctly, as a row with
	 * a type chip rather than a picture that does not exist.
	 *
	 * PUBLIC ONLY. Private documents belong to a member's drive, which is a
	 * different surface with a grants-first permission model; this page never
	 * tries to answer "what may this viewer see", it lists what is already public.
	 * That keeps it a simple indexed read and keeps the ACL in one place.
	 *
	 * Served by `KEY type_file` when filtered by type, and returns an honest total
	 * from a dedicated COUNT(*) — never `count()` of the page, which would be
	 * wrong past page 1.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type int    $per_page Rows per page. Default 20.
	 *     @type int    $page     1-based page. Default 1.
	 *     @type string $doc_type Optional MIME-group filter, e.g. 'pdf'.
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function public_documents( array $args = array() ): array {
		global $wpdb;

		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 20;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;
		$doc_type = isset( $args['doc_type'] ) ? (string) $args['doc_type'] : '';

		// SORT IS AN ALLOWLIST, never the caller's string. `orderby` is the one
		// argument here that reaches SQL as an identifier rather than a bound
		// value, so an unknown key falls back to the default instead of being
		// escaped and hoped for.
		$sortable = array(
			'created_at' => 'created_at',
			'title'      => 'title',
			'file_size'  => 'file_size',
		);
		$orderby  = isset( $args['orderby'], $sortable[ $args['orderby'] ] ) ? $sortable[ $args['orderby'] ] : 'created_at';
		$order    = ( isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::DOCUMENT_LIBRARY );

		$where  = array( $type_sql, 'privacy = %s', 'status = %s', 'moderation_status = %s' );
		$params = array_merge( $type_params, array( 'public', 'publish', 'approved' ) );

		if ( '' !== $doc_type ) {
			list( $mime_sql, $mime_params ) = $this->document_type_clause( $doc_type );

			$where[] = $mime_sql;
			$params  = array_merge( $params, $mime_params );
		}

		// Title search on the public listing. Deliberately NOT the full-text
		// index: that one is permission-scoped and covers file CONTENTS, which
		// is not something an anonymous visitor gets to search.
		$search = isset( $args['search'] ) ? trim( (string) $args['search'] ) : '';

		if ( '' !== $search ) {
			$where[]  = '( title LIKE %s OR description LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		$index     = $wpdb->prefix . 'mvs_media_index';
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE {$where_sql}", ...$params ) );

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT media_id, title, slug, description, post_author, media_type, file_type, file_size, created_at
				   FROM {$index}
				  WHERE {$where_sql}
				  ORDER BY {$orderby} {$order}
				  LIMIT %d OFFSET %d",
				...$page_params
			),
			ARRAY_A
		);

		return array(
			'items' => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * Has Migrator v29's drive backfill finished stamping every row?
	 *
	 * The cursor option holds the highest stamped `media_id` while the backfill
	 * runs, and `-1` once a pass finds nothing left to do. So `-1` — and only
	 * `-1` — means every row carries a real drive, which is what lets
	 * `drive_documents()` drop its legacy `post_author` branch and become an
	 * index-shaped query.
	 *
	 * ABSENT IS NOT FINISHED. A site that has never run v29 has no option at
	 * all, and reading that as "done" would scope its listings by a column
	 * nothing has written — every drive would look empty. The default is
	 * therefore 0, which reads as "still running", and that is the safe answer
	 * for the never-migrated and the half-migrated case alike.
	 *
	 * Memoised per request: the drive query runs several times a page, and this
	 * cannot change mid-request in a way any caller would want to see.
	 *
	 * @since 2.4.0
	 *
	 * @return bool
	 */
	private static function drive_backfill_finished(): bool {
		static $finished = null;

		if ( null === $finished ) {
			$finished = -1 === (int) get_option( \WPMediaVerse\Core\Migrator::DRIVE_BACKFILL_OPTION, 0 );
		}

		return $finished;
	}

	/**
	 * Documents in one drive, optionally inside one folder.
	 *
	 * The drive query from design §4, verbatim, so an index serves it left to
	 * right in both of its shapes — `KEY doc_listing` inside a folder,
	 * `KEY drive_listing` at the drive root. Which one, and why both are needed,
	 * is worked through at the `INDEX REALITY` comment in the body.
	 *
	 * The DRIVE scopes the root listing, not the author (Phase 11 G1). Inside a
	 * folder the scope is dropped, because the folder already scoped the drive —
	 * carrying it would add columns the index cannot use at that position and
	 * change nothing.
	 *
	 * Returns rows plus an honest total from a dedicated `COUNT(*)`. It does NOT
	 * apply document permissions: the caller holds `PermissionService` and
	 * resolves a whole page in two queries, which is the only way that stays
	 * within budget. A repository that filtered per row would reintroduce the
	 * N+1 this design exists to avoid.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type int    $author    Drive owner. Required for a root listing.
	 *     @type int    $folder_id Folder, or 0 for the drive root.
	 *     @type int    $per_page  Default 50.
	 *     @type int    $page      Default 1.
	 *     @type string $status    publish|trash. Default publish.
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function drive_documents( array $args = array() ): array {
		global $wpdb;

		$author    = isset( $args['author'] ) ? (int) $args['author'] : 0;
		$folder_id = isset( $args['folder_id'] ) ? (int) $args['folder_id'] : 0;

		// `status` is an ALLOWLIST, not a passthrough — it lands in an indexed
		// column of a query a member controls through the URL. The trash view is
		// the only caller that asks for anything but `publish`, and it asks for a
		// listing the member can restore from: without one, trashing is a one-way
		// door and the row is simply gone from every surface they have.
		$status   = ( isset( $args['status'] ) && in_array( $args['status'], array( 'publish', 'trash' ), true ) )
			? (string) $args['status']
			: 'publish';
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		list( $type_sql, $type_params ) = MediaTypes::in_clause( MediaTypes::DOCUMENTS );

		// `any_folder` spans the whole drive rather than one folder — what a
		// "Recent" view needs, since recency is a property of the document and
		// not of where it happens to be filed.
		//
		// INDEX REALITY, re-measured 2026-08-19 on a 30,000-document fixture
		// after the soft spot this comment used to describe was closed.
		//
		// TWO INDEXES, AND WHICH ONE RUNS DEPENDS ON THE SHAPE OF THE QUERY:
		//
		//   - Inside a folder, there is no drive predicate — the folder already
		//     scoped the drive — so the query is `media_type`, `folder_id`,
		//     `status`, `created_at`, which is `KEY doc_listing` verbatim. That
		//     is why doc_listing is NOT redundant now drive_listing exists and
		//     must not be dropped: drive_listing has `drive_type`/`drive_id` at
		//     positions 2 and 3, so a folder listing that does not name a drive
		//     cannot use it past `media_type`.
		//
		//   - At the drive root, the drive predicate makes the query
		//     `media_type`, `drive_type`, `drive_id`, `folder_id`, `status`,
		//     `created_at` — `KEY drive_listing` verbatim. Measured: 234 rows
		//     examined at 100% filtered, against 8,032 at 1.38% before.
		//
		// `any_folder` (the Recent view) drops `folder_id`, so it reads
		// drive_listing's first three columns and then stops — indexed, but not
		// the clean left-to-right read the other two get. That is the remaining
		// soft spot and it is a much smaller one: the drive scope is applied by
		// the index rather than by a post-filter over the whole document table.
		$any_folder = ! empty( $args['any_folder'] );
		$where      = $any_folder
			? array( $type_sql, 'status = %s' )
			: array( $type_sql, 'folder_id = %d', 'status = %s' );
		$params     = $any_folder
			? array_merge( $type_params, array( $status ) )
			: array_merge( $type_params, array( $folder_id, $status ) );

		// ROOT SCOPING IS THE DRIVE, NOT THE AUTHOR (Phase 11 G1).
		//
		// This used to read `post_author = %d`, which worked only because a
		// personal drive's owner and its documents' uploader are the same person.
		// That coincidence is exactly what made a Space-root upload impossible:
		// filed by a member, it would list under THEM rather than under the
		// Space. `post_author` goes back to meaning only "who uploaded this".
		//
		// `drive_id = 0` is carried alongside for rows Migrator v29's bounded
		// backfill has not reached yet — on those the author IS the drive, so
		// falling back to it is correct rather than merely tolerant, and a
		// half-migrated site lists exactly what it listed before.
		$drive_type = isset( $args['drive_type'] ) ? (string) $args['drive_type'] : 'user';
		$drive_id   = isset( $args['drive_id'] ) ? (int) $args['drive_id'] : $author;

		if ( ( $any_folder || 0 === $folder_id ) && $drive_id > 0 ) {
			// THE `OR` IS TEMPORARY, AND IT COSTS THE INDEX WHILE IT LASTS.
			//
			// Measured 2026-08-19 on a 30,000-document fixture: with the OR in
			// place the optimiser SEES `drive_listing` in `possible_keys` and
			// refuses it, falling back to `doc_listing` — 8,032 rows examined
			// at 1.38% filtered for one page at OFFSET 1000. An OR cannot
			// satisfy positions 2 and 3 of a composite index, so the six-column
			// index v29 added was paying write cost on the hottest table in the
			// product and serving nothing.
			//
			// The second branch exists only for rows Migrator v29's bounded
			// backfill has not stamped yet. Once the backfill reports finished
			// there are none, so the predicate collapses to the drive alone and
			// `drive_listing` matches left-to-right — which is what §12 has
			// claimed all along.
			//
			// Rows the backfill SKIPS are not lost by this: it skips only
			// `post_author <= 0`, and the legacy branch could never match those
			// either (it needs `post_author = %d` with a real author). An
			// ownerless row belongs to no personal drive, so listing it under
			// one was never right.
			if ( self::drive_backfill_finished() ) {
				$where[]  = 'drive_type = %s';
				$where[]  = 'drive_id = %d';
				$params[] = $drive_type;
				$params[] = $drive_id;
			} else {
				$where[]  = '( ( drive_id = %d AND drive_type = %s ) OR ( drive_id = 0 AND post_author = %d ) )';
				$params[] = $drive_id;
				$params[] = $drive_type;
				$params[] = $author > 0 ? $author : $drive_id;
			}
		}

		// A drive with 2,000 documents is unusable without a way to narrow it and
		// a way to reorder it — big-site checklist item 5. Both run on indexed
		// columns, and the sort column comes from a fixed allowlist because a
		// column name cannot be a prepared parameter.
		if ( ! empty( $args['doc_type'] ) ) {
			list( $mime_sql, $mime_params ) = $this->document_type_clause( (string) $args['doc_type'] );

			$where[] = $mime_sql;
			$params  = array_merge( $params, $mime_params );
		}

		$sortable = array( 'created_at', 'title', 'file_size' );
		$orderby  = ( isset( $args['orderby'] ) && in_array( $args['orderby'], $sortable, true ) )
			? (string) $args['orderby']
			: 'created_at';
		$order    = ( isset( $args['order'] ) && 'ASC' === strtoupper( (string) $args['order'] ) ) ? 'ASC' : 'DESC';

		$index     = $wpdb->prefix . 'mvs_media_index';
		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE {$where_sql}", ...$params ) );

		$page_params   = $params;
		$page_params[] = $per_page;

		// `offset` OVERRIDES the page-derived offset when given.
		//
		// The drive root pages folders and documents as ONE ordered set, folders
		// first. Once the folders on a page are placed, the documents that follow
		// start at an offset that is not a multiple of per_page — page 2 of a
		// 22-folder drive with 25 rows per page begins at document 3, not 25. A
		// page number cannot express that, so the caller passes the offset it
		// computed. Absent, behaviour is exactly as before.
		$page_params[] = isset( $args['offset'] )
			? max( 0, (int) $args['offset'] )
			: ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				// `drive_type` / `drive_id` are selected because this method
				// FILTERS on them (above) — a row set scoped by a column it does
				// not return forces every consumer to re-derive the drive, and
				// the one that did guessed "the folder it is in, else its
				// author's drive". That guess is right for every foldered
				// document and wrong for a document at a Space drive ROOT, which
				// has no folder and is not its author's. Pro's privacy ladder
				// reads them.
				"SELECT media_id, title, slug, description, post_author, media_type, file_type, file_size, privacy, folder_id, drive_type, drive_id, created_at
				   FROM {$index}
				  WHERE {$where_sql}
				  ORDER BY {$orderby} {$order}
				  LIMIT %d OFFSET %d",
				...$page_params
			),
			ARRAY_A
		);

		return array(
			'items' => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * The WHERE clause and bound params for "documents shared with this member".
	 *
	 * Extracted so the listing and its COUNT ask the same question. The
	 * predicate is subtle — grantee type, role membership, revocation, expiry,
	 * target type, and the owner exclusion — and two hand-written copies of it
	 * would drift the moment one gained a clause. The count is the one that
	 * would drift silently: a tab reading "12" beside a list of 8 is not an
	 * obvious bug, it is just wrong.
	 *
	 * @since 2.4.0
	 *
	 * @param int      $user_id Viewer.
	 * @param string[] $roles   The viewer's roles.
	 * @return array{0:string,1:array} WHERE fragment and its params, in placeholder order.
	 */
	private function shared_with_scope( int $user_id, array $roles ): array {
		// Params are appended in PLACEHOLDER ORDER. Building them out of order and
		// splicing one back into position works right up until somebody adds a
		// clause, and a misaligned prepare() is a silent wrong-answer bug.
		$grantee_sql = '( ( g.grantee_type = %s AND g.user_id = %d )';
		$params      = array( 'user', $user_id );

		if ( $roles ) {
			$grantee_sql .= ' OR ( g.grantee_type = %s AND g.grantee_role IN ( ' . implode( ', ', array_fill( 0, count( $roles ), '%s' ) ) . ' ) )';
			$params[]     = 'role';
			$params       = array_merge( $params, $roles );
		}
		$grantee_sql .= ' )';

		// "Shared with me" means things OTHER PEOPLE gave me. A role grant is
		// legitimately made to a role, and the uploader usually holds that role
		// too — so sharing to "Subscriber" put your own document into your own
		// Shared-with-me band (Basecamp 10190505530). The scope is what was
		// missing, not the grant: `PermissionService::grant()` correctly refuses
		// a DIRECT grant to the owner (`mvs_grant_redundant`) and must keep
		// accepting the role grant, which is not redundant for its other holders.
		$where = "{$grantee_sql}
		           AND g.revoked_at IS NULL
		           AND ( g.expires_at IS NULL OR g.expires_at > %s )
		           AND g.target_type = %s
		           AND m.media_type = %s
		           AND m.status = %s
		           AND m.post_author <> %d";

		$params[] = current_time( 'mysql', true );
		$params[] = 'media';
		$params[] = 'document';
		$params[] = 'publish';
		$params[] = $user_id;

		return array( $where, $params );
	}

	/**
	 * How many documents are shared with this member.
	 *
	 * `COUNT(DISTINCT media_id)`, not `count()` of a page of rows — the drive's
	 * "Shared with me" tab states the size of the set, and the set is larger
	 * than any page of it.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args { @type int $user_id, @type string[] $roles }.
	 * @return int
	 */
	public function count_documents_shared_with( array $args = array() ): int {
		global $wpdb;

		$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$roles   = isset( $args['roles'] ) && is_array( $args['roles'] ) ? array_values( $args['roles'] ) : array();

		if ( $user_id <= 0 ) {
			return 0;
		}

		$grants = $wpdb->prefix . 'mvs_access_grants';
		$index  = $wpdb->prefix . 'mvs_media_index';

		list( $where, $params ) = $this->shared_with_scope( $user_id, $roles );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT m.media_id) FROM {$grants} g INNER JOIN {$index} m ON m.media_id = g.media_id WHERE {$where}", ...$params )
		);
	}

	/**
	 * Documents shared WITH a viewer, by direct grant.
	 *
	 * Here rather than in Pro because it JOINS `mvs_media_index`, and Free owns
	 * that table. Pro's `/me/shared` route built this query itself at first —
	 * assigning the table name to a variable and joining it, which is precisely
	 * the shape the P1.1 audit records as a grep blind spot ("Pro assigns the
	 * table to a variable and queries that"). It survived the architecture check
	 * and the duplication gate, and was found only by a self-audit that went
	 * looking for that exact pattern.
	 *
	 * Direct DOCUMENT grants only. A folder grant surfaces as the folder,
	 * navigable in its owner's tree; flattening its contents here would list the
	 * same document twice with no way to tell why (design §5).
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type int      $user_id  Viewer. Required.
	 *     @type string[] $roles    Viewer's roles, for role grants.
	 *     @type int      $per_page Default 50.
	 *     @type int      $page     Default 1.
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function documents_shared_with( array $args = array() ): array {
		global $wpdb;

		$user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$roles    = isset( $args['roles'] ) && is_array( $args['roles'] ) ? array_values( $args['roles'] ) : array();
		$per_page = isset( $args['per_page'] ) ? max( 1, min( 100, (int) $args['per_page'] ) ) : 50;
		$page     = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

		$empty = array(
			'items' => array(),
			'total' => 0,
			'pages' => 0,
		);

		if ( $user_id <= 0 ) {
			return $empty;
		}

		$grants = $wpdb->prefix . 'mvs_access_grants';
		$index  = $wpdb->prefix . 'mvs_media_index';

		list( $where, $params ) = $this->shared_with_scope( $user_id, $roles );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT m.media_id) FROM {$grants} g INNER JOIN {$index} m ON m.media_id = g.media_id WHERE {$where}", ...$params )
		);

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = ( $page - 1 ) * $per_page;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT m.media_id, m.title, m.slug, m.description, m.post_author, m.media_type,
				        m.file_type, m.file_size, m.privacy, m.folder_id, m.created_at
				   FROM {$grants} g
				   INNER JOIN {$index} m ON m.media_id = g.media_id
				  WHERE {$where}
				  ORDER BY m.created_at DESC
				  LIMIT %d OFFSET %d",
				...$page_params
			),
			ARRAY_A
		);

		return array(
			'items' => $rows,
			'total' => $total,
			'pages' => (int) ceil( $total / $per_page ),
		);
	}

	/**
	 * How closed a privacy value is. Instance form, for the boundary interface.
	 *
	 * @since 2.4.0
	 *
	 * @param string $privacy Privacy value.
	 * @return int
	 */
	public function privacy_level( string $privacy ): int {
		return self::privacy_rank( $privacy );
	}

	/**
	 * Every privacy value strictly looser than the given one.
	 *
	 * @since 2.4.0
	 *
	 * @param string $privacy Privacy value.
	 * @return string[]
	 */
	public function privacy_levels_looser_than( string $privacy ): array {
		$rank = self::privacy_rank( $privacy );

		if ( $rank <= 0 ) {
			return array();
		}

		return array_slice( self::PRIVACY_ORDER, 0, $rank );
	}

	/**
	 * Tighten the privacy of every document sitting in the given folders.
	 *
	 * ONE indexed UPDATE, not a row-by-row walk: a Space drive folder can hold
	 * tens of thousands of documents and this runs while somebody waits.
	 *
	 * TIGHTENING ONLY. Rows already at or beyond the target are left alone, so an
	 * explicit `private` on a single file outranks its container and a later
	 * loosening of the folder cannot silently re-expose it. That asymmetry is the
	 * whole point of T2: the dangerous direction is blocked, the safe one is not.
	 *
	 * Lives in Free because Free owns this table — Pro calls it rather than
	 * writing `mvs_media_index` directly (architecture invariant 6).
	 *
	 * @since 2.4.0
	 *
	 * @param int[]  $folder_ids Folder ids whose direct documents are affected.
	 * @param string $privacy    Target privacy.
	 * @param int    $limit      0 for no limit; above 0 the update is capped so a
	 *                           caller can batch a very large subtree.
	 * @return int Rows changed.
	 */
	public function tighten_document_privacy_in_folders( array $folder_ids, string $privacy, int $limit = 0 ): int {
		global $wpdb;

		$folder_ids = array_values( array_unique( array_filter( array_map( 'intval', $folder_ids ), static fn( $id ) => $id >= 0 ) ) );
		$target     = self::privacy_rank( $privacy );

		if ( ! $folder_ids || $target < 0 ) {
			return 0;
		}

		// Only values strictly LOOSER than the target move. `public` is the
		// loosest level there is, so nothing can be looser than it and a
		// "tightening" to public moves nothing by definition.
		if ( 0 === $target ) {
			return 0;
		}

		$looser = array_slice( self::PRIVACY_ORDER, 0, $target );

		$index = $wpdb->prefix . 'mvs_media_index';

		$folder_placeholders  = implode( ', ', array_fill( 0, count( $folder_ids ), '%d' ) );
		$privacy_placeholders = implode( ', ', array_fill( 0, count( $looser ), '%s' ) );

		$params = array_merge(
			array( $privacy ),
			$folder_ids,
			$looser,
			array( 'document' )
		);

		$sql = "UPDATE {$index}
		           SET privacy = %s
		         WHERE folder_id IN ({$folder_placeholders})
		           AND privacy IN ({$privacy_placeholders})
		           AND media_type = %s";

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = $limit;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$changed = (int) $wpdb->query( $wpdb->prepare( $sql, ...$params ) );

		if ( $changed > 0 ) {
			// A bulk UPDATE bypasses the per-id invalidation every other write
			// goes through, so the whole request cache goes rather than guessing
			// which ids moved — a stale `privacy` here is a stale ACCESS answer.
			self::$row_cache         = array();
			self::$meta_fully_loaded = array();
		}

		return $changed;
	}

	/**
	 * How many documents a privacy tightening would change.
	 *
	 * Backs the confirmation copy — "47 documents inside will also become
	 * private" — so the number the member is shown is the number that will move,
	 * counted the same way the UPDATE selects.
	 *
	 * @since 2.4.0
	 *
	 * @param int[]  $folder_ids Folder ids.
	 * @param string $privacy    Target privacy.
	 * @return int
	 */
	public function count_documents_to_tighten( array $folder_ids, string $privacy ): int {
		global $wpdb;

		$folder_ids = array_values( array_unique( array_filter( array_map( 'intval', $folder_ids ), static fn( $id ) => $id >= 0 ) ) );
		$target     = self::privacy_rank( $privacy );

		if ( ! $folder_ids || $target < 0 ) {
			return 0;
		}

		// Nothing is looser than `public`, so counting a tightening to it is 0.
		if ( 0 === $target ) {
			return 0;
		}

		$looser = array_slice( self::PRIVACY_ORDER, 0, $target );

		$index = $wpdb->prefix . 'mvs_media_index';

		$folder_placeholders  = implode( ', ', array_fill( 0, count( $folder_ids ), '%d' ) );
		$privacy_placeholders = implode( ', ', array_fill( 0, count( $looser ), '%s' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index}
				  WHERE folder_id IN ({$folder_placeholders})
				    AND privacy IN ({$privacy_placeholders})
				    AND media_type = %s",
				...array_merge( $folder_ids, $looser, array( 'document' ) )
			)
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

		if ( $this->refuses_cpt_id( $media_id, __METHOD__ ) ) {
			return;
		}

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
			$old_privacy = null;
			if ( array_key_exists( 'privacy', $index_data ) ) {
				$old_privacy = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d",
						$media_id
					)
				);
			}

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

			if ( array_key_exists( 'privacy', $index_data ) && null !== $old_privacy && (string) $old_privacy !== (string) $index_data['privacy'] ) {
				/** This action is documented in MediaRepository::set(). */
				do_action( 'mvs_media_privacy_changed', $media_id, (string) $index_data['privacy'], (string) $old_privacy );
			}

			self::invalidate_row_cache( $media_id );
		}

		// Meta fields one by one (upsert). Each set() call self-invalidates.
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
			self::invalidate_row_cache( $media_id );
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

		self::invalidate_row_cache( $media_id );
	}

	/**
	 * Get all fields for a media item (index + meta merged).
	 *
	 * @param int $media_id Media ID.
	 * @return array All fields as key-value pairs.
	 */
	public function get_all( int $media_id ): array {
		// Serve from the per-request cache when prefetch() (or a prior get_all())
		// already loaded this media's full row. This is what lets a page call
		// prefetch() once and then render N tiles with zero per-tile queries.
		if ( isset( self::$row_cache[ $media_id ] ) && isset( self::$meta_fully_loaded[ $media_id ] ) ) {
			return self::$row_cache[ $media_id ];
		}

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

		// Prime the per-request cache so subsequent get()/get_raw() on this media
		// — within this tile and across the page — are free, and mark meta fully
		// loaded so absent-key reads short-circuit (see get_raw Tier 1b). Only
		// when the row actually exists; absent media stays uncached so a later
		// insert under the same id isn't masked.
		if ( ! empty( $data ) ) {
			if ( ! isset( self::$row_cache[ $media_id ] ) ) {
				self::$row_cache[ $media_id ] = array();
			}
			foreach ( $data as $col => $val ) {
				self::$row_cache[ $media_id ][ $col ] = $val;
			}
			self::$meta_fully_loaded[ $media_id ] = true;
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
		// A prefetched/cached index row means the media exists — skip the query.
		// can_view() calls exists() once per tile; without this, a prefetched
		// grid still fires one existence query per tile. (1.7.0)
		if ( isset( self::$row_cache[ $media_id ]['media_id'] ) ) {
			return true;
		}

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
	 * Count distinct media that have a given meta key (and optional value).
	 *
	 * Used by Pro's transcode admin queue for "how many videos have
	 * transcode_status = queued/processing/done". Joins mvs_media_index to
	 * skip orphaned meta rows where the index entry was deleted.
	 *
	 * @since 1.3.0
	 *
	 * @param string      $meta_key   Required meta key.
	 * @param string|null $meta_value Optional value. Null = match any value.
	 * @return int Distinct media count.
	 */
	public function count_by_meta( string $meta_key, ?string $meta_value = null ): int {
		global $wpdb;

		if ( null === $meta_value ) {
			return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT mi.media_id) FROM {$wpdb->prefix}mvs_media_index mi INNER JOIN {$wpdb->prefix}mvs_media_meta mm ON mi.media_id = mm.media_id WHERE mm.meta_key = %s",
					$meta_key
				)
			);
		}

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT mi.media_id) FROM {$wpdb->prefix}mvs_media_index mi INNER JOIN {$wpdb->prefix}mvs_media_meta mm ON mi.media_id = mm.media_id WHERE mm.meta_key = %s AND mm.meta_value = %s",
				$meta_key,
				$meta_value
			)
		);
	}

	/**
	 * Query media that have a given meta key (and optional value), with the
	 * small set of index columns Pro's transcode admin needs (id/title/author).
	 *
	 * Sister method of count_by_meta(). Same join + filter shape, paginated.
	 *
	 * @since 1.3.0
	 *
	 * @param string      $meta_key   Required meta key.
	 * @param string|null $meta_value Optional value. Null = match any value.
	 * @param int         $limit      Max rows.
	 * @param int         $offset     Pagination offset.
	 * @return array<int, array{media_id:int,title:string,post_author:int}>
	 */
	public function query_by_meta( string $meta_key, ?string $meta_value, int $limit, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, $limit );
		$offset = max( 0, $offset );

		if ( null === $meta_value ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT mi.media_id, mi.title, mi.post_author FROM {$wpdb->prefix}mvs_media_index mi INNER JOIN {$wpdb->prefix}mvs_media_meta mm ON mi.media_id = mm.media_id WHERE mm.meta_key = %s ORDER BY mi.media_id DESC LIMIT %d OFFSET %d",
					$meta_key,
					$limit,
					$offset
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT DISTINCT mi.media_id, mi.title, mi.post_author FROM {$wpdb->prefix}mvs_media_index mi INNER JOIN {$wpdb->prefix}mvs_media_meta mm ON mi.media_id = mm.media_id WHERE mm.meta_key = %s AND mm.meta_value = %s ORDER BY mi.media_id DESC LIMIT %d OFFSET %d",
					$meta_key,
					$meta_value,
					$limit,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Find public-privacy media rows for cloud operations (migrate / cleanup).
	 *
	 * Returns rows with the small set of columns CloudOps actually reads
	 * (media_id, file_path, file_url). Filters to status IN ('publish',
	 * 'draft') AND privacy = 'public'. Optional `local_url_only` flag adds a
	 * `file_url LIKE 'http%/wp-content/uploads/%'` filter for the migration
	 * scan (find media still pointing at local URLs); the cleanup scan omits
	 * it because cleanup wants all public media with local files regardless
	 * of where the public URL currently resolves.
	 *
	 * @since 1.3.0
	 *
	 * @param int  $limit          Max rows to return.
	 * @param bool $local_url_only When true, restrict to rows whose file_url
	 *                              still points at the local uploads dir.
	 * @return array<int, array{media_id:int,file_path:string,file_url:string}>
	 */
	public function query_public_cloud_candidates( int $limit, bool $local_url_only = false ): array {
		global $wpdb;

		$limit = max( 1, $limit );

		if ( $local_url_only ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, file_path, file_url FROM {$wpdb->prefix}mvs_media_index
					WHERE status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != '' AND privacy = 'public' AND file_url LIKE %s
					ORDER BY media_id ASC LIMIT %d",
					'http%/wp-content/uploads/%',
					$limit
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, file_path, file_url FROM {$wpdb->prefix}mvs_media_index
					WHERE status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != '' AND privacy = 'public'
					ORDER BY media_id ASC LIMIT %d",
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * How many rows `query_public_cloud_candidates()` would return, unlimited.
	 *
	 * The sibling that should have existed from the start. `CloudOps::count_candidates()`
	 * carried its own COUNT with the predicate copied out by hand, under a
	 * comment reading "Must match query_public_cloud_candidates( $limit, true )
	 * exactly" — which is a comment asking a human to keep two SQL strings in
	 * step, and the admin panel's progress bar is what breaks when they drift.
	 * It already had: the count kept a privacy filter the list did not, so the
	 * backlog never reached zero and the migrate button never went quiet.
	 *
	 * `$local_url_only` means the same thing here as there: rows whose public URL
	 * still points at the local uploads directory, i.e. the ones with work left.
	 * Its inverse (`false` for the total, then subtract) is deliberately NOT how
	 * the caller derives "already on cloud" — see the note in
	 * `CloudOps::count_candidates()` about the subtraction that double-counted
	 * private rows.
	 *
	 * @since 2.4.0
	 *
	 * @param bool $local_url_only Restrict to rows still pointing at local uploads.
	 * @param bool $invert         Count rows NOT pointing at local uploads instead.
	 * @return int
	 */
	public function count_public_cloud_candidates( bool $local_url_only = false, bool $invert = false ): int {
		global $wpdb;

		$where  = array( "status IN ('publish','draft')", 'file_path IS NOT NULL', "file_path != ''", 'privacy = %s' );
		$params = array( 'public' );

		if ( $local_url_only ) {
			$where[]  = $invert ? 'file_url NOT LIKE %s' : 'file_url LIKE %s';
			$params[] = 'http%/wp-content/uploads/%';
		}

		$sql = "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE " . implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
	}

	/**
	 * Run the REST feed query — ids for one page, plus the total.
	 *
	 * THE ONE METHOD HERE THAT TAKES SQL FRAGMENTS, and it is worth saying why
	 * rather than leaving it looking like a lapse. `MediaController` assembles
	 * `$where` / `$params` and then hands them to the **public filter**
	 * `mvs_feed_query_args`, documented since 1.1.0, which lets a site or Pro
	 * add its own predicates. Those fragments ARE the published contract;
	 * re-expressing the feed as named arguments would break every integration
	 * using it, which Production Rules 1 and 3 do not allow.
	 *
	 * So the fragments stay with the caller and the EXECUTION moves here: the
	 * table name, the stats join, the trending formula, the pagination and the
	 * prepare. That is the part Rule 7 is actually protecting — the controller
	 * no longer names `mvs_media_index`, and a change to how this table is read
	 * happens in one file.
	 *
	 * `$orderby` is matched against fixed branches, never interpolated. Every
	 * caller value is bound.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type string[] $where    WHERE fragments, already filtered.
	 *     @type array    $params   Bound parameters for those fragments.
	 *     @type string   $join     Extra JOIN fragments, or ''.
	 *     @type string   $orderby  date|trending|popular.
	 *     @type int      $per_page Page size.
	 *     @type int      $offset   Page offset.
	 * }
	 * @return array{ids: int[], total: int}
	 */
	public function feed_page( array $args ): array {
		global $wpdb;

		$where    = isset( $args['where'] ) && is_array( $args['where'] ) ? $args['where'] : array( '1=1' );
		$params   = isset( $args['params'] ) && is_array( $args['params'] ) ? $args['params'] : array();
		$join     = isset( $args['join'] ) ? (string) $args['join'] : '';
		$orderby  = isset( $args['orderby'] ) ? (string) $args['orderby'] : '';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$where_sql = $where ? implode( ' AND ', $where ) : '1=1';
		$index     = $wpdb->prefix . 'mvs_media_index';
		$stats     = $wpdb->prefix . 'mvs_media_stats';

		// The COUNT deliberately does NOT join stats — it does not need them,
		// and the join would change the row count if a media ever had more than
		// one stats row.
		$count_sql = "SELECT COUNT(*) FROM {$index} i{$join} WHERE {$where_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( $count_sql, ...$params ) : $count_sql );

		$page_params   = $params;
		$page_params[] = $per_page;
		$page_params[] = $offset;

		if ( 'trending' === $orderby ) {
			// (reactions * 3 + comments * 5 + views) / age_hours^1.5
			$data_sql = "SELECT i.media_id,
				((COALESCE(s.reactions, 0) * 3 + COALESCE(s.comments, 0) * 5 + COALESCE(s.views, 0))
				/ POWER(GREATEST(TIMESTAMPDIFF(HOUR, i.created_at, NOW()), 1), 1.5)) AS trending_score
				FROM {$index} i
				LEFT JOIN {$stats} s ON i.media_id = s.media_id{$join}
				WHERE {$where_sql}
				ORDER BY trending_score DESC
				LIMIT %d OFFSET %d";
		} elseif ( 'popular' === $orderby ) {
			$data_sql = "SELECT i.media_id
				FROM {$index} i
				LEFT JOIN {$stats} s ON i.media_id = s.media_id{$join}
				WHERE {$where_sql}
				ORDER BY COALESCE(s.views, 0) DESC
				LIMIT %d OFFSET %d";
		} else {
			$data_sql = "SELECT i.media_id FROM {$index} i{$join} WHERE {$where_sql} ORDER BY i.created_at DESC LIMIT %d OFFSET %d";
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = (array) $wpdb->get_col( $wpdb->prepare( $data_sql, ...$page_params ) );

		return array(
			'ids'   => array_map( 'intval', $ids ),
			'total' => $total,
		);
	}

	/**
	 * Aggregate row count + total bytes per file_type for a user.
	 *
	 * Used by Pro's QuotaService.recalculate_usage(). Single GROUP BY that
	 * returns one row per mime type with `cnt` and `total_size`. The caller
	 * maps file_type -> high-level bucket (image/video/audio).
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id Author ID.
	 * @return array<int, array{file_type:string,cnt:int,total_size:int}>
	 */
	public function aggregate_usage_by_author( int $user_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT file_type, COUNT(*) AS cnt, COALESCE(SUM(file_size), 0) AS total_size
				FROM {$wpdb->prefix}mvs_media_index
				WHERE post_author = %d
				GROUP BY file_type",
				$user_id
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Query the most recent published media rows since a given datetime.
	 *
	 * Returns full mvs_media_index rows in created_at DESC order. Used by
	 * stories-bar templates and other "recent activity" surfaces.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $limit          Max rows to return. Default 20.
	 * @param string $since_datetime Optional ISO datetime ("Y-m-d H:i:s")
	 *                               cutoff. Default '' = no cutoff.
	 * @return array<int, array> Numerically-indexed list of media rows.
	 */
	public function query_recent( int $limit = 20, string $since_datetime = '' ): array {
		return $this->query(
			array(
				'status'  => 'publish',
				'since'   => $since_datetime,
				'limit'   => $limit,
				'offset'  => 0,
				'privacy' => 'any',
			)
		);
	}

	/**
	 * Get media IDs belonging to a gallery group, ordered by group_position.
	 *
	 * Sister method to `count_by_group()`. The gallery group is identified by
	 * the `media_group` meta value (a generated key shared across all items
	 * in one gallery upload). Group items are ordered by the `group_position`
	 * meta cast to UNSIGNED so positions sort numerically rather than
	 * lexically. Status filter ensures only published items appear in the
	 * gallery expansion.
	 *
	 * @since 1.3.0
	 *
	 * @param string $media_group Group key (from `media_group` meta).
	 * @param string $status      Status filter on mvs_media_index. Default 'publish'.
	 * @return array<int> Media IDs in group-position order.
	 */
	public function get_group_media_ids( string $media_group, string $status = 'publish' ): array {
		global $wpdb;

		if ( '' === $media_group ) {
			return array();
		}

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT mm1.media_id FROM {$wpdb->prefix}mvs_media_meta mm1
				INNER JOIN {$wpdb->prefix}mvs_media_meta mm2 ON mm1.media_id = mm2.media_id AND mm2.meta_key = 'group_position'
				INNER JOIN {$wpdb->prefix}mvs_media_index mi ON mm1.media_id = mi.media_id AND mi.status = %s
				WHERE mm1.meta_key = 'media_group' AND mm1.meta_value = %s
				ORDER BY CAST(mm2.meta_value AS UNSIGNED) ASC",
				$status,
				$media_group
			)
		);

		return array_map( 'absint', (array) $rows );
	}

	/**
	 * Query media rows filtered by author, with optional status + moderation
	 * + pagination. Returns full mvs_media_index rows in created_at DESC order.
	 *
	 * Used by profile/feed templates that previously did direct $wpdb against
	 * the index table. Centralizes the "show me this user's published feed"
	 * query so per-template direct $wpdb calls go away.
	 *
	 * @since 1.3.0
	 *
	 * @param int   $user_id Author user ID.
	 * @param array $args    {
	 *     @type string $status            Status filter. Default 'publish'.
	 *                                     Pass '' to skip the filter.
	 *     @type string $moderation_status Moderation status filter. Default ''
	 *                                     (no filter). Pass 'approved' to
	 *                                     hide flagged/pending items.
	 *     @type int    $limit             Max rows. Default 20.
	 *     @type int    $offset            Pagination offset. Default 0.
	 * }
	 * @return array<int, array> Numerically-indexed list of media rows.
	 */
	public function query_by_author( int $user_id, array $args = array() ): array {
		$args = wp_parse_args(
			$args,
			array(
				'status'            => 'publish',
				'moderation_status' => '',
				'limit'             => 20,
				'offset'            => 0,
				'viewer_id'         => null,  // null => get_current_user_id()
				'include_private'   => false, // owner/admin opt-in to see ALL
			)
		);

		$viewer = null === $args['viewer_id']
			? get_current_user_id()
			: (int) $args['viewer_id'];

		// Privacy mode selection. 1.3.0 closed the private-leak case
		// (Basecamp #9936622656) with 'hide_private'; 1.6.0 tightens it to
		// the viewer-aware 'profile' mode because members/friends-level items
		// were still listed to viewers outside those audiences — including
		// logged-out visitors (Basecamp #9941246549). Owner / admin /
		// explicit-opt-in callers still see everything; sites can restore the
		// old discoverability via the `mvs_profile_privacy_levels` filter.
		$privacy = $this->resolve_profile_privacy_mode( $user_id, $viewer, ! empty( $args['include_private'] ) );

		return $this->query(
			array(
				'author_id'         => $user_id,
				'status'            => (string) $args['status'],
				'moderation_status' => (string) $args['moderation_status'],
				'limit'             => (int) $args['limit'],
				'offset'            => (int) $args['offset'],
				'privacy'           => $privacy,
				'viewer_id'         => $viewer,
			)
		);
	}

	/**
	 * Pick the privacy mode for a single-author profile listing.
	 *
	 * Owner / admin / explicit opt-in see everything; everyone else gets the
	 * viewer-aware 'profile' gate. Shared by query_by_author() and
	 * count_visible_by_author() so listing and pagination count always agree.
	 *
	 * @param int  $author_id       Listing author.
	 * @param int  $viewer          Current viewer (0 = anonymous).
	 * @param bool $include_private Caller opt-in to bypass the gate.
	 * @return string Privacy mode for query()/query_count().
	 */
	private function resolve_profile_privacy_mode( int $author_id, int $viewer, bool $include_private = false ): string {
		$is_owner_self = ( $author_id === $viewer ) && $viewer > 0;
		$is_admin      = $viewer > 0 && user_can( $viewer, 'moderate_mvs_media' );

		return ( $is_owner_self || $include_private || $is_admin ) ? 'any' : 'profile';
	}

	/**
	 * Count media visible to a viewer on an author's profile listing.
	 *
	 * Mirrors query_by_author()'s privacy-mode selection so profile tabs
	 * count exactly the rows they list (Basecamp #9941246549 — the BP
	 * profile media tab previously counted/listed members-only items for
	 * logged-out visitors via its own raw SQL).
	 *
	 * @since 1.6.0
	 *
	 * @param int      $user_id   Author user ID.
	 * @param int|null $viewer_id Viewer user ID. Null = current user.
	 * @return int
	 */
	public function count_visible_by_author( int $user_id, ?int $viewer_id = null ): int {
		$viewer = null === $viewer_id ? get_current_user_id() : (int) $viewer_id;

		return $this->query_count(
			array(
				'author_id' => $user_id,
				'status'    => 'publish',
				'privacy'   => $this->resolve_profile_privacy_mode( $user_id, $viewer ),
				'viewer_id' => $viewer,
			)
		);
	}

	/**
	 * ORDER BY columns query() / query_count() will accept.
	 *
	 * This allowlist is the SQL-injection guard: `orderby` is interpolated
	 * into the statement (it cannot be a prepare placeholder), so any value
	 * outside this set is rejected and falls back to `created_at`.
	 *
	 * @var array<string>
	 */
	private const QUERY_ORDERBY_ALLOWED = array( 'created_at', 'media_id', 'title', 'reaction_count' );

	/**
	 * General media-index listing query — the single place feed/profile/explore
	 * listing SQL is built.
	 *
	 * Replaces hand-written $wpdb in `templates/explore.php` and the Pro feed /
	 * profile templates. Returns full `mvs_media_index` rows (same shape as
	 * `get_batch()`), numerically indexed in `orderby`/`order` sequence.
	 *
	 * @since 1.4.0
	 *
	 * @param array $args Filter set — see {@see normalize_query_args()} for keys
	 *                    and defaults.
	 * @return array<int, array> Media rows.
	 */
	public function query( array $args = array() ): array {
		global $wpdb;

		$args  = $this->normalize_query_args( $args );
		$parts = $this->build_query_parts( $args );

		$orderby = in_array( $args['orderby'], self::QUERY_ORDERBY_ALLOWED, true ) ? $args['orderby'] : 'created_at';
		$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

		$params   = $parts['params'];
		$params[] = max( 1, (int) $args['limit'] );
		$params[] = max( 0, (int) $args['offset'] );

		// $parts['join']/['where'] and $orderby/$order are built from internal
		// allowlists + fixed fragments; all caller values flow through $params.
		$sql = "SELECT m.* FROM {$wpdb->prefix}mvs_media_index m {$parts['join']} WHERE {$parts['where']} ORDER BY m.{$orderby} {$order} LIMIT %d OFFSET %d";

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( $sql, ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Row count for the same filter set as {@see query()}.
	 *
	 * Uses COUNT(DISTINCT m.media_id) when a taxonomy join is present (a media
	 * can match multiple term rows) and COUNT(*) otherwise.
	 *
	 * @since 1.4.0
	 *
	 * @param array $args Same args as query().
	 * @return int Matching media count.
	 */
	public function query_count( array $args = array() ): int {
		global $wpdb;

		$args  = $this->normalize_query_args( $args );
		$parts = $this->build_query_parts( $args );

		$count_expr = $parts['distinct'] ? 'COUNT(DISTINCT m.media_id)' : 'COUNT(*)';

		$sql = "SELECT {$count_expr} FROM {$wpdb->prefix}mvs_media_index m {$parts['join']} WHERE {$parts['where']}";

		if ( ! empty( $parts['params'] ) ) {
			$sql = $wpdb->prepare( $sql, ...$parts['params'] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Apply defaults to a query()/query_count() args array.
	 *
	 * @param array $args Caller args.
	 * @return array {
	 *     @type string $status                  Index status. Default 'publish'. '' skips.
	 *     @type string $moderation_status        Default '' (skip). Pass 'approved' to hide flagged/pending.
	 *     @type int    $author_id                Default 0 (skip).
	 *     @type string $search                   LIKE on title/description. Default ''.
	 *     @type int    $tag_tt_id                term_taxonomy_id for a tag join. Default 0.
	 *     @type int    $category_tt_id           term_taxonomy_id for a category join. Default 0.
	 *     @type string $privacy                  'any'|'public'|'visible'|'profile'. Default 'any'.
	 *     @type int    $viewer_id                Required when privacy='visible'/'profile'. Default 0.
	 *     @type bool   $exclude_non_cover_group  Drop non-cover gallery members. Default false.
	 *     @type bool   $exclude_empty_media_type Drop privacy-only stub rows (albums/collections). Default false.
	 *     @type string[] $media_types              Library this listing is for. Default MediaTypes::MEDIA_LIBRARY.
	 *     @type string $since                    created_at >= this datetime. Default ''.
	 *     @type string $orderby                  Allowlisted column. Default 'created_at'.
	 *     @type string $order                    'ASC'|'DESC'. Default 'DESC'.
	 *     @type int    $limit                    Default 20.
	 *     @type int    $offset                   Default 0.
	 * }
	 */
	private function normalize_query_args( array $args ): array {
		return wp_parse_args(
			$args,
			array(
				'status'                   => 'publish',
				'moderation_status'        => '',
				'author_id'                => 0,
				'search'                   => '',
				'tag_tt_id'                => 0,
				'category_tt_id'           => 0,
				'privacy'                  => 'any',
				'viewer_id'                => 0,
				'exclude_non_cover_group'  => false,
				'exclude_empty_media_type' => false,
				'media_types'              => MediaTypes::MEDIA_LIBRARY,
				// ── Maintenance-walk args (2.4.0) ─────────────────────────────
				//
				// Added so the CLI and the storage services stop hand-writing
				// `SELECT ... FROM mvs_media_index WHERE ...` (architecture
				// invariant 6 / Rule 7). Eleven of those call sites were the same
				// query with different columns: "walk the table in media_id order,
				// optionally narrowed by type, status, privacy or whether the row
				// has a file at all". Expressed here once, they become arguments
				// rather than SQL, and every one of them inherits the type
				// predicate, the privacy handling and the prepare discipline this
				// builder already gets right.
				//
				// `id_after` is a KEYSET cursor, not an offset. A batch walk that
				// pages with OFFSET re-scans everything it has already read and,
				// worse, skips rows whenever the set shifts underneath it — which
				// it does, because these commands are usually writing to the rows
				// they are walking.
				'id_after'                 => 0,
				'media_ids'                => array(),
				'status_in'                => array(),
				// The NEGATION, and it exists rather than being expressed as a
				// positive list on purpose. `relocalize-private` wants "every
				// privacy level except public"; spelled positively that list has
				// to track the privacy vocabulary forever, and the first value it
				// fell behind on would be silently skipped rather than reported.
				// `dm` is already such a value — deliberately absent from
				// PRIVACY_ORDER because it is a messaging scope, not a position
				// on the scale — so a positive list built from that constant
				// would strand exactly the media nobody is watching.
				'privacy_not_in'           => array(),
				// EXACT mime match, ANDed with `mime_like_in` rather than
				// replacing it. `optimize-bulk` narrows within images: the LIKE
				// establishes "an image", the IN narrows to the mimes the
				// operator named. Folding them into one arg would turn an AND
				// into an OR, and `--mime=video/mp4` would start optimising
				// videos through the image pipeline instead of correctly
				// matching nothing.
				'file_types_in'            => array(),
				// null = do not care. TRUE = the row has a stored file, which is
				// what every storage command means by "a row worth looking at";
				// FALSE is accepted for symmetry and finds index rows whose file
				// went missing.
				'has_file'                 => null,
				'authors_in'               => array(),
				'privacy_in'               => array(),
				'mime_like_in'             => array(),
				'tax_terms'                => array(),
				'since'                    => '',
				'until'                    => '',
				'orderby'                  => 'created_at',
				'order'                    => 'DESC',
				'limit'                    => 20,
				'offset'                   => 0,
			)
		);
	}

	/**
	 * Assemble the JOIN + WHERE + bound params for query()/query_count().
	 *
	 * Pure: builds strings and a params array, runs no query. Every
	 * caller-supplied value becomes a prepare placeholder; only fixed SQL
	 * fragments and the privacy literals are interpolated.
	 *
	 * @param array $args Normalized args.
	 * @return array{join:string,where:string,params:array,distinct:bool}
	 */
	/**
	 * Build an OR-set predicate for one column: `(col op %x OR col op %x …)`.
	 *
	 * @since 2.4.0
	 *
	 * @param string $column      Qualified column.
	 * @param string $placeholder '%d' or '%s'.
	 * @param mixed  $values      Values; non-arrays and empties yield no clause.
	 * @param string $operator    Comparison operator, '=' or 'LIKE'.
	 * @return array{0:string,1:array} Fragment (empty when nothing to add) and params.
	 */
	private function or_set( string $column, string $placeholder, $values, string $operator = '=' ): array {
		$values = array_values(
			array_filter(
				(array) $values,
				static function ( $v ) {
					return '' !== $v && null !== $v;
				}
			)
		);

		if ( empty( $values ) ) {
			return array( '', array() );
		}

		$operator = 'LIKE' === strtoupper( $operator ) ? 'LIKE' : '=';
		$ors      = array_fill( 0, count( $values ), "{$column} {$operator} {$placeholder}" );

		if ( '%d' === $placeholder ) {
			$values = array_map( 'intval', $values );
		}

		return array( '(' . implode( ' OR ', $ors ) . ')', $values );
	}

	private function build_query_parts( array $args ): array {
		global $wpdb;

		$join        = '';
		$join_params = array();
		$where       = array();
		$params      = array();
		$distinct    = false;

		// `status_in` REPLACES `status` rather than joining it. `status` defaults
		// to 'publish', so ANDing the two would turn a request for
		// publish-or-draft into publish-only — the maintenance commands would
		// silently skip every draft and report success having walked half the
		// table. Asserted by MediaQueryWalkArgsTest.
		$mvs_status_in = array_values( array_filter( array_map( 'strval', (array) $args['status_in'] ) ) );

		if ( ! $mvs_status_in && '' !== (string) $args['status'] ) {
			$where[]  = 'm.status = %s';
			$params[] = (string) $args['status'];
		}

		if ( '' !== (string) $args['moderation_status'] ) {
			$where[]  = 'm.moderation_status = %s';
			$params[] = (string) $args['moderation_status'];
		}

		if ( (int) $args['author_id'] > 0 ) {
			$where[]  = 'm.post_author = %d';
			$params[] = (int) $args['author_id'];
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(m.title LIKE %s OR m.description LIKE %s)';
			$params[] = $like;
			$params[] = $like;
		}

		if ( (int) $args['tag_tt_id'] > 0 ) {
			$join    .= " INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = m.media_id";
			$where[]  = 'tr.term_taxonomy_id = %d';
			$params[] = (int) $args['tag_tt_id'];
			$distinct = true;
		}

		if ( (int) $args['category_tt_id'] > 0 ) {
			$join    .= " INNER JOIN {$wpdb->term_relationships} trc ON trc.object_id = m.media_id";
			$where[]  = 'trc.term_taxonomy_id = %d';
			$params[] = (int) $args['category_tt_id'];
			$distinct = true;
		}

		// ── Multi-value (OR-within-key) filters ───────────────────────────────
		//
		// Smart collections group rule values by key and combine SAME-key values
		// with OR, different keys with AND. A flat AND made same-key rules
		// mutually exclusive — two media_type rules became `file_type LIKE
		// '%image%' AND file_type LIKE '%video%'`, which no single file can
		// satisfy, so the collection resolved to zero and rendered the broken
		// placeholder cover (#9962118482). These args carry that semantic, so the
		// collection service no longer needs SQL of its own to express it.
		list( $or_where, $or_params ) = $this->or_set( 'm.post_author', '%d', $args['authors_in'] );
		if ( '' !== $or_where ) {
			$where[] = $or_where;
			$params  = array_merge( $params, $or_params );
		}

		// ── Maintenance-walk predicates ───────────────────────────────────────
		if ( (int) $args['id_after'] > 0 ) {
			$where[]  = 'm.media_id > %d';
			$params[] = (int) $args['id_after'];
		}

		$mvs_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $args['media_ids'] ) ) ) );
		if ( $mvs_ids ) {
			$where[] = 'm.media_id IN (' . implode( ',', array_fill( 0, count( $mvs_ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $mvs_ids );
		}

		list( $or_where, $or_params ) = $this->or_set( 'm.status', '%s', $mvs_status_in );
		if ( '' !== $or_where ) {
			$where[] = $or_where;
			$params  = array_merge( $params, $or_params );
		}

		if ( null !== $args['has_file'] ) {
			// Both halves are needed and neither is redundant: the column is
			// nullable AND legacy rows carry the empty string. Testing only NULL
			// hands a caller rows with no file to act on, which for the storage
			// commands means a migrate loop that reports work it did not do.
			$where[] = $args['has_file']
				? "( m.file_path IS NOT NULL AND m.file_path != '' )"
				: "( m.file_path IS NULL OR m.file_path = '' )";
		}

		$mvs_privacy_not = array_values( array_filter( array_map( 'strval', (array) $args['privacy_not_in'] ) ) );
		if ( $mvs_privacy_not ) {
			$where[] = 'm.privacy NOT IN (' . implode( ', ', array_fill( 0, count( $mvs_privacy_not ), '%s' ) ) . ')';
			$params  = array_merge( $params, $mvs_privacy_not );
		}

		list( $or_where, $or_params ) = $this->or_set( 'm.privacy', '%s', $args['privacy_in'] );
		if ( '' !== $or_where ) {
			$where[] = $or_where;
			$params  = array_merge( $params, $or_params );
		}

		list( $or_where, $or_params ) = $this->or_set( 'm.file_type', '%s', $args['mime_like_in'], 'LIKE' );
		if ( '' !== $or_where ) {
			$where[] = $or_where;
			$params  = array_merge( $params, $or_params );
		}

		list( $or_where, $or_params ) = $this->or_set( 'm.file_type', '%s', $args['file_types_in'] );
		if ( '' !== $or_where ) {
			$where[] = $or_where;
			$params  = array_merge( $params, $or_params );
		}

		// Taxonomy sets: TERM IDs within one taxonomy, OR'd. Each taxonomy gets its
		// own aliased pair of joins so different taxonomies AND together.
		//
		// This joins term_taxonomy and matches `tt.term_id`, NOT
		// `tr.term_taxonomy_id`. The two are different columns and are only equal
		// by coincidence on simple installs — comparing a term_id against a
		// term_taxonomy_id silently returns the wrong rows. The `tt.taxonomy`
		// constraint matters for the same reason: without it a tag and a category
		// that happen to share a term_id cross-match.
		$tax_idx = 0;
		foreach ( (array) $args['tax_terms'] as $set ) {
			$taxonomy = isset( $set['taxonomy'] ) ? (string) $set['taxonomy'] : '';
			$term_ids = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $set['term_ids'] ?? array() ) ) ) ) );

			if ( '' === $taxonomy || empty( $term_ids ) ) {
				continue;
			}

			$tr_alias = 'trx' . $tax_idx;
			$tt_alias = 'ttx' . $tax_idx;
			$ph       = implode( ',', array_fill( 0, count( $term_ids ), '%d' ) );

			$join .= " INNER JOIN {$wpdb->term_relationships} AS {$tr_alias} ON {$tr_alias}.object_id = m.media_id";
			$join .= " INNER JOIN {$wpdb->term_taxonomy} AS {$tt_alias} ON {$tt_alias}.term_taxonomy_id = {$tr_alias}.term_taxonomy_id AND {$tt_alias}.taxonomy = %s AND {$tt_alias}.term_id IN ({$ph})";

			// Join params precede WHERE params in the final SQL, so they are held
			// separately and merged join-first below.
			$join_params[] = $taxonomy;
			$join_params   = array_merge( $join_params, $term_ids );

			$distinct = true;
			++$tax_idx;
		}

		if ( '' !== (string) $args['until'] ) {
			$where[]  = 'm.created_at <= %s';
			$params[] = (string) $args['until'];
		}

		list( $privacy_where, $privacy_params ) = $this->build_privacy_where( (string) $args['privacy'], (int) $args['viewer_id'], (int) $args['author_id'] );
		if ( '' !== $privacy_where ) {
			$where[] = $privacy_where;
			$params  = array_merge( $params, $privacy_params );
		}

		if ( '' !== (string) $args['since'] ) {
			$where[]  = 'm.created_at >= %s';
			$params[] = (string) $args['since'];
		}

		if ( ! empty( $args['exclude_non_cover_group'] ) ) {
			$where[] = 'm.media_id NOT IN (' . $this->gallery_exclude_subquery() . ')';
		}

		// Every listing this repository serves — explore, the BuddyPress profile
		// and group tabs, the Pro layout feeds — states the library it is for.
		// This is the one place that has to be right: query(), query_count() and
		// query_by_author() all funnel through here, and only ONE caller
		// (explore.php) ever passed the old exclude_empty_media_type flag, so
		// every other listing ran with no type predicate at all.
		//
		// That flag was `m.media_type != ''`, an exclusion written to drop the
		// privacy-only stub rows album creation used to insert. It is superseded
		// twice over: those stubs no longer exist (2.4.0 stopped albums writing to
		// the index and Migrator v26 removed the rows), and an exclusion passes
		// every type added later — including documents — straight through.
		//
		// The arg is still accepted and still does what its name says, because it
		// is public API on the repository interface (Production Rule 2). It is now
		// simply redundant: a positive list already excludes the empty string.
		// EXPLICIT null = no type predicate at all. Reserved for whole-table
		// maintenance walks and deliberately not the same thing as an empty
		// array, which still means "match nothing".
		//
		// This is not a reopening of the fail-open hole the comment above
		// describes. That hole was an OMISSION — listings that passed no types
		// and silently matched everything, so documents surfaced in photo grids.
		// This has to be asked for by name, and the reason it exists is
		// `wp mvs reindex`, whose entire job is to find rows that are wrong. A
		// row whose `media_type` is corrupt or empty is precisely what it must
		// see, and a positive IN-list is exactly what would hide it. An
		// integrity command that skips the broken rows reports a clean table.
		$mvs_types = $args['media_types'];

		if ( null !== $mvs_types ) {
			if ( ! is_array( $mvs_types ) ) {
				$mvs_types = MediaTypes::MEDIA_LIBRARY;
			}

			list( $mvs_type_sql, $mvs_type_params ) = MediaTypes::in_clause( $mvs_types, 'm.media_type' );
			$where[]                                = $mvs_type_sql;
			$params                                 = array_merge( $params, $mvs_type_params );
		}

		// No `1 = 1` fallback below this point: the type clause above is
		// unconditional, so $where can no longer be empty. in_clause() also
		// guarantees a real predicate — an empty type set yields `1 = 0`, which
		// matches nothing rather than everything.
		// Join placeholders appear BEFORE where placeholders in the assembled SQL,
		// so their params must lead. Getting this backwards misaligns every value
		// after the first join param — the same class of bug the collection
		// service's own comment records from when it built this by hand.
		return array(
			'join'     => $join,
			'where'    => implode( ' AND ', $where ),
			'params'   => array_merge( $join_params, $params ),
			'distinct' => $distinct,
		);
	}

	/**
	 * The single source of truth for the media-index privacy WHERE fragment.
	 *
	 * Previously copy-pasted across explore + 3 Pro feed templates. Centralized
	 * here so a privacy-policy change happens in exactly one place. Lives in the
	 * Repository (never up-calls PrivacyService — that would invert the layer
	 * order).
	 *
	 * @param string $mode      'any' (no filter), 'public' (anon), 'visible'
	 *                          (logged-in non-moderator: public + members + own),
	 *                          or 'profile' (single-author listing, per-viewer levels).
	 * @param int    $viewer_id Current viewer, used by 'visible' and 'profile'.
	 * @param int    $author_id Listing author, used only by 'profile' (friendship check).
	 * @return array{0:string,1:array} [ where-fragment, params ].
	 */
	private function build_privacy_where( string $mode, int $viewer_id, int $author_id = 0 ): array {
		switch ( $mode ) {
			case 'public':
				return array( "m.privacy = 'public'", array() );
			case 'explore':
				// The explore/feed-layout rule, which is NOT the same as 'visible':
				// it grants moderators everything, and it does not exclude the
				// viewer's own `dm` attachments. Both differences are load-bearing,
				// so the Pro layouts get this mode rather than being quietly
				// remapped onto 'visible' and narrowing what a moderator sees.
				return $this->explore_privacy_clause( 'm', $viewer_id );
			case 'visible':
				// Owner sees their own media EXCEPT conversation-scoped 'dm'
				// attachments, which never belong in any library/grid listing.
				return array(
					"(m.privacy = 'public' OR m.privacy = 'members' OR ( m.post_author = %d AND m.privacy != 'dm' ))",
					array( $viewer_id ),
				);
			case 'profile':
				// Single-author (profile) listing — per-viewer privacy levels.
				// Members- and friends-level items must NOT be listed for
				// viewers outside those audiences (Basecamp #9941246549:
				// members-only media rendered for logged-out visitors).
				// `group` / `custom` need per-item membership checks, so they
				// stay owner-only in listings. Owner/admin callers get 'any'
				// upstream (query_by_author), so this branch is non-owner only.
				$levels = array( 'public' );
				if ( $viewer_id > 0 ) {
					$levels[] = 'members';
					$levels[] = 'loggedin';
					if (
						$author_id > 0
						&& function_exists( 'friends_check_friendship' )
						&& friends_check_friendship( $author_id, $viewer_id )
					) {
						$levels[] = 'friends';
					}
				}

				/**
				 * Filters the privacy levels a viewer may see in a single-author
				 * profile listing.
				 *
				 * Restore the pre-1.6.0 "everything except private" discoverability:
				 * add_filter( 'mvs_profile_privacy_levels', function () {
				 *     return array( 'public', 'members', 'loggedin', 'friends', 'group', 'custom' );
				 * } );
				 *
				 * @since 1.6.0
				 *
				 * @param string[] $levels    Allowed privacy slugs.
				 * @param int      $author_id Profile owner user ID.
				 * @param int      $viewer_id Current viewer user ID (0 = anonymous).
				 */
				$levels = (array) apply_filters( 'mvs_profile_privacy_levels', $levels, $author_id, $viewer_id );

				$placeholders = implode( ',', array_fill( 0, count( $levels ), '%s' ) );
				if ( $viewer_id > 0 ) {
					return array(
						"(m.privacy IN ({$placeholders}) OR ( m.post_author = %d AND m.privacy != 'dm' ))",
						array_merge( array_values( $levels ), array( $viewer_id ) ),
					);
				}
				return array( "m.privacy IN ({$placeholders})", array_values( $levels ) );
			case 'hide_private':
				// DEPRECATED since 1.6.0 — superseded by 'profile' (this mode
				// listed members/friends items to any visitor, Basecamp
				// #9941246549). Kept because mode strings reach the public
				// query()/query_count() args surface (Production Rule #2).
				return array(
					"((m.privacy != 'private' OR m.post_author = %d) AND m.privacy != 'dm')",
					array( $viewer_id ),
				);
			case 'any':
			default:
				// 'any' applies no audience filter (owner-self profile, admin /
				// moderation grids), but conversation-scoped 'dm' attachments are
				// never library media and must stay out of every listing.
				return array( "m.privacy != 'dm'", array() );
		}
	}

	/**
	 * Resolve the privacy mode for a SITE-WIDE explore listing, matching the
	 * canonical gate in MediaController::get_items (the REST /media endpoint):
	 * anonymous → public only; logged-in non-moderator → public + members +
	 * own; moderator → everything. Callers pass the result as the `privacy`
	 * arg to query()/query_count() (plus `moderation_status => 'approved'`),
	 * so server-rendered explore surfaces show exactly what the REST feed and
	 * its Load More return — no SSR-vs-REST divergence that leaks private or
	 * unmoderated media (audit 2026-06-04).
	 *
	 * @since 1.6.0
	 *
	 * @param int $viewer_id Current viewer (0 = anonymous).
	 * @return string 'public' | 'visible' | 'any'
	 */
	public function resolve_explore_privacy_mode( int $viewer_id ): string {
		if ( ! $viewer_id ) {
			return 'public';
		}
		if ( user_can( $viewer_id, 'moderate_mvs_media' ) ) {
			return 'any';
		}
		return 'visible';
	}

	/**
	 * The same explore gate as resolve_explore_privacy_mode(), but as a raw
	 * SQL fragment for callers that can't route through query() because they
	 * join extra tables (story meta, Pro feed layouts). Returns
	 * [ where_fragment, params ] using the supplied column alias.
	 *
	 * Mirrors build_privacy_where()'s 'public'/'visible' cases; kept in lockstep
	 * with resolve_explore_privacy_mode() so every explore surface honours one
	 * rule. `moderation_status = 'approved'` is the caller's responsibility
	 * (it varies by table alias).
	 *
	 * @since 1.6.0
	 *
	 * @param string $alias     Table alias holding the privacy + post_author columns (e.g. 'idx').
	 * @param int    $viewer_id Current viewer (0 = anonymous).
	 * @return array{0:string,1:array} [ SQL fragment (no leading AND), bound params ].
	 */
	/**
	 * One page of the admin All-Media list, its total, and the status tab counts.
	 *
	 * Its own method because `query()` cannot express this screen, and forcing it
	 * to would bend a member-facing listing into an admin one:
	 *
	 * - `query()`'s `privacy` argument is a MODE (`any`, `profile`, …), not a
	 *   column filter, so it cannot answer "privacy = members".
	 * - the screen's default is `status != 'trash'`, which is neither "any
	 *   status" nor "one status".
	 * - the tab counts are deliberately NOT narrowed by search, privacy or
	 *   status — they are global per-status totals, the way WP's own post-list
	 *   status links stay global while the table below them is filtered — but
	 *   they ARE narrowed by type, because tabs that count a library the screen
	 *   never renders would advertise "Published (100)" over a table that can
	 *   only show 70.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type string $search   Title/description LIKE.
	 *     @type string $type     One known media type; '' means the media library.
	 *     @type string $status   Exact status; '' means everything except trash.
	 *     @type string $privacy  Exact privacy level; '' means any.
	 *     @type int    $per_page Page size.
	 *     @type int    $offset   Offset.
	 * }
	 * @return array{items:array<int,array<string,mixed>>, total:int, status_counts:array<string,int>}
	 */
	public function admin_media_list( array $args ): array {
		global $wpdb;

		$search   = (string) ( $args['search'] ?? '' );
		$type     = (string) ( $args['type'] ?? '' );
		$status   = (string) ( $args['status'] ?? '' );
		$privacy  = (string) ( $args['privacy'] ?? '' );
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$offset   = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$table  = $wpdb->prefix . 'mvs_media_index';
		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $search ) {
			$where[]  = '(title LIKE %s OR description LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = $like;
			$params[] = $like;
		}

		// An explicit type filter wins — that is how an owner asks this screen for
		// one library. With no filter chosen it shows the MEDIA library, because
		// that is what "All Media" means; documents get their own screen, where
		// the columns suit them.
		if ( '' !== $type && \WPMediaVerse\Core\MediaTypes::is_known( $type ) ) {
			$type_sql    = 'media_type = %s';
			$type_params = array( $type );
		} else {
			list( $type_sql, $type_params ) = \WPMediaVerse\Core\MediaTypes::in_clause( \WPMediaVerse\Core\MediaTypes::MEDIA_LIBRARY );
		}

		$where[] = $type_sql;
		$params  = array_merge( $params, $type_params );

		if ( '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		} else {
			$where[] = "status != 'trash'";
		}

		if ( '' !== $privacy ) {
			$where[]  = 'privacy = %s';
			$params[] = $privacy;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = $params
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$params ) )
			: (int) $wpdb->get_var( $count_sql );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
				...array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT status, COUNT(*) AS cnt FROM {$table} WHERE {$type_sql} GROUP BY status", ...$type_params ),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching

		$status_counts = array();
		foreach ( (array) $rows as $row ) {
			$status_counts[ (string) $row['status'] ] = (int) $row['cnt'];
		}

		return array(
			'items'         => is_array( $items ) ? $items : array(),
			'total'         => $total,
			'status_counts' => $status_counts,
		);
	}

	/**
	 * One page of the moderation queue, plus its total.
	 *
	 * EVERY media type, EVERY privacy level, EVERY lifecycle status — a
	 * moderation queue that hides rows is not a moderation queue.
	 *
	 * Its own method rather than `query()`/`query_count()` for a measured reason,
	 * not a stylistic one: those default `media_types` to
	 * `MediaTypes::MEDIA_LIBRARY`, which EXCLUDES documents. Asked for the
	 * `approved` bucket on a site with both, `query_count()` answers 64 where the
	 * table holds 161 — every document silently absent from the queue a moderator
	 * is using to decide what has been reviewed. The same shape of trap as
	 * `query_by_author()` on the compliance paths: a listing helper carries
	 * listing defaults, and an admin surface needs none of them.
	 *
	 * @since 2.4.0
	 *
	 * @param string $moderation_status pending|approved|flagged|rejected.
	 * @param int    $per_page          Page size.
	 * @param int    $offset            Offset.
	 * @return array{items:int[], total:int}
	 */
	public function moderation_queue( string $moderation_status, int $per_page, int $offset = 0 ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_media_index';

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE moderation_status = %s", $moderation_status )
		);

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT media_id FROM {$table}
				  WHERE moderation_status = %s
				  ORDER BY created_at DESC
				  LIMIT %d OFFSET %d",
				$moderation_status,
				max( 1, $per_page ),
				max( 0, $offset )
			)
		);

		return array(
			'items' => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}

	/**
	 * How many items sit in each moderation bucket.
	 *
	 * Same unfiltered scope as `moderation_queue()`, and the same reason.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $statuses Buckets to count.
	 * @return array<string, int>
	 */
	public function moderation_counts( array $statuses ): array {
		global $wpdb;

		$statuses = array_values( array_filter( array_map( 'sanitize_key', $statuses ) ) );

		if ( ! $statuses ) {
			return array();
		}

		$table        = $wpdb->prefix . 'mvs_media_index';
		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT moderation_status AS status, COUNT(*) AS count
				   FROM {$table}
				  WHERE moderation_status IN ({$placeholders})
				  GROUP BY moderation_status",
				...$statuses
			)
		);

		$out = array_fill_keys( $statuses, 0 );

		foreach ( (array) $rows as $row ) {
			$out[ (string) $row->status ] = (int) $row->count;
		}

		return $out;
	}

	/**
	 * Find a media id by an exact match on one INDEX COLUMN.
	 *
	 * The column name cannot be a prepare placeholder, so the allowlist IS the
	 * injection guard — and it belongs here, with the table, rather than in each
	 * caller. `ActivityContentIntegration` was carrying its own copy of the same
	 * list beside its own copy of the query.
	 *
	 * Deliberately NOT `find_by_url()`: that one refuses any URL outside the
	 * gated uploads directory, which is right for its caller and wrong for a
	 * cloud-hosted file, whose CDN URL contains no such path. Swapping the two
	 * would have quietly stopped resolving media on every CDN-backed site — the
	 * 2.3.1 bug class exactly.
	 *
	 * @since 2.4.0
	 *
	 * @param string $column Index column; anything outside the allowlist returns 0.
	 * @param string $value  Value to match.
	 * @return int Media id, or 0.
	 */
	public function find_by_indexed_column( string $column, string $value ): int {
		global $wpdb;

		if ( ! in_array( $column, self::$index_columns, true ) ) {
			return 0;
		}

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE `{$column}` = %s LIMIT 1",
				$value
			)
		);

		return $id ? (int) $id : 0;
	}

	/**
	 * Every media id filed under an album.
	 *
	 * @since 2.4.0
	 *
	 * @param int $album_id Album id.
	 * @return int[]
	 */
	public function media_ids_in_album( int $album_id ): array {
		global $wpdb;

		if ( $album_id <= 0 ) {
			return array();
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE album_id = %d",
				$album_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Find a media id by its stored file hash.
	 *
	 * @since 2.4.0
	 *
	 * @param string $hash sha256 of the stored file.
	 * @return int|null
	 */
	public function find_by_hash( string $hash ): ?int {
		global $wpdb;

		if ( '' === $hash ) {
			return null;
		}

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE file_hash = %s LIMIT 1",
				$hash
			)
		);

		return $id ? (int) $id : null;
	}

	/**
	 * EVERY media row a member authored — no privacy filter, no status filter.
	 *
	 * FOR COMPLIANCE PATHS ONLY: the GDPR exporter, the GDPR eraser and account
	 * deletion. Those must see everything the person authored, and
	 * `query_by_author()` deliberately applies a viewer-aware privacy mode — using
	 * it here would silently under-export and under-delete, which is precisely
	 * the failure a data-subject request cannot have. The unfiltered scope is the
	 * whole point of this method, and the reason it is named for the caller's
	 * question rather than reusing the listing one.
	 *
	 * Ordered by `media_id` so a paginated caller sees a stable sequence while it
	 * deletes rows underneath itself.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id Author.
	 * @param int $limit   0 for no limit.
	 * @param int $offset  Offset.
	 * @return int[] Media ids.
	 */
	public function author_media_ids( int $user_id, int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		$sql    = "SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d ORDER BY media_id ASC";
		$params = array( $user_id );

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ids = $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Every row a member authored that lives on somebody ELSE'S drive.
	 *
	 * The rows a departing member must NOT take with them (§15 T1). A document
	 * uploaded into a Space belongs to that Space; the member is its author, not
	 * its owner, and deleting the account has been taking the team's files with
	 * it because the erasure cascade reads `post_author` alone.
	 *
	 * THE TEST IS THE DRIVE TYPE, not `drive_id > 0`. That was the first version
	 * and it was exactly backwards: it read `drive_id = 0` as "personal", when
	 * `0` actually means "Migrator v29 has not reached this row yet". A personal
	 * document is stamped `drive_type = user, drive_id = <author id>` by both the
	 * v29 backfill and `DocumentIngestService`, so the author id — always > 0 —
	 * made every personal file look like a team file. Measured on a real drive:
	 * 58 of 58 personal documents were classified as team, which on account
	 * deletion would have handed the lot to an administrator instead of erasing
	 * them. The inverse of T1, and a GDPR erasure failure.
	 *
	 * An unstamped row (`drive_id = 0`, or a `drive_type` this build does not
	 * know) therefore stays PERSONAL and is purged with its author. That is the
	 * safe direction: erasure stays complete, and nothing is silently retained
	 * because a migration was mid-flight.
	 *
	 * Deliberately unfiltered, for the same reason as `author_media_ids()`.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id Author.
	 * @return array<int, array<string, mixed>> Rows of media_id, drive_type, drive_id.
	 */
	public function author_team_drive_media( int $user_id ): array {
		$rows = $this->author_scoped_rows(
			array( 'media_id', 'drive_type', 'drive_id' ),
			$user_id,
			"AND drive_type <> '' AND drive_type <> 'user' AND drive_id > 0"
		);

		return array_map(
			static function ( $row ) {
				return array(
					'media_id'   => (int) $row['media_id'],
					'drive_type' => (string) $row['drive_type'],
					'drive_id'   => (int) $row['drive_id'],
				);
			},
			(array) $rows
		);
	}

	/**
	 * The exportable columns of every media row a member authored.
	 *
	 * Same unfiltered scope and the same reasoning as `author_media_ids()` — see
	 * that docblock. Returns the columns a data export carries, not whole rows:
	 * an export should be a deliberate list of what is disclosed rather than
	 * whatever happens to be in the table.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id Author.
	 * @param int $limit   Page size.
	 * @param int $offset  Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function author_media_export_rows( int $user_id, int $limit, int $offset = 0 ): array {
		return $this->author_scoped_rows(
			array( 'media_id', 'title', 'description', 'media_type', 'privacy', 'file_url', 'created_at' ),
			$user_id,
			'',
			max( 1, $limit ),
			$offset
		);
	}

	/**
	 * Rows a member authored, unfiltered, in a stable order.
	 *
	 * The shared body of the erasure and export reads. They ask for different
	 * columns and different extra conditions but must never differ in SCOPE —
	 * both exist precisely because the listing methods apply privacy and status
	 * filters that a data-subject request must not inherit, and two hand-written
	 * copies of that scope are two chances to quietly reintroduce one.
	 *
	 * `$columns` and `$where_extra` are code-supplied constants at every call
	 * site, never request data; `$user_id`, `$limit` and `$offset` are bound.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $columns     Columns to select.
	 * @param int      $user_id     Author.
	 * @param string   $where_extra Extra SQL appended to the WHERE, or ''.
	 * @param int      $limit       0 for no limit.
	 * @param int      $offset      Offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function author_scoped_rows( array $columns, int $user_id, string $where_extra = '', int $limit = 0, int $offset = 0 ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		// Guarded even though every caller is internal: a column list reaching
		// SQL unchecked is the kind of thing a later caller turns into a hole.
		$columns = array_values( array_intersect( $columns, self::selectable_columns() ) );

		if ( empty( $columns ) ) {
			return array();
		}

		$sql = 'SELECT ' . implode( ', ', $columns )
			. " FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d "
			. ( '' !== $where_extra ? $where_extra . ' ' : '' )
			. 'ORDER BY media_id ASC';

		$params = array( $user_id );

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = max( 0, $offset );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Columns `author_scoped_rows()` will select.
	 *
	 * @since 2.4.0
	 *
	 * @return string[]
	 */
	private static function selectable_columns(): array {
		return array_merge( array( 'media_id' ), self::$index_columns );
	}

	/**
	 * Whether the FULLTEXT search index exists on the media table.
	 *
	 * Schema introspection rather than a data read, and it belongs here for the
	 * same reason the data reads do: the table is this class's to know about.
	 * Callers use it to decide between MATCH…AGAINST and a LIKE fallback, so a
	 * wrong answer silently changes how every search behaves.
	 *
	 * @since 2.4.0
	 *
	 * @return bool
	 */
	public function has_fulltext_index(): bool {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_media_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->get_var( "SHOW INDEX FROM {$table} WHERE Key_name = 'media_search_ft'" );
	}

	/**
	 * The media-index table name, for callers that JOIN to it from their own.
	 *
	 * NARROW ON PURPOSE, and not a way around Rule 7. It is for the case where
	 * the DRIVING table belongs to somebody else — favourites, activity,
	 * term_relationships — and the index is only the joined side. Pulling those
	 * queries in here would move favourites, activity and taxonomy logic into
	 * the media repository and make it the thing Rule 7 exists to prevent: one
	 * class that knows everything.
	 *
	 * The same reasoning already produced `explore_privacy_clause()`, which hands
	 * a SQL fragment to exactly these callers rather than swallowing their query.
	 *
	 * NOT for: reading, writing or counting media. Those have methods, and a
	 * caller reaching for this to run `SELECT … FROM index WHERE media_id = …`
	 * is working around the rule rather than within it.
	 *
	 * @since 2.4.0
	 *
	 * @return string Prefixed table name.
	 */
	public function index_table(): string {
		global $wpdb;

		return $wpdb->prefix . 'mvs_media_index';
	}

	public function explore_privacy_clause( string $alias, int $viewer_id ): array {
		// Alias is caller-supplied code, never user input; still constrain it.
		// Empty alias → bare column names (callers that query a single table
		// without an alias, e.g. the Pro feed layouts).
		$alias  = preg_replace( '/[^a-zA-Z0-9_]/', '', $alias );
		$prefix = '' !== $alias ? $alias . '.' : '';

		if ( ! $viewer_id ) {
			return array( "{$prefix}privacy = 'public'", array() );
		}
		if ( user_can( $viewer_id, 'moderate_mvs_media' ) ) {
			return array( '1 = 1', array() );
		}
		return array(
			"({$prefix}privacy = 'public' OR {$prefix}privacy = 'members' OR {$prefix}post_author = %d)",
			array( $viewer_id ),
		);
	}

	/**
	 * The single source of truth for the "exclude non-cover gallery members"
	 * subquery (previously copy-pasted verbatim across 6 listing sites).
	 *
	 * Returns rows that are gallery members at a non-zero position — the outer
	 * query wraps this in `m.media_id NOT IN (...)`. Fully static: no params.
	 *
	 * @return string Subquery SQL (no surrounding parentheses).
	 */
	private function gallery_exclude_subquery(): string {
		global $wpdb;

		$meta = $wpdb->prefix . 'mvs_media_meta';

		return "SELECT mm1.media_id FROM {$meta} mm1
			INNER JOIN {$meta} mm2 ON mm1.media_id = mm2.media_id
			WHERE mm1.meta_key = 'media_group'
			AND mm2.meta_key = 'group_position'
			AND mm2.meta_value != '0'";
	}

	/**
	 * Read every meta_value for a media_id + meta_key (multi-value meta).
	 *
	 * The repository's `get()` / `get_raw()` collapse repeated rows to the
	 * first match. This helper returns ALL rows for callers that store
	 * multi-row meta (legacy tag-id-per-row, multi-author lists, etc.).
	 *
	 * @since 1.3.0
	 *
	 * @param int    $media_id Media ID.
	 * @param string $meta_key Meta key to fetch.
	 * @return array<string>   All meta_value strings in storage order.
	 */
	public function get_meta_values( int $media_id, string $meta_key ): array {
		global $wpdb;

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key = %s",
				$media_id,
				$meta_key
			)
		);

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	/**
	 * Find all media IDs matching a meta key/value, with optional author scope.
	 *
	 * Media that do NOT have a meta key set — the anti-join.
	 *
	 * The shape `query()` cannot express and should not learn to: every other
	 * filter on that builder narrows a set of index rows, while this one asks
	 * about the ABSENCE of a row in another table. A LEFT JOIN … IS NULL is a
	 * different query, not another predicate.
	 *
	 * `NULL or empty` is one condition here, not two, and the distinction
	 * matters to the caller that needed it: `backfill_ai` marks its work with
	 * `ai_status`, and a media that completed with an empty description must not
	 * be retried forever. Treating only NULL as "not done" would re-run the AI
	 * over the same images on every invocation, which costs the site owner money
	 * per run.
	 *
	 * @since 2.4.0
	 *
	 * @param string $meta_key Meta key whose absence is being tested.
	 * @param array  $args     {
	 *     @type array  $media_types Type allowlist. Default the media library.
	 *     @type string $status      Index status. Default 'publish'.
	 *     @type int    $limit       Row cap. 0 = unbounded.
	 *     @type int    $after_id    Keyset cursor; only ids above this. Default 0.
	 * }
	 * @return int[] Media ids, ascending.
	 */
	public function media_ids_missing_meta( string $meta_key, array $args = array() ): array {
		global $wpdb;

		$types  = isset( $args['media_types'] ) && is_array( $args['media_types'] ) ? $args['media_types'] : MediaTypes::MEDIA_LIBRARY;
		$status = isset( $args['status'] ) ? (string) $args['status'] : 'publish';
		$limit  = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
		$after  = isset( $args['after_id'] ) ? max( 0, (int) $args['after_id'] ) : 0;

		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types, 'm.media_type' );

		$where  = array( $type_sql, "( s.meta_value IS NULL OR s.meta_value = '' )" );
		$params = array_merge( array( $meta_key ), $type_params );

		if ( '' !== $status ) {
			$where[]  = 'm.status = %s';
			$params[] = $status;
		}

		// A KEYSET CURSOR, and it is not optional for every caller. A walk that
		// relies on rows LEAVING this set as it works — because the meta gets
		// written — repeats forever the moment nothing is written, which is
		// exactly what `--dry-run` does. Found by running the backfill twice.
		if ( $after > 0 ) {
			$where[]  = 'm.media_id > %d';
			$params[] = $after;
		}

		$meta      = $wpdb->prefix . 'mvs_media_meta';
		$index     = $wpdb->prefix . 'mvs_media_index';
		$where_sql = implode( ' AND ', $where );
		$limit_sql = $limit > 0 ? ' LIMIT %d' : '';

		if ( $limit > 0 ) {
			$params[] = $limit;
		}

		$sql = "SELECT m.media_id
		          FROM {$index} m
		     LEFT JOIN {$meta} s ON s.media_id = m.media_id AND s.meta_key = %s
		         WHERE {$where_sql}
		      ORDER BY m.media_id ASC{$limit_sql}";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) );
	}

	/**
	 * Sister method to find_by_meta() which returns only the first match.
	 * Use this for one-to-many meta keys (connector links, external IDs,
	 * legacy multi-row meta) or when an author filter is needed.
	 *
	 * @since 1.3.0
	 *
	 * @param string $meta_key   Meta key to match.
	 * @param string $meta_value Meta value to match.
	 * @param array  $args       {
	 *     @type int $author_id Optional author filter (joins to index).
	 *                          Default 0 = no filter.
	 *     @type int $after_id  Optional keyset cursor — only IDs greater than
	 *                          this value are returned. Default 0 = no cursor.
	 *     @type int $limit     Optional row cap, ordered by media_id ASC so the
	 *                          cursor above is stable. Default 0 = unbounded.
	 * }
	 * @return array<int> Media IDs matching the criteria.
	 */
	public function find_ids_by_meta( string $meta_key, string $meta_value, array $args = array() ): array {
		global $wpdb;

		$author_id = isset( $args['author_id'] ) ? (int) $args['author_id'] : 0;
		$after_id  = isset( $args['after_id'] ) ? (int) $args['after_id'] : 0;
		$limit     = isset( $args['limit'] ) ? (int) $args['limit'] : 0;

		$limit_sql = $limit > 0 ? $wpdb->prepare( ' ORDER BY m.media_id ASC LIMIT %d', $limit ) : '';

		if ( $author_id > 0 ) {
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT m.media_id
					 FROM {$wpdb->prefix}mvs_media_meta m
					 INNER JOIN {$wpdb->prefix}mvs_media_index i ON i.media_id = m.media_id
					 WHERE m.meta_key = %s AND m.meta_value = %s AND i.post_author = %d AND m.media_id > %d"
					. $limit_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $limit_sql is itself a $wpdb->prepare() fragment (line above); the %d is already bound.
					$meta_key,
					$meta_value,
					$author_id,
					$after_id
				)
			);
		} else {
			// Aliased as `m` (matching the author_id branch above) so $limit_sql's
			// `ORDER BY m.media_id` resolves correctly in both branches.
			$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT m.media_id FROM {$wpdb->prefix}mvs_media_meta m WHERE m.meta_key = %s AND m.meta_value = %s AND m.media_id > %d"
					. $limit_sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $limit_sql is itself a $wpdb->prepare() fragment (line above); the %d is already bound.
					$meta_key,
					$meta_value,
					$after_id
				)
			);
		}

		return array_map( 'absint', (array) $rows );
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
		return $this->query_count(
			array(
				'status'  => 'publish',
				'privacy' => 'any',
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
	public function count_by_author( int $user_id, string $status = 'publish', string $moderation_status = '' ): int {
		return $this->query_count(
			array(
				'author_id'         => $user_id,
				'status'            => $status,
				'moderation_status' => $moderation_status,
				'privacy'           => 'any',
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

		// 1.2.1 cache layer: row_cache holds the pre-trash status. Without
		// this invalidation, subsequent get_raw() calls return the stale
		// value — caught by test_trash_then_restore_round_trip.
		self::invalidate_row_cache( $media_id );

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

		// Same cache-invalidation rationale as trash() above.
		self::invalidate_row_cache( $media_id );

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

		// Author + permalink captured before the index row is deleted, so the
		// mvs_media_deleted hook (fired at the end, once the slug row is gone)
		// can still carry them. The permalink is slug-based, so a listener CANNOT
		// reconstruct it from the id after delete — a consumer that mirrors this
		// media elsewhere (e.g. a BuddyNext feed card keyed on the URL) needs the
		// exact pre-delete permalink to withdraw its copy.
		$author_id = (int) $this->get_author( $media_id );
		$permalink = $this->get_permalink( $media_id );

		// Capture every stored file path BEFORE the meta/index rows are deleted,
		// then hand them to the async storage-cleanup cycle. Both delete paths
		// funnel through here (REST delete + account deletion), so this is the
		// single point that reclaims the original + every variant (thumbnails,
		// WebP/AVIF, posters) from local + cloud (Basecamp #9952862992 family).
		$orphaned_files = $this->get_stored_file_paths( $media_id );
		if ( ! empty( $orphaned_files ) ) {
			/**
			 * Fires with every relative file path owned by a media item that is
			 * about to be torn down, so a cleanup listener can delete the bytes
			 * from disk and cloud asynchronously.
			 *
			 * @since 1.6.0
			 *
			 * @param int      $media_id       Media ID being deleted.
			 * @param string[] $orphaned_files Relative paths (original + variants).
			 */
			do_action( 'mvs_media_files_orphaned', $media_id, $orphaned_files );
		}

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
		// Media comments are detached from the post-ID space (comment_post_ID = 0)
		// and linked to the media via comment meta; delete each + its meta.
		$mvs_comment_ids = get_comments(
			array(
				'type'       => \WPMediaVerse\Social\CommentService::COMMENT_TYPE,
				'meta_key'   => \WPMediaVerse\Social\CommentService::MEDIA_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $media_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'     => 'ids',
				'number'     => 0,
			)
		);
		foreach ( $mvs_comment_ids as $mvs_comment_id ) {
			wp_delete_comment( (int) $mvs_comment_id, true );
		}

		// Taxonomy term relationships are keyed by media_id as the object id
		// (mvs_tag / mvs_category are registered against the media object type,
		// not a WP post) and are NOT covered by any of the wpdb deletes above —
		// moved here from MediaController::delete_item() so every delete path
		// (REST single, REST bulk, admin single, admin bulk) gets it for free,
		// matching this method's "single funnel for EVERY delete path" contract.
		wp_delete_object_term_relationships( $media_id, array( 'mvs_tag', 'mvs_category' ) );

		$wpdb->delete( $wpdb->prefix . 'mvs_media_index', $where, $format ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// 1.7.0 cache layer: row_cache still holds the now-deleted index row, and
		// exists() short-circuits to true when row_cache[$id]['media_id'] is set.
		// Without this invalidation exists()/get_raw() report the media as still
		// present after delete (caught by MediaRepositoryTest::test_delete_all and
		// MediaRepositoryCoverageTest::test_delete_cascade_removes_index_row).
		self::invalidate_row_cache( $media_id );

		/**
		 * Fires after a media item has been permanently deleted. delete_cascade
		 * is the single funnel for EVERY delete path (REST single + bulk, admin
		 * MediaListPage, GDPR erase, account deletion), so firing here — rather
		 * than in each caller — guarantees Pro listeners (quota decrement,
		 * collection-membership purge, competition-entry cleanup) run no matter
		 * how the media was deleted. Previously only the two REST paths fired
		 * it, so admin/GDPR/user-deletion left those rows orphaned
		 * (audit 2026-06-04, #39 follow-up; verified by the cascade double-check).
		 *
		 * @since 1.1.0
		 *
		 * @param int    $media_id  The deleted media ID.
		 * @param int    $author_id The author user ID.
		 * @param string $permalink The media's public permalink, captured before
		 *                          the slug row was deleted (added 1.9.0) — so a
		 *                          listener can withdraw a mirror keyed on the URL.
		 */
		do_action( 'mvs_media_deleted', $media_id, $author_id, $permalink );

		return true;
	}

	/**
	 * Every relative file path stored for a media item: the original plus every
	 * driver-agnostic variant path (thumbnails, WebP/AVIF siblings, video
	 * posters). Read before a delete so the storage-cleanup cycle can reclaim
	 * the bytes. Every variant path lives under a `*_path` meta key (1.4.0+).
	 *
	 * @since 1.6.0
	 *
	 * @param int $media_id Media ID.
	 * @return string[] Unique, non-empty relative paths.
	 */
	public function get_stored_file_paths( int $media_id ): array {
		global $wpdb;

		$paths = array();

		$file_path = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT file_path FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id
			)
		);
		if ( $file_path ) {
			$paths[] = (string) $file_path;
		}

		$variant_paths = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				'%_path'
			)
		);
		foreach ( $variant_paths as $variant_path ) {
			if ( '' !== (string) $variant_path ) {
				$paths[] = (string) $variant_path;
			}
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Build the shared album-items JOIN.
	 *
	 * `AlbumService` had four copies of this join — the item list, the count, and
	 * the cover picker's image pass and its fallback. They had already drifted
	 * once: the list and count excluded trash while the render path asserted
	 * publish, so an album reported twelve items and rendered nine. One builder
	 * means the count cannot disagree with the list again.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $album_id Album post id.
	 * @param array $args     status, types, mime_like.
	 * @return array{0:string,1:array} WHERE fragment (after the JOIN) and its params.
	 */
	private function album_items_parts( int $album_id, array $args ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'status'    => 'publish',
				'types'     => MediaTypes::MEDIA_LIBRARY,
				'mime_like' => '',
			)
		);

		$where  = array( 'ai.album_id = %d' );
		$params = array( $album_id );

		if ( '' !== (string) $args['status'] ) {
			$where[]  = 'idx.status = %s';
			$params[] = (string) $args['status'];
		}

		if ( '' !== (string) $args['mime_like'] ) {
			$where[]  = 'idx.file_type LIKE %s';
			$params[] = (string) $args['mime_like'];
		}

		$types                          = is_array( $args['types'] ) ? $args['types'] : MediaTypes::MEDIA_LIBRARY;
		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types, 'idx.media_type' );
		$where[]                        = $type_sql;
		$params                         = array_merge( $params, $type_params );

		unset( $wpdb );

		return array( implode( ' AND ', $where ), $params );
	}

	/**
	 * A member's aggregate media totals.
	 *
	 * `total_media` counts MEDIA (Coding Rule 13). Documents get their own
	 * counters; folding them in here would silently change every existing
	 * member's headline number on upgrade.
	 *
	 * NOTE — deliberately no status filter, preserving the behaviour this
	 * replaces: trashed items still count toward a member's totals. That is a
	 * real discrepancy against every other count in the plugin, but correcting it
	 * changes a number members already see, so it needs its own decision rather
	 * than a quiet ride-along on a relocation.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $user_id Author user id.
	 * @param array $args    types (default MediaTypes::MEDIA_LIBRARY).
	 * @return array{total_media:int, total_views:int, total_downloads:int, total_reactions:int, total_comments:int, total_shares:int}
	 */
	public function user_media_totals( int $user_id, array $args = array() ): array {
		global $wpdb;

		$zero = array(
			'total_media'     => 0,
			'total_views'     => 0,
			'total_downloads' => 0,
			'total_reactions' => 0,
			'total_comments'  => 0,
			'total_shares'    => 0,
		);

		$types                          = isset( $args['types'] ) && is_array( $args['types'] ) ? $args['types'] : MediaTypes::MEDIA_LIBRARY;
		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types, 'i.media_type' );

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT
					COUNT(*) as total_media,
					COALESCE(SUM(s.views), 0) as total_views,
					COALESCE(SUM(s.downloads), 0) as total_downloads,
					COALESCE(SUM(s.reactions), 0) as total_reactions,
					COALESCE(SUM(s.comments), 0) as total_comments,
					COALESCE(SUM(s.shares), 0) as total_shares
				FROM {$wpdb->prefix}mvs_media_index i
				INNER JOIN {$wpdb->prefix}mvs_media_stats s ON i.media_id = s.media_id
				WHERE i.post_author = %d AND {$type_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				...$type_params
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return $zero;
		}

		foreach ( $zero as $key => $unused ) {
			$zero[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : 0;
		}

		return $zero;
	}

	/**
	 * Author ids ranked by how much public media they have.
	 *
	 * Feeds "who to follow". The type group is what keeps that honest: ranked by
	 * COUNT(*), a member could otherwise earn a place in a discovery feed by
	 * bulk-uploading documents, which are cheap to produce and are not what
	 * anyone is browsing there.
	 *
	 * `privacy = 'public'` is intentional and must NOT become the viewer-aware
	 * clause — this ranks people by their PUBLIC output only.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $limit Max authors.
	 * @param array $args  types (default MediaTypes::MEDIA_LIBRARY).
	 * @return int[]
	 */
	public function top_author_ids( int $limit, array $args = array() ): array {
		global $wpdb;

		$types                          = isset( $args['types'] ) && is_array( $args['types'] ) ? $args['types'] : MediaTypes::MEDIA_LIBRARY;
		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT post_author
				FROM {$wpdb->prefix}mvs_media_index
				WHERE status = 'publish' AND moderation_status = 'approved' AND privacy = 'public' AND post_author > 0 AND {$type_sql}
				GROUP BY post_author ORDER BY COUNT(*) DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...array_merge( $type_params, array( max( 1, $limit ) ) )
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	}

	/**
	 * Which of the given authors have public media in the given taxonomy terms.
	 *
	 * Takes term_taxonomy_ids, NOT term_ids — the caller resolves them, because
	 * it already knows the taxonomy. The two columns are different and equal only
	 * by coincidence, so the distinction is kept explicit in the method name.
	 *
	 * @since 2.4.0
	 *
	 * @param int[] $author_ids Candidate authors.
	 * @param int[] $tt_ids     term_taxonomy_ids.
	 * @param array $args       types (default MediaTypes::MEDIA_LIBRARY).
	 * @return int[] Author ids with a match.
	 */
	public function authors_with_term_taxonomy_ids( array $author_ids, array $tt_ids, array $args = array() ): array {
		global $wpdb;

		$author_ids = array_values( array_filter( array_map( 'intval', $author_ids ) ) );
		$tt_ids     = array_values( array_filter( array_map( 'intval', $tt_ids ) ) );

		if ( empty( $author_ids ) || empty( $tt_ids ) ) {
			return array();
		}

		$types                          = isset( $args['types'] ) && is_array( $args['types'] ) ? $args['types'] : MediaTypes::MEDIA_LIBRARY;
		list( $type_sql, $type_params ) = MediaTypes::in_clause( $types, 'i.media_type' );

		$cand_ph = implode( ',', array_fill( 0, count( $author_ids ), '%d' ) );
		$tt_ph   = implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) );

		$rows = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT i.post_author
				FROM {$wpdb->prefix}mvs_media_index i
				INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = i.media_id AND tr.term_taxonomy_id IN ({$tt_ph})
				WHERE i.status = 'publish' AND i.privacy = 'public' AND i.moderation_status = 'approved' AND i.post_author IN ({$cand_ph}) AND {$type_sql}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...array_merge( $tt_ids, $author_ids, $type_params )
			)
		);

		return array_values( array_filter( array_map( 'intval', (array) $rows ) ) );
	}

	/**
	 * Media rows belonging to an album, in album order.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $album_id Album post id.
	 * @param array $args     status (default 'publish'), types (default
	 *                        MediaTypes::MEDIA_LIBRARY), mime_like, limit.
	 * @return array<int, array{media_id:int, position:int}>
	 */
	public function album_items( int $album_id, array $args = array() ): array {
		global $wpdb;

		list( $where, $params ) = $this->album_items_parts( $album_id, $args );

		$limit = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;
		$sql   = "SELECT ai.media_id, ai.position
			FROM {$wpdb->prefix}mvs_album_items ai
			INNER JOIN {$wpdb->prefix}mvs_media_index idx ON idx.media_id = ai.media_id
			WHERE {$where}
			ORDER BY ai.position ASC";

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$params[] = $limit;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( $sql, ...$params ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count of an album's media, using the SAME filter as {@see album_items()}.
	 *
	 * @since 2.4.0
	 *
	 * @param int   $album_id Album post id.
	 * @param array $args     Same args as album_items(), minus limit.
	 * @return int
	 */
	public function count_album_items( int $album_id, array $args = array() ): int {
		global $wpdb;

		list( $where, $params ) = $this->album_items_parts( $album_id, $args );

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}mvs_album_items ai
				INNER JOIN {$wpdb->prefix}mvs_media_index idx ON idx.media_id = ai.media_id
				WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);
	}
}
