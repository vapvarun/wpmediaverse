<?php
/**
 * Phase 4 — Migrator coverage.
 *
 * `migrate_url_column_to_id` is exercised in `MigratorUrlColumnTest`.
 * This file covers the instance `run()` method.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;

class MigratorTest extends WP_UnitTestCase {

	/**
	 * run() is idempotent — running twice should not error and should
	 * leave `mvs_db_version` at the current target version.
	 */
	public function test_run_is_idempotent(): void {
		$migrator = new Migrator();

		$migrator->run();
		$first_version = (int) get_option( 'mvs_db_version' );
		$this->assertSame( Migrator::CURRENT_VERSION, $first_version );

		$migrator->run();
		$second_version = (int) get_option( 'mvs_db_version' );
		$this->assertSame( Migrator::CURRENT_VERSION, $second_version );
	}

	/**
	 * Running on a fresh install creates the canonical media-index table
	 * and seeds default options.
	 */
	public function test_run_creates_index_table_and_seeds_defaults(): void {
		global $wpdb;

		$migrator = new Migrator();
		$migrator->run();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$index_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'mvs_media_index' )
		);
		$this->assertNotEmpty( $index_exists, 'mvs_media_index table must exist after run()' );

		// Migration v11 seeds mvs_ai_monthly_budget to 10 USD/month.
		$this->assertSame( '10', get_option( 'mvs_ai_monthly_budget' ) );
	}
}
