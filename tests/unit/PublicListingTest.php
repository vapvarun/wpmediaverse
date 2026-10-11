<?php
/**
 * Core listings (sitemap, feed) show only public albums and collections.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_Query;
use WP_UnitTestCase;

/**
 * Core listings (sitemap, feed) show only public albums and collections.
 */
class PublicListingTest extends WP_UnitTestCase {

	public function test_sitemap_query_lists_only_public_albums_and_no_favorites(): void {
		$public   = self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_status' => 'publish' ) );
		$private  = self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_status' => 'publish' ) );
		$unset    = self::factory()->post->create( array( 'post_type' => 'mvs_album', 'post_status' => 'publish' ) );
		$favorite = self::factory()->post->create( array( 'post_type' => 'mvs_collection', 'post_status' => 'publish' ) );
		update_post_meta( $public, '_mvs_privacy', 'public' );
		update_post_meta( $private, '_mvs_privacy', 'private' );
		update_post_meta( $favorite, '_mvs_favorites_collection', 1 );

		$albums = ( new WP_Query( apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'mvs_album', 'fields' => 'ids', 'posts_per_page' => 50 ), 'mvs_album' ) ) )->posts;
		$this->assertContains( $public, $albums );
		$this->assertContains( $unset, $albums, 'No stored privacy means public.' );
		$this->assertNotContains( $private, $albums );

		$collections = ( new WP_Query( apply_filters( 'wp_sitemaps_posts_query_args', array( 'post_type' => 'mvs_collection', 'fields' => 'ids', 'posts_per_page' => 50 ), 'mvs_collection' ) ) )->posts;
		$this->assertNotContains( $favorite, $collections );
	}
}
