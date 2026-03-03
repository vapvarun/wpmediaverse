<?php
/**
 * Template: Single Album.
 *
 * Override by copying to your-theme/wpmediaverse/album.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-single-album">
	<?php
	while ( have_posts() ) :
		the_post();

		global $wpdb;
		$table = $wpdb->prefix . 'mvs_album_items';
		$items = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$table} WHERE album_id = %d ORDER BY position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				get_the_ID()
			)
		);
		?>

		<article id="mvs-album-<?php the_ID(); ?>" <?php post_class( 'mvs-album-article' ); ?>>
			<header class="mvs-album-header">
				<h1 class="mvs-album-title"><?php the_title(); ?></h1>
				<?php if ( get_the_content() ) : ?>
					<div class="mvs-album-description"><?php the_content(); ?></div>
				<?php endif; ?>
				<span class="mvs-album-count">
					<?php
					printf(
						/* translators: %d: number of items */
						esc_html( _n( '%d item', '%d items', count( $items ), 'wpmediaverse' ) ),
						count( $items )
					);
					?>
				</span>
			</header>

			<?php if ( ! empty( $items ) ) : ?>
				<div class="mvs-media-grid mvs-cols-3">
					<?php foreach ( $items as $media_id ) : ?>
						<?php
						$file_url  = get_post_meta( $media_id, '_mvs_file_url', true );
						$file_type = get_post_meta( $media_id, '_mvs_file_type', true );
						$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
						?>
						<div class="mvs-grid-item">
							<?php if ( $is_image ) : ?>
								<a href="<?php echo esc_url( get_permalink( $media_id ) ); ?>">
									<img src="<?php echo esc_url( $file_url ); ?>"
										alt="<?php echo esc_attr( get_the_title( $media_id ) ); ?>"
										loading="lazy" />
								</a>
							<?php endif; ?>
							<div class="mvs-grid-item-overlay">
								<span><?php echo esc_html( get_the_title( $media_id ) ); ?></span>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="mvs-no-media"><?php esc_html_e( 'This album is empty.', 'wpmediaverse' ); ?></p>
			<?php endif; ?>
		</article>

	<?php endwhile; ?>
</div>
<?php
get_footer();
