<?php
/**
 * Collection service.
 *
 * Manages smart collection rules and resolves them to media queries.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

use WPMediaVerse\Core\MediaTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Handles smart collection rule storage and resolution.
 */
class CollectionService {

	/**
	 * Rule keys accepted by save_rules() / resolve().
	 *
	 * @var string[]
	 */
	private const RULE_KEYS = array( 'media_type', 'tag', 'category', 'author', 'date_after', 'date_before', 'privacy' );

	/**
	 * Save smart collection rules.
	 *
	 * Rules are normalized before storage: keys are whitelisted, and
	 * tag/category/author values are coerced to IDs (a tag/category name or
	 * slug is resolved to its term ID) so resolve() always operates on IDs.
	 *
	 * @param int   $collection_id Collection post ID.
	 * @param array $rules         Array of rule definitions.
	 */
	public function save_rules( int $collection_id, array $rules ): void {
		$clean = array();

		foreach ( $rules as $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['key'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}

			$key   = sanitize_key( $rule['key'] );
			$value = sanitize_text_field( (string) $rule['value'] );

			if ( ! in_array( $key, self::RULE_KEYS, true ) || '' === $value ) {
				continue;
			}

			if ( 'tag' === $key || 'category' === $key ) {
				$term_id = $this->resolve_term_id( $value, 'tag' === $key ? 'mvs_tag' : 'mvs_category' );
				if ( 0 === $term_id ) {
					continue;
				}
				$value = (string) $term_id;
			} elseif ( 'author' === $key ) {
				$value = (string) absint( $value );
				if ( '0' === $value ) {
					continue;
				}
			}

			$clean[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		update_post_meta( $collection_id, '_mvs_collection_type', 'smart' );
		update_post_meta( $collection_id, '_mvs_collection_rules', $clean );
	}

	/**
	 * Resolve a tag/category rule value to a term ID.
	 *
	 * Accepts a numeric term ID, a slug, or a name. Legacy rules (seeded demo
	 * data and rows saved before 1.6.0 by the edit modal) stored names instead
	 * of IDs; resolving here heals them without a migration.
	 *
	 * @param string $value    Raw rule value.
	 * @param string $taxonomy Taxonomy name (mvs_tag or mvs_category).
	 * @return int Term ID, or 0 when unresolvable.
	 */
	private function resolve_term_id( string $value, string $taxonomy ): int {
		if ( is_numeric( $value ) ) {
			return absint( $value );
		}

		$term = get_term_by( 'slug', $value, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $value, $taxonomy );
		}

		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
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
	 * A collection draws from the MEDIA library only. The document library has its
	 * own folders and surfaces, and no rule set can opt a collection into it.
	 *
	 * Supported rule keys:
	 * - media_type: string — matched against the MIME type (`file_type LIKE`), so
	 *   the useful values are 'image', 'video', 'audio'. This key has never
	 *   filtered the `media_type` column despite its name; 'document' was listed
	 *   here but could not match, because the stored MIME is 'application/pdf'.
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

		$empty = array(
			'items' => array(),
			'total' => 0,
		);

		if ( empty( $rules ) ) {
			return $empty;
		}

		// Group rule values by key, then combine SAME-key values with OR and
		// DIFFERENT keys with AND. A flat AND over every rule made same-key rules
		// mutually exclusive — two media_type rules became `file_type LIKE
		// '%image%' AND file_type LIKE '%video%'`, which no single file can
		// satisfy, so the collection resolved to 0 items and showed the
		// placeholder cover (#9962118482).
		$by_key = array();
		foreach ( $rules as $rule ) {
			if ( empty( $rule['key'] ) || ! isset( $rule['value'] ) ) {
				continue;
			}
			$by_key[ $rule['key'] ][] = $rule['value'];
		}

		// Translate rules into repository args. This method no longer builds SQL:
		// the OR-within-key semantic lives in MediaRepository::or_set(), and the
		// MEDIA_LIBRARY type default lives in build_query_parts(), so a collection
		// cannot resolve to a document however its rules are written.
		$args = array(
			'status'  => 'publish',
			'privacy' => 'any',
			'orderby' => 'created_at',
			'order'   => 'DESC',
			'limit'     => $per_page,
			'offset'    => ( $page - 1 ) * $per_page,
			'tax_terms' => array(),
		);

		foreach ( $by_key as $key => $values ) {
			switch ( $key ) {
				case 'media_type':
					// Historically matched against the MIME (`file_type LIKE`),
					// never the media_type column, despite the rule's name. Kept
					// as-is: renaming would break stored rules on live sites.
					foreach ( $values as $value ) {
						$args['mime_like_in'][] = '%' . sanitize_text_field( (string) $value ) . '%';
					}
					break;

				case 'author':
					foreach ( $values as $value ) {
						$args['authors_in'][] = (int) $value;
					}
					break;

				case 'privacy':
					foreach ( $values as $value ) {
						$args['privacy_in'][] = sanitize_text_field( (string) $value );
					}
					break;

				case 'date_after':
					$args['since'] = sanitize_text_field( (string) end( $values ) ) . ' 00:00:00';
					break;

				case 'date_before':
					$args['until'] = sanitize_text_field( (string) end( $values ) ) . ' 23:59:59';
					break;

				case 'tag':
				case 'category':
					$taxonomy = 'tag' === $key ? 'mvs_tag' : 'mvs_category';
					$term_ids = array();
					foreach ( $values as $value ) {
						$term_id = $this->resolve_term_id( sanitize_text_field( (string) $value ), $taxonomy );
						if ( $term_id > 0 ) {
							$term_ids[] = $term_id;
						}
					}

					// No resolvable term for this key can never match anything.
					if ( empty( $term_ids ) ) {
						return $empty;
					}

					// TERM ids plus their taxonomy — never term_taxonomy_ids. The
					// repository joins term_taxonomy and matches tt.term_id, which
					// is a different column from tr.term_taxonomy_id and equal to it
					// only by coincidence.
					$args['tax_terms'][] = array(
						'taxonomy' => $taxonomy,
						'term_ids' => array_values( array_unique( $term_ids ) ),
					);
					break;
			}
		}

		$repo  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$total = $repo->query_count( $args );

		if ( 0 === $total ) {
			return $empty;
		}

		$items = array();
		foreach ( $repo->query( $args ) as $row ) {
			$items[] = array(
				'media_id'   => (int) $row['media_id'],
				'created_at' => $row['created_at'],
			);
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
