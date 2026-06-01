<?php
/**
 * Provider-neutral object↔media linkage.
 *
 * @package WPMediaVerse\Media
 */

declare( strict_types=1 );

namespace WPMediaVerse\Media;

/**
 * Links media items to an arbitrary object — a BuddyPress activity, a BuddyNext
 * post (`bn_post`), a space, etc. — so a headless consumer can attach and read
 * media without the BuddyPress-specific save path. This is the public seam for
 * integrations that own their own UX (e.g. BuddyNext) and only need the engine
 * to store + serve the link.
 *
 * Backed by the `mvs_bp_activity_media` table; the `object_type` column (added in
 * migration v16) discriminates the id namespace, so `bn_post` ids and legacy
 * `bp_activity` ids never collide even though they share the table.
 *
 * Obtain via the container: `Plugin::container()->get( 'object_media' )`.
 *
 * @since 1.6.0
 */
class ObjectMediaLinkage {

	/**
	 * Linkage table (without prefix).
	 */
	private const TABLE = 'mvs_bp_activity_media';

	/**
	 * Replace the media linked to an object with the given ordered set.
	 *
	 * Idempotent: clears any existing links for the object first. Passing an
	 * empty `$media_ids` simply detaches all media from the object.
	 *
	 * @param string $object_type Namespace of the object id (e.g. 'bn_post', 'bp_activity').
	 * @param int    $object_id   The object's id within that namespace.
	 * @param int[]  $media_ids   Ordered media ids to link.
	 * @param string $variant     Variant tag stored per row. Default 'image'.
	 * @return void
	 */
	public function set_object_media( string $object_type, int $object_id, array $media_ids, string $variant = 'image' ): void {
		global $wpdb;

		$object_type = sanitize_key( $object_type );
		if ( '' === $object_type || $object_id <= 0 ) {
			return;
		}

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete(
			$table,
			array(
				'object_type' => $object_type,
				'activity_id' => $object_id,
			),
			array( '%s', '%d' )
		);

		$position = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;
			if ( $media_id <= 0 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				$table,
				array(
					'object_type' => $object_type,
					'activity_id' => $object_id,
					'media_id'    => $media_id,
					'variant'     => $variant,
					'position'    => $position,
					'created_at'  => current_time( 'mysql', true ),
				),
				array( '%s', '%d', '%d', '%s', '%d', '%s' )
			);
			++$position;
		}

		/**
		 * Fires after an object's media linkage has been (re)written.
		 *
		 * @since 1.6.0
		 *
		 * @param string $object_type Object namespace.
		 * @param int    $object_id   Object id.
		 * @param int[]  $media_ids   Linked media ids, in order.
		 */
		do_action( 'mvs_object_media_set', $object_type, $object_id, $media_ids );
	}

	/**
	 * Get the ordered media ids linked to an object.
	 *
	 * @param string $object_type Object namespace.
	 * @param int    $object_id   Object id.
	 * @return int[] Ordered media ids (empty when none).
	 */
	public function get_object_media( string $object_type, int $object_id ): array {
		global $wpdb;

		$object_type = sanitize_key( $object_type );
		if ( '' === $object_type || $object_id <= 0 ) {
			return array();
		}

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE object_type = %s AND activity_id = %d ORDER BY position ASC, id ASC",
				$object_type,
				$object_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}
}
