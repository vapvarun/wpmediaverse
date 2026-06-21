<?php
/**
 * Template: CPT Archive — Albums and Collections.
 *
 * Shared archive template for mvs_album (/album/) and mvs_collection
 * (/collection/).  WP_Query is already set up by WordPress at this point
 * (correct post_type, pagination, posts_per_page via Settings Reading).
 * We re-run a fresh paginated query so we control posts_per_page and
 * the no_found_rows=false needed for prev/next navigation.
 *
 * Override by copying to your-theme/wpmediaverse/cpt-archive.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();

do_action( 'mvs_before_content' );

include MVS_PLUGIN_DIR . 'templates/partials/router-region-open.php';

// -----------------------------------------------------------------------
// Determine which CPT we are archiving.
// -----------------------------------------------------------------------
$mvs_cpt          = is_post_type_archive( 'mvs_album' ) ? 'mvs_album' : 'mvs_collection';
$mvs_is_albums    = 'mvs_album' === $mvs_cpt;
$mvs_archive_url  = home_url( '/media/' );
$mvs_posts_pp     = 24; // big-site readiness: bounded, never unbounded.

// -----------------------------------------------------------------------
// Pagination.
// -----------------------------------------------------------------------
$mvs_paged = max( 1, (int) get_query_var( 'paged', 1 ) );

$mvs_archive_query = new WP_Query(
	array(
		'post_type'              => $mvs_cpt,
		'post_status'            => 'publish',
		'posts_per_page'         => $mvs_posts_pp,
		'paged'                  => $mvs_paged,
		'no_found_rows'          => false, // needed for pagination.
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
		'orderby'                => 'date',
		'order'                  => 'DESC',
	)
);

$mvs_total_pages = $mvs_archive_query->max_num_pages;

// -----------------------------------------------------------------------
// Service references (loaded once, reused in the loop).
// -----------------------------------------------------------------------
$mvs_container       = \WPMediaVerse\Core\Plugin::container();
$mvs_album_svc       = $mvs_is_albums ? $mvs_container->get( 'albums' ) : null;
$mvs_collection_svc  = $mvs_is_albums ? null : $mvs_container->get( 'collections' );
$mvs_favorites_svc   = $mvs_is_albums ? null : ( $mvs_container->has( 'favorites' ) ? $mvs_container->get( 'favorites' ) : null );
$mvs_tpl_helpers     = $mvs_container->get( 'template_helpers' );
?>
<div class="mvs-explore-page">

	<header class="mvs-explore-header">
		<h1>
		<?php
		if ( $mvs_is_albums ) {
			esc_html_e( 'Albums', 'wpmediaverse' );
		} else {
			esc_html_e( 'Collections', 'wpmediaverse' );
		}
		?>
		</h1>
	</header>

	<?php if ( $mvs_archive_query->have_posts() ) : ?>

		<?php $mvs_grid_cols = max( 2, min( 5, (int) get_option( 'mvs_grid_columns', 3 ) ) ); ?>
		<div class="mvs-media-grid mvs-cols-<?php echo (int) $mvs_grid_cols; ?> mvs-feed">

		<?php
		while ( $mvs_archive_query->have_posts() ) :
			$mvs_archive_query->the_post();
			$mvs_post_id    = (int) get_the_ID();
			$mvs_post_title = get_the_title();
			$mvs_permalink  = get_permalink( $mvs_post_id );

			// -------------------------------------------------------
			// Cover image resolution.
			// Albums: use AlbumService::get_resolved_cover_media_id +
			//         MediaUrl::thumb (consistent with the explore.php
			//         card markup, avoids a raw image URL write).
			// Collections: first published media item in the collection.
			// Per-card cover lookups are accepted here — there is no
			// batch cover API yet; each card is O(1) cached DB reads.
			// -------------------------------------------------------
			$mvs_cover_url   = '';
			$mvs_item_count  = 0;

			if ( $mvs_is_albums && $mvs_album_svc ) {
				$mvs_cover_id   = $mvs_album_svc->get_resolved_cover_media_id( $mvs_post_id );
				$mvs_item_count = $mvs_album_svc->get_item_count( $mvs_post_id );
				if ( $mvs_cover_id ) {
					$mvs_cover_url = \WPMediaVerse\Core\MediaUrl::thumb( $mvs_cover_id );
				} else {
					$mvs_cover_url = (string) $mvs_album_svc->get_cover_url( $mvs_post_id );
				}
			} elseif ( ! $mvs_is_albums && $mvs_collection_svc ) {
				$mvs_ctype = get_post_meta( $mvs_post_id, '_mvs_collection_type', true ) ?: 'manual';
				if ( 'smart' === $mvs_ctype ) {
					$mvs_resolved   = $mvs_collection_svc->resolve( $mvs_post_id, 1, 1 );
					$mvs_item_count = $mvs_resolved['total'];
					$mvs_first_ids  = array_column( $mvs_resolved['items'], 'media_id' );
				} else {
					// Manual/favorites-backed collection.
					$mvs_first_ids  = $mvs_favorites_svc
						? $mvs_favorites_svc->get_collection_media_ids( $mvs_post_id, 100 )
						: array();
					$mvs_item_count = count( $mvs_first_ids );
				}
				if ( ! empty( $mvs_first_ids ) ) {
					$mvs_cover_url = \WPMediaVerse\Core\MediaUrl::thumb( (int) $mvs_first_ids[0] );
				}
			}
			$mvs_cover_url = $mvs_cover_url ?: '';

			// -------------------------------------------------------
			// Author info — reuse same pattern as explore.php album
			// card (avatar + link to profile).
			// -------------------------------------------------------
			$mvs_author_id  = (int) get_the_author_meta( 'ID' );
			$mvs_author_url = $mvs_tpl_helpers->get_user_profile_url( $mvs_author_id );
			$mvs_author     = $mvs_tpl_helpers->get_display_name_plain( $mvs_author_id );

			$mvs_card_class = $mvs_is_albums ? 'mvs-grid-item--album' : 'mvs-grid-item--collection';
			?>

			<div class="mvs-grid-item <?php echo esc_attr( $mvs_card_class ); ?>">
				<a href="<?php echo esc_url( $mvs_permalink ); ?>" class="mvs-grid-item-link">
					<?php if ( $mvs_cover_url ) : ?>
						<img src="<?php echo esc_url( $mvs_cover_url ); ?>"
							alt="<?php echo esc_attr( $mvs_post_title ); ?>"
							loading="lazy" />
					<?php else : ?>
						<div class="mvs-grid-item-placeholder <?php echo $mvs_is_albums ? 'mvs-grid-item-placeholder--album' : 'mvs-grid-item-placeholder--collection'; ?>">
							<span class="mvs-grid-album-icon"><?php echo $mvs_is_albums ? '&#128193;' : '&#128216;'; ?></span>
						</div>
					<?php endif; ?>
					<span class="mvs-album-badge"
						title="<?php echo esc_attr( sprintf( /* translators: %d: item count */ _n( '%d item', '%d items', $mvs_item_count, 'wpmediaverse' ), $mvs_item_count ) ); ?>">
						<span class="dashicons dashicons-images-alt2" aria-hidden="true"></span>
					</span>
					<div class="mvs-grid-item-overlay">
						<div class="mvs-grid-item-stats">
							<span class="mvs-grid-stat">
								<?php
								printf(
									/* translators: %d: item count */
									esc_html( _n( '%d item', '%d items', $mvs_item_count, 'wpmediaverse' ) ),
									(int) $mvs_item_count
								);
								?>
							</span>
						</div>
					</div>
				</a>
				<div class="mvs-grid-item-info">
					<?php if ( '' !== $mvs_author_url ) : ?>
						<a class="mvs-grid-item-author-link" href="<?php echo esc_url( $mvs_author_url ); ?>">
							<?php echo get_avatar( $mvs_author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
							<span class="mvs-grid-item-author"><?php echo esc_html( $mvs_author ); ?></span>
						</a>
					<?php else : ?>
						<?php echo get_avatar( $mvs_author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
						<span class="mvs-grid-item-author"><?php echo esc_html( $mvs_author ); ?></span>
					<?php endif; ?>
					<a class="mvs-grid-item-title-link" href="<?php echo esc_url( $mvs_permalink ); ?>">
						<span class="mvs-grid-item-title"><?php echo esc_html( $mvs_post_title ); ?></span>
					</a>
				</div>
			</div>

		<?php endwhile; ?>
		<?php wp_reset_postdata(); ?>

		</div><!-- .mvs-media-grid -->

		<?php if ( $mvs_total_pages > 1 ) : ?>
			<nav class="mvs-pagination" aria-label="<?php esc_attr_e( 'Archive pages', 'wpmediaverse' ); ?>">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'total'   => (int) $mvs_total_pages,
							'current' => $mvs_paged,
							'type'    => 'list',
						)
					)
				);
				?>
			</nav>
		<?php endif; ?>

	<?php else : ?>

		<div class="mvs-empty-state mvs-empty-state--archive">
			<p class="mvs-empty-state__message">
				<?php
				if ( $mvs_is_albums ) {
					esc_html_e( 'No albums yet. Create your first album to get started.', 'wpmediaverse' );
				} else {
					esc_html_e( 'No collections yet. Create your first collection to get started.', 'wpmediaverse' );
				}
				?>
			</p>
		</div>

	<?php endif; ?>

</div><!-- .mvs-explore-page -->

<?php
include MVS_PLUGIN_DIR . 'templates/partials/router-region-close.php';

do_action( 'mvs_after_content' );

get_footer();
