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
	 * MIME types a text-family document is routinely mis-sniffed as.
	 *
	 * Markdown is the case that matters: a README carrying an `<img>` badge or a
	 * `<details>` block inspects as HTML, because by content it IS HTML. Those
	 * files are ordinary Markdown and refusing them is a false negative on a
	 * legitimate document.
	 *
	 * Unlike `AMBIGUOUS_TEXT_MIMES` these NEVER resolve on an absent extension.
	 * "Sniffs as HTML, no extension to contradict it" describes an HTML file, and
	 * this library does not store those.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	private const HTML_SNIFFED_MIMES = array( 'text/html', 'text/x-markdown' );

	/**
	 * The text family — the types resolvable from a text-ish sniff.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	private const TEXT_FAMILY = array( 'text', 'markdown', 'csv' );

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
		//
		// Dispatch is driven by the EXTENSION, not by the claimed MIME, and that
		// is the whole point. An earlier version entered this branch only when
		// the MIME sniffed as zip — which never happened in production, because
		// every caller gets its MIME from `wp_check_filetype_and_ext()`, and for
		// a non-image WordPress derives that from the extension. So `.docx`
		// arrived already claiming the Word MIME, fell through to the
		// unambiguous-MIME branch, and was admitted without the archive ever
		// being opened. A file of pure garbage named `.docx` was accepted as a
		// Word document. The marker check was unreachable for exactly the case
		// it exists to catch. Found by uploading such a file over HTTP; no unit
		// test caught it because the tests passed a sniffed MIME the real ingest
		// path never produces.
		$zip_family = self::ZIP_CONTAINERS[ $extension ] ?? null;
		if ( null !== $zip_family ) {
			// The claimed MIME still has to agree: either it sniffed as a bare
			// archive, or it is the exact MIME this extension maps to. A `.docx`
			// claiming to be a PDF is a disagreement, not something to resolve.
			$expected = self::BY_EXTENSION[ $extension ] ?? null;
			if ( ! in_array( $mime, self::ZIP_MIMES, true )
				&& ( self::BY_MIME[ $mime ] ?? null ) !== $expected ) {
				return null;
			}

			return self::resolve_zip_container( $extension, $file_path );
		}

		// A zip-sniffed file whose extension is not a container format is a plain
		// archive. Not a document.
		if ( in_array( $mime, self::ZIP_MIMES, true ) ) {
			return null;
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

			return in_array( $by_ext, self::TEXT_FAMILY, true ) ? $by_ext : null;
		}

		// 2b. Text-family files mis-sniffed as HTML. The extension is the only
		// thing that can admit these, and an absent extension admits nothing.
		if ( in_array( $mime, self::HTML_SNIFFED_MIMES, true ) ) {
			$by_ext = self::BY_EXTENSION[ $extension ] ?? null;

			return in_array( $by_ext, self::TEXT_FAMILY, true ) ? $by_ext : null;
		}

		// 2c. libmagic declined to classify the contents at all.
		//
		// `application/octet-stream` is not a positive identification, it is "I
		// don't know" — and libmagic answers it for any text file whose body is
		// one long low-entropy run once it passes ~64 KiB (measured: 65,535
		// bytes text/plain, 65,536 octet-stream). A member's padded log or
		// repeated-token dump was therefore refused as an unsupported TYPE while
		// a smaller file with the same extension and the same content uploaded
		// fine, and the message named the wrong reason (Basecamp 10190462935).
		//
		// The strictness above stays exactly where it belongs: when the sniff is
		// a POSITIVE answer that contradicts the extension, that is a disguised
		// file and still resolves to null. This branch only covers the case
		// where there is no answer to contradict.
		//
		// The extension alone does not admit it — `looks_textual()` still has to
		// agree, so a real binary renamed `.txt` is refused as it was before.
		if ( 'application/octet-stream' === $mime ) {
			$by_ext = self::BY_EXTENSION[ $extension ] ?? null;

			if ( ! in_array( $by_ext, self::TEXT_FAMILY, true ) ) {
				return null;
			}

			return self::looks_textual( $file_path ) ? $by_ext : null;
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
	 * Whether a file's opening bytes are consistent with text.
	 *
	 * The guard on the `application/octet-stream` branch in `resolve()`. Reads a
	 * single 8 KiB block and rejects it on a NUL byte — the one thing that never
	 * appears in a UTF-8/ASCII text file and appears almost immediately in every
	 * real binary (executables, images, archives all carry NULs in their header).
	 *
	 * Deliberately a cheap negative test, not a charset validation: the question
	 * here is only "is this plausibly the text file the extension claims", and
	 * the sniff has already admitted it cannot tell. A stricter test would start
	 * refusing legitimate files with odd encodings, which is the bug this whole
	 * branch exists to fix.
	 *
	 * @since 2.4.0
	 *
	 * @param string $file_path Path on disk. An unreadable path admits nothing.
	 * @return bool
	 */
	private static function looks_textual( string $file_path ): bool {
		if ( '' === $file_path || ! is_readable( $file_path ) ) {
			return false;
		}

		$handle = fopen( $file_path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		$head = fread( $handle, 8192 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $head || '' === $head ) {
			// An empty file is a legitimate empty document; there is nothing
			// binary about it.
			return true;
		}

		return ! str_contains( $head, "\0" );
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
	 * Human label for a named type.
	 *
	 * The internal keys are snake_case identifiers — printing them raw gave the
	 * public filter row chips reading "ODF_PRESENTATION", which is a database
	 * value wearing a button. Translators get a real word to translate, too.
	 *
	 * @since 2.4.0
	 *
	 * @param string $type Named document type.
	 * @return string
	 */
	public static function label( string $type ): string {
		$labels = array(
			'pdf'              => __( 'PDF', 'wpmediaverse' ),
			'word'             => __( 'Word', 'wpmediaverse' ),
			'excel'            => __( 'Excel', 'wpmediaverse' ),
			'powerpoint'       => __( 'PowerPoint', 'wpmediaverse' ),
			'odf_text'         => __( 'ODF Text', 'wpmediaverse' ),
			'odf_sheet'        => __( 'ODF Sheet', 'wpmediaverse' ),
			'odf_presentation' => __( 'ODF Slides', 'wpmediaverse' ),
			'text'             => __( 'Text', 'wpmediaverse' ),
			'markdown'         => __( 'Markdown', 'wpmediaverse' ),
			'csv'              => __( 'CSV', 'wpmediaverse' ),
			'rtf'              => __( 'RTF', 'wpmediaverse' ),
		);

		return $labels[ $type ] ?? ucfirst( str_replace( '_', ' ', $type ) );
	}

	/**
	 * The MIME this library stores for a named type.
	 *
	 * `group_for_mime()` is the only way anything downstream learns a document's
	 * type, because `doc_type` is not a stored column. That makes the stored MIME
	 * load-bearing: it has to round-trip back to the type ingest resolved.
	 *
	 * A raw sniff does not always round-trip. `.md` inspects as `text/plain`
	 * (or as `text/html` when it contains a badge or a `<details>` block), so
	 * storing the sniff turned every Markdown upload into a plain-text document
	 * and the Markdown renderer was never reached. Ingest stores this instead
	 * whenever the sniff disagrees with the resolved type.
	 *
	 * @since 2.4.0
	 *
	 * @param string $type Named document type.
	 * @return string|null Canonical MIME, or null when the type is not known.
	 */
	public static function canonical_mime( string $type ): ?string {
		$mime = array_search( $type, self::BY_MIME, true );

		return false === $mime ? null : (string) $mime;
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
