<?php
/**
 * Template: Media Explore/Archive.
 *
 * Override by copying to your-theme/wpmediaverse/explore.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-explore-page">
	<header class="mvs-explore-header">
		<h1><?php esc_html_e( 'Explore Media', 'wpmediaverse' ); ?></h1>
	</header>

	<?php if ( have_posts() ) : ?>
		<div class="mvs-media-grid mvs-cols-3">
			<?php
			while ( have_posts() ) :
				the_post();
				$file_url  = get_post_meta( get_the_ID(), '_mvs_file_url', true );
				$file_type = get_post_meta( get_the_ID(), '_mvs_file_type', true );
				$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
				?>
				<div class="mvs-grid-item">
					<a href="<?php the_permalink(); ?>">
						<?php if ( $is_image ) : ?>
							<img src="<?php echo esc_url( $file_url ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
								loading="lazy" />
						<?php else : ?>
							<div class="mvs-grid-item-placeholder">
								<span class="dashicons dashicons-media-default"></span>
							</div>
						<?php endif; ?>
					</a>
					<div class="mvs-grid-item-overlay">
						<span class="mvs-grid-item-title"><?php the_title(); ?></span>
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
