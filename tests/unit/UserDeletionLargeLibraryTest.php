<?php
/**
 * Deleting a member with a large library queues a small job and removes everything.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\UserDeletionService;

/**
 * Deleting a member with a large library queues a small job and removes everything.
 */
class UserDeletionLargeLibraryTest extends WP_UnitTestCase {

	public function test_member_with_1500_media_is_deleted_in_batches(): void {
		global $wpdb;

		$user  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$index = $wpdb->prefix . 'mvs_media_index';
		$now   = current_time( 'mysql', true );
		for ( $i = 0; $i < 1500; $i++ ) {
			$wpdb->insert( $index, array( 'title' => 'Big ' . $i, 'slug' => 'big-' . $user . '-' . $i, 'post_author' => $user, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public', 'created_at' => $now, 'updated_at' => $now ) );
		}

		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );
		$service = Plugin::container()->get( 'user_deletion' );
		$service->handle_user_deletion( $user ); // Threw on the 8,000-character argument limit before.

		$job = as_get_scheduled_actions( array( 'hook' => UserDeletionService::CASCADE_HOOK, 'status' => 'pending' ), 'ids' );
		$this->assertCount( 1, $job );
		$this->assertSame( array( $user ), \ActionScheduler::store()->fetch_action( reset( $job ) )->get_args() );

		// Run the job chain the way Action Scheduler would.
		for ( $run = 0; $run < 20; $run++ ) {
			as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );
			$service->process_cascade_batch( $user );
			if ( ! as_next_scheduled_action( UserDeletionService::CASCADE_HOOK ) ) {
				break;
			}
		}
		\WPMediaVerse\Repository\MediaRepository::reset_test_cache();
		$this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE post_author = %d", $user ) ) );
	}
}
