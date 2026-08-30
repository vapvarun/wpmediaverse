<?php
/**
 * Tests for TemplateLoader::is_app_page() / app_page_ids().
 *
 * Activator::create_pages() inserts /my-media/, /explore-media/, /upload-media/
 * as ordinary WP pages, so the active theme's default page template renders a
 * member's photo library beside the theme's blog sidebar. TemplateLoader must be
 * able to identify those plugin-owned pages so template_include can route them to
 * the plugin's own app-page.php instead.
 *
 * These tests set the mvs_page_* options directly rather than running full plugin
 * activation, so they stay fast and side-effect-free — is_app_page() only reads
 * the options, which is exactly what we are asserting.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\DashboardSections;
use WPMediaVerse\Core\TemplateLoader;

class TemplateLoaderTest extends WP_UnitTestCase {

	/**
	 * DashboardSections::all() memoises for the whole PHP process, so without
	 * this the first case to touch it decides the registry every later case
	 * sees — which is exactly how the off-domain assertion below first failed.
	 */
	public function set_up(): void {
		parent::set_up();
		DashboardSections::flush();
	}

	/**
	 * A page recorded in mvs_page_dashboard is an app page.
	 */
	public function test_app_page_ids_include_created_pages(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_option( 'mvs_page_dashboard', $page_id );

		$this->assertContains( $page_id, TemplateLoader::app_page_ids() );
		$this->assertTrue( TemplateLoader::is_app_page( $page_id ) );
	}

	/**
	 * A page the plugin never recorded is not an app page.
	 */
	public function test_non_app_page_is_not_an_app_page(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		$this->assertFalse( TemplateLoader::is_app_page( $page_id ) );
	}

	/**
	 * The mvs_app_page_ids filter can add a page to the set.
	 */
	public function test_filter_can_extend_app_page_ids(): void {
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );

		add_filter(
			'mvs_app_page_ids',
			static function ( array $ids ) use ( $page_id ): array {
				$ids[] = $page_id;
				return $ids;
			}
		);

		$this->assertTrue( TemplateLoader::is_app_page( $page_id ) );
	}

	/**
	 * Capture where a redirect would send us, without letting the exit run.
	 *
	 * wp_safe_redirect() fires `wp_redirect` before sending headers, so throwing
	 * from that filter records the target AND unwinds before redirect_offsite_
	 * section() reaches its exit — which would otherwise kill the test runner.
	 *
	 * @return string Target URL, or '' when no redirect was attempted.
	 */
	private function capture_redirect(): string {
		$caught = '';

		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$caught ) {
				$caught = (string) $location;
				throw new \RuntimeException( 'mvs-test-redirect' );
			}
		);

		try {
			( new TemplateLoader() )->redirect_offsite_section();
		} catch ( \RuntimeException $e ) {
			if ( 'mvs-test-redirect' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $caught;
	}

	/**
	 * Declare a section that lives somewhere else.
	 */
	private function declare_offsite_section( string $slug, string $url ): void {
		add_filter(
			'mvs_dashboard_sections',
			static function ( array $sections ) use ( $slug, $url ): array {
				$sections[ $slug ] = array(
					'label' => 'Offsite',
					'url'   => $url,
				);

				return $sections;
			}
		);

		// The filter is added after set_up(), so drop the memo again or all()
		// answers from the copy resolved before this declaration existed.
		DashboardSections::flush();
	}

	/**
	 * A section declaring its own url redirects there instead of rendering a
	 * dashboard panel nothing is bound to (Basecamp 10252947433).
	 */
	public function test_offsite_section_redirects_to_its_declared_url(): void {
		$target = home_url( '/compete/' );
		$this->declare_offsite_section( 'compete', $target );
		set_query_var( 'mvs_section', 'compete' );

		$this->assertSame( $target, $this->capture_redirect() );
	}

	/**
	 * An ordinary dashboard section is NOT redirected — it renders in place.
	 * This is the assertion that keeps the fix from swallowing the dashboard.
	 */
	public function test_local_section_is_not_redirected(): void {
		set_query_var( 'mvs_section', 'albums' );

		$this->assertSame( '', $this->capture_redirect() );
	}

	/**
	 * No section requested (the dashboard root) is left alone.
	 */
	public function test_no_section_is_not_redirected(): void {
		set_query_var( 'mvs_section', '' );

		$this->assertSame( '', $this->capture_redirect() );
	}

	/**
	 * An off-domain declaration survives wp_safe_redirect's host check.
	 *
	 * Without the scoped allowed_redirect_hosts widening, wp_safe_redirect
	 * rewrites this to the home page — landing the member somewhere they did
	 * not ask for, which is the same blank-screen surprise in a new costume.
	 */
	public function test_offdomain_section_url_is_not_downgraded_to_home(): void {
		$this->declare_offsite_section( 'partner', 'https://community.example.org/compete/' );
		set_query_var( 'mvs_section', 'partner' );

		$this->assertSame( 'https://community.example.org/compete/', $this->capture_redirect() );
	}
}
