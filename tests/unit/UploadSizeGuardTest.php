<?php
/**
 * Basecamp 10350220155 — the max-upload-size guard, shared by every write path.
 *
 * `UploadService::handle()` checked a file's server-measured size against
 * `mvs_max_upload_size`; `MediaController::replace_file()` did not, so a size
 * cap the owner set could be bypassed by replacing an existing item's file
 * instead of uploading a new one. The check was extracted into
 * `reject_oversized_file()` so both write paths run the identical guard —
 * the same shape as `reject_unsupported_mime()` (see MediaTypeResolutionTest),
 * fixed for exactly this bug class in 2026-06-04 (#9962125462).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.6.0
 */
class UploadSizeGuardTest extends WP_UnitTestCase {

	/**
	 * Upload service under test.
	 *
	 * @var \WPMediaVerse\Services\UploadService
	 */
	private $upload;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		$this->upload = Plugin::container()->get( 'upload' );
	}

	/**
	 * A file over the limit is refused, with the established error code.
	 */
	public function test_oversized_file_is_refused(): void {
		$fn = static function () {
			return 50; // 50 bytes.
		};
		add_filter( 'mvs_max_upload_size', $fn );

		$refusal = $this->upload->reject_oversized_file( 51, 0 );

		remove_filter( 'mvs_max_upload_size', $fn );

		$this->assertInstanceOf( '\WP_Error', $refusal, 'A file over the limit was accepted.' );
		$this->assertSame( 'mvs_file_too_large', $refusal->get_error_code() );
	}

	/**
	 * A file at or under the limit passes.
	 */
	public function test_file_within_limit_is_not_refused(): void {
		$fn = static function () {
			return 50;
		};
		add_filter( 'mvs_max_upload_size', $fn );

		$result = $this->upload->reject_oversized_file( 50, 0 );

		remove_filter( 'mvs_max_upload_size', $fn );

		$this->assertNull( $result );
	}

	/**
	 * An unmeasurable size (filesize() returned false) is refused, not treated as 0 bytes.
	 */
	public function test_unmeasurable_size_is_refused(): void {
		$refusal = $this->upload->reject_oversized_file( false, 0 );

		$this->assertInstanceOf( '\WP_Error', $refusal );
		$this->assertSame( 'mvs_file_too_large', $refusal->get_error_code() );
	}
}
