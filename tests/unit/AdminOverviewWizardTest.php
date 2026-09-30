<?php
/**
 * 2.6.0 admin simplification: the Overview screen and the setup wizard.
 *
 * Overview drops the System Status widget for an Integrations summary and only
 * offers demo content on an empty site. The wizard lost its read-only Pages
 * step and the Grid Columns row, and an old `step=pages` link lands on Display.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\OverviewPage;
use WPMediaVerse\Admin\SetupWizard;
use WPMediaVerse\Repository\MediaRepository;

class AdminOverviewWizardTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Keep the welcome banner out of the way; it is not under test here.
		update_user_meta( get_current_user_id(), '_mvs_welcome_dismissed', 1 );
		delete_option( 'mvs_demo_seeded' );
		$this->purge_media();
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function purge_media(): void {
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}mvs_media_index" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		MediaRepository::reset_test_cache();
	}

	private function add_media(): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_index',
			array(
				'title'             => 'Owner upload',
				'slug'              => 'owner-upload-' . wp_rand( 1000, 99999 ),
				'post_author'       => get_current_user_id(),
				'media_type'        => 'image',
				'file_type'         => 'image/jpeg',
				'file_path'         => 'x/owner.jpg',
				'file_size'         => 1024,
				'privacy'           => 'public',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'created_at'        => current_time( 'mysql', true ),
			)
		);
		MediaRepository::reset_test_cache();
	}

	private function overview(): string {
		ob_start();
		( new OverviewPage() )->render_page();
		return (string) ob_get_clean();
	}

	// ----------------------------------------------------------- demo import --

	public function test_demo_import_is_offered_on_an_empty_site(): void {
		$this->assertStringContainsString( 'mvs-import-demo-btn', $this->overview() );
	}

	public function test_demo_import_is_hidden_once_the_site_has_media(): void {
		$this->add_media();

		$html = $this->overview();

		$this->assertStringNotContainsString( 'mvs-import-demo-btn', $html );
		$this->assertStringNotContainsString( 'mvs-cleanup-demo-btn', $html, 'No demo data, so nothing to delete either.' );
	}

	public function test_demo_import_can_be_forced_back_by_filter(): void {
		$this->add_media();
		add_filter( 'mvs_show_demo_import', '__return_true' );

		$this->assertStringContainsString( 'mvs-import-demo-btn', $this->overview() );
	}

	public function test_seeded_site_offers_cleanup_not_import(): void {
		update_option( 'mvs_demo_seeded', 1 );

		$html = $this->overview();

		$this->assertStringContainsString( 'mvs-cleanup-demo-btn', $html );
		$this->assertStringNotContainsString( 'mvs-import-demo-btn', $html );
	}

	// ---------------------------------------------------- status / integrations --

	public function test_overview_has_no_system_status_widget(): void {
		$this->assertStringNotContainsString( 'System Status', $this->overview() );
	}

	public function test_integrations_card_is_shown_to_admins(): void {
		$html = $this->overview();

		$this->assertStringContainsString( 'page=mvs-integrations', $html );
		$this->assertStringContainsString( 'View integrations', $html );
	}

	public function test_integrations_card_is_hidden_from_non_admins(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertStringNotContainsString( 'View integrations', $this->overview() );
	}

	// ----------------------------------------------------------------- wizard --

	private function wizard( string $step ): string {
		$_GET = array(
			'page' => SetupWizard::PAGE_SLUG,
			'step' => $step,
		);
		ob_start();
		( new SetupWizard() )->render_wizard();
		return (string) ob_get_clean();
	}

	public function test_welcome_continues_straight_to_display(): void {
		$html = $this->wizard( 'welcome' );

		$this->assertStringContainsString( 'step=display', $html );
		$this->assertStringNotContainsString( 'step=pages', $html );
	}

	public function test_old_pages_step_link_lands_on_display(): void {
		$html = $this->wizard( 'pages' );

		$this->assertStringContainsString( 'name="mvs_wizard_step" value="display"', $html );
	}

	public function test_display_step_has_no_grid_columns(): void {
		$this->assertStringNotContainsString( 'mvs_grid_columns', $this->wizard( 'display' ) );
	}
}
