<?php
/**
 * Template: Single Media Item.
 *
 * Override by copying to your-theme/wpmediaverse/media-single.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-single-media">
	<?php
	while ( have_posts() ) :
		the_post();

		$file_url  = get_post_meta( get_the_ID(), '_mvs_file_url', true );
		$file_type = get_post_meta( get_the_ID(), '_mvs_file_type', true );
		$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
		$is_video  = $file_url && strpos( $file_type, 'video/' ) === 0;
		$is_audio  = $file_url && strpos( $file_type, 'audio/' ) === 0;
		?>

		<article id="mvs-media-<?php the_ID(); ?>" <?php post_class( 'mvs-media-article' ); ?>>
			<header class="mvs-media-header">
				<h1 class="mvs-media-title"><?php the_title(); ?></h1>
				<div class="mvs-media-meta">
					<span class="mvs-media-author">
						<?php
						printf(
							/* translators: %s: author display name */
							esc_html__( 'By %s', 'wpmediaverse' ),
							esc_html( get_the_author() )
						);
						?>
					</span>
					<span class="mvs-media-date"><?php echo esc_html( get_the_date() ); ?></span>
				</div>
			</header>

			<div class="mvs-media-content">
				<?php if ( $is_image ) : ?>
					<div class="mvs-media-image">
						<img src="<?php echo esc_url( $file_url ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" />
					</div>
				<?php elseif ( $is_video ) : ?>
					<div class="mvs-media-video">
						<video controls preload="metadata" style="max-width:100%;">
							<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
						</video>
					</div>
				<?php elseif ( $is_audio ) : ?>
					<div class="mvs-media-audio">
						<audio controls preload="metadata" style="width:100%;">
							<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
						</audio>
					</div>
				<?php endif; ?>

				<?php if ( get_the_content() ) : ?>
					<div class="mvs-media-description">
						<?php the_content(); ?>
					</div>
				<?php endif; ?>
			</div>

			<footer class="mvs-media-footer">
				<?php
				$tags = get_the_terms( get_the_ID(), 'mvs_tag' );
				if ( $tags && ! is_wp_error( $tags ) ) :
					?>
					<div class="mvs-media-tags">
						<?php foreach ( $tags as $media_tag ) : ?>
							<a href="<?php echo esc_url( get_term_link( $media_tag ) ); ?>" class="mvs-tag">
								<?php echo esc_html( $media_tag->name ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</footer>
		</article>

	<?php endwhile; ?>
</div>
<?php
get_footer();
