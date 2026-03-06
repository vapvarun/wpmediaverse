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

			<!-- Social Interactions Bar -->
			<div class="mvs-social-bar">
				<div class="mvs-reactions"></div>
				<?php if ( is_user_logged_in() ) : ?>
					<button class="mvs-favorite-btn" type="button">&#x2764; <?php esc_html_e( 'Favorite', 'wpmediaverse' ); ?></button>
				<?php endif; ?>
				<button class="mvs-share-btn" type="button">&#x1F517; <?php esc_html_e( 'Share', 'wpmediaverse' ); ?></button>
				<span class="mvs-view-count"></span>
			</div>

			<!-- Comments Section -->
			<div class="mvs-comments-section">
				<h3 class="mvs-comments-title"><?php esc_html_e( 'Comments', 'wpmediaverse' ); ?></h3>
				<?php if ( is_user_logged_in() ) : ?>
					<form class="mvs-comment-form">
						<textarea placeholder="<?php esc_attr_e( 'Write a comment...', 'wpmediaverse' ); ?>" rows="2"></textarea>
						<button type="submit"><?php esc_html_e( 'Post', 'wpmediaverse' ); ?></button>
					</form>
				<?php endif; ?>
				<ul class="mvs-comment-list"></ul>
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
// Enqueue social interactions script.
wp_enqueue_script(
	'mvs-media-single',
	MVS_PLUGIN_URL . 'assets/js/media-single.js',
	array(),
	MVS_VERSION,
	true
);

wp_localize_script(
	'mvs-media-single',
	'mvsMedia',
	array(
		'id'         => get_the_ID(),
		'restUrl'    => esc_url_raw( rest_url( 'mvs/v1/' ) ),
		'nonce'      => wp_create_nonce( 'wp_rest' ),
		'isLoggedIn' => is_user_logged_in(),
	)
);

get_footer();
