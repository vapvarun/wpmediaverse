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
 * @since   2.3.3
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical media_type groups.
 *
 * @since 2.3.3
 */
final class MediaTypes {

	/**
	 * Types that belong to the media library.
	 *
	 * @since 2.3.3
	 * @var string[]
	 */
	public const MEDIA = array( 'image', 'video', 'audio' );

	/**
	 * Types that belong to the document library.
	 *
	 * @since 2.3.3
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
	 * @since 2.3.3
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
	 * @since 2.3.3
	 * @var string
	 */
	public const LEGACY_DOCUMENT = 'legacy_document';

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
	 * @since 2.3.3
	 *
	 * @param string[] $types  One of the group constants, or an explicit subset.
	 * @param string   $column Qualified column name, e.g. 'm.media_type'.
	 * @return array{0:string,1:string[]} SQL fragment and its ordered parameters.
	 */
	public static function in_clause( array $types, string $column = 'media_type' ): array {
		$types = array_values( array_intersect( $types, self::ALL ) );

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
	 * @since 2.3.3
	 *
	 * @param string $type Stored media_type value.
	 * @return bool
	 */
	public static function is_known( string $type ): bool {
		return in_array( $type, self::ALL, true );
	}
}
