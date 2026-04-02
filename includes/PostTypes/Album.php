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
	}
}
