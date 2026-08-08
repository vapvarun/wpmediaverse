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
	 * Migrate ONE media row from one driver to another — original PLUS every
	 * stored variant (thumbnails, WebP/AVIF siblings, video/audio posters).
	 *
	 * Atomic per media: the destination receives the full file set before
	 * `mvs_media_index.file_url` is flipped and any source copy is deleted.
	 * If any file fails to reach the destination, nothing is flipped and
	 * nothing is deleted, so the media stays fully served from the source and
	 * the row can be retried on the next run. This is what keeps the
	 * location-based serving contract (`StorageService::get_driver_for_location()`,
	 * which derives a media's driver from the original's `file_url`) honest:
	 * after a successful migration the original AND its thumbnails all live on
	 * the destination, so thumbnails resolve to the same place as the original
	 * instead of 404ing on a CDN they were never copied to.
	 *
	 * Stages:
	 *   1. Validate row + privacy gate + resolve drivers.
	 *   2. Phase 1 — copy original + every variant to the destination (verify
	 *      each). Files already present are skipped; files missing from BOTH
	 *      source and destination are skipped (stale meta, nothing to move).
	 *   3. Phase 2 (only if Phase 1 fully succeeded) — refresh `file_url` to the
	 *      destination's URL for the original, then optionally delete every
	 *      source copy.
	 *
	 * @param int    $media_id    Media ID.
	 * @param string $from        Source driver slug.
	 * @param string $to          Destination driver slug.
	 * @param bool   $keep_source If true, source files are kept after a verified upload.
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

		// Full file set for this media — original + every recorded variant.
		// Same authoritative enumerator the orphan-cleanup path uses, so a
		// migration moves exactly what an uninstall would remove (no thumbnail
		// left behind on the old driver). Falls back to the original alone if
		// the repository is unavailable.
		$paths = self::stored_paths( $media_id, $rel_path );

		// Phase 1 — copy every file to the destination and verify. Track which
		// tmp dirs we created so we can clean them all up at the end.
		$tmp_dirs    = array();
		$moved_any   = false;
		$failed_path = '';

		foreach ( $paths as $path ) {
			$result = self::copy_to_dest( $source_driver, $dest_driver, $path, $tmp_dirs );
			if ( 'failed' === $result ) {
				$failed_path = $path;
				break;
			}
			if ( 'moved' === $result ) {
				$moved_any = true;
			}
		}

		// Any hard failure → abort without flipping file_url or deleting
		// sources. The media stays fully served from the source; the row is
		// retried next run (already-copied files short-circuit via exists()).
		if ( '' !== $failed_path ) {
			self::cleanup_tmp_dirs( $tmp_dirs );
			return array(
				'ok'     => false,
				'status' => 'failed',
				'error'  => 'transfer failed for ' . $failed_path,
			);
		}

		// Phase 2 — destination now holds the full set. Flip file_url to the
		// destination's URL for the ORIGINAL (that is the signal
		// get_driver_for_location() reads), then optionally delete sources.
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

		// Phase 2b — repoint every stored variant URL meta at the destination.
		// The variant FILES already moved (stored_paths() collects every
		// `%_path` meta, which includes the WebP/AVIF siblings), but their URL
		// metas kept the old base. On a CDN migration that left `thumb_*_webp`
		// pointing into wp-content/uploads/wpmediaverse/ — the directory
		// MediaVerse locks with `Deny from all` — so admin links to them 403'd.
		// The read side no longer trusts these metas when a `_path` exists
		// (MediaUrl::variant_url), so this is hygiene rather than the fix for
		// Basecamp #10162798416; it keeps the stored data honest.
		self::refresh_variant_urls( $media_id, $dest_driver );

		if ( ! $keep_source ) {
			foreach ( $paths as $path ) {
				$source_driver->delete( $path );
			}
		}

		self::cleanup_tmp_dirs( $tmp_dirs );

		return array(
			'ok'     => true,
			'status' => $moved_any ? 'migrated' : 'skipped',
		);
	}

	/**
	 * Repoint stored variant URL metas at a driver after a migration.
	 *
	 * Walks every `<key>_path` meta and rewrites its sibling `<key>` URL meta
	 * to the destination driver's URL. Generic on purpose: it covers
	 * `thumb_<size>`, `thumb_<size>_webp`, `thumb_<size>_avif`, `original_webp`
	 * and `original_avif` without enumerating them, so a future variant format
	 * is handled the day it is added.
	 *
	 * Only rewrites keys that already hold a URL — an absent URL meta means the
	 * variant was never recorded on the legacy path and does not need one.
	 *
	 * @since 2.3.1
	 *
	 * @param int                    $media_id    Media ID.
	 * @param StorageDriverInterface $dest_driver Driver the files now live on.
	 * @return int Number of URL metas rewritten.
	 */
	private static function refresh_variant_urls( int $media_id, $dest_driver ): int {
		global $wpdb;

		if ( $media_id <= 0 || ! is_object( $dest_driver ) || ! method_exists( $dest_driver, 'url' ) ) {
			return 0;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM {$wpdb->prefix}mvs_media_meta WHERE media_id = %d AND meta_key LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$media_id,
				'%_path'
			)
		);
		if ( empty( $rows ) ) {
			return 0;
		}

		$repo    = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$updated = 0;
		foreach ( $rows as $row ) {
			$rel_path = (string) $row->meta_value;
			if ( '' === $rel_path ) {
				continue;
			}

			// `thumb_large_webp_path` -> `thumb_large_webp`. `file_path` has no
			// sibling URL meta of this shape (file_url lives on the index row
			// and was flipped above), so skip it.
			$url_key = (string) preg_replace( '/_path$/', '', (string) $row->meta_key );
			if ( '' === $url_key || 'file' === $url_key ) {
				continue;
			}

			if ( '' === (string) $repo->get_raw( $media_id, $url_key ) ) {
				continue;
			}

			$new_url = (string) $dest_driver->url( $rel_path );
			if ( '' !== $new_url ) {
				$repo->set( $media_id, $url_key, $new_url );
				++$updated;
			}
		}

		return $updated;
	}

	/**
	 * Copy ONE relative path to the destination driver and verify it landed.
	 *
	 * Idempotent: a file already on the destination is reported as 'skipped'.
	 * A file missing from BOTH source and destination is also 'skipped' (stale
	 * meta — there is nothing to move and the variant is already absent
	 * everywhere). Only a genuine download/upload/verify failure is 'failed'.
	 *
	 * @param StorageDriverInterface $source_driver Source driver.
	 * @param StorageDriverInterface $dest_driver   Destination driver.
	 * @param string                 $rel_path      Relative path to copy.
	 * @param array                  $tmp_dirs      Collected tmp dirs (by reference).
	 * @return string 'moved'|'skipped'|'failed'
	 */
	private static function copy_to_dest( $source_driver, $dest_driver, string $rel_path, array &$tmp_dirs ): string {
		if ( '' === $rel_path ) {
			return 'skipped';
		}

		if ( $dest_driver->exists( $rel_path ) ) {
			return 'skipped';
		}

		// Not on the destination — must be readable on the source to move it.
		// If it is absent from the source too, the variant is already gone
		// (stale meta); skip rather than fail the whole media.
		if ( ! $source_driver->exists( $rel_path ) ) {
			return 'skipped';
		}

		$tmp_path   = trailingslashit( get_temp_dir() ) . 'mvs-cloudops-' . uniqid() . '/' . ltrim( $rel_path, '/' );
		$tmp_dirs[] = dirname( $tmp_path );

		if ( ! $source_driver->download( $rel_path, $tmp_path ) ) {
			return 'failed';
		}
		if ( ! $dest_driver->store( $tmp_path, $rel_path ) ) {
			return 'failed';
		}
		if ( ! $dest_driver->exists( $rel_path ) ) {
			return 'failed';
		}

		return 'moved';
	}

	/**
	 * Best-effort removal of the temp files/dirs created during a migration.
	 *
	 * @param array $tmp_dirs Tmp directory paths.
	 * @return void
	 */
	private static function cleanup_tmp_dirs( array $tmp_dirs ): void {
		foreach ( array_unique( $tmp_dirs ) as $tmp_dir ) {
			if ( ! is_dir( $tmp_dir ) ) {
				continue;
			}
			$files = glob( trailingslashit( $tmp_dir ) . '*' ) ?: array();
			foreach ( $files as $file ) {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best-effort cleanup of a plugin-controlled tmp dir; WP_Filesystem isn't initialized in this CLI/cron path and would add a credentials prompt for an internal-only path.
			@rmdir( $tmp_dir );
		}
	}

	/**
	 * Resolve the full set of stored relative paths for a media (original +
	 * every recorded variant), via the shared repository enumerator.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $rel_path Original relative path (fallback).
	 * @return string[] Unique non-empty relative paths.
	 */
	private static function stored_paths( int $media_id, string $rel_path ): array {
		$paths = array();

		if ( class_exists( '\WPMediaVerse\Core\Plugin' ) ) {
			$container = \WPMediaVerse\Core\Plugin::container();
			$repo      = $container ? $container->get( 'media_repository' ) : null;
			if ( $repo && method_exists( $repo, 'get_stored_file_paths' ) ) {
				$paths = (array) $repo->get_stored_file_paths( $media_id );
			}
		}

		if ( '' !== $rel_path ) {
			$paths[] = $rel_path;
		}

		$paths = array_filter(
			array_map( 'strval', $paths ),
			static function ( $p ) {
				return '' !== $p;
			}
		);

		return array_values( array_unique( $paths ) );
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
		$thumbs_kept    = 0;
		if ( ! $keep_thumbs ) {
			// Delete each recorded variant's local copy ONLY when that exact
			// file is verified on the cloud. Pre-1.8.0 this globbed sibling
			// files and deleted them gated solely on the ORIGINAL being on the
			// cloud — so a migration that moved only the original (the pre-1.8.0
			// migrate_one behaviour) followed by "Free up server space"
			// permanently deleted thumbnails that were never uploaded, turning
			// every thumbnail into a 404. Verify each file on its own; keep any
			// the cloud can't confirm. (BC #10029395885 follow-up.)
			foreach ( self::stored_paths( $media_id, $rel_path ) as $path ) {
				if ( $path === $rel_path ) {
					continue; // Original handled above.
				}
				$variant_full = trailingslashit( $wpmv_local_dir ) . $path;
				if ( ! is_file( $variant_full ) ) {
					continue;
				}
				if ( ! $driver->exists( $path ) ) {
					++$thumbs_kept;
					continue;
				}
				wp_delete_file( $variant_full );
				++$thumbs_removed;
			}
		}

		return array(
			'ok'             => true,
			'status'         => 'cleaned',
			'thumbs_removed' => $thumbs_removed,
			'thumbs_kept'    => $thumbs_kept,
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

		// Counted from the index, NOT derived as `total - needs_migration`.
		// That subtraction over-reported: `total` spans every privacy level
		// while `needs_migration` filters to privacy='public', so private rows
		// fell out of the subtrahend and were silently reported as "in cloud"
		// — while the private tile counted them a second time. On a library of
		// 64 with 4 private rows the panel showed "4 in cloud" against 0 files
		// actually on the CDN, and the three tiles summed past the total.
		//
		// This is the exact complement of `needs_migration` inside the public
		// set, so on_cloud + needs_migration == public rows, by construction.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$on_cloud = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status IN ('publish','draft') AND file_path IS NOT NULL AND file_path != '' AND privacy = 'public' AND file_url NOT LIKE 'http%/wp-content/uploads/%'" );

		return array(
			'total'           => $total,
			'needs_migration' => $still_local,
			'non_public_kept' => $non_public,
			'on_cloud'        => $on_cloud,
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
