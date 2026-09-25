<?php
/**
 * 2.6.0 frontend polish from the member walk.
 *
 * - The album page lists only items the viewer may open: a private item in a
 *   public album showed as a broken tile with its title, and counted.
 * - Privacy badges use the short word ("Members"), not the picker sentence.
 * - Virtual routes keep the site name in the tab title.
 * - Reaction emoji resolve to the vendored Fluent SVGs.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Core\TemplateLoader;

class FrontendPolishTest extends WP_UnitTestCase {

	public function test_album_page_lists_only_what_the_viewer_can_open(): void {
		( new \WPMediaVerse\Core\Migrator() )->run();
		$owner  = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$viewer = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$repo   = Plugin::container()->get( 'media_repository' );
		$albums = Plugin::container()->get( 'albums' );

		$make = static function ( string $privacy ) use ( $repo, $owner ): int {
			return (int) $repo->insert(
				array(
					'title'             => 'Album probe ' . $privacy,
					'post_author'       => $owner,
					'media_type'        => 'image',
					'status'            => 'publish',
					'moderation_status' => 'approved',
					'privacy'           => $privacy,
					'file_path'         => '2026/09/probe.jpg',
					'file_type'         => 'image/jpeg',
					'slug'              => 'album-probe-' . wp_generate_password( 8, false, false ),
				)
			);
		};
		$public  = $make( 'public' );
		$private = $make( 'private' );
		$album   = (int) $albums->create( $owner, array( 'title' => 'Mixed album' ) );
		$albums->add_items( $album, array( $public, $private ) );

		wp_set_current_user( $viewer );
		$ids = array_map( 'intval', array_column( $albums->get_items_with_data( $album ), 'media_id' ) );

		$this->assertSame( array( $public ), $ids, 'The album page listed an item the viewer cannot open.' );
	}

	public function test_privacy_badge_is_the_short_word(): void {
		$this->assertSame( 'Members', TemplateHelpers::privacy_short_label( 'loggedin' ) );
		$this->assertSame( 'Only me', TemplateHelpers::privacy_short_label( 'private' ) );
		$this->assertStringNotContainsString( 'legacy', TemplateHelpers::privacy_label( 'loggedin' ) );
	}

	public function test_route_title_keeps_the_site_name(): void {
		$parts = TemplateLoader::title_parts( array( 'title' => get_bloginfo( 'name' ), 'tagline' => 'Just another site' ), 'Compete' );

		$this->assertSame( 'Compete', $parts['title'] );
		$this->assertSame( get_bloginfo( 'name', 'display' ), $parts['site'] );
		$this->assertArrayNotHasKey( 'tagline', $parts );
	}

	public function test_reactions_resolve_to_fluent_svgs(): void {
		foreach ( \WPMediaVerse\Social\ReactionService::TYPES as $type ) {
			$this->assertStringEndsWith( 'assets/emoji/' . $type . '.svg', TemplateHelpers::emoji_url( $type ) );
		}
		$this->assertSame( '', TemplateHelpers::emoji_url( 'nope' ) );
	}
}
