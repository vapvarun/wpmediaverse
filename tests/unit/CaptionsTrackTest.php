<?php
/**
 * Every player renders captions from one helper.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * WCAG 1.2.2 (card 9822979354). Pro made .vtt captions but no player had a
 * <track>; captions_track() renders one from the mvs_media_captions filter.
 */
class CaptionsTrackTest extends WP_UnitTestCase {

	private function track( $captions ): string {
		$cb = static fn() => $captions;
		add_filter( 'mvs_media_captions', $cb );
		$html = Plugin::container()->get( 'template_helpers' )->captions_track( 123 );
		remove_filter( 'mvs_media_captions', $cb );

		return $html;
	}

	public function test_captions_render_a_track_with_their_language(): void {
		$html = $this->track( array( 'url' => 'https://example.org/c/123.vtt', 'lang' => 'en-GB' ) );

		$this->assertStringContainsString( '<track kind="captions" src="https://example.org/c/123.vtt" srclang="en-GB"', $html );
		$this->assertStringContainsString( 'default', $html );
	}

	public function test_a_non_language_value_is_left_out(): void {
		$html = $this->track( array( 'url' => 'https://example.org/c/123.vtt', 'lang' => 'manual' ) );

		$this->assertStringNotContainsString( 'srclang', $html );
	}

	public function test_no_captions_render_nothing(): void {
		$this->assertSame( '', $this->track( null ) );
		$this->assertSame( '', $this->track( array( 'url' => '' ) ) );
	}
}
