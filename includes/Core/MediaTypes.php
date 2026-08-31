<?php
/**
 * The canonical media_type vocabulary.
 *
 * `mvs_media_index` holds every library item, and `media_type` is the column that
 * says which library an item belongs to. This class is the single source for that
 * vocabulary — admin, REST and the services all read it here rather than each
 * carrying its own list.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical media_type groups.
 *
 * @since 2.4.0
 */
final class MediaTypes {

	/**
	 * Types that belong to the media library.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const MEDIA = array( 'image', 'video', 'audio' );

	/**
	 * Types that belong to the document library.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const DOCUMENTS = array( 'document' );

	/**
	 * Every type this plugin recognises.
	 *
	 * Anything not listed here — an empty string, or the `legacy_document` rows the
	 * pre-1.2.3 catch-all produced — belongs to NEITHER library. That is deliberate:
	 * an untyped row is a data defect, not content, and a surface that shows it is
	 * showing a bug to a member.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const ALL = array( 'image', 'video', 'audio', 'document' );

	/**
	 * Rows the pre-1.2.3 catch-all classified by elimination.
	 *
	 * `UploadService::get_media_type()` used to return `'document'` for anything that
	 * was not image/video/audio — the inverse of an allowlist. Migrator v26 re-types
	 * those rows to this value so they can be told apart from documents a member
	 * deliberately uploaded. They stay readable at their permalink and appear in no
	 * library listing.
	 *
	 * @since 2.4.0
	 * @var string
	 */
	public const LEGACY_DOCUMENT = 'legacy_document';

	/**
	 * What the MEDIA LIBRARY shows. Media, and only media.
	 *
	 * **REVERSED BY THE OWNER, 2026-08-09: "documents type will never display at
	 * media grid."** This constant previously also carried `legacy_document`, on
	 * the reasoning that hiding those rows would remove content a member can see
	 * today. That reasoning was sound in the abstract and wrong in practice, and
	 * the browser showed why: the quarantined PDF rendered in Explore as a
	 * **broken image tile** — a dark rectangle with a missing-image glyph. A grid
	 * is a grid of pictures. A PDF has no picture, so "keeping it visible" did not
	 * keep anything visible; it published a defect.
	 *
	 * **Absent beats broken.** These rows stay fully readable at their permalink,
	 * stay in the database, and stay countable — they simply stop being offered to
	 * surfaces that can only draw them wrong.
	 *
	 * They are still NOT in `DOCUMENTS`: they never passed
	 * `DocumentTypes::resolve()`, have no folder and no extraction, so the
	 * document library must not claim them either. Adopting one is a deliberate,
	 * owner-initiated migration, never an upgrade side effect. `legacy_document`
	 * is a quarantine, and a quarantine belongs to neither library.
	 *
	 * Production Rule 3 is satisfied by `library_types()`, not by keeping the old
	 * behaviour: a site that wants those rows back in its grids adds one filter.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const MEDIA_LIBRARY = array( 'image', 'video', 'audio' );

	/**
	 * What the DOCUMENT LIBRARY's public listing shows.
	 *
	 * Wider than `DOCUMENTS` on purpose, and this is what resolves the problem the
	 * broken Explore tile exposed. Two different questions were being answered by
	 * one constant:
	 *
	 *   - *"whose drive is this in?"* — `DOCUMENTS`, real documents only. A
	 *     `legacy_document` has no folder and no extraction, so no drive claims it.
	 *   - *"what does the document page list?"* — this, which includes the legacy
	 *     rows, because a document listing renders a **row with a type chip**, not
	 *     an image tile. A quarantined PDF draws perfectly here and drew a broken
	 *     rectangle in the media grid.
	 *
	 * So the legacy rows are not hidden, they are relocated: out of the surface
	 * that could only render them wrong, into the one built for exactly their
	 * shape. That is a better answer than either of the two this replaced —
	 * keeping them in the media grid (broken) or dropping them entirely (content
	 * a member can see today, gone).
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const DOCUMENT_LIBRARY = array( 'document', 'legacy_document' );

	/**
	 * The media-library type group, filtered.
	 *
	 * Production Rule 3 escape hatch for the reversal above. A site that ran
	 * pre-1.2.3 with PDFs enabled and would rather show those rows than hide them
	 * can restore the previous behaviour in one line:
	 *
	 *     add_filter(
	 *         'mvs_media_library_types',
	 *         fn( $types ) => array_merge( $types, array( \WPMediaVerse\Core\MediaTypes::LEGACY_DOCUMENT ) )
	 *     );
	 *
	 * The trade it re-accepts is the broken tile, so the docblock says so rather
	 * than presenting it as a neutral preference.
	 *
	 * Values outside the known vocabulary are dropped — a filter cannot widen a
	 * listing into rows this plugin has no idea how to render.
	 *
	 * @since 2.4.0
	 *
	 * @return string[]
	 */
	public static function library_types(): array {
		/**
		 * Filters the media_type group that media listings show.
		 *
		 * @since 2.4.0
		 *
		 * @param string[] $types Default MediaTypes::MEDIA_LIBRARY.
		 */
		$types = (array) apply_filters( 'mvs_media_library_types', self::MEDIA_LIBRARY );

		$known = array_merge( self::ALL, array( self::LEGACY_DOCUMENT ) );
		$types = array_values( array_intersect( $types, $known ) );

		return $types ?: self::MEDIA_LIBRARY;
	}

	/**
	 * Build a `media_type IN (…)` fragment and its parameters.
	 *
	 * ALWAYS a positive list. Never `!= ''`, never `NOT IN`.
	 *
	 * The difference is not stylistic. `MediaController`'s feed constrained with
	 * `media_type != ''` — an exclusion — which passed every future type straight
	 * through, and the same shape caused the trashed-media leak (`68113454`), whose
	 * query also carried a `media_type` predicate and still served trashed items to
	 * the feed and the mobile app. An exclusion answers "what do I not want today";
	 * an inclusion answers "what is this surface for", which is the question that
	 * stays correct when a new type is added.
	 *
	 * @since 2.4.0
	 *
	 * @param string[] $types  One of the group constants, or an explicit subset.
	 * @param string   $column Qualified column name, e.g. 'm.media_type'.
	 * @return array{0:string,1:string[]} SQL fragment and its ordered parameters.
	 */
	public static function in_clause( array $types, string $column = 'media_type' ): array {
		// Validate against every value this plugin may legitimately store, which
		// includes the legacy quarantine value — otherwise MEDIA_LIBRARY would be
		// silently narrowed back to MEDIA here and the promise above broken again.
		$known = array_merge( self::ALL, array( self::LEGACY_DOCUMENT ) );
		$types = array_values( array_intersect( $types, $known ) );

		if ( empty( $types ) ) {
			// An empty group must match nothing, never everything — failing open
			// here would undo the whole point of the class.
			return array( '1 = 0', array() );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

		return array( "{$column} IN ({$placeholders})", $types );
	}

	/**
	 * Whether a stored value is a type this plugin recognises.
	 *
	 * @since 2.4.0
	 *
	 * @param string $type Stored media_type value.
	 * @return bool
	 */
	public static function is_known( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}
}
