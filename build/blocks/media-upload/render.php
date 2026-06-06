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

$max_files     = isset( $attributes['maxFiles'] ) ? absint( $attributes['maxFiles'] ) : 10;
// Admin setting "Allow users to set privacy for their content" can force-hide
// the dropdown regardless of the block attribute (matches Dashboard + FAB modal behaviour).
$show_privacy  = ! empty( $attributes['showPrivacy'] ) && (bool) get_option( 'mvs_allow_user_privacy', true );
$mvs_block_uid = ! empty( $attributes['uniqueId'] ) ? $attributes['uniqueId'] : '';
if ( empty( $mvs_shortcode_context ) ) {
	\WPMediaVerse\Blocks\MVS_CSS::add( $mvs_block_uid, $attributes );
}
$mvs_classes = trim(
	implode(
		' ',
		array_filter(
			array(
				'mvs-upload-block',
				$mvs_block_uid ? 'mvs-block-' . sanitize_html_class( $mvs_block_uid ) : '',
				\WPMediaVerse\Blocks\StandardAttributes::visibility_classes( $attributes ),
			)
		)
	)
);
$wrapper       = empty( $mvs_shortcode_context ) ? get_block_wrapper_attributes( array( 'class' => $mvs_classes ) ) : 'class="' . esc_attr( $mvs_classes ) . '"';
$rest_url      = esc_url( rest_url( 'mvs/v1/media' ) );
$nonce         = wp_create_nonce( 'wp_rest' );
$allowed_types = get_option( 'mvs_allowed_file_types', 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg' );
?>
<div <?php echo $wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-interactive="mvs/media-upload"
	data-wp-context='
	<?php
	echo wp_json_encode(
		array(
			'maxFiles'       => $max_files,
			'restUrl'        => $rest_url,
			'nonce'          => $nonce,
			'uploading'      => false,
			'uploadError'    => '',
			'successMessage' => '',
			'hasPending'     => false,
			'pendingCount'   => 0,
			'files'          => array(),
			'privacy'        => $show_privacy ? get_option( 'mvs_default_privacy', 'public' ) : '',
			'allowedTypes'   => array_map( 'trim', explode( ',', $allowed_types ) ),
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
			aria-label="<?php esc_attr_e( 'Choose files to upload', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.handleFileSelect"
			accept="<?php echo esc_attr( $allowed_types ); ?>"
		/>
		<?php
		if ( $show_privacy ) :
			$default_privacy = get_option( 'mvs_default_privacy', 'public' );
			?>
			<?php
			// 4 privacy levels — backed by ActivityPrivacyFilter's viewer-side
			// gating (1.2.1+). Friends Only only shown when BP friends component
			// is active (otherwise the level has no distinct semantics vs Members).
			$show_friends = function_exists( 'bp_is_active' ) && bp_is_active( 'friends' );
			?>
			<select class="mvs-upload-privacy" data-wp-on--change="actions.setPrivacy" aria-label="<?php esc_attr_e( 'Who can see this media', 'wpmediaverse' ); ?>">
				<option value="public" <?php selected( $default_privacy, 'public' ); ?>><?php esc_html_e( 'Public: anyone can see', 'wpmediaverse' ); ?></option>
				<option value="members" <?php selected( $default_privacy, 'members' ); ?>><?php esc_html_e( 'Members: logged-in users only', 'wpmediaverse' ); ?></option>
				<?php if ( $show_friends ) : ?>
					<option value="friends" <?php selected( $default_privacy, 'friends' ); ?>><?php esc_html_e( 'Friends: BuddyPress friends only', 'wpmediaverse' ); ?></option>
				<?php endif; ?>
				<option value="private" <?php selected( $default_privacy, 'private' ); ?>><?php esc_html_e( 'Only me: hidden from everyone else', 'wpmediaverse' ); ?></option>
			</select>
		<?php endif; ?>
	</div>
	<!-- Optional metadata fields -->
	<div class="mvs-upload-fields">
		<input type="text" class="mvs-upload-title-input"
			placeholder="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>"
			aria-label="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.setTitle" />
		<textarea class="mvs-upload-desc-input" rows="2"
			placeholder="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>"
			aria-label="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.setDescription"></textarea>
		<input type="text" class="mvs-upload-tags-input"
			placeholder="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>"
			aria-label="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>"
			data-wp-on--change="actions.setTags" />
	</div>
	<!-- Review step: shown after files are selected so the user can fill the
	     details above before the upload starts. -->
	<div class="mvs-upload-review" data-wp-bind--hidden="!state.hasPending" hidden>
		<p class="mvs-upload-review-hint"><?php esc_html_e( 'Add details above (optional), then upload.', 'wpmediaverse' ); ?></p>
		<div class="mvs-upload-review-actions">
			<button type="button" class="mvs-upload-confirm" data-wp-on--click="actions.confirmUpload" data-wp-text="state.pendingLabel"></button>
			<button type="button" class="mvs-upload-cancel" data-wp-on--click="actions.cancelPending"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
		</div>
	</div>
	<div class="mvs-upload-error" data-wp-bind--hidden="!state.hasError" hidden>
		<p data-wp-text="state.errorMessage" style="margin:0;flex:1;"></p>
		<button type="button" data-wp-on--click="actions.dismissError" style="background:none;border:none;color:#d63638;cursor:pointer;font-size:18px;padding:0 4px;line-height:1;" aria-label="<?php esc_attr_e( 'Dismiss error', 'wpmediaverse' ); ?>">&times;</button>
	</div>
	<div class="mvs-upload-progress" data-wp-bind--hidden="!state.isUploading" hidden>
		<p data-wp-text="state.uploadStatus"></p>
	</div>
	<div class="mvs-upload-success" data-wp-bind--hidden="!state.hasSuccess" hidden>
		<p data-wp-text="state.successText" style="color:#00a32a;margin:0;"></p>
	</div>
</div>
