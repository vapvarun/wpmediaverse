<?php
/**
 * One message-attachment limit, told the same way by server and browser.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Messaging\MessagingController;

/**
 * The browser refuses an over-limit attachment before upload using the limit
 * and message the server enforces, so a 60 MB video is refused at once instead
 * of after a minute of uploading.
 */
class MessageAttachmentLimitTest extends WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'mvs_dm_max_upload_size' );
		parent::tear_down();
	}

	public function test_default_limit_and_message_name_the_size(): void {
		$this->assertSame( 10 * MB_IN_BYTES, MessagingController::max_attachment_size() );
		$this->assertStringContainsString( size_format( 10 * MB_IN_BYTES ), MessagingController::attachment_too_large_message() );
	}

	public function test_filter_changes_limit_and_message_together(): void {
		add_filter( 'mvs_dm_max_upload_size', static fn() => 25 * MB_IN_BYTES );

		$this->assertSame( 25 * MB_IN_BYTES, MessagingController::max_attachment_size() );
		$this->assertStringContainsString( size_format( 25 * MB_IN_BYTES ), MessagingController::attachment_too_large_message() );
	}
}
