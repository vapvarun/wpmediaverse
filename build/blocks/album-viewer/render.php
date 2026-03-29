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

if ( ! $album_id ) {
	return;
}

$album = get_post( $album_id );
if ( ! $album || 'mvs_album' !== $album->post_type ) {
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

$wrapper = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => 'mvs-album-viewer-block' ) ) : 'class="mvs-album-viewer-block"';
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
				$permalink  = \WPMediaVerse\Services\MediaMeta::get_permalink( $media_id );
				$thumb_url = \WPMediaVerse\Core\TemplateHelpers::get_thumb_url( $media_id, 'large' );
				if ( ! $thumb_url && 'image' === $media_type && $file_url ) {
					$thumb_url = set_url_scheme( $file_url );
				}
				?>
				<div class="mvs-grid-item">
					<a href="<?php echo esc_url( $permalink ); ?>">
						<?php if ( $thumb_url ) : ?>
							<img src="<?php echo esc_url( $thumb_url ); ?>"
								alt="<?php echo esc_attr( $item_title ); ?>"
								loading="lazy" />
							<?php if ( 'video' === $media_type ) : ?>
								<span class="mvs-grid-play-icon">&#9654;</span>
							<?php endif; ?>
						<?php elseif ( 'video' === $media_type ) : ?>
							<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video">
								<span class="mvs-grid-play-icon">&#9654;</span>
							</div>
						<?php elseif ( 'audio' === $media_type ) : ?>
							<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio">
								<span class="mvs-grid-audio-icon">&#9835;</span>
							</div>
						<?php else : ?>
							<div class="mvs-grid-item-placeholder">
								<span class="dashicons dashicons-media-default"></span>
							</div>
						<?php endif; ?>
					</a>
					<div class="mvs-grid-item-overlay">
						<span><?php echo esc_html( $item_title ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="mvs-no-media"><?php esc_html_e( 'This album is empty.', 'wpmediaverse' ); ?></p>
	<?php endif; ?>
</div>
