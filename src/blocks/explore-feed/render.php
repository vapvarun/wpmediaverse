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

// '' means inherit the site's Default Layout setting. It used to default
// to 'grid', which made "not set" indistinguishable from "deliberately
// grid" and left no way to follow the site setting. Basecamp 10297763824.
$layout       = isset( $attributes['layout'] ) ? sanitize_text_field( $attributes['layout'] ) : '';
$mvs_per_page = isset( $attributes['perPage'] ) ? absint( $attributes['perPage'] ) : 12;
$show_filters = ! empty( $attributes['showFilters'] );
$show_search  = ! empty( $attributes['showSearch'] );
$columns      = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;

// Gated, viewer-scoped listing — matches the REST /media feed + its Load More
// exactly (anon: public only; member: public + members + own; moderator: all),
// approved-only. Replaces a raw `WHERE status='publish'` query that leaked
// private/members and pending/rejected media to everyone (audit 2026-06-04).
$mvs_repo       = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
$mvs_viewer_id  = get_current_user_id();
$mvs_query_args = array(
	'status'            => 'publish',
	'moderation_status' => 'approved',
	'privacy'           => $mvs_repo->resolve_explore_privacy_mode( $mvs_viewer_id ),
	'viewer_id'         => $mvs_viewer_id,
	'limit'             => $mvs_per_page,
	'offset'            => 0,
	'orderby'           => 'created_at',
	'order'             => 'DESC',
);
$media_items = $mvs_repo->query( $mvs_query_args );
$total_count = $mvs_repo->query_count( $mvs_query_args );
// Batch-load index + all meta for the page in 2 queries so each tile renders
// from the request cache instead of ~14 queries/tile, and prime the access-rules
// presence cache so can_view() doesn't COUNT once per tile. (1.7.0)
$mvs_page_ids = array_map( 'intval', array_column( $media_items, 'media_id' ) );
$mvs_repo->prefetch( $mvs_page_ids );
\WPMediaVerse\Core\Plugin::container()->get( 'access_rules' )->prefetch_active_rules( $mvs_page_ids );

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

// i18n for the mvs/explore-feed store (script module — English-locked __()). Seed
// PHP-translated strings; store reads state.i18n.<key> with English fallback. Basecamp 10073528834.
wp_interactivity_state(
	'mvs/explore-feed',
	array(
		'i18n' => array(
			'loadMoreError' => __( 'Couldn’t load more. Tap to retry.', 'wpmediaverse' ),
			'untitled'      => __( '(untitled)', 'wpmediaverse' ),
		),
	)
);
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/explore-feed"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'restUrl'             => $rest_url,
			// The block's Layout control writes this attribute and view.js reads
			// `getContext().layout` for state.isMasonry - but the key was never
			// put into the context, so two of the three choices (Masonry, List)
			// silently rendered as Grid on every site.
			'page'                => 1,
			'perPage'             => $mvs_per_page,
			'filter'              => '',
			'search'              => '',
			'loading'             => false,
			'hasMore'             => $max_num_pages > 1,
			'suggestions'         => array(),
			'suggestionsOpen'     => false,
			'suggestionHighlight' => -1,
		)
	);
	?>
	'
>
	<?php if ( $show_search ) : ?>
		<div class="mvs-explore-search-wrap">
			<input type="search"
				class="mvs-explore-search"
				role="combobox"
				aria-autocomplete="list"
				aria-controls="mvs-explore-suggestions-list"
				aria-haspopup="listbox"
				placeholder="<?php esc_attr_e( 'Search media...', 'wpmediaverse' ); ?>"
				aria-label="<?php esc_attr_e( 'Search media', 'wpmediaverse' ); ?>"
				data-wp-bind--aria-expanded="state.hasSuggestions"
				data-wp-on--input="actions.handleSearch"
				data-wp-on--keydown="actions.handleSearchKeydown"
				data-wp-on--blur="actions.closeSuggestions"
				data-wp-bind--value="context.search"
			/>
			<!-- Autocomplete dropdown — debounced 250ms, top 8 title matches. -->
			<ul class="mvs-explore-suggestions" id="mvs-explore-suggestions-list"
				role="listbox"
				data-wp-bind--hidden="!state.hasSuggestions">
				<template data-wp-each="context.suggestions">
					<li class="mvs-explore-suggestion"
						role="option"
						data-wp-on--click="actions.selectSuggestion">
						<img class="mvs-explore-suggestion__thumb"
							data-wp-bind--src="context.item.thumb"
							data-wp-bind--hidden="!context.item.thumb"
							alt="" />
						<span class="mvs-explore-suggestion__title" data-wp-text="context.item.title"></span>
					</li>
				</template>
			</ul>
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
		<?php
		// Layout is known at render time, so the class is emitted here rather
		// than bound client-side. The old data-wp-class--mvs-layout-masonry
		// pointed at a class with NO CSS anywhere, so Masonry and List both
		// rendered as Grid. One emitter now serves every grid.
		$mvs_layout_class = \WPMediaVerse\Core\SettingsHelper::grid_layout_class( $layout );
		?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?><?php echo $mvs_layout_class ? ' ' . esc_attr( $mvs_layout_class ) : ''; ?>">
			<?php
			foreach ( $media_items as $item ) :
				$item_id    = (int) $item['media_id'];
				$item_title = $item['title'] ?? '';
				$permalink  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $item_id );
				?>
				<div class="mvs-grid-item" data-media-type="<?php echo esc_attr( \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_media_type( $item_id ) ); ?>"<?php echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->grid_item_ar_style( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper esc_attr()s the ratio. ?>>
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_grid_thumbnail( $item_id, '', $item_title ); // '' = admin-configured grid size + responsive srcset (1.7.0). ?>
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
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block_empty_state() returns pre-escaped HTML; the __() values are data it escapes.
		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'icon'    => 'image',
				'title'   => __( 'No media yet', 'wpmediaverse' ),
				'message' => __( 'No media items found.', 'wpmediaverse' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php endif; ?>
</div>
