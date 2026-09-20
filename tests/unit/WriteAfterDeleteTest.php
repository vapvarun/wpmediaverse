<?php
/**
 * A write for a media row that is gone must not bring the row back.
 *
 * Basecamp: a BunnyCDN upload job finishing through Action Scheduler AFTER the
 * member deleted the media called set( $id, 'file_url', ... ). set() inserted a
 * stub carrying one column, leaving a ghost row with an empty slug, title,
 * author and media_type. `slug` is NOT NULL UNIQUE, so the first ghost stuck
 * and every later one failed with a duplicate-key database error.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class WriteAfterDeleteTest extends WP_UnitTestCase {

	private function repo() {
		return \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
	}

	private function rows( int $media_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", $media_id ) );
	}

	public function test_setting_a_column_on_a_missing_row_creates_nothing(): void {
		global $wpdb;

		$gone = 999777;
		$this->assertSame( 0, $this->rows( $gone ) );

		$this->repo()->set( $gone, 'file_url', 'https://cdn.example.com/late-upload.jpg' );

		$this->assertSame( 0, $this->rows( $gone ), 'set() resurrected a deleted media row.' );
		$this->assertSame( '', (string) $wpdb->last_error );
	}

	public function test_a_second_late_write_does_not_hit_the_unique_slug(): void {
		global $wpdb;

		$this->repo()->set( 999778, 'file_url', 'https://cdn.example.com/a.jpg' );
		$this->repo()->set( 999779, 'file_url', 'https://cdn.example.com/b.jpg' );

		$this->assertSame( '', (string) $wpdb->last_error, 'Duplicate-key error on a second write-after-delete.' );
		$this->assertSame( 0, $this->rows( 999778 ) );
		$this->assertSame( 0, $this->rows( 999779 ) );
	}

	public function test_a_live_row_still_updates(): void {
		$id = (int) $this->repo()->insert(
			array(
				'title'       => 'Live row',
				'post_author' => 1,
				'media_type'  => 'image',
				'status'      => 'publish',
				'privacy'     => 'public',
				'file_path'   => '2026/09/live.jpg',
				'file_type'   => 'image/jpeg',
				'slug'        => 'live-row-' . wp_generate_password( 8, false, false ),
			)
		);

		$this->repo()->set( $id, 'file_url', 'https://cdn.example.com/live.jpg' );

		global $wpdb;
		// The raw column: get( 'file_url' ) hands back a signed serve URL.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT file_url FROM {$wpdb->prefix}mvs_media_index WHERE media_id = %d", $id ) );

		$this->assertSame( 'https://cdn.example.com/live.jpg', $stored );
	}
}
