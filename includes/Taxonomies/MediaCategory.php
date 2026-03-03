<?php
/**
 * Media Category taxonomy.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the mvs_category taxonomy.
 */
class MediaCategory {

	/**
	 * Register the mvs_category taxonomy.
	 */
	public static function register(): void {
		$labels = array(
			'name'              => __( 'Media Categories', 'wpmediaverse' ),
			'singular_name'     => __( 'Media Category', 'wpmediaverse' ),
			'search_items'      => __( 'Search Categories', 'wpmediaverse' ),
			'all_items'         => __( 'All Categories', 'wpmediaverse' ),
			'parent_item'       => __( 'Parent Category', 'wpmediaverse' ),
			'parent_item_colon' => __( 'Parent Category:', 'wpmediaverse' ),
			'edit_item'         => __( 'Edit Category', 'wpmediaverse' ),
			'update_item'       => __( 'Update Category', 'wpmediaverse' ),
			'add_new_item'      => __( 'Add New Category', 'wpmediaverse' ),
			'new_item_name'     => __( 'New Category Name', 'wpmediaverse' ),
			'menu_name'         => __( 'Categories', 'wpmediaverse' ),
			'not_found'         => __( 'No categories found.', 'wpmediaverse' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'media-category' ),
		);

		register_taxonomy( 'mvs_category', 'mvs_media', $args );
	}
}
