<?php
/**
 * Fixes for QA rejects on the 2.6.0 Big-site card.
 *
 * - Admin and REST search: words FULLTEXT never indexes (under 3 characters,
 *   InnoDB stopwords) were required, so "Mountain Peak at Sunrise" or
 *   "QA Load 17" matched nothing. They are left out, and the caller is told so
 *   it can require the whole phrase with LIKE.
 * - A background tag merge left the merged tag's name in each item's tags
 *   list, and must not skip items across batches.
 *
 * FULLTEXT itself cannot be exercised here: InnoDB does not index rows that
 * the test suite's transaction has not committed.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaRepository;

class QaRejectFixesTest extends WP_UnitTestCase {

	public function test_search_leaves_out_words_fulltext_cannot_match(): void {
		$this->assertSame( array( 'query' => '+Mountain* +Peak* +Sunrise*', 'dropped' => true ), MediaRepository::fulltext_boolean_query( 'Mountain Peak at Sunrise' ) );
		$this->assertSame( array( 'query' => '+Load*', 'dropped' => true ), MediaRepository::fulltext_boolean_query( 'QA Load 17' ) );
		$this->assertSame( array( 'query' => '+sunset* +beach*', 'dropped' => false ), MediaRepository::fulltext_boolean_query( 'sunset beach' ) );
		$this->assertSame( '', MediaRepository::fulltext_boolean_query( 'at the' )['query'], 'Only stopwords left must fall back to LIKE.' );
		$this->assertSame( '+Rain* +roof*', MediaRepository::fulltext_boolean_query( '+Rain -on (the) "roof"' )['query'], 'Operators must never reach MySQL.' );
	}

	public function test_background_tag_merge_updates_every_item_and_drops_the_old_name(): void {
		( new \WPMediaVerse\Core\Migrator() )->run();
		$repo   = Plugin::container()->get( 'media_repository' );
		$source = (int) wp_insert_term( 'sunsets-old', 'mvs_tag' )['term_id'];
		$target = (int) wp_insert_term( 'sunset', 'mvs_tag' )['term_id'];
		$owner  = self::factory()->user->create();

		$ids = array();
		for ( $i = 0; $i < 201; $i++ ) { // One more than a batch, so the merge runs twice.
			$id = (int) $repo->insert(
				array(
					'title'       => 'Merge probe ' . $i,
					'post_author' => $owner,
					'media_type'  => 'image',
					'status'      => 'publish',
					'privacy'     => 'public',
					'slug'        => 'merge-probe-' . $i . '-' . wp_generate_password( 6, false, false ),
				)
			);
			wp_set_object_terms( $id, array( $source ), 'mvs_tag' );
			$repo->set( $id, 'tags', wp_json_encode( array( 'sunsets-old' ) ) );
			$ids[] = $id;
		}

		$page = Plugin::container()->get( 'admin.tags' );
		$page->process_merge_batch( $source, $target ); // First batch; the rest is queued.
		if ( get_term( $source, 'mvs_tag' ) ) {
			$page->process_merge_batch( $source, $target, 0 ); // What the queued action runs.
		}

		$this->assertNull( get_term( $source, 'mvs_tag' ), 'The merged tag still exists.' );
		foreach ( array( $ids[0], $ids[150], $ids[200] ) as $id ) {
			$this->assertSame( array( 'sunset' ), json_decode( (string) $repo->get( $id, 'tags' ), true ), "Item {$id} kept the old tag name." );
		}
		$this->assertSame( 201, (int) get_term( $target, 'mvs_tag' )->count, 'An item was skipped between batches.' );
	}
}
