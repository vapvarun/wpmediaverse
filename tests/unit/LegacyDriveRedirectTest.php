<?php
/**
 * The member drive has one home: `?drive=` on the documents page redirects.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\DashboardSections;
use WPMediaVerse\Core\TemplateLoader;

class LegacyDriveRedirectTest extends WP_UnitTestCase {

	/** @var int */
	private $documents_page;

	/** @var int */
	private $dashboard_page;

	public function set_up(): void {
		parent::set_up();
		$this->set_permalink_structure( '/%postname%/' );

		$this->dashboard_page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'my-media',
				'post_title' => 'My Media',
			)
		);
		$this->documents_page = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_name'    => 'explore-document',
				'post_content' => '[mvs_documents]',
			)
		);
		update_option( 'mvs_page_dashboard', $this->dashboard_page );

		// Declared here rather than relying on Pro, so the test does not
		// depend on Pro's licence/capability state.
		add_filter(
			'mvs_dashboard_sections',
			static function ( array $sections ): array {
				$sections['documents'] = array( 'label' => 'Documents' );
				return $sections;
			}
		);
		DashboardSections::flush();
	}

	public function tear_down(): void {
		unset( $_GET['drive'] );
		DashboardSections::flush();
		parent::tear_down();
	}

	/**
	 * Visit the documents page with ?drive=<root> and return the redirect target.
	 */
	private function visit( string $drive ): string {
		$this->go_to( add_query_arg( 'drive', $drive, get_permalink( $this->documents_page ) ) );
		$_GET['drive'] = $drive;

		$caught = '';
		add_filter(
			'wp_redirect',
			static function ( $location ) use ( &$caught ) {
				$caught = (string) $location;
				throw new \RuntimeException( 'mvs-test-redirect' );
			}
		);

		try {
			( new TemplateLoader() )->redirect_legacy_drive_query();
		} catch ( \RuntimeException $e ) {
			if ( 'mvs-test-redirect' !== $e->getMessage() ) {
				throw $e;
			}
		}

		return $caught;
	}

	public function test_my_drive_redirects_to_the_documents_section(): void {
		$target = $this->visit( 'my-drive' );

		$this->assertSame( DashboardSections::url( 'documents' ), $target );
		$this->assertStringEndsWith( '/my-media/documents/', $target );
	}

	public function test_shared_redirects_to_the_shared_view(): void {
		$this->assertStringEndsWith( '/my-media/documents/shared/', $this->visit( 'shared' ) );
	}

	public function test_unknown_drive_is_left_alone(): void {
		$this->assertSame( '', $this->visit( 'nope' ) );
	}

	public function test_no_redirect_without_a_dashboard_page(): void {
		delete_option( 'mvs_page_dashboard' );

		$this->assertSame( '', $this->visit( 'my-drive' ) );
	}

	public function test_no_redirect_when_filtered_off(): void {
		add_filter( 'mvs_redirect_legacy_drive_query', '__return_false' );

		$this->assertSame( '', $this->visit( 'my-drive' ) );
	}
}
