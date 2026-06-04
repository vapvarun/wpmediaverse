<?php
/**
 * Collection custom post type.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mvs_collection custom post type.
 */
class Collection {

	/**
	 * Register the mvs_collection post type.
	 */
	public static function register(): void {
		$labels = array(
			'name'               => __( 'Collections', 'wpmediaverse' ),
			'singular_name'      => __( 'Collection', 'wpmediaverse' ),
			'add_new'            => __( 'Add New', 'wpmediaverse' ),
			'add_new_item'       => __( 'Add New Collection', 'wpmediaverse' ),
			'edit_item'          => __( 'Edit Collection', 'wpmediaverse' ),
			'new_item'           => __( 'New Collection', 'wpmediaverse' ),
			'view_item'          => __( 'View Collection', 'wpmediaverse' ),
			'search_items'       => __( 'Search Collections', 'wpmediaverse' ),
			'not_found'          => __( 'No collections found.', 'wpmediaverse' ),
			'not_found_in_trash' => __( 'No collections found in Trash.', 'wpmediaverse' ),
			'all_items'          => __( 'Collections', 'wpmediaverse' ),
			'menu_name'          => __( 'Collections', 'wpmediaverse' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => true,
			'show_in_rest'    => true,
			'rest_base'       => 'mvs-collections',
			'supports'        => array( 'title', 'editor', 'author', 'thumbnail' ),
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => array( 'slug' => 'collection' ),
			'show_in_menu'    => 'wpmediaverse',
		);

		register_post_type( 'mvs_collection', $args );

		add_action( 'before_delete_post', array( self::class, 'on_before_delete' ), 10, 2 );
	}

	/**
	 * Fire mvs_collection_deleted when a collection CPT is permanently deleted
	 * so Pro can purge its multi-collection membership rows
	 * (mvs_pro_collection_items) — they were leaked before (audit 2026-06-04,
	 * #13). The collection's own postmeta (_mvs_collection_rules etc.) is
	 * removed by WordPress with the post. Permanent delete only.
	 *
	 * @param int           $post_id Post being deleted.
	 * @param \WP_Post|null $post    Post object (WP 5.5+).
	 */
	public static function on_before_delete( int $post_id, $post = null ): void {
		if ( ! $post instanceof \WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post || 'mvs_collection' !== $post->post_type ) {
			return;
		}

		/**
		 * Fires when a collection is permanently deleted.
		 *
		 * @since 1.6.0
		 *
		 * @param int $collection_id Deleted collection post ID.
		 */
		do_action( 'mvs_collection_deleted', $post_id );
	}
}
