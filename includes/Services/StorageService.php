<?php
/**
 * Storage service — driver factory.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Storage service with configurable driver pattern.
 */
class StorageService {

	/**
	 * Cached driver instance.
	 *
	 * @var StorageDriverInterface|null
	 */
	private $driver;

	/**
	 * Get the configured storage driver.
	 *
	 * @return StorageDriverInterface
	 */
	public function get_driver(): StorageDriverInterface {
		if ( null === $this->driver ) {
			$driver_name = get_option( 'mvs_storage_driver', 'local' );

			/**
			 * Filter the storage driver instance.
			 *
			 * @param StorageDriverInterface|null $driver      Current driver.
			 * @param string                      $driver_name Configured driver name.
			 */
			$this->driver = apply_filters( 'mvs_storage_driver', null, $driver_name );

			if ( ! $this->driver instanceof StorageDriverInterface ) {
				$this->driver = new LocalDriver();
			}
		}

		return $this->driver;
	}

	/**
	 * The local-disk driver, regardless of the active driver setting.
	 *
	 * @return StorageDriverInterface
	 */
	public function get_local_driver(): StorageDriverInterface {
		return new LocalDriver();
	}

	/**
	 * The driver that should STORE a media of the given privacy.
	 *
	 * Private/restricted media never leaves the local server — only public
	 * media is eligible for cloud storage. This is the single enforcement point
	 * for that policy: every write path (upload, thumbnails, variant publish)
	 * resolves its driver here instead of calling get_driver() directly, so a
	 * private upload can never land on a public cloud bucket. Mirrors the
	 * public-only filter already used by the migration flows.
	 *
	 * @param string $privacy Media privacy (public|members|friends|private|group|custom).
	 * @return StorageDriverInterface Active driver for public media, else local.
	 */
	public function get_driver_for_privacy( string $privacy ): StorageDriverInterface {
		return 'public' === $privacy ? $this->get_driver() : $this->get_local_driver();
	}

	/**
	 * The driver that should store a given media's bytes, by its stored privacy.
	 *
	 * @param int $media_id Media ID.
	 * @return StorageDriverInterface
	 */
	public function get_driver_for_media( int $media_id ): StorageDriverInterface {
		$privacy = (string) \WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->get_raw( $media_id, 'privacy' );

		return $this->get_driver_for_privacy( $privacy );
	}
}
