<?php
/**
 * Driver-routing decisions for the upload pipeline.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for the "which driver should write this media's
 * bytes?" decision. Historically the same question was asked three different
 * ways:
 *
 *   - `StorageService::get_driver_for_privacy()` (the canonical helper)
 *   - inline `'local' !== $driver_slug && $is_public` (`UploadService:864`)
 *   - `StorageService::get_driver_for_media()` for read-side
 *     (`ImageOptimizationService:779, :823`)
 *
 * This class delegates to the canonical helper and exposes a small surface
 * for callers in the upload pipeline. Phase 6 will fold the inline checks
 * into this routing.
 *
 * @since 1.5.0
 */
class StorageRouter {

	/**
	 * @var StorageService
	 */
	private $storage;

	public function __construct( StorageService $storage ) {
		$this->storage = $storage;
	}

	/**
	 * The driver that owns this media's bytes (driven by stored privacy).
	 *
	 * @param int $media_id Media ID.
	 * @return StorageDriverInterface
	 */
	public function driver_for_media( int $media_id ): StorageDriverInterface {
		return $this->storage->get_driver_for_media( $media_id );
	}

	/**
	 * True when the media is on a non-local driver (its bytes live on cloud).
	 * Public media on a configured cloud driver is cloud-eligible; all
	 * non-public media is local-only by policy
	 * (`StorageService::get_driver_for_privacy`).
	 *
	 * @param int $media_id Media ID.
	 * @return bool
	 */
	public function is_cloud_eligible( int $media_id ): bool {
		return ! $this->driver_for_media( $media_id ) instanceof LocalDriver;
	}

	/**
	 * Store a variant via the media's active driver and return the public
	 * URL to persist. Local drivers do not need an upload (the file is
	 * already on disk at `$spec->local_path()`); cloud drivers receive the
	 * file at `$spec->rel_path()` (driver prepends `wpmediaverse/`
	 * internally).
	 *
	 * Returns `null` if the cloud store call failed — callers should fall
	 * back to the local URL and log the failure (mirrors the existing
	 * cloud-upload-fail behavior in UploadService).
	 *
	 * @param VariantSpec $spec Variant to store.
	 * @return string|null Public URL on success, null on cloud-store failure.
	 */
	public function store_variant( VariantSpec $spec ): ?string {
		$driver = $this->driver_for_media( $spec->media_id() );

		if ( $driver instanceof LocalDriver ) {
			return $driver->url( $spec->rel_path() );
		}

		if ( ! $driver->store( $spec->local_path(), $spec->rel_path() ) ) {
			return null;
		}
		return (string) $driver->url( $spec->rel_path() );
	}
}
