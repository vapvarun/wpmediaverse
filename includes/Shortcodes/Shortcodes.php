<?php
/**
 * Shortcode registrations.
 *
 * Provides shortcode alternatives to Gutenberg blocks for classic editor users.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders all WPMediaVerse shortcodes.
 */
class Shortcodes {

	/**
	 * Initialize shortcode registration.
	 */
	public function init(): void {
		add_shortcode( 'mvs_gallery', array( $this, 'render_gallery' ) );
		add_shortcode( 'mvs_upload', array( $this, 'render_upload' ) );
		add_shortcode( 'mvs_album', array( $this, 'render_album' ) );
		add_shortcode( 'mvs_player', array( $this, 'render_player' ) );
		add_shortcode( 'mvs_stats', array( $this, 'render_stats' ) );
		add_shortcode( 'mvs_dashboard', array( $this, 'render_dashboard' ) );
		add_shortcode( 'mvs_collection', array( $this, 'render_collection' ) );
	}

	/**
	 * Render the [mvs_gallery] shortcode.
	 *
	 * Usage: [mvs_gallery columns="3" per_page="12" type="image" category="" tag="" orderby="date"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_gallery( $atts ): string {
		$atts = shortcode_atts(
			array(
				'columns'  => 3,
				'per_page' => 12,
				'type'     => '',
				'category' => '',
				'tag'      => '',
				'orderby'  => 'date',
			),
			$atts,
			'mvs_gallery'
		);

		$block_attrs = array(
			'columns'       => absint( $atts['columns'] ),
			'perPage'       => absint( $atts['per_page'] ),
			'mediaType'     => sanitize_text_field( $atts['type'] ),
			'category'      => sanitize_text_field( $atts['category'] ),
			'tag'           => sanitize_text_field( $atts['tag'] ),
			'orderBy'       => sanitize_text_field( $atts['orderby'] ),
			'showLightbox'  => true,
			'showReactions' => true,
			'gap'           => 8,
		);

		return $this->render_block_template( 'media-grid', $block_attrs );
	}

	/**
	 * Render the [mvs_upload] shortcode.
	 *
	 * Usage: [mvs_upload max_files="10" show_privacy="true"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_upload( $atts ): string {
		$atts = shortcode_atts(
			array(
				'max_files'    => 10,
				'show_privacy' => 'true',
			),
			$atts,
			'mvs_upload'
		);

		$block_attrs = array(
			'maxFiles'    => absint( $atts['max_files'] ),
			'showPrivacy' => filter_var( $atts['show_privacy'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'media-upload', $block_attrs );
	}

	/**
	 * Render the [mvs_album] shortcode.
	 *
	 * Usage: [mvs_album id="123" columns="3" show_title="true" show_description="true"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_album( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'               => 0,
				'columns'          => 3,
				'show_title'       => 'true',
				'show_description' => 'true',
			),
			$atts,
			'mvs_album'
		);

		$block_attrs = array(
			'albumId'         => absint( $atts['id'] ),
			'columns'         => absint( $atts['columns'] ),
			'showTitle'       => filter_var( $atts['show_title'], FILTER_VALIDATE_BOOLEAN ),
			'showDescription' => filter_var( $atts['show_description'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'album-viewer', $block_attrs );
	}

	/**
	 * Render the [mvs_player] shortcode.
	 *
	 * Usage: [mvs_player id="123" autoplay="false" loop="false" download="false"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_player( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'autoplay' => 'false',
				'loop'     => 'false',
				'download' => 'false',
			),
			$atts,
			'mvs_player'
		);

		$block_attrs = array(
			'mediaId'      => absint( $atts['id'] ),
			'autoplay'     => filter_var( $atts['autoplay'], FILTER_VALIDATE_BOOLEAN ),
			'loop'         => filter_var( $atts['loop'], FILTER_VALIDATE_BOOLEAN ),
			'showDownload' => filter_var( $atts['download'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'media-player', $block_attrs );
	}

	/**
	 * Render the [mvs_stats] shortcode.
	 *
	 * Usage: [mvs_stats views="true" downloads="true" reactions="true" top="true" top_count="5"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_stats( $atts ): string {
		$atts = shortcode_atts(
			array(
				'views'     => 'true',
				'downloads' => 'true',
				'reactions' => 'true',
				'top'       => 'true',
				'top_count' => 5,
			),
			$atts,
			'mvs_stats'
		);

		$block_attrs = array(
			'showViews'     => filter_var( $atts['views'], FILTER_VALIDATE_BOOLEAN ),
			'showDownloads' => filter_var( $atts['downloads'], FILTER_VALIDATE_BOOLEAN ),
			'showReactions' => filter_var( $atts['reactions'], FILTER_VALIDATE_BOOLEAN ),
			'showTopMedia'  => filter_var( $atts['top'], FILTER_VALIDATE_BOOLEAN ),
			'topCount'      => absint( $atts['top_count'] ),
		);

		return $this->render_block_template( 'media-stats', $block_attrs );
	}

	/**
	 * Render the [mvs_dashboard] shortcode.
	 *
	 * Usage: [mvs_dashboard]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_dashboard( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to access your media dashboard.', 'wpmediaverse' ) . '</p>';
		}

		wp_enqueue_style( 'mvs-frontend' );

		// Enqueue Interactivity API stores.
		$shared_asset_file = MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php';
		$shared_asset      = file_exists( $shared_asset_file ) ? require $shared_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
		wp_enqueue_script_module(
			'mvs-shared-ui',
			MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
			$shared_asset['dependencies'],
			$shared_asset['version']
		);

		$dash_asset_file = MVS_PLUGIN_DIR . 'build/blocks/dashboard-view/view.asset.php';
		$dash_asset      = file_exists( $dash_asset_file ) ? require $dash_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
		wp_enqueue_script_module(
			'mvs-dashboard-view',
			MVS_PLUGIN_URL . 'build/blocks/dashboard-view/view.js',
			$dash_asset['dependencies'],
			$dash_asset['version']
		);

		$mvs_dash_ctx = wp_interactivity_data_wp_context(
			array(
				'restUrl'  => esc_url_raw( rest_url( 'mvs/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'userId'   => get_current_user_id(),
				'mediaUrl' => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
			)
		);

		ob_start();
		?>
		<div class="mvs-dashboard"
			data-wp-interactive="mvs/dashboard"
			<?php echo $mvs_dash_ctx; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			data-wp-init="callbacks.init">

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
				<button class="mvs-dashboard-tab" data-tab="collections" role="tab" type="button"
					data-wp-class--active="state.isCollectionsTab"
					data-wp-on--click="actions.switchTab">
					<?php esc_html_e( 'Collections', 'wpmediaverse' ); ?>
				</button>
			</nav>

			<!-- My Media Panel -->
			<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isMediaTab">
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
					<div class="mvs-dashboard-upload-status" data-wp-bind--hidden="!state.upload.uploading"
						data-wp-text="state.upload.status"></div>
				</div>
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

			<!-- Albums / Favorites panels rendered server-side similar to dashboard.php template -->
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

			<!-- My Collections Panel -->
			<div class="mvs-dashboard-panel" role="tabpanel" data-wp-bind--hidden="!state.isCollectionsTab">
				<div class="mvs-dashboard-actions">
					<button class="mvs-btn" type="button"
						data-wp-on--click="actions.openCreateCollection">+ <?php esc_html_e( 'Create Collection', 'wpmediaverse' ); ?></button>
				</div>
				<div class="mvs-dashboard-grid">
					<template data-wp-each="state.collections.items">
						<div class="mvs-dashboard-card mvs-collection-card" data-wp-bind--data-collection-id="context.item.id">
							<div class="mvs-collection-card-header">
								<span class="mvs-collection-type-badge" data-wp-text="context.item.type"></span>
								<span class="mvs-collection-count" data-wp-text="(context.item.matchCount || 0) + ' items'"></span>
							</div>
							<div class="mvs-dashboard-card-body">
								<a class="mvs-dashboard-card-title" data-wp-bind--href="context.item.link"
									data-wp-text="context.item.title || '(Untitled)'"></a>
								<div class="mvs-dashboard-card-meta" data-wp-text="context.item.description"></div>
								<div class="mvs-collection-rules-preview" data-wp-bind--hidden="context.item.type !== 'smart'">
									<template data-wp-each--rule="context.item.rules">
										<span class="mvs-rule-pill" data-wp-text="context.rule.key + ': ' + context.rule.value"></span>
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

			<!-- Collection Modal -->
			<div class="mvs-modal-overlay" data-wp-bind--hidden="!state.collectionModal.visible"
				data-wp-on--click="actions.closeOverlay">
				<div class="mvs-modal mvs-modal--wide" data-wp-on--click="actions.stopPropagation">
					<div class="mvs-modal-header">
						<h2 data-wp-text="state.collectionModal.isEdit ? '<?php echo esc_js( __( 'Edit Collection', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Create Collection', 'wpmediaverse' ) ); ?>'"></h2>
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
									data-wp-class--active="state.collectionModal.collectionType === 'manual'"
									data-wp-on--click="actions.setCollectionTypeManual">
									<?php esc_html_e( 'Manual', 'wpmediaverse' ); ?>
								</button>
								<button type="button" class="mvs-toggle-btn"
									data-wp-class--active="state.collectionModal.collectionType === 'smart'"
									data-wp-on--click="actions.setCollectionTypeSmart">
									<?php esc_html_e( 'Smart', 'wpmediaverse' ); ?>
								</button>
							</div>
							<p class="mvs-field-hint" data-wp-bind--hidden="state.collectionModal.collectionType !== 'manual'">
								<?php esc_html_e( 'Add media to this collection manually via the Favorites button.', 'wpmediaverse' ); ?>
							</p>
							<p class="mvs-field-hint" data-wp-bind--hidden="state.collectionModal.collectionType !== 'smart'">
								<?php esc_html_e( 'Define rules and media matching all conditions will appear automatically.', 'wpmediaverse' ); ?>
							</p>
						</div>
						<div class="mvs-rules-builder" data-wp-bind--hidden="state.collectionModal.collectionType !== 'smart'">
							<label><?php esc_html_e( 'Rules (all must match)', 'wpmediaverse' ); ?></label>
							<div class="mvs-rules-list">
								<template data-wp-each--rule="state.collectionModal.rules">
									<div class="mvs-rule-row" data-wp-bind--data-rule-index="context.rule.index">
										<select class="mvs-rule-key" data-wp-on--change="actions.setRuleKey">
											<option value=""><?php esc_html_e( '-- Select --', 'wpmediaverse' ); ?></option>
											<option value="media_type" data-wp-bind--selected="context.rule.key === 'media_type'"><?php esc_html_e( 'Media Type', 'wpmediaverse' ); ?></option>
											<option value="tag" data-wp-bind--selected="context.rule.key === 'tag'"><?php esc_html_e( 'Tag', 'wpmediaverse' ); ?></option>
											<option value="category" data-wp-bind--selected="context.rule.key === 'category'"><?php esc_html_e( 'Category', 'wpmediaverse' ); ?></option>
											<option value="author" data-wp-bind--selected="context.rule.key === 'author'"><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></option>
											<option value="privacy" data-wp-bind--selected="context.rule.key === 'privacy'"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></option>
											<option value="date_after" data-wp-bind--selected="context.rule.key === 'date_after'"><?php esc_html_e( 'Date After', 'wpmediaverse' ); ?></option>
											<option value="date_before" data-wp-bind--selected="context.rule.key === 'date_before'"><?php esc_html_e( 'Date Before', 'wpmediaverse' ); ?></option>
										</select>
										<select class="mvs-rule-value" data-wp-on--change="actions.setRuleValue"
											data-wp-bind--hidden="!state.isRuleSelectType">
											<template data-wp-each--opt="state.ruleValueOptions">
												<option data-wp-bind--value="context.opt.value"
													data-wp-bind--selected="context.opt.value == context.rule.value"
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
								<span data-wp-text="state.collectionModal.previewCount + ' <?php echo esc_js( __( 'media items match', 'wpmediaverse' ) ); ?>'"></span>
							</div>
						</div>
					</div>
					<div class="mvs-modal-footer">
						<button class="mvs-btn mvs-btn--secondary" type="button"
							data-wp-on--click="actions.closeCollectionModal"><?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?></button>
						<button class="mvs-btn" type="button"
							data-wp-on--click="actions.saveCollection"
							data-wp-bind--disabled="state.collectionModal.saving"
							data-wp-text="state.collectionModal.saving ? '<?php echo esc_js( __( 'Saving...', 'wpmediaverse' ) ); ?>' : ( state.collectionModal.isEdit ? '<?php echo esc_js( __( 'Save', 'wpmediaverse' ) ); ?>' : '<?php echo esc_js( __( 'Create', 'wpmediaverse' ) ); ?>' )"></button>
					</div>
				</div>
			</div>

			<!-- Toast + Confirm (shared-ui) -->
			<div class="mvs-toast"
				data-wp-interactive="mvs/shared-ui"
				data-wp-bind--hidden="!state.toast.visible"
				data-wp-text="state.toast.message"
				data-wp-class--mvs-toast--success="state.toast.type === 'success'"
				data-wp-class--mvs-toast--error="state.toast.type === 'error'"></div>
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
		return ob_get_clean();
	}

	/**
	 * Render a block's server-side template with given attributes.
	 *
	 * @param string $block_name Block name (without namespace).
	 * @param array  $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_block_template( string $block_name, array $attributes ): string {
		$allowed = array( 'media-grid', 'media-upload', 'album-viewer', 'media-player', 'media-stats' );
		if ( ! in_array( $block_name, $allowed, true ) ) {
			return '';
		}

		$template = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/render.php';

		if ( ! file_exists( $template ) ) {
			$template = MVS_PLUGIN_DIR . 'src/blocks/' . $block_name . '/render.php';
		}

		// Ensure frontend + block styles are loaded.
		wp_enqueue_style( 'mvs-frontend' );
		$block_style = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/style-index.css';
		if ( file_exists( $block_style ) ) {
			wp_enqueue_style(
				'mvs-block-' . $block_name,
				MVS_PLUGIN_URL . 'build/blocks/' . $block_name . '/style-index.css',
				array(),
				filemtime( $block_style )
			);
		}
		// Enqueue the block's view script (Interactivity API store).
		$block_view = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/view.js';
		if ( file_exists( $block_view ) ) {
			$asset_file = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/view.asset.php';
			$asset      = file_exists( $asset_file ) ? require $asset_file : array( 'dependencies' => array(), 'version' => filemtime( $block_view ) );
			wp_enqueue_script_module(
				'mvs-block-' . $block_name . '-view',
				MVS_PLUGIN_URL . 'build/blocks/' . $block_name . '/view.js',
				$asset['dependencies'],
				$asset['version']
			);
		}

		if ( ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		// Make $attributes available to the template.
		$content = '';
		$block   = null;
		// Flag so render.php can skip get_block_wrapper_attributes() (causes warning outside block context).
		$mvs_shortcode_context = true;
		include $template;
		return ob_get_clean();
	}

	/**
	 * Render the [mvs_collection] shortcode.
	 *
	 * Usage: [mvs_collection id="123" columns="3" per_page="20"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_collection( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'columns'  => 3,
				'per_page' => 20,
			),
			$atts,
			'mvs_collection'
		);

		$collection_id = (int) $atts['id'];
		if ( ! $collection_id ) {
			return '<p>' . esc_html__( 'Please provide a collection ID: [mvs_collection id="123"]', 'wpmediaverse' ) . '</p>';
		}

		$post = get_post( $collection_id );
		if ( ! $post || 'mvs_collection' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '<p>' . esc_html__( 'Collection not found.', 'wpmediaverse' ) . '</p>';
		}

		$container  = \WPMediaVerse\Core\Plugin::container();
		$service    = $container->get( 'collections' );
		$type       = $service->get_type( $collection_id );
		$media_ids  = array();

		if ( 'smart' === $type ) {
			$resolved  = $service->resolve( $collection_id, (int) $atts['per_page'], 1 );
			$media_ids = array_column( $resolved['items'], 'media_id' );
		} else {
			global $wpdb;
			$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$collection_id,
					(int) $atts['per_page']
				)
			);
		}

		if ( empty( $media_ids ) ) {
			return '<p class="mvs-no-media">' . esc_html__( 'No media in this collection.', 'wpmediaverse' ) . '</p>';
		}

		wp_enqueue_style( 'mvs-frontend' );

		$columns = (int) $atts['columns'];
		$output  = '<div class="mvs-media-grid mvs-collection-embed" style="--mvs-grid-cols: ' . $columns . '">';

		foreach ( $media_ids as $media_id ) {
			$media_post = get_post( $media_id );
			if ( ! $media_post || 'publish' !== $media_post->post_status ) {
				continue;
			}
			$file_url   = get_post_meta( $media_id, '_mvs_file_url', true );
			$media_type = get_post_meta( $media_id, '_mvs_media_type', true ) ?: 'image';
			$permalink  = get_permalink( $media_id );

			$output .= '<div class="mvs-grid-item">';
			$output .= '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link">';
			if ( $file_url && 'image' === $media_type ) {
				$output .= '<img src="' . esc_url( $file_url ) . '" alt="' . esc_attr( $media_post->post_title ) . '" loading="lazy" />';
			} else {
				$output .= '<div class="mvs-grid-placeholder mvs-grid-placeholder--' . esc_attr( $media_type ) . '">'
					. esc_html( strtoupper( $media_type ) ) . '</div>';
			}
			$output .= '</a>';
			$output .= '<div class="mvs-grid-item-title">' . esc_html( $media_post->post_title ?: __( '(Untitled)', 'wpmediaverse' ) ) . '</div>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}
}
