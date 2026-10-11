<?php
/**
 * Deleting or erasing a member removes their media through the cascade, files included.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Privacy\MemberPurger;
use WPMediaVerse\Repository\MediaRepository;
use WPMediaVerse\Services\StorageCleanupService;
use WPMediaVerse\Services\UserDeletionService;

/**
 * Deleting or erasing a member removes their media through the cascade, files included.
 */
class MemberDeletionRemovesFilesTest extends WP_UnitTestCase {

	/**
	 * Three media rows with a stored file and meta, owned by a new member.
	 *
	 * @return array{0:int, 1:int[]} User id and media ids.
	 */
	private function member_with_media(): array {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo = Plugin::container()->get( 'media_repository' );
		$ids  = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$id = $repo->insert(
				array(
					'title'       => 'Owned ' . $i,
					'post_author' => $user,
					'status'      => 'publish',
					'media_type'  => 'image',
					'privacy'     => 'public',
					'file_path'   => '2026/10/owned-' . $user . '-' . $i . '.jpg',
				)
			);
			$repo->set( $id, 'original_filename', 'owned-' . $i . '.jpg' );
			$ids[] = $id;
		}
		return array( $user, $ids );
	}

	private function meta_rows( array $ids ): int {
		global $wpdb;
		$in = implode( ',', array_map( 'intval', $ids ) );
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_meta WHERE media_id IN ( {$in} )" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	}

	private function cleanup_queued(): bool {
		return (bool) as_get_scheduled_actions( array( 'hook' => StorageCleanupService::CLEANUP_HOOK, 'status' => 'pending' ), 'ids' );
	}

	public function test_account_deletion_leaves_media_to_the_cascade_and_queues_file_cleanup(): void {
		list( $user, $ids ) = $this->member_with_media();
		$this->assertGreaterThan( 0, $this->meta_rows( $ids ) );

		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );
		as_unschedule_all_actions( StorageCleanupService::CLEANUP_HOOK );
		$service = Plugin::container()->get( 'user_deletion' );
		$service->handle_user_deletion( $user );

		// The purge that runs in the same request must not take the rows first.
		MediaRepository::reset_test_cache();
		$this->assertCount( 3, Plugin::container()->get( 'media_repository' )->author_media_ids( $user ) );

		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );
		$service->process_cascade_batch( $user );

		MediaRepository::reset_test_cache();
		$this->assertSame( array(), Plugin::container()->get( 'media_repository' )->author_media_ids( $user ) );
		$this->assertSame( 0, $this->meta_rows( $ids ), 'Media meta must go with the media.' );
		$this->assertTrue( $this->cleanup_queued(), 'The files must be handed to storage cleanup.' );
	}

	public function test_privacy_erasure_deletes_media_through_the_cascade(): void {
		list( $user, $ids ) = $this->member_with_media();
		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );
		as_unschedule_all_actions( StorageCleanupService::CLEANUP_HOOK );

		$purger = new MemberPurger();
		$purger->purge( $user );

		MediaRepository::reset_test_cache();
		$this->assertSame( array(), Plugin::container()->get( 'media_repository' )->author_media_ids( $user ) );
		$this->assertSame( 0, $this->meta_rows( $ids ), 'Media meta must go with the media.' );
		$this->assertTrue( $this->cleanup_queued(), 'The files must be handed to storage cleanup.' );
	}
}
