<?php
/**
 * Phase 1 — the media feed is not a document surface.
 *
 * `GET /media` hand-builds its own WHERE rather than going through
 * MediaRepository, so it does not inherit the repository's MEDIA_LIBRARY
 * default and needs its own pin. Until 2.4.0 an explicit `?media_type=document`
 * returned document rows from this route, and the same handler backs
 * `/me/media`, so both leaked. This is also the route the mobile app reads.
 *
 * Build plan: plan/document-library.md §19 (was P1.5, absorbed 2026-08-11)
 * Journey:    audit/journeys/security/07-document-never-in-media-surface.md
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class MediaFeedDocumentRefusalTest extends WP_UnitTestCase {

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
		do_action( 'rest_api_init' );
	}

	/**
	 * Insert through the repository so AUTO_INCREMENT assigns the id.
	 *
	 * Never `$wpdb->insert()` at a chosen media_id: on a populated table that id
	 * is usually taken, the insert fails silently, and cleanup then deletes a row
	 * it never created.
	 *
	 * @param string $type   media_type value.
	 * @param int    $author Author user id.
	 * @return int New media id.
	 */
	private function insert_row( string $type, int $author = 1 ): int {
		return (int) $this->repo->insert(
			array(
				'title'             => 'Feed ' . $type . ' ' . wp_rand( 1000, 9999 ),
				'slug'              => 'feed-' . $type . '-' . wp_rand( 100000, 999999 ),
				'post_author'       => $author,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'media_type'        => $type,
				'file_type'         => 'document' === $type ? 'application/pdf' : $type . '/generic',
				'file_path'         => '2026/08/feed-' . wp_rand( 1000, 9999 ) . '.bin',
			)
		);
	}

	/**
	 * Dispatch a GET against the media feed.
	 *
	 * @param array  $params Query parameters.
	 * @param string $route  Route to hit.
	 * @return \WP_REST_Response
	 */
	private function get_feed( array $params = array(), string $route = '/mvs/v1/media' ) {
		$request = new \WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * The default feed carries a positive type group.
	 */
	public function test_default_feed_excludes_documents(): void {
		$photo = $this->insert_row( 'image' );
		$doc   = $this->insert_row( 'document' );

		$response = $this->get_feed( array( 'per_page' => 100 ) );
		$ids      = array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $photo, $ids, 'The photo should appear in the media feed.' );
		$this->assertNotContains( $doc, $ids, 'A document leaked into the default media feed.' );
	}

	/**
	 * An explicit document request is refused, not silently emptied.
	 *
	 * A 200 with an empty list would read as "there are no documents", which is a
	 * different and wrong statement — and Coding Rule 20 forbids dressing a
	 * refusal as a success.
	 */
	public function test_explicit_document_request_is_refused(): void {
		$this->insert_row( 'document' );

		$response = $this->get_feed( array( 'media_type' => 'document' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'mvs_document_route', $response->as_error()->get_error_code() );
	}

	/**
	 * `/me/media` delegates to the same handler, so it inherits the refusal.
	 *
	 * Worth its own test: the delegation in get_my_items() is exactly the kind of
	 * indirection a refactor breaks without anything else failing.
	 */
	public function test_me_media_inherits_the_refusal(): void {
		$user = self::factory()->user->create();
		wp_set_current_user( $user );
		$this->insert_row( 'document', $user );

		$response = $this->get_feed( array( 'media_type' => 'document' ), '/mvs/v1/me/media' );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'mvs_document_route', $response->as_error()->get_error_code() );
	}

	/**
	 * The guard must not narrow the whole parameter.
	 *
	 * A guard that refuses every media_type would pass the refusal test above
	 * while breaking type filtering for every real client.
	 */
	public function test_media_type_filter_still_works_for_media(): void {
		$photo = $this->insert_row( 'image' );
		$this->insert_row( 'video' );

		$response = $this->get_feed(
			array(
				'media_type' => 'image',
				'per_page'   => 100,
			)
		);
		$data     = (array) $response->get_data();
		$ids      = array_map( 'intval', array_column( $data, 'id' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $photo, $ids );
		foreach ( $data as $item ) {
			$this->assertSame( 'image', $item['media_type'], 'The type filter returned a foreign type.' );
		}
	}

	/**
	 * Production Rule 3 — the escape hatch restores the old behaviour.
	 *
	 * `media_type`'s enum has advertised `document` since the first release, so a
	 * site depending on it must have a one-line way back. An escape hatch nobody
	 * has watched work is not an escape hatch.
	 */
	public function test_escape_hatch_restores_documents(): void {
		$doc = $this->insert_row( 'document' );

		add_filter( 'mvs_media_feed_allows_documents', '__return_true' );
		$response = $this->get_feed(
			array(
				'media_type' => 'document',
				'per_page'   => 100,
			)
		);
		remove_filter( 'mvs_media_feed_allows_documents', '__return_true' );

		$this->assertSame( 200, $response->get_status(), 'The escape hatch did not restore the old behaviour.' );
		$ids = array_map( 'intval', array_column( (array) $response->get_data(), 'id' ) );
		$this->assertContains( $doc, $ids );

		// And the hatch must be off again once removed.
		$this->assertSame( 400, $this->get_feed( array( 'media_type' => 'document' ) )->get_status() );
	}
}
