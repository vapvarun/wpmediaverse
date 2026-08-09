<?php
/**
 * The only producer of the `document` media type.
 *
 * MediaVerse is a media plugin first. Document support is additive and must be
 * asked for EXPLICITLY: no code path may reach a document type by elimination.
 * `UploadService::get_media_type()` used to end `return 'document';` — anything
 * unrecognised became a document — and that inversion is what this class exists
 * to make impossible. `resolve()` returns a NAMED type or `null`. There is no
 * default branch, and adding one would defeat the whole design.
 *
 * WHY THIS LIVES IN FREE, not Pro, when the document library is a Pro feature:
 * the ingest path is `Services\UploadService`, which is Free's, and Free must
 * never depend on Pro (Coding Rule 10 runs one way). The vocabulary and the
 * resolution therefore sit beside `Core\MediaTypes`, which is the same kind of
 * thing for the same reason. Pro owns the *engine* — folders, permissions,
 * delivery — and calls in here freely.
 *
 * Design: plan/document-library.md §3. Build plan: P3.1.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Named document types and the resolution that produces them.
 *
 * @since 2.4.0
 */
final class DocumentTypes {

	/**
	 * MIME type => named document type.
	 *
	 * @since 2.4.0
	 * @var array<string, string>
	 */
	private const BY_MIME = array(
		'application/pdf'                                                           => 'pdf',
		'application/msword'                                                        => 'word',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document'   => 'word',
		'application/vnd.ms-excel'                                                  => 'excel',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'         => 'excel',
		'application/vnd.ms-powerpoint'                                             => 'powerpoint',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'powerpoint',
		'application/vnd.oasis.opendocument.text'                                   => 'odf_text',
		'application/vnd.oasis.opendocument.spreadsheet'                            => 'odf_sheet',
		'application/vnd.oasis.opendocument.presentation'                           => 'odf_presentation',
		'text/plain'                                                                => 'text',
		'text/markdown'                                                             => 'markdown',
		'text/csv'                                                                  => 'csv',
		'application/rtf'                                                           => 'rtf',
		'text/rtf'                                                                  => 'rtf',
	);

	/**
	 * Extension => named document type.
	 *
	 * Needed in both directions. `.md` and `.csv` both sniff as `text/plain`, so
	 * the MIME alone cannot tell them apart; and the ZIP-container formats sniff
	 * as `application/zip`, so the extension is what says which one to expect.
	 * Resolution takes both arguments and trusts neither alone.
	 *
	 * @since 2.4.0
	 * @var array<string, string>
	 */
	private const BY_EXTENSION = array(
		'pdf'  => 'pdf',
		'doc'  => 'word',
		'docx' => 'word',
		'xls'  => 'excel',
		'xlsx' => 'excel',
		'ppt'  => 'powerpoint',
		'pptx' => 'powerpoint',
		'odt'  => 'odf_text',
		'ods'  => 'odf_sheet',
		'odp'  => 'odf_presentation',
		'txt'  => 'text',
		'md'   => 'markdown',
		'csv'  => 'csv',
		'rtf'  => 'rtf',
	);

	/**
	 * Extensions whose files are ZIP containers.
	 *
	 * `finfo` reports `application/zip` for every one of them, so a zip is only
	 * admitted when the extension is in this map AND the archive carries the
	 * marker the format requires. A bare `.zip` never resolves, because `zip` is
	 * not an extension this class knows.
	 *
	 * @since 2.4.0
	 * @var array<string, string> extension => 'ooxml'|'odf'
	 */
	private const ZIP_CONTAINERS = array(
		'docx' => 'ooxml',
		'xlsx' => 'ooxml',
		'pptx' => 'ooxml',
		'odt'  => 'odf',
		'ods'  => 'odf',
		'odp'  => 'odf',
	);

