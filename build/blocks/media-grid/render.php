<?php
/**
 * Server-side render for the media-grid block.
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$columns        = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : absint( get_option( 'mvs_grid_columns', 3 ) );
$mvs_per_page   = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : absint( get_option( 'mvs_items_per_page', 12 ) );
$media_type     = isset( $attributes['mediaType'] ) ? sanitize_text_field( $attributes['mediaType'] ) : '';
$category       = isset( $attributes['category'] ) ? sanitize_text_field( $attributes['category'] ) : '';
$mvs_tag        = isset( $attributes['tag'] ) ? sanitize_text_field( $attributes['tag'] ) : '';
$order_by       = isset( $attributes['orderBy'] ) ? sanitize_text_field( $attributes['orderBy'] ) : 'date';
$show_lightbox  = ! empty( $attributes['showLightbox'] );
$show_reactions = ! empty( $attributes['showReactions'] );
$gap            = isset( $attributes['gap'] ) ? absint( $attributes['gap'] ) : 8;

// Pagination support.
$mvs_paged = 1;
if ( ! empty( $mvs_shortcode_context ) ) {
	$mvs_paged = max( 1, absint( get_query_var( 'paged', 1 ) ) );
}

// Build query.
$query_args = array(
	'post_type'      => 'mvs_media',
	'post_status'    => 'publish',
	'posts_per_page' => $mvs_per_page,
	'paged'          => $mvs_paged,
	'orderby'        => 'date' === $order_by ? 'date' : 'title',
	'order'          => 'DESC',
);

if ( $media_type ) {
	$query_args['meta_key']   = '_mvs_file_type';
	$query_args['meta_value'] = $media_type; // phpcs:ignore WordPress.DB.SlowDBQuery
}

if ( $category ) {
	$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
		array(
			'taxonomy' => 'mvs_category',
			'field'    => 'slug',
			'terms'    => $category,
		),
	);
}

if ( $mvs_tag ) {
	$tax_query               = isset( $query_args['tax_query'] ) ? $query_args['tax_query'] : array();
	$tax_query[]             = array(
		'taxonomy' => 'mvs_tag',
		'field'    => 'slug',
		'terms'    => $mvs_tag,
	);
	$query_args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery
}

$query   = new WP_Query( $query_args );
$wrapper = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => 'mvs-media-grid-block' ) ) : 'class="mvs-media-grid-block"';

// Enqueue universal lightbox when this block is rendered.
if ( $show_lightbox && wp_script_is( 'mvs-lightbox', 'registered' ) ) {
	wp_enqueue_script( 'mvs-lightbox' );
}
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $query->have_posts() ) : ?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?>" style="--mvs-grid-gap: <?php echo absint( $gap ); ?>px">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$mvs_grid_media_type = \WPMediaVerse\Core\TemplateHelpers::get_media_type( get_the_ID() );
				?>
				<div class="mvs-grid-item"
					data-media-id="<?php echo absint( get_the_ID() ); ?>"
					data-media-type="<?php echo esc_attr( $mvs_grid_media_type ); ?>"
				>
					<a href="<?php the_permalink(); ?>" class="mvs-grid-item-link">
					<?php \WPMediaVerse\Core\TemplateHelpers::render_grid_thumbnail( get_the_ID(), 'medium', get_the_title() ); ?>
					<div class="mvs-grid-item-overlay">
						<span class="mvs-grid-item-title"><?php echo esc_html( get_the_title() ); ?></span>
					</div>
					</a>
				</div>
			<?php endwhile; ?>
		</div>

		<?php
		if ( $query->max_num_pages > 1 ) :
			?>
			<div class="mvs-grid-pagination">
				<span class="mvs-grid-pagination-info">
				<?php
				/* translators: 1: current page, 2: total pages */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d items)', 'wpmediaverse' ), $mvs_paged, $query->max_num_pages, $query->found_posts ) );
				?>
				</span>
				<div class="mvs-grid-pagination-links">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'total'     => $query->max_num_pages,
								'current'   => $mvs_paged,
								'prev_text' => '&laquo; ' . __( 'Previous', 'wpmediaverse' ),
								'next_text' => __( 'Next', 'wpmediaverse' ) . ' &raquo;',
								'type'      => 'list',
							)
						)
					);
					?>
				</div>
			</div>
		<?php endif; ?>
	<?php else : ?>
		<p class="mvs-no-media"><?php esc_html_e( 'No media items found.', 'wpmediaverse' ); ?></p>
	<?php endif; ?>
	<?php wp_reset_postdata(); ?>
</div>
