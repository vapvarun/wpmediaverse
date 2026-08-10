<?php
/**
 * What "documents are switched off" has to mean, and who may administer them.
 *
 * Both of these were found in a browser rather than in review, which is why
 * they have tests now: the master toggle took away the dashboard tab and the
 * admin screen and left two other surfaces standing, and the capability that
 * exists so document administration can be delegated was masked by
 * `manage_options` everywhere it was checked.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\DocumentListPage;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class DocumentSurfacesTest extends WP_UnitTestCase {

	/**
	 * Tear down.
	 */
	public function tear_down(): void {
		remove_all_filters( 'mvs_documents_enabled' );
		remove_role( 'mvs_test_doc_admin' );

		parent::tear_down();
	}

	// ------------------------------------------------------- the delegation --

	/**
	 * A role holding the document capability opens the Documents screen.
	 *
	 * This is the whole reason a named capability exists: an owner hands
	 * document administration to somebody who must NOT have `manage_options`.
	 * Gating the screen on `manage_options` made the capability decorative —
	 * it appeared in the role matrix, ticking it changed nothing.
	 */
	public function test_a_delegated_role_can_open_the_documents_screen(): void {
		add_role( 'mvs_test_doc_admin', 'Doc Admin', array( 'read' => true, 'manage_mvs_documents' => true ) );

		$user = self::factory()->user->create( array( 'role' => 'mvs_test_doc_admin' ) );

		$this->assertFalse( user_can( $user, 'manage_options' ), 'Precondition: this role is deliberately not an administrator.' );
		$this->assertTrue( user_can( $user, DocumentListPage::CAP ) );
	}

	/**
	 * An administrator keeps the screen whether or not the grant ever ran.
	 *
	 * The reason this is a meta capability rather than the primitive one on the
	 * menu: hardcoding `manage_mvs_documents` would take the screen away from
	 * every administrator on a site where the migration had not run yet.
	 */
	public function test_an_administrator_keeps_the_screen_without_the_grant(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );

		get_role( 'administrator' )->remove_cap( 'manage_mvs_documents' );

		$this->assertTrue( user_can( $admin, DocumentListPage::CAP ) );

		get_role( 'administrator' )->add_cap( 'manage_mvs_documents' );
	}

	/**
	 * A member does not get in either way.
	 */
	public function test_a_subscriber_cannot_open_the_documents_screen(): void {
		$user = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertFalse( user_can( $user, DocumentListPage::CAP ) );
	}

	// ------------------------------------------------------------ off means off --

	/**
	 * The public document listing goes quiet when documents are switched off.
	 *
	 * It carried on listing every public document, each row linking to a single
	 * page the switch had already taken down — a page of dead links on the one
	 * document surface still standing.
	 */
	public function test_the_public_listing_stops_listing_when_documents_are_off(): void {
		add_filter( 'mvs_documents_enabled', '__return_false' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( '', do_shortcode( '[mvs_documents]' ), 'A visitor gets nothing, not an explanation of a setting.' );
	}

	/**
	 * An owner is told why the page went blank.
	 */
	public function test_an_owner_is_told_why_the_listing_is_empty(): void {
		add_filter( 'mvs_documents_enabled', '__return_false' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertStringContainsString( 'switched off', do_shortcode( '[mvs_documents]' ) );
	}

	/**
	 * With documents on, the listing renders again.
	 *
	 * The other half of the assertion above: a guard that is always closed would
	 * pass the two tests before this one and break the feature.
	 */
	public function test_the_listing_renders_when_documents_are_on(): void {
		add_filter( 'mvs_documents_enabled', '__return_true' );

		$this->assertStringNotContainsString( 'switched off', do_shortcode( '[mvs_documents]' ) );
	}

	/**
	 * Free alone still answers "no" — Pro is what can show a document.
	 */
	public function test_free_on_its_own_reports_documents_unavailable(): void {
		remove_all_filters( 'mvs_documents_enabled' );

		$this->assertFalse( Plugin::documents_enabled() );
	}
}
