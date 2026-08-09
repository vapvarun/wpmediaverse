<?php
/**
 * Phase 3 — DocumentTypes: the only producer of the `document` media type.
 *
 * The property under test is negative and it is the important one: there is NO
 * default branch, so anything this class cannot name comes back null. A test
 * suite that only checked the happy path would pass just as well against a
 * class that ended `return 'pdf';`.
 *
 * Build plan: plan/document-library-build.md P3.1. Design: §3.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\DocumentTypes;

/**
 * @since 2.4.0
 */
class DocumentTypesTest extends WP_UnitTestCase {

	/**
	 * Files created during a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $temp_files = array();

	/**
	 * Clean up any archives a test wrote.
	 */
	public function tear_down(): void {
		foreach ( $this->temp_files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}
		$this->temp_files = array();
		parent::tear_down();
	}

	/**
	 * Build a zip archive containing the given entries.
	 *
	 * @param array<string,string> $entries name => contents.
	 * @return string Path to the archive.
	 */
	private function make_zip( array $entries ): string {
		$path               = wp_tempnam( 'mvs-doctype' ) . '.zip';
		$this->temp_files[] = $path;

		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();

		return $path;
	}

	/**
	 * Unambiguous MIME types resolve to their named type.
	 */
	public function test_resolves_named_types(): void {
		$this->assertSame( 'pdf', DocumentTypes::resolve( 'application/pdf', 'pdf' ) );
		$this->assertSame( 'word', DocumentTypes::resolve( 'application/msword', 'doc' ) );
		$this->assertSame( 'excel', DocumentTypes::resolve( 'application/vnd.ms-excel', 'xls' ) );
		$this->assertSame( 'rtf', DocumentTypes::resolve( 'application/rtf', 'rtf' ) );
	}

	/**
	 * THE headline property: no default branch.
	 *
	 * Anything unrecognised is null — never a document of unspecified kind.
	 */
	public function test_unknown_input_is_null_never_a_document(): void {
		$this->assertNull( DocumentTypes::resolve( 'image/jpeg', 'jpg' ) );
		$this->assertNull( DocumentTypes::resolve( 'video/mp4', 'mp4' ) );
		$this->assertNull( DocumentTypes::resolve( 'application/x-msdownload', 'exe' ) );
		$this->assertNull( DocumentTypes::resolve( 'application/octet-stream', 'bin' ) );
		$this->assertNull( DocumentTypes::resolve( '', '' ) );
		$this->assertNull( DocumentTypes::resolve( 'application/x-php', 'php' ) );
	}

	/**
	 * `.md` and `.csv` both sniff as text/plain — the extension separates them.
	 */
	public function test_extension_separates_the_text_family(): void {
		$this->assertSame( 'markdown', DocumentTypes::resolve( 'text/plain', 'md' ) );
		$this->assertSame( 'csv', DocumentTypes::resolve( 'text/plain', 'csv' ) );
		$this->assertSame( 'text', DocumentTypes::resolve( 'text/plain', 'txt' ) );
	}

	/**
	 * text/plain under a FOREIGN extension resolves to nothing.
	 *
	 * Reversed after the P3.4 ingest tests: falling back to `text` for any
	 * text/plain looked harmless and was not. A file called `photo.jpg` holding
	 * text was accepted as a text document — identification by elimination
	 * wearing a different hat, which is what this class exists to remove.
	 */
	public function test_text_plain_with_a_foreign_extension_is_not_a_document(): void {
		$this->assertNull( DocumentTypes::resolve( 'text/plain', 'log' ) );
		$this->assertNull( DocumentTypes::resolve( 'text/plain', 'exe' ) );
		$this->assertNull(
			DocumentTypes::resolve( 'text/plain', 'jpg' ),
			'A text file wearing an image extension must not become a document.'
		);
	}

	/**
	 * With NO extension there is nothing to contradict the sniffed type.
	 */
	public function test_text_plain_without_an_extension_is_text(): void {
		$this->assertSame( 'text', DocumentTypes::resolve( 'text/plain', '' ) );
	}

	/**
	 * A real .docx is admitted — the OOXML marker is present.
	 */
	public function test_real_ooxml_archive_is_admitted(): void {
		$path = $this->make_zip(
			array(
				'[Content_Types].xml' => '<?xml version="1.0"?><Types/>',
				'word/document.xml'   => '<w:document/>',
			)
		);

		$this->assertSame( 'word', DocumentTypes::resolve( 'application/zip', 'docx', $path ) );
	}

