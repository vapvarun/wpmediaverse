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
 *
 * DEAD SINCE THE PLUGIN WAS SCAFFOLDED. This is the WordPress Plugin
 * Boilerplate's collector class: you push hooks into it and call `run()` to
 * register them all at once. Nothing in WPMediaVerse has ever instantiated one
 * — every service calls `add_action()` / `add_filter()` directly — so the class
 * has no callers, no state anyone reads, and no effect on behaviour.
 *
 * It is DEPRECATED here rather than deleted. Production Rule 1 gives a public
 * symbol two major versions between deprecation and removal, and makes no
 * exception for one that happens to be unused: the point of the rule is that
 * nobody has to judge, per symbol, whether some customer's mu-plugin took a
 * dependency on it. Deleting it today would save nothing and break the rule.
 *
 * Anyone still holding one gets identical behaviour from `add_action()` and
 * `add_filter()`, which is what `run()` calls anyway.
 *
 * @deprecated 2.4.0 Call add_action() / add_filter() directly. Remove in 4.0.0.
 */
class Loader {

	/**
	 * Warn once, the moment someone actually constructs one.
	 *
	 * On the constructor rather than on each method, so a caller is told before
	 * they queue anything — and told exactly once per instance rather than once
	 * per hook.
	 */
	public function __construct() {
		_deprecated_class( __CLASS__, '2.4.0', 'add_action() / add_filter()' );
	}

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
