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
		//
		// Cast, because get_option()'s TYPE is not a property of the option — it
		// is a property of cache warmth. migrate_to_11() writes int 10, so a read
		// served from the in-request options cache returns int 10 while one that
		// round-trips the database returns string '10' (WP stores options as
		// strings). assertSame is type-strict, so this assertion flipped with test
		// order and cache state and was the whole of the "stage 2.4 CI flake"
		// (Basecamp 10198467141) — not opcache, which the shipped
		// `opcache.enable_cli=0` guard proved by failing with it applied.
		//
		// Cast rather than assertEquals: the VALUE still has to be exactly 10.
		// Nothing downstream cares about the type — AIService reads it as
		// (float) — so the test should not either.
		$this->assertSame( '10', (string) get_option( 'mvs_ai_monthly_budget' ) );
	}
}
