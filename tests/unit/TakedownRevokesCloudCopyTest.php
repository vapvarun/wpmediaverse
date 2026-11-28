<?php
/**
 * A taken-down item loses its CDN copy, not only its local file names.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

/**
 * Rejecting, flagging or trashing a public cloud item queues the trip home that deletes the CDN copy.
 */
class TakedownRevokesCloudCopyTest extends WP_UnitTestCase {

	private const JOB = 'mvs_cloud_repatriate_media';

	public function test_takedown_queues_cloud_repatriation_only_on_a_cloud_driver(): void {
		update_option( 'mvs_storage_driver', 'local' );
		do_action( 'mvs_moderation_changed', 9101, 'rejected', 'approved', 1 );
		$this->assertFalse( as_has_scheduled_action( self::JOB, array( 'media_id' => 9101 ) ), 'Local storage has no cloud copy to bring home.' );

		update_option( 'mvs_storage_driver', 'bunnycdn' );
		do_action( 'mvs_moderation_changed', 9102, 'rejected', 'approved', 1 );
		do_action( 'mvs_media_flagged', 9103, array() );
		do_action( 'mvs_media_trashed', 9104, 1, '' );
		do_action( 'mvs_moderation_changed', 9105, 'rejected', 'pending', 1 );

		$this->assertTrue( as_has_scheduled_action( self::JOB, array( 'media_id' => 9102 ) ), 'A rejected approved item is brought home.' );
		$this->assertTrue( as_has_scheduled_action( self::JOB, array( 'media_id' => 9103 ) ), 'A flagged item is brought home.' );
		$this->assertTrue( as_has_scheduled_action( self::JOB, array( 'media_id' => 9104 ) ), 'A trashed item is brought home.' );
		$this->assertFalse( as_has_scheduled_action( self::JOB, array( 'media_id' => 9105 ) ), 'An item that was never approved was never public.' );
	}
}
