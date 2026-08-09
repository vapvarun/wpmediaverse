<?php
/**
 * P2.1 — the catch-all removal.
 *
 * `get_media_type()` used to return 'document' for anything that was not
 * image/video/audio — an allowlist inverted. With documents becoming a real
 * library that fallback would manufacture documents out of every unrecognised
 * upload, so unknown now resolves to '' and is refused at ingest.
 *
 * Build plan: plan/document-library-build.md P2.1
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Plugin;

/**
 * @since 2.4.0
 */
class MediaTypeResolutionTest extends WP_UnitTestCase {

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
	 * Every media MIME still resolves to its own type.
	 */
	public function test_media_mimes_still_resolve(): void {
		$this->assertSame( 'image', $this->upload->get_media_type_public( 'image/jpeg' ) );
		$this->assertSame( 'image', $this->upload->get_media_type_public( 'image/webp' ) );
		$this->assertSame( 'video', $this->upload->get_media_type_public( 'video/mp4' ) );
		$this->assertSame( 'audio', $this->upload->get_media_type_public( 'audio/mpeg' ) );
	}

	/**
	 * The headline: an unrecognised MIME resolves to '' and NOT to 'document'.
	 */
	public function test_unknown_mime_is_empty_not_document(): void {
		foreach ( array( 'application/pdf', 'application/zip', 'text/plain', 'application/octet-stream', '' ) as $mime ) {
			$this->assertSame(
				'',
				$this->upload->get_media_type_public( $mime ),
				"{$mime} resolved to a type it should not have."
			);
		}
	}

	/**
	 * An unrecognised MIME is refused at ingest.
	 *
	 * This is the half that had to change in the same edit: the old guard tested
	 * `'document' === $type`, which only caught unknown files because unknown
	 * files were NAMED 'document'. Changing the fallback alone would have opened
	 * ingest to every unrecognised type.
	 */
	public function test_unknown_mime_is_refused_at_ingest(): void {
		$refusal = $this->upload->reject_unsupported_mime( 'application/zip' );

		$this->assertInstanceOf( '\WP_Error', $refusal, 'An unrecognised MIME was accepted.' );
		$this->assertSame( 'mvs_unsupported_file_type', $refusal->get_error_code() );
	}

	/**
	 * PDF keeps its own error code, which clients already branch on.
	 */
	public function test_pdf_keeps_its_established_error_code(): void {
		$refusal = $this->upload->reject_unsupported_mime( 'application/pdf' );

		$this->assertInstanceOf( '\WP_Error', $refusal );
		$this->assertSame( 'mvs_document_not_supported', $refusal->get_error_code() );
	}

	/**
	 * Supported media passes the guard.
	 */
	public function test_media_is_not_refused(): void {
		$this->assertNull( $this->upload->reject_unsupported_mime( 'image/jpeg' ) );
		$this->assertNull( $this->upload->reject_unsupported_mime( 'video/mp4' ) );
	}

	/**
	 * The Production Rule 3 escape hatch restores the old behaviour.
	 */
	public function test_filter_can_override_the_resolution(): void {
		$fn = static function ( $type, $mime ) {
			return ( '' === $type && 'application/zip' === $mime ) ? 'document' : $type;
		};
		add_filter( 'mvs_media_type_for_mime', $fn, 10, 2 );

		$this->assertSame( 'document', $this->upload->get_media_type_public( 'application/zip' ) );

		remove_filter( 'mvs_media_type_for_mime', $fn, 10 );
	}

	/**
	 * …but the filter cannot smuggle in a type the plugin does not recognise.
	 *
	 * A value outside MediaTypes::ALL would defeat every positive type predicate
	 * downstream — the row would belong to no library and appear on no surface.
	 */
	public function test_filter_cannot_return_an_unknown_type(): void {
		$fn = static function () {
			return 'nonsense';
		};
		add_filter( 'mvs_media_type_for_mime', $fn );

		$this->assertSame( '', $this->upload->get_media_type_public( 'application/zip' ), 'A bogus type reached the resolver.' );
		$this->assertSame( 'image', $this->upload->get_media_type_public( 'image/png' ), 'A bogus filter broke a valid resolution.' );

		remove_filter( 'mvs_media_type_for_mime', $fn );
	}
}
