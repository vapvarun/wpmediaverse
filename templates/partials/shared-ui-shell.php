<?php
/**
 * Shared UI shell — rendered in wp_footer on all frontend pages.
 *
 * Provides global components:
 * - Floating action button (FAB) for upload
 * - Upload modal (photo, gallery, album, video)
 * - Media lightbox overlay
 * - Toast notifications
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	return;
}

$mvs_rest_url = esc_url_raw( rest_url( 'mvs/v1/' ) );
$mvs_nonce    = wp_create_nonce( 'wp_rest' );
?>
<div class="mvs-app-shell"
	data-wp-interactive="mvs/shared-ui"
	<?php
	echo wp_interactivity_data_wp_context(
		array(
			'restUrl' => $mvs_rest_url,
			'nonce'   => $mvs_nonce,
		)
	);
	?>
	data-wp-on--keydown="actions.handleLightboxKeydown"
>
	<!-- Floating Action Button -->
	<div class="mvs-fab-container">
		<button class="mvs-fab" data-wp-on--click="actions.openUploadModal"
			data-wp-context='{"uploadMode":"photo"}'
			aria-label="<?php esc_attr_e( 'Upload media', 'wpmediaverse' ); ?>">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" width="24" height="24" aria-hidden="true">
				<line x1="12" y1="5" x2="12" y2="19"></line>
				<line x1="5" y1="12" x2="19" y2="12"></line>
			</svg>
		</button>
	</div>

	<!-- Upload Modal Overlay -->
	<div class="mvs-modal-overlay" data-wp-bind--hidden="!state.uploadModal.visible" data-wp-on--click="actions.closeUploadModal">
		<div class="mvs-modal" data-wp-on--click="event.stopPropagation()">
			<!-- Modal Header -->
			<div class="mvs-modal-header">
				<h3 class="mvs-modal-title" data-wp-text="state.uploadModalTitle"></h3>
				<button class="mvs-modal-close" data-wp-on--click="actions.closeUploadModal" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true">
						<line x1="18" y1="6" x2="6" y2="18"></line>
						<line x1="6" y1="6" x2="18" y2="18"></line>
					</svg>
				</button>
			</div>

			<!-- Mode Tabs -->
			<div class="mvs-modal-tabs">
				<button class="mvs-modal-tab" data-wp-class--active="state.uploadModal.mode === 'photo'"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"photo"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
						<circle cx="8.5" cy="8.5" r="1.5"></circle>
						<polyline points="21 15 16 10 5 21"></polyline>
					</svg>
					<?php esc_html_e( 'Photo', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.uploadModal.mode === 'gallery'"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"gallery"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<rect x="2" y="2" width="16" height="16" rx="2"></rect>
						<rect x="6" y="6" width="16" height="16" rx="2"></rect>
					</svg>
					<?php esc_html_e( 'Gallery', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.uploadModal.mode === 'album'"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"album"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
					</svg>
					<?php esc_html_e( 'Album', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.uploadModal.mode === 'video'"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"video"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<polygon points="23 7 16 12 23 17 23 7"></polygon>
						<rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
					</svg>
					<?php esc_html_e( 'Video', 'wpmediaverse' ); ?>
				</button>
			</div>

			<!-- Modal Body -->
			<div class="mvs-modal-body">
				<!-- Album fields (only in album mode) -->
				<div class="mvs-modal-album-fields" data-wp-bind--hidden="!state.isAlbumMode">
					<div class="mvs-modal-field">
						<label for="mvs-modal-album-title"><?php esc_html_e( 'Album Name', 'wpmediaverse' ); ?></label>
						<input type="text" id="mvs-modal-album-title"
							placeholder="<?php esc_attr_e( 'Enter album name...', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateAlbumTitle" />
					</div>
					<div class="mvs-modal-field">
						<label for="mvs-modal-album-desc"><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
						<textarea id="mvs-modal-album-desc" rows="2"
							placeholder="<?php esc_attr_e( 'Album description (optional)...', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateAlbumDescription"></textarea>
					</div>
				</div>

				<!-- Dropzone -->
				<div class="mvs-modal-dropzone" data-wp-on--click="actions.handleUploadClick"
					data-wp-on--drop="actions.handleUploadDrop"
					data-wp-on--dragover="actions.handleUploadDragOver"
					data-wp-bind--hidden="state.uploadModal.uploading">
					<input type="file" id="mvs-modal-file-input" style="display:none"
						data-wp-bind--accept="state.uploadAccept"
						data-wp-bind--multiple="state.uploadMultiple"
						data-wp-on--change="actions.handleFileSelect" />

					<!-- Preview thumbnails -->
					<div class="mvs-modal-previews" data-wp-bind--hidden="!state.hasFiles">
						<template data-wp-each="state.uploadModal.previews">
							<img class="mvs-modal-preview-thumb" data-wp-bind--src="context.item" alt="" />
						</template>
					</div>

					<!-- Dropzone placeholder -->
					<div class="mvs-modal-dropzone-placeholder" data-wp-bind--hidden="state.hasFiles">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="48" height="48" aria-hidden="true">
							<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
							<polyline points="17 8 12 3 7 8"></polyline>
							<line x1="12" y1="3" x2="12" y2="15"></line>
						</svg>
						<p><?php esc_html_e( 'Drag & drop files here or click to browse', 'wpmediaverse' ); ?></p>
					</div>
				</div>

				<!-- Upload progress -->
				<div class="mvs-modal-progress" data-wp-bind--hidden="!state.uploadModal.uploading">
					<div class="mvs-modal-progress-bar">
						<div class="mvs-modal-progress-fill"
							data-wp-style--width="(state.uploadModal.total ? Math.round((state.uploadModal.done / state.uploadModal.total) * 100) : 0) + '%'"></div>
					</div>
					<p class="mvs-modal-progress-text" data-wp-text="state.uploadProgressText"></p>
				</div>

				<!-- Metadata fields -->
				<div class="mvs-modal-fields" data-wp-bind--hidden="state.uploadModal.uploading">
					<div class="mvs-modal-field">
						<input type="text" placeholder="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateUploadTitle"
							data-wp-bind--value="state.uploadModal.title" />
					</div>
					<div class="mvs-modal-field">
						<textarea rows="2" placeholder="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateUploadDescription"
							data-wp-text="state.uploadModal.description"></textarea>
					</div>
					<div class="mvs-modal-field-row">
						<input type="text" placeholder="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateUploadTags"
							data-wp-bind--value="state.uploadModal.tags" />
						<select data-wp-on--change="actions.updateUploadPrivacy">
							<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
							<option value="loggedin"><?php esc_html_e( 'Members Only', 'wpmediaverse' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
						</select>
					</div>
				</div>
			</div>

			<!-- Modal Footer -->
			<div class="mvs-modal-footer" data-wp-bind--hidden="state.uploadModal.uploading">
				<button class="mvs-btn mvs-btn--secondary" data-wp-on--click="actions.closeUploadModal">
					<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-btn mvs-btn--primary" data-wp-on--click="actions.submitUpload">
					<?php esc_html_e( 'Upload', 'wpmediaverse' ); ?>
				</button>
			</div>
		</div>
	</div>

	<!-- Lightbox Overlay -->
	<div class="mvs-lightbox-overlay" data-wp-bind--hidden="!state.lightbox.visible" data-wp-on--click="actions.closeLightbox">
		<div class="mvs-lightbox" data-wp-on--click="event.stopPropagation()">
			<!-- Close button -->
			<button class="mvs-lightbox-close" data-wp-on--click="actions.closeLightbox" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
				</svg>
			</button>

			<!-- Loading spinner -->
			<div class="mvs-lightbox-loading" data-wp-bind--hidden="!state.lightbox.loading">
				<div class="mvs-spinner"></div>
			</div>

			<!-- Image -->
			<div class="mvs-lightbox-media" data-wp-bind--hidden="state.lightbox.loading">
				<img data-wp-bind--src="state.lightboxImageUrl" data-wp-bind--alt="state.lightboxTitle" />
			</div>

			<!-- Sidebar with info -->
			<div class="mvs-lightbox-sidebar" data-wp-bind--hidden="state.lightbox.loading">
				<div class="mvs-lightbox-author">
					<img class="mvs-lightbox-author-avatar" data-wp-bind--src="state.lightboxAuthorAvatar" alt="" width="32" height="32" />
					<strong data-wp-text="state.lightboxAuthor"></strong>
				</div>
				<h3 class="mvs-lightbox-title" data-wp-text="state.lightboxTitle"></h3>
			</div>
		</div>
	</div>

	<!-- Toast (already in shared-ui, but rendered here as a global container) -->
	<div class="mvs-toast" data-wp-bind--hidden="!state.toast.visible"
		data-wp-class--mvs-toast--success="state.isToastSuccess"
		data-wp-class--mvs-toast--error="state.isToastError">
		<span data-wp-text="state.toast.message"></span>
		<button class="mvs-toast-close" data-wp-on--click="actions.hideToast">&times;</button>
	</div>
</div>
