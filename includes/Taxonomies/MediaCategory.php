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
			'labels'                => $labels,
			'hierarchical'          => true,
			'public'                => true,
			'show_in_rest'          => true,
			'show_admin_column'     => true,
			'rewrite'               => array( 'slug' => 'media-category' ),
			'update_count_callback' => array( MediaTag::class, 'update_term_count' ),
			// No metabox on the album editor. Categories describe MEDIA, never albums:
			// every browsing, filtering and archive surface resolves this taxonomy by
			// joining wp_term_relationships.object_id to mvs_media_index.media_id, so a
			// term assigned to an album post matched nothing anywhere. Core's default
			// metabox wrote exactly those inert album-space rows — and because an album
			// post ID can equal a real media_id, they were readable back as a photo's
			// categories. Removed in 2.3.3 along with the plugin's own album-category
			// write paths.
			'meta_box_cb'           => false,
		);

		// Still registered against mvs_album, and deliberately: it is what keeps the
		// term-management screen reachable. Unlike tags (Admin\TagManagementPage) there
		// is no dedicated admin page for media categories, so detaching the taxonomy
		// would remove the only way to create or edit one. The object-type association
		// is a registration vehicle, not a statement that albums have categories —
		// meta_box_cb => false above is what enforces that.
		//
		// Residual: mvs_album registers show_in_rest => true with no controller
		// override, so core's /wp/v2/mvs-albums still exposes this taxonomy as a
		// writable field. It writes inert rows that nothing reads; Migrator v26 clears
		// them. Closing it properly needs a dedicated category admin page so the
		// taxonomy can be detached — tracked, not done here.
		//
		// Custom update_count_callback (shared with MediaTag) counts media in
		// mvs_media_index, not wp_posts — see MediaTag::update_term_count() docblock.
		// Plan: plan/2026-08-08-cpt-id-collision-fix-plan.md §3.4.
		register_taxonomy( 'mvs_category', 'mvs_album', $args );
	}
}