	/**
	 * A bare .zip renamed to .docx is REFUSED — no OOXML marker.
	 *
	 * This is the spoof the marker check exists to stop. An extension-only check
	 * would admit it.
	 */
	public function test_zip_renamed_to_docx_is_refused(): void {
		$path = $this->make_zip( array( 'holiday-photos/1.jpg' => 'not really a jpeg' ) );

		$this->assertNull(
			DocumentTypes::resolve( 'application/zip', 'docx', $path ),
			'An archive without the OOXML marker must not be admitted as a Word document.'
		);
	}

	/**
	 * A genuine .zip is never a document, marker or not.
	 */
	public function test_plain_zip_is_never_a_document(): void {
		$path = $this->make_zip( array( '[Content_Types].xml' => '<Types/>' ) );

		$this->assertNull(
			DocumentTypes::resolve( 'application/zip', 'zip', $path ),
			'.zip is not a document extension, so even a marker-bearing archive is refused.'
		);
	}

	/**
	 * ODF is verified by the CONTENTS of its mimetype entry, not its presence.
	 */
	public function test_odf_marker_contents_are_verified(): void {
		$good = $this->make_zip( array( 'mimetype' => 'application/vnd.oasis.opendocument.text' ) );
		$this->assertSame( 'odf_text', DocumentTypes::resolve( 'application/zip', 'odt', $good ) );

		// A mimetype entry naming a DIFFERENT ODF format than the extension.
		$mismatched = $this->make_zip( array( 'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet' ) );
		$this->assertNull(
			DocumentTypes::resolve( 'application/zip', 'odt', $mismatched ),
			'The declared ODF format must match the extension.'
		);

		// Present but meaningless — the check reads contents, so this fails.
		$empty = $this->make_zip( array( 'mimetype' => 'whatever' ) );
		$this->assertNull( DocumentTypes::resolve( 'application/zip', 'odt', $empty ) );
	}

	/**
	 * An unverifiable container is refused, not assumed.
	 */
	public function test_zip_container_without_a_readable_path_is_refused(): void {
		$this->assertNull(
			DocumentTypes::resolve( 'application/zip', 'docx' ),
			'Without the file there is no marker to read, so it cannot be admitted.'
		);
		$this->assertNull( DocumentTypes::resolve( 'application/zip', 'docx', '/no/such/file.docx' ) );
	}

	/**
	 * A MIME/extension disagreement resolves to null rather than picking a side.
	 */
	public function test_mime_and_extension_must_agree(): void {
		$this->assertNull(
			DocumentTypes::resolve( 'application/pdf', 'docx' ),
			'A PDF named .docx is a disagreement for the caller to answer, not one to paper over.'
		);
		$this->assertNull( DocumentTypes::resolve( 'application/msword', 'pdf' ) );
	}

	/**
	 * An unknown extension alongside a known MIME is accepted on the MIME.
	 */
	public function test_unknown_extension_defers_to_a_known_mime(): void {
		$this->assertSame( 'pdf', DocumentTypes::resolve( 'application/pdf', '' ) );
		$this->assertSame( 'pdf', DocumentTypes::resolve( 'application/pdf', 'download' ) );
	}

	/**
	 * Case and stray dots do not change the answer.
	 */
	public function test_input_is_normalised(): void {
		$this->assertSame( 'pdf', DocumentTypes::resolve( 'APPLICATION/PDF', '.PDF' ) );
		$this->assertSame( 'markdown', DocumentTypes::resolve( ' text/plain ', 'MD' ) );
	}

	/**
	 * group_for_mime maps stored MIMEs, and refuses non-document ones.
	 */
	public function test_group_for_mime(): void {
		$this->assertSame( 'pdf', DocumentTypes::group_for_mime( 'application/pdf' ) );
		$this->assertSame( 'odf_sheet', DocumentTypes::group_for_mime( 'application/vnd.oasis.opendocument.spreadsheet' ) );
		$this->assertNull( DocumentTypes::group_for_mime( 'image/png' ) );
	}

	/**
	 * Every type the maps produce is declared in ALL, and vice versa.
	 *
	 * Guards the drift where a MIME is added to the map but not to ALL, so
	 * is_known() then rejects a type resolve() just produced.
	 */
	public function test_vocabulary_is_internally_consistent(): void {
		foreach ( DocumentTypes::ALL as $type ) {
			$this->assertTrue( DocumentTypes::is_known( $type ) );
		}

		foreach ( DocumentTypes::allowed_mimes() as $mime ) {
			$type = DocumentTypes::group_for_mime( $mime );
			$this->assertNotNull( $type, "MIME {$mime} maps to nothing." );
			$this->assertTrue(
				DocumentTypes::is_known( $type ),
				"MIME {$mime} maps to '{$type}', which is not in ALL."
			);
		}
	}

