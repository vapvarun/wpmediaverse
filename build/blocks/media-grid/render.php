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

// Admin setting is the source of truth for grid columns.
// Block attribute only overrides if user explicitly set a non-default value.
$columns = absint( get_option( 'mvs_grid_columns', 3 ) );
$thumb_style    = \WPMediaVerse\Core\SettingsHelper::get_thumbnail_style();
$mvs_per_page   = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : absint( get_option( 'mvs_items_per_page', 12 ) );
$media_type     = isset( $attributes['mediaType'] ) ? sanitize_text_field( $attributes['mediaType'] ) : '';
$category       = isset( $attributes['category'] ) ? sanitize_text_field( $attributes['category'] ) : '';
$mvs_tag        = isset( $attributes['tag'] ) ? sanitize_text_field( $attributes['tag'] ) : '';
$order_by       = isset( $attributes['orderBy'] ) ? sanitize_text_field( $attributes['orderBy'] ) : 'date';
$order_dir      = isset( $attributes['order'] ) && 'asc' === strtolower( $attributes['order'] ) ? 'ASC' : 'DESC';
$show_lightbox  = ! empty( $attributes['showLightbox'] );
$show_reactions = ! empty( $attributes['showReactions'] );
$gap            = isset( $attributes['gap'] ) ? absint( $attributes['gap'] ) : 8;
$mvs_user_id    = isset( $attributes['userId'] ) ? absint( $attributes['userId'] ) : 0; // 0 = no filter; > 0 scopes to post_author.

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

// Viewer-scoped privacy gate (anon: public only; member: public + members +
// own; moderator: all). Without this the grid rendered every private/members
// item to everyone (audit 2026-06-04). Same rule as the REST /media feed.
$mvs_grid_repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
list( $mvs_priv_sql, $mvs_priv_params ) = $mvs_grid_repo->explore_privacy_clause( 'm', get_current_user_id() );
$where  .= ' AND ' . $mvs_priv_sql;
$params  = array_merge( $params, $mvs_priv_params );

if ( $mvs_user_id > 0 ) {
	$where   .= ' AND m.post_author = %d';
	$params[] = $mvs_user_id;
}

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

// ── ORDER BY clause (and optional JOIN for popularity-based sort) ─────────
// Supported values: date | title | popular | views | reactions | random.
// $order_dir = ASC | DESC (ignored for `random`).
$stats_table = $wpdb->prefix . 'mvs_media_stats';
switch ( $order_by ) {
	case 'title':
		$order_clause = 'm.title ' . $order_dir;
		break;

	case 'popular':
	case 'views':
	case 'reactions':
		// Popular = reactions + views (engagement), 0 fallback for media without a stats row.
		$joins .= " LEFT JOIN {$stats_table} s ON s.media_id = m.media_id";
		if ( 'views' === $order_by ) {
			$order_clause = 'COALESCE(s.views, 0) ' . $order_dir;
		} elseif ( 'reactions' === $order_by ) {
			$order_clause = 'COALESCE(s.reactions, 0) ' . $order_dir;
		} else {
			$order_clause = '(COALESCE(s.reactions, 0) + COALESCE(s.views, 0)) ' . $order_dir;
		}
		break;

	case 'random':
		$order_clause = 'RAND()';
		break;

	case 'date':
	default:
		$order_clause = 'm.created_at ' . $order_dir;
		break;
}

$offset = ( $mvs_paged - 1 ) * $mvs_per_page;

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

// Batch-load index + all meta for the page in 2 queries so each tile renders
// from the request cache instead of ~14 queries/tile, and prime the access-rules
// presence cache so can_view() doesn't COUNT once per tile. (1.7.0)
$mvs_page_ids = array_map( 'intval', array_column( $media_items, 'media_id' ) );
\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->prefetch( $mvs_page_ids );
\WPMediaVerse\Core\Plugin::container()->get( 'access_rules' )->prefetch_active_rules( $mvs_page_ids );

