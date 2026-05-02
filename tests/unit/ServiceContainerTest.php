<?php
/**
 * Tests for the lazy-load service container.
 *
 * Phase 4 — 100% public-method coverage on ServiceContainer.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\ServiceContainer;

class ServiceContainerTest extends WP_UnitTestCase {

	/**
	 * register() stores a factory; has() reflects registration; get() returns
	 * the factory's product.
	 */
	public function test_register_then_get_returns_factory_product(): void {
		$container = new ServiceContainer();

		$expected = new \stdClass();
		$container->register(
			'sample',
			static function () use ( $expected ) {
				return $expected;
			}
		);

		$this->assertTrue( $container->has( 'sample' ) );
		$this->assertSame( $expected, $container->get( 'sample' ) );
	}

	/**
	 * Resolution is lazy + cached: the factory runs exactly once per key,
	 * subsequent get() calls return the cached instance.
	 */
	public function test_get_caches_factory_result_lazily(): void {
		$container = new ServiceContainer();

		$call_count = 0;
		$container->register(
			'lazy',
			static function () use ( &$call_count ) {
				++$call_count;
				return new \stdClass();
			}
		);

		$this->assertSame( 0, $call_count, 'register() must not invoke the factory' );

		$first  = $container->get( 'lazy' );
		$second = $container->get( 'lazy' );

		$this->assertSame( 1, $call_count, 'factory should run exactly once' );
		$this->assertSame( $first, $second, 'subsequent calls must return the cached instance' );
	}

	/**
	 * Factory receives the container as its argument so it can resolve
	 * dependencies during construction (typical DI pattern).
	 */
	public function test_factory_receives_container_for_dependency_resolution(): void {
		$container = new ServiceContainer();

		$container->register(
			'dependency',
			static function () {
				$o      = new \stdClass();
				$o->tag = 'inner';
				return $o;
			}
		);

		$container->register(
			'consumer',
			static function ( ServiceContainer $c ) {
				$o            = new \stdClass();
				$o->dependency = $c->get( 'dependency' );
				return $o;
			}
		);

		$consumer = $container->get( 'consumer' );
		$this->assertSame( 'inner', $consumer->dependency->tag );
	}

	/**
	 * has() is honest about unregistered keys.
	 */
	public function test_has_returns_false_for_unregistered_key(): void {
		$container = new ServiceContainer();
		$this->assertFalse( $container->has( 'nope' ) );
	}

	/**
	 * Re-registering replaces the factory — useful for test fixtures
	 * overriding production wiring.
	 */
	public function test_re_register_replaces_factory(): void {
		$container = new ServiceContainer();
		$container->register(
			'service',
			static function () {
				$o      = new \stdClass();
				$o->tag = 'first';
				return $o;
			}
		);
		$container->register(
			'service',
			static function () {
				$o      = new \stdClass();
				$o->tag = 'second';
				return $o;
			}
		);

		$this->assertSame( 'second', $container->get( 'service' )->tag );
	}

	/**
	 * get() throws InvalidArgumentException for unregistered keys — Pro and
	 * other consumers depend on this contract to detect missing wiring.
	 */
	public function test_get_throws_when_unregistered(): void {
		$container = new ServiceContainer();

		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Service "missing" is not registered' );
		$container->get( 'missing' );
	}
}
