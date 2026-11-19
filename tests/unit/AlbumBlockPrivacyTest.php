<?php
/**
 * The Album block asks about the ALBUM, never about a photo that shares its number.
 *
 * Album ids and media ids are two number sequences, so they collide. The block
 * used the default lookup, where the photo wins: a public album vanished behind a
 * private draft, and a private album showed because a public photo shared its id.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\AlbumService;

class AlbumBlockPrivacyTest extends WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( 0 );
	}

	/**
	 * Make an album with the given privacy and a media row that takes its id.
	 *
	 * @param string $album_privacy Album privacy.
	 * @param string $media_status  Status of the colliding media row.
	 * @param string $media_privacy Privacy of the colliding media row.
	 * @return int Album id.
	 */
	private function album_with_colliding_media( string $album_privacy, string $media_status, string $media_privacy ): int {
		global $wpdb;

		$album_id = (int) self::factory()->post->create(
			array(
				'post_type'   => 'mvs_album',
				'post_status' => 'publish',
				'post_title'  => 'Colliding album',
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $album_id, AlbumService::PRIVACY_META, $album_privacy );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'media_id'    => $album_id,
				'post_author' => $this->owner,
				'media_type'  => 'image',
				'status'      => $media_status,
				'privacy'     => $media_privacy,
			)
		);
		Plugin::container()->get( 'privacy' )->flush_cache();

		return $album_id;
	}

	private function render( int $album_id ): string {
		return trim( do_blocks( '<!-- wp:mvs/album-viewer {"albumId":' . $album_id . '} /-->' ) );
	}

	/**
	 * A private album stays hidden from a visitor even when a PUBLIC photo has its number.
	 */
	public function test_private_album_is_hidden_when_a_public_photo_shares_its_id(): void {
		$album_id = $this->album_with_colliding_media( 'private', 'publish', 'public' );

		$this->assertSame( '', $this->render( $album_id ) );
	}

	/**
	 * A public album still shows when a PRIVATE draft photo has its number.
	 */
	public function test_public_album_shows_when_a_private_draft_photo_shares_its_id(): void {
		$album_id = $this->album_with_colliding_media( 'public', 'draft', 'private' );

		$this->assertNotSame( '', $this->render( $album_id ), 'The album decides, not the photo that shares its number.' );
	}
}
