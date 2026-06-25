<?php
/**
 * One-time storage-path repair for media left in an inconsistent state by
 * pre-1.8.0 behavior. Two states are healed, both pre-existing (this service
 * does not create them — it repairs data already on disk):
 *
 *   D. Absolute file_path. The pre-1.8.0 plugin-migration importers (rtMedia,
 *      MediaPress, BuddyBoss) stored file_path as an absolute path referencing
 *      the source attachment in place. The local driver resolves
 *      `base_dir . file_path`, so an absolute path resolves nowhere and the
 *      full-size image 404s. Heal: re-sideload into uploads/wpmediaverse/ as a
 *      relative path via UploadService::sideload_external_file().
 *
 *   C. Stranded thumbnails. The pre-1.8.0 "Migrate all" moved only the original
 *      to cloud; thumbnails were left on local disk while file_url became a
 *      cloud URL. Location-based serving then resolves thumbnails to the cloud,
 *      where they do not exist -> 404. Heal: push the stranded variants to the
 *      active cloud driver (idempotent CloudOps::migrate_one) and refresh the
 *      thumbnail URL meta to the cloud URLs.
 *
 * Safety contract (why this is safe to auto-run on client sites):
 *   - Clean sites do nothing. Detection is a cheap COUNT plus a bounded
 *     (<=PROBE_LIMIT) precise probe; when both are empty no batch is scheduled
 *     and no file/network I/O happens.
 *   - Never synchronous on update. The Migrator only marks state 'pending';
 *     the work runs in bounded Action Scheduler batches once AS is ready.
 *   - Additive + copy-only. Repair copies files into the library and corrects
 *     file_path/file_url; the source attachment is never moved or deleted.
 *   - Idempotent + resumable. A healed row is skipped on re-run; a keyset
 *     cursor in the state option makes an interrupted run resume safely.
 *   - Opt-out. The `mvs_storage_repair_enabled` filter (default true) lets a
 *     site disable the whole pass from a mu-plugin.
 *
 * @package WPMediaVerse
 * @since   1.8.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Detects and heals pre-1.8.0 storage-path inconsistencies in bounded batches.
 */
class StorageRepairService {

	/**
	 * Action Scheduler hook that processes one batch.
	 */
	const REPAIR_HOOK = 'mvs_repair_storage_paths';

	/**
	 * Action Scheduler group.
	 */
	const AS_GROUP = 'wpmediaverse';

	/**
	 * State option: { status, cursor, scanned, healed_absolute, healed_stranded, failed }.
	 */
	const STATE_OPTION = 'mvs_storage_repair_state';

	/**
	 * Rows processed per batch. Small on purpose — a State-C row may perform
	 * several cloud uploads, so a conservative batch keeps the per-run cost (and
	 * cloud-API rate) bounded on large sites.
	 */
	const BATCH = 25;

	/**
	 * Upper bound on the State-C detection probe — never scans the whole corpus
	 * just to decide whether to schedule.
	 */
	const PROBE_LIMIT = 200;

	/**
	 * Storage service.
	 *
	 * @var StorageService
	 */
	private $storage;

	/**
	 * Upload service (owns sideload_external_file()).
	 *
	 * @var UploadService
	 */
	private $upload;

	/**
	 * Constructor.
	 *
	 * @param StorageService $storage Storage service.
	 * @param UploadService  $upload  Upload service.
	 */
	public function __construct( StorageService $storage, UploadService $upload ) {
		$this->storage = $storage;
		$this->upload  = $upload;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( self::REPAIR_HOOK, array( $this, 'process_batch' ), 10, 1 );
		// Lazy auto-start: the Migrator marks state 'pending' on update; once
		// Action Scheduler is ready we detect and (if needed) schedule batch 1.
		add_action( 'action_scheduler_init', array( $this, 'maybe_autostart' ) );
	}

