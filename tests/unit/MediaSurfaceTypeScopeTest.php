<?php
/**
 * Phase 1 — query discipline, remaining Free surfaces.
 *
 * Collections, profiles, interests and member stats all read mvs_media_index
 * with no type predicate, so each adopts documents by default the moment they
 * exist. These pin the positive-inclusion behaviour per surface.
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
class MediaSurfaceTypeScopeTest extends WP_UnitTestCase {

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
	 * Insert through the repository so the id comes from AUTO_INCREMENT.
	 *
	 * @param string $type   media_type value.
	 * @param int    $author Author user id.
	 * @return int New media id.
	 */
	private function insert_row( string $type, int $author = 1 ): int {
		return (int) $this->repo->insert(
			array(
				'title'             => 'Surface ' . $type . ' ' . wp_rand( 1000, 9999 ),
				'slug'              => 'surface-' . $type . '-' . wp_rand( 100000, 999999 ),
				'post_author'       => $author,
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'media_type'        => $type,
				'file_type'         => 'document' === $type ? 'application/pdf' : $type . '/generic',
				'file_path'         => '2026/08/surface-' . wp_rand( 1000, 9999 ) . '.bin',
			)
		);
	}

	/**
	 * A smart collection is a MEDIA collection.
	 *
	 * The rule here is author-based, i.e. it says nothing about type — which is
	 * exactly the case that swept documents in before, since the base condition
	 * constrained status alone.
	 */
	public function test_smart_collection_rules_never_resolve_to_documents(): void {
		$author = self::factory()->user->create();

		$photo = $this->insert_row( 'image', $author );
		$doc   = $this->insert_row( 'document', $author );

		$collection_id = self::factory()->post->create( array( 'post_type' => 'mvs_collection' ) );
		update_post_meta(
			$collection_id,
			'_mvs_collection_rules',
			array( array( 'key' => 'author', 'value' => $author ) )
		);

		$result = Plugin::container()->get( 'collections' )->resolve( $collection_id, 50, 1 );
		$ids    = array_map( 'intval', array_column( $result['items'], 'media_id' ) );

		$this->assertContains( $photo, $ids, 'The photo should resolve into the collection.' );
		$this->assertNotContains( $doc, $ids, 'A document resolved into a media collection.' );
		$this->assertSame( count( $ids ), (int) $result['total'], 'The collection total disagrees with its items.' );
	}

	/**
	 * A profile's media count must match the grid beneath it.
	 *
	 * This controller already carries a comment about that agreement, written when
	 * trashed items were leaking into the total. Type is the same requirement one
	 * step on: counting documents while rendering a media grid tells the visitor
	 * the same lie by another route.
	 */
	public function test_profile_media_count_excludes_documents(): void {
		$user = self::factory()->user->create();

		$this->insert_row( 'image', $user );
		$this->insert_row( 'video', $user );
		$this->insert_row( 'document', $user );

		$request  = new \WP_REST_Request( 'GET', '/mvs/v1/users/' . $user );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );

		$data = (array) $response->get_data();
		$this->assertSame( 2, (int) $data['media_count'], 'A document was counted on a member profile.' );
	}

	/**
	 * A member's `total_media` counts media.
	 *
	 * Folding documents in would silently change every existing member's headline
	 * number on upgrade.
	 */
	public function test_member_stats_total_media_excludes_documents(): void {
		$user = self::factory()->user->create();

		$photo = $this->insert_row( 'image', $user );
		$doc   = $this->insert_row( 'document', $user );

		// get_for_user() INNER JOINs mvs_media_stats, so both rows need one.
		foreach ( array( $photo, $doc ) as $mid ) {
			$GLOBALS['wpdb']->insert(
				$GLOBALS['wpdb']->prefix . 'mvs_media_stats',
				array( 'media_id' => $mid, 'views' => 5 ),
				array( '%d', '%d' )
			);
		}

		$stats = Plugin::container()->get( 'stats' )->get_for_user( $user );

		$this->assertSame( 1, (int) $stats['total_media'], 'A document was counted in a member total.' );
		$this->assertSame( 5, (int) $stats['total_views'], 'Document views were summed into a media stat.' );
	}

	/**
	 * "Who to follow" must not be buyable with documents.
	 *
	 * The candidate pool ranks by COUNT(*) of public output, so without a type
	 * list a member reaches a discovery feed by bulk-uploading files nobody
	 * browses there — the same exploit shape as the Pro leaderboard.
	 */
	public function test_suggestion_pool_is_not_earned_with_documents(): void {
		$stuffer = self::factory()->user->create();
		$viewer  = self::factory()->user->create();

		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_row( 'document', $stuffer );
		}

		delete_transient( 'mvs_top_creators' );

		// Not a container service — UserController news it up directly.
		$suggestions = ( new \WPMediaVerse\Social\SuggestionService() )->get_suggestions( $viewer, 50 );
		$ids         = array_map( 'intval', array_column( $suggestions, 'user_id' ) );

		$this->assertNotContains( $stuffer, $ids, 'Document uploads bought a place in "who to follow".' );
	}

	/**
	 * The repository default is the one that covers every listing at once.
	 *
	 * query(), query_count() and query_by_author() all funnel through the same
	 * arg normalisation, and only explore.php ever passed the old
	 * exclude_empty_media_type flag — so every other listing (the BuddyPress
	 * profile and group tabs, the Pro layout feeds) ran with no type predicate.
	 */
	public function test_repository_query_defaults_to_the_media_library(): void {
		$author = self::factory()->user->create();

		$photo = $this->insert_row( 'image', $author );
		$doc   = $this->insert_row( 'document', $author );

		$rows = $this->repo->query( array( 'author_id' => $author, 'limit' => 50 ) );
		$ids  = array_map( 'intval', array_column( $rows, 'media_id' ) );

		$this->assertContains( $photo, $ids );
		$this->assertNotContains( $doc, $ids, 'A document reached a default repository listing.' );

		$this->assertSame(
			count( $ids ),
			$this->repo->query_count( array( 'author_id' => $author ) ),
			'query_count() disagrees with query() on the same args.'
		);
	}

	/**
	 * …and the document library asks for itself explicitly.
	 *
	 * The default must be narrow, but it must not be a dead end — this is the
	 * seam the document surfaces will use.
	 */
	public function test_repository_query_can_be_asked_for_documents(): void {
		$author = self::factory()->user->create();

		$photo = $this->insert_row( 'image', $author );
		$doc   = $this->insert_row( 'document', $author );

		$rows = $this->repo->query(
			array(
				'author_id'   => $author,
				'limit'       => 50,
				'media_types' => MediaTypes::DOCUMENTS,
			)
		);
		$ids  = array_map( 'intval', array_column( $rows, 'media_id' ) );

		$this->assertContains( $doc, $ids, 'An explicit document listing returned no documents.' );
		$this->assertNotContains( $photo, $ids, 'An explicit document listing returned media.' );
	}

	/**
	 * The BuddyPress profile tab and /users/{id}/media inherit the default.
	 *
	 * query_by_author() delegates to query(), so this proves the fix reaches the
	 * callers that never passed a flag of their own.
	 */
	public function test_profile_listing_inherits_the_media_default(): void {
		$author = self::factory()->user->create();

		$photo = $this->insert_row( 'image', $author );
		$doc   = $this->insert_row( 'document', $author );

		$rows = $this->repo->query_by_author( $author, array( 'limit' => 50, 'include_private' => true ) );
		$ids  = array_map( 'intval', array_column( $rows, 'media_id' ) );

		$this->assertContains( $photo, $ids );
		$this->assertNotContains( $doc, $ids, 'A document reached the profile media listing.' );
	}

	/**
	 * A legacy PDF does not count as MEDIA on a profile.
	 *
	 * REVERSED with the MEDIA_LIBRARY change (owner, 2026-08-09: "documents type
	 * will never display at media grid"). This previously asserted the opposite,
	 * to stop an upgrade deducting from a member's visible numbers. The browser
	 * settled it: the row a profile was counting rendered in Explore as a broken
	 * image tile, so the count was promising a picture that did not exist.
	 *
	 * The rows are not lost — they move to the document surfaces, which draw them
	 * as rows with a type chip. Counting them as media was the part that was
	 * wrong, not their existence.
	 */
	public function test_legacy_documents_do_not_count_as_media_on_a_profile(): void {
		$user = self::factory()->user->create();

		$this->insert_row( 'image', $user );
		$this->insert_row( MediaTypes::LEGACY_DOCUMENT, $user );

		$request  = new \WP_REST_Request( 'GET', '/mvs/v1/users/' . $user );
		$response = rest_get_server()->dispatch( $request );
		$data     = (array) $response->get_data();

		$this->assertSame( 1, (int) $data['media_count'], 'Only the photo is media.' );
	}
}
