<?php
/**
 * Server-side render for the media-grid block.
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

$columns        = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : absint( get_option( 'mvs_grid_columns', 3 ) );
$mvs_per_page   = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : absint( get_option( 'mvs_items_per_page', 12 ) );
$media_type     = isset( $attributes['mediaType'] ) ? sanitize_text_field( $attributes['mediaType'] ) : '';
$category       = isset( $attributes['category'] ) ? sanitize_text_field( $attributes['category'] ) : '';
$mvs_tag        = isset( $attributes['tag'] ) ? sanitize_text_field( $attributes['tag'] ) : '';
$order_by       = isset( $attributes['orderBy'] ) ? sanitize_text_field( $attributes['orderBy'] ) : 'date';
$show_lightbox  = ! empty( $attributes['showLightbox'] );
$show_reactions = ! empty( $attributes['showReactions'] );
$gap            = isset( $attributes['gap'] ) ? absint( $attributes['gap'] ) : 8;

// Pagination support -- static front pages use 'page', archive pages use 'paged'.
$mvs_paged = 1;
if ( ! empty( $mvs_shortcode_context ) ) {
	$mvs_paged = max( 1, absint( get_query_var( 'paged' ) ?: get_query_var( 'page' ) ) );
}

global $wpdb;
$index_table = $wpdb->prefix . 'mvs_media_index';
$meta_table  = $wpdb->prefix . 'mvs_media_meta';

// Build WHERE/JOIN clauses.
$where  = "WHERE m.status = 'publish'";
$joins  = '';
$params = array();

if ( $media_type ) {
	$where   .= ' AND m.media_type = %s';
	$params[] = $media_type;
}

// Category filter via term_relationships.
if ( $category ) {
	$cat_term = get_term_by( 'slug', $category, 'mvs_category' );
	if ( $cat_term ) {
		$joins   .= " INNER JOIN {$wpdb->term_relationships} trc ON trc.object_id = m.media_id";
		$where   .= ' AND trc.term_taxonomy_id = %d';
		$params[] = $cat_term->term_taxonomy_id;
	}
}

// Tag filter via term_relationships.
if ( $mvs_tag ) {
	$tag_term = get_term_by( 'slug', $mvs_tag, 'mvs_tag' );
	if ( $tag_term ) {
		$joins   .= " INNER JOIN {$wpdb->term_relationships} trt ON trt.object_id = m.media_id";
		$where   .= ' AND trt.term_taxonomy_id = %d';
		$params[] = $tag_term->term_taxonomy_id;
	}
}

// Exclude non-cover gallery group items.
$where .= " AND m.media_id NOT IN (
	SELECT mm1.media_id FROM {$meta_table} mm1
	INNER JOIN {$meta_table} mm2 ON mm1.media_id = mm2.media_id
	WHERE mm1.meta_key = 'media_group'
	AND mm2.meta_key = 'group_position'
	AND mm2.meta_value != '0'
)";

$order_clause = 'date' === $order_by ? 'm.created_at DESC' : 'm.title ASC';
$offset       = ( $mvs_paged - 1 ) * $mvs_per_page;

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
// Count total.
$count_sql = "SELECT COUNT(DISTINCT m.media_id) FROM {$index_table} m {$joins} {$where}";
if ( ! empty( $params ) ) {
	$count_sql = $wpdb->prepare( $count_sql, ...$params );
}
$found_posts = (int) $wpdb->get_var( $count_sql );

// Fetch items.
$items_sql = "SELECT m.* FROM {$index_table} m {$joins} {$where} ORDER BY {$order_clause} LIMIT %d OFFSET %d";
$all_params   = array_merge( $params, array( $mvs_per_page, $offset ) );
$media_items  = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$all_params ), ARRAY_A );
// phpcs:enable

$max_num_pages = $mvs_per_page > 0 ? (int) ceil( $found_posts / $mvs_per_page ) : 1;
$wrapper       = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => 'mvs-media-grid-block' ) ) : 'class="mvs-media-grid-block"';

// Enqueue universal lightbox when this block is rendered.
if ( $show_lightbox && wp_script_is( 'mvs-lightbox', 'registered' ) ) {
	wp_enqueue_script( 'mvs-lightbox' );
}
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( ! empty( $media_items ) ) : ?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?>" style="--mvs-grid-gap: <?php echo absint( $gap ); ?>px">
			<?php
			foreach ( $media_items as $item ) :
				$item_id             = (int) $item['media_id'];
				$mvs_grid_media_type = \WPMediaVerse\Core\TemplateHelpers::get_media_type( $item_id );
				$mvs_grid_group      = \WPMediaVerse\Services\MediaMeta::get( $item_id, 'media_group' );
				$mvs_grid_group_cnt  = 0;
				if ( $mvs_grid_group ) {
					$mvs_grid_group_cnt = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						"SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = 'media_group' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$mvs_grid_group
					) );
				}
				$mvs_grid_item_class = 'mvs-grid-item' . ( $mvs_grid_group ? ' mvs-grid-item--gallery' : '' );
				$item_title          = $item['title'] ?? '';
				$item_permalink      = \WPMediaVerse\Services\MediaMeta::get_permalink( $item_id );
				?>
				<div class="<?php echo esc_attr( $mvs_grid_item_class ); ?>"
					data-media-id="<?php echo absint( $item_id ); ?>"
					data-media-type="<?php echo esc_attr( $mvs_grid_media_type ); ?>"
				>
					<a href="<?php echo esc_url( $item_permalink ); ?>" class="mvs-grid-item-link">
					<?php \WPMediaVerse\Core\TemplateHelpers::render_grid_thumbnail( $item_id, 'large', $item_title ); ?>
					<?php if ( $mvs_grid_group && $mvs_grid_group_cnt > 1 ) : ?>
						<span class="mvs-gallery-badge" title="<?php echo esc_attr( sprintf( '%d photos', $mvs_grid_group_cnt ) ); ?>">
							<span class="dashicons dashicons-images-alt2"></span> <?php echo esc_html( $mvs_grid_group_cnt ); ?>
						</span>
					<?php endif; ?>
					<div class="mvs-grid-item-overlay">
						<span class="mvs-grid-item-title"><?php echo esc_html( $item_title ); ?></span>
					</div>
					</a>
				</div>
			<?php endforeach; ?>
		</div>

		<?php
		if ( $max_num_pages > 1 ) :
			?>
			<div class="mvs-grid-pagination">
				<span class="mvs-grid-pagination-info">
				<?php
				/* translators: 1: current page, 2: total pages */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d items)', 'wpmediaverse' ), $mvs_paged, $max_num_pages, $found_posts ) );
				?>
				</span>
				<div class="mvs-grid-pagination-links">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'total'     => $max_num_pages,
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
</div>
