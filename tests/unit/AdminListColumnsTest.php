<?php
/**
 * 2.6.0 admin simplification: the All Media and Tags lists carry fewer columns.
 *
 * The ID and Optimization columns and the Optimize / Repair row links left All
 * Media; the actions themselves moved to the Details view, which still offers
 * them. Tags lost the ID and Slug columns, but a bookmarked `orderby=slug`
 * sort must still render.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\MediaListPage;
use WPMediaVerse\Admin\TagManagementPage;

class AdminListColumnsTest extends WP_UnitTestCase {

	/** @var int */
	private static $seq = 0;

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$_GET = array();
		parent::tear_down();
	}

	private function row( string $media_type, string $mime ): int {
		global $wpdb;
		++self::$seq;

		$wpdb->insert(
			$wpdb->prefix . 'mvs_media_index',
			array(
				'title'             => 'Column row ' . self::$seq,
				'slug'              => 'column-row-' . self::$seq . '-' . wp_rand( 1000, 9999 ),
				'post_author'       => get_current_user_id(),
				'media_type'        => $media_type,
				'file_type'         => $mime,
				'file_path'         => 'x/column-' . self::$seq . '.bin',
				'file_size'         => 1024,
				'privacy'           => 'public',
				'status'            => 'publish',
				'moderation_status' => 'approved',
				'created_at'        => current_time( 'mysql', true ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	private function render_media( array $get ): string {
		$_GET = $get;
		ob_start();
		MediaListPage::render();
		return (string) ob_get_clean();
	}

	// ------------------------------------------------------------ all media --

	public function test_media_list_has_no_id_or_optimization_column(): void {
		$this->row( 'image', 'image/jpeg' );

		$html = $this->render_media( array( 'page' => 'mvs-media' ) );

		$this->assertStringContainsString( 'column-primary', $html, 'The list itself must render.' );
		$this->assertStringNotContainsString( 'mvs-col-id', $html );
		$this->assertStringNotContainsString( 'mvs-col-optimization', $html );
		$this->assertStringNotContainsString( 'class="optimize"', $html );
		$this->assertStringNotContainsString( 'class="repair-thumb"', $html );
	}

	public function test_empty_media_list_spans_every_column(): void {
		$html = $this->render_media(
			array(
				'page' => 'mvs-media',
				's'    => 'no-such-media-' . wp_rand(),
			)
		);

		$this->assertStringContainsString( 'colspan="9"', $html );
	}

	public function test_details_view_still_offers_re_optimize(): void {
		$id = $this->row( 'image', 'image/jpeg' );

		$html = $this->render_media(
			array(
				'page'     => 'mvs-media',
				'view'     => 'details',
				'media_id' => (string) $id,
			)
		);

		$this->assertStringContainsString( 'Re-optimize', $html );
	}

	public function test_details_view_offers_repair_for_a_video_when_an_integration_can(): void {
		$id = $this->row( 'video', 'video/mp4' );
		add_filter( 'mvs_can_repair_thumb', '__return_true' );

		$html = $this->render_media(
			array(
				'page'     => 'mvs-media',
				'view'     => 'details',
				'media_id' => (string) $id,
			)
		);

		$this->assertStringContainsString( 'Repair thumbnails', $html );
	}

	// ----------------------------------------------------------------- tags --

	private function render_tags( array $get ): string {
		$_GET = $get;
		ob_start();
		( new TagManagementPage() )->render();
		return (string) ob_get_clean();
	}

	public function test_tags_list_has_four_columns(): void {
		wp_insert_term( 'Sunsets', 'mvs_tag' );

		$html = $this->render_tags( array( 'page' => 'mvs-tags' ) );

		$this->assertStringContainsString( 'Sunsets', $html );
		$this->assertStringNotContainsString( 'column-id', $html );
		$this->assertStringNotContainsString( 'column-slug', $html );
		$this->assertStringNotContainsString( 'orderby=term_id', $html );
		$this->assertStringNotContainsString( 'orderby=slug', $html );
	}

	public function test_empty_tags_list_spans_every_column(): void {
		$html = $this->render_tags(
			array(
				'page' => 'mvs-tags',
				's'    => 'no-such-tag-' . wp_rand(),
			)
		);

		$this->assertStringContainsString( 'colspan="4"', $html );
	}

	public function test_a_bookmarked_slug_sort_still_renders(): void {
		wp_insert_term( 'Beaches', 'mvs_tag' );

		$html = $this->render_tags(
			array(
				'page'    => 'mvs-tags',
				'orderby' => 'slug',
				'order'   => 'desc',
			)
		);

		$this->assertStringContainsString( 'Beaches', $html );
	}
}
