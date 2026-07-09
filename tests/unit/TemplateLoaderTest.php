<?php
/**
 * Tests for TemplateLoader::is_app_page() / app_page_ids().
 *
 * Activator::create_pages() inserts /my-media/, /explore-media/, /upload-media/
 * as ordinary WP pages, so the active theme's default page template renders a
 * member's photo library beside the theme's blog sidebar. TemplateLoader must be
 * able to identify those plugin-owned pages so template_include can route them to
 * the plugin's own app-page.php instead.
 *
 * These tests set the mvs_page_* options directly rather than running full plugin
 * activation, so they stay fast and side-effect-free — is_app_page() only reads
 * the options, which is exactly what we are asserting.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\TemplateLoader;

class TemplateLoaderTest extends WP_UnitTestCase {

	/**
	 * A page recorded in mvs_page_dashboard is an app page.
	 */
	public function test_app_page_ids_include_created_pages(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'mvs_page_dashboard', $page_id );

		$this->assertContains( $page_id, TemplateLoader::app_page_ids() );
		$this->assertTrue( TemplateLoader::is_app_page( $page_id ) );
	}

	/**
	 * A page the plugin never recorded is not an app page.
	 */
	public function test_non_app_page_is_not_an_app_page(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertFalse( TemplateLoader::is_app_page( $page_id ) );
	}

	/**
	 * The mvs_app_page_ids filter can add a page to the set.
	 */
	public function test_filter_can_extend_app_page_ids(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		add_filter(
			'mvs_app_page_ids',
			static function ( array $ids ) use ( $page_id ): array {
				$ids[] = $page_id;
				return $ids;
			}
		);

		$this->assertTrue( TemplateLoader::is_app_page( $page_id ) );
	}
}
