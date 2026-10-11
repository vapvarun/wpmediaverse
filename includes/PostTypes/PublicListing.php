<?php
/**
 * Keeps non-public albums and collections out of WordPress's own listings.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Albums and collections are public post types, so core lists them in their
 * archive, their RSS feed and the XML sitemap without asking MediaVerse about
 * their privacy. Those listings show only public
 * ones and never a member's Favorites collection. Single pages keep their own
 * gate (TemplateLoader); site search leaves them out through
 * `exclude_from_search` on the post types.
 *
 * @since 2.6.2
 */
final class PublicListing {

	const POST_TYPES = array( 'mvs_album', 'mvs_collection' );

	/**
	 * Hook the archive/feed query and the sitemap query.
	 */
	public static function register(): void {
		add_action( 'pre_get_posts', array( self::class, 'filter_listing' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( self::class, 'filter_sitemap' ), 10, 2 );
	}

	/**
	 * Meta query matching albums and collections anyone may see.
	 *
	 * @return array
	 */
	public static function meta_query(): array {
		return array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'key'   => \WPMediaVerse\Services\AlbumService::PRIVACY_META,
					'value' => 'public',
				),
				array(
					'key'     => \WPMediaVerse\Services\AlbumService::PRIVACY_META,
					'compare' => 'NOT EXISTS',
				),
			),
			array(
				'key'     => \WPMediaVerse\Social\FavoriteService::FAVORITES_META,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Archive and feed of an album/collection type: public ones only.
	 *
	 * @param \WP_Query $query Query.
	 */
	public static function filter_listing( $query ): void {
		if ( is_admin() || ! ( $query->is_main_query() || $query->is_feed() ) || $query->is_singular() ) {
			return;
		}
		if ( ! array_intersect( (array) $query->get( 'post_type' ), self::POST_TYPES ) ) {
			return;
		}
		$existing = (array) $query->get( 'meta_query' );
		$query->set(
			'meta_query',
			$existing
				? array(
					'relation' => 'AND',
					$existing,
					self::meta_query(),
				)
				: self::meta_query()
		);
	}

	/**
	 * XML sitemap of an album/collection type: public ones only.
	 *
	 * @param array  $args      WP_Query args.
	 * @param string $post_type Post type of this sitemap.
	 * @return array
	 */
	public static function filter_sitemap( $args, $post_type ) {
		if ( in_array( $post_type, self::POST_TYPES, true ) ) {
			$args['meta_query'] = self::meta_query(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by the sitemap page size.
		}
		return $args;
	}
}
