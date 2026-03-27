<?php
/**
 * Basic smoke test for WPMediaVerse.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class SampleTest extends WP_UnitTestCase {

	public function test_plugin_constants_defined(): void {
		$this->assertTrue( defined( 'MVS_VERSION' ), 'MVS_VERSION should be defined.' );
		$this->assertTrue( defined( 'MVS_PLUGIN_DIR' ), 'MVS_PLUGIN_DIR should be defined.' );
	}

	public function test_post_type_registered(): void {
		$this->assertTrue( post_type_exists( 'mvs_media' ), 'mvs_media post type should be registered.' );
	}
}
