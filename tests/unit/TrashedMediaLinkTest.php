<?php
/**
 * A signed link to a trashed photo stops working, even one signed for its owner.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.6.3
 */
class TrashedMediaLinkTest extends WP_UnitTestCase {

	/**
	 * A media row.
	 *
	 * @param int    $author Author id.
	 * @param string $type   Media type.
	 */
	private function media( int $author, string $type = 'image' ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Item',
				'post_author' => $author,
				'status'      => 'publish',
				'media_type'  => $type,
				'privacy'     => 'public',
				'file_url'    => 'https://example.test/p.jpg',
				'file_path'   => 'p.jpg',
				'file_type'   => 'image/jpeg',
				'file_size'   => 1,
				'file_hash'   => wp_generate_password( 12, false ),
			)
		);
	}

	public function test_trash_refuses_owner_and_guest_but_not_a_moderator(): void {
		$links     = Plugin::container()->get( 'signed_urls' );
		$repo      = Plugin::container()->get( 'media_repository' );
		$owner     = self::factory()->user->create();
		$moderator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$media     = $this->media( $owner );

		$this->assertFalse( $links->refuses_trashed( $media, $owner ), 'a live item is not refused' );

		$repo->trash( $media );
		$this->assertTrue( $links->refuses_trashed( $media, $owner ) );
		$this->assertTrue( $links->refuses_trashed( $media, 0 ) );
		$this->assertFalse( $links->refuses_trashed( $media, $moderator ) );

		$repo->restore( $media );
		$this->assertFalse( $links->refuses_trashed( $media, $owner ), 'restoring brings the link back' );
	}

	public function test_a_trashed_document_keeps_its_own_rules(): void {
		$links = Plugin::container()->get( 'signed_urls' );
		$owner = self::factory()->user->create();
		$doc   = $this->media( $owner, 'document' );

		Plugin::container()->get( 'media_repository' )->trash( $doc );

		$this->assertFalse( $links->refuses_trashed( $doc, $owner ) );
	}
}
