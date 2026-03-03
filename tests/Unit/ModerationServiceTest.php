<?php
/**
 * Unit tests for ModerationService.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WPMediaVerse\Services\ModerationService;
use WPMediaVerse\Services\AIService;

class ModerationServiceTest extends TestCase {

	private ModerationService $service;
	private int $media_id;

	protected function setUp(): void {
		parent::setUp();

		$ai            = new AIService();
		$this->service = new ModerationService( $ai );

		// Create a test media post.
		$this->media_id = wp_insert_post(
			array(
				'post_type'   => 'mvs_media',
				'post_title'  => 'Test Moderation',
				'post_status' => 'publish',
			)
		);
	}

	protected function tearDown(): void {
		wp_delete_post( $this->media_id, true );
		parent::tearDown();
	}

	public function test_get_status_default_is_approved(): void {
		$this->assertSame( 'approved', $this->service->get_status( $this->media_id ) );
	}

	public function test_set_status_updates_meta(): void {
		$result = $this->service->set_status( $this->media_id, ModerationService::STATUS_FLAGGED );
		$this->assertTrue( $result );
		$this->assertSame( 'flagged', $this->service->get_status( $this->media_id ) );
	}

	public function test_set_status_rejects_invalid(): void {
		$result = $this->service->set_status( $this->media_id, 'invalid_status' );
		$this->assertFalse( $result );
	}

	public function test_set_status_logs_action(): void {
		$this->service->set_status( $this->media_id, ModerationService::STATUS_FLAGGED, 42 );
		$log = $this->service->get_log( $this->media_id );

		$this->assertCount( 1, $log );
		$this->assertSame( 'flagged', $log[0]['status'] );
		$this->assertSame( 42, $log[0]['user_id'] );
	}

	public function test_approve_sets_status_and_publishes(): void {
		// Set to flagged first.
		$this->service->set_status( $this->media_id, ModerationService::STATUS_FLAGGED );
		wp_update_post( array( 'ID' => $this->media_id, 'post_status' => 'draft' ) );

		$this->service->approve( $this->media_id, 1 );

		$this->assertSame( 'approved', $this->service->get_status( $this->media_id ) );
		$this->assertSame( 'publish', get_post_status( $this->media_id ) );
	}

	public function test_reject_sets_status_and_drafts(): void {
		$this->service->reject( $this->media_id, 1, 'Violates policy' );

		$this->assertSame( 'rejected', $this->service->get_status( $this->media_id ) );
		$this->assertSame( 'draft', get_post_status( $this->media_id ) );
		$this->assertSame( 'Violates policy', get_post_meta( $this->media_id, '_mvs_rejection_reason', true ) );
	}

	public function test_get_log_empty_by_default(): void {
		$this->assertSame( array(), $this->service->get_log( $this->media_id ) );
	}

	public function test_get_counts_structure(): void {
		$counts = $this->service->get_counts();
		$this->assertArrayHasKey( 'pending', $counts );
		$this->assertArrayHasKey( 'flagged', $counts );
		$this->assertArrayHasKey( 'rejected', $counts );
	}

	public function test_get_queue_returns_structure(): void {
		$this->service->set_status( $this->media_id, ModerationService::STATUS_FLAGGED );
		$result = $this->service->get_queue( array( 'status' => 'flagged' ) );

		$this->assertArrayHasKey( 'items', $result );
		$this->assertArrayHasKey( 'total', $result );
		$this->assertArrayHasKey( 'pages', $result );
		$this->assertGreaterThanOrEqual( 1, $result['total'] );
	}

	public function test_handle_flagged_flag_action(): void {
		update_option( 'mvs_moderation_auto_action', 'flag' );
		$this->service->handle_flagged(
			$this->media_id,
			array(
				'safe'       => false,
				'flags'      => array( 'nudity' ),
				'confidence' => 0.9,
			)
		);

		$this->assertSame( 'flagged', $this->service->get_status( $this->media_id ) );
	}

	public function test_handle_flagged_reject_action(): void {
		update_option( 'mvs_moderation_auto_action', 'reject' );
		$this->service->handle_flagged(
			$this->media_id,
			array(
				'safe'       => false,
				'flags'      => array( 'violence' ),
				'confidence' => 0.95,
			)
		);

		$this->assertSame( 'rejected', $this->service->get_status( $this->media_id ) );
		$this->assertSame( 'draft', get_post_status( $this->media_id ) );
	}

	public function test_handle_flagged_hide_action(): void {
		update_option( 'mvs_moderation_auto_action', 'hide' );
		$this->service->handle_flagged(
			$this->media_id,
			array(
				'safe'       => false,
				'flags'      => array( 'hate' ),
				'confidence' => 0.8,
			)
		);

		$this->assertSame( 'flagged', $this->service->get_status( $this->media_id ) );
		$this->assertSame( 'private', get_post_meta( $this->media_id, '_mvs_privacy', true ) );
	}
}
