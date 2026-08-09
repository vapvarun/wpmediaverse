<?php
/**
 * Phase 1 — query discipline.
 *
 * Documents become rows in mvs_media_index with media_type='document'. Every media
 * surface must therefore say what it is FOR (a positive type group) rather than what
 * it is not. These tests pin that.
 *
 * Build plan: plan/document-library-build.md P1.2
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\MediaTypes;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class MediaTypeGroupTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var \WPMediaVerse\Repository\MediaRepository
	 */
	private $repo;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->repo = Plugin::container()->get( 'media_repository' );
	}

	/**
	 * Insert a row through the repository so the ID comes from AUTO_INCREMENT.
	 *
	 * Never `$wpdb->insert()` at a chosen ID: on a populated table that ID is often
	 * already taken, the insert fails silently, and a later cleanup deletes a row it
	 * did not create. That is exactly how four real media rows were destroyed on the
	 * reference install.
	 *
	 * @param string $type  media_type value.
	 * @param string $title Row title.
	 * @return int New media id.
	 */
	private function insert_row( string $type, string $title ): int {
		return (int) $this->repo->insert(
			array(
				'title'             => $title,
				'slug'              => sanitize_title( $title ) . '-' . wp_rand( 1000, 9999 ),
				'post_author'       => 1,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'media_type'        => $type,
				'file_path'         => '2026/08/' . sanitize_title( $title ) . '.bin',
			)
		);
	}

	/**
	 * The clause is a positive list. This is the whole point of the class.
	 */
	public function test_in_clause_is_positive_not_an_exclusion(): void {
		list( $sql, $params ) = MediaTypes::in_clause( MediaTypes::MEDIA );

		$this->assertStringContainsString( 'IN (', $sql );
		$this->assertStringNotContainsString( '!=', $sql, 'The clause must never be an exclusion.' );
		$this->assertStringNotContainsString( 'NOT IN', $sql );
		$this->assertSame( array( 'image', 'video', 'audio' ), $params );
	}

	/**
	 * An empty group must match nothing, never everything.
	 *
	 * Failing open here would undo the class: a caller that passes an empty set by
	 * accident would silently widen its surface to every type.
	 */
	public function test_empty_group_matches_nothing(): void {
		list( $sql, $params ) = MediaTypes::in_clause( array() );

		$this->assertSame( '1 = 0', $sql );
		$this->assertSame( array(), $params );
	}

	/**
	 * Unknown values are dropped; storable values are not.
	 *
	 * `legacy_document` is a value the column legitimately holds after Migrator v26,
	 * so in_clause() must accept it — the document listing asks for it by name via
	 * DOCUMENT_LIBRARY. It is still not a LIBRARY type, which is what is_known()
	 * answers, and it is no longer in MEDIA_LIBRARY.
	 */
	public function test_unknown_types_are_filtered_out(): void {
		list( , $params ) = MediaTypes::in_clause( array( 'image', 'legacy_document', 'nonsense' ) );

		$this->assertSame( array( 'image', 'legacy_document' ), $params, 'A storable value was dropped.' );
		$this->assertNotContains( 'nonsense', $params, 'An unknown value reached SQL.' );

		$this->assertFalse( MediaTypes::is_known( MediaTypes::LEGACY_DOCUMENT ), 'legacy_document is storable, but not a library type.' );
		$this->assertFalse( MediaTypes::is_known( '' ) );
		$this->assertTrue( MediaTypes::is_known( 'document' ) );
	}

	/**
	 * The headline behaviour: the /media feed must not return documents.
	 *
	 * Before this work the feed constrained with `media_type != ''`, which passed
	 * every future type through — including one that did not exist yet.
	 */
	public function test_media_feed_excludes_documents(): void {
		$photo = $this->insert_row( 'image', 'Feed Photo' );
		$doc   = $this->insert_row( 'document', 'Feed Document' );

		$request  = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$ids = wp_list_pluck( (array) $response->get_data(), 'id' );

		$this->assertContains( $photo, $ids, 'The photo should be in the media feed.' );
		$this->assertNotContains( $doc, $ids, 'A document leaked into the media feed.' );
	}

	/**
	 * The opt-in path is REFUSED — the media feed is not a document surface.
	 *
	 * This test previously asserted the opposite, on the reasoning that an
	 * explicit `?media_type=document` is "how a document surface asks for
	 * documents". Reversed by owner decision (2026-08-09) after the P1.5 walk,
	 * for two reasons the original framing missed:
	 *
	 * 1. This route applies MEDIA privacy (public / members / author). Document
	 *    access is grants-first through the folder ancestor chain, so the route
	 *    answers with the wrong permission model — an ACL bypass by construction
	 *    once PermissionService exists, not merely a stale filter.
	 * 2. On a document, `public` means UNLISTED — reachable by URL, never
	 *    discoverable. A feed that enumerates public documents to anonymous
	 *    callers breaks that, and the mobile app reads this same route.
	 *
	 * Full coverage of the refusal, the delegation through `/me/media`, and the
	 * Production Rule 3 escape hatch lives in `MediaFeedDocumentRefusalTest`.
	 * This case stays here so the reversal is visible where the old promise was.
	 */
	public function test_explicit_media_type_param_is_refused_for_documents(): void {
		$this->insert_row( 'image', 'Optin Photo' );
		$this->insert_row( 'document', 'Optin Document' );

		$request = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_param( 'media_type', 'document' );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 400, $response->get_status(), 'The media feed must refuse a document request.' );
		$this->assertSame( 'mvs_document_route', $response->as_error()->get_error_code() );
	}

	/**
	 * An unknown media_type param must narrow to nothing, never widen the feed.
	 */
	public function test_unknown_media_type_param_does_not_widen_the_feed(): void {
		$doc = $this->insert_row( 'document', 'Sneaky Document' );

		$request = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_param( 'media_type', 'nonsense' );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( (array) $response->get_data(), 'id' );

		$this->assertNotContains( $doc, $ids, 'An unknown type param widened the feed to documents.' );
	}

	/**
	 * The orphan stub row the old `!= ''` guard was written for stays excluded.
	 *
	 * The guard is gone, so this proves the replacement kept the behaviour that
	 * guard existed to provide (Basecamp 10074442944).
	 */
	public function test_untyped_rows_stay_out_of_the_feed(): void {
		$orphan = $this->insert_row( '', 'Untyped Orphan' );

		$request  = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( (array) $response->get_data(), 'id' );

		$this->assertNotContains( $orphan, $ids, 'An untyped row reached the feed.' );
	}

	/**
	 * NEITHER kind of document appears in the media feed.
	 *
	 * REVERSED, owner 2026-08-09: *"documents type will never display at media
	 * grid."* This previously asserted that a pre-1.2.3 PDF must keep appearing,
	 * so upgrading would not remove content a member can see. The browser settled
	 * it — that row rendered in Explore as a **broken image tile**, a dark
	 * rectangle with a missing-image glyph. Keeping it "visible" published a
	 * defect rather than content.
	 *
	 * The rows are relocated, not deleted: `MediaTypes::DOCUMENT_LIBRARY` carries
	 * them to the document surfaces, which draw a row with a type chip instead of
	 * trying to draw a picture that does not exist. Absent beats broken, and
	 * correct beats both.
	 */
	public function test_no_document_type_appears_in_the_media_feed(): void {
		$legacy = $this->insert_row( MediaTypes::LEGACY_DOCUMENT, 'Historical PDF' );
		$doc    = $this->insert_row( 'document', 'New Document' );
		$photo  = $this->insert_row( 'image', 'A Photo' );

		$request  = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$response = rest_get_server()->dispatch( $request );
		$ids      = wp_list_pluck( (array) $response->get_data(), 'id' );

		$this->assertContains( $photo, $ids, 'Media must still appear.' );
		$this->assertNotContains( $doc, $ids, 'A document leaked into the media feed.' );
		$this->assertNotContains( $legacy, $ids, 'A legacy PDF rendered as a broken tile in the media grid.' );
	}

	/**
	 * The escape hatch puts the legacy rows back, for a site that wants them.
	 *
	 * Production Rule 3: this IS a default-behaviour change, so one filter
	 * restores the old listing — along with the broken tile it comes with.
	 */
	public function test_media_library_types_filter_restores_legacy_rows(): void {
		$legacy = $this->insert_row( MediaTypes::LEGACY_DOCUMENT, 'Restored PDF' );

		add_filter(
			'mvs_media_library_types',
			static fn( $types ) => array_merge( $types, array( MediaTypes::LEGACY_DOCUMENT ) )
		);

		$restored = MediaTypes::library_types();
		remove_all_filters( 'mvs_media_library_types' );

		$this->assertContains( MediaTypes::LEGACY_DOCUMENT, $restored );
		$this->assertIsInt( $legacy );

		// And a filter cannot widen a listing into rows nothing knows how to draw.
		add_filter( 'mvs_media_library_types', static fn() => array( 'nonsense' ) );
		$this->assertSame( MediaTypes::MEDIA_LIBRARY, MediaTypes::library_types() );
		remove_all_filters( 'mvs_media_library_types' );
	}

	/**
	 * A legacy row belongs to no drive, but the document PAGE still lists it.
	 *
	 * Two different questions, which one constant used to answer badly:
	 * "whose drive is this in?" (`DOCUMENTS` — never a legacy row, it has no
	 * folder and no extraction) and "what does the document page show?"
	 * (`DOCUMENT_LIBRARY` — which does include it, because that surface renders a
	 * row with a type chip and can draw it correctly).
	 */
	public function test_legacy_rows_are_listed_as_documents_but_owned_by_no_drive(): void {
		$this->assertNotContains( MediaTypes::LEGACY_DOCUMENT, MediaTypes::DOCUMENTS, 'No drive may claim a quarantined row.' );
		$this->assertContains( MediaTypes::LEGACY_DOCUMENT, MediaTypes::DOCUMENT_LIBRARY, 'The document page is where they render correctly.' );
		$this->assertNotContains( MediaTypes::LEGACY_DOCUMENT, MediaTypes::MEDIA_LIBRARY, 'They must never reach a media grid.' );
		$this->assertNotContains( MediaTypes::LEGACY_DOCUMENT, MediaTypes::MEDIA );
	}

	/**
	 * An album must not hold a document, and its count must agree with its list.
	 *
	 * Before this work get_items()/get_item_count() joined on `status <> 'trash'`
	 * while the render path (get_items_with_data) filtered to 'publish' in PHP — so
	 * an album containing a scheduled or held-for-moderation item reported a higher
	 * count than it rendered, which the count method's own comment forbids.
	 */
	public function test_album_items_and_count_agree_and_exclude_documents(): void {
		$albums   = Plugin::container()->get( 'albums' );
		$album_id = $albums->create( 1, array( 'title' => 'Mixed Album', 'privacy' => 'public' ) );
		$this->assertIsInt( $album_id );

		$photo     = $this->insert_row( 'image', 'Album Photo' );
		$doc       = $this->insert_row( 'document', 'Album Document' );
		$scheduled = $this->insert_row( 'image', 'Album Scheduled' );
		$this->repo->set( $scheduled, 'status', 'scheduled' );

		$albums->add_items( $album_id, array( $photo, $doc, $scheduled ) );

		$ids = array_map( 'intval', array_column( $albums->get_items( $album_id ), 'media_id' ) );

		$this->assertContains( $photo, $ids, 'The published photo should be in the album.' );
		$this->assertNotContains( $doc, $ids, 'A document leaked into an album.' );
		$this->assertNotContains( $scheduled, $ids, 'A scheduled item leaked into an album.' );

		$this->assertSame(
			count( $ids ),
			$albums->get_item_count( $album_id ),
			'The album count disagrees with the album list.'
		);
	}

	/**
	 * A document must never be picked as an album cover.
	 *
	 * The cover falls back to "first item of any type" when the album holds no
	 * image. A document there is a broken thumbnail on explore, the CPT archive,
	 * album REST and the BuddyPress tab.
	 *
	 * `get_first_image_item()` is private — it is the unit under test, so it is
	 * reached by reflection rather than by asserting on `get_cover_url()`, whose
	 * return also depends on thumbnail files that do not exist in a unit run.
	 */
	public function test_document_is_never_an_album_cover(): void {
		$albums   = Plugin::container()->get( 'albums' );
		$album_id = $albums->create( 1, array( 'title' => 'Docs First Album', 'privacy' => 'public' ) );

		// Document added FIRST, so position order would pick it if type were ignored.
		$doc   = $this->insert_row( 'document', 'Cover Document' );
		$video = $this->insert_row( 'video', 'Cover Video' );
		$albums->add_items( $album_id, array( $doc, $video ) );

		$method = new \ReflectionMethod( $albums, 'get_first_image_item' );
		$method->setAccessible( true );
		$cover = (int) $method->invoke( $albums, $album_id );

		$this->assertNotSame( $doc, $cover, 'A document was chosen as the album cover.' );
		$this->assertSame( $video, $cover, 'The renderable item should have been chosen instead.' );
	}
}