$max_num_pages = $mvs_per_page > 0 ? (int) ceil( $found_posts / $mvs_per_page ) : 1;
$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
if ( empty( $mvs_shortcode_context ) ) {
	\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
}
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-media-grid-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper       = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => $mvs_classes ) ) : 'class="' . esc_attr( $mvs_classes ) . '"';

// Lightbox handled by shared-ui Interactivity API module.
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( ! empty( $media_items ) ) : ?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?><?php echo 'original' === $thumb_style ? ' mvs-grid--original' : ''; ?>" style="--mvs-grid-gap: <?php echo absint( $gap ); ?>px">
			<?php
			foreach ( $media_items as $item ) :
				$item_id             = (int) $item['media_id'];
				$mvs_grid_media_type = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_media_type( $item_id );
				$mvs_grid_signed     = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
				$mvs_grid_file_url   = $mvs_grid_signed ? $mvs_grid_signed->generate( $item_id, get_current_user_id() ) : '';
				$mvs_grid_group      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $item_id, 'media_group' );
				$mvs_grid_group_cnt  = 0;
				if ( $mvs_grid_group ) {
					$mvs_grid_group_cnt = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						"SELECT COUNT(*) FROM {$meta_table} WHERE meta_key = 'media_group' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$mvs_grid_group
					) );
				}
				$mvs_grid_item_class = 'mvs-grid-item' . ( $mvs_grid_group ? ' mvs-grid-item--gallery' : '' );
				$item_title          = $item['title'] ?? '';
				$item_permalink      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $item_id );
				$mvs_grid_author_id  = ! empty( $item['post_author'] ) ? (int) $item['post_author'] : 0;
				$mvs_grid_thumb_url  = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_thumb_url( $item_id, 'large' );
				$mvs_grid_lightbox   = array(
					'id'            => $item_id,
					'title'         => $item_title,
					'description'   => $item['description'] ?? '',
					'media_type'    => $mvs_grid_media_type,
					'file_url'      => $mvs_grid_file_url ?: ( $item['file_url'] ?? '' ),
					'file_type'     => $item['file_type'] ?? '',
					'thumbnail_url' => $mvs_grid_thumb_url,
					'link'          => $item_permalink,
					'media_group'   => $mvs_grid_group ?: '',
					'group_count'   => $mvs_grid_group_cnt,
					'author'        => $mvs_grid_author_id,
					'author_data'   => array(
						// Plain name — third-party filters (bp-verified-member
						// hooks `bp_core_get_user_displayname`) can inject
						// HTML into the raw meta, which would arrive in the
						// lightbox as a literal `<span>` via `data-wp-text`.
						// The lightbox renders decorations from a separate
						// `badge_html` field via `data-wp-init`/`data-wp-watch`,
						// so we mirror the REST payload contract here.
						'name'        => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_display_name_plain( $mvs_grid_author_id ),
						'badge_html'  => (string) apply_filters( 'mvs_user_badge_html', '', (int) $mvs_grid_author_id ),
						'avatar'      => get_avatar_url( $mvs_grid_author_id, array( 'size' => 64 ) ),
						'profile_url' => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $mvs_grid_author_id ),
					),
				);
				?>
				<div class="<?php echo esc_attr( $mvs_grid_item_class ); ?>"
					data-media-id="<?php echo absint( $item_id ); ?>"
					data-media-type="<?php echo esc_attr( $mvs_grid_media_type ); ?>"
					data-media-json="<?php echo esc_attr( wp_json_encode( $mvs_grid_lightbox ) ); ?>"
				>
					<a href="<?php echo esc_url( $item_permalink ); ?>" class="mvs-grid-item-link">
					<?php \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_thumbnail( $item_id, '', $item_title ); // '' = admin-configured grid size + responsive srcset (1.7.0). ?>
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
		<?php
		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'icon'    => 'image',
				'title'   => __( 'No media yet', 'wpmediaverse' ),
				'message' => __( 'No media items found.', 'wpmediaverse' ),
			)
		); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns pre-escaped HTML.
		?>
	<?php endif; ?>
</div>
