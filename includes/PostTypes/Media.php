<?php
/**
 * Media custom post type.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mvs_media custom post type.
 */
class Media {

	/**
	 * Register the mvs_media post type.
	 */
	public static function register(): void {
		$labels = array(
			'name'                  => __( 'Media Items', 'wpmediaverse' ),
			'singular_name'         => __( 'Media Item', 'wpmediaverse' ),
			'add_new'               => __( 'Add New', 'wpmediaverse' ),
			'add_new_item'          => __( 'Add New Media Item', 'wpmediaverse' ),
			'edit_item'             => __( 'Edit Media Item', 'wpmediaverse' ),
			'new_item'              => __( 'New Media Item', 'wpmediaverse' ),
			'view_item'             => __( 'View Media Item', 'wpmediaverse' ),
			'search_items'          => __( 'Search Media Items', 'wpmediaverse' ),
			'not_found'             => __( 'No media items found.', 'wpmediaverse' ),
			'not_found_in_trash'    => __( 'No media items found in Trash.', 'wpmediaverse' ),
			'all_items'             => __( 'All Media Items', 'wpmediaverse' ),
			'archives'              => __( 'Media Archives', 'wpmediaverse' ),
			'insert_into_item'      => __( 'Insert into media item', 'wpmediaverse' ),
			'uploaded_to_this_item' => __( 'Uploaded to this media item', 'wpmediaverse' ),
			'menu_name'             => __( 'WPMediaVerse', 'wpmediaverse' ),
		);

		$args = array(
			'labels'          => $labels,
			'public'          => true,
			'has_archive'     => true,
			'show_in_rest'    => true,
			'rest_base'       => 'mvs-media',
			'supports'        => array( 'title', 'author', 'thumbnail', 'custom-fields' ),
			'capability_type' => array( 'mvs_media', 'mvs_medias' ),
			'map_meta_cap'    => true,
			'rewrite'         => array( 'slug' => 'media' ),
			'menu_icon'       => 'dashicons-format-gallery',
			'show_in_menu'    => true,
			'menu_position'   => 25,
		);

		register_post_type( 'mvs_media', $args );
	}
}
