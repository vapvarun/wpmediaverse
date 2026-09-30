<?php
/**
 * 2.6.0 "missing surfaces" guards.
 *
 * - Erasing member #N no longer anonymises (or exports) reports whose target
 *   is media, a comment or a message that happens to have id N.
 * - Comments and messages can be reported, only by someone who can see them,
 *   and never by their author.
 * - A mention in a description reaches only people who can open the item,
 *   and re-saving the description does not mention them again.
 * - Restoring a member with a pending account deletion cancels the deletion.
 * - The activity feed is trimmed on the daily retention job.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class MissingSurfacesTest extends WP_UnitTestCase {

	private int $author;
	private int $member;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->author = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->member = self::factory()->user->create(
			array(
				'role'       => 'subscriber',
				'user_login' => 'member_' . wp_generate_password( 6, false, false ),
			)
		);
		do_action( 'rest_api_init' );
	}

	private function media( string $privacy, string $description = '' ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'Surface probe',
				'description'       => $description,
				'post_author'       => $this->author,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/probe.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'probe-' . wp_generate_password( 8, false, false ),
			)
		);
	}

	private function post( string $path, int $as ) {
		wp_set_current_user( $as );
		$req = new WP_REST_Request( 'POST', '/mvs/v1/' . $path );
		$req->set_param( 'reason', 'spam' );
		return rest_do_request( $req );
	}

	public function test_erasing_a_member_leaves_reports_on_an_item_with_the_same_id(): void {
		global $wpdb;
		$victim = self::factory()->user->create();
		$wpdb->insert( // phpcs:ignore
			$wpdb->prefix . 'mvs_reports',
			array(
				'reporter_id' => $this->member,
				'target_type' => 'media',
				'target_id'   => $victim,
				'reason'      => 'spam',
			)
		);
		$report = (int) $wpdb->insert_id;

		( new \WPMediaVerse\Privacy\MemberPurger() )->purge( $victim );

		$this->assertSame( $victim, (int) $wpdb->get_var( $wpdb->prepare( "SELECT target_id FROM {$wpdb->prefix}mvs_reports WHERE id = %d", $report ) ), 'Erasing a member zeroed a report about a media item.' ); // phpcs:ignore
	}

	public function test_comment_report_needs_sight_and_not_authorship(): void {
		update_option( 'mvs_enable_reports', true );
		$private = $this->media( 'private' );
		$public  = $this->media( 'public' );
		$comments = Plugin::container()->get( 'comments' );
		$on_private = (int) $comments->add( $private, $this->author, 'hidden remark' );
		$on_public  = (int) $comments->add( $public, $this->author, 'visible remark' );

		$this->assertSame( 404, $this->post( 'comments/' . $on_private . '/report', $this->member )->get_status(), 'A comment on media the reporter cannot see was reportable.' );
		$this->assertSame( 400, $this->post( 'comments/' . $on_public . '/report', $this->author )->get_status(), 'Authors could report their own comment.' );
		$this->assertSame( 200, $this->post( 'comments/' . $on_public . '/report', $this->member )->get_status() );
	}

	public function test_message_report_is_for_participants_only(): void {
		update_option( 'mvs_enable_reports', true );
		$messaging = Plugin::container()->get( 'messaging' );
		$convo     = $messaging->find_or_create_conversation( $this->author, $this->member );
		$convo_id  = (int) $convo['conversation_id'];
		$sent      = $messaging->send_message( $convo_id, $this->author, array( 'content' => 'hello there' ) );
		$message   = (int) ( $sent['message_id'] ?? 0 );
		$outsider  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertGreaterThan( 0, $message, 'Fixture: message not sent.' );
		$this->assertSame( 404, $this->post( 'messages/' . $message . '/report', $outsider )->get_status(), 'An outsider could report a private message.' );
		$this->assertSame( 400, $this->post( 'messages/' . $message . '/report', $this->author )->get_status() );
		$this->assertSame( 200, $this->post( 'messages/' . $message . '/report', $this->member )->get_status() );
	}

	public function test_description_mention_respects_privacy_and_is_not_repeated(): void {
		$login   = get_userdata( $this->member )->user_login;
		$private = $this->media( 'private', 'Look @' . $login );
		$public  = $this->media( 'public', 'Look @' . $login );
		$mentions = Plugin::container()->get( 'mentions' );
		$notifier = Plugin::container()->get( 'notifications' );
		// NotificationService::init() registers once per process (static flag),
		// and the test framework restores hooks between tests, so re-attach.
		if ( ! has_action( 'mvs_mentions_created', array( $notifier, 'on_mentions' ) ) ) {
			add_action( 'mvs_mentions_created', array( $notifier, 'on_mentions' ), 10, 4 );
		}

		wp_set_current_user( $this->author );
		$mentions->sync_description( $private );
		$mentions->sync_description( $public );
		$mentions->sync_description( $public );

		$types = wp_list_pluck( Plugin::container()->get( 'notifications' )->get_notifications( $this->member, 20, 1, 'all' )['notifications'], 'media_id' );
		$this->assertNotContains( $private, array_map( 'intval', $types ), 'A mention revealed a private item.' );
		$this->assertSame( 1, count( array_filter( array_map( 'intval', $types ), static fn( $id ) => $id === $public ) ), 'Re-saving mentioned the member again.' );

		wp_set_current_user( $this->member );
		$listed = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/me/mentions' ) )->get_data();
		$this->assertSame( array( $public ), array_map( 'intval', wp_list_pluck( $listed, 'id' ) ) );
		$this->assertSame( $this->author, (int) $listed[0]['mention']['by']['id'] );
	}

	public function test_restoring_a_member_cancels_their_pending_deletion(): void {
		update_user_meta( $this->member, 'mvs_deletion_scheduled_at', time() + DAY_IN_SECONDS );
		update_user_meta( $this->member, 'mvs_suspended', 1 );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$restore = new \ReflectionMethod( \WPMediaVerse\Admin\MemberModeration::class, 'set_suspended' );
		if ( PHP_VERSION_ID < 80100 ) {
			$restore->setAccessible( true );
		}
		$restore->invoke( new \WPMediaVerse\Admin\MemberModeration(), $this->member, false );

		$this->assertSame( 0, Plugin::container()->get( 'account_deletion' )->scheduled_at( $this->member ), 'The member was restored but their deletion still runs.' );
	}

	public function test_old_activity_is_trimmed(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mvs_activity';
		$wpdb->insert( $table, array( 'user_id' => $this->member, 'type' => 'user_follow', 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 200 * DAY_IN_SECONDS ) ) ); // phpcs:ignore
		$old = (int) $wpdb->insert_id;
		$wpdb->insert( $table, array( 'user_id' => $this->member, 'type' => 'user_follow', 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		$new = (int) $wpdb->insert_id;

		do_action( 'mvs_purge_old_views' );

		$left = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table} WHERE id IN ({$old},{$new})" ) ); // phpcs:ignore
		$this->assertSame( array( $new ), $left );
	}

	public function test_someone_who_left_a_group_is_not_added_back_by_the_next_message(): void {
		global $wpdb;
		$messaging = Plugin::container()->get( 'messaging' );
		$third     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$group     = $messaging->create_group_conversation( $this->author, array( $this->member, $third ), 'Crew' );

		$messaging->leave_conversation( $group, $this->member );
		$messaging->send_message( $group, $this->author, array( 'content' => 'still there?' ) );

		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mvs_conversation_participants WHERE conversation_id = %d AND user_id = %d", $group, $this->member ) ); // phpcs:ignore
		$this->assertSame( 'left', $status, 'Leaving a group did not stick.' );
	}
}
