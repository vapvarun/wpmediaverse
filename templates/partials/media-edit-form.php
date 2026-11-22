<?php
/**
 * The media edit form: every field, written once.
 *
 * Rendered by the edit modal (My Media, the lightbox, the BuddyPress media tab)
 * and by the inline panel on the single media page. Both read and write the
 * same `mvs/shared-ui` edit state, so the fields, the tag pills, Replace File,
 * the album-privacy lock and the save request cannot drift apart again. Only
 * the footer buttons and the container differ between the two looks.
 *
 * @package WPMediaVerse
 * @since   2.6.5
 * @version 2.6.5
 *
 * @var string $mvs_edit_prefix Prefix that keeps element ids unique when the form is on the page twice.
 */

defined( 'ABSPATH' ) || exit;

$mvs_edit_prefix = isset( $mvs_edit_prefix ) ? sanitize_html_class( (string) $mvs_edit_prefix ) : 'mvs-edit';
?>
<div class="mvs-edit-form" data-wp-interactive="mvs/shared-ui">
	<div class="mvs-modal-loading" data-wp-bind--hidden="!state.editModalLoading" hidden>
		<div class="mvs-spinner"></div>
		<p><?php esc_html_e( 'Loading…', 'wpmediaverse' ); ?></p>
	</div>

	<div class="mvs-modal-fields" data-wp-bind--hidden="state.editModalLoading">
		<div class="mvs-modal-field">
			<label for="<?php echo esc_attr( $mvs_edit_prefix ); ?>-title"><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-title" class="mvs-modal-field-input"
				aria-describedby="<?php echo esc_attr( $mvs_edit_prefix ); ?>-title-hint"
				data-wp-on--input="actions.updateEditTitle"
				data-wp-bind--value="state.editModalTitle"
				data-wp-bind--aria-invalid="state.editTitleMissing" />
			<p class="mvs-modal-field-hint" id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-title-hint" role="status" hidden
				data-wp-bind--hidden="!state.editTitleMissing"><?php esc_html_e( 'Title cannot be empty.', 'wpmediaverse' ); ?></p>
		</div>
		<div class="mvs-modal-field">
			<label for="<?php echo esc_attr( $mvs_edit_prefix ); ?>-description"><?php esc_html_e( 'Description', 'wpmediaverse' ); ?></label>
			<textarea id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-description" rows="3" class="mvs-modal-field-input"
				data-wp-on--input="actions.updateEditDescription"
				data-wp-bind--value="state.editModalDescription"></textarea>
		</div>
		<div class="mvs-modal-field">
			<label for="<?php echo esc_attr( $mvs_edit_prefix ); ?>-tags"><?php esc_html_e( 'Tags', 'wpmediaverse' ); ?></label>
			<div class="mvs-tag-input-wrap">
				<div class="mvs-tag-pills">
					<template data-wp-each="state.editModalTagList">
						<span class="mvs-tag-pill">
							<span data-wp-text="context.item"></span>
							<button type="button" class="mvs-tag-pill-remove"
								data-wp-bind--data-tag-name="context.item"
								data-wp-on--click="actions.removeEditTag"><span aria-hidden="true">&times;</span><span class="mvs-sr-only"><?php esc_html_e( 'Remove tag', 'wpmediaverse' ); ?></span> <span class="mvs-sr-only" data-wp-text="context.item"></span></button>
						</span>
					</template>
					<input type="text" id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-tags" class="mvs-tag-text-input"
						placeholder="<?php esc_attr_e( 'Add tags...', 'wpmediaverse' ); ?>"
						data-wp-bind--value="state.editModalTagInput"
						data-wp-on--input="actions.updateEditTagInput"
						data-wp-on--keydown="actions.addEditTag" />
				</div>
				<div class="mvs-tag-autocomplete" hidden data-wp-bind--hidden="!state.editModalTagDropdown">
					<template data-wp-each="state.editModalTagResults">
						<div class="mvs-tag-autocomplete-item"
							data-wp-bind--data-tag-name="context.item"
							data-wp-text="context.item"
							data-wp-on--click="actions.selectEditTag"></div>
					</template>
				</div>
			</div>
		</div>
		<?php // Privacy + slug-regenerate share a row to save vertical space; no functional pairing. ?>
		<div class="mvs-modal-row">
			<?php if ( \WPMediaVerse\Services\PrivacyService::user_may_choose_privacy() ) : // Owner lock. ?>
			<div class="mvs-modal-field mvs-modal-field--inline">
				<label for="<?php echo esc_attr( $mvs_edit_prefix ); ?>-privacy"><?php esc_html_e( 'Privacy', 'wpmediaverse' ); ?></label>
				<select id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-privacy"
					aria-describedby="<?php echo esc_attr( $mvs_edit_prefix ); ?>-privacy-album"
					data-wp-bind--disabled="state.editPrivacyFollowsAlbum"
					data-wp-on--change="actions.updateEditPrivacy"
					data-wp-bind--value="state.editModalPrivacy">
					<?php // A level the picker does not offer shows as a readable, disabled option so the owner can see what the item is set to. ?>
					<option data-wp-bind--hidden="!state.editModalPrivacyUnlisted" data-wp-bind--selected="state.editModalPrivacyUnlisted" data-wp-bind--value="state.editModalPrivacy" data-wp-text="state.editModalPrivacyLabel"></option>
					<?php \WPMediaVerse\Core\TemplateHelpers::privacy_options(); ?>
				</select>
				<p id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-privacy-album" class="mvs-modal-note" hidden data-wp-bind--hidden="!state.editPrivacyFollowsAlbum" data-wp-text="state.editPrivacyFollowsText"></p>
			</div>
			<?php endif; ?>
			<div class="mvs-modal-field mvs-modal-field--inline mvs-modal-field--checkbox">
				<label for="<?php echo esc_attr( $mvs_edit_prefix ); ?>-regenerate-slug" title="<?php esc_attr_e( 'Tick to regenerate the URL slug from the new title. Off by default to keep inbound links stable.', 'wpmediaverse' ); ?>">
					<input type="checkbox" id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-regenerate-slug"
						data-wp-on--change="actions.updateEditRegenerateSlug"
						data-wp-bind--checked="state.editModalRegenerateSlug" />
					<?php esc_html_e( 'Update URL slug', 'wpmediaverse' ); ?>
				</label>
			</div>
		</div>
		<?php if ( (bool) get_option( 'mvs_allow_downloads', true ) ) : ?>
		<div class="mvs-modal-field mvs-modal-field--checkbox">
			<label for="<?php echo esc_attr( $mvs_edit_prefix ); ?>-allow-download">
				<input type="checkbox" id="<?php echo esc_attr( $mvs_edit_prefix ); ?>-allow-download"
					data-wp-on--change="actions.toggleEditAllowDownload"
					data-wp-bind--checked="state.editModalAllowDownload" />
				<?php esc_html_e( 'Allow viewers to download this media', 'wpmediaverse' ); ?>
			</label>
			<p class="mvs-modal-field-hint">
				<?php esc_html_e( 'Uncheck to remove the Download button and the player\'s download option for this item. It cannot stop someone who can play or view it from saving or recording it. The site-wide download setting still applies.', 'wpmediaverse' ); ?>
			</p>
		</div>
		<?php endif; ?>
		<div class="mvs-replace-file-row">
			<label class="mvs-btn mvs-btn--secondary mvs-btn--small mvs-replace-file-label">
				<span aria-hidden="true">&#8635;</span> <?php esc_html_e( 'Replace File', 'wpmediaverse' ); ?>
				<?php // Visually hidden, not `hidden`: a hidden input can't take keyboard focus. ?>
				<input type="file" class="mvs-sr-only" data-wp-on--change="actions.handleEditReplaceFile" />
			</label>
			<span class="mvs-replace-progress" role="status" aria-live="polite" hidden data-wp-bind--hidden="!state.editModalReplaceProgress" data-wp-text="state.editModalReplaceProgress"></span>
			<span class="mvs-replace-file-hint"><?php esc_html_e( 'Upload a new file. Metadata is preserved.', 'wpmediaverse' ); ?></span>
		</div>
		<div class="mvs-modal-error" hidden data-wp-bind--hidden="!state.editModalError">
			<p role="alert" tabindex="-1">
				<span data-wp-text="state.editModalError"></span>
				<button type="button" class="mvs-modal-error__retry" hidden data-wp-bind--hidden="state.hideEditModalRetry" data-wp-on--click="actions.saveEditModal"><?php esc_html_e( 'Retry', 'wpmediaverse' ); ?></button>
			</p>
		</div>
	</div>
</div>
