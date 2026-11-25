<?php
/**
 * The profile Follow button shows a waiting request on load, not only after the click.
 *
 * "Requested" and the cancel click existed only in the script that set them after a click, so a reload of the
 * profile showed "Follow" again and the member could no longer see or cancel the request.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Server-rendered follow button.
 */
class FollowRequestedStateTest extends WP_UnitTestCase {

	/**
	 * Viewer.
	 *
	 * @var int
	 */
	private int $viewer;

	/**
	 * Member whose profile is shown.
	 *
	 * @var int
	 */
	private int $member;

	/**
	 * Members, with the viewer signed in.
	 */
	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->viewer = self::factory()->user->create();
		$this->member = self::factory()->user->create();
		wp_set_current_user( $this->viewer );
	}

	/**
	 * The profile actions partial, rendered.
	 *
	 * @return string
	 */
	private function render(): string {
		$mvs_profile_id = $this->member; // Read by the partial.
		ob_start();
		include dirname( __DIR__, 2 ) . '/templates/partials/profile-actions.php';
		return (string) ob_get_clean();
	}

	/**
	 * A pending request, as answered by a community plugin, renders Requested with the cancel state on.
	 */
	public function test_a_pending_request_renders_as_requested(): void {
		add_filter( 'mvs_follow_is_requested', '__return_true' );
		$html = $this->render();

		$this->assertStringContainsString( 'data-requested="1"', $html );
		$this->assertMatchesRegularExpression( '#mvs-follow-toggle[^>]*>\s*Requested\s*</button>#s', $html );
		$this->assertStringContainsString( 'data-following="0"', $html, 'A request is not a follow.' );
		$this->assertStringContainsString( 'mvs-btn--primary', $html, 'It keeps the look the click gives it.' );
		$this->assertStringNotContainsString( 'mvs-follow-toggle--following', $html );
	}

	/**
	 * With nothing pending the button is Follow, as before.
	 */
	public function test_without_a_request_the_button_is_follow(): void {
		$html = $this->render();

		$this->assertStringNotContainsString( 'data-requested', $html );
		$this->assertMatchesRegularExpression( '#mvs-follow-toggle[^>]*>\s*Follow\s*</button>#s', $html );
	}

	/**
	 * A member who already follows sees Following, and the filter is not asked.
	 */
	public function test_an_active_follow_is_following_and_ignores_the_filter(): void {
		Plugin::container()->get( 'follows' )->follow( $this->viewer, $this->member );
		add_filter( 'mvs_follow_is_requested', '__return_true' );
		$html = $this->render();

		$this->assertStringContainsString( 'data-following="1"', $html );
		$this->assertStringNotContainsString( 'data-requested', $html );
		$this->assertMatchesRegularExpression( '#mvs-follow-toggle[^>]*>\s*Following\s*</button>#s', $html );
	}
}
