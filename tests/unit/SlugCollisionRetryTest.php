<?php
/**
 * Same-title uploads racing for one slug both land.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * Same-title uploads racing for one slug both land.
 */
class SlugCollisionRetryTest extends WP_UnitTestCase {

	public function test_parallel_insert_taking_the_slug_does_not_fail_the_upload(): void {
		global $wpdb;

		$repo  = Plugin::container()->get( 'media_repository' );
		$index = $wpdb->prefix . 'mvs_media_index';
		$raced = false;

		// Just before our INSERT runs, a "parallel upload" takes the same slug.
		$racer = static function ( $query ) use ( &$raced, $wpdb, $index ) {
			if ( ! $raced && 0 === strpos( $query, "INSERT INTO `{$index}`" ) && false !== strpos( $query, "'race-photo'" ) ) {
				$raced = true;
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$index} (title, slug, post_author, status, media_type, privacy) VALUES ('Race photo', %s, 1, 'publish', 'image', 'public')", 'race-photo' ) ); // phpcs:ignore
			}
			return $query;
		};
		add_filter( 'query', $racer );
		$id = $repo->insert( array( 'title' => 'Race photo', 'post_author' => 1, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );
		remove_filter( 'query', $racer );

		$this->assertTrue( $raced, 'The race did not happen; the test proves nothing.' );
		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertStringStartsWith( 'race-photo-', (string) $repo->get( $id, 'slug' ) );
	}

	public function test_crowded_title_costs_at_most_two_queries(): void {
		$repo = Plugin::container()->get( 'media_repository' );
		for ( $i = 0; $i < 6; $i++ ) {
			$repo->insert( array( 'title' => 'Crowded', 'post_author' => 1, 'status' => 'publish', 'media_type' => 'image', 'privacy' => 'public' ) );
		}

		global $wpdb;
		$before = $wpdb->num_queries;
		$slug   = $repo->generate_unique_slug( 'Crowded' );
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $before );
		$this->assertMatchesRegularExpression( '/^crowded-[a-z0-9]{6}$/', $slug );
	}
}
