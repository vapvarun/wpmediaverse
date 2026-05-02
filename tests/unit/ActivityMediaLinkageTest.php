<?php
/**
 * Phase 8 — `ActivityMediaLinkage` service tests.
 *
 * Verifies the structured-storage path: write → read → render →
 * delete-on-activity-delete.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Integrations\BuddyPress\ActivityMediaLinkage;
use WPMediaVerse\Repository\MediaRepositoryInterface;

class ActivityMediaLinkageTest extends WP_UnitTestCase {

	private int $admin_id;
	private MediaRepositoryInterface $repo;
	private ActivityMediaLinkage $linkage;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		// Make sure migration v12 has run — the linkage table needs to exist
		// for these tests. Migrator::run() is idempotent.
		( new \WPMediaVerse\Core\Migrator() )->run();

		$linkage_table = $wpdb->prefix . 'mvs_bp_activity_media';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $linkage_table ) );
		if ( ! $exists ) {
			$this->markTestSkipped( 'mvs_bp_activity_media table missing — Migrator did not create it.' );
		}

		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin_id );

		$this->repo    = Plugin::container()->get( 'media_repository' );
		$this->linkage = new ActivityMediaLinkage(
			$this->repo,
			Plugin::container()->get( 'template_helpers' )
		);
	}

	private function insert_image( string $title = 'Linkage fixture' ): int {
		return (int) $this->repo->insert(
			array(
				'title'       => $title,
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/' . sanitize_title( $title ) . '.jpg',
			)
		);
	}

	private function fake_activity( int $id ): \stdClass {
		$activity     = new \stdClass();
		$activity->id = $id;
		return $activity;
	}

	private function count_links( int $activity_id ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_bp_activity_media WHERE activity_id = %d",
				$activity_id
			)
		);
	}

	/**
	 * Filter feed: when the `mvs_activity_media_ids` filter supplies media
	 * IDs, on_activity_save writes one linkage row per ID with monotonically
	 * increasing position.
	 */
	public function test_on_activity_save_writes_one_link_per_media_id(): void {
		$a            = $this->insert_image( 'Linkage A' );
		$b            = $this->insert_image( 'Linkage B' );
		$c            = $this->insert_image( 'Linkage C' );
		$activity_id  = 1001;

		add_filter(
			'mvs_activity_media_ids',
			function ( $ids ) use ( $a, $b, $c ) {
				return array( $a, $b, $c );
			}
		);

		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );

		remove_all_filters( 'mvs_activity_media_ids' );

		$this->assertSame( 3, $this->count_links( $activity_id ) );
		$this->assertTrue( $this->linkage->has_links( $activity_id ) );
	}

	/**
	 * Re-saving the same activity replaces existing linkage rows rather
	 * than appending duplicates (covers the edit-then-resave case).
	 */
	public function test_on_activity_save_replaces_existing_links(): void {
		$a           = $this->insert_image( 'Replace A' );
		$b           = $this->insert_image( 'Replace B' );
		$activity_id = 1002;

		add_filter( 'mvs_activity_media_ids', fn() => array( $a ) );
		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );
		$this->assertSame( 1, $this->count_links( $activity_id ) );

		remove_all_filters( 'mvs_activity_media_ids' );
		add_filter( 'mvs_activity_media_ids', fn() => array( $a, $b ) );
		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );
		remove_all_filters( 'mvs_activity_media_ids' );

		$this->assertSame( 2, $this->count_links( $activity_id ) );
	}

	/**
	 * Activities with no resolved media leave the linkage table untouched.
	 */
	public function test_on_activity_save_with_no_media_writes_nothing(): void {
		$activity_id = 1003;
		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );
		$this->assertSame( 0, $this->count_links( $activity_id ) );
		$this->assertFalse( $this->linkage->has_links( $activity_id ) );
	}

	/**
	 * `render()` emits MVS markup wrapped in `.mvs-activity-media-group`.
	 * Markup is generated via TemplateHelpers — no regex on saved HTML.
	 */
	public function test_render_emits_grouped_markup_from_linkage(): void {
		$a           = $this->insert_image( 'Render A' );
		$b           = $this->insert_image( 'Render B' );
		$activity_id = 1004;

		add_filter( 'mvs_activity_media_ids', fn() => array( $a, $b ) );
		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );
		remove_all_filters( 'mvs_activity_media_ids' );

		$html = $this->linkage->render( $activity_id );
		$this->assertStringContainsString( 'mvs-activity-media-group', $html );
		$this->assertStringContainsString( 'mvs-activity-media-group--count-2', $html );
		$this->assertStringContainsString(
			'data-mvs-activity-id="' . $activity_id . '"',
			$html
		);
	}

	/**
	 * `render()` returns '' when the activity has no linkage rows so
	 * ActivityContentIntegration falls back to the legacy regex parser.
	 */
	public function test_render_returns_empty_string_when_no_links(): void {
		$activity_id = 1005;
		$this->assertSame( '', $this->linkage->render( $activity_id ) );
	}

	/**
	 * Deleting an activity drops its linkage rows.
	 */
	public function test_on_activity_delete_drops_links(): void {
		$a           = $this->insert_image( 'Delete A' );
		$b           = $this->insert_image( 'Delete B' );
		$activity_id = 1006;

		add_filter( 'mvs_activity_media_ids', fn() => array( $a, $b ) );
		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );
		remove_all_filters( 'mvs_activity_media_ids' );

		$this->assertSame( 2, $this->count_links( $activity_id ) );

		$this->linkage->on_activity_delete( array( 'id' => $activity_id ) );

		$this->assertSame( 0, $this->count_links( $activity_id ) );
	}

	/**
	 * Linkage rows for non-existent media are silently skipped — caller
	 * supplied a stale ID, the table never accumulates orphans.
	 */
	public function test_on_activity_save_skips_unknown_media_ids(): void {
		$real_id     = $this->insert_image( 'Skip-unknown real' );
		$activity_id = 1007;

		add_filter(
			'mvs_activity_media_ids',
			fn() => array( $real_id, 999_999, 0, -1 )
		);
		$this->linkage->on_activity_save( $this->fake_activity( $activity_id ) );
		remove_all_filters( 'mvs_activity_media_ids' );

		$this->assertSame( 1, $this->count_links( $activity_id ) );
	}
}
