<?php
/**
 * Cloud operations service.
 *
 * Per-row helpers for cloud-storage migrations, thumbnail backfill, and
 * local cleanup. Both the CLI commands and the Pro admin UI call these
 * methods to keep the implementation in one place.
 *
 * @package WPMediaVerse
 * @since   1.2.1
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Atomic per-media cloud operations + queue/status helpers.
 */
class CloudOps {

	/**
	 * Status option key. Stores the most recent admin-triggered job state.
	 *
	 * @var string
	 */
	const STATUS_OPTION = 'mvs_cloud_ops_status';

	/**
	 * Migrate ONE media row's file from one driver to another.
	 *
	 * Stages mirror the CLI:
	 *   1. Skip if destination already has the file (idempotent).
	 *   2. Download source to temp.
	 *   3. Upload temp to destination.
	 *   4. Verify destination has the file.
	 *   5. Refresh `mvs_media_index.file_url` to the destination's URL.
	 *   6. Optionally delete source.
	 *
	 * @param int    $media_id    Media ID.
	 * @param string $from        Source driver slug.
	 * @param string $to          Destination driver slug.
	 * @param bool   $keep_source If true, source file is kept after a verified upload.
	 * @return array { ok: bool, status: 'migrated'|'skipped'|'failed', error?: string }
	 */
	public static function migrate_one( int $media_id, string $from, string $to, bool $keep_source ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT media_id, file_path, privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d AND status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != ''", $media_id ) );
		if ( ! $row ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'media row not found',
			);
		}

		// Defense in depth — privacy gate. Cloud buckets we support today
		// (S3, BunnyCDN) are public-readable. Uploading a non-public media's
		// original to such a bucket leaks it to anyone with the URL. Callers
		// MUST gate at the query level (`WHERE privacy = 'public'`); this
		// check is the safety net if a caller forgets. Filter override
		// `mvs_cloudops_allow_non_public_to_cloud` lets customers with
		// private cloud buckets bypass after they've taken responsibility
		// for restricting public access at the bucket / CDN layer.
		if ( 'public' !== (string) $row->privacy && 'local' !== $to ) {
			$allow = (bool) apply_filters( 'mvs_cloudops_allow_non_public_to_cloud', false, $media_id, (string) $row->privacy, $to );
			if ( ! $allow ) {
				return array(
					'ok'     => false,
					'status' => 'skipped-non-public',
					'error'  => 'non-public media not migrated to cloud (would leak via public bucket); set mvs_cloudops_allow_non_public_to_cloud filter if your cloud bucket is private',
				);
			}
		}

		$rel_path      = (string) $row->file_path;
		$source_driver = self::resolve_driver( $from );
		$dest_driver   = self::resolve_driver( $to );

