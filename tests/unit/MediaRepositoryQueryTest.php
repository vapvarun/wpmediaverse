<?php
/**
 * Acceptance tests for MediaRepository::query() / query_count() — the TIER-D
 * index-listing query engine.
 *
 * Written red-first (Part 13): every test here fails until the engine and its
 * two public methods land. Covers each filter, the privacy tri-state, the
 * gallery-exclude subquery, the orderby/order allowlist (SQL-injection guard),
 * count/list parity, and behavioral equivalence with the legacy explore SQL.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MediaRepositoryQueryTest extends WP_UnitTestCase {

	/** @var int */
	private int $author_a;

	/** @var int */
	private int $author_b;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		// Ensure mvs tables exist (mirror MediaRepositoryTest set_up).
		$index_table = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index_table ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->author_a = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->author_b = self::factory()->user->create( array( 'role' => 'author' ) );
	}

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	/**
	 * Insert a media row and return its id.
	 *
	 * @param array $overrides Column overrides.
	 * @return int
	 */
	private function make_media( array $overrides = array() ): int {
		$defaults = array(
			'title'             => 'Item ' . wp_generate_password( 6, false ),
			'post_author'       => $this->author_a,
			'media_type'        => 'image',
			'status'            => 'publish',
			'moderation_status' => 'approved',
			'privacy'           => 'public',
		);

		return (int) $this->repo()->insert( array_merge( $defaults, $overrides ) );
	}

	/**
	 * Pluck media_id list from a query() result.
	 *
	 * @param array $rows query() result.
	 * @return array<int>
	 */
	private function ids( array $rows ): array {
		return array_map(
			static function ( $row ) {
				return (int) $row['media_id'];
			},
			$rows
		);
	}

	/*
	------------------------------------------------------------------
	 * Status / moderation / author filters
	 * ----------------------------------------------------------------*/

	public function test_query_defaults_to_published(): void {
		$pub   = $this->make_media();
		$draft = $this->make_media( array( 'status' => 'draft' ) );

		$ids = $this->ids( $this->repo()->query() );

		$this->assertContains( $pub, $ids );
		$this->assertNotContains( $draft, $ids );
	}

	public function test_query_status_filter(): void {
		$pub   = $this->make_media();
		$draft = $this->make_media( array( 'status' => 'draft' ) );

		$ids = $this->ids( $this->repo()->query( array( 'status' => 'draft' ) ) );

		$this->assertSame( array( $draft ), array_values( array_intersect( $ids, array( $pub, $draft ) ) ) );
	}

	public function test_query_moderation_status_filter(): void {
		$approved = $this->make_media();
		$pending  = $this->make_media( array( 'moderation_status' => 'pending' ) );

		$ids = $this->ids( $this->repo()->query( array( 'moderation_status' => 'approved' ) ) );

		$this->assertContains( $approved, $ids );
		$this->assertNotContains( $pending, $ids );
	}

	public function test_query_author_filter(): void {
		$a = $this->make_media( array( 'post_author' => $this->author_a ) );
		$b = $this->make_media( array( 'post_author' => $this->author_b ) );

		$ids = $this->ids( $this->repo()->query( array( 'author_id' => $this->author_b ) ) );

		$this->assertContains( $b, $ids );
		$this->assertNotContains( $a, $ids );
	}

	public function test_query_search_matches_title_or_description(): void {
		$by_title = $this->make_media( array( 'title' => 'Aurora over the fjord' ) );
		$by_desc  = $this->make_media(
			array(
				'title'       => 'Untitled',
				'description' => 'A faint aurora glow.',
			)
		);
		$miss     = $this->make_media( array( 'title' => 'Desert dunes' ) );

		$ids = $this->ids( $this->repo()->query( array( 'search' => 'aurora' ) ) );

		$this->assertContains( $by_title, $ids );
		$this->assertContains( $by_desc, $ids );
		$this->assertNotContains( $miss, $ids );
	}

	/*
	------------------------------------------------------------------
	 * Privacy tri-state
	 * ----------------------------------------------------------------*/

	public function test_query_privacy_public_only(): void {
		$public  = $this->make_media( array( 'privacy' => 'public' ) );
		$members = $this->make_media( array( 'privacy' => 'members' ) );
		$private = $this->make_media( array( 'privacy' => 'private' ) );

		$ids = $this->ids( $this->repo()->query( array( 'privacy' => 'public' ) ) );

		$this->assertContains( $public, $ids );
		$this->assertNotContains( $members, $ids );
		$this->assertNotContains( $private, $ids );
	}

	public function test_query_privacy_visible_includes_members_and_own_private(): void {
		$public        = $this->make_media( array( 'privacy' => 'public' ) );
		$members       = $this->make_media( array( 'privacy' => 'members' ) );
		$own_private   = $this->make_media(
			array(
				'privacy'     => 'private',
				'post_author' => $this->author_b,
			)
		);
		$other_private = $this->make_media(
			array(
				'privacy'     => 'private',
				'post_author' => $this->author_a,
			)
		);

		$ids = $this->ids(
			$this->repo()->query(
				array(
					'privacy'   => 'visible',
					'viewer_id' => $this->author_b,
				)
			)
		);

		$this->assertContains( $public, $ids );
		$this->assertContains( $members, $ids );
		$this->assertContains( $own_private, $ids );
		$this->assertNotContains( $other_private, $ids );
	}

	public function test_query_privacy_any_returns_all(): void {
		$public  = $this->make_media( array( 'privacy' => 'public' ) );
		$private = $this->make_media( array( 'privacy' => 'private' ) );

		$ids = $this->ids( $this->repo()->query( array( 'privacy' => 'any' ) ) );

		$this->assertContains( $public, $ids );
		$this->assertContains( $private, $ids );
	}

	/*
	------------------------------------------------------------------
	 * Gallery-exclude subquery
	 * ----------------------------------------------------------------*/

	public function test_query_excludes_non_cover_group_members(): void {
		$group = 'grp_' . wp_generate_password( 8, false );

		$cover  = $this->make_media();
		$member = $this->make_media();

		// Cover = group_position 0; member = position 1. Both share media_group.
		$this->repo()->set( $cover, 'media_group', $group );
		$this->repo()->set( $cover, 'group_position', '0' );
		$this->repo()->set( $member, 'media_group', $group );
		$this->repo()->set( $member, 'group_position', '1' );

		$ids = $this->ids( $this->repo()->query( array( 'exclude_non_cover_group' => true ) ) );

		$this->assertContains( $cover, $ids, 'Gallery cover (position 0) must remain.' );
		$this->assertNotContains( $member, $ids, 'Non-cover gallery member must be excluded.' );
	}

	/*
	------------------------------------------------------------------
	 * Taxonomy joins
	 * ----------------------------------------------------------------*/

	public function test_query_tag_filter_by_term_taxonomy_id(): void {
		$tagged   = $this->make_media();
		$untagged = $this->make_media();

		$term = wp_insert_term( 'Landscape ' . wp_generate_password( 5, false ), 'mvs_tag' );
		$this->assertIsArray( $term );
		wp_set_object_terms( $tagged, array( (int) $term['term_id'] ), 'mvs_tag' );

		$ids = $this->ids( $this->repo()->query( array( 'tag_tt_id' => (int) $term['term_taxonomy_id'] ) ) );

		$this->assertContains( $tagged, $ids );
		$this->assertNotContains( $untagged, $ids );
	}

	public function test_query_category_filter_by_term_taxonomy_id(): void {
		$in_cat  = $this->make_media();
		$not_cat = $this->make_media();

		$term = wp_insert_term( 'Nature ' . wp_generate_password( 5, false ), 'mvs_category' );
		$this->assertIsArray( $term );
		wp_set_object_terms( $in_cat, array( (int) $term['term_id'] ), 'mvs_category' );

		$ids = $this->ids( $this->repo()->query( array( 'category_tt_id' => (int) $term['term_taxonomy_id'] ) ) );

		$this->assertContains( $in_cat, $ids );
		$this->assertNotContains( $not_cat, $ids );
	}

	/*
	------------------------------------------------------------------
	 * Pagination + ordering + injection guard
	 * ----------------------------------------------------------------*/

	public function test_query_limit_and_offset(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->make_media();
		}

		$page1 = $this->repo()->query(
			array(
				'limit'  => 2,
				'offset' => 0,
			)
		);
		$page2 = $this->repo()->query(
			array(
				'limit'  => 2,
				'offset' => 2,
			)
		);

		$this->assertCount( 2, $page1 );
		$this->assertCount( 2, $page2 );
		$this->assertEmpty( array_intersect( $this->ids( $page1 ), $this->ids( $page2 ) ) );
	}

	public function test_query_order_asc_vs_desc(): void {
		$first  = $this->make_media();
		$second = $this->make_media();

		$desc = $this->ids(
			$this->repo()->query(
				array(
					'order' => 'DESC',
					'limit' => 50,
				)
			)
		);
		$asc  = $this->ids(
			$this->repo()->query(
				array(
					'order' => 'ASC',
					'limit' => 50,
				)
			)
		);

		// In DESC the later id comes before the earlier; ASC is the reverse.
		$this->assertGreaterThan( array_search( $second, $desc, true ), array_search( $first, $desc, true ) );
		$this->assertLessThan( array_search( $second, $asc, true ), array_search( $first, $asc, true ) );
	}

	public function test_query_orderby_allowlist_rejects_injection(): void {
		$a = $this->make_media();
		$b = $this->make_media();

		// Malicious orderby must be ignored (fall back to created_at), NOT executed.
		$evil = $this->repo()->query(
			array(
				'orderby' => 'created_at; DROP TABLE wp_mvs_media_index; --',
				'limit'   => 50,
			)
		);
		$safe = $this->repo()->query(
			array(
				'orderby' => 'created_at',
				'limit'   => 50,
			)
		);

		// No fatal, table intact, and identical ordering to the safe default.
		$this->assertSame( $this->ids( $safe ), $this->ids( $evil ) );
		$this->assertContains( $a, $this->ids( $evil ) );
		$this->assertContains( $b, $this->ids( $evil ) );
	}

	public function test_query_order_allowlist_rejects_bad_direction(): void {
		$this->make_media();

		$bad = $this->repo()->query(
			array(
				'order' => 'DESC; DROP',
				'limit' => 10,
			)
		);
		$ok  = $this->repo()->query(
			array(
				'order' => 'DESC',
				'limit' => 10,
			)
		);

		$this->assertSame( $this->ids( $ok ), $this->ids( $bad ) );
	}

	/*
	------------------------------------------------------------------
	 * Count / list parity
	 * ----------------------------------------------------------------*/

	public function test_query_count_matches_list_size(): void {
		$this->make_media( array( 'post_author' => $this->author_b ) );
		$this->make_media( array( 'post_author' => $this->author_b ) );
		$this->make_media( array( 'post_author' => $this->author_a ) );

		$args  = array( 'author_id' => $this->author_b );
		$count = $this->repo()->query_count( $args );
		$rows  = $this->repo()->query( array_merge( $args, array( 'limit' => 1000 ) ) );

		$this->assertSame( count( $rows ), $count );
		$this->assertSame( 2, $count );
	}

	public function test_query_count_distinct_with_tag_join(): void {
		$media = $this->make_media();

		// Two terms on the same media → without DISTINCT a join would double-count.
		$t1 = wp_insert_term( 'TagA ' . wp_generate_password( 4, false ), 'mvs_tag' );
		$t2 = wp_insert_term( 'TagB ' . wp_generate_password( 4, false ), 'mvs_tag' );
		wp_set_object_terms( $media, array( (int) $t1['term_id'], (int) $t2['term_id'] ), 'mvs_tag' );

		// Filter on one tag — should count the media exactly once.
		$count = $this->repo()->query_count( array( 'tag_tt_id' => (int) $t1['term_taxonomy_id'] ) );

		$this->assertSame( 1, $count );
	}

	/*
	------------------------------------------------------------------
	 * Behavioral equivalence with the legacy explore (anon) SQL
	 * ----------------------------------------------------------------*/

	public function test_query_equivalent_to_legacy_explore_anon_sql(): void {
		global $wpdb;
		$index = $wpdb->prefix . 'mvs_media_index';
		$meta  = $wpdb->prefix . 'mvs_media_meta';

		// Mixed fixtures: public/members/private, approved/pending, a gallery.
		$this->make_media( array( 'privacy' => 'public' ) );
		$this->make_media( array( 'privacy' => 'members' ) );
		$this->make_media(
			array(
				'privacy'           => 'public',
				'moderation_status' => 'pending',
			)
		);

		$group  = 'grp_' . wp_generate_password( 8, false );
		$cover  = $this->make_media();
		$member = $this->make_media();
		$this->repo()->set( $cover, 'media_group', $group );
		$this->repo()->set( $cover, 'group_position', '0' );
		$this->repo()->set( $member, 'media_group', $group );
		$this->repo()->set( $member, 'group_position', '1' );

		// Legacy explore-anon SQL, verbatim shape from templates/explore.php.
		// phpcs:disable WordPress.DB
		$legacy = $wpdb->get_results(
			"SELECT m.* FROM {$index} m
			WHERE m.status = 'publish' AND m.moderation_status = 'approved'
			AND m.privacy = 'public'
			AND m.media_id NOT IN (
				SELECT mm1.media_id FROM {$meta} mm1
				INNER JOIN {$meta} mm2 ON mm1.media_id = mm2.media_id
				WHERE mm1.meta_key = 'media_group'
				AND mm2.meta_key = 'group_position'
				AND mm2.meta_value != '0'
			)
			ORDER BY m.created_at DESC LIMIT 1000 OFFSET 0",
			ARRAY_A
		);
		// phpcs:enable

		$engine = $this->repo()->query(
			array(
				'status'                  => 'publish',
				'moderation_status'       => 'approved',
				'privacy'                 => 'public',
				'exclude_non_cover_group' => true,
				'limit'                   => 1000,
			)
		);

		$this->assertSame( $this->ids( $legacy ), $this->ids( $engine ) );
	}
}
