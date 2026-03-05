<?php
/**
 * Server-side render for the lock-overlay block.
 *
 * Shows blurred preview + unlock prompt for gated media, or full content if user has access.
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
$show_price      = ! empty( $attributes['showPrice'] );
$unlock_label    = ! empty( $attributes['unlockLabel'] ) ? sanitize_text_field( $attributes['unlockLabel'] ) : __( 'Unlock This Media', 'wpmediaverse' );
$overlay_opacity = isset( $attributes['overlayOpacity'] ) ? absint( $attributes['overlayOpacity'] ) : 60;

if ( ! $media_id ) {
	return;
}

$post = get_post( $media_id );
if ( ! $post || 'mvs_media' !== $post->post_type ) {
	return;
}

$user_id     = get_current_user_id();
$container   = \WPMediaVerse\Core\Plugin::container();
$privacy     = $container->get( 'privacy' );
$has_access  = $privacy->can_view( $media_id, $user_id );

$file_url    = get_post_meta( $media_id, '_mvs_file_url', true );
$file_type   = get_post_meta( $media_id, '_mvs_file_type', true );
$is_image    = $file_url && 0 === strpos( $file_type, 'image/' );
$wrapper     = get_block_wrapper_attributes( array( 'class' => 'mvs-lock-overlay-block' ) );

// Get pricing info from access rules.
$access_rules = $container->get( 'access_rules' );
$rules        = $access_rules->get_rules( $media_id );
$price_info   = '';
if ( $show_price && $rules ) {
	foreach ( $rules as $rule ) {
		if ( ! empty( $rule['price'] ) ) {
			$currency   = ! empty( $rule['currency'] ) ? $rule['currency'] : 'USD';
			$price_info = number_format( (float) $rule['price'], 2 ) . ' ' . esc_html( $currency );
			break;
		}
	}
}

// Determine rule types for frontend context.
$rule_types = array_unique( array_column( $rules, 'rule_type' ) );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/lock-overlay"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'mediaId'   => $media_id,
			'hasAccess' => $has_access,
			'price'     => $price_info,
			'ruleTypes' => $rule_types,
			'loading'   => false,
			'message'   => '',
		)
	);
	?>
	'
>
	<?php if ( $has_access ) : ?>
		<div class="mvs-lock-overlay-content mvs-lock-overlay-unlocked">
			<?php if ( $is_image ) : ?>
				<img src="<?php echo esc_url( $file_url ); ?>" alt="<?php echo esc_attr( $post->post_title ); ?>" loading="lazy" />
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
					<span><?php echo esc_html( $post->post_title ); ?></span>
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
				<h3 class="mvs-lock-overlay-title"><?php echo esc_html( $post->post_title ); ?></h3>
				<?php if ( $price_info ) : ?>
					<p class="mvs-lock-overlay-price"><?php echo esc_html( $price_info ); ?></p>
				<?php endif; ?>
				<?php if ( $user_id ) : ?>
					<button
						class="mvs-lock-overlay-btn wp-element-button"
						data-wp-on--click="actions.initiateCheckout"
						data-wp-bind--disabled="context.loading"
					>
						<span data-wp-text="context.loading ? '<?php echo esc_attr__( 'Processing...', 'wpmediaverse' ); ?>' : '<?php echo esc_attr( $unlock_label ); ?>'"></span>
					</button>
				<?php else : ?>
					<a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>" class="mvs-lock-overlay-btn wp-element-button">
						<?php esc_html_e( 'Log in to Unlock', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
				<p class="mvs-lock-overlay-message" data-wp-text="context.message"></p>
			</div>
		</div>
	<?php endif; ?>
</div>
