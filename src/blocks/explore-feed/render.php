<?php
/**
 * Server-side render for the explore-feed block.
 *
 * Queries mvs_media_index directly instead of WP_Query.
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

global $wpdb;
$index_table = $wpdb->prefix . 'mvs_media_index';

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$media_items = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT * FROM {$index_table} WHERE status = 'publish' ORDER BY created_at DESC LIMIT %d",
		$mvs_per_page
	),
	ARRAY_A
);

$total_count = (int) $wpdb->get_var(
	"SELECT COUNT(*) FROM {$index_table} WHERE status = 'publish'"
);
// phpcs:enable

$max_num_pages = $mvs_per_page > 0 ? (int) ceil( $total_count / $mvs_per_page ) : 1;

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-explore-feed-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper  = get_block_wrapper_attributes( array( 'class' => $mvs_classes ) );
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
			'hasMore' => $max_num_pages > 1,
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
				aria-label="<?php esc_attr_e( 'Search media', 'wpmediaverse' ); ?>"
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

	<?php if ( ! empty( $media_items ) ) : ?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?>" data-wp-class--mvs-layout-masonry="state.isMasonry">
			<?php
			foreach ( $media_items as $item ) :
				$item_id    = (int) $item['media_id'];
				$item_title = $item['title'] ?? '';
				$permalink  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $item_id );
				?>
				<div class="mvs-grid-item" data-media-type="<?php echo esc_attr( \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_media_type( $item_id ) ); ?>">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_thumbnail( $item_id, 'large', $item_title ); ?>
					</a>
					<div class="mvs-grid-item-overlay">
						<span class="mvs-grid-item-title"><?php echo esc_html( $item_title ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
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
</div>
