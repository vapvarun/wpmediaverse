<?php
/**
 * Orphaned-file cleanup cycle.
 *
 * When a media item is torn down, MediaRepository::delete_cascade() fires
 * `mvs_media_files_orphaned` with every relative path the item owned (the
 * original plus all thumbnail / WebP / AVIF / poster variants) BEFORE the meta
 * rows are deleted. This service queues those paths for deletion and reclaims
 * the bytes from both local disk and cloud asynchronously via Action Scheduler.
 *
 * Both delete paths funnel through delete_cascade() — the REST media delete
 * (MediaController::delete_item) and account deletion (UserDeletionService) —
 * so this single listener covers every way media leaves the system. Without it
 * the REST path would delete only the original (from the active driver, missing
 * variants and cloud-resident files) and account deletion would delete no files at
 * all, leaving orphaned bytes on disk and the CDN.
 *
 * Async-first is deliberate: a bulk delete can orphan hundreds of paths and
 * each cloud-resident path costs an HTTP DELETE — running those inline would
 * time out the admin request that triggered them. Reliability comes from the
 * retry cycle in process(), not from deleting inline.
 *
 * @package WPMediaVerse
 * @since   1.6.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Async cleanup of a deleted media's files across every storage driver.
 */
class StorageCleanupService {

	/**
	 * Action Scheduler / cron hook that processes a batch of orphaned paths.
	 */
	const CLEANUP_HOOK = 'mvs_cleanup_media_files';

	/**
	 * Action Scheduler group.
	 *
	 * @deprecated 2.7.0 Removed in 4.0.0. Every MediaVerse job uses \WPMediaVerse\Core\Jobs::GROUP.
	 */
	const AS_GROUP = \WPMediaVerse\Core\Jobs::GROUP;

	/**
	 * Seconds added per failed attempt before the next retry (5 minutes, then 10).
	 */
	const RETRY_STEP_SECONDS = 5 * MINUTE_IN_SECONDS;

	/**
	 * Maximum delete attempts per path batch (the first try plus two retries) before giving up with an error log.
	 */
	const MAX_ATTEMPTS = 3;

	/**
	 * Storage service (driver factory).
	 *
	 * @var StorageService
	 */
	private $storage;

	/**
	 * Paths orphaned during this request, queued together at shutdown.
	 *
	 * @var string[]
	 */
	private $pending = array();

	/**
	 * Encoded size cap per queued action's paths. Action Scheduler refuses args over 8,000 JSON characters (and the
	 * refused paths would be orphaned for good), so a bulk delete is packed into as few actions as fit under it.
	 */
	const ARGS_BUDGET = 7000;

	/**
	 * Constructor.
	 *
	 * @param StorageService $storage Storage service.
	 */
	public function __construct( StorageService $storage ) {
		$this->storage = $storage;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'mvs_media_files_orphaned', array( $this, 'enqueue' ), 10, 2 );
		add_action( self::CLEANUP_HOOK, array( $this, 'process' ), 10, 2 );
	}

	/**
	 * Queue orphaned paths for async deletion.
	 *
	 * Uses Action Scheduler when available so the (potentially many, cloud-bound)
	 * deletes run off the request that triggered them. Falls back to deleting
	 * inline when Action Scheduler isn't loaded, so files are never stranded.
	 *
	 * @param int      $media_id Media ID the paths belonged to (context only).
	 * @param string[] $paths    Relative file paths to delete.
	 */
	public function enqueue( int $media_id, array $paths ): void {
		unset( $media_id );

		$paths = array_values( array_filter( array_map( 'strval', (array) $paths ) ) );
		if ( empty( $paths ) ) {
			return;
		}

		if ( ! $this->pending ) {
			// WordPress fires `shutdown` from a registered shutdown function, so the flush runs after a fatal too.
			add_action( 'shutdown', array( $this, 'flush' ) );
		}
		array_push( $this->pending, ...$paths );
	}

	/**
	 * Queue this request's orphaned paths, packed into actions of at most ARGS_BUDGET encoded characters.
	 */
	public function flush(): void {
		$paths         = array_values( array_unique( $this->pending ) );
		$this->pending = array();
		$chunk         = array();
		$size          = 0;
		foreach ( $paths as $path ) {
			$len = strlen( (string) wp_json_encode( $path ) ) + 1;
			if ( $chunk && $size + $len > self::ARGS_BUDGET ) {
				$this->queue_chunk( $chunk );
				$chunk = array();
				$size  = 0;
			}
			$chunk[] = $path;
			$size   += $len;
		}
		if ( $chunk ) {
			$this->queue_chunk( $chunk );
		}
	}

	/**
	 * Queue one chunk of paths, or delete it now when the scheduler refuses it, so no file is left orphaned.
	 *
	 * @param string[] $chunk Relative file paths.
	 */
	private function queue_chunk( array $chunk ): void {
		if ( ! \WPMediaVerse\Core\Jobs::async( self::CLEANUP_HOOK, array( $chunk, 1 ) ) ) {
			$this->process( $chunk );
		}
	}

	/**
	 * Delete each queued path from every configured driver (local + cloud).
	 *
	 * Failed paths (driver returned false — network error, bad credentials,
	 * non-2xx from the cloud API) are re-queued with a growing backoff instead
	 * of being silently dropped. Before this, a single failed R2/S3/BunnyCDN
	 * call orphaned the file permanently with no log and no retry. After MAX_ATTEMPTS the batch is abandoned with an error
	 * log so the orphan is at least visible in MediaVerse → Logs.
	 *
	 * @param string[] $paths   Relative file paths.
	 * @param int      $attempt Delete attempt number for this batch (1-based).
	 */
	public function process( array $paths, int $attempt = 1 ): void {
		$failed = array();

		foreach ( (array) $paths as $path ) {
			if ( ! $this->storage->delete_everywhere( (string) $path ) ) {
				$failed[] = (string) $path;
			}
		}

		if ( empty( $failed ) ) {
			return;
		}

		/**
		 * Delete attempts for a batch of files whose deletion failed (a cloud outage, a locked file). Each retry
		 * waits RETRY_STEP_SECONDS times the attempt number longer. After the last one the paths are logged as an
		 * error in MediaVerse > Logs for manual deletion.
		 *
		 * @since 2.7.0
		 *
		 * @param int $max At least 1. Default 3.
		 */
		$max = max( 1, (int) apply_filters( 'mvs_storage_cleanup_max_attempts', self::MAX_ATTEMPTS ) );

		if ( $attempt < $max && \WPMediaVerse\Core\Jobs::at(
			time() + ( self::RETRY_STEP_SECONDS * $attempt ),
			self::CLEANUP_HOOK,
			array( $failed, $attempt + 1 )
		) ) {
			LoggerService::warning(
				'storage_cleanup',
				sprintf( 'Failed to delete %d orphaned file(s) (attempt %d/%d) - retry scheduled.', count( $failed ), $attempt, $max ),
				array( 'paths' => $failed )
			);

			return;
		}

		LoggerService::error(
			'storage_cleanup',
			sprintf( 'Giving up on %d orphaned file(s) after %d attempt(s) - delete these paths by hand.', count( $failed ), $attempt ),
			array( 'paths' => $failed )
		);
	}
}
