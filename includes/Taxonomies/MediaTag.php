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
			'labels'                => $labels,
			'hierarchical'          => false,
			'public'                => true,
			'show_ui'               => true,
			// Hide the core taxonomy submenu — TagManagementPage (`mvs-tags`) is
			// the single Tags screen. Leaving show_in_menu on produced two "Tags"
			// entries under WPMediaVerse (custom page + edit-tags.php).
			'show_in_menu'          => false,
			'show_in_rest'          => true,
			'show_admin_column'     => true,
			'rewrite'               => array( 'slug' => 'media-tag' ),
			'update_count_callback' => array( __CLASS__, 'update_term_count' ),
			// No metabox on the album editor. Every mvs_tag write in this plugin passes a
			// media-index ID, but core's default metabox on the album screen wrote
			// ALBUM post IDs into the same wp_term_relationships.object_id space — a
			// contamination path no plugin code accounted for. Closed in 2.4.0.
			'meta_box_cb'           => false,
		);

		// Registered on mvs_album as a registration vehicle for the term-management
		// screen, not because albums have tags. Media-tag associations are stored
		// against mvs_media_index.media_id. See MediaCategory::register() for the full
		// reasoning and the residual /wp/v2/mvs-albums note.
		register_taxonomy( 'mvs_tag', 'mvs_album', $args );
	}

	/**
	 * Custom term count callback that counts media rows in mvs_media_index.
	 *
	 * The default _update_post_term_count only counts wp_posts rows of registered
	 * post types. Our media lives in the mvs_media_index custom table, so term
	 * counts would otherwise stay at 0 and tags would never appear on the
	 * Explore tag cloud (which filters hide_empty=true).
	 *
	 * @param array<int>          $terms    Term taxonomy IDs.
	 * @param \WP_Taxonomy|string $taxonomy Taxonomy object or slug.
	 */
	public static function update_term_count( array $terms, $taxonomy ): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';

		foreach ( (array) $terms as $term_taxonomy_id ) {
			$term_taxonomy_id = (int) $term_taxonomy_id;

			// Count published media rows attached to this term via term_relationships.
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
					INNER JOIN {$index_table} m ON m.media_id = tr.object_id
					WHERE tr.term_taxonomy_id = %d AND m.status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$term_taxonomy_id
				)
			);

			/**
			 * Filter the computed term count before persisting.
			 *
			 * @param int $count            Computed media count for the term.
			 * @param int $term_taxonomy_id Term taxonomy ID.
			 */
			$count = (int) apply_filters( 'mvs_tag_term_count', $count, $term_taxonomy_id );

			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->term_taxonomy,
				array( 'count' => $count ),
				array( 'term_taxonomy_id' => $term_taxonomy_id )
			);
		}
	}
}
