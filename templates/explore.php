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
		<h1>
		<?php
		if ( is_tax( 'mvs_tag' ) ) {
			printf(
				/* translators: %s: tag name */
				esc_html__( 'Tag: %s', 'wpmediaverse' ),
				esc_html( single_term_title( '', false ) )
			);
		} elseif ( is_tax( 'mvs_category' ) ) {
			printf(
				/* translators: %s: category name */
				esc_html__( 'Category: %s', 'wpmediaverse' ),
				esc_html( single_term_title( '', false ) )
			);
		} else {
			esc_html_e( 'Explore', 'wpmediaverse' );
		}
		?>
		</h1>
		<?php if ( is_tax() && term_description() ) : ?>
			<p class="mvs-explore-term-desc"><?php echo wp_kses_post( term_description() ); ?></p>
		<?php endif; ?>
	</header>

	<!-- Search Bar -->
	<div class="mvs-explore-search">
		<form method="get" action="<?php echo esc_url( get_post_type_archive_link( 'mvs_media' ) ); ?>">
			<input type="text" name="s" placeholder="<?php esc_attr_e( 'Search media...', 'wpmediaverse' ); ?>"
				value="<?php echo esc_attr( get_search_query() ); ?>" />
			<button type="submit"><?php esc_html_e( 'Search', 'wpmediaverse' ); ?></button>
		</form>
	</div>

	<!-- Tag Cloud (Interactivity API) -->
	<?php
	$mvs_explore_ctx = array(
		'restUrl'    => esc_url_raw( rest_url( 'mvs/v1/' ) ),
		'archiveUrl' => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
		'activeTag'  => is_tax( 'mvs_tag' ) ? get_queried_object()->slug : ( isset( $_GET['mvs_tag'] ) ? sanitize_text_field( wp_unslash( $_GET['mvs_tag'] ) ) : '' ), // phpcs:ignore WordPress.Security.NonceVerification
		'tags'       => array(),
		'loaded'     => false,
	);
	?>
	<div class="mvs-tag-cloud"
		data-wp-interactive="mvs/explore"
		<?php echo wp_interactivity_data_wp_context( $mvs_explore_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		data-wp-init="callbacks.init">
		<template data-wp-each="context.tags">
			<a class="mvs-tag-cloud-item"
				data-wp-bind--href="context.item.href"
				data-wp-text="context.item.name"
				data-wp-class--active="context.item.active"></a>
		</template>
	</div>

	<?php if ( have_posts() ) : ?>
		<div class="mvs-media-grid mvs-cols-3 mvs-feed">
			<?php
			while ( have_posts() ) :
				the_post();

				$post_type = get_post_type();
				$is_album  = ( 'mvs_album' === $post_type );

				if ( $is_album ) {
					$container  = \WPMediaVerse\Core\Plugin::container();
					$album_svc  = $container->get( 'albums' );
					$item_count = $album_svc->get_item_count( get_the_ID() );
					$cover_url  = $album_svc->get_cover_url( get_the_ID() );
				} else {
					$stats_data = \WPMediaVerse\Core\TemplateHelpers::bulk_get_stats( array( get_the_ID() ) );
					$my_stats   = $stats_data[ get_the_ID() ] ?? array();
				}
				?>
				<?php if ( $is_album ) : ?>
				<div class="mvs-grid-item mvs-grid-item--album">
					<a href="<?php the_permalink(); ?>" class="mvs-grid-item-link">
						<?php if ( $cover_url ) : ?>
							<img src="<?php echo esc_url( $cover_url ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
								loading="lazy" />
						<?php else : ?>
							<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album">
								<span class="mvs-grid-album-icon">&#128193;</span>
							</div>
						<?php endif; ?>
						<span class="mvs-album-badge" title="<?php echo esc_attr( sprintf( '%d items', $item_count ) ); ?>">
							<span class="dashicons dashicons-images-alt2"></span>
						</span>
						<div class="mvs-grid-item-overlay">
							<div class="mvs-grid-item-stats">
								<span class="mvs-grid-stat">&#x1F5BC;&#xFE0F; <?php echo esc_html( $item_count ); ?></span>
							</div>
						</div>
					</a>
					<div class="mvs-grid-item-info">
						<?php echo get_avatar( get_the_author_meta( 'ID' ), 24, '', '', array( 'class' => 'mvs-grid-avatar' ) ); ?>
						<span class="mvs-grid-item-author"><?php echo esc_html( get_the_author() ); ?></span>
					</div>
				</div>
				<?php else : ?>
				<?php
				\WPMediaVerse\Core\TemplateHelpers::render_grid_item(
					get_the_ID(),
					$my_stats,
					array( 'show_author' => true )
				);
				?>
				<?php endif; ?>
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
// Enqueue Interactivity API stores.
$mvs_explore_asset_file = MVS_PLUGIN_DIR . 'build/blocks/explore-view/view.asset.php';
$mvs_explore_asset      = file_exists( $mvs_explore_asset_file ) ? require $mvs_explore_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
wp_enqueue_script_module(
	'mvs-explore-view',
	MVS_PLUGIN_URL . 'build/blocks/explore-view/view.js',
	$mvs_explore_asset['dependencies'],
	$mvs_explore_asset['version']
);

get_footer();
