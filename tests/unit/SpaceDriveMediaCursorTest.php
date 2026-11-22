<?php
/**
 * A space-delete walk passes items it cannot trash and still reaches the rest (2.6.5).
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class SpaceDriveMediaCursorTest extends WP_UnitTestCase {

	private const SPACE = 987654;

	public function test_the_walk_moves_past_250_untrashable_items_at_the_head(): void {
		$repo   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$author = self::factory()->user->create();
		$ids    = array();
		for ( $i = 0; $i < 450; $i++ ) {
			$ids[] = (int) $repo->insert(
				array(
					'title'             => 'Photo ' . $i,
					'post_author'       => $author,
					'media_type'        => 'image',
					'file_type'         => 'image/jpeg',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => 'public',
					'drive_type'        => 'space',
					'drive_id'          => self::SPACE,
				)
			);
		}
		$blocked = array_flip( array_slice( $ids, 0, 250 ) );

		// The caller's chain: 200 per run, each run starts after the last id the previous one saw.
		$after = 0;
		$runs  = 0;
		do {
			$page = $repo->space_drive_media_ids( self::SPACE, 200, $after );
			foreach ( $page as $id ) {
				if ( ! isset( $blocked[ $id ] ) ) {
					$repo->trash( $id );
				}
			}
			$after = $page ? max( $page ) : $after;
			$full  = count( $page ) >= 200;
		} while ( $full && ++$runs < 10 );

		$this->assertSame( array_keys( $blocked ), $repo->space_drive_media_ids( self::SPACE, 500 ), 'Only the untrashable head is left.' );
		$this->assertSame( array(), $repo->space_drive_media_ids( self::SPACE, 500, max( $ids ) ) );
	}
}
