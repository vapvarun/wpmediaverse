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
 * @since 2.3.3
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
	 * Unknown values are dropped rather than passed through to SQL.
	 */
	public function test_unknown_types_are_filtered_out(): void {
		list( , $params ) = MediaTypes::in_clause( array( 'image', 'legacy_document', 'nonsense' ) );

		$this->assertSame( array( 'image' ), $params );
		$this->assertFalse( MediaTypes::is_known( 'legacy_document' ) );
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
	 * The opt-in path still works — that is how a document surface asks for documents.
	 */
	public function test_explicit_media_type_param_can_request_documents(): void {
		$photo = $this->insert_row( 'image', 'Optin Photo' );
		$doc   = $this->insert_row( 'document', 'Optin Document' );

		$request = new \WP_REST_Request( 'GET', '/mvs/v1/media' );
		$request->set_param( 'media_type', 'document' );
		$response = rest_get_server()->dispatch( $request );

		$ids = wp_list_pluck( (array) $response->get_data(), 'id' );

		$this->assertContains( $doc, $ids, 'An explicit media_type=document must return documents.' );
		$this->assertNotContains( $photo, $ids, 'An explicit type filter must exclude other types.' );
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
}
