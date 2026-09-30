<?php
/**
 * BuddyNext / community notification contract: the payload appended to
 * mvs_notification_created, the declared type catalogue, the visibility
 * answer and the permanent-delete purge.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Social\CommunityNotificationContract;

class CommunityNotificationContractTest extends WP_UnitTestCase {

	private int $owner;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->owner = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	/**
	 * A published, public media item.
	 *
	 * @param array<string,mixed> $over Overrides.
	 * @return int Media id.
	 */
	private function media( array $over = array() ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array_merge(
				array(
					'title'             => 'Contract probe',
					'post_author'       => $this->owner,
					'media_type'        => 'image',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => 'public',
					'file_path'         => '2026/09/probe.jpg',
					'file_type'         => 'image/jpeg',
					'slug'              => 'contract-' . wp_generate_password( 8, false, false ),
				),
				$over
			)
		);
	}

	/**
	 * Every existing (pre-contract) listener on the hook keeps receiving
	 * exactly the arguments it declared - the payload is appended, not
	 * inserted, and `do_action()` never hands the extra argument to a
	 * callback with a smaller `accepted_args`.
	 *
	 * @return void
	 */
	public function test_existing_seven_arg_listeners_are_unaffected(): void {
		$media  = $this->media();
		$member = self::factory()->user->create();

		$seen_args = null;
		add_action(
			'mvs_notification_created',
			static function ( $id, $uid, $type, $actor, $mid, $msg, $link ) use ( &$seen_args ) {
				$seen_args = func_get_args();
			},
			10,
			7
		);

		Plugin::container()->get( 'notifications' )->create( $member, 'media_reaction', $this->owner, $media );

		$this->assertCount( 7, $seen_args, 'A listener declared with accepted_args=7 must receive exactly 7 arguments.' );
	}

	/**
	 * A real member notification carries a complete contract payload as the
	 * 9th argument.
	 *
	 * @return void
	 */
	public function test_a_member_facing_dispatch_carries_the_payload(): void {
		$media  = $this->media();
		$member = self::factory()->user->create();

		$seen = null;
		add_action(
			'mvs_notification_created',
			static function ( $id, $uid, $type, $actor, $mid, $msg, $link, $object_id, $payload ) use ( &$seen ) {
				$seen = $payload;
			},
			10,
			9
		);

		Plugin::container()->get( 'notifications' )->create( $member, 'media_reaction', $this->owner, $media );

		$this->assertIsArray( $seen );
		$this->assertSame( $member, $seen['recipient_id'] );
		$this->assertSame( $this->owner, $seen['actor_id'] );
		$this->assertSame( 'media_reaction', $seen['type'] );
		$this->assertSame( 'media', $seen['object_type'] );
		$this->assertSame( $media, $seen['object_id'] );
		$this->assertNotEmpty( $seen['message'] );
		$this->assertNotEmpty( $seen['url'] );

		// Repeats on the same media merge into one bell row.
		$this->assertSame( 'media_reaction_' . $media, $seen['group_key'] );
		$this->assertStringStartsWith( '{actor} and {others}', $seen['message_grouped'] );
	}

	/**
	 * Only a DECLARED type is sent. A type another plugin adds to
	 * `mvs_notification_types` (Pro's competitions and documents) has no switch
	 * in the host's settings, and competitions carry a competition id in the
	 * media slot, so it must never reach the host bell undeclared.
	 *
	 * @return void
	 */
	public function test_an_undeclared_type_never_carries_a_payload(): void {
		$media  = $this->media();
		$member = self::factory()->user->create();

		add_filter(
			'mvs_notification_types',
			static function ( $types ) {
				$types[] = 'battle_invite';
				return $types;
			}
		);
		add_filter(
			'mvs_notification_link',
			static function ( $link, $type ) {
				return 'battle_invite' === $type ? 'https://example.test/compete/battles/9/' : $link;
			},
			10,
			2
		);

		$seen = 'not fired';
		add_action(
			'mvs_notification_created',
			static function ( $id, $uid, $type, $actor, $mid, $msg, $link, $object_id, $payload ) use ( &$seen ) {
				$seen = $payload;
			},
			10,
			9
		);

		Plugin::container()->get( 'notifications' )->create( $member, 'battle_invite', $this->owner, $media );

		$this->assertSame( array(), $seen, 'An undeclared type reached the community bell.' );
	}

	/**
	 * The four types a host community plugin already owns (follows, media
	 * comments, favorites, direct messages) never carry a contract payload,
	 * even if a host forgot to wire `mvs_should_send_notification` and the
	 * notification reaches `create()` anyway.
	 *
	 * @return void
	 */
	public function test_host_owned_types_never_carry_a_payload(): void {
		$media  = $this->media();
		$member = self::factory()->user->create();

		foreach ( array( 'new_follower', 'media_comment', 'media_favorite', 'new_message' ) as $type ) {
			$payload = CommunityNotificationContract::payload( 1, $member, $type, $this->owner, $media, 'Someone did a thing', 'https://example.test/media/1/' );
			$this->assertSame( array(), $payload, "\"$type\" must never carry a contract payload." );
		}
	}

	/**
	 * A type with no real destination (report_resolved names no media and
	 * links nowhere) never carries a payload - a host would drop it anyway
	 * (no url), so this plugin never declares or sends it as a contract
	 * notification.
	 *
	 * @return void
	 */
	public function test_report_resolved_has_no_payload(): void {
		$member = self::factory()->user->create();

		$payload = CommunityNotificationContract::payload( 1, $member, 'report_resolved', $this->owner, 0, 'A moderator reviewed your report.', '' );
		$this->assertSame( array(), $payload );
	}

	/**
	 * A recipient acting on themselves is not a notification.
	 *
	 * @return void
	 */
	public function test_a_self_action_is_not_a_notification(): void {
		$member = self::factory()->user->create();

		$payload = CommunityNotificationContract::payload( 1, $member, 'media_reaction', $member, 5, 'You reacted', 'https://example.test/media/5/' );
		$this->assertSame( array(), $payload );
	}

	/**
	 * Every type this plugin actually sends through the contract is
	 * declared, so a host can list it with a settings switch.
	 *
	 * @return void
	 */
	public function test_declared_types_match_what_is_sent(): void {
		$types = (array) apply_filters( 'mvs_community_notification_types', array() );

		foreach ( array( 'media_reaction', 'media_mention' ) as $type ) {
			$this->assertArrayHasKey( $type, $types );
			$this->assertNotEmpty( $types[ $type ]['label'] ?? '' );
		}
		foreach ( array( 'new_follower', 'media_comment', 'media_favorite', 'new_message', 'report_resolved' ) as $type ) {
			$this->assertArrayNotHasKey( $type, $types, "\"$type\" is never sent through the contract and must not be declared." );
		}
	}

	/**
	 * Visibility follows the SAME `PrivacyService::can_view()` rule every
	 * other privacy decision in this plugin uses - never a second rule for
	 * the community bell.
	 *
	 * @return void
	 */
	public function test_visibility_follows_privacy_service(): void {
		$public  = $this->media();
		$private = $this->media( array( 'privacy' => 'private' ) );

		$targets = array(
			'a' => array( 'type' => 'media_reaction', 'object_type' => 'media', 'object_id' => $public, 'actor_id' => $this->owner ),
			'b' => array( 'type' => 'media_reaction', 'object_type' => 'media', 'object_id' => $private, 'actor_id' => $this->owner ),
		);

		$stranger = self::factory()->user->create();
		$visible  = (array) apply_filters(
			'mvs_community_notification_visible',
			array_fill_keys( array_keys( $targets ), true ),
			$stranger,
			$targets
		);

		$this->assertTrue( $visible['a'] );
		$this->assertFalse( $visible['b'], 'A stranger must not see a bell row about a private media item.' );
	}

	/**
	 * Permanently deleting media fires the removal hook so a host purges
	 * every bell row about it.
	 *
	 * @return void
	 */
	public function test_permanent_delete_fires_the_removal_hook(): void {
		$media = $this->media();

		$removed = null;
		add_action(
			'mvs_community_notification_removed',
			static function ( $object_type, $object_id ) use ( &$removed ) {
				$removed = array( $object_type, $object_id );
			},
			10,
			2
		);

		Plugin::container()->get( 'media_repository' )->delete_cascade( $media );

		$this->assertSame( array( 'media', $media ), $removed );
	}
}
