<?php
/**
 * 2.6.0 privacy and moderation guards.
 *
 * - A media item the caller may not open answers exactly like one that does
 *   not exist (404 mvs_not_found), so its existence is not confirmed.
 * - AI moderation's retired 'hide' action hides by status only; it no longer
 *   rewrites the member's privacy, which approving never restored.
 * - Report auto-hide goes through ModerationService, so the moderation hook
 *   (cache, webhook, log) fires.
 * - The media privacy filter is not fired for album posts, whose ids collide
 *   with media ids.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;

class HiddenLooksMissingTest extends WP_UnitTestCase {

	private int $owner;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->owner = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		do_action( 'rest_api_init' );
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	private function media( string $privacy ): int {
		return (int) $this->repo()->insert(
			array(
				'title'             => 'Hidden probe',
				'post_author'       => $this->owner,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/hidden.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'hidden-' . wp_generate_password( 8, false, false ),
			)
		);
	}

	private function get( int $id ) {
		$req = new WP_REST_Request( 'GET', '/mvs/v1/media/' . $id );
		return rest_do_request( $req );
	}

	public function test_private_media_answers_like_missing_media(): void {
		$private = $this->media( 'private' );
		wp_set_current_user( $this->other );

		$hidden  = $this->get( $private );
		$missing = $this->get( 999999999 );

		$this->assertSame( 404, $hidden->get_status() );
		$this->assertSame( $missing->get_status(), $hidden->get_status() );
		$this->assertSame( $missing->get_data()['code'], $hidden->get_data()['code'] );
	}

	public function test_hide_action_does_not_rewrite_member_privacy(): void {
		$id = $this->media( 'public' );
		update_option( 'mvs_moderation_auto_action', 'hide' );

		\WPMediaVerse\Core\Plugin::container()->get( 'moderation' )->handle_flagged( $id, array() );

		$this->assertSame( 'flagged', $this->repo()->get( $id, 'moderation_status' ) );
		$this->assertSame( 'public', $this->repo()->get( $id, 'privacy' ), 'Hide forced the member\'s item private.' );
		delete_option( 'mvs_moderation_auto_action' );
	}

	public function test_report_auto_hide_fires_the_moderation_hook(): void {
		$id    = $this->media( 'public' );
		$fired = 0;
		$count = static function () use ( &$fired ) {
			++$fired;
		};
		add_action( 'mvs_moderation_changed', $count );
		update_option( 'mvs_report_auto_hide_threshold', 1 );

		$reporter = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		\WPMediaVerse\Core\Plugin::container()->get( 'reports' )->report( $reporter, 'media', $id, 'spam' );

		$this->assertSame( 'flagged', $this->repo()->get( $id, 'moderation_status' ) );
		$this->assertSame( 1, $fired, 'Auto-hide skipped ModerationService, so cache/webhook/log never heard.' );

		remove_action( 'mvs_moderation_changed', $count );
		delete_option( 'mvs_report_auto_hide_threshold' );
	}

	public function test_media_privacy_filter_is_not_fired_for_albums(): void {
		$album = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_album',
				'post_author' => $this->owner,
			)
		);
		$seen  = array();
		$spy   = static function ( $result, $id ) use ( &$seen ) {
			$seen[] = (int) $id;
			return $result;
		};
		add_filter( 'mvs_privacy_can_view', $spy, 1, 2 );

		\WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( $album, $this->other, \WPMediaVerse\Services\PrivacyService::SPACE_CPT );

		$this->assertNotContains( $album, $seen, 'The media filter was handed an album id.' );
		remove_filter( 'mvs_privacy_can_view', $spy, 1 );
	}
}
