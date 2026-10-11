<?php
/**
 * A notification does not show the title of an item its reader can no longer see.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Basecamp 10393276031 (notification title after privacy change).
 */
class NotificationTitlePrivacyTest extends WP_UnitTestCase {

	public function test_title_is_hidden_once_the_item_is_private_to_the_reader(): void {
		$owner  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$reader = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo   = Plugin::container()->get( 'media_repository' );
		$media  = $repo->insert( array( 'title' => 'Secret Beach Trip', 'post_author' => $owner, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );

		$notes = Plugin::container()->get( 'notifications' );
		$this->assertStringContainsString( 'Secret Beach Trip', $notes->build_message_and_link( 'media_reaction', $owner, $media, 0, $reader )['message'] );

		$repo->set( $media, 'privacy', 'private' );
		Plugin::container()->get( 'privacy' )->flush_cache();
		$this->assertStringNotContainsString( 'Secret Beach Trip', $notes->build_message_and_link( 'media_reaction', $owner, $media, 0, $reader )['message'] );
	}
}
