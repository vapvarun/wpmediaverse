<?php
/**
 * Collection service.
 *
 * Manages smart collection rules and resolves them to media queries.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Handles smart collection rule storage and resolution.
 */
class CollectionService {

	/**
	 * Save smart collection rules.
	 *
	 * @param int   $collection_id Collection post ID.
	 * @param array $rules         Array of rule definitions.
	 */
	public function save_rules( int $collection_id, array $rules ): void {
		update_post_meta( $collection_id, '_mvs_collection_type', 'smart' );
		update_post_meta( $collection_id, '_mvs_collection_rules', $rules );
	}

	/**
	 * Get the collection type.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return string 'manual' or 'smart'.
	 */
	public function get_type( int $collection_id ): string {
		$type = get_post_meta( $collection_id, '_mvs_collection_type', true );
		return $type ? $type : 'manual';
	}

	/**
	 * Get stored rules for a smart collection.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array
	 */
	public function get_rules( int $collection_id ): array {
		$rules = get_post_meta( $collection_id, '_mvs_collection_rules', true );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Resolve smart collection rules to media IDs.
	 *
	 * Supported rule keys:
	 * - media_type: string (image, video, audio, document)
	 * - tag: int (term ID for mvs_tag)
	 * - category: int (term ID for mvs_category)
	 * - author: int (user ID)
	 * - date_after: string (Y-m-d)
	 * - date_before: string (Y-m-d)
	 * - privacy: string (public, members, private, etc.)
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $per_page      Items per page.
	 * @param int $page          Page number.
	 * @return array{items: array, total: int}
	 */
	public function resolve( int $collection_id, int $per_page = 20, int $page = 1 ): array {
		$rules = $this->get_rules( $collection_id );

		if ( empty( $rules ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$args = array(
			'post_type'      => 'mvs_media',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		$meta_query = array();
		$tax_query  = array();
		$date_query = array();

		foreach ( $rules as $rule ) {
			if ( empty( $rule['key'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}

			switch ( $rule['key'] ) {
				case 'media_type':
					$meta_query[] = array(
						'key'     => '_mvs_file_type',
						'value'   => sanitize_text_field( $rule['value'] ),
						'compare' => 'LIKE',
					);
					break;

				case 'tag':
					$tax_query[] = array(
						'taxonomy' => 'mvs_tag',
						'terms'    => (int) $rule['value'],
					);
					break;

				case 'category':
					$tax_query[] = array(
						'taxonomy' => 'mvs_category',
						'terms'    => (int) $rule['value'],
					);
					break;

				case 'author':
					$args['author'] = (int) $rule['value'];
					break;

				case 'date_after':
					$date_query['after'] = sanitize_text_field( $rule['value'] );
					break;

				case 'date_before':
					$date_query['before'] = sanitize_text_field( $rule['value'] );
					break;

				case 'privacy':
					$meta_query[] = array(
						'key'   => '_mvs_privacy',
						'value' => sanitize_text_field( $rule['value'] ),
					);
					break;
			}
		}

		if ( ! empty( $meta_query ) ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( ! empty( $tax_query ) ) {
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		if ( ! empty( $date_query ) ) {
			$args['date_query'] = array( $date_query );
		}

		$query = new \WP_Query( $args );

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'media_id'   => $post->ID,
				'created_at' => $post->post_date_gmt,
			);
		}

		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	}
}
