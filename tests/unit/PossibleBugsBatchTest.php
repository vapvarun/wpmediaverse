<?php
/**
 * 2.6.0 QA Possible Bugs.
 *
 * - An unpublished (draft) item was served in full over REST to signed-out
 *   visitors while its page answered 404.
 * - NotificationService::create() returned the id of whatever a listener
 *   inserted last, not the notification's.
 * - My Media hid the owner's item held for review but still counted it.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class PossibleBugsBatchTest extends WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->owner = self::factory()->user->create( array( 'role' => 'author' ) );
		do_action( 'rest_api_init' );
	}

	private function media( array $over = array() ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array_merge(
				array(
					'title'             => 'Batch probe',
					'post_author'       => $this->owner,
					'media_type'        => 'image',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => 'public',
					'file_path'         => '2026/09/probe.jpg',
					'file_type'         => 'image/jpeg',
					'slug'              => 'batch-' . wp_generate_password( 8, false, false ),
				),
				$over
			)
		);
	}

	public function test_a_draft_is_its_owners_alone(): void {
		$draft   = $this->media( array( 'status' => 'draft' ) );
		$privacy = Plugin::container()->get( 'privacy' );

		wp_set_current_user( 0 );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/media/' . $draft ) )->get_status(), 'A draft was served to a visitor.' );
		$this->assertFalse( $privacy->can_view( $draft, self::factory()->user->create() ) );
		$privacy->flush_cache();
		$this->assertTrue( $privacy->can_view( $draft, $this->owner ), 'The owner lost their own draft.' );
	}

	public function test_create_returns_the_notifications_own_id(): void {
		global $wpdb;
		$other = self::factory()->user->create();
		$noise = static function () use ( $wpdb ) {
			// Stand-in for a listener that writes a row of its own.
			$wpdb->insert( $wpdb->prefix . 'mvs_error_log', array( 'level' => 'info', 'message' => 'listener write', 'created_at' => current_time( 'mysql', true ) ) ); // phpcs:ignore
		};
		add_action( 'mvs_notification_created', $noise );

		$id = Plugin::container()->get( 'notifications' )->create( $other, 'new_follower', $this->owner );
		remove_action( 'mvs_notification_created', $noise );

		$row = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}mvs_notifications WHERE id = %d", $id ) ); // phpcs:ignore
		$this->assertSame( $other, $row, 'create() returned an id that is not the notification it created.' );
	}

	public function test_my_media_lists_the_owners_held_items(): void {
		$held = $this->media( array( 'moderation_status' => 'flagged' ) );
		$this->media();

		wp_set_current_user( $this->owner );
		$res = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/me/media' ) );
		$ids = array_map( 'intval', wp_list_pluck( $res->get_data(), 'id' ) );
		$this->assertContains( $held, $ids, 'The owner could not see their own item held for review.' );
		$this->assertSame( count( $ids ), (int) $res->get_headers()['X-WP-Total'], 'The count and the list disagree.' );

		wp_set_current_user( self::factory()->user->create() );
		$req = new WP_REST_Request( 'GET', '/mvs/v1/media' );
		$req->set_param( 'author', $this->owner );
		$this->assertNotContains( $held, array_map( 'intval', wp_list_pluck( rest_do_request( $req )->get_data(), 'id' ) ), 'Someone else saw an item held for review.' );
	}
}
