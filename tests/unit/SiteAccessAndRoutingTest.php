<?php
/**
 * Owner-facing routing and access switches from Zoho #41875.
 *
 * - My Media as a child page keeps its section URLs (Basecamp 10344427213).
 * - "Members only" arms the privacy gate on a standalone site, and a host
 *   community plugin's answer always wins (Basecamp 10344427420).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\TemplateLoader;
use WPMediaVerse\REST\CommunityPrivacyGate;

/**
 * @since 2.6.0
 */
class SiteAccessAndRoutingTest extends WP_UnitTestCase {

	public function tear_down(): void {
		delete_option( 'mvs_page_dashboard' );
		delete_option( 'mvs_page_explore' );
		delete_option( TemplateLoader::OLD_DASHBOARD_PATHS_OPTION );
		unset( $_GET['q'], $_GET['s'] );
		delete_option( CommunityPrivacyGate::MEMBERS_ONLY_OPTION );
		delete_transient( 'mvs_flush_rewrite' );
		remove_all_filters( 'mvs_rest_require_auth' );
		CommunityPrivacyGate::register();
		parent::tear_down();
	}

	/**
	 * Rules registered for the current dashboard mapping.
	 *
	 * @return array<string, string>
	 */
	private function dashboard_rules(): array {
		global $wp_rewrite;

		$wp_rewrite->extra_rules_top = array();
		( new TemplateLoader() )->register_rewrite_rules();

		return $wp_rewrite->extra_rules_top;
	}

	public function test_a_child_dashboard_page_gets_rules_for_its_full_path(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$parent = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'visual-feed',
			)
		);
		$page   = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_name'   => 'my-media',
				'post_parent' => $parent,
			)
		);
		update_option( 'mvs_page_dashboard', $page );

		$rules = $this->dashboard_rules();
		$hits  = array_filter( array_keys( $rules ), static fn( $regex ) => 0 === strpos( $regex, '^visual\-feed\/my\-media/' ) );

		$this->assertNotEmpty( $hits, 'No dashboard rule was built for parent/child path.' );
		foreach ( $hits as $regex ) {
			$this->assertStringContainsString( 'pagename=visual-feed/my-media', $rules[ $regex ] );
		}
		$this->assertArrayHasKey( '^visual\-feed\/my\-media/documents/?$', $rules );
	}

	public function test_moving_the_dashboard_page_queues_a_rewrite_flush(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'mvs_page_dashboard', $page );
		delete_transient( 'mvs_flush_rewrite' );

		$parent = self::factory()->post->create( array( 'post_type' => 'page' ) );
		wp_update_post(
			array(
				'ID'          => $page,
				'post_parent' => $parent,
			)
		);

		$this->assertNotEmpty( get_transient( 'mvs_flush_rewrite' ) );
	}

	public function test_members_only_arms_the_gate_on_a_standalone_site(): void {
		$this->assertFalse( (bool) apply_filters( 'mvs_rest_require_auth', false, null ), 'Off by default.' );
		$this->assertFalse( CommunityPrivacyGate::host_decides() );

		update_option( CommunityPrivacyGate::MEMBERS_ONLY_OPTION, true );

		$this->assertTrue( (bool) apply_filters( 'mvs_rest_require_auth', false, null ) );
	}

	public function test_a_host_community_plugin_always_decides(): void {
		update_option( CommunityPrivacyGate::MEMBERS_ONLY_OPTION, true );
		add_filter( 'mvs_rest_require_auth', '__return_false' );

		$this->assertTrue( CommunityPrivacyGate::host_decides() );
		$this->assertFalse(
			(bool) apply_filters( 'mvs_rest_require_auth', false, null ),
			'The host said public; the Members only setting must not fight it.'
		);
	}

	public function test_members_only_sends_guests_away_from_explore_documents(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'mvs_page_explore_documents', $page );
		update_option( CommunityPrivacyGate::MEMBERS_ONLY_OPTION, true );
		wp_set_current_user( 0 );

		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( CommunityPrivacyGate::page_needs_login() );

		wp_set_current_user( self::factory()->user->create() );
		$this->assertFalse( CommunityPrivacyGate::page_needs_login(), 'Members are never sent to login.' );

		delete_option( 'mvs_page_explore_documents' );
	}

	public function test_moving_the_dashboard_page_remembers_its_old_path(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'my-media',
			)
		);
		update_option( 'mvs_page_dashboard', $page );

		$parent = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'visual-feed',
			)
		);
		wp_update_post(
			array(
				'ID'          => $page,
				'post_parent' => $parent,
			)
		);

		$this->assertSame( array( 'my-media' ), get_option( TemplateLoader::OLD_DASHBOARD_PATHS_OPTION ) );
	}

	public function test_explore_searches_with_q_and_still_reads_old_s_links(): void {
		$helpers = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );

		$_GET['s'] = 'legacy';
		$this->assertSame( 'legacy', $helpers->explore_search(), 'Old /media/?s= links keep working.' );

		$_GET['q'] = 'current';
		$this->assertSame( 'current', $helpers->explore_search(), 'q wins: s is WordPress\'s own search.' );
	}

	public function test_explore_url_is_the_mapped_page_when_there_is_one(): void {
		$helpers = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );
		$this->assertSame( home_url( '/media/' ), $helpers->explore_url() );

		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'mvs_page_explore', $page );
		$this->assertSame( get_permalink( $page ), $helpers->explore_url() );
	}
}
