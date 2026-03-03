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
}
