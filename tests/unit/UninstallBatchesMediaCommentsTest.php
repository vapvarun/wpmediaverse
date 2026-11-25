<?php
/**
 * Removing the data deletes media comments in bounded batches.
 *
 * Two unbounded statements held core's comments table, and with it the site's own
 * comments, for the whole delete on a site with very many media comments.
 *
 * @package WPMediaVerse\Tests\Unit
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Migrator;

/**
 * Uninstall with the delete-data opt-in.
 */
class UninstallBatchesMediaCommentsTest extends WP_UnitTestCase {

	/**
	 * Every comments DELETE is bounded, there is more than one, nothing is left, and a site's own comments stay.
	 */
	public function test_media_comments_go_in_bounded_batches(): void {
		global $wpdb;

		( new Migrator() )->run();
		update_option( 'mvs_delete_data_on_uninstall', '1' );

		$post = self::factory()->post->create();
		$kept = self::factory()->comment->create( array( 'comment_post_ID' => $post ) );

		// 2,100 media comments with their meta: more than one batch of 2,000.
		$values = array();
		for ( $i = 0; $i < 2100; $i++ ) {
			$values[] = $wpdb->prepare( '(%d, %s, %s, %s, %s, %s)', $post, 'mvs_comment', 'media comment', '1', '2026-01-01 00:00:00', '2026-01-01 00:00:00' );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "INSERT INTO {$wpdb->comments} (comment_post_ID, comment_type, comment_content, comment_approved, comment_date, comment_date_gmt) VALUES " . implode( ',', $values ) );
		$wpdb->query( "INSERT INTO {$wpdb->commentmeta} (comment_id, meta_key, meta_value) SELECT comment_ID, 'mvs_media_id', '7' FROM {$wpdb->comments} WHERE comment_type = 'mvs_comment'" );
		// phpcs:enable

		$deletes = array();
		$capture = static function ( $sql ) use ( &$deletes, $wpdb ) {
			if ( 0 === strpos( ltrim( (string) $sql ), "DELETE FROM {$wpdb->comments}" ) ) {
				$deletes[] = (string) $sql;
			}
			return $sql;
		};
		add_filter( 'query', $capture );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'wpmediaverse/wpmediaverse.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core's own constant.
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
		remove_filter( 'query', $capture );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = 'mvs_comment'" );
		$this->assertSame( 0, $left, 'Every media comment is removed.' );
		$this->assertNotNull( get_comment( $kept ), "The site's own comments stay." );
		$this->assertGreaterThanOrEqual( 2, count( $deletes ), 'More than one batch.' );
		foreach ( $deletes as $sql ) {
			$this->assertSame( 1, preg_match( '/comment_ID IN \(([^)]*)\)/', $sql, $m ), 'Each delete names its ids: ' . substr( $sql, 0, 80 ) );
			$this->assertLessThanOrEqual( 2000, count( explode( ',', $m[1] ) ), 'No statement removes more than one batch.' );
		}
	}
}
