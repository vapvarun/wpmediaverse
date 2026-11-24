<?php
/**
 * A table repair is only a rebuild: it never starts a second upgrade, and it never takes back an owner's choice.
 *
 * It used to reset the stored version to 0 and call run(), so while it was under way, or after it was cut
 * short, an ordinary request ran every step from the start, including v45, which takes the moderator caps
 * off the Editor role and so undid a re-grant the owner had made in Settings > Permissions.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Capabilities\MediaCapabilities;
use WPMediaVerse\Core\Migrator;

/**
 * Repair of missing tables.
 */
class MigratorRepairTest extends WP_UnitTestCase {

	/**
	 * Table taken away to make a repair necessary (unprefixed).
	 *
	 * @var string
	 */
	private const TABLE = 'mvs_blocks';

	/**
	 * An Editor moderation cap the owner has granted back.
	 *
	 * @var string
	 */
	private string $cap;

	/**
	 * Fully migrated site, with the owner's Editor moderation grant in place and one table missing.
	 */
	public function set_up(): void {
		global $wpdb;
		parent::set_up();

		( new Migrator() )->run();
		update_option( Migrator::VERSION_OPTION, Migrator::CURRENT_VERSION );

		$this->cap = MediaCapabilities::EDITOR_MODERATION_CAPS[0];
		get_role( 'editor' )->add_cap( $this->cap );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE . '_gone' );
		// A rename, not a DROP: the WordPress test suite turns DROP TABLE into DROP TEMPORARY TABLE.
		$wpdb->query( "RENAME TABLE {$wpdb->prefix}" . self::TABLE . " TO {$wpdb->prefix}" . self::TABLE . '_gone' );
		// phpcs:enable
	}

	/**
	 * Put the table back.
	 */
	public function tear_down(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}" . self::TABLE );
		$wpdb->query( "RENAME TABLE {$wpdb->prefix}" . self::TABLE . "_gone TO {$wpdb->prefix}" . self::TABLE );
		// phpcs:enable
		delete_option( Migrator::LOCK_NAME . '.lock' );
		parent::tear_down();
	}

	/**
	 * Whether the table can be read again. A query, not SHOW TABLES: the WordPress test suite turns CREATE TABLE
	 * into CREATE TEMPORARY TABLE, which SHOW TABLES does not list.
	 */
	private function table_exists(): bool {
		global $wpdb;
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$readable = false !== $wpdb->query( "SELECT 1 FROM {$wpdb->prefix}" . self::TABLE . ' LIMIT 1' );
		$wpdb->suppress_errors( false );
		return $readable;
	}

	/**
	 * The repair rebuilds the table, leaves the version alone and leaves the owner's grant alone.
	 */
	public function test_a_repair_rebuilds_the_table_and_keeps_the_version_and_the_grant(): void {
		$missing = ( new Migrator() )->repair_missing_tables();

		$this->assertContains( self::TABLE, $missing );
		$this->assertTrue( $this->table_exists(), 'The missing table is rebuilt.' );
		$this->assertSame( Migrator::CURRENT_VERSION, (int) get_option( Migrator::VERSION_OPTION ), 'The stored version is not touched.' );
		$this->assertTrue( get_role( 'editor' )->has_cap( $this->cap ), "The owner's Editor grant is not taken back." );
	}

	/**
	 * A repair that cannot take the lock leaves nothing behind for the next request to resume.
	 */
	public function test_a_repair_that_loses_the_lock_leaves_the_version_for_the_next_request(): void {
		add_option( Migrator::LOCK_NAME . '.lock', time() );

		( new Migrator() )->repair_missing_tables();

		$this->assertSame( Migrator::CURRENT_VERSION, (int) get_option( Migrator::VERSION_OPTION ), 'The version is not reset to 0.' );

		// The other runner finishes; the ordinary request that follows has nothing to run, so v45 cannot take the grant back.
		delete_option( Migrator::LOCK_NAME . '.lock' );
		( new Migrator() )->run();
		$this->assertTrue( get_role( 'editor' )->has_cap( $this->cap ), "The owner's Editor grant survives the next request." );
		$this->assertFalse( get_transient( Migrator::SCHEMA_RECHECK_TRANSIENT ), 'It is checked again on the next admin request.' );
	}
}
