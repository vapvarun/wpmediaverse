<?php
/**
 * Server-side render for the media-upload block.
 *
 * @package WPMediaVerse
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block content.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() || ! current_user_can( 'upload_mvs_media' ) ) {
	return;
}

$max_files    = isset( $attributes['maxFiles'] ) ? absint( $attributes['maxFiles'] ) : 10;
$show_privacy = ! empty( $attributes['showPrivacy'] );
$wrapper      = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => 'mvs-upload-block' ) ) : 'class="mvs-upload-block"';
$rest_url     = esc_url( rest_url( 'mvs/v1/media' ) );
$nonce        = wp_create_nonce( 'wp_rest' );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/media-upload"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'maxFiles'  => $max_files,
			'restUrl'   => $rest_url,
			'nonce'     => $nonce,
			'uploading' => false,
			'files'     => array(),
		)
	);
	?>
	'
>
	<div class="mvs-upload-dropzone"
		data-wp-on--click="actions.handleClick"
		data-wp-on--dragover="actions.handleDragOver"
		data-wp-on--dragleave="actions.handleDragLeave"
		data-wp-on--drop="actions.handleDrop"
		data-wp-class--mvs-dragover="state.isDragOver"
	>
		<div class="mvs-upload-icon">
			<svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
				<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
				<polyline points="17 8 12 3 7 8"></polyline>
				<line x1="12" y1="3" x2="12" y2="15"></line>
			</svg>
		</div>
		<p class="mvs-upload-text"><?php esc_html_e( 'Drag & drop files here or click to browse', 'wpmediaverse' ); ?></p>
		<input type="file" class="mvs-upload-input" multiple
			data-wp-on--change="actions.handleFileSelect"
			accept="image/*,video/*,audio/*"
		/>
		<?php if ( $show_privacy ) : ?>
			<select class="mvs-upload-privacy" data-wp-on--change="actions.setPrivacy">
				<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
				<option value="members"><?php esc_html_e( 'Members Only', 'wpmediaverse' ); ?></option>
				<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
			</select>
		<?php endif; ?>
	</div>
	<!-- Optional metadata fields -->
	<div class="mvs-upload-fields">
		<input type="text" class="mvs-upload-title-input"
			placeholder="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.setTitle" />
		<textarea class="mvs-upload-desc-input" rows="2"
			placeholder="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.setDescription"></textarea>
		<input type="text" class="mvs-upload-tags-input"
			placeholder="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.setTags" />
	</div>
	<div class="mvs-upload-progress" data-wp-bind--hidden="!state.isUploading">
		<p data-wp-text="state.uploadStatus"></p>
	</div>
</div>
