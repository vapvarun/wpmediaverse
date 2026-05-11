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
	 * Get a cached value.
	 *
	 * @param string $key   Cache key.
	 * @param bool   $found Whether the key was found (passed by reference).
	 * @return mixed Cached value or false.
	 */
	public function get( string $key, bool &$found = false ) {
		return wp_cache_get( $key, self::GROUP, false, $found );
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
		return wp_cache_set( $key, $value, self::GROUP, $ttl );
	}

	/**
	 * Delete a cached value.
	 *
	 * @param string $key Cache key.
	 * @return bool True on success.
	 */
	public function delete( string $key ): bool {
		return wp_cache_delete( $key, self::GROUP );
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
	 * @param int $user_id User ID.
	 * @return array Aggregated stats.
	 */
	public function get_user_stats( int $user_id ): array {
		$key = "user_stats_{$user_id}";
		return $this->remember(
			$key,
			function () use ( $user_id ) {
				global $wpdb;
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
						WHERE i.post_author = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$user_id
					),
					ARRAY_A
				);
				if ( ! $row ) {
					return array(
						'total_media'     => 0,
						'total_views'     => 0,
						'total_downloads' => 0,
						'total_reactions' => 0,
						'total_comments'  => 0,
						'total_shares'    => 0,
					);
				}
				return array(
					'total_media'     => (int) $row['total_media'],
					'total_views'     => (int) $row['total_views'],
					'total_downloads' => (int) $row['total_downloads'],
					'total_reactions' => (int) $row['total_reactions'],
					'total_comments'  => (int) $row['total_comments'],
					'total_shares'    => (int) $row['total_shares'],
				);
			},
			self::TTL_MEDIUM
		);
	}

	/**
	 * Get moderation counts with caching.
	 *
	 * @return array Counts by status.
	 */
	public function get_moderation_counts(): array {
		return $this->remember(
			'moderation_counts',
			function () {
				global $wpdb;
				$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					"SELECT moderation_status, COUNT(*) as cnt FROM {$wpdb->prefix}mvs_media_index GROUP BY moderation_status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					ARRAY_A
				);
				$counts = array();
				if ( $rows ) {
					foreach ( $rows as $row ) {
						$counts[ $row['moderation_status'] ] = (int) $row['cnt'];
					}
				}
				return $counts;
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
	 * @param int $limit Max tags.
	 * @return array
	 */
	public function get_tag_cloud( int $limit = 50 ): array {
		$key = "tag_cloud_{$limit}";
		return $this->remember(
			$key,
			function () use ( $limit ) {
				$terms = get_terms(
					array(
						'taxonomy'   => 'mvs_tag',
						'orderby'    => 'count',
						'order'      => 'DESC',
						'number'     => $limit,
						'hide_empty' => true,
					)
				);
				if ( is_wp_error( $terms ) ) {
					return array();
				}
				$cloud = array();
				foreach ( $terms as $term ) {
					$cloud[] = array(
						'id'    => $term->term_id,
						'name'  => $term->name,
						'slug'  => $term->slug,
						'count' => $term->count,
					);
				}
				return $cloud;
			},
			self::TTL_LONG
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
	 */
	public function invalidate_tag_cloud(): void {
		// Clear all tag cloud variants.
		foreach ( array( 20, 50, 100 ) as $limit ) {
			$this->delete( "tag_cloud_{$limit}" );
		}
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
	 * Uses wp_cache_flush_group if available (WP 6.1+), otherwise individual deletes.
	 *
	 * @return bool True on success.
	 */
	public function flush_all(): bool {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			return wp_cache_flush_group( self::GROUP );
		}

		// Fallback: can't flush unknown keys, but we clear known patterns.
		$this->delete( 'moderation_counts' );
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

		// Invalidate follow counts on follow/unfollow.
		add_action( 'mvs_user_followed', array( $this, 'on_follow_change' ), 10, 2 );
		add_action( 'mvs_user_unfollowed', array( $this, 'on_follow_change' ), 10, 2 );

		// Invalidate tag cloud on term changes.
		add_action( 'created_mvs_tag', array( $this, 'on_tag_change' ) );
		add_action( 'edited_mvs_tag', array( $this, 'on_tag_change' ) );
		add_action( 'delete_mvs_tag', array( $this, 'on_tag_change' ) );

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
	 * Handle media post save.
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_media_save( int $post_id ): void {
		$this->invalidate_media_stats( $post_id );
		$this->invalidate_tag_cloud();
	}
}
