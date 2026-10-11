<?php
/**
 * The data export lists the blocks and reports a member made, not the ones about them.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * The data export lists the blocks and reports a member made, not the ones about them.
 */
class GdprExportActorOnlyTest extends WP_UnitTestCase {

	public function test_export_does_not_name_who_blocked_or_reported_the_member(): void {
		global $wpdb;

		$member = self::factory()->user->create( array( 'role' => 'subscriber', 'user_email' => 'member@example.org' ) );
		$other  = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$wpdb->insert( $wpdb->prefix . 'mvs_blocks', array( 'blocker_id' => $other, 'blocked_id' => $member ) );
		$wpdb->insert( $wpdb->prefix . 'mvs_blocks', array( 'blocker_id' => $member, 'blocked_id' => $other ) );
		$wpdb->insert(
			$wpdb->prefix . 'mvs_reports',
			array( 'reporter_id' => $other, 'target_type' => 'user', 'target_id' => $member, 'reason' => 'spam', 'details' => 'secret note' )
		);

		$export = ( new \WPMediaVerse\Services\GDPRService() )->export_mapped( 'member@example.org' );
		$groups = wp_list_pluck( $export['data'], 'group_id' );

		$this->assertSame( 1, count( array_keys( $groups, 'mvs-mvs_blocks', true ) ), 'Only the block the member made is exported.' );
		$this->assertNotContains( 'mvs-mvs_reports', $groups, 'A report about the member must not be exported.' );
	}
}
