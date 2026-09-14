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

	/**
	 * v32 clears rows that outlived their media, and nothing else.
	 *
	 * The trap it guards: album and collection ids share the numeric space with
	 * media ids, and their stats rows are keyed by post id in `media_id`. A
	 * naive "not in the media index" purge deletes a live album's stats - which
	 * the QA site had, in both mvs_media_stats and mvs_activity.
	 */
	public function test_v32_purges_orphans_but_keeps_live_media_and_albums(): void {
		global $wpdb;

		$repo     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$live     = (int) $repo->insert(
			array(
				'title'       => 'v32 live',
				'post_author' => self::factory()->user->create(),
				'media_type'  => 'image',
				'file_url'    => 'https://example.com/wp-content/uploads/wpmediaverse/2026/09/v32-live.jpg',
			)
		);
		$album    = self::factory()->post->create( array( 'post_type' => 'mvs_album' ) );
		$orphan   = 987650;
		$stats    = $wpdb->prefix . 'mvs_media_stats';
		$meta     = $wpdb->prefix . 'mvs_media_meta';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$stats,
			array(
				'media_id' => $album,
				'views'    => 7,
			)
		);
		$wpdb->replace(
			$stats,
			array(
				'media_id' => $orphan,
				'views'    => 3,
			)
		);
		$wpdb->insert(
			$meta,
			array(
				'media_id'   => $live,
				'meta_key'   => 'v32', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'keep', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);
		$wpdb->insert(
			$meta,
			array(
				'media_id'   => $orphan,
				'meta_key'   => 'v32', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => 'drop', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		update_option( 'mvs_db_version', 31 );
		( new Migrator() )->run();

		$count = static function ( string $table, int $id ) use ( $wpdb ): int {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE media_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		// phpcs:enable

		$this->assertSame( 0, $count( $stats, $orphan ), 'orphaned stats row must be purged' );
		$this->assertSame( 0, $count( $meta, $orphan ), 'orphaned meta row must be purged' );
		$this->assertSame( 1, $count( $stats, $album ), 'a live album\'s stats row is NOT an orphan' );
		$this->assertSame( 1, $count( $meta, $live ), 'rows for live media must survive' );
	}
}
