<?php
/**
 * Regression tests for album/collection IDs colliding with media IDs.
 *
 * mvs_media_index.media_id is AUTO_INCREMENT for real media, but albums write
 * their own wp_posts ID into that same primary key. Where the integers coincide,
 * permanently deleting the CPT used to purge the media item's index record — the
 * file survived on disk and the item vanished from every surface.
 *
 * Basecamp 10183850886. Plan: plan/2026-08-08-cpt-id-collision-fix-plan.md
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class CptIdCollisionTest extends WP_UnitTestCase {

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
	 * Force an index row to carry a specific media_id so the collision can be
	 * reproduced deterministically, mirroring what an explicit-ID write does.
	 *
	 * @param int    $media_id Row id to occupy.
	 * @param int    $author   Owning user.
	 * @param string $title    Media title.
	 * @return void
	 */
	private function seed_media_row_at( int $media_id, int $author, string $title ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_media_index',
			array(
				'media_id'    => $media_id,
				'title'       => $title,
				'slug'        => 'seeded-' . $media_id,
				'post_author' => $author,
				'status'      => 'publish',
				'media_type'  => 'image',
				'privacy'     => 'public',
				'file_path'   => '2026/08/seeded-' . $media_id . '.jpg',
			)
		);
	}

	/**
	 * The headline defect: deleting an album must not destroy a media row that
	 * happens to share its ID.
	 */
	public function test_deleting_album_does_not_destroy_colliding_media_row(): void {
		$album_author = self::factory()->user->create();
		$media_author = self::factory()->user->create();

		$album_id = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_album',
				'post_title'  => 'Holiday 2026',
				'post_author' => $album_author,
			)
		);

		// A real media item that happens to occupy the same integer.
		$this->seed_media_row_at( $album_id, $media_author, 'City Skyline at Dusk' );
		$this->assertTrue( $this->repo->exists( $album_id ), 'Fixture failed: media row was not seeded.' );

		wp_delete_post( $album_id, true );

		$this->assertTrue(
			$this->repo->exists( $album_id ),
			'Deleting the album destroyed a real media row that shared its ID.'
		);
		$this->assertSame(
			'City Skyline at Dusk',
			(string) $this->repo->get( $album_id, 'title' ),
			'The surviving row is no longer the media item.'
		);
		$this->assertSame(
			$media_author,
			(int) $this->repo->get_author( $album_id ),
			'The surviving row lost its owner.'
		);
	}

	/**
	 * The delete path must not touch mvs_media_index at all.
	 *
	 * Before 2.4.0 an album stored its privacy at media_id = <album post ID> and
	 * purged that row on delete. Both halves are gone: albums write privacy to post
	 * meta, so there is no row to purge, and the purge itself was what destroyed
	 * colliding media. Legacy rows from older installs are cleared once by
	 * Migrator v26, not on every delete.
	 */
	public function test_deleting_album_touches_no_index_row(): void {
		global $wpdb;

		$album_id = self::factory()->post->create(
			array(
				'post_type'  => 'mvs_album',
				'post_title' => 'Ordinary Album',
			)
		);

		$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index" );

		wp_delete_post( $album_id, true );

		$after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index" );

		$this->assertSame(
			$before,
			$after,
			'Deleting an album changed the media index row count — the purge has come back.'
		);
	}

	/**
	 * Creating an album must not write into mvs_media_index either.
	 *
	 * This is the invariant: an album ID never appears in media_id. It is the write
	 * side of the same defect, and the more damaging half — album creation is
	 * routine, deletion is rare.
	 */
	public function test_creating_an_album_writes_no_index_row(): void {
		$album_id = Plugin::container()->get( 'albums' )->create(
			self::factory()->user->create(),
			array(
				'title'   => 'Brand New Album',
				'privacy' => 'private',
			)
		);

		$this->assertIsInt( $album_id, 'Album was not created.' );
		$this->assertSame(
			'private',
			Plugin::container()->get( 'albums' )->get_privacy( $album_id ),
			'Album privacy did not round-trip through post meta.'
		);
	}

	/**
	 * Collections never write an index row of their own, so a purge there could
	 * only ever hit someone else's media.
	 */
	public function test_deleting_collection_does_not_destroy_colliding_media_row(): void {
		$media_author = self::factory()->user->create();

		$collection_id = self::factory()->post->create(
			array(
				'post_type'  => 'mvs_collection',
				'post_title' => 'Best of 2026',
			)
		);

		$this->seed_media_row_at( $collection_id, $media_author, 'Harbour at Night' );
		$this->assertTrue( $this->repo->exists( $collection_id ), 'Fixture failed: media row was not seeded.' );

		wp_delete_post( $collection_id, true );

		$this->assertTrue(
			$this->repo->exists( $collection_id ),
			'Deleting the collection destroyed a real media row that shared its ID.'
		);
		$this->assertSame(
			'Harbour at Night',
			(string) $this->repo->get( $collection_id, 'title' ),
			'The surviving row is no longer the media item.'
		);
	}

	/**
	 * Deleting an unrelated album must leave every other media row alone.
	 */
	public function test_deleting_album_leaves_unrelated_media_untouched(): void {
		$album_id = self::factory()->post->create( array( 'post_type' => 'mvs_album' ) );

		// Album privacy goes to post meta. Writing it through MediaRepository is
		// exactly what the guard refuses — see
		// test_repository_refuses_an_album_id below.
		Plugin::container()->get( 'albums' )->set_privacy( $album_id, 'private' );

		$other_id = $album_id + 5000;
		$this->seed_media_row_at( $other_id, self::factory()->user->create(), 'Untouched' );

		wp_delete_post( $album_id, true );

		$this->assertTrue( $this->repo->exists( $other_id ), 'An unrelated media row was removed.' );
	}

	/**
	 * The guard is the invariant enforced at the choke point: mvs_media_index is
	 * keyed on media IDs, so a wp_posts ID must be refused rather than written.
	 *
	 * Refuses rather than throws — a fatal on a mis-keyed write would take a site
	 * down, and the correct outcome is simply that the bad write does not happen.
	 */
	public function test_repository_refuses_an_album_id(): void {
		$album_id = self::factory()->post->create( array( 'post_type' => 'mvs_album' ) );

		$this->setExpectedIncorrectUsage( 'WPMediaVerse\\Repository\\MediaRepository::set_many' );
		$this->repo->set_many( $album_id, array( 'privacy' => 'private', 'slug' => 'nope' ) );

		$this->assertFalse(
			$this->repo->exists( $album_id ),
			'The guard let an album ID into mvs_media_index.'
		);
	}

	/**
	 * The analyser must report a collision when one exists, and must never write.
	 */
	public function test_analyser_detects_a_collision_without_writing(): void {
		$album_id = self::factory()->post->create(
			array(
				'post_type'  => 'mvs_album',
				'post_title' => 'Colliding Album',
			)
		);
		$this->seed_media_row_at( $album_id, self::factory()->user->create(), 'Real Photo' );

		$service = new \WPMediaVerse\Services\CptIdCollisionService();
		$report  = $service->analyze();

		$this->assertGreaterThanOrEqual( 1, (int) $report['totals']['collisions'], 'Collision not detected.' );
		$this->assertNotEmpty( $report['purge_risk'], 'Data-loss risk not reported.' );

		// Read-only: the row it reported on is still there, unchanged.
		$this->assertTrue( $this->repo->exists( $album_id ) );
		$this->assertSame( 'Real Photo', (string) $this->repo->get( $album_id, 'title' ) );
	}
}
