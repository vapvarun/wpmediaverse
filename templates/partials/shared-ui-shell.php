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
		)
	);
	?>
	data-wp-on--keydown="actions.handleLightboxKeydown"
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
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20" aria-hidden="true">
						<line x1="18" y1="6" x2="6" y2="18"></line>
						<line x1="6" y1="6" x2="18" y2="18"></line>
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
					<div class="mvs-modal-previews" data-wp-bind--hidden="!state.hasFiles">
						<template data-wp-each="state.uploadModalPreviews">
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
				<div class="mvs-modal-progress" data-wp-bind--hidden="!state.uploadModalUploading">
					<div class="mvs-modal-progress-bar">
						<div class="mvs-modal-progress-fill"
							data-wp-style--width="state.uploadProgressWidth"></div>
					</div>
					<p class="mvs-modal-progress-text" data-wp-text="state.uploadProgressText"></p>
				</div>

				<!-- Metadata fields -->
				<div class="mvs-modal-fields" data-wp-bind--hidden="state.uploadModalUploading">
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
							data-wp-on--input="actions.updateUploadTags"
							data-wp-bind--value="state.uploadModalTags" />
						<select data-wp-on--change="actions.updateUploadPrivacy"
							data-wp-bind--value="state.uploadModalPrivacy">
							<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
							<option value="members"><?php esc_html_e( 'Members Only', 'wpmediaverse' ); ?></option>
							<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
						</select>
					</div>
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

	<!-- Lightbox Overlay -->
	<div class="mvs-lightbox-overlay" hidden data-wp-bind--hidden="!state.lightboxVisible" data-wp-on--click="actions.closeLightbox">
		<div class="mvs-lightbox" data-wp-on--click="actions.handleModalClick">
			<!-- Close button -->
			<button class="mvs-lightbox-close" data-wp-on--click="actions.closeLightbox" aria-label="<?php esc_attr_e( 'Close', 'wpmediaverse' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24" aria-hidden="true">
					<line x1="18" y1="6" x2="6" y2="18"></line>
					<line x1="6" y1="6" x2="18" y2="18"></line>
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
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><polyline points="15 18 9 12 15 6"></polyline></svg>
				</button>

				<img data-wp-bind--src="state.lightboxImageUrl" alt="" data-wp-bind--alt="state.lightboxTitle" data-wp-bind--hidden="state.lightboxHideImage" />
				<video class="mvs-lightbox-video" controls data-wp-bind--src="state.lightboxVideoUrl" data-wp-bind--hidden="state.lightboxHideVideo" hidden></video>
				<audio class="mvs-lightbox-audio" controls data-wp-bind--src="state.lightboxVideoUrl" data-wp-bind--hidden="state.lightboxHideAudio" hidden></audio>

				<!-- Next arrow (gallery groups only) -->
				<button class="mvs-lightbox-nav mvs-lightbox-nav--next"
					data-wp-bind--hidden="!state.lightboxIsGroup"
					data-wp-on--click="actions.lightboxNext"
					aria-label="<?php esc_attr_e( 'Next', 'wpmediaverse' ); ?>">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="24" height="24"><polyline points="9 18 15 12 9 6"></polyline></svg>
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
				<div class="mvs-lightbox-reactions">
					<?php if ( $mvs_is_logged_in ) : ?>
						<button class="mvs-lightbox-reaction" data-reaction="like" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsLike">
							<span>&#x1F44D;</span> <span data-wp-text="state.lightboxReactionCount_like"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="love" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsLove">
							<span>&#x2764;&#xFE0F;</span> <span data-wp-text="state.lightboxReactionCount_love"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="haha" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsHaha">
							<span>&#x1F602;</span> <span data-wp-text="state.lightboxReactionCount_haha"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="wow" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsWow">
							<span>&#x1F62E;</span> <span data-wp-text="state.lightboxReactionCount_wow"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="sad" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsSad">
							<span>&#x1F622;</span> <span data-wp-text="state.lightboxReactionCount_sad"></span>
						</button>
						<button class="mvs-lightbox-reaction" data-reaction="angry" data-wp-on--click="actions.lightboxToggleReaction" data-wp-class--active="state.lightboxUserReactionIsAngry">
							<span>&#x1F621;</span> <span data-wp-text="state.lightboxReactionCount_angry"></span>
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
						<button class="mvs-lightbox-action" data-wp-on--click="actions.lightboxToggleFavorite" data-wp-class--active="state.lightboxIsFavorited"
							data-wp-bind--hidden="state.lightboxIsOwner">
							<span data-wp-text="state.lightboxFavoriteLabel"></span>
						</button>
					<?php endif; ?>
					<button class="mvs-lightbox-action" data-wp-on--click="actions.lightboxShare">&#x1F517; <?php esc_html_e( 'Share', 'wpmediaverse' ); ?></button>
					<a class="mvs-lightbox-action" data-wp-bind--href="state.lightboxPermalink" target="_blank">&#x2197;&#xFE0F; <?php esc_html_e( 'Open', 'wpmediaverse' ); ?></a>
					<?php if ( $mvs_is_logged_in ) : ?>
						<button class="mvs-lightbox-action mvs-lightbox-action--report" data-wp-on--click="actions.lightboxReport"
							data-wp-bind--hidden="state.lightboxIsOwner">
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
					<div class="mvs-lightbox-comment-list">
						<template data-wp-each="state.lightboxComments">
							<div class="mvs-lightbox-comment">
								<a class="mvs-lightbox-comment-author-link" data-wp-bind--href="context.item.author_url">
									<img class="mvs-lightbox-comment-avatar" data-wp-bind--src="context.item.author_avatar" alt="" width="24" height="24" />
									<strong data-wp-text="context.item.author_name"></strong>
								</a>
								<span data-wp-text="context.item.content"></span>
							</div>
						</template>
						<p class="mvs-lightbox-no-comments" data-wp-bind--hidden="state.lightboxHasComments">
							<?php esc_html_e( 'No comments yet. Be the first!', 'wpmediaverse' ); ?>
						</p>
						<a class="mvs-lightbox-view-all-comments" data-wp-bind--href="state.lightboxPermalink" data-wp-bind--hidden="!state.lightboxHasMoreComments">
							<?php esc_html_e( 'View all comments', 'wpmediaverse' ); ?> &rarr;
						</a>
					</div>
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
		<button class="mvs-toast-close" data-wp-on--click="actions.hideToast">&times;</button>
	</div>
</div>
