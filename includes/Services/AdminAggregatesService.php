<?php
/**
 * Admin aggregates — single source of truth for site-wide counts.
 *
 * Every admin/CLI surface that needs total media, total albums, total views,
 * storage size, recent media, or pending moderation MUST read through this
 * service. The service caches each aggregate via `CacheService::remember_persistent`
 * (object cache primary, daily transient fallback) so a 100k-row site doesn't
 * repeat full-table SUM scans on every admin page load.
 *
 * Coding Rule #16 (CLAUDE.md): raw `$wpdb->get_var` SUM/COUNT against any
 * `mvs_*` table OUTSIDE this service is forbidden — `bin/coding-rules-check.sh`
 * Rule 3 enforces it. Add a new aggregate by adding a method here, not by
 * inlining a query at the consumer.
 *
 * Cache invalidation: every aggregate is busted on `mvs_media_uploaded` /
 * `mvs_media_deleted` via `CacheService::on_admin_aggregate_change`.
 *
 * @since 1.2.1
 *
 * @package WPMediaVerse\Services
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only service exposing every site-wide aggregate count.
 */
class AdminAggregatesService {

	/**
	 * Cache keys exposed by this service. Listed here so
	 * `CacheService::on_admin_aggregate_change` (and any future debugger)
	 * can iterate without keeping a parallel list in sync.
	 */
	public const CACHE_KEYS = array(
		'admin_total_media',
		'admin_total_albums',
		'admin_total_views',
		'admin_total_reactions',
		'admin_total_favorites',
		'admin_pending_moderation',
		'admin_storage_size_bytes',
		'admin_recent_media_5',
	);

	/**
	 * Cache service.
	 *
	 * @var CacheService
	 */
	private CacheService $cache;

	public function __construct( CacheService $cache ) {
		$this->cache = $cache;
	}

	/**
	 * Total published MEDIA items — photos, video and audio.
	 *
	 * Documents are counted separately by `total_documents()`. They share the
	 * index table but they are not media, and until 2.4.0 this counted them:
	 * a site whose members had uploaded 400 documents saw them added to "Total
	 * Media" on a screen where no document was reachable.
	 *
	 * The type list runs through `MediaTypes::library_types()`, so a site that
	 * wants the old number back has the documented one-line escape hatch
	 * (`mvs_media_library_types`) rather than nothing (Production Rule 3).
	 *
	 * The cache key is deliberately versioned: the same key holding a different
	 * meaning would keep showing the pre-2.4.0 number until something unrelated
	 * flushed it.
	 */
	public function total_media(): int {
		return (int) $this->cache->remember_persistent(
			'admin_total_media_v2',
			static function (): int {
				global $wpdb;

				list( $type_sql, $type_params ) = \WPMediaVerse\Core\MediaTypes::in_clause( \WPMediaVerse\Core\MediaTypes::library_types() );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish' AND {$type_sql}",
						...$type_params
					)
				);
			}
		);
	}

	/**
	 * Total published documents, both current and quarantined-legacy.
	 *
	 * Owed since the library types were narrowed: a count that stops being part
	 * of one number has to become its own, or it silently disappears from the
	 * admin. Together with `total_media()` this sums to every published index
	 * row — asserted by `DocumentAggregatesTest`.
	 *
	 * @since 2.4.0
	 *
	 * @return int
	 */
	public function total_documents(): int {
		return (int) $this->cache->remember_persistent(
			'admin_total_documents',
			static function (): int {
				global $wpdb;

				list( $type_sql, $type_params ) = \WPMediaVerse\Core\MediaTypes::in_clause( \WPMediaVerse\Core\MediaTypes::DOCUMENT_LIBRARY );

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				return (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish' AND {$type_sql}",
						...$type_params
					)
				);
			}
		);
	}

	/**
	 * Total published albums (mvs_album CPT).
	 */
	public function total_albums(): int {
		return (int) $this->cache->remember_persistent(
			'admin_total_albums',
			static function (): int {
				return (int) wp_count_posts( 'mvs_album' )->publish;
			}
		);
	}

	/**
	 * Total media awaiting moderation.
	 */
	public function pending_moderation(): int {
		return (int) $this->cache->remember_persistent(
			'admin_pending_moderation',
			static function (): int {
				global $wpdb;
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE moderation_status = 'pending'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}
		);
	}

	/**
	 * Total views aggregated across all media.
	 */
	public function total_views(): int {
		return (int) $this->cache->remember_persistent(
			'admin_total_views',
			static function (): int {
				global $wpdb;
				return (int) $wpdb->get_var( "SELECT COALESCE(SUM(views), 0) FROM {$wpdb->prefix}mvs_media_stats" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}
		);
	}

	/**
	 * Total reactions across all media.
	 */
	public function total_reactions(): int {
		return (int) $this->cache->remember_persistent(
			'admin_total_reactions',
			static function (): int {
				global $wpdb;
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_reactions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}
		);
	}

	/**
	 * Total favorites across all media.
	 */
	public function total_favorites(): int {
		return (int) $this->cache->remember_persistent(
			'admin_total_favorites',
			static function (): int {
				global $wpdb;
				return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_favorites" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}
		);
	}

	/**
	 * Total storage used (sum of mvs_media_index.file_size, in bytes).
	 */
	public function storage_size_bytes(): int {
		return (int) $this->cache->remember_persistent(
			'admin_storage_size_bytes',
			static function (): int {
				global $wpdb;
				return (int) $wpdb->get_var( "SELECT COALESCE(SUM(file_size), 0) FROM {$wpdb->prefix}mvs_media_index" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			}
		);
	}

	/**
	 * Storage used as a human-readable string (`6 MB`, `1.2 GB`).
	 */
	public function storage_used_human(): string {
		return size_format( $this->storage_size_bytes() );
	}

	/**
	 * Most-recent published media rows. Limit is fixed at 5 so the cache
	 * key is stable; callers needing other limits should compute that
	 * directly (cache only the canonical 5-row payload).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function recent_media(): array {
		$rows = $this->cache->remember_persistent(
			'admin_recent_media_5',
			static function (): array {
				global $wpdb;
				$result = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
					"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish' ORDER BY created_at DESC LIMIT 5",
					ARRAY_A
				);
				return $result ?: array();
			}
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Bulk getter for the admin Overview cards. Existing UI shape preserved
	 * so the page stays a one-call site without losing the cache benefit.
	 *
	 * @return array{total_media:int, total_albums:int, pending_moderation:int, total_views:int, storage_used:string}
	 */
	public function overview_cards(): array {
		return array(
			'total_media'        => $this->total_media(),
			'total_documents'    => $this->total_documents(),
			'total_albums'       => $this->total_albums(),
			'pending_moderation' => $this->pending_moderation(),
			'total_views'        => $this->total_views(),
			'storage_used'       => $this->storage_used_human(),
		);
	}
}
