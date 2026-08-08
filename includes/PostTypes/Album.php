<?php
/**
 * Album custom post type.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mvs_album custom post type.
 */
class Album {

	/**
	 * Register the mvs_album post type.
	 */
	public static function register(): void {
		$labels = array(
			'name'               => __( 'Albums', 'wpmediaverse' ),
			'singular_name'      => __( 'Album', 'wpmediaverse' ),
			'add_new'            => __( 'Add New', 'wpmediaverse' ),
			'add_new_item'       => __( 'Add New Album', 'wpmediaverse' ),
			'edit_item'          => __( 'Edit Album', 'wpmediaverse' ),
			'new_item'           => __( 'New Album', 'wpmediaverse' ),
			'view_item'          => __( 'View Album', 'wpmediaverse' ),
			'search_items'       => __( 'Search Albums', 'wpmediaverse' ),
			'not_found'          => __( 'No albums found.', 'wpmediaverse' ),
			'not_found_in_trash' => __( 'No albums found in Trash.', 'wpmediaverse' ),
			'all_items'          => __( 'Albums', 'wpmediaverse' ),
			'menu_name'          => __( 'Albums', 'wpmediaverse' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => true,
			'show_in_rest'    => true,
			'rest_base'       => 'mvs-albums',
			'supports'        => array( 'title', 'editor', 'author', 'thumbnail' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => array( 'slug' => 'album' ),
			'show_in_menu'    => 'wpmediaverse',
		);

		register_post_type( 'mvs_album', $args );

		add_action( 'before_delete_post', array( self::class, 'on_before_delete' ), 10, 2 );
	}

	/**
	 * Clean up an album's custom-table rows when the CPT is permanently
	 * deleted (admin trash+delete, user deletion, wp_delete_post).
	 *
	 * Without this the album's mvs_album_items rows were orphaned forever
	 * (audit 2026-06-04, #11). Fires mvs_album_deleted so Pro/integrations can
	 * react. Permanent delete only (before_delete_post), not trash.
	 *
	 * @param int           $post_id Post being deleted.
	 * @param \WP_Post|null $post    Post object (WP 5.5+).
	 */
	public static function on_before_delete( int $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return;
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'mvs_album_items', array( 'album_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// NO index purge here — removed in 2.3.3, and it must not come back.
		//
		// An album is a wp_posts row that media POINT AT via mvs_media_index.album_id.
		// It is a reference target, never a row in the media table: media_id belongs to
		// the media ID sequence and an album ID must never appear there. Album privacy
		// controls how the ALBUM is visible and lives in post meta (_mvs_privacy);
		// mvs_media_index.privacy means the media item's own visibility. Two values,
		// two homes.
		//
		// The old code stored album privacy at mvs_media_index.media_id = <album post
		// ID>, then purged that row on delete. Because media_id is AUTO_INCREMENT for
		// real media, an album's post ID routinely lands on an existing photo — so the
		// purge destroyed that photo's index record: the file survived on disk and the
		// item vanished from every surface. It also meant the purge was only ever
		// needed to clean up rows that should not have existed.
		//
		// With albums no longer writing to mvs_media_index (AlbumService,
		// AlbumController, Pro importers) and MediaRepository refusing wp_posts IDs,
		// there is nothing left to purge. Legacy rows from before 2.3.3 are cleared
		// once by Migrator v26, not on every delete.
		//
		// Basecamp 10183850886. Plan: plan/2026-08-08-cpt-id-collision-fix-plan.md §4.0.
		// Audit any site with `wp mvs diagnose_cpt_ids` (read-only).

		/**
		 * Fires after an album's custom-table rows are cleaned on permanent delete.
		 *
		 * @since 1.6.0
		 *
		 * @param int $album_id Deleted album post ID.
		 */
		do_action( 'mvs_album_deleted', $post_id );
	}
}
