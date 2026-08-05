<?php
/**
 * Server-side render for the album-viewer block.
 *
 * Album is still a CPT (mvs_album), but media items inside it are read from
 * mvs_media_index via mvs_album_items -- not from wp_posts.
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$album_id         = isset( $attributes['albumId'] ) ? absint( $attributes['albumId'] ) : 0;
$columns          = isset( $attributes['columns'] ) ? absint( $attributes['columns'] ) : 3;
$show_title       = ! empty( $attributes['showTitle'] );
$show_description = ! empty( $attributes['showDescription'] );

// Misconfiguration — no album chosen, or an id that is not an album. Show the
// editor what went wrong instead of rendering nothing (Coding Rule 11); a
// blank space is indistinguishable from a broken plugin. Visitors get nothing,
// because a stray "album not found" on a live page helps nobody and hints at
// content they were never meant to know about.
if ( ! $album_id || ! ( $mvs_album_post = get_post( $album_id ) ) || 'mvs_album' !== $mvs_album_post->post_type ) {
	if ( current_user_can( 'edit_posts' ) ) {
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block_empty_state() returns pre-escaped HTML.
		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'icon'    => 'image',
				'title'   => __( 'Album not found', 'wpmediaverse' ),
				'message' => $album_id
					? __( 'This block points at an album that no longer exists. Pick another album in the block settings.', 'wpmediaverse' )
					: __( 'Choose an album in the block settings to display it here.', 'wpmediaverse' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
	return;
}

$album = $mvs_album_post;

// Privacy gate: a members/private album embedded via this block (or the
// [mvs_album] shortcode that shares this render) must not expose its contents
// to viewers who can't see it (audit 2026-06-04). A non-viewer gets nothing.
//
// Deliberately NO empty state here, unlike the not-found branch above. An
// "you cannot see this album" notice confirms the album exists, which is the
// one thing a privacy gate must not disclose. Coding Rule 11 wants the editor
// told about mistakes, not the visitor told about content.
if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( (int) $album_id, get_current_user_id() ) ) {
	return;
}

// Get album items joined with mvs_media_index for full media data.
global $wpdb;
$album_items_table = $wpdb->prefix . 'mvs_album_items';
$index_table       = $wpdb->prefix . 'mvs_media_index';

// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$items = $wpdb->get_results(
	$wpdb->prepare(
		"SELECT idx.* FROM {$album_items_table} ai
		INNER JOIN {$index_table} idx ON idx.media_id = ai.media_id
		WHERE ai.album_id = %d AND idx.status = 'publish'
		ORDER BY ai.position ASC",
		$album_id
	),
	ARRAY_A
);
// phpcs:enable

$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
if ( empty( $mvs_shortcode_context ) ) {
	\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
}
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-album-viewer-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => $mvs_classes ) ) : 'class="' . esc_attr( $mvs_classes ) . '"';
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php if ( $show_title ) : ?>
		<h3 class="mvs-album-title"><?php echo esc_html( $album->post_title ); ?></h3>
	<?php endif; ?>

	<?php if ( $show_description && $album->post_content ) : ?>
		<div class="mvs-album-description"><?php echo wp_kses_post( $album->post_content ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $items ) ) : ?>
		<div class="mvs-media-grid mvs-cols-<?php echo absint( $columns ); ?>">
			<?php foreach ( $items as $item_row ) : ?>
				<?php
				$media_id   = (int) $item_row['media_id'];
				$file_url   = $item_row['file_url'] ?? '';
				$file_type  = $item_row['file_type'] ?? '';
				$media_type = $item_row['media_type'] ?? '';
				$item_title = $item_row['title'] ?? '';
				$permalink  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
				?>
				<div class="mvs-grid-item">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php
						echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->media_thumbnail( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- alt pre-escaped here; helper validates size and emits already-escaped markup.
							$media_id,
							array( 'alt' => esc_attr( $item_title ) )
						);
						?>
					</a>
					<div class="mvs-grid-item-overlay">
						<span><?php echo esc_html( $item_title ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<?php
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block_empty_state() returns pre-escaped HTML; the __() values are data it escapes.
		echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
			array(
				'icon'    => 'image',
				'title'   => __( 'Empty album', 'wpmediaverse' ),
				'message' => __( 'This album is empty.', 'wpmediaverse' ),
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		?>
	<?php endif; ?>
</div>
