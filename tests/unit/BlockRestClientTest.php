<?php
/**
 * Any mvs/* block loads the shared REST client its view script calls.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10350019690. Block view scripts are modules that call
 * window.mvsRest, but mvs-rest was enqueued only on MediaVerse's own pages, so a
 * block on an ordinary page silently lost view and download tracking.
 */
class BlockRestClientTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! wp_script_is( 'mvs-rest', 'registered' ) ) {
			wp_register_script( 'mvs-rest', '', array(), '1' );
		}
		wp_dequeue_script( 'mvs-rest' );
	}

	public function test_an_mvs_block_enqueues_the_rest_client_and_passes_content_through(): void {
		$out = Plugin::enqueue_rest_client_for_block( '<div>x</div>', array( 'blockName' => 'mvs/media-player' ) );

		$this->assertSame( '<div>x</div>', $out );
		$this->assertTrue( wp_script_is( 'mvs-rest', 'enqueued' ) );
	}

	public function test_other_blocks_do_not(): void {
		Plugin::enqueue_rest_client_for_block( '<p>x</p>', array( 'blockName' => 'core/paragraph' ) );
		Plugin::enqueue_rest_client_for_block( '<p>x</p>', array( 'blockName' => null ) );

		$this->assertFalse( wp_script_is( 'mvs-rest', 'enqueued' ) );
	}
}
