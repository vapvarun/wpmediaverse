<?php
/**
 * Unsending a message that shared a library item takes the access back.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Unsending a message that shared a library item takes the access back.
 */
class UnsentShareRevokesTest extends WP_UnitTestCase {

	public function test_unsent_share_no_longer_grants_view(): void {
		$a = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$b = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$media = Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Shared then unsent',
				'post_author' => $a,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'private',
			)
		);

		$service = Plugin::container()->get( 'messaging' );
		$conv    = (int) $service->find_or_create_conversation( $a, $b )['conversation_id'];
		$sent    = $service->send_message( $conv, $a, array( 'media_id' => $media ) );
		$this->assertTrue( (bool) $sent['success'], 'Sharing own media in a DM failed: ' . wp_json_encode( $sent ) );

		$privacy = Plugin::container()->get( 'privacy' );
		$privacy->flush_cache();
		$this->assertTrue( $privacy->can_view( $media, $b ), 'The recipient should see a shared item.' );

		$service->unsend_message( (int) $sent['message_id'], $a );
		$privacy->flush_cache();
		$this->assertFalse( $privacy->can_view( $media, $b ), 'Unsending must take the access back.' );
	}
}
