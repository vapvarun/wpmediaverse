<?php
/**
 * Hook loader.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Hook loader that collects and registers actions/filters.
 */
class Loader {

	/**
	 * Registered actions.
	 *
	 * @var array
	 */
	private $actions = array();

	/**
	 * Registered filters.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Add an action hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	public function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->actions[] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Add a filter hook.
	 *
	 * @param string   $hook          Hook name.
	 * @param callable $callback      Callback.
	 * @param int      $priority      Priority.
	 * @param int      $accepted_args Number of accepted arguments.
	 */
	public function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$this->filters[] = compact( 'hook', 'callback', 'priority', 'accepted_args' );
	}

	/**
	 * Register all collected hooks with WordPress.
	 */
	public function run(): void {
		foreach ( $this->actions as $hook ) {
			add_action( $hook['hook'], $hook['callback'], $hook['priority'], $hook['accepted_args'] );
		}
		foreach ( $this->filters as $hook ) {
			add_filter( $hook['hook'], $hook['callback'], $hook['priority'], $hook['accepted_args'] );
		}
	}
}
