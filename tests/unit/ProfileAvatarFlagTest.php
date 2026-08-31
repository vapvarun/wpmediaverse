<?php
/**
 * has_custom_avatar() must describe the member, not our storage.
 *
 * @package WPMediaVerse
 */

/**
 * Covers the `mvs_has_custom_avatar` seam.
 */
class ProfileAvatarFlagTest extends WP_UnitTestCase {

	/**
	 * The profile service under test.
	 *
	 * @var \WPMediaVerse\Services\ProfileService
	 */
	private $svc;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->svc = \WPMediaVerse\Core\Plugin::container()->get( 'profile' );
	}

	/**
	 * A member nobody has given an avatar reports false.
	 *
	 * The half that is easy to lose: a seam that makes the flag true for
	 * everyone is as useless as one that makes it false for everyone, because
	 * nobody is ever asked for a photo again.
	 */
	public function test_member_with_no_avatar_anywhere_is_false(): void {
		$user_id = self::factory()->user->create();

		$this->assertFalse( $this->svc->has_custom_avatar( $user_id ) );
	}

	/**
	 * Another avatar provider can answer for its own members.
	 *
	 * On the QA site with BuddyNext active, 8 of 8 members who had uploaded a
	 * picture there got `false` here while the `avatar` field in the same REST
	 * response served their real photograph (Basecamp 10252323883). Nothing in
	 * the avatar chain distinguishes a photograph from a generated placeholder —
	 * core's `found_avatar` is set by both — so the owner of the avatar is asked
	 * instead.
	 */
	public function test_a_provider_can_report_an_avatar_we_do_not_store(): void {
		$user_id = self::factory()->user->create();

		$bridge = static function ( $has, $uid ) use ( $user_id ) {
			return $has || $uid === $user_id;
		};

		add_filter( 'mvs_has_custom_avatar', $bridge, 10, 2 );
		$with = $this->svc->has_custom_avatar( $user_id );
		remove_filter( 'mvs_has_custom_avatar', $bridge, 10 );

		$this->assertTrue( $with, 'A provider answered yes and the flag ignored it.' );
		$this->assertFalse(
			$this->svc->has_custom_avatar( $user_id ),
			'The flag kept the filtered answer after the provider went away.'
		);
	}

	/**
	 * A provider cannot silently erase an avatar we DO store.
	 *
	 * The filter is there to widen the answer. If a careless listener returning
	 * a bare false could narrow it, a member's own MediaVerse upload would stop
	 * counting — so this pins the default that is passed in.
	 */
	public function test_our_own_avatar_is_the_default_passed_to_the_filter(): void {
		$user_id = self::factory()->user->create();
		$seen    = null;

		$spy = static function ( $has, $uid ) use ( &$seen ) {
			$seen = $has;
			return $has;
		};

		add_filter( 'mvs_has_custom_avatar', $spy, 10, 2 );
		$this->svc->has_custom_avatar( $user_id );
		remove_filter( 'mvs_has_custom_avatar', $spy, 10 );

		$this->assertFalse( $seen, 'The filter should receive our own store answer as its default.' );
	}
}