	/**
	 * The allowlist is positive and contains no media types.
	 */
	public function test_allowed_mimes_never_include_media(): void {
		foreach ( DocumentTypes::allowed_mimes() as $mime ) {
			$this->assertStringStartsNotWith( 'image/', $mime );
			$this->assertStringStartsNotWith( 'video/', $mime );
			$this->assertStringStartsNotWith( 'audio/', $mime );
		}
	}

	// ------------------------------------------- production-MIME regressions --

	/**
	 * The marker check must fire on the MIME PRODUCTION actually supplies.
	 *
	 * Every zip test above passes `application/zip` — a value the real ingest
	 * path never produces. `DocumentIngestService::detect_mime()` returns
	 * `wp_check_filetype_and_ext()`, and for a non-image WordPress derives that
	 * from the EXTENSION. So a `.docx` arrived already claiming the Word MIME,
	 * skipped the zip branch entirely, and was admitted without the archive ever
	 * being opened. A file of pure garbage named `.docx` was accepted as a Word
	 * document over HTTP while this suite was green.
	 */
	public function test_garbage_named_docx_is_refused_under_the_production_mime(): void {
		$path = wp_tempnam( 'mvs-doctype' ) . '.docx';
		file_put_contents( $path, "this is not a docx, it is garbage bytes\x00\x01" );

		$this->assertNull(
			DocumentTypes::resolve(
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'docx',
				$path
			),
			'A non-archive named .docx must never be admitted.'
		);

		unlink( $path );
	}

	/**
	 * A real .docx is still admitted when the MIME arrives extension-derived.
	 */
	public function test_real_docx_is_admitted_under_the_production_mime(): void {
		$path = $this->make_zip( array( '[Content_Types].xml' => '<Types/>' ) );

		$this->assertSame(
			'word',
			DocumentTypes::resolve(
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'docx',
				$path
			)
		);
	}

	/**
	 * A container extension whose claimed MIME names a different format is a
	 * disagreement, not something to resolve in either direction.
	 */
	public function test_docx_claiming_pdf_mime_is_refused(): void {
		$path = $this->make_zip( array( '[Content_Types].xml' => '<Types/>' ) );

		$this->assertNull( DocumentTypes::resolve( 'application/pdf', 'docx', $path ) );
	}

	// ------------------------------------------------ markdown sniffed as HTML --

	/**
	 * Markdown carrying an HTML snippet inspects as text/html and is STILL
	 * markdown. A README with an <img> badge or a <details> block is the
	 * ordinary case, and refusing it is a false negative on a real document.
	 */
	public function test_markdown_sniffed_as_html_is_admitted(): void {
		$this->assertSame( 'markdown', DocumentTypes::resolve( 'text/html', 'md' ) );
	}

	/**
	 * An actual HTML file is not a document, with or without an extension.
	 */
	public function test_html_is_never_a_document(): void {
		$this->assertNull( DocumentTypes::resolve( 'text/html', 'html' ) );
		$this->assertNull(
			DocumentTypes::resolve( 'text/html', '' ),
			'"Sniffs as HTML, nothing to contradict it" describes an HTML file.'
		);
	}

	// -------------------------------------------------------- canonical MIME --

	/**
	 * The stored MIME must round-trip back to the resolved type.
	 *
	 * `doc_type` is not a stored column, so a MIME that groups to something else
	 * silently discards the ingest decision. Storing the `.md` sniff turned every
	 * Markdown upload into a plain-text document.
	 */
	public function test_canonical_mime_round_trips_every_type(): void {
		foreach ( DocumentTypes::ALL as $type ) {
			$mime = DocumentTypes::canonical_mime( $type );

			$this->assertNotNull( $mime, "{$type} must have a canonical MIME." );
			$this->assertSame(
				$type,
				DocumentTypes::group_for_mime( (string) $mime ),
				"{$type} must round-trip through its canonical MIME."
			);
		}
	}

	/**
	 * An unknown type has no canonical MIME rather than a guessed one.
	 */
	public function test_canonical_mime_of_an_unknown_type_is_null(): void {
		$this->assertNull( DocumentTypes::canonical_mime( 'spreadsheet-ish' ) );
	}
}
