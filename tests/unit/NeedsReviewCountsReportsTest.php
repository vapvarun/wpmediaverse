<?php
/**
 * The "Needs review" number counts pending member reports.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Services\ModerationService;

/**
 * Basecamp 10364778984 (QA bounce): with five pending reports and no flagged
 * media, the Overview card said "0 Needs review" and the menu had no badge.
 */
class NeedsReviewCountsReportsTest extends WP_UnitTestCase {

	private function needs_review(): int {
		return ModerationService::needs_review( Plugin::container()->get( 'moderation' )->get_counts() );
	}

	public function test_a_pending_report_raises_the_number_and_acting_on_it_lowers_it(): void {
		$owner    = self::factory()->user->create();
		$reporter = self::factory()->user->create();
		$media_id = (int) Plugin::container()->get( 'media_repository' )->insert(
			array(
				'title'       => 'Reported item',
				'post_author' => $owner,
				'privacy'     => 'public',
			)
		);
		$reports  = Plugin::container()->get( 'reports' );
		$before   = $this->needs_review();

		set_transient( 'mvs_moderation_counts', array( 'pending' => 0 ), 60 );
		$report_id = $reports->report( $reporter, 'media', $media_id, 'spam' );
		$this->assertIsInt( $report_id );

		$this->assertSame( $before + 1, $this->needs_review() );
		$this->assertFalse( get_transient( 'mvs_moderation_counts' ), 'the cached menu badge is dropped when a report arrives' );

		set_transient( 'mvs_moderation_counts', array( 'pending' => 0 ), 60 );
		$reports->update_status( $report_id, 'dismissed' );

		$this->assertSame( $before, $this->needs_review() );
		$this->assertFalse( get_transient( 'mvs_moderation_counts' ), 'and when it is dismissed' );
	}
}
