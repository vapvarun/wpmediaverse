<?php
/**
 * One answer to "may this item be downloaded?": site switch AND per-item opt-out.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Core\TemplateHelpers;

/**
 * The media-player block rendered a Download link with
 * neither check, while the single page gated on both.
 */
class DownloadsAllowedTest extends WP_UnitTestCase {

	private function media( array $extra = array() ): int {
		return (int) Plugin::container()->get( 'media_repository' )->insert(
			array_merge(
				array(
					'title'       => 'Downloadable',
					'post_author' => self::factory()->user->create( array( 'role' => 'author' ) ),
					'privacy'     => 'public',
				),
				$extra
			)
		);
	}

	private function repo() {
		return Plugin::container()->get( 'media_repository' );
	}

	public function test_allowed_when_site_on_and_item_silent(): void {
		update_option( 'mvs_allow_downloads', true );

		$this->assertTrue( $this->repo()->downloads_allowed( $this->media() ) );
	}

	public function test_refused_when_the_site_switch_is_off(): void {
		// An unchecked box saves '' - and update_option( ..., false ) on a missing
		// option is a no-op, which would leave the default (on) in place.
		update_option( 'mvs_allow_downloads', '' );

		$this->assertFalse( $this->repo()->downloads_allowed( $this->media() ) );
	}

	public function test_refused_when_the_owner_opted_the_item_out(): void {
		update_option( 'mvs_allow_downloads', true );
		$id = $this->media();
		$this->repo()->set_meta_many( 'allow_download', array( $id => '0' ) );

		$this->assertFalse( $this->repo()->downloads_allowed( $id ) );
	}

	/**
	 * The players follow the same answer: no attribute while downloads are
	 * allowed, controlsList="nodownload" (plus the guard script) when either
	 * gate is closed. Basecamp 10458760669.
	 */
	public function test_player_attribute_follows_both_gates(): void {
		update_option( 'mvs_allow_downloads', true );
		$open   = $this->media();
		$locked = $this->media();
		$this->repo()->set_meta_many( 'allow_download', array( $locked => '0' ) );

		$this->assertSame( '', TemplateHelpers::player_download_attr( $open ) );
		$this->assertSame( ' controlsList="nodownload"', TemplateHelpers::player_download_attr( $locked ) );

		update_option( 'mvs_allow_downloads', '' );
		$this->assertSame( ' controlsList="nodownload"', TemplateHelpers::player_download_attr( $open ) );
	}
}
