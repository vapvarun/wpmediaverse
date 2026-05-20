<?php
/**
 * Regression guards for folding existing helpers onto the query engine.
 *
 * These assert the public helpers (query_by_author, count_by_author,
 * count_published, query_recent) return EXACTLY what their original
 * hand-written SQL returned. They are green against the pre-refactor
 * implementations and must stay green after each helper delegates to
 * query()/query_count() — proving the fold-in changed no behavior.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MediaRepositoryFoldInTest extends WP_UnitTestCase {

	/** @var int */
	private int $author_a;

	/** @var int */
	private int $author_b;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
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

	private function ids( array $rows ): array {
		return array_map(
			static function ( $row ) {
				return (int) $row['media_id'];
			},
			$rows
		);
	}

	/**
	 * Seed a mixed fixture set for both authors and varied status/moderation.
	 */
	private function seed_mixed(): void {
		$this->make_media( array( 'post_author' => $this->author_a, 'status' => 'publish', 'moderation_status' => 'approved' ) );
		$this->make_media( array( 'post_author' => $this->author_a, 'status' => 'publish', 'moderation_status' => 'pending' ) );
		$this->make_media( array( 'post_author' => $this->author_a, 'status' => 'draft', 'moderation_status' => 'approved' ) );
		$this->make_media( array( 'post_author' => $this->author_b, 'status' => 'publish', 'moderation_status' => 'approved' ) );
		$this->make_media( array( 'post_author' => $this->author_b, 'status' => 'publish', 'moderation_status' => 'approved' ) );
	}

	public function test_query_by_author_matches_legacy_sql(): void {
		global $wpdb;
		$this->seed_mixed();
		$index = $wpdb->prefix . 'mvs_media_index';

		// Legacy: post_author + status + moderation, created_at DESC, paginated.
		// phpcs:disable WordPress.DB
		$legacy = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$index} WHERE post_author = %d AND status = %s AND moderation_status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$this->author_a,
				'publish',
				'approved',
				20,
				0
			),
			ARRAY_A
		);
		// phpcs:enable

		$folded = $this->repo()->query_by_author(
			$this->author_a,
			array(
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'limit'             => 20,
				'offset'            => 0,
			)
		);

		$this->assertSame( $this->ids( $legacy ), $this->ids( $folded ) );
		$this->assertNotEmpty( $folded );
	}

	public function test_query_by_author_skips_moderation_when_blank(): void {
		global $wpdb;
		$this->seed_mixed();
		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB
		$legacy = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$index} WHERE post_author = %d AND status = %s ORDER BY created_at DESC LIMIT %d OFFSET %d",
				$this->author_a,
				'publish',
				20,
				0
			),
			ARRAY_A
		);
		// phpcs:enable

		$folded = $this->repo()->query_by_author( $this->author_a );

		$this->assertSame( $this->ids( $legacy ), $this->ids( $folded ) );
	}

	public function test_count_by_author_matches_legacy_sql(): void {
		global $wpdb;
		$this->seed_mixed();
		$index = $wpdb->prefix . 'mvs_media_index';

		// With moderation filter.
		// phpcs:disable WordPress.DB
		$legacy_mod = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} WHERE post_author = %d AND status = %s AND moderation_status = %s",
				$this->author_a,
				'publish',
				'approved'
			)
		);
		// Without moderation filter.
		$legacy_nomod = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$index} WHERE post_author = %d AND status = %s",
				$this->author_a,
				'publish'
			)
		);
		// phpcs:enable

		$this->assertSame( $legacy_mod, $this->repo()->count_by_author( $this->author_a, 'publish', 'approved' ) );
		$this->assertSame( $legacy_nomod, $this->repo()->count_by_author( $this->author_a, 'publish' ) );
	}

	public function test_count_published_matches_legacy_sql(): void {
		global $wpdb;
		$this->seed_mixed();
		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB
		$legacy = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$index} WHERE status = %s", 'publish' )
		);
		// phpcs:enable

		$this->assertSame( $legacy, $this->repo()->count_published() );
	}

	public function test_query_recent_matches_legacy_sql(): void {
		global $wpdb;
		$this->seed_mixed();
		$index = $wpdb->prefix . 'mvs_media_index';

		// No since.
		// phpcs:disable WordPress.DB
		$legacy = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$index} WHERE status = 'publish' ORDER BY created_at DESC LIMIT %d", 5 ),
			ARRAY_A
		);
		// phpcs:enable

		$this->assertSame( $this->ids( $legacy ), $this->ids( $this->repo()->query_recent( 5 ) ) );
	}

	public function test_query_recent_with_since_matches_legacy_sql(): void {
		global $wpdb;
		$this->seed_mixed();
		$index = $wpdb->prefix . 'mvs_media_index';
		$since = '2000-01-01 00:00:00';

		// phpcs:disable WordPress.DB
		$legacy = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$index} WHERE status = 'publish' AND created_at >= %s ORDER BY created_at DESC LIMIT %d",
				$since,
				50
			),
			ARRAY_A
		);
		// phpcs:enable

		$this->assertSame( $this->ids( $legacy ), $this->ids( $this->repo()->query_recent( 50, $since ) ) );
	}
}
