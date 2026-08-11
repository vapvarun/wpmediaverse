<?php
/**
 * Settings permissions matrix manager.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

use WPMediaVerse\Capabilities\MediaCapabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Handles the role x capability matrix on the Permissions settings panel.
 */
class PermissionsManager {

	/**
	 * Singular → plural CPT cap map.
	 *
	 * @deprecated 1.6.0 Use MediaCapabilities::PLURAL_CAP_MAP — capabilities
	 *             own cap relationships. Kept as an alias because the const
	 *             is public (Production Rule #1).
	 */
	const PLURAL_CAP_MAP = MediaCapabilities::PLURAL_CAP_MAP;

	/**
	 * Register hooks for direct-POST save handler.
	 */
	public function init(): void {
		add_action( 'admin_post_mvs_save_role_caps', array( $this, 'save_role_caps' ) );
	}

	/**
	 * Whether the current user may save the Permissions matrix.
	 *
	 * `manage_options` is a hard fallback: an administrator who revokes
	 * `manage_mvs_settings` from their own role while testing must not be
	 * locked out of the matrix (Basecamp #9937811664).
	 */
	private function current_user_can_save(): bool {
		return current_user_can( 'manage_mvs_settings' ) || current_user_can( 'manage_options' );
	}

	/**
	 * Every site role, slug => translated display name.
	 *
	 * The matrix must cover ALL roles — MediaCapabilities::add_caps()
	 * grants base member caps to every role on the site (custom,
	 * BuddyPress, bbPress, WooCommerce, etc.), so a matrix hardcoded to
	 * the 5 core roles made those grants irrevocable (Basecamp #9937811664).
	 *
	 * @return array<string, string>
	 */
	private function get_manageable_roles(): array {
		return array_map( 'translate_user_role', wp_roles()->get_names() );
	}

	/**
	 * The caps the matrix manages, cap => column label.
	 *
	 * Filterable so a Pro feature can add its own column without Free having to
	 * know the feature exists. The list is used for BOTH rendering and saving —
	 * `process_role_caps_save()` reads the same method — so a cap added here is
	 * automatically writable, and one that is NOT here can never be granted or
	 * revoked through the matrix by a crafted POST.
	 *
	 * @since 2.4.0 The `mvs_managed_caps` filter.
	 *
	 * @return array<string, string>
	 */
	private function get_managed_caps(): array {
		$caps = array(
			'upload_mvs_media'        => __( 'Upload', 'wpmediaverse' ),
			'read_mvs_media'          => __( 'View', 'wpmediaverse' ),
			'publish_mvs_media'       => __( 'Publish', 'wpmediaverse' ),
			'edit_mvs_media'          => __( 'Edit Own', 'wpmediaverse' ),
			'edit_others_mvs_media'   => __( 'Edit Others', 'wpmediaverse' ),
			'delete_mvs_media'        => __( 'Delete Own', 'wpmediaverse' ),
			'delete_others_mvs_media' => __( 'Delete Others', 'wpmediaverse' ),
			'moderate_mvs_media'      => __( 'Moderate', 'wpmediaverse' ),
			'manage_mvs_access'       => __( 'Manage Access', 'wpmediaverse' ),
			'manage_mvs_settings'     => __( 'Manage Settings', 'wpmediaverse' ),
		);

		/**
		 * The capabilities shown as columns in the role matrix.
		 *
		 * Adding a cap here makes it grantable per role on the Permissions tab.
		 * The matrix already enumerates every role registered on the site, so a
		 * new column appears for custom, BuddyPress and WooCommerce roles with
		 * no further work.
		 *
		 * @since 2.4.0
		 *
		 * @param array<string,string> $caps Capability => column label.
		 */
		return (array) apply_filters( 'mvs_managed_caps', $caps );
	}

	/**
	 * Render the Permissions tab — role x capability matrix with checkboxes.
	 */
	public function render_permissions_tab(): void {
		// Handle save.
		if (
			isset( $_POST['_wpnonce'] ) &&
			wp_verify_nonce( sanitize_key( $_POST['_wpnonce'] ), 'mvs_save_role_caps' ) &&
			$this->current_user_can_save()
		) {
			$this->process_role_caps_save();
		}

		$roles = $this->get_manageable_roles();
		$caps  = $this->get_managed_caps();

		$nonce_field_html = wp_nonce_field( 'mvs_save_role_caps', '_wpnonce', true, false );
		?>
		<div class="mvs-permissions-tab">
			<?php settings_errors( 'mvs_role_caps' ); ?>
			<form method="post" action="">
				<?php echo $nonce_field_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_nonce_field output is safe. ?>
				<input type="hidden" name="mvs_permissions_submit" value="1" />
				<input type="hidden" name="mvs_form_roles" value="<?php echo esc_attr( implode( ',', array_keys( $roles ) ) ); ?>" />

				<p class="description">
					<?php esc_html_e( 'Control which user roles can perform each media action. Uncheck to revoke a capability. Your choices persist across plugin updates.', 'wpmediaverse' ); ?>
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
	 * Applies grants/revokes to wp_user_roles AND persists the full matrix
	 * to the MediaCapabilities::OVERRIDES_OPTION option, which add_caps()
	 * re-applies after its default grants — so plugin updates (the
	 * version-gated add_caps() call in Plugin::init()) no longer reset the
	 * admin's choices (Basecamp #9937811664).
	 *
	 * @return int Number of roles updated.
	 */
	public function process_role_caps_save(): int {
		// Only process roles that were actually rendered in the submitted
		// form (unchecked checkboxes don't POST, so a role added between
		// render and save would otherwise be read as "revoke everything").
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in render_permissions_tab() / save_role_caps().
		$form_roles = isset( $_POST['mvs_form_roles'] )
			? array_filter( array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_POST['mvs_form_roles'] ) ) ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: array();

		$roles = array_keys( $this->get_manageable_roles() );
		if ( $form_roles ) {
			$roles = array_values( array_intersect( $roles, $form_roles ) );
		}
		$caps = array_keys( $this->get_managed_caps() );

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

		// Build the full matrix, then hand it to the single cap writer. The
		// grant/revoke, the plural-twin sync and the overrides bookkeeping all
		// live in MediaCapabilities::apply_role_caps() so that this screen and
		// Pro's "Who can use documents" field cannot drift apart.
		$matrix = array();
		foreach ( $roles as $role_slug ) {
			foreach ( $caps as $cap ) {
				$matrix[ $role_slug ][ $cap ] = ! empty( $submitted[ $role_slug ][ $cap ] );
			}
		}

		$updated_count = MediaCapabilities::apply_role_caps( $matrix );

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

		if ( ! $this->current_user_can_save() ) {
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
