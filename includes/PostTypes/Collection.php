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
			'capability_type' => array( 'mvs_media', 'mvs_medias' ),
			'map_meta_cap'    => true,
			'rewrite'         => array( 'slug' => 'collection' ),
			'show_in_menu'    => 'wpmediaverse',
		);

		register_post_type( 'mvs_collection', $args );
	}
}
