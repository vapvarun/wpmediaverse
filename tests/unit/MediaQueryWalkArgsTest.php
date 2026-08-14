<?php
/**
 * The maintenance-walk arguments on query() / query_count().
 *
 * These four args exist so the CLI and the storage services can stop writing
 * their own `SELECT ... FROM mvs_media_index WHERE ...` (architecture invariant
 * 6 / coding Rule 7). Eleven call sites were the same walk with different
 * columns; the point of moving them here is that they stop being eleven chances
 * to get the predicate subtly wrong.
 *
 * Which makes THIS file the thing standing behind eleven commands that write to
 * the rows they walk. A wrong predicate here does not throw — it quietly does
 * the work to the wrong set, and `migrate-storage` moving the wrong files is not
 * something a customer discovers the same day.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class MediaQueryWalkArgsTest extends WP_UnitTestCase {

	/**
	 * Author for the fixtures.
	 *
	 * @var int
	 */
	private int $author;

	public function set_up(): void {
		parent::set_up();

		global $wpdb;

		$index = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ) ) {
			( new \WPMediaVerse\Core\Migrator() )->run();
		}

		$this->author = self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Repository.
	 *
	 * @return \WPMediaVerse\Repository\MediaRepository
	 */
	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	/**
	 * Insert a media row.
	 *
	 * @param array $overrides Column overrides.
	 * @return int
	 */
	private function make( array $overrides = array() ): int {
		return (int) $this->repo()->insert(
			array_merge(
				array(
					'title'             => 'Walk ' . wp_generate_password( 6, false ),
					'post_author'       => $this->author,
					'media_type'        => 'image',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => 'public',
					'file_path'         => '2026/08/file.jpg',
				),
				$overrides
			)
		);
	}

	/**
	 * Ids from a query() result.
	 *
	 * @param array $rows Rows.
	 * @return int[]
	 */
	private function ids( array $rows ): array {
		return array_map( static fn( $r ) => (int) $r['media_id'], $rows );
	}

	// ------------------------------------------------------------- id_after --

	/**
	 * `id_after` is a cursor: strictly greater, never equal.
	 *
	 * Off-by-one here re-processes the last row of every batch. For an idempotent
	 * command that is waste; for `optimize-bulk`, which re-encodes an image, it
	 * is a second lossy pass over one file per batch.
	 */
	public function test_id_after_is_exclusive(): void {
		$a = $this->make();
		$b = $this->make();

		$ids = $this->ids( $this->repo()->query( array( 'id_after' => $a, 'orderby' => 'media_id', 'order' => 'ASC' ) ) );

		$this->assertNotContains( $a, $ids, 'The cursor row must not be returned again.' );
		$this->assertContains( $b, $ids );
	}

	/**
	 * A keyset walk visits every row exactly once.
	 *
	 * The property the whole cursor exists for, asserted end to end rather than
	 * one batch at a time — a walk can be correct per batch and still drop or
	 * repeat rows across batches.
	 */
	public function test_a_keyset_walk_covers_every_row_exactly_once(): void {
		$expected = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$expected[] = $this->make();
		}
		sort( $expected );

		$seen = array();
		$last = 0;

		do {
			$rows = $this->repo()->query(
				array(
					'id_after'    => $last,
					'media_ids'   => $expected,
					'orderby'     => 'media_id',
					'order'       => 'ASC',
					'limit'       => 2,
					'media_types' => \WPMediaVerse\Core\MediaTypes::ALL,
				)
			);

			foreach ( $rows as $row ) {
				$seen[] = (int) $row['media_id'];
				$last   = (int) $row['media_id'];
			}
		} while ( ! empty( $rows ) );

		$this->assertSame( $expected, $seen, 'A keyset walk must visit each row once, in order.' );
		$this->assertSame( count( $seen ), count( array_unique( $seen ) ), 'No row may be visited twice.' );
	}

	// ------------------------------------------------------------ status_in --

	/**
	 * `status_in` REPLACES the default status rather than ANDing with it.
	 *
	 * The bug this is written against: `status` defaults to `publish`, so a naive
	 * addition would produce `status = 'publish' AND ( status = 'publish' OR
	 * status = 'draft' )`. Every draft silently vanishes, the command reports
	 * success, and half the table was never touched. Nothing errors.
	 */
	public function test_status_in_replaces_the_default_status(): void {
		$pub   = $this->make();
		$draft = $this->make( array( 'status' => 'draft' ) );

		$ids = $this->ids(
			$this->repo()->query( array( 'status_in' => array( 'publish', 'draft' ), 'media_ids' => array( $pub, $draft ) ) )
		);

		$this->assertContains( $pub, $ids );
		$this->assertContains( $draft, $ids, 'status_in was ANDed with the default instead of replacing it.' );
	}

	/**
	 * And an explicit `status` still wins when no set is given.
	 */
	public function test_status_still_works_without_a_set(): void {
		$pub   = $this->make();
		$draft = $this->make( array( 'status' => 'draft' ) );

		$ids = $this->ids( $this->repo()->query( array( 'status' => 'draft', 'media_ids' => array( $pub, $draft ) ) ) );

		$this->assertSame( array( $draft ), $ids );
	}

	// ------------------------------------------------------------- has_file --

	/**
	 * `has_file` treats NULL and the empty string alike.
	 *
	 * Both occur — the column is nullable and legacy rows carry ''. Testing only
	 * NULL hands a storage command rows with nothing to move, which it then
	 * counts as migrated.
	 */
	public function test_has_file_excludes_null_and_empty_alike(): void {
		$with  = $this->make();
		$null  = $this->make( array( 'file_path' => null ) );
		$empty = $this->make( array( 'file_path' => '' ) );

		$ids = $this->ids(
			$this->repo()->query( array( 'has_file' => true, 'media_ids' => array( $with, $null, $empty ) ) )
		);

		$this->assertSame( array( $with ), $ids );
	}

	/**
	 * And inverts cleanly — index rows whose file is gone.
	 */
	public function test_has_file_false_finds_rows_with_no_file(): void {
		$with  = $this->make();
		$empty = $this->make( array( 'file_path' => '' ) );

		$ids = $this->ids(
			$this->repo()->query( array( 'has_file' => false, 'media_ids' => array( $with, $empty ) ) )
		);

		$this->assertSame( array( $empty ), $ids );
	}

	/**
	 * Absent means absent: no predicate, every row.
	 *
	 * `null` and `false` must not collapse into each other — if they did, every
	 * caller that omits the arg would silently get the "no file" set.
	 */
	public function test_omitting_has_file_filters_nothing(): void {
		$with  = $this->make();
		$empty = $this->make( array( 'file_path' => '' ) );

		$ids = $this->ids( $this->repo()->query( array( 'media_ids' => array( $with, $empty ) ) ) );

		sort( $ids );
		$expected = array( $with, $empty );
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}

	// ------------------------------------------------------------ media_ids --

	/**
	 * `media_ids` restricts to the given set.
	 */
	public function test_media_ids_restricts_to_the_set(): void {
		$a = $this->make();
		$b = $this->make();
		$this->make();

		$ids = $this->ids( $this->repo()->query( array( 'media_ids' => array( $a, $b ) ) ) );

		sort( $ids );
		$expected = array( $a, $b );
		sort( $expected );

		$this->assertSame( $expected, $ids );
	}

	/**
	 * An EMPTY set is not a filter, and this is the dangerous direction.
	 *
	 * `media_ids => array()` has to mean "no restriction", matching every other
	 * list arg on this builder — but a caller that computed an empty list and
	 * expected zero rows would instead act on the whole table. The behaviour is
	 * pinned here so the choice is explicit rather than emergent: callers with a
	 * possibly-empty id list must check it themselves, and the ones migrated in
	 * this pass do.
	 */
	public function test_an_empty_media_ids_set_is_not_a_filter(): void {
		$a = $this->make();

		$ids = $this->ids( $this->repo()->query( array( 'media_ids' => array() ) ) );

		$this->assertContains( $a, $ids );
	}

	// ----------------------------------------------------------- media_types --

	/**
	 * `media_types => null` drops the type predicate; `array()` still matches nothing.
	 *
	 * The distinction is the whole point. `wp mvs reindex` exists to find rows
	 * that are WRONG, and a row with a corrupt or empty `media_type` is the most
	 * obvious kind — a positive IN-list would hide exactly the rows the command
	 * was run to find, and it would report a clean table. Meanwhile an empty
	 * array must keep failing closed, because that is the omission bug that put
	 * documents in photo grids.
	 */
	public function test_null_media_types_sees_rows_an_in_list_would_hide(): void {
		$normal = $this->make();
		$broken = $this->make( array( 'media_type' => '' ) );

		$all = $this->ids( $this->repo()->query( array( 'media_types' => null, 'media_ids' => array( $normal, $broken ) ) ) );
		sort( $all );
		$expected = array( $normal, $broken );
		sort( $expected );
		$this->assertSame( $expected, $all, 'A whole-table walk must see the malformed row.' );

		$listed = $this->ids( $this->repo()->query( array( 'media_ids' => array( $normal, $broken ) ) ) );
		$this->assertSame( array( $normal ), $listed, 'A normal listing must still not see it.' );

		$this->assertSame(
			array(),
			$this->ids( $this->repo()->query( array( 'media_types' => array(), 'media_ids' => array( $normal, $broken ) ) ) ),
			'An empty type set must still match nothing — that is the fail-closed direction.'
		);
	}

	/**
	 * `MediaTypes::ALL` does NOT mean every stored type, and a storage walk must not use it.
	 *
	 * THE BUG THIS PASS ALMOST SHIPPED. Migrating the storage commands off raw
	 * SQL, `MediaTypes::ALL` looked like the faithful way to write "no type
	 * filter". It is not: it lists image, video, audio and document, and the
	 * table also stores `legacy_document` — on real rows, with real files. The
	 * live data had exactly one, which is how it was caught, by diffing the new
	 * counts against the old SQL rather than by any test.
	 *
	 * What it would have cost: `relocalize-private` walks non-public media to
	 * pull it back off a public bucket. Skipping legacy documents there leaves a
	 * private file readable on a CDN, with the command reporting success.
	 *
	 * So this asserts the property that matters — a storage walk sees a
	 * `legacy_document` row — rather than the constant's contents, which are
	 * free to change.
	 */
	public function test_a_storage_walk_sees_legacy_document_rows(): void {
		$normal = $this->make();
		$legacy = $this->make( array( 'media_type' => \WPMediaVerse\Core\MediaTypes::LEGACY_DOCUMENT ) );

		$storage_args = array(
			'status'      => '',
			'status_in'   => array( 'publish', 'draft' ),
			'has_file'    => true,
			'media_types' => null,
			'media_ids'   => array( $normal, $legacy ),
		);

		$this->assertContains(
			$legacy,
			$this->ids( $this->repo()->query( $storage_args ) ),
			'A storage walk must see legacy_document rows — their files live on the same drivers.'
		);

		$this->assertNotContains(
			$legacy,
			$this->ids( $this->repo()->query( array_merge( $storage_args, array( 'media_types' => \WPMediaVerse\Core\MediaTypes::ALL ) ) ) ),
			'This is the trap: MediaTypes::ALL excludes legacy_document. If this ever starts passing, ALL has changed meaning and the null above may no longer be needed — check before removing it.'
		);
	}

	// --------------------------------------------------------------- counts --

	/**
	 * query_count() honours the same args as query().
	 *
	 * They share `build_query_parts()`, so a new arg reaching one and not the
	 * other would make a command's "found N" line disagree with the rows it
	 * then processes — the failure looks like a progress-bar bug and is really
	 * two different queries.
	 */
	public function test_count_and_list_agree_on_the_new_args(): void {
		$this->make();
		$this->make( array( 'status' => 'draft' ) );
		$this->make( array( 'file_path' => '' ) );

		$args = array(
			'status_in'   => array( 'publish', 'draft' ),
			'has_file'    => true,
			'media_types' => \WPMediaVerse\Core\MediaTypes::ALL,
			'limit'       => 100,
		);

		$this->assertSame(
			$this->repo()->query_count( $args ),
			count( $this->repo()->query( $args ) ),
			'query_count() and query() must describe the same set.'
		);
	}

	/**
	 * Every new arg is bound, not interpolated.
	 *
	 * These args are reached from WP-CLI flags, so a value arrives from a shell.
	 * A string where an int is expected must narrow the result set or be
	 * ignored — never reach the statement.
	 */
	public function test_hostile_values_do_not_reach_the_statement(): void {
		$a = $this->make();

		$rows = $this->repo()->query(
			array(
				'id_after'  => "0 OR 1=1; DROP TABLE {$GLOBALS['wpdb']->prefix}mvs_media_index; --",
				'media_ids' => array( "{$a}) OR (1=1", $a ),
				'status_in' => array( "publish' OR '1'='1" ),
			)
		);

		$this->assertIsArray( $rows );

		global $wpdb;
		$index = $wpdb->prefix . 'mvs_media_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertNotEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) ), 'The table is still there.' );
	}
}
