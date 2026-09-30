<?php
/**
 * Member messaging choices never promise more than the site allows, and the
 * chat panel's "MediaVerse pages" scope includes Explore Documents.
 *
 * Settings audit 2026-09-27 (Basecamp 10301267654).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\EmailService;
use WPMediaVerse\Services\ProfileService;

/**
 * @since 2.6.0
 */
class ProfileMessagingChoicesTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'mvs_dm_access' );
		foreach ( EmailService::TYPES as $option ) {
			delete_option( $option );
		}
		parent::tear_down();
	}

	public function test_members_are_offered_only_the_site_level_or_stricter(): void {
		$this->assertSame( ProfileService::DM_RANK, ProfileService::dm_access_choices(), 'Site default Everyone: all four.' );

		update_option( 'mvs_dm_access', 'mutual' );
		$this->assertSame( array( 'mutual', 'nobody' ), ProfileService::dm_access_choices() );
		$this->assertSame( array( 'mutual', 'nobody' ), array_keys( ProfileService::dm_access_options() ) );
	}

	public function test_a_looser_choice_is_saved_as_the_site_level(): void {
		update_option( 'mvs_dm_access', 'mutual' );
		$user    = self::factory()->user->create();
		$service = new ProfileService();

		$service->update_profile( $user, array( 'dm_access' => 'everyone' ) );

		$this->assertSame( 'mutual', get_user_meta( $user, '_mvs_dm_access', true ), 'Saved must be true: no stored value the site will not honour.' );

		$service->update_profile( $user, array( 'dm_access' => 'nobody' ) );
		$this->assertSame( 'nobody', get_user_meta( $user, '_mvs_dm_access', true ), 'Stricter than the site is kept.' );
	}

	public function test_the_form_shows_what_the_site_enforces(): void {
		$user = self::factory()->user->create();
		update_user_meta( $user, '_mvs_dm_access', 'everyone' );
		update_option( 'mvs_dm_access', 'followers' );

		$this->assertSame( 'followers', ProfileService::effective_dm_access( $user ) );

		$profile = ( new ProfileService() )->get_profile( $user );
		$this->assertSame( 'followers', $profile['dm_access'] );
		$this->assertSame( array( 'followers', 'mutual', 'nobody' ), $profile['dm_access_choices'] );
	}

	public function test_activity_email_switch_is_available_only_when_an_email_type_is_on(): void {
		foreach ( EmailService::TYPES as $option ) {
			update_option( $option, '' );
		}
		$this->assertFalse( EmailService::any_type_enabled() );

		update_option( array_values( EmailService::TYPES )[0], '1' );
		$this->assertTrue( EmailService::any_type_enabled() );
	}

	public function test_chat_panel_mvs_pages_scope_includes_explore_documents(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'mvs_page_explore_documents', $page );
		$this->go_to( get_permalink( $page ) );

		$this->assertTrue( ( new \ReflectionMethod( Plugin::class, 'is_mvs_page' ) )->invoke( null ) );
	}

	public function test_follow_back_refusal_uses_the_words_members_see(): void {
		$message = Plugin::container()->get( 'messaging' )->denial_message( 'mutual_follow_required' );

		$this->assertStringContainsString( 'follow back', $message, 'The member choice is labelled "People you follow back" (Basecamp 10344500471).' );
		$this->assertStringNotContainsString( 'connected', $message );
	}
}
