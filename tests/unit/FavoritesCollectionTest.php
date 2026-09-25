<?php
/**
 * 2.6.0: Save replaced the lightbox Favorite button. A member's favourites
 * appear in a private "Favorites" collection, read from mvs_favorites.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WP_REST_Request;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Social\FavoriteService;

class FavoritesCollectionTest extends WP_UnitTestCase {

	private int $member;
	private int $other;

	public function set_up(): void {
		parent::set_up();
		( new \WPMediaVerse\Core\Migrator() )->run();
		$this->member = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		do_action( 'rest_api_init' );
	}

	private function media(): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'             => 'Fav probe',
				'post_author'       => $this->other,
				'media_type'        => 'image',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'privacy'           => 'public',
				'file_path'         => '2026/09/fav.jpg',
				'file_type'         => 'image/jpeg',
				'slug'              => 'fav-' . wp_generate_password( 8, false, false ),
			)
		);
	}

	public function test_existing_favorites_show_in_a_private_favorites_collection(): void {
		$favorites = Plugin::container()->get( 'favorites' );
		$a         = $this->media();
		$b         = $this->media();
		$favorites->toggle( $a, $this->member );
		$favorites->toggle( $b, $this->member );

		wp_set_current_user( $this->member );
		$list = rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/collections' ) )->get_data();
		$fav  = array_values( array_filter( $list, static fn( $c ) => ! empty( $c['is_favorites'] ) ) );
		$this->assertCount( 1, $fav, 'The member has no Favorites collection.' );
		$fav_id = (int) $fav[0]["id"];
		$this->assertSame( 2, (int) $fav[0]['total'], 'Favorites does not hold what the member favorited.' );
		$this->assertSame( 'private', get_post_status( $fav_id ) );

		// Once per member.
		rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/collections' ) );
		$this->assertSame( $fav_id, $favorites->favorites_collection_id( $this->member ) );
		$this->assertCount( 1, get_posts( array( 'post_type' => 'mvs_collection', 'post_status' => 'any', 'author' => $this->member, 'fields' => 'ids' ) ) );

		// Never public, never deletable, never someone else's to read.
		$collections = Plugin::container()->get( 'collections' );
		$this->assertSame( 'private', $collections->set_privacy( $fav_id, 'public' ) );
		$this->assertSame( 400, rest_do_request( new WP_REST_Request( 'DELETE', '/mvs/v1/collections/' . $fav_id ) )->get_status() );
		$this->assertSame( 'private', get_post_status( $fav_id ), 'The Favorites collection was deleted.' );
		wp_set_current_user( $this->other );
		$this->assertContains( rest_do_request( new WP_REST_Request( 'GET', '/mvs/v1/collections/' . $fav_id ) )->get_status(), array( 403, 404 ) );
		$this->assertFalse( Plugin::container()->get( 'privacy' )->can_view( $fav_id, $this->other, \WPMediaVerse\Services\PrivacyService::SPACE_CPT ) );
	}

	public function test_the_app_can_target_the_favorites_collection(): void {
		$media  = $this->media();
		$fav_id = Plugin::container()->get( 'favorites' )->favorites_collection_id( $this->member, true );
		wp_set_current_user( $this->member );

		$req = new WP_REST_Request( 'POST', '/mvs/v1/media/' . $media . '/favorite' );
		$req->set_param( 'collection_id', $fav_id );
		$this->assertSame( 200, rest_do_request( $req )->get_status() );
		$this->assertContains( $media, FavoriteService::favorites_owner( $fav_id ) ? Plugin::container()->get( 'favorites' )->get_collection_media_ids( $fav_id, 0, $this->member ) : array() );
	}

	public function test_lightbox_offers_save_not_favorite(): void {
		wp_set_current_user( $this->member );
		add_filter( 'mvs_collections_enabled', '__return_false', 99 ); // Free alone.
		ob_start();
		include MVS_PLUGIN_DIR . 'templates/partials/shared-ui-frame.php';
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'mvs-lb-fav', $html, 'The lightbox still shows a separate Favorite button.' );
		$this->assertMatchesRegularExpression( '/class="mvs-lightbox-action mvs-lb-save"[^>]*data-mvs-fav-toggle/', $html, 'Free has no way to keep an item.' );
		$this->assertStringContainsString( 'data-wp-bind--hidden="!state.lightboxHasNext"', $html, 'Next is not bound to whether there is a next item.' );
		$this->assertStringContainsString( 'state.lightboxHideFullscreen', $html );
		$this->assertStringContainsString( 'class="mvs-lightbox-title"', $html );

		add_filter( 'mvs_show_favorite_button', '__return_true' );
		ob_start();
		include MVS_PLUGIN_DIR . 'templates/partials/shared-ui-frame.php';
		$legacy = (string) ob_get_clean();
		remove_filter( 'mvs_show_favorite_button', '__return_true' );
		remove_filter( 'mvs_collections_enabled', '__return_false', 99 );
		$this->assertStringContainsString( 'mvs-lb-fav', $legacy, 'The filter did not bring the Favorite button back.' );
	}
}
