<?php
/**
 * Album-wide privacy changes stay bounded on big albums (Basecamp 10344644266).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\AlbumService;

/**
 * @since 2.6.0
 */
class AlbumPrivacyScaleTest extends WP_UnitTestCase {

	private const PHOTOS = 600;

	public function test_album_privacy_change_costs_a_bounded_number_of_queries(): void {
		global $wpdb;

		$owner = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $owner );

		$albums = Plugin::container()->get( 'albums' );
		$repo   = Plugin::container()->get( 'media_repository' );
		$album  = (int) $albums->create( $owner, array( 'title' => 'Big album' ) );
		$albums->set_privacy( $album, 'public' );

		$ids = array();
		for ( $i = 0; $i < self::PHOTOS; $i++ ) {
			$ids[] = (int) $repo->insert(
				array(
					'title'       => 'Scale ' . $i,
					'post_author' => $owner,
					'media_type'  => 'image',
					'status'      => 'publish',
					'privacy'     => 0 === $i % 3 ? 'private' : 'public',
				)
			);
		}
		$before = $wpdb->num_queries;
		$albums->add_items( $album, $ids, array( 'announce' => false ) );
		$used = $wpdb->num_queries - $before;
		$this->assertLessThan( 60, $used, "Adding the photos used {$used} queries." );
		$this->assertCount( self::PHOTOS, $albums->viewable_item_ids( $album ) );

		$announced = 0;
		add_action(
			'mvs_media_privacy_changed',
			static function () use ( &$announced ) {
				++$announced;
			}
		);

		$before = $wpdb->num_queries;
		$albums->set_privacy( $album, 'members' );
		$used = $wpdb->num_queries - $before;

		$this->assertLessThan( 60, $used, "Album privacy change used {$used} queries for " . self::PHOTOS . ' photos.' );
		$this->assertSame( self::PHOTOS, $announced, 'Every photo whose privacy changed must still announce it.' );

		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT privacy FROM {$wpdb->prefix}mvs_media_index WHERE media_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( array( 'members' ), $rows );

		// Emptying the album gives every photo its own choice back.
		$before = $wpdb->num_queries;
		$albums->delete_all_items( $album );
		$used = $wpdb->num_queries - $before;

		$this->assertLessThan( 60, $used, "Emptying the album used {$used} queries." );
		$this->assertSame( 'private', (string) $repo->get( $ids[0], 'privacy' ) );
		$this->assertSame( 'public', (string) $repo->get( $ids[1], 'privacy' ) );
		$this->assertSame( '', (string) $repo->get( $ids[1], AlbumService::OWN_PRIVACY_META ) );
	}
}
