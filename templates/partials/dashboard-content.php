<?php
/**
 * Partial: Dashboard content.
 *
 * Shared by templates/dashboard.php and Shortcodes\Shortcodes::render_dashboard().
 * Expects $mvs_dash_ctx (array) to be set before inclusion.
 *
 * @package WPMediaVerse
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fires before the dashboard content is rendered.
 *
 * Pro uses this to display the quota usage widget.
 *
 * @since 1.1.0
 */
do_action( 'mvs_dashboard_before_content' );
?>
<div class="mvs-dashboard"
	data-wp-interactive="mvs/dashboard"
	<?php echo wp_interactivity_data_wp_context( $mvs_dash_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	data-wp-init="callbacks.init">

	<div class="mvs-dashboard-header">
		<span class="mvs-dashboard-heading"><?php esc_html_e( 'My Media', 'wpmediaverse' ); ?></span>
		<div class="mvs-notification-bell" data-wp-on--click="actions.toggleNotifications">
			<span class="mvs-notification-bell-icon">&#128276;</span>
			<span class="mvs-notification-badge" data-wp-bind--hidden="!state.notifications.count"
				data-wp-text="state.notifications.count"></span>
			<div class="mvs-notification-dropdown" data-wp-bind--hidden="!state.notifications.visible"
				data-wp-on--click="actions.stopPropagation">
				<div class="mvs-notification-dropdown-header">
					<strong><?php esc_html_e( 'Notifications', 'wpmediaverse' ); ?></strong>
					<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
						data-wp-on--click="actions.markAllRead"
						data-wp-bind--hidden="!state.notifications.count"><?php esc_html_e( 'Mark all read', 'wpmediaverse' ); ?></button>
				</div>
				<ul class="mvs-notification-list">
					<template data-wp-each="state.notifications.items">
						<li class="mvs-notification-item" data-wp-class--mvs-notification-unread="!context.item.read">
							<span data-wp-text="context.item.message"></span>
							<span class="mvs-notification-time" data-wp-text="context.item.date"></span>
						</li>
					</template>
				</ul>
				<p data-wp-bind--hidden="state.notifications.items.length > 0" class="mvs-notification-empty">
					<?php esc_html_e( 'No notifications yet.', 'wpmediaverse' ); ?>
				</p>
			</div>
		</div>
	</div>

	<nav class="mvs-dashboard-tabs" role="tablist">
		<button class="mvs-dashboard-tab" data-tab="media" role="tab" type="button"
			data-wp-class--active="state.isMediaTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'Media', 'wpmediaverse' ); ?>
		</button>
		<button class="mvs-dashboard-tab" data-tab="albums" role="tab" type="button"
			data-wp-class--active="state.isAlbumsTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'Albums', 'wpmediaverse' ); ?>
		</button>
		<button class="mvs-dashboard-tab" data-tab="favorites" role="tab" type="button"
			data-wp-class--active="state.isFavoritesTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'Favorites', 'wpmediaverse' ); ?>
		</button>
		<button class="mvs-dashboard-tab" data-tab="collections" role="tab" type="button"
			data-wp-class--active="state.isCollectionsTab"
			data-wp-on--click="actions.switchTab">
			<?php esc_html_e( 'Collections', 'wpmediaverse' ); ?>
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
				data-wp-on--click="actions.toggleUploadFields">
				<span data-wp-bind--hidden="state.upload.showFields"><?php esc_html_e( 'Add title, tags & privacy', 'wpmediaverse' ); ?></span>
				<span data-wp-bind--hidden="!state.upload.showFields"><?php esc_html_e( 'Hide fields', 'wpmediaverse' ); ?></span>
			</button>
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
						<img data-wp-bind--hidden="!state.mediaThumbUrl" data-wp-bind--src="state.mediaThumbUrl" data-wp-bind--alt="context.item.title" loading="lazy" />
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video"
							data-wp-bind--hidden="!state.showMediaVideoPlaceholder">
							<span class="mvs-grid-play-icon">&#9654;</span>
						</div>
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio"
							data-wp-bind--hidden="!state.showMediaAudioPlaceholder">
							<span class="mvs-grid-audio-icon">&#9835;</span>
						</div>
					</a>
					<div class="mvs-dashboard-card-body">
						<a class="mvs-dashboard-card-title" data-wp-bind--href="context.item.link"
							data-wp-text="state.itemTitle"></a>
						<div class="mvs-dashboard-card-meta">
							<span class="mvs-privacy-badge" data-wp-text="state.itemPrivacy"></span>
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
						<img data-wp-bind--hidden="!state.hasAlbumCover" data-wp-bind--src="context.item.cover_url" data-wp-bind--alt="context.item.title" loading="lazy" />
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--album"
							data-wp-bind--hidden="state.hasAlbumCover">
							<span class="mvs-grid-album-icon">&#128193;</span>
						</div>
					</a>
					<div class="mvs-dashboard-card-body">
						<div class="mvs-dashboard-card-title" data-wp-text="state.itemTitle"></div>
						<div class="mvs-dashboard-card-meta" data-wp-text="state.albumItemCount"></div>
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
						<img data-wp-bind--hidden="!state.favThumbUrl" data-wp-bind--src="state.favThumbUrl" data-wp-bind--alt="context.item.title" loading="lazy" />
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video"
							data-wp-bind--hidden="!state.showFavVideoPlaceholder">
							<span class="mvs-grid-play-icon">&#9654;</span>
						</div>
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio"
							data-wp-bind--hidden="!state.showFavAudioPlaceholder">
							<span class="mvs-grid-audio-icon">&#9835;</span>
						</div>
					</a>
					<div class="mvs-dashboard-card-body">
						<div class="mvs-dashboard-card-title" data-wp-text="state.itemTitle"></div>
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

	<!-- My Collections Panel -->
	<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isCollectionsTab">
		<div class="mvs-dashboard-actions">
			<button class="mvs-btn" type="button"
				data-wp-on--click="actions.openCreateCollection">+ <?php esc_html_e( 'Create Collection', 'wpmediaverse' ); ?></button>
		</div>
		<div class="mvs-dashboard-grid">
			<template data-wp-each="state.collections.items">
				<div class="mvs-dashboard-card mvs-collection-card" data-wp-bind--data-collection-id="context.item.id">
					<a class="mvs-dashboard-card-thumb" data-wp-bind--href="context.item.link">
						<img data-wp-bind--src="state.collectionCoverUrl"
							data-wp-bind--alt="state.itemTitle"
							data-wp-bind--hidden="!state.hasCollectionCover"
							loading="lazy" />
						<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--collection"
							data-wp-bind--hidden="state.hasCollectionCover">
							<span class="mvs-grid-collection-icon">&#128218;</span>
						</div>
						<span class="mvs-collection-type-badge" data-wp-text="context.item.type"></span>
					</a>
					<div class="mvs-dashboard-card-body">
						<a class="mvs-dashboard-card-title" data-wp-bind--href="context.item.link"
							data-wp-text="state.itemTitle"></a>
						<div class="mvs-dashboard-card-meta" data-wp-text="state.collectionItemCount"></div>
						<div class="mvs-dashboard-card-meta" data-wp-text="context.item.description"
							data-wp-bind--hidden="!context.item.description"></div>
						<div class="mvs-collection-rules-preview" data-wp-bind--hidden="!state.isSmartCollection">
							<template data-wp-each--rule="context.item.rules">
								<span class="mvs-rule-pill" data-wp-text="state.rulePillText"></span>
							</template>
						</div>
						<div class="mvs-dashboard-card-actions">
							<button class="mvs-btn mvs-btn--small mvs-btn--secondary" type="button"
								data-wp-on--click="actions.openEditCollection"><?php esc_html_e( 'Edit', 'wpmediaverse' ); ?></button>
							<button class="mvs-btn mvs-btn--small mvs-btn--danger" type="button"
								data-wp-on--click="actions.confirmDeleteCollection"><?php esc_html_e( 'Delete', 'wpmediaverse' ); ?></button>
						</div>
					</div>
				</div>
			</template>
		</div>
		<p data-wp-bind--hidden="!state.showCollectionsEmpty" class="mvs-no-media">
			<?php esc_html_e( 'No collections yet. Create a smart collection to auto-organize your media!', 'wpmediaverse' ); ?>
		</p>
	</div>

	<!-- Collection Modal (Create/Edit with Rule Builder) -->
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.collectionModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal mvs-modal--wide" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2>
					<span data-wp-bind--hidden="state.collectionModal.isEdit"><?php esc_html_e( 'Create Collection', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.collectionModal.isEdit"><?php esc_html_e( 'Edit Collection', 'wpmediaverse' ); ?></span>
				</h2>
				<button class="mvs-modal-close" type="button" data-wp-on--click="actions.closeCollectionModal">&times;</button>
			</div>
			<div class="mvs-modal-body">
				<div class="mvs-field">
					<label><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="state.collectionModal.title"
						data-wp-on--input="actions.setCollectionTitle" />
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
					<textarea data-wp-bind--value="state.collectionModal.description"
						data-wp-on--input="actions.setCollectionDesc" rows="2"></textarea>
				</div>
				<div class="mvs-field">
					<label><?php esc_html_e( 'Type', 'wpmediaverse' ); ?></label>
					<div class="mvs-collection-type-toggle">
						<button type="button" class="mvs-toggle-btn"
							data-wp-class--active="state.isManualType"
							data-wp-on--click="actions.setCollectionTypeManual">
							<?php esc_html_e( 'Manual', 'wpmediaverse' ); ?>
						</button>
						<button type="button" class="mvs-toggle-btn"
							data-wp-class--active="state.isSmartType"
							data-wp-on--click="actions.setCollectionTypeSmart">
							<?php esc_html_e( 'Smart', 'wpmediaverse' ); ?>
						</button>
					</div>
					<p class="mvs-field-hint" data-wp-bind--hidden="!state.isManualType">
						<?php esc_html_e( 'Add media to this collection manually via the Favorites button.', 'wpmediaverse' ); ?>
					</p>
					<p class="mvs-field-hint" data-wp-bind--hidden="!state.isSmartType">
						<?php esc_html_e( 'Define rules and media matching all conditions will appear automatically.', 'wpmediaverse' ); ?>
					</p>
				</div>

				<!-- Smart Rules Builder -->
				<div class="mvs-rules-builder" data-wp-bind--hidden="!state.isSmartType">
					<label><?php esc_html_e( 'Rules (all must match)', 'wpmediaverse' ); ?></label>
					<div class="mvs-rules-list">
						<template data-wp-each--rule="state.collectionModal.rules">
							<div class="mvs-rule-row" data-wp-bind--data-rule-index="context.rule.index">
								<select class="mvs-rule-key" data-wp-on--change="actions.setRuleKey">
									<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
									<option value="media_type" data-wp-bind--selected="state.isRuleKeyMediaType"><?php esc_html_e( 'Media Type', 'wpmediaverse' ); ?></option>
									<option value="tag" data-wp-bind--selected="state.isRuleKeyTag"><?php esc_html_e( 'Tag', 'wpmediaverse' ); ?></option>
									<option value="category" data-wp-bind--selected="state.isRuleKeyCategory"><?php esc_html_e( 'Category', 'wpmediaverse' ); ?></option>
									<option value="author" data-wp-bind--selected="state.isRuleKeyAuthor"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></option>
									<option value="privacy" data-wp-bind--selected="state.isRuleKeyPrivacy"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></option>
									<option value="date_after" data-wp-bind--selected="state.isRuleKeyDateAfter"><?php esc_html_e( 'Date After', 'wpmediaverse' ); ?></option>
									<option value="date_before" data-wp-bind--selected="state.isRuleKeyDateBefore"><?php esc_html_e( 'Date Before', 'wpmediaverse' ); ?></option>
								</select>

								<!-- Value input: changes based on rule key -->
								<select class="mvs-rule-value" data-wp-on--change="actions.setRuleValue"
									data-wp-bind--hidden="!state.isRuleSelectType">
									<template data-wp-each--opt="state.ruleValueOptions">
										<option data-wp-bind--value="context.opt.value"
											data-wp-bind--selected="state.isRuleValueSelected"
											data-wp-text="context.opt.label"></option>
									</template>
								</select>
								<input type="text" class="mvs-rule-value-text" data-wp-on--change="actions.setRuleValue"
									data-wp-bind--hidden="state.isRuleSelectType"
									data-wp-bind--value="context.rule.value"
									data-wp-bind--type="state.ruleInputType"
									data-wp-bind--placeholder="state.ruleInputPlaceholder" />

								<button type="button" class="mvs-rule-remove" data-wp-on--click="actions.removeRule">&times;</button>
							</div>
						</template>
					</div>
					<button type="button" class="mvs-btn mvs-btn--small mvs-btn--secondary"
						data-wp-on--click="actions.addRule">+ <?php esc_html_e( 'Add Rule', 'wpmediaverse' ); ?></button>

					<div class="mvs-rules-preview" data-wp-bind--hidden="!state.collectionModal.previewCount">
						<span data-wp-text="state.collectionModal.previewCount"></span> <?php esc_html_e( 'media items match', 'wpmediaverse' ); ?>
					</div>
				</div>
			</div>
			<div class="mvs-modal-footer">
				<button class="mvs-btn mvs-btn--secondary" type="button"
					data-wp-on--click="actions.closeCollectionModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
				<button class="mvs-btn" type="button"
					data-wp-on--click="actions.saveCollection"
					data-wp-bind--disabled="state.collectionModal.saving">
					<span data-wp-bind--hidden="state.collectionModal.saving">
						<span data-wp-bind--hidden="state.collectionModal.isEdit"><?php esc_html_e( 'Create', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!state.collectionModal.isEdit"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
					</span>
					<span data-wp-bind--hidden="!state.collectionModal.saving"><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Edit Media Modal -->
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.editModal.visible"
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
					data-wp-bind--disabled="state.editModal.saving">
					<span data-wp-bind--hidden="state.editModal.saving"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.editModal.saving"><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Album Modal (Create/Edit) -->
	<div class="mvs-modal-overlay" hidden data-wp-bind--hidden="!state.albumModal.visible"
		data-wp-on--click="actions.closeOverlay">
		<div class="mvs-modal" data-wp-on--click="actions.stopPropagation">
			<div class="mvs-modal-header">
				<h2>
					<span data-wp-bind--hidden="state.albumModal.isEdit"><?php esc_html_e( 'Create Album', 'wpmediaverse' ); ?></span>
					<span data-wp-bind--hidden="!state.albumModal.isEdit"><?php esc_html_e( 'Edit Album', 'wpmediaverse' ); ?></span>
				</h2>
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
				<div class="mvs-field">
					<label>
						<span data-wp-bind--hidden="state.albumModal.isEdit"><?php esc_html_e( 'Select Media', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!state.albumModal.isEdit"><?php esc_html_e( 'Album Media & Cover', 'wpmediaverse' ); ?></span>
					</label>
					<p class="mvs-field-hint">
						<?php esc_html_e( 'Click a thumbnail to toggle selection. Click "Set Cover" to choose the album cover.', 'wpmediaverse' ); ?>
					</p>
					<div class="mvs-media-picker">
						<p data-wp-bind--hidden="!state.albumModal.pickerLoading"><?php esc_html_e( 'Loading media...', 'wpmediaverse' ); ?></p>
						<template data-wp-each="state.albumModal.pickerItems">
							<div class="mvs-media-picker-item"
								data-wp-bind--data-picker-id="context.item.id"
								data-wp-class--mvs-media-picker-cover="state.isPickerCover"
								data-wp-on--click="actions.togglePickerItem">
								<img data-wp-bind--hidden="!state.pickerThumbUrl" data-wp-bind--src="state.pickerThumbUrl" data-wp-bind--alt="context.item.title" loading="lazy" />
								<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video"
									data-wp-bind--hidden="!state.showPickerVideoPlaceholder">
									<span class="mvs-grid-play-icon">&#9654;</span>
								</div>
								<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio"
									data-wp-bind--hidden="!state.showPickerAudioPlaceholder">
									<span class="mvs-grid-audio-icon">&#9835;</span>
								</div>
								<span class="mvs-media-picker-check">&#x2713;</span>
								<button class="mvs-media-picker-cover-btn" type="button"
									data-wp-on--click="actions.setCoverItem">
									<?php esc_html_e( 'Set Cover', 'wpmediaverse' ); ?>
								</button>
								<span class="mvs-media-picker-cover-badge" data-wp-bind--hidden="!state.isPickerCover">
									<?php esc_html_e( 'Cover', 'wpmediaverse' ); ?>
								</span>
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
					data-wp-bind--disabled="state.albumModal.saving">
					<span data-wp-bind--hidden="state.albumModal.saving">
						<span data-wp-bind--hidden="state.albumModal.isEdit"><?php esc_html_e( 'Create', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!state.albumModal.isEdit"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
					</span>
					<span data-wp-bind--hidden="!state.albumModal.saving"><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
				</button>
			</div>
		</div>
	</div>

	<!-- Toast (shared-ui) -->
	<div class="mvs-toast" hidden
		data-wp-interactive="mvs/shared-ui"
		data-wp-bind--hidden="!state.toast.visible"
		data-wp-text="state.toast.message"
		data-wp-class--mvs-toast--success="state.isToastSuccess"
		data-wp-class--mvs-toast--error="state.isToastError"</div>

	<!-- Confirm Dialog (shared-ui) -->
	<div class="mvs-confirm-overlay" hidden
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
