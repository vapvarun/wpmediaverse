<?php
/**
 * Private communities start new uploads at Members Only (Basecamp 10252887515).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\PrivateCommunityDefault;
use WPMediaVerse\Core\Activator;

/**
 * @since 2.6.0
 */
class PrivateCommunityDefaultTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'mvs_rest_require_auth' );
		delete_option( PrivateCommunityDefault::ANSWERED_OPTION );
		parent::tear_down();
	}

	private function make_private(): void {
		add_filter( 'mvs_rest_require_auth', '__return_true' );
	}

	private function run_set_defaults(): void {
		( new \ReflectionMethod( Activator::class, 'set_defaults' ) )->invoke( null );
	}

	public function test_first_activation_on_a_private_community_defaults_to_members(): void {
		delete_option( 'mvs_default_privacy' );
		$this->make_private();

		$this->run_set_defaults();

		$this->assertSame( 'members', get_option( 'mvs_default_privacy' ) );
	}

	public function test_first_activation_on_an_open_site_stays_public(): void {
		delete_option( 'mvs_default_privacy' );

		$this->run_set_defaults();

		$this->assertSame( 'public', get_option( 'mvs_default_privacy' ) );
	}

	public function test_activation_never_replaces_a_saved_choice(): void {
		update_option( 'mvs_default_privacy', 'public' );
		$this->make_private();

		$this->run_set_defaults();

		$this->assertSame( 'public', get_option( 'mvs_default_privacy' ), 'An owner who chose Public keeps it.' );
	}

	public function test_notice_shows_only_on_a_private_community_still_defaulting_to_public(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'toplevel_page_wpmediaverse' );
		update_option( 'mvs_default_privacy', 'public' );
		$notice = new PrivateCommunityDefault();

		ob_start();
		$notice->render_notice();
		$this->assertSame( '', ob_get_clean(), 'Open site: no notice.' );

		$this->make_private();
		ob_start();
		$notice->render_notice();
		$this->assertStringContainsString( 'Use Members Only', ob_get_clean() );

		update_option( PrivateCommunityDefault::ANSWERED_OPTION, 1 );
		ob_start();
		$notice->render_notice();
		$this->assertSame( '', ob_get_clean(), 'Answered once: never asked again.' );

		delete_option( PrivateCommunityDefault::ANSWERED_OPTION );
		update_option( 'mvs_default_privacy', 'members' );
		ob_start();
		$notice->render_notice();
		$this->assertSame( '', ob_get_clean(), 'Already Members Only: nothing to ask.' );

		set_current_screen( 'dashboard' );
		update_option( 'mvs_default_privacy', 'public' );
		ob_start();
		$notice->render_notice();
		$this->assertSame( '', ob_get_clean(), 'Only on MediaVerse screens.' );
	}
}
