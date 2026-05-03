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

$mvs_is_logged_in = is_user_logged_in();
$mvs_rest_url     = esc_url_raw( rest_url( 'mvs/v1/' ) );
$mvs_nonce        = $mvs_is_logged_in ? wp_create_nonce( 'wp_rest' ) : '';

// Show FAB only on MVS pages (explore, dashboard, media single/archive).
$mvs_page_ids = array_filter( array_map( 'absint', array(
	get_option( 'mvs_page_explore', 0 ),
	get_option( 'mvs_page_dashboard', 0 ),
) ) );
$mvs_show_fab = $mvs_is_logged_in && (
	( ! empty( $mvs_page_ids ) && is_page( $mvs_page_ids ) )
	|| ! empty( $GLOBALS['mvs_current_media'] )
	|| ! empty( $GLOBALS['mvs_is_media_archive'] )
	|| is_post_type_archive( 'mvs_album' )
	|| is_tax( 'mvs_tag' )
	|| is_tax( 'mvs_category' )
);
?>
<div class="mvs-app-shell"
	data-wp-interactive="mvs/shared-ui"
	<?php
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() handles its own escaping.
	echo wp_interactivity_data_wp_context(
		array(
			'restUrl'        => $mvs_rest_url,
			'nonce'          => $mvs_nonce,
			'currentUserId'  => $mvs_is_logged_in ? get_current_user_id() : 0,
			'defaultPrivacy' => get_option( 'mvs_default_privacy', 'public' ),
			'allowedTypes'   => get_option( 'mvs_allowed_file_types', 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg' ),
		)
	);
	?>
	data-wp-on-document--keydown="actions.handleLightboxKeydown"
>
	<!-- Floating Action Button (MVS pages only) -->
	<?php if ( $mvs_show_fab ) : ?>
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
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.uploadModalVisible" data-wp-on--click="actions.closeUploadModal">
		<div class="mvs-modal" data-wp-on--click="actions.handleModalClick">
			<!-- Modal Header -->
			<div class="mvs-modal-header">
				<h3 class="mvs-modal-title" data-wp-text="state.uploadModalHeading"></h3>
				<button class="mvs-modal-close" data-wp-on--click="actions.closeUploadModal" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M18 6 6 18"/><path d="m6 6 12 12"/>
					</svg>
				</button>
			</div>

			<!-- Mode Tabs -->
			<div class="mvs-modal-tabs">
				<button class="mvs-modal-tab" data-wp-class--active="state.isPhotoMode"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"photo"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
						<circle cx="8.5" cy="8.5" r="1.5"></circle>
						<polyline points="21 15 16 10 5 21"></polyline>
					</svg>
					<?php esc_html_e( 'Photo', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.isGalleryMode"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"gallery"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<rect x="2" y="2" width="16" height="16" rx="2"></rect>
						<rect x="6" y="6" width="16" height="16" rx="2"></rect>
					</svg>
					<?php esc_html_e( 'Gallery', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.isAlbumMode"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"album"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path>
					</svg>
					<?php esc_html_e( 'Album', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.isVideoMode"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"video"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<polygon points="23 7 16 12 23 17 23 7"></polygon>
						<rect x="1" y="5" width="15" height="14" rx="2" ry="2"></rect>
					</svg>
					<?php esc_html_e( 'Video', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-modal-tab" data-wp-class--active="state.isAudioMode"
					data-wp-on--click="actions.setUploadMode" data-wp-context='{"uploadMode":"audio"}'>
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<path d="M9 18V5l12-2v13"></path>
						<circle cx="6" cy="18" r="3"></circle>
						<circle cx="18" cy="16" r="3"></circle>
					</svg>
					<?php esc_html_e( 'Audio', 'wpmediaverse' ); ?>
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
					data-wp-bind--hidden="state.uploadModalUploading">
					<input type="file" id="mvs-modal-file-input" style="display:none"
						data-wp-bind--accept="state.uploadAccept"
						data-wp-bind--multiple="state.uploadMultiple"
						data-wp-on--change="actions.handleFileSelect" />

					<!-- Preview thumbnails -->
					<div class="mvs-modal-previews" data-wp-bind--hidden="!state.hasFiles" role="list">
						<template data-wp-each="state.uploadModalPreviews">
							<div class="mvs-modal-preview" role="listitem"
								data-wp-bind--data-mvs-file-uid="context.item.uid">
								<!-- Image / video thumbnail (when src is populated) -->
								<img class="mvs-modal-preview-thumb"
									data-wp-bind--src="context.item.src"
									data-wp-bind--hidden="!context.item.src"
									alt="" />
								<!-- Audio fallback icon (no src possible) -->
								<div class="mvs-modal-preview-icon"
									data-wp-bind--hidden="!context.item.isAudio">
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="32" height="32" aria-hidden="true">
										<path d="M9 18V5l12-2v13"/>
										<circle cx="6" cy="18" r="3"/>
										<circle cx="18" cy="16" r="3"/>
									</svg>
								</div>
								<!-- Generic file fallback (other / not-yet-loaded video) -->
								<div class="mvs-modal-preview-icon"
									data-wp-bind--hidden="!context.item.isOther">
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="32" height="32" aria-hidden="true">
										<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
										<polyline points="14 2 14 8 20 8"/>
									</svg>
								</div>
								<!-- File name -->
								<span class="mvs-modal-preview-name" data-wp-text="context.item.name"></span>
								<!-- Remove this single file -->
								<button type="button" class="mvs-modal-preview-remove"
									data-wp-on--click="actions.removeUploadFile"
									aria-label="<?php esc_attr_e( 'Remove this file from upload', 'wpmediaverse' ); ?>">
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" aria-hidden="true">
										<path d="M18 6 6 18"/><path d="m6 6 12 12"/>
									</svg>
								</button>
							</div>
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
				<div class="mvs-modal-progress" data-wp-bind--hidden="!state.uploadModalUploading">
					<div class="mvs-modal-progress-bar">
						<div class="mvs-modal-progress-fill"
							data-wp-style--width="state.uploadProgressWidth"></div>
					</div>
					<p class="mvs-modal-progress-text" data-wp-text="state.uploadProgressText"></p>
				</div>

				<!-- Per-file metadata (photo/gallery/video/audio modes only; album has its own fields above) -->
				<div class="mvs-modal-fields" data-wp-bind--hidden="state.hideUploadMetaFields">
					<div class="mvs-modal-field">
						<input type="text" placeholder="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateUploadTitle"
							data-wp-bind--value="state.uploadModalTitle" />
					</div>
					<div class="mvs-modal-field">
						<textarea rows="2" placeholder="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateUploadDescription"
							data-wp-bind--value="state.uploadModalDescription"></textarea>
					</div>
					<div class="mvs-modal-field-row">
						<input type="text" placeholder="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>"
							aria-label="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>"
							data-wp-on--input="actions.updateUploadTags"
							data-wp-bind--value="state.uploadModalTags" />
						<?php if ( get_option( 'mvs_allow_user_privacy', true ) ) : ?>
						<select data-wp-on--change="actions.updateUploadPrivacy"
							data-wp-bind--value="state.uploadModalPrivacy"
							aria-label="<?php esc_attr_e( 'Privacy', 'wpmediaverse' ); ?>">
							<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
							<option value="members"><?php esc_html_e( 'Members Only', 'wpmediaverse' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
						</select>
						<?php endif; ?>
					</div>
					<!-- Popular tag pills — lazy-loaded; click to add to the
						 tag input above. Hidden when none returned. -->
					<div class="mvs-tag-pills" data-wp-bind--hidden="!state.popularTagsLoaded" role="group" aria-label="<?php esc_attr_e( 'Popular tags', 'wpmediaverse' ); ?>">
						<span class="mvs-tag-pills__label"><?php esc_html_e( 'Popular tags:', 'wpmediaverse' ); ?></span>
						<template data-wp-each="state.popularTags">
							<button type="button" class="mvs-tag-pill"
								data-wp-on--click="actions.addUploadTag"
								data-wp-bind--data-mvs-tag-name="context.item.name">
								<span data-wp-text="context.item.name"></span>
							</button>
						</template>
					</div>
				</div>

				<!-- Album cover hint (album mode only, when files are selected) -->
				<div class="mvs-modal-album-cover-hint"
					data-wp-bind--hidden="state.hideAlbumCoverHint">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="16" height="16" aria-hidden="true">
						<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"></polygon>
					</svg>
					<span><?php esc_html_e( 'The first image you upload will be the album cover. You can change it later from the album settings.', 'wpmediaverse' ); ?></span>
				</div>
			</div>

			<!-- Modal Footer -->
			<div class="mvs-modal-footer" data-wp-bind--hidden="state.uploadModalUploading">
				<button class="mvs-btn mvs-btn--secondary" data-wp-on--click="actions.closeUploadModal">
					<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-btn mvs-btn--primary" data-wp-on--click="actions.submitUpload">
					<?php esc_html_e( 'Upload', 'wpmediaverse' ); ?>
				</button>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<!-- Edit Media modal — opened via window.mvsOpenEditModal( id )
	     when an owner clicks .mvs-media-edit-btn on their own card. -->
	<?php if ( $mvs_is_logged_in ) : ?>
	<div class="mvs-modal-overlay mvs-edit-modal-overlay" hidden data-wp-bind--hidden="!state.editModalVisible" data-wp-on--click="actions.closeEditModal" role="dialog" aria-modal="true" aria-labelledby="mvs-edit-modal-title">
		<div class="mvs-modal mvs-edit-modal" data-wp-on--click="actions.handleModalClick">
			<div class="mvs-modal-header">
				<h2 class="mvs-modal-title" id="mvs-edit-modal-title">
					<?php esc_html_e( 'Edit media settings', 'wpmediaverse' ); ?>
				</h2>
				<button class="mvs-modal-close" data-wp-on--click="actions.closeEditModal" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
						<path d="M18 6 6 18"/><path d="m6 6 12 12"/>
					</svg>
				</button>
			</div>

			<div class="mvs-modal-body">
				<!-- Loading state while fetching the current media data. -->
				<div class="mvs-modal-loading" data-wp-bind--hidden="!state.editModalLoading">
					<div class="mvs-spinner"></div>
					<p><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></p>
				</div>

				<!-- Edit form -->
				<div class="mvs-modal-fields" data-wp-bind--hidden="state.editModalLoading">
					<div class="mvs-modal-field">
						<label for="mvs-edit-title"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
						<input type="text" id="mvs-edit-title" class="mvs-modal-field-input"
							data-wp-on--input="actions.updateEditTitle"
							data-wp-bind--value="state.editModalTitle" />
					</div>
					<div class="mvs-modal-field">
						<label for="mvs-edit-description"><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
						<textarea id="mvs-edit-description" rows="3" class="mvs-modal-field-input"
							data-wp-on--input="actions.updateEditDescription"
							data-wp-bind--value="state.editModalDescription"></textarea>
					</div>
					<div class="mvs-modal-field">
						<label for="mvs-edit-privacy"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
						<select id="mvs-edit-privacy"
							data-wp-on--change="actions.updateEditPrivacy"
							data-wp-bind--value="state.editModalPrivacy">
							<option value="public"><?php esc_html_e( 'Public — anyone can view', 'wpmediaverse' ); ?></option>
							<option value="members"><?php esc_html_e( 'Members Only — logged-in users', 'wpmediaverse' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private — only you', 'wpmediaverse' ); ?></option>
						</select>
					</div>
					<?php if ( (bool) get_option( 'mvs_allow_downloads', true ) ) : ?>
					<div class="mvs-modal-field mvs-modal-field--checkbox">
						<label for="mvs-edit-allow-download">
							<input type="checkbox" id="mvs-edit-allow-download"
								data-wp-on--change="actions.toggleEditAllowDownload"
								data-wp-bind--checked="state.editModalAllowDownload" />
							<?php esc_html_e( 'Allow viewers to download this media', 'wpmediaverse' ); ?>
						</label>
						<p class="mvs-modal-field-hint">
							<?php esc_html_e( 'Uncheck to hide the Download button on this single item. The site-wide download setting still applies.', 'wpmediaverse' ); ?>
						</p>
					</div>
					<?php endif; ?>
					<div class="mvs-modal-error" data-wp-bind--hidden="!state.editModalError">
						<p data-wp-text="state.editModalError"></p>
					</div>
				</div>
			</div>

			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" data-wp-on--click="actions.closeEditModal" data-wp-bind--disabled="state.editModalSaving">
					<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
				</button>
				<button class="mvs-btn mvs-btn--primary" data-wp-on--click="actions.saveEditModal" data-wp-bind--disabled="state.editModalSaving">
					<span data-wp-bind--hidden="state.editModalSaving"><?php esc_html_e( 'Save changes', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.editModalSaving"><?php esc_html_e( 'Saving…', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<!-- Lightbox Overlay -->
	<div class="mvs-lightbox-overlay" hidden data-wp-bind--hidden="!state.lightboxVisible" data-wp-on--click="actions.closeLightbox">
		<div class="mvs-lightbox" data-wp-on--click="actions.handleModalClick">
			<!-- Close button -->
			<button class="mvs-lightbox-close" data-wp-on--click="actions.closeLightbox" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
					<path d="M18 6 6 18"/><path d="m6 6 12 12"/>
				</svg>
			</button>

			<!-- Loading spinner -->
			<div class="mvs-lightbox-loading" data-wp-bind--hidden="!state.lightboxLoading">
				<div class="mvs-spinner"></div>
			</div>

			<!-- Image with gallery navigation -->
			<div class="mvs-lightbox-media" data-wp-bind--hidden="state.lightboxLoading">
				<!-- Prev arrow (gallery groups only) -->
				<button class="mvs-lightbox-nav mvs-lightbox-nav--prev"
					data-wp-bind--hidden="!state.lightboxIsGroup"
					data-wp-on--click="actions.lightboxPrev"
					aria-label="<?php esc_attr_e( 'Previous', 'wpmediaverse' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
				</button>

				<img data-wp-bind--src="state.lightboxImageUrl" alt="" data-wp-bind--alt="state.lightboxTitle" data-wp-bind--hidden="state.lightboxHideImage" />
				<video class="mvs-lightbox-video" controls data-wp-bind--src="state.lightboxVideoUrl" data-wp-bind--hidden="state.lightboxHideVideo" hidden></video>
				<audio class="mvs-lightbox-audio" controls data-wp-bind--src="state.lightboxVideoUrl" data-wp-bind--hidden="state.lightboxHideAudio" hidden></audio>

				<!-- Next arrow (gallery groups only) -->
				<button class="mvs-lightbox-nav mvs-lightbox-nav--next"
					data-wp-bind--hidden="!state.lightboxIsGroup"
					data-wp-on--click="actions.lightboxNext"
					aria-label="<?php esc_attr_e( 'Next', 'wpmediaverse' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
				</button>

				<!-- Position indicator (e.g. "2 / 4") -->
				<div class="mvs-lightbox-position" data-wp-bind--hidden="!state.lightboxIsGroup">
					<span data-wp-text="state.lightboxPositionText"></span>
				</div>
			</div>

			<!-- Sidebar with full social features -->
			<div class="mvs-lightbox-sidebar" data-wp-bind--hidden="state.lightboxLoading">
				<!-- Author header -->
				<div class="mvs-lightbox-author">
					<a class="mvs-lightbox-author-link" data-wp-bind--href="state.lightboxAuthorUrl">
						<img class="mvs-lightbox-author-avatar" data-wp-bind--src="state.lightboxAuthorAvatar" alt="" width="36" height="36" />
						<strong data-wp-text="state.lightboxAuthor"></strong>
					</a>
				</div>

				<!-- Stats -->
				<div class="mvs-lightbox-stats">
					<span data-wp-text="state.lightboxViewsText"></span>
				</div>

				<!-- Reactions -->
				<div class="mvs-lightbox-reactions" role="group" aria-label="<?php esc_attr_e( 'Reactions', 'wpmediaverse' ); ?>">
					<?php if ( $mvs_is_logged_in ) : ?>
						<button class="mvs-lightbox-reaction" data-reaction="like" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsLike" data-wp-bind--aria-pressed="state.lightboxUserReactionIsLike" aria-label="<?php esc_attr_e( 'Like', 'wpmediaverse' ); ?>">
							<span aria-hidden="true">&#x1F44D;</span> <span data-wp-text="state.lightboxReactionCount_like"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="love" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsLove" data-wp-bind--aria-pressed="state.lightboxUserReactionIsLove" aria-label="<?php esc_attr_e( 'Love', 'wpmediaverse' ); ?>">
							<span aria-hidden="true">&#x2764;&#xFE0F;</span> <span data-wp-text="state.lightboxReactionCount_love"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="haha" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsHaha" data-wp-bind--aria-pressed="state.lightboxUserReactionIsHaha" aria-label="<?php esc_attr_e( 'Haha', 'wpmediaverse' ); ?>">
							<span aria-hidden="true">&#x1F602;</span> <span data-wp-text="state.lightboxReactionCount_haha"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="wow" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsWow" data-wp-bind--aria-pressed="state.lightboxUserReactionIsWow" aria-label="<?php esc_attr_e( 'Wow', 'wpmediaverse' ); ?>">
							<span aria-hidden="true">&#x1F62E;</span> <span data-wp-text="state.lightboxReactionCount_wow"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="sad" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsSad" data-wp-bind--aria-pressed="state.lightboxUserReactionIsSad" aria-label="<?php esc_attr_e( 'Sad', 'wpmediaverse' ); ?>">
							<span aria-hidden="true">&#x1F622;</span> <span data-wp-text="state.lightboxReactionCount_sad"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="angry" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsAngry" data-wp-bind--aria-pressed="state.lightboxUserReactionIsAngry" aria-label="<?php esc_attr_e( 'Angry', 'wpmediaverse' ); ?>">
							<span aria-hidden="true">&#x1F621;</span> <span data-wp-text="state.lightboxReactionCount_angry"></span>
						</button>
					<?php else : ?>
						<span class="mvs-lightbox-reaction mvs-lightbox-reaction--readonly"><span>&#x1F44D;</span> <span data-wp-text="state.lightboxReactionCount_like"></span></span>
						<span class="mvs-lightbox-reaction mvs-lightbox-reaction--readonly"><span>&#x2764;&#xFE0F;</span> <span data-wp-text="state.lightboxReactionCount_love"></span></span>
						<span class="mvs-lightbox-reaction mvs-lightbox-reaction--readonly"><span>&#x1F602;</span> <span data-wp-text="state.lightboxReactionCount_haha"></span></span>
						<span class="mvs-lightbox-reaction mvs-lightbox-reaction--readonly"><span>&#x1F62E;</span> <span data-wp-text="state.lightboxReactionCount_wow"></span></span>
						<span class="mvs-lightbox-reaction mvs-lightbox-reaction--readonly"><span>&#x1F622;</span> <span data-wp-text="state.lightboxReactionCount_sad"></span></span>
						<span class="mvs-lightbox-reaction mvs-lightbox-reaction--readonly"><span>&#x1F621;</span> <span data-wp-text="state.lightboxReactionCount_angry"></span></span>
					<?php endif; ?>
				</div>

				<!-- Actions bar -->
				<div class="mvs-lightbox-actions">
					<?php if ( $mvs_is_logged_in ) : ?>
						<button class="mvs-lightbox-action" data-wp-on--click="actions.lightboxToggleFavorite" data-wp-class--active="state.lightboxIsFavorited" aria-label="<?php esc_attr_e( 'Favorite this media', 'wpmediaverse' ); ?>" data-wp-bind--aria-pressed="state.lightboxIsFavorited">
							<i data-lucide="heart" aria-hidden="true"></i>
							<span data-wp-text="state.lightboxFavoriteLabel"></span>
						</button>
					<?php endif; ?>
					<button class="mvs-lightbox-action" data-wp-on--click="actions.lightboxShare" aria-label="<?php esc_attr_e( 'Share this media', 'wpmediaverse' ); ?>">
						<i data-lucide="share-2" aria-hidden="true"></i>
						<?php esc_html_e( 'Share', 'wpmediaverse' ); ?>
					</button>
					<?php if ( (bool) get_option( 'mvs_allow_downloads', true ) ) : ?>
					<button class="mvs-lightbox-action" data-wp-on--click="actions.lightboxDownload" data-wp-bind--hidden="state.lightboxHideDownload" aria-label="<?php esc_attr_e( 'Download this media to your device', 'wpmediaverse' ); ?>">
						<i data-lucide="download" aria-hidden="true"></i>
						<?php esc_html_e( 'Download', 'wpmediaverse' ); ?>
					</button>
					<?php endif; ?>
					<button class="mvs-lightbox-action" data-wp-on--click="actions.lightboxToggleFullscreen" aria-label="<?php esc_attr_e( 'Toggle fullscreen view (press F)', 'wpmediaverse' ); ?>">
						<i data-lucide="maximize-2" aria-hidden="true"></i>
						<?php esc_html_e( 'Fullscreen', 'wpmediaverse' ); ?>
					</button>
					<a class="mvs-lightbox-action" data-wp-bind--href="state.lightboxPermalink" target="_blank" aria-label="<?php esc_attr_e( 'Open this media in a new tab', 'wpmediaverse' ); ?>">
						<i data-lucide="external-link" aria-hidden="true"></i>
						<?php esc_html_e( 'Open', 'wpmediaverse' ); ?>
					</a>
					<?php if ( $mvs_is_logged_in ) : ?>
						<button class="mvs-lightbox-action mvs-lightbox-action--report" data-wp-on--click="actions.lightboxReport"
							data-wp-bind--hidden="state.lightboxIsOwner" aria-label="<?php esc_attr_e( 'Report this media for review', 'wpmediaverse' ); ?>">
							<i data-lucide="flag" aria-hidden="true"></i>
							<?php esc_html_e( 'Report', 'wpmediaverse' ); ?>
						</button>
					<?php endif; ?>
				</div>

				<!-- Description -->
				<div class="mvs-lightbox-desc" data-wp-bind--hidden="!state.lightboxDescription">
					<strong data-wp-text="state.lightboxAuthor"></strong>
					<span data-wp-text="state.lightboxDescription"></span>
				</div>

				<!-- Comments -->
				<div class="mvs-lightbox-comments">
					<h3 class="mvs-lightbox-comments-heading" data-wp-bind--hidden="!state.lightboxHasComments">
						<?php esc_html_e( 'Comments', 'wpmediaverse' ); ?>
					</h3>
					<ul class="mvs-lightbox-comment-list" role="list">
						<template data-wp-each="state.lightboxComments">
							<li class="mvs-lightbox-comment">
								<a class="mvs-lightbox-comment-avatar-link" data-wp-bind--href="context.item.author_url">
									<img class="mvs-lightbox-comment-avatar" data-wp-bind--src="context.item.author_avatar" alt="" width="40" height="40" loading="lazy" />
								</a>
								<div class="mvs-lightbox-comment-body">
									<div class="mvs-lightbox-comment-header">
										<a class="mvs-lightbox-comment-author-link" data-wp-bind--href="context.item.author_url">
											<strong class="mvs-lightbox-comment-author" data-wp-text="context.item.author_name"></strong>
										</a>
										<time class="mvs-lightbox-comment-time" data-wp-bind--datetime="context.item.date" data-wp-text="context.item.date_human"></time>
									</div>
									<p class="mvs-lightbox-comment-content" data-wp-text="context.item.content"></p>
								</div>
							</li>
						</template>
					</ul>
					<p class="mvs-lightbox-no-comments" data-wp-bind--hidden="state.lightboxHasComments">
						<?php esc_html_e( 'No comments yet. Be the first to say something!', 'wpmediaverse' ); ?>
					</p>
					<a class="mvs-lightbox-view-all-comments" data-wp-bind--href="state.lightboxPermalink" data-wp-bind--hidden="!state.lightboxHasMoreComments">
						<?php esc_html_e( 'View all comments', 'wpmediaverse' ); ?> &rarr;
					</a>
					<?php if ( $mvs_is_logged_in ) : ?>
						<div class="mvs-lightbox-comment-form">
							<input type="text" class="mvs-lightbox-comment-input"
								placeholder="<?php esc_attr_e( 'Add a comment…', 'wpmediaverse' ); ?>"
								data-wp-on--input="actions.lightboxUpdateComment"
								data-wp-on--keydown="actions.lightboxCommentKeydown"
								data-wp-bind--value="state.lightboxCommentText" />
							<button class="mvs-lightbox-comment-post"
								data-wp-on--click="actions.lightboxPostComment"
								data-wp-bind--disabled="!state.lightboxCommentText">
								<?php esc_html_e( 'Post', 'wpmediaverse' ); ?>
							</button>
						</div>
					<?php else : ?>
						<p class="mvs-lightbox-login-prompt">
							<a href="<?php echo esc_url( wp_login_url( home_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ) ) ) ); ?>">
								<?php esc_html_e( 'Log in to comment', 'wpmediaverse' ); ?>
							</a>
						</p>
					<?php endif; ?>
				</div>
			</div>
		</div>
	</div>

	<!-- Toast -->
	<div class="mvs-toast" hidden data-wp-bind--hidden="!state.toastVisible"
		data-wp-class--mvs-toast--success="state.isToastSuccess"
		data-wp-class--mvs-toast--error="state.isToastError">
		<span data-wp-text="state.toastMessage"></span>
		<button class="mvs-toast-close" data-wp-on--click="actions.hideToast" aria-label="<?php esc_attr_e( 'Dismiss', 'wpmediaverse' ); ?>">
			<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
				<path d="M18 6 6 18"/><path d="m6 6 12 12"/>
			</svg>
		</button>
	</div>
</div>
