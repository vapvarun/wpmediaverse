<?php
/**
 * Deleting the plugin keeps member data unless the owner opted in.
 *
 * Premium plugins are routinely updated by deleting and re-uploading them.
 * Before 2.6.0 that path dropped every MediaVerse table - media, albums,
 * messages - with no question asked. uninstall.php now clears scheduled work
 * and caches, and stops there unless mvs_delete_data_on_uninstall is '1'.
 *
 * Only the KEEP path runs here: the delete path drops tables, which would
 * break every later test in the run. It is covered by the Docker check noted
 * on the Data safety card.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class UninstallKeepsDataTest extends WP_UnitTestCase {

	public function test_uninstall_without_opt_in_keeps_settings_and_albums(): void {
		( new \WPMediaVerse\Core\Migrator() )->run();
		delete_option( 'mvs_delete_data_on_uninstall' );
		update_option( 'mvs_grid_columns', 4 );
		$album = self::factory()->post->create( array( 'post_type' => 'mvs_album' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wpmediaverse/wpmediaverse.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		// No table assertion: the WP test suite rewrites DROP TABLE as DROP
		// TEMPORARY TABLE, so the permanent test table always survives and a
		// table check could never fail. Options and posts are real rows, so
		// they carry the proof (verified by removing the gate: this failed).
		$this->assertSame( 4, (int) get_option( 'mvs_grid_columns' ), 'Uninstall without the opt-in deleted settings.' );
		$this->assertNotNull( get_post( $album ), 'Uninstall without the opt-in deleted albums.' );
	}
}
