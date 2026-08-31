<?php
/**
 * The half of activation that an UPDATE also has to run.
 *
 * `register_activation_hook` does not fire when a plugin is updated, only when
 * it is switched on — so anything `activate()` creates that a new release
 * depends on never happens on the sites already running the product. 2.4.0 is
 * where that stopped being theoretical: the Documents page simply was not there
 * on any upgrading site, while a fresh install was perfect, which is exactly
 * why it survived testing.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Activator;

/**
 * @since 2.4.0
 */
class UpgradeRoutineTest extends WP_UnitTestCase {

	/**
	 * Set up: a site that has documents available, as Pro makes it.
	 */
	public function set_up(): void {
		parent::set_up();

		add_filter( 'mvs_documents_enabled', '__return_true' );
	}

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		remove_all_filters( 'mvs_documents_enabled' );

		parent::tear_down();
	}

	/**
	 * Put the site in the state an upgrading customer is in.
	 *
	 * @param string $from Version the site was running.
	 * @return void
	 */
	private function simulate_upgrade_from( string $from ): void {
		$existing = (int) get_option( 'mvs_page_explore_documents' );

		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		delete_option( 'mvs_page_explore_documents' );
		update_option( Activator::VERSION_OPTION, $from );
	}

	/**
	 * The page an upgrade needs is created on the first load after it.
	 */
	public function test_an_upgrade_creates_the_documents_page(): void {
		$this->simulate_upgrade_from( '2.3.1' );

		Activator::maybe_upgrade();

		$page = (int) get_option( 'mvs_page_explore_documents' );

		$this->assertGreaterThan( 0, $page, 'An upgrading site must end up with a documents page.' );
		$this->assertSame( 'publish', get_post_status( $page ) );
		$this->assertStringContainsString( '[mvs_documents]', (string) get_post_field( 'post_content', $page ) );
	}

	/**
	 * And the version is stamped, so it does not run again.
	 */
	public function test_the_version_is_recorded_after_upgrading(): void {
		$this->simulate_upgrade_from( '2.3.1' );

		Activator::maybe_upgrade();

		$this->assertSame( MVS_VERSION, get_option( Activator::VERSION_OPTION ) );
	}

	/**
	 * Running it again changes nothing.
	 *
	 * This routine runs on `init`, so "harmless when repeated" is not a nicety:
	 * a non-idempotent version would insert a page on every request the site
	 * serves.
	 */
	public function test_running_twice_does_not_create_a_second_page(): void {
		$this->simulate_upgrade_from( '2.3.1' );

		Activator::maybe_upgrade();
		$first = (int) get_option( 'mvs_page_explore_documents' );

		Activator::maybe_upgrade();
		Activator::maybe_upgrade();

		$this->assertSame( $first, (int) get_option( 'mvs_page_explore_documents' ) );

		$pages = get_posts(
			array(
				'post_type'   => 'page',
				'name'        => 'explore-document',
				'post_status' => 'publish',
				'numberposts' => -1,
				'fields'      => 'ids',
			)
		);

		$this->assertCount( 1, $pages, 'One page, however many times the routine runs.' );
	}

	/**
	 * Pro switched on AFTER Free was already current is still covered.
	 *
	 * The case a version check alone misses, and the one a customer hits by
	 * buying Pro a week after updating Free: nothing changes version, so a
	 * routine keyed only on the version would leave the page missing forever.
	 */
	public function test_a_missing_page_is_created_even_when_the_version_has_not_changed(): void {
		// Free already current — exactly the state that defeats a version check.
		update_option( Activator::VERSION_OPTION, MVS_VERSION );

		$existing = (int) get_option( 'mvs_page_explore_documents' );

		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		delete_option( 'mvs_page_explore_documents' );

		Activator::maybe_upgrade();

		$this->assertGreaterThan(
			0,
			(int) get_option( 'mvs_page_explore_documents' ),
			'Activating Pro later must still get the page documents need.'
		);
	}

	/**
	 * A Free-only site with no legacy documents gets no page.
	 *
	 * The page exists where something can show a document. Creating it anyway
	 * would put an empty listing in a Free site's menu — and the routine runs on
	 * every load, so getting this wrong means a page nobody asked for appearing
	 * on every install.
	 */
	public function test_a_free_only_site_gets_no_documents_page(): void {
		remove_all_filters( 'mvs_documents_enabled' );

		$existing = (int) get_option( 'mvs_page_explore_documents' );

		if ( $existing ) {
			wp_delete_post( $existing, true );
		}

		delete_option( 'mvs_page_explore_documents' );
		update_option( Activator::VERSION_OPTION, '2.3.1' );

		Activator::maybe_upgrade();

		$this->assertSame(
			0,
			(int) get_option( 'mvs_page_explore_documents' ),
			'Free alone cannot show a document, so it gets no page listing them.'
		);
	}
}
