<?php
/**
 * Migrations run one runner at a time and record progress per step.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;

/**
 * Basecamp 10393276031 item 19.
 */
class MigratorLockTest extends WP_UnitTestCase {

	public function test_a_held_lock_stops_a_second_runner_and_a_stale_one_does_not(): void {
		$current = Migrator::CURRENT_VERSION;
		update_option( Migrator::VERSION_OPTION, $current - 1 );

		add_option( 'mvs_migrator_lock', time() );
		( new Migrator() )->run();
		$this->assertSame( $current - 1, (int) get_option( Migrator::VERSION_OPTION ), 'Ran while another runner held the lock.' );

		update_option( 'mvs_migrator_lock', time() - HOUR_IN_SECONDS );
		( new Migrator() )->run();
		$this->assertSame( $current, (int) get_option( Migrator::VERSION_OPTION ) );
		$this->assertFalse( get_option( 'mvs_migrator_lock' ), 'The lock must be released.' );
	}
}
