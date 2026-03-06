<?php
/**
 * Template: Media Explore/Archive.
 *
 * Unified feed displaying both media items and albums (Instagram-style).
 * Override by copying to your-theme/wpmediaverse/explore.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-explore-page">
	<header class="mvs-explore-header">
		<h1><?php esc_html_e( 'Explore', 'wpmediaverse' ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="mvs-media-grid mvs-cols-3 mvs-feed">
			<?php
			while ( have_posts() ) :
				the_post();
				global $wpdb;

				$post_type = get_post_type();
				$is_album  = ( 'mvs_album' === $post_type );

				if ( $is_album ) {
					// Album: get cover image (first item) and item count.
					$album_table = $wpdb->prefix . 'mvs_album_items';
					$cover_id    = $wpdb->get_var( $wpdb->prepare( "SELECT media_id FROM {$album_table} WHERE album_id = %d ORDER BY position ASC LIMIT 1", get_the_ID() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$item_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$album_table} WHERE album_id = %d", get_the_ID() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$cover_url   = $cover_id ? get_post_meta( $cover_id, '_mvs_file_url', true ) : '';
					$cover_type  = $cover_id ? get_post_meta( $cover_id, '_mvs_file_type', true ) : '';
					$is_image    = $cover_url && strpos( $cover_type, 'image/' ) === 0;
				} else {
					// Single media item.
					$file_url  = get_post_meta( get_the_ID(), '_mvs_file_url', true );
					$file_type = get_post_meta( get_the_ID(), '_mvs_file_type', true );
					$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;

					// Get stats for hover overlay.
					$stats_table    = $wpdb->prefix . 'mvs_media_stats';
					$media_stats    = $wpdb->get_row( $wpdb->prepare( "SELECT reactions, comments FROM {$stats_table} WHERE media_id = %d", get_the_ID() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$reaction_count = $media_stats ? (int) $media_stats->reactions : 0;
					$comment_count  = $media_stats ? (int) $media_stats->comments : 0;
				}
				?>
				<div class="mvs-grid-item<?php echo $is_album ? ' mvs-grid-item--album' : ''; ?>">
					<a href="<?php the_permalink(); ?>" class="mvs-grid-item-link">
						<?php if ( $is_album && $is_image ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
								loading="lazy" />
							<span class="mvs-album-badge" title="<?php echo esc_attr( sprintf( '%d items', $item_count ) ); ?>">
								<span class="dashicons dashicons-images-alt2"></span>
							</span>
						<?php elseif ( $is_album ) : ?>
							<div class="mvs-grid-item-placeholder">
								<span class="dashicons dashicons-format-gallery"></span>
							</div>
							<span class="mvs-album-badge">
								<span class="dashicons dashicons-images-alt2"></span>
							</span>
						<?php elseif ( $is_image ) : ?>
							<img src="<?php echo esc_url( $file_url ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
								loading="lazy" />
						<?php else : ?>
							<div class="mvs-grid-item-placeholder">
								<span class="dashicons dashicons-media-default"></span>
							</div>
						<?php endif; ?>
						<div class="mvs-grid-item-overlay">
							<div class="mvs-grid-item-stats">
								<?php if ( $is_album ) : ?>
									<span class="mvs-grid-stat">&#x1F5BC;&#xFE0F; <?php echo esc_html( $item_count ); ?></span>
								<?php else : ?>
									<span class="mvs-grid-stat">&#x2764;&#xFE0F; <?php echo esc_html( $reaction_count ); ?></span>
									<span class="mvs-grid-stat">&#x1F4AC; <?php echo esc_html( $comment_count ); ?></span>
								<?php endif; ?>
							</div>
						</div>
					</a>
					<div class="mvs-grid-item-info">
						<?php echo get_avatar( get_the_author_meta( 'ID' ), 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
						<span class="mvs-grid-item-author"><?php echo esc_html( get_the_author() ); ?></span>
					</div>
				</div>
			<?php endwhile; ?>
		</div>

		<div class="mvs-pagination">
			<?php
			the_posts_pagination(
				array(
					'prev_text' => __( '&laquo; Previous', 'wpmediaverse' ),
					'next_text' => __( 'Next &raquo;', 'wpmediaverse' ),
				)
			);
			?>
		</div>
	<?php else : ?>
		<p class="mvs-no-media"><?php esc_html_e( 'No media items found.', 'wpmediaverse' ); ?></p>
	<?php endif; ?>
</div>
<?php
get_footer();
