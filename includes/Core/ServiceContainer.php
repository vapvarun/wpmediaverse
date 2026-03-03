<?php
/**
 * Service Container.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Simple lazy-load service container.
 */
class ServiceContainer {

	/**
	 * Factory callables keyed by service name.
	 *
	 * @var array<string, callable>
	 */
	private $factories = array();

	/**
	 * Resolved instances cache.
	 *
	 * @var array<string, object>
	 */
	private $instances = array();

	/**
	 * Register a service factory.
	 *
	 * @param string   $key     Service identifier.
	 * @param callable $factory Factory callable that returns the service instance.
	 */
	public function register( string $key, callable $factory ): void {
		$this->factories[ $key ] = $factory;
	}

	/**
	 * Get a service instance (lazy-loaded, cached).
	 *
	 * @param string $key Service identifier.
	 * @return object
	 * @throws \InvalidArgumentException If service is not registered.
	 */
	public function get( string $key ) {
		if ( isset( $this->instances[ $key ] ) ) {
			return $this->instances[ $key ];
		}

		if ( ! isset( $this->factories[ $key ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Service "%s" is not registered.', esc_html( $key ) )
			);
		}

		$this->instances[ $key ] = call_user_func( $this->factories[ $key ], $this );

		return $this->instances[ $key ];
	}

	/**
	 * Check if a service is registered.
	 *
	 * @param string $key Service identifier.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->factories[ $key ] );
	}
}