		if ( ! $source_driver || ! $dest_driver ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'driver not available',
			);
		}

		if ( $dest_driver->exists( $rel_path ) ) {
			$expected = $dest_driver->url( $rel_path );
			if ( '' !== $expected ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->prefix . 'mvs_media_index',
					array( 'file_url' => $expected ),
					array( 'media_id' => $media_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
			return array(
				'ok'     => true,
				'status' => 'skipped',
			);
		}

		$tmp_path = trailingslashit( get_temp_dir() ) . 'mvs-cloudops-' . uniqid() . '/' . ltrim( $rel_path, '/' );
		if ( ! $source_driver->download( $rel_path, $tmp_path ) ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'download failed',
			);
		}

		if ( ! $dest_driver->store( $tmp_path, $rel_path ) ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'upload failed',
			);
		}

		if ( ! $dest_driver->exists( $rel_path ) ) {
			if ( file_exists( $tmp_path ) ) {
				wp_delete_file( $tmp_path );
			}
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'verify failed',
			);
		}

		$new_url = $dest_driver->url( $rel_path );
		if ( '' !== $new_url ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->prefix . 'mvs_media_index',
				array( 'file_url' => $new_url ),
				array( 'media_id' => $media_id ),
				array( '%s' ),
				array( '%d' )
			);
		}

		if ( ! $keep_source ) {
			$source_driver->delete( $rel_path );
		}

		if ( file_exists( $tmp_path ) ) {
			wp_delete_file( $tmp_path );
		}
		$tmp_dir = dirname( $tmp_path );
		if ( is_dir( $tmp_dir ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of a plugin-controlled tmp dir; WP_Filesystem isn't initialized in this CLI/cron path and would add a credentials prompt for an internal-only path.
			@rmdir( $tmp_dir );
		}

		return array(
			'ok'     => true,
			'status' => 'migrated',
		);
	}

	/**
	 * Delete the local copy of ONE public media after verifying it exists on
	 * the active cloud driver. Includes sibling thumbnail variants unless
	 * `$keep_thumbs` is true. Idempotent — already-clean media returns
	 * `status='skipped-already-clean'`.
	 *
	 * NEVER processes non-public media (privacy != public) — those still
	 * route through the gated /serve proxy which (as of 1.2.2) only reads
	 * from local disk.
	 *
	 * @param int  $media_id    Media ID.
	 * @param bool $keep_thumbs If true, only the original is deleted.
	 * @return array { ok: bool, status: ..., thumbs_removed: int, error?: string }
	 */
	public static function cleanup_local_one( int $media_id, bool $keep_thumbs ): array {
		global $wpdb;

		$driver_slug = (string) get_option( 'mvs_storage_driver', 'local' );
		if ( 'local' === $driver_slug ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'active driver is local',
			);
		}

		$driver = self::resolve_driver( $driver_slug );
		if ( ! $driver ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'driver not available',
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT media_id, file_path, privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d AND status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != ''", $media_id ) );
		if ( ! $row ) {
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'media row not found',
			);
		}

		if ( 'public' !== (string) $row->privacy ) {
			return array(
				'ok'     => false,
				'status' => 'skipped-non-public',
				'error'  => 'private media keeps local copy until cloud-aware /serve ships',
			);
		}

		$upload_basedir = (string) ( wp_upload_dir()['basedir'] ?? '' );
		$wpmv_local_dir = trailingslashit( $upload_basedir ) . 'wpmediaverse';
		$rel_path       = (string) $row->file_path;
		$local_full     = trailingslashit( $wpmv_local_dir ) . $rel_path;

		if ( ! file_exists( $local_full ) ) {
			return array(
				'ok'     => true,
				'status' => 'skipped-already-clean',
			);
		}

		if ( ! $driver->exists( $rel_path ) ) {
			return array(
				'ok'     => false,
				'status' => 'skipped-not-on-cloud',
				'error'  => 'cloud verify failed; local kept as safety',
			);
		}

		wp_delete_file( $local_full );

		$thumbs_removed = 0;
		if ( ! $keep_thumbs ) {
			$dir      = dirname( $local_full );
			$base     = pathinfo( $local_full, PATHINFO_FILENAME );
			$siblings = glob( $dir . '/' . $base . '-*x*.*' ) ?: array();
			foreach ( $siblings as $sibling ) {
				if ( is_file( $sibling ) ) {
					wp_delete_file( $sibling );
					++$thumbs_removed;
				}
			}
		}

		return array(
			'ok'             => true,
			'status'         => 'cleaned',
			'thumbs_removed' => $thumbs_removed,
		);
	}

	/**
	 * Count media that need each kind of operation.
	 *
	 * Called by the admin UI to render the storage-management stat tiles
	 * (in cloud / on server / private kept) + button enabled/disabled state.
	 * All counts respect the same WHERE clauses the admin handlers use, so
	 * the numbers match what the buttons will actually process.
	 *
	 * @return array{total:int, needs_migration:int, non_public_kept:int}
	 */
	public static function count_candidates(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != ''" );

		// Must match query_public_cloud_candidates( $limit, true ) exactly — the
		// migrate flow only processes privacy='public' rows, so the count has to
		// share the privacy filter or it permanently over-counts (private/members
		// media is always kept local and is never migrated → backlog never hits 0).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$still_local = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != '' AND privacy = 'public' AND file_url LIKE 'http%/wp-content/uploads/%'" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$non_public = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != '' AND privacy != 'public'" );

		return array(
			'total'           => $total,
			'needs_migration' => $still_local,
			'non_public_kept' => $non_public,
		);
	}

	/**
	 * Per-service media distribution — how many files (and total bytes) live on
	 * each storage backend, inferred from the stored `file_url` host. Drives the
	 * admin Storage Overview so an operator can see where media is before/after
	 * switching the active driver.
	 *
	 * Local is matched by the uploads-dir URL; each cloud service by its
	 * provider host pattern plus any configured custom/CDN domain. Anything that
	 * matches none is bucketed as `other` (legacy/imported/external URLs).
	 *
	 * @since 1.4.0
	 *
	 * @return array<string,array{label:string,count:int,bytes:int}>
	 */
	public static function counts_by_service(): array {
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
	 * Resolve a driver slug to a driver instance via the canonical filter.
	 *
	 * @param string $slug Driver slug.
	 * @return StorageDriverInterface|null
	 */
	private static function resolve_driver( string $slug ): ?StorageDriverInterface {
		$driver = apply_filters( 'mvs_storage_driver', null, $slug );

		if ( $driver instanceof StorageDriverInterface ) {
			return $driver;
		}

		if ( 'local' === $slug ) {
			return new LocalDriver();
		}

		return null;
	}

	/**
	 * Read the current job status (set by admin UI). Used by the admin
	 * page to display "Migration in progress: 42/100" between page loads.
	 *
	 * @return array { state, op, processed, total, started_at, finished_at, last_error }
	 */
	public static function get_status(): array {
		$default = array(
			'state'       => 'idle',
			'op'          => '',
			'processed'   => 0,
			'total'       => 0,
			'started_at'  => 0,
			'finished_at' => 0,
			'last_error'  => '',
		);
		$saved   = get_option( self::STATUS_OPTION, array() );
		return array_merge( $default, is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Persist the current job status. Called by the admin handlers between
	 * batches so the UI can show progress on the next page load.
	 *
	 * @param array $patch Fields to update — merged with current status.
	 */
	public static function set_status( array $patch ): void {
		$current = self::get_status();
		update_option( self::STATUS_OPTION, array_merge( $current, $patch ), false );
	}
}
