<?php
/**
 * Template: User Dashboard.
 *
 * Displays My Media, My Albums, and My Favorites tabs.
 * Uses Interactivity API — all CRUD via mvs/dashboard store.
 * Override by copying to your-theme/wpmediaverse/dashboard.php
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
	get_header();
	echo '<div class="mvs-dashboard"><p>';
	esc_html_e( 'Please log in to access your media dashboard.', 'wpmediaverse' );
	echo '</p></div>';
	get_footer();
	return;
}

get_header();

$mvs_dash_ctx = array(
	'restUrl'  => esc_url_raw( rest_url( 'mvs/v1/' ) ),
	'nonce'    => wp_create_nonce( 'wp_rest' ),
	'userId'   => get_current_user_id(),
	'mediaUrl' => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
);
?>
<div class="mvs-dashboard"
	data-wp-interactive="mvs/dashboard"
	<?php echo wp_interactivity_data_wp_context( $mvs_dash_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-init="callbacks.init">

	<header class="mvs-dashboard-header">
		<h1><?php esc_html_e( 'My Media', 'wpmediaverse' ); ?></h1>
	</header>

	<nav class="mvs-dashboard-tabs" role="tablist">
		<button class="mvs-dashboard-tab" data-tab="media" role="tab" type="button"
			data-wp-class--active="state.isMediaTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'My Media', 'wpmediaverse' ); ?>
		</button>
		<button class="mvs-dashboard-tab" data-tab="albums" role="tab" type="button"
			data-wp-class--active="state.isAlbumsTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'My Albums', 'wpmediaverse' ); ?>
		</button>
		<button class="mvs-dashboard-tab" data-tab="favorites" role="tab" type="button"
			data-wp-class--active="state.isFavoritesTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'My Favorites', 'wpmediaverse' ); ?>
		</button>
	</nav>

	<!-- My Media Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isMediaTab">
		<!-- Upload Section -->
		<div class="mvs-dashboard-upload">
			<div class="mvs-dashboard-dropzone"
				data-wp-class--mvs-drag-active="state.upload.dragOver"
				data-wp-on--click="actions.handleUploadClick"
				data-wp-on--dragover="actions.handleUploadDragOver"
				data-wp-on--dragleave="actions.handleUploadDragLeave"
				data-wp-on--drop="actions.handleUploadDrop">
				<span class="mvs-dashboard-dropzone-icon">&#x2B06;&#xFE0F;</span>
				<span class="mvs-dashboard-dropzone-label"><?php esc_html_e( 'Drop files here or click to upload', 'wpmediaverse' ); ?></span>
				<input type="file" multiple accept="image/*,video/*,audio/*" class="mvs-upload-file-input" style="display:none"
					data-wp-on--change="actions.handleUploadFileSelect" />
			</div>
			<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
				data-wp-on--click="actions.toggleUploadFields"
				data-wp-text="state.upload.showFields ? '<?php echo esc_js( __( 'Hide fields', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Add title, tags & privacy', 'wpmediaverse' ) ); ?>'"></button>
			<div class="mvs-dashboard-upload-fields" data-wp-bind--hidden="!state.upload.showFields">
				<input type="text" placeholder="<?php esc_attr_e( 'Title (optional)', 'wpmediaverse' ); ?>" class="mvs-upload-meta-title"
					data-wp-on--input="actions.setUploadTitle" />
				<textarea placeholder="<?php esc_attr_e( 'Description (optional)', 'wpmediaverse' ); ?>" class="mvs-upload-meta-desc" rows="2"
					data-wp-on--input="actions.setUploadDesc"></textarea>
				<div class="mvs-dashboard-upload-row">
					<input type="text" placeholder="<?php esc_attr_e( 'Tags (comma separated)', 'wpmediaverse' ); ?>" class="mvs-upload-meta-tags"
						data-wp-on--input="actions.setUploadTags" />
					<select class="mvs-upload-meta-privacy" data-wp-on--change="actions.setUploadPrivacy">
						<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
						<option value="members"><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
					</select>
				</div>
			</div>
			<div class="mvs-dashboard-upload-status" data-wp-bind--hidden="!state.upload.uploading"
				data-wp-text="state.upload.status"></div>
		</div>

		<!-- Media Grid -->
		<div class="mvs-dashboard-grid">
			<template data-wp-each="state.media.items">
				<div class="mvs-dashboard-card" data-wp-bind--data-media-id="context.item.id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link">
						<img data-wp-bind--src="context.item.file_url" data-wp-bind--alt="context.item.title" loading="lazy" />
					</a>
					<div class="mvs-dashboard-card-body">
						<a class="mvs-dashboard-card-title" data-wp-bind--href="context.item.link"
							data-wp-text="context.item.title || '(Untitled)'"></a>
						<div class="mvs-dashboard-card-meta">
							<span class="mvs-privacy-badge" data-wp-text="context.item.privacy || 'public'"></span>
						</div>
						<div class="mvs-dashboard-card-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.openEditModal"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDeleteMedia"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
			</template>
		</div>
		<p data-wp-bind--hidden="!state.showMediaEmpty" class="mvs-no-media">
			<?php esc_html_e( 'No media yet. Use the upload area above!', 'wpmediaverse' ); ?>
		</p>
		<div class="mvs-load-more-wrap" data-wp-bind--hidden="!state.hasMoreMedia">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.loadMoreMedia"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- My Albums Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isAlbumsTab">
		<div class="mvs-dashboard-actions">
			<button class="mvs-btn" type="button"
				data-wp-on--click="actions.openCreateAlbum">+ <?php esc_html_e( 'Create Album', 'wpmediaverse' ); ?></button>
		</div>
		<div class="mvs-dashboard-grid">
			<template data-wp-each="state.albums.items">
				<div class="mvs-dashboard-card" data-wp-bind--data-album-id="context.item.id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link">
						<img data-wp-bind--src="context.item.cover_url" data-wp-bind--alt="context.item.title" loading="lazy" />
					</a>
					<div class="mvs-dashboard-card-body">
						<div class="mvs-dashboard-card-title" data-wp-text="context.item.title || '(Untitled)'"></div>
						<div class="mvs-dashboard-card-meta" data-wp-text="(context.item.media_count || 0) + ' items'"></div>
						<div class="mvs-dashboard-card-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.openAlbumModal"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDeleteAlbum"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
			</template>
		</div>
		<p data-wp-bind--hidden="!state.showAlbumsEmpty" class="mvs-no-media">
			<?php esc_html_e( 'No albums yet. Create one!', 'wpmediaverse' ); ?>
		</p>
	</div>

	<!-- My Favorites Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isFavoritesTab">
		<div class="mvs-dashboard-grid">
			<template data-wp-each="state.favorites.items">
				<div class="mvs-dashboard-card" data-wp-bind--data-fav-id="context.item.media_id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link">
						<img data-wp-bind--src="context.item.file_url" data-wp-bind--alt="context.item.title" loading="lazy" />
					</a>
					<div class="mvs-dashboard-card-body">
						<div class="mvs-dashboard-card-title" data-wp-text="context.item.title || '(Untitled)'"></div>
						<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
							data-wp-on--click="actions.unfavorite"><?php esc_html_e( 'Unfavorite', 'wpmediaverse' ); ?></button>
					</div>
				</div>
			</template>
		</div>
		<p data-wp-bind--hidden="!state.showFavoritesEmpty" class="mvs-no-media">
			<?php esc_html_e( 'No favorites yet. Browse and favorite media from the Explore page!', 'wpmediaverse' ); ?>
		</p>
		<div class="mvs-load-more-wrap" data-wp-bind--hidden="!state.hasMoreFavorites">
			<button class="mvs-btn mvs-btn--secondary" type="button"
				data-wp-on--click="actions.loadMoreFavorites"><?php esc_html_e( 'Load More', 'wpmediaverse' ); ?></button>
		</div>
	</div>

	<!-- Edit Media Modal -->
	<div class="mvs-modal-overlay" data-wp-bind--hidden="!state.editModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2><?php esc_html_e( 'Edit Media', 'wpmediaverse' ); ?></h2>
				<button class="mvs-modal-close" type="button" data-wp-on--click="actions.closeEditModal">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-field">
					<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="state.editModal.title"
						data-wp-on--input="actions.setEditTitle" />
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
					<textarea data-wp-bind--value="state.editModal.description"
						data-wp-on--input="actions.setEditDesc"></textarea>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
					<select data-wp-on--change="actions.setEditPrivacy">
						<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
						<option value="members"><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
					</select>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Tags', 'wpmediaverse' ); ?></label>
					<div class="mvs-tag-input-wrap">
						<div class="mvs-tag-pills">
							<template data-wp-each="state.editModal.tags">
								<span class="mvs-tag-pill">
									<span data-wp-text="context.item"></span>
									<button type="button" class="mvs-tag-pill-remove"
										data-wp-bind--data-tag-name="context.item"
										data-wp-on--click="actions.removeEditTag">&times;</button>
								</span>
							</template>
							<input type="text" class="mvs-tag-text-input" placeholder="<?php esc_attr_e( 'Add tags...', 'wpmediaverse' ); ?>"
								data-wp-bind--value="state.editModal.tagInput"
								data-wp-on--input="actions.updateEditTagInput"
								data-wp-on--keydown="actions.addEditTag" />
						</div>
						<div class="mvs-tag-autocomplete" data-wp-bind--hidden="!state.editModal.tagDropdownVisible">
							<template data-wp-each="state.editModal.tagResults">
								<div class="mvs-tag-autocomplete-item"
									data-wp-bind--data-tag-name="context.item"
									data-wp-text="context.item"
									data-wp-on--click="actions.selectEditTag"></div>
							</template>
						</div>
					</div>
				</div>
			</div>
			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.closeEditModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn" type="button"
					data-wp-on--click="actions.saveEdit"
					data-wp-bind--disabled="state.editModal.saving"
					data-wp-text="state.editModal.saving ? '<?php echo esc_js( __( 'Saving...', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Save', 'wpmediaverse' ) ); ?>'"></button>
			</div>
		</div>
	</div>

	<!-- Album Modal (Create/Edit) -->
	<div class="mvs-modal-overlay" data-wp-bind--hidden="!state.albumModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2 data-wp-text="state.albumModal.isEdit ? '<?php echo esc_js( __( 'Edit Album', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Create Album', 'wpmediaverse' ) ); ?>'"></h2>
				<button class="mvs-modal-close" type="button" data-wp-on--click="actions.closeAlbumModal">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-field">
					<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="state.albumModal.title"
						data-wp-on--input="actions.setAlbumTitle" />
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
					<textarea data-wp-bind--value="state.albumModal.description"
						data-wp-on--input="actions.setAlbumDesc"></textarea>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
					<select data-wp-on--change="actions.setAlbumPrivacy">
						<option value="public"><?php esc_html_e( 'Public', 'wpmediaverse' ); ?></option>
						<option value="members"><?php esc_html_e( 'Members', 'wpmediaverse' ); ?></option>
						<option value="private"><?php esc_html_e( 'Private', 'wpmediaverse' ); ?></option>
					</select>
				</div>
				<div class="mvs-field" data-wp-bind--hidden="state.albumModal.isEdit">
					<label><?php esc_html_e( 'Select Media', 'wpmediaverse' ); ?></label>
					<div class="mvs-media-picker">
						<p data-wp-bind--hidden="!state.albumModal.pickerLoading"><?php esc_html_e( 'Loading media...', 'wpmediaverse' ); ?></p>
						<template data-wp-each="state.albumModal.pickerItems">
							<div class="mvs-media-picker-item"
								data-wp-bind--data-picker-id="context.item.id"
								data-wp-on--click="actions.togglePickerItem">
								<img data-wp-bind--src="context.item.file_url" data-wp-bind--alt="context.item.title" loading="lazy" />
								<span class="mvs-media-picker-check">&#x2713;</span>
							</div>
						</template>
					</div>
				</div>
			</div>
			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.closeAlbumModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn" type="button"
					data-wp-on--click="actions.saveAlbum"
					data-wp-bind--disabled="state.albumModal.saving"
					data-wp-text="state.albumModal.saving ? '<?php echo esc_js( __( 'Saving...', 'wpmediaverse' ) ); ?>' : ( state.albumModal.isEdit ? '<?php echo esc_js( __( 'Save', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Create', 'wpmediaverse' ) ); ?>' )"></button>
			</div>
		</div>
	</div>

	<!-- Toast (shared-ui) -->
	<div class="mvs-toast"
		data-wp-interactive="mvs/shared-ui"
		data-wp-bind--hidden="!state.toast.visible"
		data-wp-text="state.toast.message"
		data-wp-class--mvs-toast--success="state.toast.type === 'success'"
		data-wp-class--mvs-toast--error="state.toast.type === 'error'"></div>

	<!-- Confirm Dialog (shared-ui) -->
	<div class="mvs-confirm-overlay"
		data-wp-interactive="mvs/shared-ui"
		data-wp-bind--hidden="!state.confirm.visible">
		<div class="mvs-confirm">
			<p data-wp-text="state.confirm.message"></p>
			<div class="mvs-confirm-actions">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.handleConfirmCancel"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn mvs-btn--danger" type="button"
					data-wp-on--click="actions.handleConfirmYes"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
			</div>
		</div>
	</div>
</div>
<?php
wp_enqueue_style( 'mvs-frontend' );

// Enqueue Interactivity API stores.
$mvs_shared_asset_file = MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php';
$mvs_shared_asset      = file_exists( $mvs_shared_asset_file ) ? require $mvs_shared_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
wp_enqueue_script_module(
	'mvs-shared-ui',
	MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
	$mvs_shared_asset['dependencies'],
	$mvs_shared_asset['version']
);

$mvs_dash_asset_file = MVS_PLUGIN_DIR . 'build/blocks/dashboard-view/view.asset.php';
$mvs_dash_asset      = file_exists( $mvs_dash_asset_file ) ? require $mvs_dash_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
wp_enqueue_script_module(
	'mvs-dashboard-view',
	MVS_PLUGIN_URL . 'build/blocks/dashboard-view/view.js',
	$mvs_dash_asset['dependencies'],
	$mvs_dash_asset['version']
);

get_footer();
