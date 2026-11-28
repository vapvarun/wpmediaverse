<?php
/**
 * Cache service.
 *
 * Provides caching layer for frequently accessed media data.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Object cache wrapper for WPMediaVerse data.
 */
class CacheService {

	const GROUP = 'wpmediaverse';

	const TTL_SHORT  = 60;       // 1 minute.
	const TTL_MEDIUM = 300;      // 5 minutes.
	const TTL_LONG   = 3600;     // 1 hour.

	/**
	 * Whether the tag cloud keys are already cleared in this request.
	 *
	 * Moving 200 photos into an album fires mvs_media_privacy_changed 200 times, and each clear
	 * deletes six transients, so 1,200 queries for the same result. A read (tag_cloud(),
	 * tag_cloud_total()) can store the keys again, so it resets the flag.
	 *
	 * @var bool
	 */
	private $tag_cloud_cleared = false;

	/**
	 * A key tied to the group's last-changed token, so flush_all() can retire every cached read at
	 * once by moving the token, on any object-cache backend (core's own pattern for a family of reads).
	 *
	 * @param string $key Cache key.
	 * @return string Key carrying the current token.
	 */
	private function versioned( string $key ): string {
		return $key . ':' . wp_cache_get_last_changed( self::GROUP );
	}

	/**
	 * Get a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param bool   $found Whether the key was found (passed by reference).
	 * @return mixed Cached value or false.
	 */
	public function get( string $key, bool &$found = false ) {
		return wp_cache_get( $this->versioned( $key ), self::GROUP, false, $found );
	}

	/**
	 * Set a cached value.
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $value  Value to cache.
	 * @param int    $ttl    Time to live in seconds.
	 * @return bool True on success.
	 */
	public function set( string $key, $value, int $ttl = self::TTL_MEDIUM ): bool {
		return wp_cache_set( $this->versioned( $key ), $value, self::GROUP, $ttl );
	}

	/**
	 * Delete a cached value.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool {
		return wp_cache_delete( $this->versioned( $key ), self::GROUP );
	}

	/**
	 * Get or set a cached value using a callback.
	 *
	 * @param string   $key      Cache key.
	 * @param callable $callback Callback to generate value if not cached.
	 * @param int      $ttl      Time to live in seconds.
	 * @return mixed Cached or freshly generated value.
	 */
	public function remember( string $key, callable $callback, int $ttl = self::TTL_MEDIUM ) {
		$found = false;
		$value = $this->get( $key, $found );

		if ( $found ) {
			return $value;
		}

		$value = $callback();
		$this->set( $key, $value, $ttl );
		return $value;
	}

	/**
	 * Persistent two-tier remember for low-cardinality global aggregates.
	 *
	 * Tier 1: `wp_cache_*` — instant when an external object cache (Redis,
	 * Memcached) is wired up; per-request only otherwise.
	 *
	 * Tier 2: `set_transient` — only when no external object cache. This
	 * guarantees aggregates survive across requests on stock-WP hosts
	 * without polluting `wp_options` for sites that already have Redis.
	 *
	 * Use ONLY for keys whose cardinality is bounded and tiny — global
	 * counters, system stats, settings derivatives. NEVER for per-user or
	 * per-entity values: see Coding Rule #16 (cache backend by cardinality).
	 *
	 * @since 1.2.1
	 *
	 * @param string   $key      Cache key (must be a fixed identifier, not entity-keyed).
	 * @param callable $callback Callback that returns the value to cache.
	 * @param int      $ttl      Time to live in seconds (defaults to 1 day).
	 * @return mixed Cached or freshly generated value.
	 */
	public function remember_persistent( string $key, callable $callback, int $ttl = DAY_IN_SECONDS ) {
		$found = false;
		$value = $this->get( $key, $found );
		if ( $found ) {
			return $value;
		}

		$transient_fallback = ! wp_using_ext_object_cache();
		$transient_key      = 'mvs_pcache_' . $key;

		if ( $transient_fallback ) {
			$cached = get_transient( $transient_key );
			if ( false !== $cached ) {
				$this->set( $key, $cached, $ttl );
				return $cached;
			}
		}

		$value = $callback();
		$this->set( $key, $value, $ttl );

		if ( $transient_fallback ) {
			set_transient( $transient_key, $value, $ttl );
		}

		return $value;
	}

	/**
	 * Invalidate a persistent two-tier cache entry — clears BOTH layers so
	 * the next read recomputes. Pair with `remember_persistent`.
	 *
	 * @since 1.2.1
	 *
	 * @param string $key Cache key.
	 */
	public function forget_persistent( string $key ): void {
		$this->delete( $key );
		delete_transient( 'mvs_pcache_' . $key );
	}

