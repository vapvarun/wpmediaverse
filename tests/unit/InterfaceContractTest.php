<?php
/**
 * Phase 1a contract tests.
 *
 * Asserts the boundary interfaces exist and that any class declared as
 * implementing them satisfies the contract. Container-resolution
 * conformance is added in Phase 1b/1c when the implementations move to
 * instance methods.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class InterfaceContractTest extends WP_UnitTestCase {

	/**
	 * MediaRepositoryInterface ships in includes/Repository/.
	 */
	public function test_media_repository_interface_exists(): void {
		$this->assertTrue(
			interface_exists( '\\WPMediaVerse\\Repository\\MediaRepositoryInterface' ),
			'MediaRepositoryInterface should be autoloadable.'
		);
	}

	/**
	 * TemplateHelpersInterface ships in includes/Core/.
	 */
	public function test_template_helpers_interface_exists(): void {
		$this->assertTrue(
			interface_exists( '\\WPMediaVerse\\Core\\TemplateHelpersInterface' ),
			'TemplateHelpersInterface should be autoloadable.'
		);
	}

	/**
	 * MediaRepositoryInterface declares the public surface Pro depends on.
	 *
	 * If a method drops out of the interface, Pro callers (which type-hint
	 * against the interface after Phase 1d) lose access.
	 */
	public function test_media_repository_interface_declares_critical_methods(): void {
		$reflect = new \ReflectionClass( '\\WPMediaVerse\\Repository\\MediaRepositoryInterface' );
		$methods = array_map(
			static fn( \ReflectionMethod $m ): string => $m->getName(),
			$reflect->getMethods()
		);

		// Sample of the contract — any of these dropping is a Pro break.
		$required = array(
			'get',
			'get_raw',
			'get_all',
			'get_batch',
			'get_filesystem_path',
			'find_by_url',
			'get_broadcast_url',
			'get_broadcast_thumbnail_url',
			'get_thumbnail_url_for_viewer',
			'set',
			'set_many',
			'delete',
			'exists',
			'insert',
			'get_author',
			'get_permalink',
		);

		$missing = array_values( array_diff( $required, $methods ) );
		$this->assertSame(
			array(),
			$missing,
			'MediaRepositoryInterface missing methods: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Phase 1c — `Plugin::container()->get('media_repository')` returns
	 * an instance implementing the interface. This is the contract Pro
	 * depends on after Phase 1d.
	 */
	public function test_media_repository_container_resolution_returns_interface_impl(): void {
		if ( ! class_exists( '\\WPMediaVerse\\Core\\Plugin' ) ) {
			$this->markTestSkipped( 'Plugin bootstrap not loaded.' );
		}
		$container = \WPMediaVerse\Core\Plugin::container();
		$this->assertNotNull( $container );
		$this->assertTrue( $container->has( 'media_repository' ) );

		$instance = $container->get( 'media_repository' );
		$this->assertInstanceOf(
			'\\WPMediaVerse\\Repository\\MediaRepositoryInterface',
			$instance,
			'Container key "media_repository" must resolve to an interface implementation.'
		);
	}

	/**
	 * Phase 1b — `Plugin::container()->get('template_helpers')` returns
	 * an instance implementing the interface. This is the contract Pro
	 * depends on after Phase 1d.
	 */
	public function test_template_helpers_container_resolution_returns_interface_impl(): void {
		if ( ! class_exists( '\\WPMediaVerse\\Core\\Plugin' ) ) {
			$this->markTestSkipped( 'Plugin bootstrap not loaded.' );
		}
		$container = \WPMediaVerse\Core\Plugin::container();
		$this->assertNotNull( $container );
		$this->assertTrue( $container->has( 'template_helpers' ) );

		$instance = $container->get( 'template_helpers' );
		$this->assertInstanceOf(
			'\\WPMediaVerse\\Core\\TemplateHelpersInterface',
			$instance,
			'Container key "template_helpers" must resolve to an interface implementation.'
		);
	}

	/**
	 * TemplateHelpersInterface declares the public surface Pro depends on.
	 */
	public function test_template_helpers_interface_declares_critical_methods(): void {
		$reflect = new \ReflectionClass( '\\WPMediaVerse\\Core\\TemplateHelpersInterface' );
		$methods = array_map(
			static fn( \ReflectionMethod $m ): string => $m->getName(),
			$reflect->getMethods()
		);

		$required = array(
			'get_thumb_url',
			'get_lightbox_url',
			'get_media_type',
			'get_user_profile_url',
			'get_display_name',
			'media_thumbnail',
			'render_grid_thumbnail',
			'render_grid_item',
			'bulk_get_stats',
			'get_parent_route',
			'render_back_link',
		);

		$missing = array_values( array_diff( $required, $methods ) );
		$this->assertSame(
			array(),
			$missing,
			'TemplateHelpersInterface missing methods: ' . implode( ', ', $missing )
		);
	}
}
