<?php
/**
 * 2.6.0 admin simplification: fewer sidebar entries, every old URL still works.
 *
 * Logs moved under Tools, Integrations and Reports stay registered (so old
 * bookmarks and redirects resolve) but only appear in the sidebar while they
 * are the current screen, and Free's reports queue became a Moderation tab.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\IntegrationsPage;
use WPMediaVerse\Admin\LogViewerPage;
use WPMediaVerse\Admin\ModerationQueue;
use WPMediaVerse\Admin\ReportsPage;
use WPMediaVerse\Core\Plugin;

class AdminMenuTidyTest extends WP_UnitTestCase {

	/** @var string|null */
	private $pagenow;

	public function set_up(): void {
		parent::set_up();
		$this->pagenow = $GLOBALS['pagenow'] ?? null;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		global $submenu, $_registered_pages, $_parent_pages;
		$submenu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$_parent_pages     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	}

	public function tear_down(): void {
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['pagenow'] = $this->pagenow;
		delete_option( 'mvs_ai_auto_moderate' );
		parent::tear_down();
	}

	/**
	 * Slugs registered under a parent in the sidebar.
	 *
	 * @param string $parent Parent slug.
	 * @return string[]
	 */
	private function slugs_under( string $parent ): array {
		global $submenu;
		return array_map( static fn( $item ) => (string) $item[2], $submenu[ $parent ] ?? array() );
	}

	/**
	 * Run a callable and return where it redirected ('' when it did not).
	 *
	 * @param callable $fn Code under test.
	 * @return string
	 */
	private function redirect_of( callable $fn ): string {
		$caught = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$caught ) {
				$caught = (string) $location;
				throw new \RuntimeException( 'mvs-test-redirect' );
			}
		);

		try {
			$fn();
		} catch ( \RuntimeException $e ) {
			if ( 'mvs-test-redirect' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $caught;
	}

	// ------------------------------------------------------------------ logs --

	public function test_logs_live_under_tools_with_a_named_label(): void {
		( new LogViewerPage() )->add_menu_page();

		global $submenu;
		$this->assertContains( LogViewerPage::PAGE_SLUG, $this->slugs_under( 'tools.php' ) );
		$this->assertNotContains( LogViewerPage::PAGE_SLUG, $this->slugs_under( Plugin::ADMIN_SLUG ) );

		$labels = wp_list_pluck( $submenu['tools.php'], 0 );
		$this->assertContains( 'MediaVerse Logs', $labels, 'Under Tools the entry must say whose logs these are.' );
	}

	public function test_old_logs_bookmark_redirects_to_tools_keeping_its_filters(): void {
		$GLOBALS['pagenow'] = 'admin.php';
		$_GET               = array(
			'page'  => 'mvs-logs',
			'level' => 'error',
		);

		$target = $this->redirect_of( array( new LogViewerPage(), 'redirect_legacy_url' ) );

		$this->assertSame( admin_url( 'tools.php?page=mvs-logs&level=error' ), $target );
	}

	public function test_logs_under_tools_do_not_redirect(): void {
		$GLOBALS['pagenow'] = 'tools.php';
		$_GET               = array( 'page' => 'mvs-logs' );

		$this->assertSame( '', $this->redirect_of( array( new LogViewerPage(), 'redirect_legacy_url' ) ) );
	}

	// --------------------------------------------------------- integrations --

	public function test_integrations_is_hidden_from_the_sidebar_on_other_screens(): void {
		$_GET = array( 'page' => 'mvs-settings' );
		( new IntegrationsPage() )->register_submenu();

		$this->assertNotContains( IntegrationsPage::PAGE_SLUG, $this->slugs_under( Plugin::ADMIN_SLUG ) );
	}

	public function test_integrations_stays_routable_on_its_own_screen(): void {
		$_GET = array( 'page' => IntegrationsPage::PAGE_SLUG );
		( new IntegrationsPage() )->register_submenu();

		$this->assertContains( IntegrationsPage::PAGE_SLUG, $this->slugs_under( Plugin::ADMIN_SLUG ) );
	}

	// -------------------------------------------------------------- reports --

	/**
	 * Free's reports page, built fresh with Pro's moderation tab removed so the
	 * test sees Free's behaviour whether or not Pro is loaded.
	 */
	private function reports_page(): ReportsPage {
		remove_all_filters( 'mvs_moderation_tabs' );
		return new ReportsPage( Plugin::container()->get( 'reports' ) );
	}

	public function test_reports_is_hidden_from_the_sidebar_on_other_screens(): void {
		$_GET = array( 'page' => 'mvs-moderation' );
		$this->reports_page()->add_menu_page();

		$this->assertNotContains( ReportsPage::PAGE_SLUG, $this->slugs_under( Plugin::ADMIN_SLUG ) );
		$this->assertNotContains( ReportsPage::PAGE_SLUG, $this->slugs_under( ModerationQueue::PAGE_SLUG ) );
	}

	public function test_reports_stays_routable_on_its_own_screen(): void {
		$_GET = array( 'page' => ReportsPage::PAGE_SLUG );
		$this->reports_page()->add_menu_page();

		$this->assertContains( ReportsPage::PAGE_SLUG, $this->slugs_under( ModerationQueue::PAGE_SLUG ) );
	}

	public function test_reports_render_as_a_moderation_tab(): void {
		$page = $this->reports_page();
		$tabs = ModerationQueue::build_tabs(
			array(
				'flagged'  => 0,
				'pending'  => 0,
				'rejected' => 0,
			)
		);

		$this->assertArrayHasKey( 'user-reports', $tabs );
		$this->assertSame( 'Reports', $tabs['user-reports']['label'] );
		$this->assertSame( array( $page, 'render_content' ), $tabs['user-reports']['callback'] );
	}

	public function test_report_status_change_returns_to_the_moderation_tab(): void {
		$page = $this->reports_page();

		$_POST    = array(
			'report_id'   => '0',
			'status'      => 'resolved',
			'back_status' => 'dismissed',
			'_wpnonce'    => wp_create_nonce( 'mvs_report_status_0' ),
		);
		$_REQUEST = $_POST;

		$target = $this->redirect_of( array( $page, 'handle_status_change' ) );

		$this->assertSame(
			add_query_arg(
				array(
					'page'          => 'mvs-moderation',
					'tab'           => 'user-reports',
					'report_status' => 'dismissed',
					'updated'       => 'resolved',
				),
				admin_url( 'admin.php' )
			),
			$target
		);
	}

	// ------------------------------------------------------ moderation tabs --

	public function test_ai_flagged_tab_is_hidden_when_ai_is_off_and_nothing_is_flagged(): void {
		remove_all_filters( 'mvs_moderation_tabs' );
		update_option( 'mvs_ai_auto_moderate', '0' );

		$tabs = ModerationQueue::build_tabs(
			array(
				'flagged'  => 0,
				'pending'  => 3,
				'rejected' => 1,
			)
		);

		$this->assertArrayNotHasKey( 'ai-flagged', $tabs );
		$this->assertSame( 'pending', array_key_first( $tabs ), 'The first tab left is the default.' );
	}

	public function test_ai_flagged_tab_shows_while_anything_is_flagged(): void {
		remove_all_filters( 'mvs_moderation_tabs' );
		update_option( 'mvs_ai_auto_moderate', '0' );

		// Reports past the threshold flag media with AI off, so a non-zero
		// count must keep the tab reachable.
		$tabs = ModerationQueue::build_tabs(
			array(
				'flagged'  => 2,
				'pending'  => 0,
				'rejected' => 0,
			)
		);

		$this->assertArrayHasKey( 'ai-flagged', $tabs );
	}

	public function test_ai_flagged_tab_shows_when_ai_moderation_is_on(): void {
		remove_all_filters( 'mvs_moderation_tabs' );
		update_option( 'mvs_ai_auto_moderate', '1' );

		$tabs = ModerationQueue::build_tabs(
			array(
				'flagged'  => 0,
				'pending'  => 0,
				'rejected' => 0,
			)
		);

		$this->assertSame( 'ai-flagged', array_key_first( $tabs ) );
	}
}
