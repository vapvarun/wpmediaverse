<?php
/**
 * A manual collection lists, counts and covers only what the viewer may open.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Social\FavoriteService;

/**
 * Basecamp 10298492555.
 *
 * get_collection_media_ids() returned raw favourite rows with no privacy
 * check, and the REST controller counted them with its own raw SQL, so a
 * manual collection could report - and draw its cover from - media the viewer
 * cannot open.
 */
class CollectionViewerGateTest extends WP_UnitTestCase {

	private FavoriteService $service;
	private int $owner;
	private int $stranger;
	private int $collection;
	private int $public_media;
	private int $private_media;

	public function set_up(): void {
		parent::set_up();

		$this->service  = new FavoriteService();
		$this->owner    = self::factory()->user->create( array( 'role' => 'author' ) );
		$this->stranger = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->collection = self::factory()->post->create(
			array(
				'post_type'   => 'mvs_collection',
				'post_author' => $this->owner,
			)
		);

		$this->public_media  = $this->seed_media( 'public' );
		$this->private_media = $this->seed_media( 'private' );

		$this->add_to_collection( $this->public_media );
		$this->add_to_collection( $this->private_media );

		// Prove the fixture IS the situation under test. If the repository
		// ignored 'privacy', both rows would be public and every assertion
		// below would pass while testing nothing - the exact way my last
		// regression test went green on an empty fixture.
		$privacy = Plugin::container()->get( 'privacy' );
		$this->assertTrue(
			$privacy->can_view( $this->public_media, $this->stranger ),
			'fixture: the public row is not viewable by a stranger'
		);
		$this->assertFalse(
			$privacy->can_view( $this->private_media, $this->stranger ),
			'fixture: the private row IS viewable by a stranger'
		);
	}

	private function seed_media( string $privacy ): int {
		$id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Fixture ' . wp_generate_password( 8, false ),
				'post_author' => $this->owner,
				'media_type'  => 'image',
				'privacy'     => $privacy,
			)
		);

		$this->assertGreaterThan( 0, $id, 'fixture media did not insert' );

		return $id;
	}

	private function add_to_collection( int $media_id ): void {
		global $wpdb;

		$ok = $wpdb->insert(
			$wpdb->prefix . 'mvs_favorites',
			array(
				'media_id'      => $media_id,
				'user_id'       => $this->owner,
				'collection_id' => $this->collection,
				'created_at'    => current_time( 'mysql' ),
			)
		);

		$this->assertNotFalse( $ok, 'fixture row did not insert: ' . $wpdb->last_error );
	}

	/**
	 * A stranger gets only the rows they may open.
	 */
	public function test_stranger_gets_only_what_they_may_open(): void {
		$ids = $this->service->get_collection_media_ids( $this->collection, 0, $this->stranger );

		$this->assertSame( array( $this->public_media ), $ids );
	}

	/**
	 * The owner still gets everything.
	 */
	public function test_owner_gets_everything(): void {
		$ids = $this->service->get_collection_media_ids( $this->collection, 0, $this->owner );

		$this->assertCount( 2, $ids );
		$this->assertContains( $this->public_media, $ids );
		$this->assertContains( $this->private_media, $ids );
	}

	/**
	 * A null viewer means the current user, not "no gate".
	 */
	public function test_null_viewer_means_the_current_user(): void {
		wp_set_current_user( $this->stranger );

		$this->assertSame(
			array( $this->public_media ),
			$this->service->get_collection_media_ids( $this->collection )
		);
	}

	/**
	 * The gate runs AFTER the mvs_collection_media_ids filter.
	 *
	 * Pro hooks that filter to union in its multi-collection rows. Gating
	 * BEFORE it would authorise Free's rows and let every Pro row through
	 * ungated - which is how the first cut of this fix was written. This
	 * asserts against that ordering, not just against the symptom.
	 */
	public function test_rows_added_by_the_filter_are_gated_too(): void {
		$private = $this->private_media;
		$inject  = static function ( $ids ) use ( $private ) {
			$ids[] = $private;

			return $ids;
		};

		add_filter( 'mvs_collection_media_ids', $inject );
		$ids = $this->service->get_collection_media_ids( $this->collection, 0, $this->stranger );
		remove_filter( 'mvs_collection_media_ids', $inject );

		$this->assertSame( array( $this->public_media ), $ids );
	}
}
