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

// Logged-out: show a "Log in to upload" CTA instead of a blank gap on the page.
if ( ! is_user_logged_in() ) {
	// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_block_empty_state() returns pre-escaped HTML; the __()/wp_login_url() values are data it escapes.
	echo \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->render_block_empty_state(
		array(
			'icon'    => 'upload',
			'title'   => __( 'Log in to upload', 'wpmediaverse' ),
			'message' => __( 'You need an account to share media.', 'wpmediaverse' ),
			'actions' => array(
				array(
					'url'     => wp_login_url( get_permalink() ),
					'label'   => __( 'Log in', 'wpmediaverse' ),
					'variant' => 'primary',
				),
			),
		)
	);
	// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	return;
}

// Logged-in without the upload capability: stay silent (no broken affordance).
if ( ! current_user_can( 'upload_mvs_media' ) ) {
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
// Stories is a Pro feature, off by default. Show the "share as story" toggle
// only when the option is on AND Pro is actually active to honour it.
//
// The option check alone is not enough: deactivating Pro leaves
// mvs_stories_enabled at '1', so the toggle kept rendering on a Free-only site
// where nothing consumes it — the upload succeeds but no story is ever created,
// which reads as a broken feature rather than an absent one. Basecamp
// #10156642726.
$mvs_stories_on = defined( 'MVS_PRO_VERSION' ) && '1' === get_option( 'mvs_stories_enabled', '0' );
// Pre-check the toggle when arriving from the "Your story" tile (?mvs_story=1). Display-only, no action.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$mvs_prefill_story = $mvs_stories_on && isset( $_GET['mvs_story'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['mvs_story'] ) );

// This uploader is for media (images, videos, audio); documents have their own
// uploader in the Documents library, with the folder/privacy/type controls a
// document needs. Rather than list a "Documents" category the input would
// reject, point members who have documents at the right place. Only when the
// feature is on AND this member may use it (Basecamp 10226421698).
$mvs_docs_url = '';
if ( \WPMediaVerse\Core\Plugin::documents_enabled()
	&& \WPMediaVerse\Core\Plugin::user_can_use_documents( get_current_user_id() )
) {
	$mvs_docs_url = \WPMediaVerse\Core\DashboardSections::url( 'documents' );
}

// i18n for the mvs/media-upload store. It is a script MODULE (viewScriptModule), so
// wp_set_script_translations() can't reach it and window.wp.i18n.__() falls
// through to English. Seed PHP-translated strings into interactivity state; the
// store reads state.i18n.<key> with an English fallback. Basecamp 10073528834.
wp_interactivity_state(
	'mvs/media-upload',
	array(
		'i18n' => array(
			'uploading'          => __( 'Uploading...', 'wpmediaverse' ),
			'uploadOneFile'      => __( 'Upload 1 file', 'wpmediaverse' ),
			/* translators: %d: number of files. */
			'uploadNFiles'       => __( 'Upload %d files', 'wpmediaverse' ),
			/* translators: %d: number of files. */
			'uploadingNFiles'    => __( 'Uploading %d file(s)...', 'wpmediaverse' ),
			'uploadLimitReached' => __( 'Upload limit reached. Please upgrade your plan.', 'wpmediaverse' ),
			/* translators: %1$d: current file number, %2$d: total number of files. */
			'uploadingProgress'  => __( 'Uploading %1$d of %2$d...', 'wpmediaverse' ),
			/* translators: %s: file name. */
			'uploadFailedFor'    => __( 'Upload failed for %s.', 'wpmediaverse' ),
			/* translators: %s: file name. */
			'networkError'       => __( 'Network error uploading %s.', 'wpmediaverse' ),
			/* translators: %d: number of files uploaded. */
			'uploadSuccess'      => __( '%d file(s) uploaded successfully!', 'wpmediaverse' ),
			/* translators: %1$d: number uploaded, %2$d: total number of files. */
			'uploadPartial'      => __( '%1$d of %2$d file(s) uploaded.', 'wpmediaverse' ),
			/* translators: %1$d: number of duplicate files, %2$d: existing media ID. */
			'duplicatesDetected' => __( '%1$d duplicate file(s) detected. Existing media #%2$d already contains this content.', 'wpmediaverse' ),
			'allowedFallback'    => __( 'images, videos, and audio files', 'wpmediaverse' ),
			/* translators: %1$s: rejected file names, %2$s: supported formats. */
			'fileTypeNotAllowed' => __( 'File type not allowed: %1$s. Supported formats: %2$s.', 'wpmediaverse' ),
		),
	)
);
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
			'isStory'        => $mvs_prefill_story,
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
	<?php if ( '' !== $mvs_docs_url ) : ?>
		<p class="mvs-upload-docs-pointer">
			<?php
			printf(
				/* translators: %s: link to the member's Documents library. */
				esc_html__( 'Looking to upload a document? Use your %s.', 'wpmediaverse' ),
				'<a href="' . esc_url( $mvs_docs_url ) . '">' . esc_html__( 'Documents library', 'wpmediaverse' ) . '</a>'
			);
			?>
		</p>
	<?php endif; ?>
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
		<?php if ( $mvs_stories_on ) : ?>
			<label class="mvs-upload-story-toggle">
				<input type="checkbox" data-wp-on--change="actions.toggleStory" <?php checked( $mvs_prefill_story ); ?> />
				<span><?php esc_html_e( 'Also share as a story (visible for 24 hours)', 'wpmediaverse' ); ?></span>
			</label>
		<?php endif; ?>
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
		<p data-wp-text="state.errorMessage"></p>
		<button type="button" class="mvs-upload-error__dismiss" data-wp-on--click="actions.dismissError" aria-label="<?php esc_attr_e( 'Dismiss error', 'wpmediaverse' ); ?>">&times;</button>
	</div>
	<div class="mvs-upload-progress" data-wp-bind--hidden="!state.isUploading" hidden>
		<p data-wp-text="state.uploadStatus"></p>
	</div>
	<div class="mvs-upload-success" data-wp-bind--hidden="!state.hasSuccess" hidden>
		<p data-wp-text="state.successText"></p>
	</div>
</div>