	/**
	 * Get media stats with caching.
	 *
	 * @param int $media_id Media post ID.
	 * @return array|null Stats array or null.
	 */
	public function get_media_stats( int $media_id ): ?array {
		$key = "media_stats_{$media_id}";
		return $this->remember(
			$key,
			function () use ( $media_id ) {
				global $wpdb;
				$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT views, downloads, reactions, comments, shares, updated_at FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
						$media_id
					),
					ARRAY_A
				);
				if ( ! $row ) {
					return null;
				}
				return array(
					'media_id'   => $media_id,
					'views'      => (int) $row['views'],
					'downloads'  => (int) $row['downloads'],
					'reactions'  => (int) $row['reactions'],
					'comments'   => (int) $row['comments'],
					'shares'     => (int) $row['shares'],
					'updated_at' => $row['updated_at'],
				);
			},
			self::TTL_MEDIUM
		);
	}

	/**
	 * Get user stats with caching.
	 *
	 * @deprecated 2.4.0 Use MediaRepository::get_user_stats() instead. This copy
	 *             carried its own SQL that had already drifted from the
	 *             repository's — it applied no status filter, so it could report
	 *             a different number for the same member. Kept as a caching
	 *             delegate because it is public API on a container-registered
	 *             service (Production Rule 1); scheduled for removal in 4.0.
	 *
	 * @param int $user_id User ID.
	 * @return array Aggregated stats.
	 */
	public function get_user_stats( int $user_id ): array {
		return $this->remember(
			"user_stats_{$user_id}",
			static function () use ( $user_id ) {
				return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_user_stats( $user_id );
			},
			self::TTL_MEDIUM
		);
	}

	/**
	 * Get moderation counts with caching.
	 *
	 * @deprecated 2.4.0 Use MediaRepository::get_moderation_counts() instead.
	 *             Duplicate of the repository query; kept as a caching delegate
	 *             because it is public API on a container-registered service
	 *             (Production Rule 1). Scheduled for removal in 4.0.
	 *
	 * @return array Counts by status.
	 */
	public function get_moderation_counts(): array {
		return $this->remember(
			'moderation_counts',
			static function () {
				return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_moderation_counts();
			},
			self::TTL_SHORT
		);
	}

	/**
	 * Get follower/following counts with caching.
	 *
	 * @param int $user_id User ID.
	 * @return array{followers: int, following: int}
	 */
	public function get_follow_counts( int $user_id ): array {
		$key = "follow_counts_{$user_id}";
		return $this->remember(
			$key,
			function () use ( $user_id ) {
				global $wpdb;
				$followers = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE following_id = %d",
						$user_id
					)
				);
				$following = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d",
						$user_id
					)
				);
				return array(
					'followers' => $followers,
					'following' => $following,
				);
			},
			self::TTL_MEDIUM
		);
	}

	/**
	 * Get tag cloud data with caching.
	 *
	 * @deprecated 2.6.0 Unused — every caller reads the media-index-backed cloud
	 *             via `tag_cloud()` below (itself a cache in front of
	 *             `MediaRepository::tag_cloud()`), which counts against the same
	 *             table and type group the feed reads. This copy counted against
	 *             `wp_term_taxonomy.count`, which includes documents the feed
	 *             excludes — the exact defect `MediaRepository::tag_cloud()` was
	 *             written to fix. Kept as a caching
	 *             delegate because it is public API on a container-registered
	 *             service (Production Rule 1); scheduled for removal in 4.0.
	 *
	 * @param int $limit Max tags.
	 * @return array
	 */
	public function get_tag_cloud( int $limit = 50 ): array {
		_deprecated_function( __METHOD__, '2.6.0', __CLASS__ . '::tag_cloud()' );
		return $this->tag_cloud( $limit );
	}

	/**
	 * Tag cloud, cached.
	 *
	 * `MediaRepository::tag_cloud()` GROUP BYs term_relationships joined against
	 * mvs_media_index for every page view that renders the Explore tag row
	 * (every layout, every request) or the `/tags/cloud` REST route — at 50k+
	 * media that join runs on every uncached request. Cardinality is small and
	 * site-controlled (the limit comes from a site-wide filter or a REST `limit`
	 * param clamped to 1-200, not a per-user/per-entity value), so the
	 * persistent two-tier cache applies (Coding Rule 16).
	 *
	 * @since 2.6.0
	 *
	 * @param int      $limit     Max tags (1-200).
	 * @param string[] $types     Media types to count against. Null = library default.
	 * @param int      $author_id One member's tags (a profile). Not cached here: it is per member, the
	 *                            query is bounded by the author_date index, and one cache key per member
	 *                            would grow without limit.
	 * @return array<int, object> Term rows: term_id, name, slug, media_count.
	 */
	public function tag_cloud( int $limit = 20, ?array $types = null, int $author_id = 0 ): array {
		if ( $author_id > 0 ) {
			return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->tag_cloud( $limit, $types, $author_id );
		}
		$key                     = 'tagcloud3_' . $limit . '_' . ( $types ? implode( ',', $types ) : 'default' );
		$this->tag_cloud_cleared = false;
		return $this->remember_persistent(
			$key,
			static function () use ( $limit, $types ) {
				return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->tag_cloud( $limit, $types );
			},
			self::TTL_MEDIUM
		);
	}

	/**
	 * How many tags would qualify for the cloud, cached. Pairs with `tag_cloud()`.
	 *
	 * @since 2.6.0
	 *
	 * @param string[]|null $types     Media types to count against. Null = library default.
	 * @param int           $author_id One member's tags (a profile); uncached, see tag_cloud().
	 * @return int
	 */
	public function tag_cloud_total( ?array $types = null, int $author_id = 0 ): int {
		if ( $author_id > 0 ) {
			return (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->tag_cloud_total( $types, $author_id );
		}
		$key                     = 'tagcloudtotal3_' . ( $types ? implode( ',', $types ) : 'default' );
		$this->tag_cloud_cleared = false;
		return (int) $this->remember_persistent(
			$key,
			static function () use ( $types ) {
				return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->tag_cloud_total( $types );
			},
			self::TTL_MEDIUM
		);
	}

	/**
	 * Invalidate media stats cache.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function invalidate_media_stats( int $media_id ): void {
		$this->delete( "media_stats_{$media_id}" );

		// Also invalidate the author's user stats.
		$author_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'post_author' );
		if ( $author_id ) {
			$this->delete( "user_stats_{$author_id}" );
		}
	}

	/**
	 * Invalidate follow counts for both users.
	 *
	 * @param int $follower_id  Follower user ID.
	 * @param int $following_id Followed user ID.
	 */
	public function invalidate_follow_counts( int $follower_id, int $following_id ): void {
		$this->delete( "follow_counts_{$follower_id}" );
		$this->delete( "follow_counts_{$following_id}" );
	}

	/**
	 * Invalidate tag cloud cache.
	 *
	 * Clears the concrete (limit, types) combinations this plugin actually
	 * requests (404.php/explore.php "popular tags" = 5, the Explore chip row's
	 * default and site-filterable limit = 20, and the REST route's default and
	 * max = 20/200). TTL_MEDIUM (5 min) bounds staleness for any limit a caller
	 * asks for outside this list — the same tradeoff made for the legacy keys
	 * below.
	 */
	public function invalidate_tag_cloud(): void {
		if ( $this->tag_cloud_cleared ) {
			return;
		}
		$this->tag_cloud_cleared = true;

		// Legacy keys (deprecated get_tag_cloud()).
		foreach ( array( 20, 50, 100 ) as $limit ) {
			$this->delete( "tag_cloud_{$limit}" );
		}
		// Current keys (tag_cloud() / tag_cloud_total()).
		foreach ( array( 5, 20, 50, 100, 200 ) as $limit ) {
			$this->forget_persistent( 'tagcloud3_' . $limit . '_default' );
		}
		$this->forget_persistent( 'tagcloudtotal3_default' );
	}

	/**
	 * Invalidate moderation counts cache.
	 */
	public function invalidate_moderation_counts(): void {
		$this->delete( 'moderation_counts' );
	}

	/**
	 * Flush all MVS caches.
	 *
	 * Moves the group's last-changed token, which retires every in-memory / external-cache value at
	 * once without asking the backend to flush a group or emptying the site's cache. The two-tier
	 * "persistent" values also keep a transient copy on a site with no external object cache, and
	 * that copy would be read back under the new token, so those keys are dropped explicitly. They
	 * are the admin aggregates and the tag cloud, the only users of remember_persistent().
	 *
	 * @return bool True on success.
	 */
	public function flush_all(): bool {
		wp_cache_set_last_changed( self::GROUP );
		$this->on_admin_aggregate_change();
		$this->invalidate_tag_cloud();
		return true;
	}

	/**
	 * Wire cache invalidation hooks.
	 */
	public function init(): void {
		// Invalidate media stats on social interactions.
		add_action( 'mvs_reaction_added', array( $this, 'on_media_stat_change' ), 10, 1 );
		add_action( 'mvs_reaction_removed', array( $this, 'on_media_stat_change' ), 10, 1 );
		add_action( 'mvs_comment_created', array( $this, 'on_media_stat_change' ), 10, 1 );
		add_action( 'mvs_share_recorded', array( $this, 'on_media_stat_change' ), 10, 1 );

		// Invalidate moderation cache.
		add_action( 'mvs_moderation_changed', array( $this, 'on_moderation_change' ) );
		add_action( 'mvs_media_uploaded', array( $this, 'on_moderation_change' ) );
		// Runs after the report close above (priority 20), so the count is read from the closed rows.
		add_action( 'mvs_media_deleted', array( $this, 'on_moderation_change' ), 20 );

		// Invalidate follow counts on follow/unfollow.
		add_action( 'mvs_user_followed', array( $this, 'on_follow_change' ), 10, 2 );
		add_action( 'mvs_user_unfollowed', array( $this, 'on_follow_change' ), 10, 2 );

		// Invalidate tag cloud on term changes.
		add_action( 'created_mvs_tag', array( $this, 'on_tag_change' ) );
		add_action( 'edited_mvs_tag', array( $this, 'on_tag_change' ) );
		add_action( 'delete_mvs_tag', array( $this, 'on_tag_change' ) );

		// The tag cloud counts visible media per tag, so it also moves when an item is tagged or untagged
		// (a PUT with tags=[] fires none of the term hooks above) and when an item stops being visible.
		add_action( 'set_object_terms', array( $this, 'on_object_terms' ), 10, 4 );
		add_action( 'mvs_media_deleted', array( $this, 'on_tag_change' ) );
		add_action( 'mvs_media_trashed', array( $this, 'on_tag_change' ) );
		add_action( 'mvs_media_privacy_changed', array( $this, 'on_tag_change' ) );
		add_action( 'mvs_moderation_changed', array( $this, 'on_tag_change' ) );

		// Invalidate media meta cache on upload (media is custom table, not CPT).
		add_action( 'mvs_media_uploaded', array( $this, 'on_media_save' ) );

		// Bust the persistent admin-overview aggregates whenever total counts
		// can change. Low-cardinality (5 keys total) so cost is bounded.
		add_action( 'mvs_media_uploaded', array( $this, 'on_admin_aggregate_change' ) );
		add_action( 'mvs_media_deleted', array( $this, 'on_admin_aggregate_change' ) );
	}

	/**
	 * Bust the persistent admin-overview aggregate keys. Low-cardinality
	 * (5 fixed keys: total_media, total_albums, pending_moderation,
	 * total_views, storage_used + recent_5).
	 *
	 * @since 1.2.1
	 */
	public function on_admin_aggregate_change(): void {
		// AdminAggregatesService owns the canonical key list — iterating it
		// here keeps the two files honest. Adding a new aggregate adds a
		// CACHE_KEYS entry; invalidation updates automatically.
		foreach ( AdminAggregatesService::CACHE_KEYS as $key ) {
			$this->forget_persistent( $key );
		}
	}

	/**
	 * Handle media stat change.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function on_media_stat_change( int $media_id ): void {
		$this->invalidate_media_stats( $media_id );
	}

	/**
	 * Handle moderation change.
	 */
	public function on_moderation_change(): void {
		$this->invalidate_moderation_counts();
	}

	/**
	 * Handle follow/unfollow events.
	 *
	 * @param int $follower_id  Follower user ID.
	 * @param int $following_id Followed user ID.
	 */
	public function on_follow_change( int $follower_id, int $following_id ): void {
		$this->invalidate_follow_counts( $follower_id, $following_id );
	}

	/**
	 * Handle tag taxonomy changes.
	 */
	public function on_tag_change(): void {
		$this->invalidate_tag_cloud();
	}

	/**
	 * An object's terms were set: refresh the tag cloud when they were media tags.
	 *
	 * @param int                      $object_id Object id.
	 * @param array<int|string, mixed> $terms     Terms.
	 * @param array<int, int|string>   $tt_ids    Term taxonomy ids.
	 * @param string                   $taxonomy  Taxonomy slug.
	 */
	public function on_object_terms( $object_id, $terms, $tt_ids, $taxonomy ): void {
		if ( 'mvs_tag' === $taxonomy ) {
			$this->invalidate_tag_cloud();
		}
	}

	/**
	 * Handle media post save.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_media_save( int $post_id ): void {
		$this->invalidate_media_stats( $post_id );
		$this->invalidate_tag_cloud();
	}
}
