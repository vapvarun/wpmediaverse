<?php
/**
 * The profile-edit panel.
 *
 * WAS an inline form on a card above the rail, shown by flipping
 * `context.editingProfile`. It is a panel — it always behaved like one — so it
 * is now declared as the `profile` section and rendered here with the other
 * panels, bound to `state.isProfileTab` like every one of them.
 *
 * Moving it out of `dashboard-content.php` is what made that possible without
 * a rewrite: the markup below is the same markup, at a new address.
 *
 * Expects: $mvs_current_user, $mvs_avatar_url, $mvs_has_custom, $mvs_dash_active.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

defined( 'ABSPATH' ) || exit;
?>
<?php
// `hidden` server-side unless this IS the requested section — the binding only
// takes effect once the Interactivity runtime hydrates, so without it the whole
// form paints on first load and then vanishes. Same reason the documents panel
// carries one.
?>
<div class="mvs-dashboard-panel mvs-dashboard-profile-edit-form" role="tabpanel"
	data-wp-bind--hidden="!state.isProfileTab"
	<?php echo ( isset( $mvs_dash_active ) && 'profile' === $mvs_dash_active ) ? '' : 'hidden'; ?>>
			<div data-wp-interactive="mvs/profile-edit"
			<?php
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() handles its own escaping.
			echo wp_interactivity_data_wp_context(
				array(
					'restUrl'         => esc_url_raw( rest_url( 'mvs/v1/' ) ),
					'nonce'           => wp_create_nonce( 'wp_rest' ),
					'firstName'       => $mvs_current_user->first_name,
					'lastName'        => $mvs_current_user->last_name,
					'displayName'     => $mvs_current_user->display_name,
					'bio'             => $mvs_current_user->description,
					'dmAccess'        => get_user_meta( $mvs_current_user->ID, '_mvs_dm_access', true ) ?: get_option( 'mvs_dm_access', 'everyone' ),
					'onlineStatus'    => get_user_meta( $mvs_current_user->ID, '_mvs_show_online', true ) ?: get_option( 'mvs_show_online_status', 'everyone' ),
					'avatarUrl'       => $mvs_avatar_url ?: '',
					'hasCustomAvatar' => $mvs_has_custom,
					'uploadingAvatar' => false,
					'savingProfile'   => false,
					'saving'          => false,
					'profileMessage'  => '',
					'profileError'    => '',
					'savedMessage'    => '',
					'errorMessage'    => '',
				)
			);
			?>
			>

			<div class="mvs-profile-message mvs-profile-message--success"
				data-wp-bind--hidden="!context.profileMessage"
				data-wp-text="context.profileMessage"></div>
			<div class="mvs-profile-message mvs-profile-message--error"
				data-wp-bind--hidden="!context.profileError"
				data-wp-text="context.profileError"></div>

			<div class="mvs-profile-avatar-section">
				<div class="mvs-profile-avatar-preview">
					<img data-wp-bind--src="context.avatarUrl"
						alt="" width="96" height="96" class="mvs-profile-avatar-img" />
				</div>
				<div class="mvs-profile-avatar-actions">
					<label class="mvs-btn mvs-btn--secondary mvs-btn--small mvs-profile-avatar-upload-label">
						<span data-wp-bind--hidden="context.uploadingAvatar"><?php esc_html_e( 'Change Avatar', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!context.uploadingAvatar" hidden><?php esc_html_e( 'Uploading...', 'wpmediaverse' ); ?></span>
						<input type="file" accept="image/jpeg,image/png,image/gif,image/webp"
							class="mvs-profile-avatar-input"
							data-wp-on--change="actions.uploadAvatar" />
					</label>
					<button type="button"
						class="mvs-btn mvs-btn--text mvs-profile-avatar-remove"
						data-wp-bind--hidden="!context.hasCustomAvatar"
						data-wp-on--click="actions.deleteAvatar">
						<?php esc_html_e( 'Remove', 'wpmediaverse' ); ?>
					</button>
					<p class="mvs-profile-avatar-hint"><?php esc_html_e( 'Max 2 MB. JPEG, PNG, GIF, WebP.', 'wpmediaverse' ); ?></p>
				</div>
			</div>

			<div class="mvs-profile-form-inline">
				<div class="mvs-profile-field-row">
					<div class="mvs-profile-field">
						<label><?php esc_html_e( 'First Name', 'wpmediaverse' ); ?></label>
						<input type="text" data-wp-bind--value="context.firstName"
							data-wp-on--input="actions.updateFirstName" />
					</div>
					<div class="mvs-profile-field">
						<label><?php esc_html_e( 'Last Name', 'wpmediaverse' ); ?></label>
						<input type="text" data-wp-bind--value="context.lastName"
							data-wp-on--input="actions.updateLastName" />
					</div>
				</div>
				<div class="mvs-profile-field">
					<label><?php esc_html_e( 'Display Name', 'wpmediaverse' ); ?></label>
					<input type="text" data-wp-bind--value="context.displayName"
						data-wp-on--input="actions.updateDisplayName" />
				</div>
				<div class="mvs-profile-field">
					<label><?php esc_html_e( 'Bio', 'wpmediaverse' ); ?></label>
					<textarea rows="3" maxlength="500"
						data-wp-on--input="actions.updateBio"
						data-wp-bind--value="context.bio"></textarea>
				</div>
				<div class="mvs-profile-field-row">
					<div class="mvs-profile-field">
						<label for="mvs-dash-dm-access"><?php esc_html_e( 'Who can message you', 'wpmediaverse' ); ?></label>
						<select id="mvs-dash-dm-access"
							data-wp-bind--value="context.dmAccess"
							data-wp-on--change="actions.updateDmAccess">
							<option value="everyone"><?php esc_html_e( 'Everyone', 'wpmediaverse' ); ?></option>
							<option value="followers"><?php esc_html_e( 'People who follow you', 'wpmediaverse' ); ?></option>
							<option value="mutual"><?php esc_html_e( 'People you follow back', 'wpmediaverse' ); ?></option>
							<option value="nobody"><?php esc_html_e( 'No one', 'wpmediaverse' ); ?></option>
						</select>
					</div>
					<div class="mvs-profile-field">
						<label for="mvs-dash-online-status"><?php esc_html_e( 'Show your online status', 'wpmediaverse' ); ?></label>
						<select id="mvs-dash-online-status"
							data-wp-bind--value="context.onlineStatus"
							data-wp-on--change="actions.updateOnlineStatus">
							<option value="everyone"><?php esc_html_e( 'Yes', 'wpmediaverse' ); ?></option>
							<option value="nobody"><?php esc_html_e( 'No', 'wpmediaverse' ); ?></option>
						</select>
					</div>
				</div>
				<div class="mvs-profile-form-actions">
					<button type="button" class="mvs-btn mvs-btn--primary mvs-btn--small"
						data-wp-bind--disabled="context.savingProfile"
						data-wp-on--click="actions.saveProfile">
						<span data-wp-bind--hidden="context.savingProfile"><?php esc_html_e( 'Save', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!context.savingProfile" hidden><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
					</button>
					<button type="button" class="mvs-btn mvs-btn--secondary mvs-btn--small mvs-profile-cancel-btn"
						data-wp-on--click="actions.cancelProfileEdit">
						<?php esc_html_e( 'Cancel', 'wpmediaverse' ); ?>
					</button>
				</div>

					<?php
					// See and undo your blocks. This section lived only in
					// templates/profile-edit.php, reachable solely via the
					// [mvs_profile_edit] shortcode — and the plugin never creates a page
					// for it. So a member could block someone and never take it back.
					// Rendered here, inside the mvs/profile-edit region that owns
					// actions.unblockMember, it reaches every install.
					require MVS_PLUGIN_DIR . 'templates/partials/blocked-members.php';
					?>
			</div>
			</div>
</div>
