<?php
/**
 * Tests for Migrator::migrate_url_column_to_id (Phase 0b helper).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;

class MigratorUrlColumnTest extends WP_UnitTestCase {

	private int $admin_id;
	private string $fixture_table;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$this->admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->fixture_table = $wpdb->prefix . 'mvs_url_migration_fixture';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$this->fixture_table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				cover_image_url VARCHAR(500) DEFAULT NULL,
				cover_media_id BIGINT(20) UNSIGNED DEFAULT NULL,
				PRIMARY KEY (id)
			) {$wpdb->get_charset_collate()}"
		);
	}

	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS {$this->fixture_table}" );
		parent::tear_down();
	}

	/**
	 * Backfill resolves indexed URLs and writes media_id into the FK column.
	 */
	public function test_migrates_resolved_urls(): void {
		global $wpdb;

		$raw_url  = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/cover-' . wp_generate_password( 8, false ) . '.jpg';
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Cover for migration',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => $raw_url,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert( $this->fixture_table, array( 'cover_image_url' => $raw_url ), array( '%s' ) );
		$row_id = (int) $wpdb->insert_id;

		$result = Migrator::migrate_url_column_to_id( 'mvs_url_migration_fixture', 'cover_image_url', 'cover_media_id' );

		$this->assertSame( 1, $result['rows_seen'] );
		$this->assertSame( 1, $result['rows_updated'] );
		$this->assertSame( 0, $result['rows_unresolved'] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$stored_id = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT cover_media_id FROM {$this->fixture_table} WHERE id = %d", $row_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$this->assertSame( $media_id, $stored_id );
	}

	/**
	 * Unresolved URLs (avatars, theme images, deleted media) leave the FK
	 * column NULL and are counted as unresolved — caller decides fallback.
	 */
	public function test_unresolved_urls_left_null(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->fixture_table,
			array( 'cover_image_url' => 'https://example.com/wp-content/uploads/2026/05/avatar.jpg' ),
			array( '%s' )
		);

		$result = Migrator::migrate_url_column_to_id( 'mvs_url_migration_fixture', 'cover_image_url', 'cover_media_id' );

		$this->assertSame( 1, $result['rows_seen'] );
		$this->assertSame( 0, $result['rows_updated'] );
		$this->assertSame( 1, $result['rows_unresolved'] );
	}

	/**
	 * Idempotent: rows already migrated (cover_media_id NOT NULL) are
	 * skipped on a re-run.
	 */
	public function test_skips_already_migrated_rows(): void {
		global $wpdb;

		$raw_url  = 'https://example.com/wp-content/uploads/wpmediaverse/2026/05/already-' . wp_generate_password( 8, false ) . '.jpg';
		$media_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Already migrated',
				'post_author' => $this->admin_id,
				'media_type'  => 'image',
				'file_url'    => $raw_url,
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			$this->fixture_table,
			array(
				'cover_image_url' => $raw_url,
				'cover_media_id'  => $media_id,
			),
			array( '%s', '%d' )
		);

		$result = Migrator::migrate_url_column_to_id( 'mvs_url_migration_fixture', 'cover_image_url', 'cover_media_id' );

		// Pre-populated row is filtered by the WHERE cover_media_id IS NULL clause.
		$this->assertSame( 0, $result['rows_seen'] );
		$this->assertSame( 0, $result['rows_updated'] );
	}

	/**
	 * Identifier allowlist rejects SQL-unsafe arguments without touching the DB.
	 */
	public function test_rejects_unsafe_identifiers(): void {
		$result = Migrator::migrate_url_column_to_id( 'mvs_competitions; DROP TABLE foo', 'cover_image_url', 'cover_media_id' );

		$this->assertSame( 0, $result['rows_seen'] );
		$this->assertSame( 0, $result['rows_updated'] );
	}
}
