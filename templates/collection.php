<?php
/**
 * Template: Single Collection.
 *
 * Displays a collection's matched media items in a grid.
 * Override by copying to your-theme/wpmediaverse/collection.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<div class="mvs-single-collection">
	<?php
	while ( have_posts() ) :
		the_post();

		$collection_id   = get_the_ID();
		$collection_type = get_post_meta( $collection_id, '_mvs_collection_type', true ) ?: 'manual';
		$is_owner        = is_user_logged_in() && (int) get_the_author_meta( 'ID' ) === get_current_user_id();

		// Resolve items.
		$container = \WPMediaVerse\Core\Plugin::container();
		$service   = $container->get( \WPMediaVerse\Services\CollectionService::class );
		$items     = array();

		if ( 'smart' === $collection_type ) {
			$resolved = $service->resolve( $collection_id, 100, 1 );
			$items    = array_column( $resolved['items'], 'media_id' );
		} else {
			global $wpdb;
			$items = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$collection_id
				)
			);
		}

		$rules = $service->get_rules( $collection_id );
		?>

		<article id="mvs-collection-<?php the_ID(); ?>" <?php post_class( 'mvs-collection-article' ); ?>>
			<header class="mvs-collection-header">
				<h1 class="mvs-collection-title"><?php the_title(); ?></h1>
				<span class="mvs-collection-type-badge"><?php echo esc_html( $collection_type ); ?></span>

				<?php if ( get_the_content() ) : ?>
					<div class="mvs-collection-description"><?php the_content(); ?></div>
				<?php endif; ?>

				<?php if ( 'smart' === $collection_type && ! empty( $rules ) ) : ?>
					<div class="mvs-collection-rules-display">
						<?php foreach ( $rules as $rule ) : ?>
							<span class="mvs-rule-pill">
								<?php echo esc_html( $rule['key'] . ': ' . $rule['value'] ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="mvs-collection-meta">
					<?php
					printf(
						/* translators: %d: number of items */
						esc_html( _n( '%d item', '%d items', count( $items ), 'wpmediaverse' ) ),
						count( $items )
					);
					?>
					&middot;
					<?php
					printf(
						/* translators: %s: author name */
						esc_html__( 'by %s', 'wpmediaverse' ),
						esc_html( get_the_author() )
					);
					?>
				</div>
			</header>

			<?php if ( ! empty( $items ) ) : ?>
				<div class="mvs-media-grid mvs-collection-grid">
					<?php
					foreach ( $items as $media_id ) :
						$media_post = get_post( $media_id );
						if ( ! $media_post || 'publish' !== $media_post->post_status ) {
							continue;
						}
						$file_url   = get_post_meta( $media_id, '_mvs_file_url', true );
						$media_type = get_post_meta( $media_id, '_mvs_media_type', true ) ?: 'image';
						$permalink  = get_permalink( $media_id );
						?>
						<div class="mvs-grid-item">
							<a href="<?php echo esc_url( $permalink ); ?>" class="mvs-grid-item-link">
								<?php if ( 'video' === $media_type ) : ?>
									<div class="mvs-grid-item-video-badge">&#9654;</div>
								<?php elseif ( 'audio' === $media_type ) : ?>
									<div class="mvs-grid-item-audio-badge">&#9835;</div>
								<?php endif; ?>
								<?php if ( $file_url && 'image' === $media_type ) : ?>
									<img src="<?php echo esc_url( $file_url ); ?>" alt="<?php echo esc_attr( $media_post->post_title ); ?>" loading="lazy" />
								<?php else : ?>
									<div class="mvs-grid-placeholder mvs-grid-placeholder--<?php echo esc_attr( $media_type ); ?>">
										<?php echo esc_html( strtoupper( $media_type ) ); ?>
									</div>
								<?php endif; ?>
							</a>
							<div class="mvs-grid-item-title"><?php echo esc_html( $media_post->post_title ?: __( '(Untitled)', 'wpmediaverse' ) ); ?></div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="mvs-no-media">
					<?php
					if ( 'smart' === $collection_type ) {
						esc_html_e( 'No media matches the current rules.', 'wpmediaverse' );
					} else {
						esc_html_e( 'This collection is empty.', 'wpmediaverse' );
					}
					?>
				</p>
			<?php endif; ?>
		</article>

	<?php endwhile; ?>
</div>
<?php
wp_enqueue_style( 'mvs-frontend' );
get_footer();
