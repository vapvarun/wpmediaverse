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
if ( ! \WPMediaVerse\Repository\MediaRepository::exists( $media_id ) ) {
	return;
}

$media_title = \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'title' ) ?: '';
$user_id     = get_current_user_id();
$container   = \WPMediaVerse\Core\Plugin::container();
$privacy     = $container->get( 'privacy' );
$has_access  = $privacy->can_view( $media_id, $user_id );

$file_url  = \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'file_url' );
$file_type = \WPMediaVerse\Repository\MediaRepository::get( $media_id, 'file_type' );
$is_image  = $file_url && 0 === strpos( $file_type, 'image/' );
$wrapper   = get_block_wrapper_attributes( array( 'class' => 'mvs-lock-overlay-block' ) );

// Determine active rule types for display.
$access_rules = $container->get( 'access_rules' );
$rules        = $access_rules->get_rules( $media_id );
$rule_types   = array_unique( array_column( $rules, 'rule_type' ) );

$permalink = \WPMediaVerse\Repository\MediaRepository::get_permalink( $media_id );
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
		<div class="mvs-lock-overlay-content mvs-lock-overlay-locked" style="--mvs-blur: <?php echo absint( $blur_amount ); ?>px; --mvs-overlay-opacity: <?php echo absint( $overlay_opacity ) / 100; ?>">
			<div class="mvs-lock-overlay-preview">
				<?php if ( $is_image ) : ?>
					<img src="<?php echo esc_url( $file_url ); ?>" alt="" loading="lazy" aria-hidden="true" />
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
