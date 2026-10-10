<?php
/**
 * BuddyPress activity rendering with direct-delivery URLs.
 *
 * Basecamp 10387034419: since 2.6.1 a public upload stores its image under
 * uploads/wpmediaverse/, and the legacy "drop every <img> into that folder"
 * guard (Basecamp 10290384337) deleted it from the stream.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Integrations\BuddyPress\ActivityContentIntegration;

class ActivityContentDirectUrlTest extends WP_UnitTestCase {

	private string $base_url;

	public function set_up(): void {
		parent::set_up();
		$this->base_url = trailingslashit( wp_get_upload_dir()['baseurl'] ) . 'wpmediaverse/2026/10/';
		add_filter( 'mvs_direct_media_delivery', '__return_true' );
	}

	public function tear_down(): void {
		remove_filter( 'mvs_direct_media_delivery', '__return_true' );
		parent::tear_down();
	}

	private function render( string $content ): string {
		return ( new ActivityContentIntegration() )->enhance_activity_media_content( $content, null );
	}

	/**
	 * The markup a 2.6.1 upload leaves after BuddyPress kses: bare <source>, direct <img>.
	 */
	private function stored_block( int $media_id ): string {
		return '<div class="mvs-activity-media mvs-activity-media--image" data-mvs-media-id="' . $media_id . '">'
			. '<a href="http://example.org/media/qa/"><source type="image/webp">'
			. '<img class="mvs-media-thumb" src="' . $this->base_url . 'cff5a6409d80ca42-1024x640.jpg" alt="QA" loading="lazy" /></a></div>';
	}

	public function test_id_keyed_direct_url_image_survives_and_bare_source_is_dropped(): void {
		$author   = self::factory()->user->create();
		$media_id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Direct URL fixture',
				'post_author' => $author,
				'media_type'  => 'image',
				'privacy'     => 'public',
				'file_url'    => $this->base_url . 'cff5a6409d80ca42.jpg',
				'file_path'   => '2026/10/cff5a6409d80ca42.jpg',
			)
		);
		$this->assertGreaterThan( 0, $media_id );

		$out = $this->render( $this->stored_block( $media_id ) );

		$this->assertStringContainsString( 'data-mvs-media-id="' . $media_id . '"', $out );
		$this->assertMatchesRegularExpression( '~<img\b[^>]*\bsrc="[^"]+"~', $out, 'The photo must stay in the activity item.' );
		$this->assertStringNotContainsString( '<source type="image/webp"', $out );
	}

	public function test_legacy_image_without_media_id_is_still_dropped(): void {
		$out = $this->render( '<p>Old post</p><img src="' . $this->base_url . 'readable-name.jpg" alt="" />' );

		$this->assertStringContainsString( 'Old post', $out );
		$this->assertStringNotContainsString( '<img', $out );
	}

	public function test_block_for_missing_media_is_removed(): void {
		$out = $this->render( $this->stored_block( 987654321 ) );

		$this->assertStringNotContainsString( 'mvs-activity-media', $out );
	}
}
