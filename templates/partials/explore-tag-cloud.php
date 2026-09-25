<?php
/**
 * Explore tag cloud - the ONE tag chip row every Explore layout renders.
 *
 * Free's grid (templates/explore.php) and every Pro feed layout (via
 * wpmediaverse-pro/templates/layouts/partials/explore-filters.php) include this
 * same file, so the chips, their links, the active state and the
 * "View all N tags" row are identical on every layout (Basecamp 10263762202).
 * They used to be two copies: when the Free copy moved to server rendering in
 * 2.4.2, the Pro copy kept depending on a client-side fetch that no longer
 * existed, and every Pro layout silently showed only "All".
 *
 * Theme override: {theme}/wpmediaverse/partials/explore-tag-cloud.php
 *
 * Expects:
 *   - $mvs_archive_url (string) Base media archive URL.
 *   - $mvs_filter_tag  (string, optional) Current tag slug filter.
 *
 * @package WPMediaVerse
 * @since   2.4.2
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filters how many tags the Explore tag cloud requests.
 *
 * The client used to hardcode 20, which left site owners no way to widen
 * or narrow the cloud for their own community — a photo site with a
 * handful of curated tags and one with hundreds want different numbers.
 * Clamped to the range the /tags/cloud endpoint itself accepts, so a
 * filter returning something wild cannot produce an unbounded query.
 *
 * @since 2.3.0
 *
 * @param int $limit Number of tags to show. Default 20, max 200.
 */
$mvs_tag_limit = (int) apply_filters( 'mvs_explore_tag_cloud_limit', 20 );
$mvs_tag_limit = max( 1, min( 200, $mvs_tag_limit ) );

// Server-rendered, like the identical chip row in documents.php.
//
// These chips used to be a data-wp-each template whose `active` flag was
// computed once in callbacks.init() by comparing against context.activeTag.
// activeTag is written by PHP and read by JS exactly once, and a router
// navigation does not refresh the live context proxy - so after clicking a
// chip the row kept rendering with the OLD active flag and no chip ever
// showed as selected (Basecamp 10277976087). A derived getter would not have
// helped: it would read the same stale context value.
//
// Rendering on the server makes it correct on every navigation by
// construction, and removes a REST round-trip from every Explore load.
// Cached (5 min): this partial renders on every Explore layout, every
// request. CacheService::tag_cloud()/tag_cloud_total() wrap the repository's
// GROUP BY join in a persistent cache so a 50k+ media library isn't re-scored
// on every page view.
$mvs_cache      = \WPMediaVerse\Core\Plugin::container()->get( 'cache' );
$mvs_show_all   = ! empty( $_GET['mvs_all_tags'] ); // phpcs:ignore WordPress.Security.NonceVerification
$mvs_tag_total  = $mvs_cache->tag_cloud_total();
$mvs_tag_chips  = $mvs_cache->tag_cloud( $mvs_show_all ? max( $mvs_tag_total, 1 ) : $mvs_tag_limit );
$mvs_active_tag = (string) ( $mvs_filter_tag ?? ( isset( $_GET['mvs_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['mvs_tag'] ) ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
?>
<div class="mvs-tag-cloud<?php echo $mvs_show_all ? ' mvs-tag-cloud--all' : ''; ?>">
	<a class="mvs-tag-cloud-item <?php echo '' === $mvs_active_tag && empty( $_GET['s'] ) ? 'active' : ''; // phpcs:ignore WordPress.Security.NonceVerification ?>"
		href="<?php echo esc_url( $mvs_archive_url ); ?>"><?php esc_html_e( 'All', 'wpmediaverse' ); ?></a>
	<span class="mvs-tag-cloud-items">
		<?php
		// tag_cloud() returns stdClass rows (get_results default), not arrays.
		foreach ( $mvs_tag_chips as $mvs_chip ) :
			$mvs_chip_slug = (string) ( is_object( $mvs_chip ) ? ( $mvs_chip->slug ?? '' ) : ( $mvs_chip['slug'] ?? '' ) );
			$mvs_chip_name = (string) ( is_object( $mvs_chip ) ? ( $mvs_chip->name ?? '' ) : ( $mvs_chip['name'] ?? '' ) );
			if ( '' === $mvs_chip_slug ) {
				continue;
			}
			?>
			<a class="mvs-tag-cloud-item <?php echo $mvs_active_tag === $mvs_chip_slug ? 'active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( 'mvs_tag', $mvs_chip_slug, $mvs_archive_url ) ); ?>">
				<?php echo esc_html( $mvs_chip_name ); ?>
			</a>
		<?php endforeach; ?>
	</span>
	<?php
	// The cap is correct - 121 chips above the grid buries the media, and
	// core caps its own tag cloud too. What was missing is any route to
	// the rest: with everything below the top N sharing a count of 1, the
	// tie-break decided the cut, so a tag was reachable or unreachable
	// forever based on spelling. Basecamp 10278224214.
	if ( ! $mvs_show_all && $mvs_tag_total > count( $mvs_tag_chips ) ) :
		?>
		<a class="mvs-tag-cloud-item mvs-tag-cloud-item--more"
			href="<?php echo esc_url( add_query_arg( 'mvs_all_tags', 1, $mvs_archive_url ) ); ?>">
			<?php
			printf(
				/* translators: %d: total number of tags. */
				esc_html__( 'More tags (%d)', 'wpmediaverse' ),
				(int) $mvs_tag_total
			);
			?>
		</a>
	<?php elseif ( $mvs_show_all ) : ?>
		<a class="mvs-tag-cloud-item mvs-tag-cloud-item--more"
			href="<?php echo esc_url( $mvs_archive_url ); ?>">
			<?php esc_html_e( 'Show fewer tags', 'wpmediaverse' ); ?>
		</a>
	<?php endif; ?>
</div>

