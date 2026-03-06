<?php
/**
 * Server-side render for the album-viewer block.
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

// Get album items from the custom table.
global $wpdb;
$table = $wpdb->prefix . 'mvs_album_items';
$items = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->prepare(
		"SELECT media_id FROM {$table} WHERE album_id = %d ORDER BY position ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$album_id
	)
);

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
			<?php foreach ( $items as $media_id ) : ?>
				<?php
				$file_url  = get_post_meta( $media_id, '_mvs_file_url', true );
				$file_type = get_post_meta( $media_id, '_mvs_file_type', true );
				$is_image  = $file_url && strpos( $file_type, 'image/' ) === 0;
				?>
				<div class="mvs-grid-item">
					<?php if ( $is_image ) : ?>
						<img src="<?php echo esc_url( $file_url ); ?>"
							alt="<?php echo esc_attr( get_the_title( $media_id ) ); ?>"
							loading="lazy" />
					<?php else : ?>
						<div class="mvs-grid-item-placeholder">
							<span class="dashicons dashicons-media-default"></span>
						</div>
					<?php endif; ?>
					<div class="mvs-grid-item-overlay">
						<span><?php echo esc_html( get_the_title( $media_id ) ); ?></span>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<p class="mvs-no-media"><?php esc_html_e( 'This album is empty.', 'wpmediaverse' ); ?></p>
	<?php endif; ?>
</div>
