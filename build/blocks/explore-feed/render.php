<?php
/**
 * Server-side render for the explore-feed block.
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$layout       = isset( $attributes['layout'] ) ? sanitize_text_field( $attributes['layout'] ) : 'grid';
$mvs_per_page = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : 12;
$show_filters = ! empty( $attributes['showFilters'] );
$show_search  = ! empty( $attributes['showSearch'] );
$columns      = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;

$query = new WP_Query(
	array(
		'post_type'      => 'mvs_media',
		'post_status'    => 'publish',
		'posts_per_page' => $mvs_per_page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	)
);

$wrapper  = get_block_wrapper_attributes( array( 'class' => 'mvs-explore-feed-block' ) );
$rest_url = esc_url( rest_url( 'mvs/v1/media' ) );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/explore-feed"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'restUrl' => $rest_url,
			'page'    => 1,
			'perPage' => $mvs_per_page,
			'filter'  => '',
			'search'  => '',
			'loading' => false,
			'hasMore' => $query->max_num_pages > 1,
		)
	);
	?>
	'
>
	<?php if ( $show_search ) : ?>
		<div class="mvs-explore-filters">
			<input type="search"
				class="mvs-explore-search"
				placeholder="<?php esc_attr_e( 'Search media...', 'wpmediaverse' ); ?>"
				data-wp-on--input="actions.handleSearch"
				data-wp-bind--value="context.search"
			/>
		</div>
	<?php endif; ?>

	<?php if ( $show_filters ) : ?>
		<div class="mvs-explore-filters">
			<?php
			$types = array(
				''      => __( 'All', 'wpmediaverse' ),
				'image' => __( 'Images', 'wpmediaverse' ),
				'video' => __( 'Video', 'wpmediaverse' ),
				'audio' => __( 'Audio', 'wpmediaverse' ),
			);
			foreach ( $types as $value => $label ) :
				?>
				<button class="mvs-explore-filter-btn"
					data-wp-on--click="actions.setFilter"
					data-wp-class--active="state.isActiveFilter"
					data-wp-context='<?php echo wp_json_encode( array( 'filterValue' => $value ) ); ?>'
				>
					<?php echo esc_html( $label ); ?>
				</button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>

	<?php if ( $query->have_posts() ) : ?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?>" data-wp-class--mvs-layout-masonry="state.isMasonry">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$file_url  = get_post_meta( get_the_ID(), '_mvs_file_url', true );
				$file_type = get_post_meta( get_the_ID(), '_mvs_file_type', true );
				$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
				?>
				<div class="mvs-grid-item">
					<?php if ( $is_image ) : ?>
						<img src="<?php echo esc_url( $file_url ); ?>"
							alt="<?php echo esc_attr( get_the_title() ); ?>"
							loading="lazy" />
					<?php else : ?>
						<div class="mvs-grid-item-placeholder">
							<span class="dashicons dashicons-media-<?php echo esc_attr( strpos( $file_type, 'video/' ) === 0 ? 'video' : ( strpos( $file_type, 'audio/' ) === 0 ? 'audio' : 'default' ) ); ?>"></span>
						</div>
					<?php endif; ?>
					<div class="mvs-grid-item-overlay">
						<span class="mvs-grid-item-title"><?php echo esc_html( get_the_title() ); ?></span>
					</div>
				</div>
			<?php endwhile; ?>
		</div>

		<button class="mvs-load-more"
			data-wp-bind--hidden="!context.hasMore"
			data-wp-on--click="actions.loadMore"
			data-wp-bind--disabled="context.loading"
		>
			<span data-wp-bind--hidden="context.loading"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></span>
			<span data-wp-bind--hidden="!context.loading"><?php esc_html_e( 'Loading...', 'wpmediaverse' ); ?></span>
		</button>
	<?php else : ?>
		<p class="mvs-no-media"><?php esc_html_e( 'No media items found.', 'wpmediaverse' ); ?></p>
	<?php endif; ?>
	<?php wp_reset_postdata(); ?>
</div>
