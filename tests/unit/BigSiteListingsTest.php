<?php
/**
 * Big-site readiness (2.6.0): pagination + caching + N+1 guards for the
 * listing surfaces a 50k-media / 10k-member install actually hits.
 *
 * - Album items route: real LIMIT/OFFSET, and the total matches only what
 *   THIS viewer may see (privacy stays applied even when paginated).
 * - Tag cloud: a second call within the cache window costs zero queries.
 * - Activity feed: batch-prefetches actors + media, so the query count does
 *   not grow with the number of distinct actors/media on the page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;

class BigSiteListingsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		do_action( 'rest_api_init' );
	}

	private function repo() {
		return Plugin::container()->get( 'media_repository' );
	}

	private function seed_media( int $author_id, string $privacy = 'public' ): int {
		$id = (int) $this->repo()->insert(
			array(
				'title'             => 'Big-site fixture ' . wp_generate_password( 8, false, false ),
				'post_author'       => $author_id,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => $privacy,
				'file_path'         => '2026/09/bigsite.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'bigsite-' . wp_generate_password( 10, false, false ),
			)
		);
		$this->assertGreaterThan( 0, $id, 'fixture media did not insert' );
		return $id;
	}

	// ------------------------------------------------------------------
	// 1. Album items route paginates, and the total matches only what
	//    this viewer may see.
	// ------------------------------------------------------------------

	public function test_album_items_route_paginates_and_total_matches_viewable_items(): void {
		$owner    = self::factory()->user->create( array( 'role' => 'author' ) );
		$stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$album_id = Plugin::container()->get( 'albums' )->create(
			$owner,
			array( 'title' => 'Big album', 'privacy' => 'public' )
		);
		$this->assertIsInt( $album_id );

		// 3 public items the stranger may see, 1 private item they may not.
		$public_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$public_ids[] = $this->seed_media( $owner, 'public' );
		}
		$private_id = $this->seed_media( $owner, 'private' );

		Plugin::container()->get( 'albums' )->add_items( $album_id, array_merge( $public_ids, array( $private_id ) ) );

		wp_set_current_user( $stranger );

		// Page 1 of 2, per_page=2: only the 3 viewable (public) items count
		// toward the total, the private one is excluded entirely.
		$req = new WP_REST_Request( 'GET', "/mvs/v1/albums/{$album_id}/items" );
		$req->set_param( 'per_page', 2 );
		$req->set_param( 'page', 1 );
		$page1 = rest_do_request( $req );

		$this->assertSame( 200, $page1->get_status() );
		$this->assertCount( 2, $page1->get_data(), 'page 1 should return exactly per_page items' );
		$this->assertSame( '3', $page1->headers['X-WP-Total'], 'total must count only viewer-visible items, not the private one' );
		$this->assertSame( '2', $page1->headers['X-WP-TotalPages'] );

		// Page 2 returns the remainder (1 item), not the private one, and no
		// overlap with page 1.
		$req2 = new WP_REST_Request( 'GET', "/mvs/v1/albums/{$album_id}/items" );
		$req2->set_param( 'per_page', 2 );
		$req2->set_param( 'page', 2 );
		$page2 = rest_do_request( $req2 );

		$this->assertCount( 1, $page2->get_data() );

		$page1_ids = wp_list_pluck( $page1->get_data(), 'id' );
		$page2_ids = wp_list_pluck( $page2->get_data(), 'id' );

		$this->assertEmpty( array_intersect( $page1_ids, $page2_ids ), 'pages must not overlap' );
		$this->assertNotContains( $private_id, array_merge( $page1_ids, $page2_ids ), 'private item must never appear to the stranger' );
	}

	/**
	 * GET /collections/{id}/items (new, 2.6.0) — manual collection, same
	 * pagination + privacy-total contract as the album route above. Before
	 * this route existed, a manual collection's detail response either
	 * dumped every favourite row (unbounded) or was capped at a flat 100 with
	 * no way to reach the rest.
	 */
	public function test_manual_collection_items_route_paginates_and_total_matches_viewable_items(): void {
		$owner    = self::factory()->user->create( array( 'role' => 'author' ) );
		$stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$collection_id = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_collection',
				'post_author' => $owner,
			)
		);

		$public_ids = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$public_ids[] = $this->seed_media( $owner, 'public' );
		}
		$private_id = $this->seed_media( $owner, 'private' );

		global $wpdb;
		foreach ( array_merge( $public_ids, array( $private_id ) ) as $media_id ) {
			$wpdb->insert(
				$wpdb->prefix . 'mvs_favorites',
				array(
					'media_id'      => $media_id,
					'user_id'       => $owner,
					'collection_id' => $collection_id,
					'created_at'    => current_time( 'mysql' ),
				)
			);
		}

		wp_set_current_user( $stranger );

		$req = new WP_REST_Request( 'GET', "/mvs/v1/collections/{$collection_id}/items" );
		$req->set_param( 'per_page', 2 );
		$req->set_param( 'page', 1 );
		$page1 = rest_do_request( $req );

		$this->assertSame( 200, $page1->get_status() );
		$this->assertCount( 2, $page1->get_data() );
		$this->assertSame( '3', $page1->headers['X-WP-Total'], 'total must count only viewer-visible favourites, not the private one' );

		$req2 = new WP_REST_Request( 'GET', "/mvs/v1/collections/{$collection_id}/items" );
		$req2->set_param( 'per_page', 2 );
		$req2->set_param( 'page', 2 );
		$page2 = rest_do_request( $req2 );

		$this->assertCount( 1, $page2->get_data() );

		$ids = array_merge( wp_list_pluck( $page1->get_data(), 'id' ), wp_list_pluck( $page2->get_data(), 'id' ) );
		$this->assertNotContains( $private_id, $ids, 'private favourite must never appear to the stranger' );
	}

	// ------------------------------------------------------------------
	// 2. Tag cloud: second call within the cache window costs zero queries.
	// ------------------------------------------------------------------

	public function test_tag_cloud_second_call_hits_cache(): void {
		$author = self::factory()->user->create();
		$media  = $this->seed_media( $author, 'public' );
		wp_set_object_terms( $media, array( 'bigsite-tag' ), 'mvs_tag' );
		$this->repo()->set( $media, 'tags', wp_json_encode( array( 'bigsite-tag' ) ) );

		$cache = Plugin::container()->get( 'cache' );

		global $wpdb;

		$before_cold = $wpdb->num_queries;
		$cold        = $cache->tag_cloud( 20 );
		$cold_queries = $wpdb->num_queries - $before_cold;

		$this->assertGreaterThan( 0, $cold_queries, 'first call should hit the database' );
		$this->assertNotEmpty( $cold, 'fixture: tag cloud should contain the seeded tag' );

		$before_warm = $wpdb->num_queries;
		$warm        = $cache->tag_cloud( 20 );
		$warm_queries = $wpdb->num_queries - $before_warm;

		$this->assertSame( 0, $warm_queries, 'second call within the cache window must not touch the database' );
		$this->assertEquals( $cold, $warm, 'cached value must match the freshly computed one' );
	}

	// ------------------------------------------------------------------
	// 3. Activity feed: batch-prefetches actors + media; query count does
	//    not grow with the number of distinct actors/media on the page.
	// ------------------------------------------------------------------

	private function record_activity( int $actor_id, int $media_id ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'mvs_activity',
			array(
				'user_id'    => $actor_id,
				'type'       => 'media_upload',
				'media_id'   => $media_id,
				'album_id'   => 0,
				'content'    => '',
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);
	}

	public function test_activity_feed_does_not_run_per_row_actor_or_media_queries(): void {
		$activities = Plugin::container()->get( 'activity' );

		// Baseline: a single activity from a single (uncached) actor/media pair.
		$solo_actor = self::factory()->user->create();
		$solo_media = $this->seed_media( $solo_actor );
		$this->record_activity( $solo_actor, $solo_media );

		global $wpdb;
		$before    = $wpdb->num_queries;
		$activities->get_feed( array( 'scope' => 'public', 'per_page' => 1, 'page' => 1 ) );
		$baseline_queries = $wpdb->num_queries - $before;

		// 7 MORE activities, each from a distinct, never-before-seen actor and
		// media item — so nothing is warm from a prior lookup.
		for ( $i = 0; $i < 7; $i++ ) {
			$actor = self::factory()->user->create();
			$media = $this->seed_media( $actor );
			$this->record_activity( $actor, $media );
		}

		$before = $wpdb->num_queries;
		$activities->get_feed( array( 'scope' => 'public', 'per_page' => 10, 'page' => 1 ) );
		$eight_row_queries = $wpdb->num_queries - $before;

		// Without the batch prefetch, 7 more distinct actors + 7 more distinct
		// media rows cost their own query each inside format_activity() - at
		// least 14 more. The fix buys that back to a small, mostly flat
		// constant (COUNT + SELECT + one WP_User_Query + prefetch's 2
		// queries), regardless of how many distinct actors/media are on the page.
		$this->assertLessThan(
			$baseline_queries + 8,
			$eight_row_queries,
			'activity feed query count scaled with row count - per-row actor/media queries leaked back in'
		);
	}
}
