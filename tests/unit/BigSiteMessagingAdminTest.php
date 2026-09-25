<?php
/**
 * Big-site readiness — messaging inbox/thread batching, user-deletion
 * cascade batching via Action Scheduler, and BP activity cleanup reading the
 * `mvs_bp_activity_media` linkage table instead of a `bp_activity_meta`
 * LIKE scan.
 *
 * Each test proves the bound that fails against the pre-fix code: the
 * unbatched inbox/thread ran roughly 3 extra queries PER conversation /
 * message, so a fixed query-count ceiling here would have failed at 20
 * conversations or 15 reacted messages before enrich_conversations() /
 * get_messages() were batched.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\UserDeletionService;

class BigSiteMessagingAdminTest extends WP_UnitTestCase {

	/**
	 * Inbox query count must not grow with conversation count.
	 *
	 * Pre-fix: 1 main query + 20 * (1 participants + 2 unread) = 61 queries
	 * for a 20-conversation page. Batched: 1 main + 1 participants IN() +
	 * cache_users() (<=2) + 1 unread UNION = ~5.
	 */
	public function test_inbox_query_count_does_not_grow_with_conversation_count(): void {
		// The 20-conversation fixture below is well past MessagingService's own
		// per-hour conversation-creation rate limit (10) — raise it for this
		// test only; it exists to stop spam, not to cap this assertion.
		add_filter( 'mvs_dm_convo_rate_limit', static fn() => 100 );

		$service = Plugin::container()->get( 'messaging' );
		$author  = self::factory()->user->create();

		for ( $i = 0; $i < 20; $i++ ) {
			$other        = self::factory()->user->create();
			$conversation = $service->find_or_create_conversation( $author, $other );
			$service->send_message( (int) $conversation['conversation_id'], $other, array( 'content' => 'hi ' . $i ) );
		}

		remove_all_filters( 'mvs_dm_convo_rate_limit' );

		global $wpdb;
		$before  = $wpdb->num_queries;
		$result  = $service->get_conversations( $author, 'all', 20, 1 );
		$queries = $wpdb->num_queries - $before;

		$this->assertCount( 20, $result );
		$this->assertLessThanOrEqual(
			10,
			$queries,
			"Inbox of 20 conversations ran {$queries} queries; enrich_conversations() must batch participants + unread counts, not run them per conversation."
		);
	}

	/**
	 * Thread reactions must be fetched in one batched query for the whole
	 * page, not one query per message.
	 */
	public function test_thread_reactions_query_count_does_not_grow_with_message_count(): void {
		$service = Plugin::container()->get( 'messaging' );
		$author  = self::factory()->user->create();
		$other   = self::factory()->user->create();

		$conversation    = $service->find_or_create_conversation( $author, $other );
		$conversation_id = (int) $conversation['conversation_id'];

		for ( $i = 0; $i < 15; $i++ ) {
			$message = $service->send_message( $conversation_id, $author, array( 'content' => 'msg ' . $i ) );
			$service->add_reaction( (int) $message['message_id'], $other, 'love' );
		}

		global $wpdb;
		$before  = $wpdb->num_queries;
		$result  = $service->get_messages( $conversation_id, $author, 0, 30 );
		$queries = $wpdb->num_queries - $before;

		$this->assertCount( 15, $result );
		foreach ( $result as $message ) {
			$this->assertNotEmpty( $message->reactions, 'Every fixture message carries a reaction.' );
		}
		$this->assertLessThanOrEqual(
			10,
			$queries,
			"Thread of 15 reacted messages ran {$queries} queries; get_messages() must batch reactions, not run one query per message."
		);
	}

	/**
	 * Deleting a user with media schedules the cascade batch instead of
	 * running `delete_cascade()` inline on the `deleted_user` request.
	 *
	 * Proven via an `mvs_media_deleted` fire-count rather than re-checking
	 * `exists()` afterwards: this plugin's QA fixture data reuses low user
	 * ids, so a freshly factory-created user can legitimately inherit
	 * pre-existing seeded media via `author_media_ids()` — a real, already
	 * scheduled/dispatched Action Scheduler runner in this environment can
	 * process that queue between this method returning and a later
	 * assertion. Counting `do_action( 'mvs_media_deleted' )` calls made
	 * DURING this synchronous call is immune to that: `delete_cascade()` can
	 * only fire it from code running on this call stack.
	 */
	public function test_user_deletion_schedules_cascade_job(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}

		$author = self::factory()->user->create();

		// Guarantee at least one media row to cascade, regardless of whether
		// this environment's fixture data already gives the fresh author any.
		Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Cascade fixture',
				'post_author' => $author,
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/wp-content/uploads/wpmediaverse/2026/09/cascade-fixture.jpg',
			)
		);

		$synchronous_deletes = 0;
		add_action(
			'mvs_media_deleted',
			static function () use ( &$synchronous_deletes ) {
				++$synchronous_deletes;
			}
		);

		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );

		Plugin::container()->get( 'user_deletion' )->handle_user_deletion( $author );

		remove_all_actions( 'mvs_media_deleted' );

		$this->assertSame(
			0,
			$synchronous_deletes,
			'delete_cascade() must not run inline on the deleted_user request when Action Scheduler is available — it must be batched.'
		);
		$this->assertNotFalse(
			as_next_scheduled_action( UserDeletionService::CASCADE_HOOK, null, 'wpmediaverse' ),
			'Deleting a user must schedule the cascade batch rather than silently doing nothing.'
		);

		// Don't leave a live job pointed at this fixture author for whatever
		// background runner this shared environment has active.
		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );
	}

	/**
	 * A batch over the per-run cap reschedules the remainder instead of
	 * processing everything inline — and running a batch actually deletes.
	 */
	public function test_user_deletion_batch_processes_and_reschedules_remainder(): void {
		if ( ! function_exists( 'as_next_scheduled_action' ) || ! function_exists( 'as_unschedule_all_actions' ) ) {
			$this->markTestSkipped( 'Action Scheduler not loaded.' );
		}

		$service = Plugin::container()->get( 'user_deletion' );
		$repo    = Plugin::container()->get( 'media_repository' );
		$author  = self::factory()->user->create();

		$media_id = (int) $repo->insert(
			array(
				'title'       => 'Batch fixture',
				'post_author' => $author,
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/wp-content/uploads/wpmediaverse/2026/09/batch-fixture.jpg',
			)
		);

		// 150 ids: a real one plus 149 already-gone ones. delete_cascade() is
		// a no-op on a missing row (deletes 0 child rows, still returns true —
		// MediaRepository::delete_cascade()), so the padding proves the batch
		// cap without needing 150 real uploads.
		$media_ids   = array_merge( array( $media_id ), range( 900001, 900001 + 148 ) );
		$this->assertCount( 150, $media_ids );

		as_unschedule_all_actions( UserDeletionService::CASCADE_HOOK );

		$service->process_cascade_batch( $author, $media_ids );

		$this->assertFalse( $repo->exists( $media_id ), 'The real media item in the first batch must be deleted.' );
		$this->assertNotFalse(
			as_next_scheduled_action( UserDeletionService::CASCADE_HOOK, null, 'wpmediaverse' ),
			'150 ids is more than one batch (100) — the remainder must be rescheduled, not dropped.'
		);
	}

	/**
	 * BP activity cleanup finds activities via the indexed `media_id` lookup
	 * on `mvs_bp_activity_media` instead of a `LIKE '%,123,%'` scan across
	 * `bp_activity_meta`, and the `object_type` scope keeps a media item's
	 * lookup from returning a numerically-colliding row that belongs to a
	 * different object type (e.g. a BuddyNext post) sharing the same table.
	 */
	public function test_bp_activity_cleanup_uses_linkage_table_with_object_type_scope(): void {
		global $wpdb;
		( new \WPMediaVerse\Core\Migrator() )->run();
		$linkage_table = $wpdb->prefix . 'mvs_bp_activity_media';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $linkage_table ) ) ) {
			$this->markTestSkipped( 'mvs_bp_activity_media table missing — Migrator did not create it.' );
		}

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$repo    = Plugin::container()->get( 'media_repository' );
		$linkage = Plugin::container()->get( 'integration.bp_activity_linkage' );

		$media_id = (int) $repo->insert(
			array(
				'title'       => 'Linkage lookup fixture',
				'post_author' => $admin_id,
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/wp-content/uploads/wpmediaverse/2026/09/linkage-lookup.jpg',
			)
		);

		$activity_id  = 555001;
		$activity_obj = new \stdClass();
		$activity_obj->id = $activity_id;

		add_filter( 'mvs_activity_media_ids', fn() => array( $media_id ) );
		$linkage->on_activity_save( $activity_obj );
		remove_all_filters( 'mvs_activity_media_ids' );

		$this->assertSame(
			array( $activity_id ),
			$linkage->activity_ids_for_media( $media_id ),
			'clean_activities_for_media() reads this lookup instead of scanning bp_activity_meta with LIKE.'
		);

		// No linkage rows for an unrelated media item — nothing to scan.
		$this->assertSame( array(), $linkage->activity_ids_for_media( 999999999 ) );

		// A row belonging to a different object_type (the table is shared
		// with Media\ObjectMediaLinkage, e.g. BuddyNext posts) must never be
		// returned as a BP activity id, even on a numeric id collision.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$linkage_table,
			array(
				'object_type' => 'bn_post',
				'activity_id' => $activity_id + 1,
				'media_id'    => $media_id,
				'variant'     => 'image',
				'position'    => 0,
				'created_at'  => current_time( 'mysql', true ),
			),
			array( '%s', '%d', '%d', '%s', '%d', '%s' )
		);

		$this->assertSame(
			array( $activity_id ),
			$linkage->activity_ids_for_media( $media_id ),
			'A bn_post-scoped row sharing the linkage table must not be returned as a BP activity id.'
		);
	}
}
