<?php
/**
 * A control's stylesheet loads wherever the control renders (QA MV35).
 *
 * The Explore Feed block and [mvs_explore_feed] render a Load More button
 * whose look and focus ring live in load-more.css, which the page loader
 * enqueues only on MediaVerse pages. The chat's filled controls carry text,
 * so they use the contrast-safe button pair, not a fixed light blue.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class ControlStylesheetLoadTest extends WP_UnitTestCase {

	public function test_explore_feed_shortcode_on_an_ordinary_page_loads_the_load_more_stylesheet(): void {
		do_action( 'wp_enqueue_scripts' );
		wp_dequeue_style( 'mvs-load-more' );
		$this->assertTrue( wp_style_is( 'mvs-load-more', 'registered' ) );
		$this->assertFalse( wp_style_is( 'mvs-load-more', 'enqueued' ) );

		do_shortcode( '[mvs_explore_feed]' );

		$this->assertTrue( wp_style_is( 'mvs-load-more', 'enqueued' ) );
	}

	public function test_a_shortcode_rendered_block_loads_the_rest_client_its_view_calls(): void {
		wp_set_current_user( 0 );
		do_action( 'wp_enqueue_scripts' );
		wp_dequeue_script( 'mvs-rest' );
		$this->assertTrue( wp_script_is( 'mvs-rest', 'registered' ) );

		do_shortcode( '[mvs_explore_feed]' );

		$this->assertTrue( wp_script_is( 'mvs-rest', 'enqueued' ) );
	}

	public function test_chat_text_fills_use_the_contrast_safe_pair(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/messaging.css' );

		$this->assertStringContainsString( '--mvs-chat-bubble-sent: var(--mvs-chat-fill);', $css );
		$this->assertStringContainsString( '--mvs-chat-fill: var(--mvs-btn-primary-bg, #0b6fd0);', $css );
		$this->assertStringNotContainsString( '--mvs-chat-bubble-sent: #0095f6', $css, 'white on #0095f6 is 3.2:1' );
		$this->assertMatchesRegularExpression( '/\.mvs-chat-msg--sent \.mvs-chat-msg__reply \{[^}]*rgba\(0, 0, 0, 0\.18\)/', $css );
		$this->assertDoesNotMatchRegularExpression( '/background: var\(--mvs-chat-primary\);\s*(border-color: [^;]+;\s*)?color: #fff;/', $css, 'white text on the raw theme primary' );
	}
}