	/**
	 * MIME types that report as a zip container.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	private const ZIP_MIMES = array( 'application/zip', 'application/x-zip-compressed' );

	/**
	 * Text MIME types that cannot distinguish their own subtypes.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	private const AMBIGUOUS_TEXT_MIMES = array( 'text/plain' );

	/**
	 * Every named document type.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const ALL = array(
		'pdf',
		'word',
		'excel',
		'powerpoint',
		'odf_text',
		'odf_sheet',
		'odf_presentation',
		'text',
		'markdown',
		'csv',
		'rtf',
	);

	/**
	 * Resolve a file to a named document type, or null.
	 *
	 * NO DEFAULT BRANCH. Every return is either a name from `ALL` or `null`, and
	 * `null` means "this is not a document", never "this is a document of some
	 * kind we could not name".
	 *
	 * @since 2.4.0
	 *
	 * @param string $mime      Detected MIME type.
	 * @param string $extension File extension, without the dot. Case-insensitive.
	 * @param string $file_path Optional path on disk. Required to admit a ZIP
	 *                          container: without it the marker cannot be read
	 *                          and the file is refused rather than assumed.
	 * @return string|null Named type, or null when this is not a document.
	 */
	public static function resolve( string $mime, string $extension, string $file_path = '' ): ?string {
		$mime      = strtolower( trim( $mime ) );
		$extension = strtolower( ltrim( trim( $extension ), '.' ) );

		// 1. ZIP containers. OOXML and ODF are zip archives, so the MIME says
		// "zip" and tells us nothing. Admit only when the extension names a
		// container format AND the archive carries that format's marker.
		if ( in_array( $mime, self::ZIP_MIMES, true ) ) {
			return self::resolve_zip_container( $extension, $file_path );
		}

		// 2. Ambiguous text. `.md` and `.csv` both sniff as text/plain, so the
		// extension decides — but ONLY within the text family.
		//
		// An earlier version fell back to `text` for any text/plain whatever the
		// extension. That looks harmless and is not: a file called `photo.jpg`
		// holding text would be stored as a text document, which is
		// identification by elimination wearing a different hat — the exact thing
		// this class exists to remove. Caught by the P3.4 ingest tests, where a
		// `.jpg` was accepted as a document.
		//
		// So an unrecognised extension on text/plain resolves to nothing. An
		// ABSENT extension still resolves to `text`, because there is nothing to
		// contradict the sniffed type.
		if ( in_array( $mime, self::AMBIGUOUS_TEXT_MIMES, true ) ) {
			if ( '' === $extension ) {
				return 'text';
			}

			$by_ext = self::BY_EXTENSION[ $extension ] ?? null;

			return in_array( $by_ext, array( 'text', 'markdown', 'csv' ), true ) ? $by_ext : null;
		}

		// 3. Unambiguous MIME.
		$by_mime = self::BY_MIME[ $mime ] ?? null;
		if ( null === $by_mime ) {
			return null;
		}

		// The extension must agree when it is one this class knows. A `.pdf`
		// named `.docx` is a disagreement the caller has to resolve, not
		// something to paper over — P3.4 answers 400 rather than silently
		// correcting either side.
		$by_ext = self::BY_EXTENSION[ $extension ] ?? null;
		if ( null !== $by_ext && $by_ext !== $by_mime ) {
			return null;
		}

		return $by_mime;
	}

	/**
	 * Resolve a zip-sniffed file by reading the marker its format requires.
	 *
	 * The check runs in BOTH directions, which is the point: the extension says
	 * which format to expect, and the archive has to actually contain that
	 * format's marker. Either half alone is trivially spoofed — a renamed `.zip`
	 * passes an extension-only check, and a real `.docx` fails a marker-only
	 * check that does not know what to look for.
	 *
	 * @since 2.4.0
	 *
	 * @param string $extension Lower-case extension without the dot.
	 * @param string $file_path Path on disk.
	 * @return string|null
	 */
	private static function resolve_zip_container( string $extension, string $file_path ): ?string {
		$family = self::ZIP_CONTAINERS[ $extension ] ?? null;
		if ( null === $family ) {
			// A bare .zip, or any archive whose extension is not a container
			// format this class knows. Not a document.
			return null;
		}

		if ( '' === $file_path || ! is_readable( $file_path ) || ! class_exists( '\ZipArchive' ) ) {
			// Cannot verify, so do not admit. Refusing an unverifiable file is
			// the whole reason this class has no default branch.
			return null;
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file_path ) ) {
			return null;
		}

		if ( 'ooxml' === $family ) {
			$has_marker = false !== $zip->locateName( '[Content_Types].xml' );
		} else {
			// ODF stores an uncompressed `mimetype` entry whose contents name the
			// exact format, so verify the contents too rather than the presence
			// of a file anyone could add.
			$has_marker = false;
			if ( false !== $zip->locateName( 'mimetype' ) ) {
				$declared   = (string) $zip->getFromName( 'mimetype' );
				$has_marker = ( self::BY_MIME[ trim( $declared ) ] ?? null ) === self::BY_EXTENSION[ $extension ];
			}
		}

		$zip->close();

		return $has_marker ? self::BY_EXTENSION[ $extension ] : null;
	}

	/**
	 * Map an already-admitted file's stored MIME to its display group.
	 *
	 * Grouping a file the plugin has already accepted is not inference — the
	 * admission decision was made once, at ingest, by `resolve()`. `doc_type` is
	 * deliberately NOT a stored column: `file_type` already holds the validated
	 * MIME, and `KEY type_file (media_type, file_type)` makes "every PDF in this
	 * drive" an index range scan.
	 *
	 * @since 2.4.0
	 *
	 * @param string $mime Stored MIME type.
	 * @return string|null Named type, or null when the MIME is not a document MIME.
	 */
	public static function group_for_mime( string $mime ): ?string {
		return self::BY_MIME[ strtolower( trim( $mime ) ) ] ?? null;
	}

	/**
	 * Whether a string is a document type this plugin names.
	 *
	 * @since 2.4.0
	 *
	 * @param string $type Candidate type.
	 * @return bool
	 */
	public static function is_known( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}

	/**
	 * Every MIME type the document library accepts.
	 *
	 * Used by the upload allowlist and by Site Health. Returned as a positive
	 * list, never as "everything except".
	 *
	 * @since 2.4.0
	 *
	 * @return string[]
	 */
	public static function allowed_mimes(): array {
		return array_keys( self::BY_MIME );
	}
}
