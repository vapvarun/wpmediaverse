<?php
/**
 * 2.6.0 member experience: readable defaults and lighter lists.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\FilenameStrategy;

class MemberExperienceTest extends WP_UnitTestCase {

	public function test_untitled_uploads_get_a_readable_title(): void {
		$this->assertSame( 'Magnific feel the beat', FilenameStrategy::title_from( 'magnific-feel-the-beat.mp3' ) );
		$this->assertSame( 'IMG 2034', FilenameStrategy::title_from( 'IMG_2034.jpg' ) );
		$this->assertSame( 'Été à paris', FilenameStrategy::title_from( 'été_à.paris.png' ) );
		$this->assertSame( '', FilenameStrategy::title_from( '.jpg' ) );
	}

	public function test_lists_offer_one_sort_control_and_none_when_empty(): void {
		$tpl = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' );

		$this->assertSame( '', $tpl->render_explore_sort_toolbar( 0 ), 'An empty list still offered sorting.' );

		$html = $tpl->render_explore_sort_toolbar( 5 );
		$this->assertSame( 1, substr_count( $html, '<select' ), 'The list has more than one sort control.' );
		$this->assertStringNotContainsString( 'name="order"', $html );
		$this->assertStringContainsString( 'Newest', $html );
		$this->assertStringContainsString( 'Oldest', $html );
		$this->assertStringContainsString( 'Most viewed', $html );

		$_GET = array( 'sort' => 'oldest' );
		$this->assertSame( array( 'orderby' => 'created_at', 'order' => 'ASC' ), $tpl->explore_sort() );
		$this->assertStringContainsString( 'value="oldest" selected', $tpl->render_explore_sort_toolbar( 5 ) );
		$_GET = array( 'sort' => 'title', 'order' => 'asc' ); // An old link keeps working.
		$this->assertSame( array( 'orderby' => 'title', 'order' => 'ASC' ), $tpl->explore_sort() );
		$_GET = array();
	}

	public function test_favorites_has_one_home_in_collections(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		\WPMediaVerse\Core\DashboardSections::flush();

		$rail = array();
		foreach ( \WPMediaVerse\Core\DashboardSections::grouped() as $sections ) {
			$rail = array_merge( $rail, array_keys( $sections ) );
		}
		$this->assertNotContains( 'favorites', $rail, 'Favorites is a second home on the rail.' );
		$this->assertContains( 'collections', $rail );
		$this->assertTrue( \WPMediaVerse\Core\DashboardSections::exists( 'favorites' ), '/my-media/favorites/ stopped working.' );
	}
}
