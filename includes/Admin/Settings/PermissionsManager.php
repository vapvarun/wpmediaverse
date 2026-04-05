<?php
/**
 * Settings permissions matrix manager.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the role x capability matrix on the Permissions settings panel.
 */
class PermissionsManager {

	/**
	 * Register hooks for direct-POST save handler.
	 */
	public function init(): void {
		add_action( 'admin_post_mvs_save_role_caps', array( $this, 'save_role_caps' ) );
	}

	/**
	 * Render the Permissions tab — role x capability matrix with checkboxes.
	 */
	public function render_permissions_tab(): void {
		// Handle save.
		if (
			isset( $_POST['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'mvs_save_role_caps' ) &&
			current_user_can( 'manage_mvs_settings' )
		) {
			$this->process_role_caps_save();
		}

		$roles = array(
			'administrator' => __( 'Administrator', 'wpmediaverse' ),
			'editor'        => __( 'Editor', 'wpmediaverse' ),
			'author'        => __( 'Author', 'wpmediaverse' ),
			'contributor'   => __( 'Contributor', 'wpmediaverse' ),
			'subscriber'    => __( 'Subscriber', 'wpmediaverse' ),
		);

		$caps = array(
			'upload_mvs_media'        => __( 'Upload', 'wpmediaverse' ),
			'edit_mvs_media'          => __( 'Edit Own', 'wpmediaverse' ),
			'edit_others_mvs_media'   => __( 'Edit Others', 'wpmediaverse' ),
			'delete_mvs_media'        => __( 'Delete Own', 'wpmediaverse' ),
			'delete_others_mvs_media' => __( 'Delete Others', 'wpmediaverse' ),
			'moderate_mvs_media'      => __( 'Moderate', 'wpmediaverse' ),
			'manage_mvs_settings'     => __( 'Manage Settings', 'wpmediaverse' ),
		);

		$nonce_field_html = wp_nonce_field( 'mvs_save_role_caps', '_wpnonce', true, false );
		?>
		<div class="mvs-permissions-tab">
			<?php settings_errors( 'mvs_role_caps' ); ?>
			<form method="post" action="">
				<?php echo $nonce_field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe. ?>
				<input type="hidden" name="mvs_permissions_submit" value="1" />

				<p class="description">
					<?php esc_html_e( 'Control which user roles can perform each media action. Uncheck to revoke a capability.', 'wpmediaverse' ); ?>
				</p>

				<table class="mvs-recent-table mvs-caps-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Role', 'wpmediaverse' ); ?></th>
							<?php foreach ( $caps as $cap_key => $cap_label ) : ?>
								<th class="mvs-caps-table__check"><?php echo esc_html( $cap_label ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $roles as $role_slug => $role_label ) : ?>
							<?php $role_obj = get_role( $role_slug ); ?>
							<tr>
								<td><strong><?php echo esc_html( $role_label ); ?></strong></td>
								<?php foreach ( $caps as $cap_key => $cap_label ) : ?>
									<td class="mvs-caps-table__check">
										<?php
										$has_cap = $role_obj && ! empty( $role_obj->capabilities[ $cap_key ] );
										printf(
											'<input type="checkbox" name="mvs_role_caps[%s][%s]" value="1" %s aria-label="%s" />',
											esc_attr( $role_slug ),
											esc_attr( $cap_key ),
											checked( $has_cap, true, false ),
											esc_attr(
												sprintf(
													/* translators: 1: capability label, 2: role label */
													__( '%1$s for %2$s', 'wpmediaverse' ),
													$cap_label,
													$role_label
												)
											)
										);
										?>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php submit_button( __( 'Save Permissions', 'wpmediaverse' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Process the role-capability matrix save from the Permissions tab form.
	 *
	 * @return int Number of roles updated.
	 */
	public function process_role_caps_save(): int {
		$roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		$caps  = array(
			'upload_mvs_media',
			'edit_mvs_media',
			'edit_others_mvs_media',
			'delete_mvs_media',
			'delete_others_mvs_media',
			'moderate_mvs_media',
			'manage_mvs_settings',
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in render_permissions_tab().
		$raw_caps  = isset( $_POST['mvs_role_caps'] ) && is_array( $_POST['mvs_role_caps'] )
			? wp_unslash( $_POST['mvs_role_caps'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array();
		$submitted = array();
		foreach ( $raw_caps as $role_key => $cap_arr ) {
			if ( ! is_array( $cap_arr ) ) {
				continue;
			}
			$clean_role = sanitize_key( $role_key );
			foreach ( $cap_arr as $cap_key => $cap_val ) {
				$submitted[ $clean_role ][ sanitize_key( $cap_key ) ] = sanitize_text_field( $cap_val );
			}
		}

		$updated_count = 0;
		foreach ( $roles as $role_slug ) {
			$role_obj = get_role( $role_slug );
			if ( ! $role_obj ) {
				continue;
			}
			$changed = false;
			foreach ( $caps as $cap ) {
				$granted = ! empty( $submitted[ $role_slug ][ $cap ] );
				$current = $role_obj->has_cap( $cap );
				if ( $granted !== $current ) {
					$changed = true;
				}
				if ( $granted ) {
					$role_obj->add_cap( $cap );
				} else {
					$role_obj->remove_cap( $cap );
				}
			}
			if ( $changed ) {
				++$updated_count;
			}
		}

		add_settings_error( 'mvs_role_caps', 'mvs_role_caps_saved', __( 'Permissions saved.', 'wpmediaverse' ), 'updated' );

		return $updated_count;
	}

	/**
	 * Handle the admin-post action for role cap saves (fallback / direct POST).
	 */
	public function save_role_caps(): void {
		if (
			! isset( $_POST['_wpnonce'] ) || // phpcs:ignore WordPress.Security.NonceVerification.Missing
			! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'mvs_save_role_caps' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		) {
			wp_die( esc_html__( 'Security check failed.', 'wpmediaverse' ) );
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wpmediaverse' ) );
		}

		$roles_updated = $this->process_role_caps_save();

		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'               => 'permissions',
					'permissions-saved' => $roles_updated,
				),
				admin_url( 'admin.php?page=' . SettingsPage::PAGE_SLUG )
			)
		);
		exit;
	}
}
