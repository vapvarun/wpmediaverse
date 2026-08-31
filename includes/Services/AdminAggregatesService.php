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
		// The pre-2.4.0 key. Kept so an upgraded site whose cache still holds
		// the old value gets it cleared on the next write rather than carrying
		// a number nothing writes any more.
		'admin_total_media',
		'admin_total_media_v2',
		'admin_total_documents',
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
	/**
	 * Engagement totals for the Stats page, optionally bounded by date.
	 *
	 * Views, reactions, comments and shares in ONE row rather than four calls —
	 * the Stats page shows them together and they come from the same table.
	 *
	 * Lives here rather than in `MediaRepository` because Rule 16 puts site-wide
	 * aggregates in this service, and because the join is `mvs_media_stats` ×
	 * `mvs_media_index`: the index side contributes only `status = 'publish'` and
	 * the date floor, so this is an aggregate that happens to need the index, not
	 * an index query. It moved out of `Admin\StatsPage` for Rule 7 — the page was
	 * building the join by hand.
	 *
	 * The unbounded case does not touch the index at all: with no date filter
	 * every stats row already belongs to a media item, so the join only costs
	 * work without changing the answer.
	 *
	 * @since 2.4.0
	 *
	 * @param string $since Optional `Y-m-d H:i:s` floor on the media's creation.
	 * @return array{total_views:int, total_reactions:int, total_comments:int, total_shares:int}
	 */
	public function engagement_totals( string $since = '' ): array {
		$key = 'admin_engagement_totals_' . ( '' === $since ? 'all' : md5( $since ) );

		$totals = $this->cache->remember_persistent(
			$key,
			static function () use ( $since ): array {
				global $wpdb;

				$stats = $wpdb->prefix . 'mvs_media_stats';
				$sums  = 'COALESCE(SUM(s.views), 0) AS total_views,
					COALESCE(SUM(s.reactions), 0) AS total_reactions,
					COALESCE(SUM(s.comments), 0) AS total_comments,
					COALESCE(SUM(s.shares), 0) AS total_shares';

				if ( '' === $since ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$row = $wpdb->get_row( "SELECT {$sums} FROM {$stats} s", ARRAY_A );
				} else {
					$index = $wpdb->prefix . 'mvs_media_index';
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$row = $wpdb->get_row(
						$wpdb->prepare(
							"SELECT {$sums}
							   FROM {$stats} s
							   INNER JOIN {$index} m ON m.media_id = s.media_id
							  WHERE m.status = 'publish' AND m.created_at >= %s",
							$since
						),
						ARRAY_A
					);
				}

				return array(
					'total_views'     => (int) ( $row['total_views'] ?? 0 ),
					'total_reactions' => (int) ( $row['total_reactions'] ?? 0 ),
					'total_comments'  => (int) ( $row['total_comments'] ?? 0 ),
					'total_shares'    => (int) ( $row['total_shares'] ?? 0 ),
				);
			}
		);

		return is_array( $totals ) ? $totals : array(
			'total_views'     => 0,
			'total_reactions' => 0,
			'total_comments'  => 0,
			'total_shares'    => 0,
		);
	}

	/**
	 * The most-viewed published media, for the Stats page's leaderboard.
	 *
	 * Same reasoning as `engagement_totals()`: a `mvs_media_stats` ×
	 * `mvs_media_index` join whose index side contributes only the publish gate,
	 * the date floor and the title. It moved out of `Admin\StatsPage` for Rule 7.
	 *
	 * @since 2.4.0
	 *
	 * ONE method for both the on-screen leaderboard and the CSV export. They are
	 * the same question with a different row limit and a type filter, and the
	 * export exists to be reconciled against the page — two queries that could
	 * drift is exactly how an owner ends up with a CSV that disagrees with the
	 * dashboard it came from (Rule 14).
	 *
	 * @since 2.4.0
	 *
	 * @param string   $since       Optional `Y-m-d H:i:s` floor on the media's creation.
	 * @param int      $limit       Rows to return.
	 * @param string[] $media_types Optional type allowlist; empty means every type.
	 * @return array<int, array<string, mixed>> media_id, views, reactions, comments, shares, post_title.
	 */
	public function top_media_by_views( string $since = '', int $limit = 10, array $media_types = array() ): array {
		$limit = max( 1, min( 500, $limit ) );
		$key   = 'admin_top_media_' . md5( $since . '|' . $limit . '|' . implode( ',', $media_types ) );

		$rows = $this->cache->remember_persistent(
			$key,
			static function () use ( $since, $limit, $media_types ): array {
				global $wpdb;

				$stats = $wpdb->prefix . 'mvs_media_stats';
				$index = $wpdb->prefix . 'mvs_media_index';

				$where  = "m.status = 'publish'";
				$params = array();

				if ( '' !== $since ) {
					$where   .= ' AND m.created_at >= %s';
					$params[] = $since;
				}

				if ( $media_types ) {
					list( $type_sql, $type_params ) = \WPMediaVerse\Core\MediaTypes::in_clause( $media_types, 'm.media_type' );
					$where                         .= ' AND ' . $type_sql;
					$params                         = array_merge( $params, $type_params );
				}

				$params[] = $limit;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT s.media_id, s.views, s.reactions, s.comments, s.shares, m.title AS post_title
						   FROM {$stats} s
						   INNER JOIN {$index} m ON m.media_id = s.media_id
						  WHERE {$where}
						  ORDER BY s.views DESC
						  LIMIT %d",
						...$params
					),
					ARRAY_A
				);

				return is_array( $found ) ? $found : array();
			}
		);

		return is_array( $rows ) ? $rows : array();
	}

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
	 * Media count and total bytes per storage backend, inferred from file_url.
	 *
	 * Moved here from `CloudOps` in 2.4.0, and the move is the point rather
	 * than a side effect: this is a site-wide COUNT + SUM over
	 * `mvs_media_index`, which coding Rule 16 puts in this service and nowhere
	 * else. It was the last aggregate still living outside it.
	 *
	 * NOT cached, deliberately, unlike everything above. The Storage Overview
	 * exists to be watched WHILE a migration runs — a cached number would sit
	 * still through the operation it is reporting on, which is worse than
	 * slightly slower.
	 *
	 * `CloudOps::counts_by_service()` remains as a delegate: it is public API
	 * that Pro's admin calls, and Production Rule 2 does not allow a rename
	 * without one.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string,array{label:string,count:int,bytes:int}>
	 */
	public function media_counts_by_service(): array {
		global $wpdb;

		$table = $wpdb->prefix . 'mvs_media_index';

		// LIKE patterns per service: provider host(s) + any configured domain.
		$like = static function ( $needle ) use ( $wpdb ) {
			return '%' . $wpdb->esc_like( $needle ) . '%';
		};

		$services = array(
			'local'    => array(
				'label'    => __( 'Local (WordPress uploads)', 'wpmediaverse' ),
				'patterns' => array( '/wp-content/uploads/' ),
			),
			's3'       => array(
				'label'    => __( 'Amazon S3', 'wpmediaverse' ),
				'patterns' => array_filter( array( '.amazonaws.com/', (string) get_option( 'mvs_pro_s3_cdn_domain', '' ) ) ),
			),
			'bunnycdn' => array(
				'label'    => __( 'BunnyCDN', 'wpmediaverse' ),
				'patterns' => array_filter( array( '.b-cdn.net/', (string) get_option( 'mvs_pro_bunny_cdn_hostname', '' ) ) ),
			),
			'r2'       => array(
				'label'    => __( 'Cloudflare R2', 'wpmediaverse' ),
				'patterns' => array_filter( array( '.r2.cloudflarestorage.com/', '.r2.dev/', (string) get_option( 'mvs_pro_r2_cdn_domain', '' ) ) ),
			),
			'dospaces' => array(
				'label'    => __( 'DigitalOcean Spaces', 'wpmediaverse' ),
				'patterns' => array_filter( array( '.digitaloceanspaces.com/', (string) get_option( 'mvs_pro_do_cdn_domain', '' ) ) ),
			),
		);

		$out       = array();
		$base      = "status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != ''";
		$accounted = array(); // collect non-local patterns to derive `other`.

		foreach ( $services as $slug => $svc ) {
			// Every service carries at least its provider host literal, so the
			// filtered pattern list is always non-empty.
			$patterns = array_values( array_unique( array_filter( $svc['patterns'] ) ) );
			$clauses  = array();
			$params   = array();
			foreach ( $patterns as $p ) {
				$clauses[] = 'file_url LIKE %s';
				$params[]  = $like( $p );
				if ( 'local' !== $slug ) {
					$accounted[] = $like( $p );
				}
			}
			$where = $base . ' AND (' . implode( ' OR ', $clauses ) . ')';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row          = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, COALESCE(SUM(file_size),0) AS b FROM {$table} WHERE {$where}", ...$params ), ARRAY_A );
			$out[ $slug ] = array(
				'label' => $svc['label'],
				'count' => (int) ( $row['c'] ?? 0 ),
				'bytes' => (int) ( $row['b'] ?? 0 ),
			);
		}

		// `other`: has file_path, not local, and matches no known cloud host.
		$other_where  = $base . ' AND file_url NOT LIKE %s';
		$other_params = array( $like( '/wp-content/uploads/' ) );
		foreach ( $accounted as $pat ) {
			$other_where   .= ' AND file_url NOT LIKE %s';
			$other_params[] = $pat;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$other        = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS c, COALESCE(SUM(file_size),0) AS b FROM {$table} WHERE {$other_where}", ...$other_params ), ARRAY_A );
		$out['other'] = array(
			'label' => __( 'Other / external', 'wpmediaverse' ),
			'count' => (int) ( $other['c'] ?? 0 ),
			'bytes' => (int) ( $other['b'] ?? 0 ),
		);

		return $out;
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
