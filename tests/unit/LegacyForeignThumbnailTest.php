<?php
/**
 * A migrated row's stale thumbnail URL on a foreign host is never emitted.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10350203155. With no thumb_*_path rows, the legacy branch returned
 * any https thumb URL outside local uploads, so a site migrated from another
 * host pointed its grid at the old host while the original was still here.
 */
class LegacyForeignThumbnailTest extends WP_UnitTestCase {

	public function test_a_foreign_legacy_thumb_url_is_not_emitted(): void {
		$repo = Plugin::container()->get( 'media_repository' );
		$id   = (int) $repo->insert(
			array(
				'title'       => 'Migrated',
				'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
				'privacy'     => 'public',
			)
		);
		$repo->set_meta_many( 'thumb_medium', array( $id => 'https://oldsite.wpcomstaging.com/wp-content/uploads/x-medium.jpg' ) );

		$url = (string) Plugin::container()->get( 'signed_urls' )->generate_thumbnail( $id, 0, 'medium', 0, true );

		$this->assertStringNotContainsString( 'oldsite.wpcomstaging.com', $url );
	}
}
