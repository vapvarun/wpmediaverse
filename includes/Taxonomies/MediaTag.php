<?php
/**
 * Media Tag taxonomy.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mvs_tag taxonomy.
 */
class MediaTag {

	/**
	 * Register the mvs_tag taxonomy.
	 */
	public static function register(): void {
		$labels = array(
			'name'                       => __( 'Media Tags', 'wpmediaverse' ),
			'singular_name'              => __( 'Media Tag', 'wpmediaverse' ),
			'search_items'               => __( 'Search Tags', 'wpmediaverse' ),
			'popular_items'              => __( 'Popular Tags', 'wpmediaverse' ),
			'all_items'                  => __( 'All Tags', 'wpmediaverse' ),
			'edit_item'                  => __( 'Edit Tag', 'wpmediaverse' ),
			'update_item'                => __( 'Update Tag', 'wpmediaverse' ),
			'add_new_item'               => __( 'Add New Tag', 'wpmediaverse' ),
			'new_item_name'              => __( 'New Tag Name', 'wpmediaverse' ),
			'separate_items_with_commas' => __( 'Separate tags with commas', 'wpmediaverse' ),
			'add_or_remove_items'        => __( 'Add or remove tags', 'wpmediaverse' ),
			'choose_from_most_used'      => __( 'Choose from the most used tags', 'wpmediaverse' ),
			'not_found'                  => __( 'No tags found.', 'wpmediaverse' ),
			'menu_name'                  => __( 'Tags', 'wpmediaverse' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'media-tag' ),
		);

		register_taxonomy( 'mvs_tag', 'mvs_media', $args );
	}
}
