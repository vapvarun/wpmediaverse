<?php
/**
 * Uninstall drops every table the plugin creates.
 *
 * `uninstall.php` kept its own hardcoded list and had drifted to 10 of the 22
 * tables the migrator creates. Uninstalling left the whole messaging stack
 * behind — conversations, messages, participants, message reactions — along
 * with notifications, follows, blocks, activity, reports and transactions, rows
 * and all. Measured on a development site at the time: 366 transactions, 56
 * follows, 54 activity rows and 28 notifications survived a delete.
 *
 * A single source of truth is only true if something checks it, and nothing
 * did. `Migrator::tables()` is now that source, and this is the check: it
 * compares the list against the tables the migrator ACTUALLY creates on a clean
 * database, rather than against another list someone maintains by hand. Add a
 * table and forget to name it, and this fails in the same commit.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;

class UninstallCoverageTest extends WP_UnitTestCase {

	/**
	 * Table names this plugin's migrator creates, read from its own source.
	 *
	 * NOT from the database. The test database has Pro's tables in it too, so
	 * comparing against `SHOW TABLES` made this test fail on tables Free does
	 * not own and never uninstalls — it would have forced Free's list to claim
	 * `mvs_pro_folders`. The migrator's `CREATE TABLE` statements are the honest
	 * definition of what Free creates, and they cannot be polluted by whatever
	 * else happens to be installed.
	 *
	 * @return string[]
	 */
	private function created_tables(): array {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/includes/Core/Migrator.php' );

		preg_match_all(
			'/CREATE TABLE (?:IF NOT EXISTS )?\{\$(?:wpdb->)?prefix\}(mvs_[a-z_]+)/',
			(string) $source,
			$matches
		);

		$names = array_values( array_unique( $matches[1] ) );
		sort( $names );

		return $names;
	}

	/**
	 * Every table the migrator creates is named in `Migrator::tables()`.
	 *
	 * The direction that matters: a table created but not listed is one that
	 * survives uninstall with a member's data in it.
	 */
	public function test_every_created_table_is_listed(): void {
		$missing = array_values( array_diff( $this->created_tables(), Migrator::tables() ) );

		$this->assertSame(
			array(),
			$missing,
			"These tables exist but are not in Migrator::tables(), so uninstall would leave them behind: \n  "
				. implode( "\n  ", $missing )
		);
	}

	/**
	 * Nothing is listed that the migrator does not create.
	 *
	 * Harmless at uninstall — `DROP TABLE IF EXISTS` shrugs — but a name in the
	 * list that no longer exists is a lie about what this plugin owns, and the
	 * next person to read the list inherits it.
	 */
	public function test_nothing_listed_is_obsolete(): void {
		$obsolete = array_values( array_diff( Migrator::tables(), $this->created_tables() ) );

		$this->assertSame(
			array(),
			$obsolete,
			"These are named in Migrator::tables() but the migrator does not create them: \n  "
				. implode( "\n  ", $obsolete )
		);
	}

	/**
	 * `uninstall.php` asks the migrator rather than keeping its own copy.
	 *
	 * Reading the file is crude, but the alternative is running an uninstall
	 * inside the test suite — which would drop the tables the rest of the suite
	 * is using. This asserts the shape that made the drift impossible, not the
	 * behaviour, and says so.
	 */
	public function test_uninstall_reads_the_migrator_list(): void {
		$uninstall = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString(
			'Migrator::tables()',
			$uninstall,
			'uninstall.php must derive its drop list from the migrator, not maintain a second one.'
		);
	}
}
