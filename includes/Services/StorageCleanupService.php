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
 * so this single listener covers every way media leaves the system. Previously
 * the REST path deleted only the original (from the active driver, missing
 * variants and cloud-resident files) and account deletion deleted no files at
 * all, leaving orphaned bytes on disk and the CDN.
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
	 * Action Scheduler group for queued cleanup actions.
	 */
	const AS_GROUP = 'wpmediaverse';

	/**
	 * Storage service (driver factory).
	 *
	 * @var StorageService
	 */
	private $storage;

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
		add_action( self::CLEANUP_HOOK, array( $this, 'process' ), 10, 1 );
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

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::CLEANUP_HOOK, array( $paths ), self::AS_GROUP );
			return;
		}

		// No Action Scheduler — delete inline so nothing is left orphaned.
		$this->process( $paths );
	}

	/**
	 * Delete each queued path from every configured driver (local + cloud).
	 *
	 * @param string[] $paths Relative file paths.
	 */
	public function process( array $paths ): void {
		foreach ( (array) $paths as $path ) {
			$this->storage->delete_everywhere( (string) $path );
		}
	}
}
