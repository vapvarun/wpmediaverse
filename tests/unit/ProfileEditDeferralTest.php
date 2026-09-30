<?php
/**
 * One profile editor: fields a community plugin owns are edited there.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\DashboardSections;
use WPMediaVerse\Core\TemplateLoader;
use WPMediaVerse\Services\ProfileService;

class ProfileEditDeferralTest extends WP_UnitTestCase {

	/** @var int */
	private $user_id;

	public function set_up(): void {
		parent::set_up();
		$this->user_id = self::factory()->user->create(
			array(
				'display_name' => 'Original Name',
				'first_name'   => 'Orig',
			)
		);
		wp_set_current_user( $this->user_id );
		DashboardSections::flush();
	}

	public function tear_down(): void {
		DashboardSections::flush();
		parent::tear_down();
	}

	private function defer_names(): void {
		add_filter(
			'mvs_community_profile',
			static function (): array {
				return array(
					'url'    => 'https://example.org/members/me/profile/edit/',
					'label'  => 'Edit your community profile',
					'fields' => array( 'first_name', 'last_name', 'display_name' ),
				);
			}
		);
	}

	public function test_no_community_plugin_defers_nothing(): void {
		$profile = ProfileService::community_profile( $this->user_id );

		$this->assertSame( '', $profile['url'] );
		$this->assertSame( array(), $profile['fields'] );
	}

	public function test_fields_without_a_url_are_not_deferred(): void {
		add_filter(
			'mvs_community_profile',
			static function (): array {
				return array(
					'url'    => '',
					'fields' => array( 'display_name' ),
				);
			}
		);

		$this->assertSame( array(), ProfileService::community_profile( $this->user_id )['fields'] );
	}

	public function test_update_leaves_deferred_fields_alone_but_saves_the_rest(): void {
		$this->defer_names();

		$result = ( new ProfileService() )->update_profile(
			$this->user_id,
			array(
				'display_name' => 'Hijacked',
				'first_name'   => 'Nope',
				'dm_access'    => 'nobody',
			)
		);

		$this->assertTrue( $result );
		$user = get_userdata( $this->user_id );
		$this->assertSame( 'Original Name', $user->display_name );
		$this->assertSame( 'Orig', $user->first_name );
		$this->assertSame( 'nobody', get_user_meta( $this->user_id, '_mvs_dm_access', true ) );
	}

	/**
	 * Render the dashboard profile panel for the current user.
	 */
	private function render_panel(): string {
		$mvs_current_user = wp_get_current_user();
		$mvs_avatar_url   = '';
		$mvs_has_custom   = false;
		$mvs_dash_active  = 'profile';

		ob_start();
		require MVS_PLUGIN_DIR . 'templates/partials/profile-edit-panel.php';
		return (string) ob_get_clean();
	}

	public function test_panel_hides_deferred_fields_and_links_to_the_community_profile(): void {
		$this->defer_names();
		$html = $this->render_panel();

		$this->assertStringNotContainsString( 'First Name', $html );
		$this->assertStringNotContainsString( 'Display Name', $html );
		$this->assertStringContainsString( 'Who can message you', $html );
		$this->assertStringContainsString( 'https://example.org/members/me/profile/edit/', $html );
	}

	public function test_panel_without_deferral_keeps_every_field(): void {
		$html = $this->render_panel();

		$this->assertStringContainsString( 'First Name', $html );
		$this->assertStringNotContainsString( 'Your name is edited on your community profile.', $html );
	}

	/**
	 * Hit /media/edit-profile/ and return the redirect target ('' = none).
	 */
	private function visit_edit_profile(): string {
		set_query_var( 'mvs_edit_profile', 1 );

		$caught = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$caught ) {
				$caught = (string) $location;
				throw new \RuntimeException( 'mvs-test-redirect' );
			}
		);

		try {
			( new TemplateLoader() )->load_media_templates();
		} catch ( \RuntimeException $e ) {
			if ( 'mvs-test-redirect' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $caught;
	}

	public function test_edit_profile_page_redirects_to_the_dashboard_profile_section(): void {
		$this->set_permalink_structure( '/%postname%/' );
		update_option( 'mvs_page_dashboard', self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'my-media' ) ) );

		$this->assertStringEndsWith( '/my-media/profile/', $this->visit_edit_profile() );
	}

	public function test_edit_profile_redirect_can_be_filtered_off(): void {
		update_option( 'mvs_page_dashboard', self::factory()->post->create( array( 'post_type' => 'page' ) ) );
		add_filter( 'mvs_profile_edit_redirect', '__return_false' );

		$this->assertSame( '', $this->visit_edit_profile() );
	}
}
