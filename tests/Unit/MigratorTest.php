<?php
/**
 * Migrator tests.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPMediaVerse\Core\Migrator;

class MigratorTest extends TestCase {

	/**
	 * Test that all 9 custom tables exist after migration.
	 */
	public function test_all_tables_exist(): void {
		global $wpdb;

		$expected_tables = array(
			'mvs_reactions',
			'mvs_favorites',
			'mvs_media_views',
			'mvs_media_stats',
			'mvs_access_rules',
			'mvs_access_grants',
			'mvs_mentions',
			'mvs_album_items',
			'mvs_media_index',
		);

		foreach ( $expected_tables as $table ) {
			$full_table = $wpdb->prefix . $table;
			$exists     = $wpdb->get_var(
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $full_table )
			);
			$this->assertEquals( $full_table, $exists, "Table {$full_table} should exist." );
		}
	}

	/**
	 * Test that DB version option is set correctly.
	 */
	public function test_db_version_is_current(): void {
		$version = (int) get_option( Migrator::VERSION_OPTION, 0 );
		$this->assertEquals( Migrator::CURRENT_VERSION, $version );
	}

	/**
	 * Test that running migrate again is a no-op.
	 */
	public function test_migrate_idempotent(): void {
		$migrator = new Migrator();
		$migrator->run();

		$version = (int) get_option( Migrator::VERSION_OPTION, 0 );
		$this->assertEquals( Migrator::CURRENT_VERSION, $version );
	}

	/**
	 * Test mvs_media_index table has expected columns.
	 */
	public function test_media_index_schema(): void {
		global $wpdb;

		$columns = $wpdb->get_results(
			"DESCRIBE {$wpdb->prefix}mvs_media_index",
			ARRAY_A
		);

		$col_names = array_column( $columns, 'Field' );

		$expected = array( 'media_id', 'post_author', 'media_type', 'privacy', 'moderation_status', 'created_at' );

		foreach ( $expected as $col ) {
			$this->assertContains( $col, $col_names, "Column {$col} should exist in mvs_media_index." );
		}
	}
}