	/**
	 * Owner escape hatch — return false to disable the repair entirely.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return (bool) apply_filters( 'mvs_storage_repair_enabled', true );
	}

	/**
	 * Mark a repair as pending so maybe_autostart() picks it up. Called by the
	 * Migrator on update — intentionally does no heavy work inline.
	 */
	public static function mark_pending(): void {
		$state = get_option( self::STATE_OPTION, array() );
		// Don't disturb an in-flight run.
		if ( is_array( $state ) && isset( $state['status'] ) && 'running' === $state['status'] ) {
			return;
		}
		update_option(
			self::STATE_OPTION,
			array(
				'status' => 'pending',
				'cursor' => 0,
			),
			false
		);
	}

	/**
	 * If a repair is pending and there is something to do, schedule batch 1.
	 * Runs once: flips state to 'running' (work to do) or 'done' (nothing).
	 */
	public function maybe_autostart(): void {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) || ( $state['status'] ?? '' ) !== 'pending' ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			$this->set_state( array( 'status' => 'disabled' ) );
			return;
		}

		$counts = $this->count_broken();
		if ( ! $counts['needs_repair'] ) {
			$this->set_state( array( 'status' => 'done' ) );
			return;
		}

		$this->set_state(
			array(
				'status'          => 'running',
				'cursor'          => 0,
				'scanned'         => 0,
				'healed_absolute' => 0,
				'healed_stranded' => 0,
				'failed'          => 0,
			)
		);
		$this->schedule_next( 0 );
	}

	/**
	 * Cheap detection: absolute-path COUNT + bounded precise State-C probe.
	 *
	 * @return array{absolute:int,stranded:bool,needs_repair:bool}
	 */
	public function count_broken(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_media_index';

		// LEFT(file_path,1)='/' detects absolute paths (a literal comparison —
		// no LIKE wildcard; file_path is an unindexed TEXT column either way).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$absolute = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('publish','draft') AND LEFT(file_path,1) = '/'" );

		$stranded = $this->probe_stranded();

		return array(
			'absolute'     => $absolute,
			'stranded'     => $stranded,
			'needs_repair' => ( $absolute > 0 || $stranded ),
		);
	}

	/**
	 * Bounded probe for State C: any public, cloud-located media whose thumbnail
	 * URL meta is still a local /wp-content/uploads/ URL. That meta is written at
	 * thumbnail-generation time — proper cloud upload/migrate records a cloud
	 * URL, so a local URL on a cloud-located row is a precise stranded signal
	 * (no network call, no false positive on healthy cloud media).
	 *
	 * @return bool
	 */
	private function probe_stranded(): bool {
		if ( 'local' === (string) get_option( 'mvs_storage_driver', 'local' ) ) {
			return false; // No cloud driver -> nothing to push.
		}

		global $wpdb;
		$idx  = $wpdb->prefix . 'mvs_media_index';
		$meta = $wpdb->prefix . 'mvs_media_meta';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT i.media_id
				 FROM {$idx} i
				 JOIN {$meta} m ON m.media_id = i.media_id AND m.meta_key IN ('thumb_large','thumb_medium','thumb_thumb')
				 WHERE i.status IN ('publish','draft')
				   AND i.privacy = 'public'
				   AND LEFT(i.file_path,1) != '/'
				   AND i.file_url != ''
				   AND i.file_url NOT LIKE %s
				   AND m.meta_value LIKE %s
				 LIMIT 1",
				'%/wp-content/uploads/%',
				'%/wp-content/uploads/%'
			)
		);

		return ! empty( $found );
	}

	/**
	 * Process one batch of candidate rows, then schedule the next until the
	 * cursor is exhausted.
	 *
	 * @param int $cursor Last processed media_id (keyset pagination).
	 */
	public function process_batch( int $cursor = 0 ): void {
		if ( ! self::is_enabled() ) {
			$this->set_state( array( 'status' => 'disabled' ) );
			return;
		}

		$ids = $this->candidate_ids( $cursor );
		if ( empty( $ids ) ) {
			$this->set_state( array( 'status' => 'done' ) );
			return;
		}

		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		foreach ( $ids as $media_id ) {
			$result                      = $this->repair_one( (int) $media_id );
			$state['scanned']            = ( (int) ( $state['scanned'] ?? 0 ) ) + 1;
			$state['healed_absolute']    = ( (int) ( $state['healed_absolute'] ?? 0 ) ) + ( 'absolute' === $result ? 1 : 0 );
			$state['healed_stranded']    = ( (int) ( $state['healed_stranded'] ?? 0 ) ) + ( 'stranded' === $result ? 1 : 0 );
			$state['failed']             = ( (int) ( $state['failed'] ?? 0 ) ) + ( 'failed' === $result ? 1 : 0 );
			$cursor                      = max( $cursor, (int) $media_id );
		}

		$state['status'] = 'running';
		$state['cursor'] = $cursor;
		update_option( self::STATE_OPTION, $state, false );

		$this->schedule_next( $cursor );
	}

	/**
	 * Next batch of candidate media ids after $cursor: rows with an absolute
	 * file_path (State D) OR public cloud-located rows with a stranded local
	 * thumbnail URL meta (State C). Targeted so healthy rows are never visited.
	 *
	 * @param int $cursor Keyset cursor (exclusive lower bound on media_id).
	 * @return int[]
	 */
	private function candidate_ids( int $cursor ): array {
		global $wpdb;
		$idx  = $wpdb->prefix . 'mvs_media_index';
		$meta = $wpdb->prefix . 'mvs_media_meta';

		$is_cloud = ( 'local' !== (string) get_option( 'mvs_storage_driver', 'local' ) );

		if ( $is_cloud ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT i.media_id
					 FROM {$idx} i
					 LEFT JOIN {$meta} m ON m.media_id = i.media_id AND m.meta_key IN ('thumb_large','thumb_medium','thumb_thumb')
					 WHERE i.status IN ('publish','draft')
					   AND i.media_id > %d
					   AND (
					        LEFT(i.file_path,1) = '/'
					        OR ( i.privacy = 'public' AND LEFT(i.file_path,1) != '/' AND i.file_url != '' AND i.file_url NOT LIKE %s AND m.meta_value LIKE %s )
					   )
					 ORDER BY i.media_id ASC
					 LIMIT %d",
					$cursor,
					'%/wp-content/uploads/%',
					'%/wp-content/uploads/%',
					self::BATCH
				)
			);
		} else {
			// Local driver active: only State D is actionable.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT i.media_id FROM {$idx} i
					 WHERE i.status IN ('publish','draft') AND i.media_id > %d AND LEFT(i.file_path,1) = '/'
					 ORDER BY i.media_id ASC LIMIT %d",
					$cursor,
					self::BATCH
				)
			);
		}

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Heal a single media row. Idempotent: a row already consistent is a no-op.
	 *
	 * @param int $media_id Media id.
	 * @return string 'absolute'|'stranded'|'noop'|'failed'
	 */
	public function repair_one( int $media_id ): string {
		$repo      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$file_path = (string) $repo->get_raw( $media_id, 'file_path' );
		$file_url  = (string) $repo->get_raw( $media_id, 'file_url' );
		$mime      = (string) $repo->get_raw( $media_id, 'file_type' );

		$media_type = (string) $repo->get_raw( $media_id, 'media_type' );
		if ( '' === $media_type && '' !== $mime ) {
			$media_type = $this->upload->get_media_type_public( $mime );
		}

		// State D — absolute file_path. Re-sideload into the library if the file
		// is reachable; otherwise leave it (cannot heal a missing source).
		if ( '' !== $file_path && '/' === $file_path[0] ) {
			if ( ! file_exists( $file_path ) ) {
				LoggerService::warning(
					'storage_repair',
					sprintf( 'Media #%d has an absolute file_path whose file is missing — left for manual review.', $media_id ),
					array( 'file_path' => $file_path )
				);
				return 'failed';
			}
			$rel = $this->upload->sideload_external_file( $media_id, $file_path, $media_type, $mime );
			return '' !== $rel ? 'absolute' : 'failed';
		}

		// State C — stranded thumbnails. Only actionable for public, cloud-located
		// media while a cloud driver is active.
		$slug = (string) get_option( 'mvs_storage_driver', 'local' );
		if ( 'local' !== $slug
			&& 'public' === (string) $repo->get_raw( $media_id, 'privacy' )
			&& '' !== $file_url
			&& false === strpos( $file_url, '/wp-content/uploads/' )
		) {
			$result = CloudOps::migrate_one( $media_id, 'local', $slug, false );
			if ( ! empty( $result['ok'] ) ) {
				// Refresh the thumbnail URL meta to the cloud URLs so the stranded
				// discriminator clears and a re-run is a clean no-op.
				$this->refresh_thumb_urls( $media_id, $slug );
				return 'migrated' === ( $result['status'] ?? '' ) ? 'stranded' : 'noop';
			}
			return 'failed';
		}

		return 'noop';
	}

	/**
	 * Rewrite the thumbnail URL meta (thumb_large / thumb_medium / thumb_thumb,
	 * incl. webp/avif siblings) to the active cloud driver's URLs, derived from
	 * the driver-agnostic *_path meta. Leaves rows untouched when a path is
	 * absent. Idempotent.
	 *
	 * @param int    $media_id Media id.
	 * @param string $slug     Active driver slug.
	 */
	private function refresh_thumb_urls( int $media_id, string $slug ): void {
		$driver = apply_filters( 'mvs_storage_driver', null, $slug );
		if ( ! $driver instanceof StorageDriverInterface ) {
			return;
		}
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		foreach ( array( 'thumb_large', 'thumb_medium', 'thumb_thumb' ) as $size_key ) {
			foreach ( array( '', '_webp', '_avif' ) as $fmt ) {
				$url_key  = $size_key . $fmt;
				$path_key = $size_key . $fmt . '_path';
				$rel      = (string) $repo->get_raw( $media_id, $path_key );
				if ( '' === $rel || '/' === $rel[0] ) {
					continue;
				}
				$url = (string) $driver->url( $rel );
				if ( '' !== $url ) {
					$repo->set( $media_id, $url_key, $url );
				}
			}
		}
	}

	/**
	 * Schedule the next batch via Action Scheduler (no-op without AS).
	 *
	 * @param int $cursor Cursor to resume from.
	 */
	private function schedule_next( int $cursor ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return; // No Action Scheduler — repair is resumed on the next admin load via WP-CLI / manual run.
		}
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::REPAIR_HOOK, array( $cursor ), self::AS_GROUP ) ) {
			return;
		}
		as_enqueue_async_action( self::REPAIR_HOOK, array( $cursor ), self::AS_GROUP );
	}

	/**
	 * Merge a patch into the state option.
	 *
	 * @param array $patch Keys to merge.
	 */
	private function set_state( array $patch ): void {
		$state = get_option( self::STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();
		update_option( self::STATE_OPTION, array_merge( $state, $patch ), false );
	}

	/**
	 * Run the repair synchronously in bounded loops — for WP-CLI / headless use.
	 *
	 * @param bool $dry_run When true, only report counts; make no changes.
	 * @return array Summary counts.
	 */
	public function run_to_completion( bool $dry_run = false ): array {
		$counts = $this->count_broken();
		if ( $dry_run || ! $counts['needs_repair'] ) {
			return array(
				'dry_run'      => $dry_run,
				'absolute'     => $counts['absolute'],
				'stranded_any' => $counts['stranded'],
				'needs_repair' => $counts['needs_repair'],
			);
		}

		$cursor          = 0;
		$scanned         = 0;
		$healed_absolute = 0;
		$healed_stranded = 0;
		$failed          = 0;

		do {
			$ids = $this->candidate_ids( $cursor );
			foreach ( $ids as $media_id ) {
				$result = $this->repair_one( (int) $media_id );
				++$scanned;
				$healed_absolute += ( 'absolute' === $result ? 1 : 0 );
				$healed_stranded += ( 'stranded' === $result ? 1 : 0 );
				$failed          += ( 'failed' === $result ? 1 : 0 );
				$cursor           = max( $cursor, (int) $media_id );
			}
		} while ( ! empty( $ids ) );

		$this->set_state(
			array(
				'status'          => 'done',
				'cursor'          => $cursor,
				'scanned'         => $scanned,
				'healed_absolute' => $healed_absolute,
				'healed_stranded' => $healed_stranded,
				'failed'          => $failed,
			)
		);

		return array(
			'dry_run'         => false,
			'scanned'         => $scanned,
			'healed_absolute' => $healed_absolute,
			'healed_stranded' => $healed_stranded,
			'failed'          => $failed,
		);
	}
}
