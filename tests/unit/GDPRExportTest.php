<?php
/**
 * Basecamp 10350231765 — a member with zero media got a personal data
 * export with no Media Items section at all, not an empty one. WordPress
 * only renders a group heading in the export file when at least one item
 * carries that group_id, so an exporter returning `data: []` is
 * indistinguishable from one that never ran.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\GDPRService;

/**
 * @since 2.6.0
 */
class GDPRExportTest extends WP_UnitTestCase {

	private GDPRService $gdpr;

	public function set_up(): void {
		parent::set_up();
		$this->gdpr = new GDPRService();
	}

	/**
	 * A member with no media still gets an explicit, empty Media Items
	 * group on page 1, so the exporter's own output proves it ran.
	 */
	public function test_export_media_keeps_the_section_when_the_member_has_no_media(): void {
		$user   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$result = $this->gdpr->export_media( get_userdata( $user )->user_email, 1 );

		$this->assertNotEmpty( $result['data'], 'The Media Items section must not vanish when there is nothing to export.' );
		$this->assertSame( 'wpmediaverse-media', $result['data'][0]['group_id'] );
		$this->assertTrue( $result['done'] );
	}

	/**
	 * A member who DOES have media still sees their item, unaffected by the
	 * empty-case placeholder.
	 */
	public function test_export_media_lists_a_real_item_when_the_member_has_media(): void {
		$user     = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'media_type'  => 'image',
				'post_author' => $user,
				'title'       => 'Export Me',
				'slug'        => 'export-me-' . wp_rand(),
				'file_url'    => 'https://example.test/export.jpg',
				'file_type'   => 'image/jpeg',
				'file_size'   => 1024,
			)
		);

		$result = $this->gdpr->export_media( get_userdata( $user )->user_email, 1 );

		$this->assertCount( 1, $result['data'] );
		$this->assertSame( "mvs-media-{$media_id}", $result['data'][0]['item_id'] );
	}

	/**
	 * The placeholder is only for the genuinely empty first page — an empty
	 * LATER page of a multi-page export is normal and must not add a
	 * second, duplicate group entry.
	 */
	public function test_export_media_does_not_duplicate_the_placeholder_on_a_later_page(): void {
		$user   = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$result = $this->gdpr->export_media( get_userdata( $user )->user_email, 2 );

		$this->assertSame( array(), $result['data'], 'A later empty page must stay empty; the heading already appeared on page 1.' );
	}
}
