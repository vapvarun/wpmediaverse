<?php
/**
 * Server-side render for the lock-overlay block.
 *
 * Shows blurred preview + unlock prompt for gated media, or full content if user has access.
 * Reads media data from mvs_media_index via MediaMeta -- no get_post().
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

$media_id        = isset( $attributes['mediaId'] ) ? absint( $attributes['mediaId'] ) : 0;
$blur_amount     = isset( $attributes['blurAmount'] ) ? absint( $attributes['blurAmount'] ) : 20;
$unlock_label    = ! empty( $attributes['unlockLabel'] ) ? sanitize_text_field( $attributes['unlockLabel'] ) : __( 'Restricted Content', 'wpmediaverse' );
$overlay_opacity = isset( $attributes['overlayOpacity'] ) ? absint( $attributes['overlayOpacity'] ) : 60;

if ( ! $media_id ) {
	return;
}

// Verify media exists in the index table.
if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
	return;
}

$media_title = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'title' ) ?: '';
$user_id     = get_current_user_id();
$container   = \WPMediaVerse\Core\Plugin::container();
$privacy     = $container->get( 'privacy' );
$has_access  = $privacy->can_view( $media_id, $user_id );

$file_type   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
$is_image    = 0 === strpos( (string) $file_type, 'image/' );
// Full signed URL only for users with access; MediaUrl::file() already
// checks can_view() and returns '' when denied.
$file_url    = \WPMediaVerse\Core\MediaUrl::file( $media_id, $user_id );

// Locked-user teaser. Default: the standard thumbnail shown CSS-blurred. When
// watermarking is enabled and a watermarked preview exists for this gated image,
// show THAT instead and drop the blur — the watermark is the intended
// protection for a gated teaser, and blurring it would defeat the purpose. This
// is the render-side consumer of WatermarkService's preview (the same image the
// REST response exposes as preview_url / watermarked).
$preview_url    = \WPMediaVerse\Core\MediaUrl::thumb( $media_id, 'large', 0, $user_id );
$is_watermarked = false;
if ( ! $has_access && $is_image ) {
	$watermark = $container->get( 'watermark' );

	$uploader_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'post_author' );

	/**
	 * Two cases for a locked viewer: watermark on -> show the watermarked image;
	 * watermark off -> show the plain blurred teaser. Watermarking applies to
	 * IMAGES only (video/audio are never watermarked — enforced in
	 * WatermarkService::get_preview_url()). Role targeting keys off the UPLOADER:
	 * by default every gated image is watermarked (so e.g. all subscriber uploads
	 * get the mark); a site can exclude higher roles by returning false, e.g.
	 * skip when the uploader can edit others' posts (editor/admin).
	 *
	 * @param bool $apply       Whether to apply the watermark.
	 * @param int  $media_id    Media ID.
	 * @param int  $uploader_id Author/uploader of the media (for role targeting).
	 * @param int  $user_id     Current viewer (0 = logged out).
	 */
	$apply_watermark = $watermark->is_enabled()
		&& (bool) apply_filters( 'mvs_apply_watermark_preview', true, $media_id, $uploader_id, $user_id );

	if ( $apply_watermark ) {
		$watermarked_preview = $watermark->get_preview_url( $media_id );
		if ( '' !== $watermarked_preview ) {
			$preview_url    = $watermarked_preview;
			$is_watermarked = true;
		}
	}
}

// When watermarked, the watermark replaces the blur/heavy overlay as the
// protection, so show the image clearly (no blur, light overlay so the mark
// stays visible); otherwise keep the configured blur + overlay teaser.
$lock_blur    = $is_watermarked ? 0 : absint( $blur_amount );
$lock_opacity = ( $is_watermarked ? min( absint( $overlay_opacity ), 20 ) : absint( $overlay_opacity ) ) / 100;
$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-lock-overlay-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper   = get_block_wrapper_attributes( array( 'class' => $mvs_classes ) );

// Determine active rule types for display.
$access_rules = $container->get( 'access_rules' );
$rules        = $access_rules->get_rules( $media_id );
$rule_types   = array_unique( array_column( $rules, 'rule_type' ) );

$permalink = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/lock-overlay"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'mediaId'   => $media_id,
			'hasAccess' => $has_access,
			'ruleTypes' => $rule_types,
		)
	);
	?>
	'
>
	<?php if ( $has_access ) : ?>
		<div class="mvs-lock-overlay-content mvs-lock-overlay-unlocked">
			<?php if ( $is_image ) : ?>
				<img src="<?php echo esc_url( $file_url ); ?>" alt="<?php echo esc_attr( $media_title ); ?>" loading="lazy" />
			<?php elseif ( 0 === strpos( $file_type, 'video/' ) ) : ?>
				<video controls preload="metadata">
					<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
				</video>
			<?php elseif ( 0 === strpos( $file_type, 'audio/' ) ) : ?>
				<audio controls preload="metadata">
					<source src="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $file_type ); ?>" />
				</audio>
			<?php else : ?>
				<div class="mvs-lock-overlay-file">
					<span class="dashicons dashicons-media-default"></span>
					<span><?php echo esc_html( $media_title ); ?></span>
				</div>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="mvs-lock-overlay-content mvs-lock-overlay-locked<?php echo $is_watermarked ? ' is-watermarked' : ''; ?>" style="--mvs-blur: <?php echo (int) $lock_blur; ?>px; --mvs-overlay-opacity: <?php echo esc_attr( (string) $lock_opacity ); ?>">
			<div class="mvs-lock-overlay-preview">
				<?php if ( $is_image && $preview_url ) : ?>
					<img src="<?php echo esc_url( $preview_url ); ?>" alt="" loading="lazy" aria-hidden="true" />
				<?php else : ?>
					<div class="mvs-lock-overlay-placeholder">
						<span class="dashicons dashicons-lock"></span>
					</div>
				<?php endif; ?>
			</div>
			<div class="mvs-lock-overlay-prompt">
				<span class="dashicons dashicons-lock mvs-lock-icon"></span>
				<h3 class="mvs-lock-overlay-title"><?php echo esc_html( $media_title ); ?></h3>
				<p class="mvs-lock-overlay-info"><?php echo esc_html( $unlock_label ); ?></p>
				<?php if ( ! $user_id ) : ?>
					<a href="<?php echo esc_url( wp_login_url( $permalink ) ); ?>" class="mvs-lock-overlay-btn wp-element-button">
						<?php esc_html_e( 'Log in to View', 'wpmediaverse' ); ?>
					</a>
				<?php else : ?>
					<p class="mvs-lock-overlay-restricted">
						<?php esc_html_e( 'You do not have access to this content. Contact the site administrator for access.', 'wpmediaverse' ); ?>
					</p>
				<?php endif; ?>
			</div>
		</div>
	<?php endif; ?>
</div>
