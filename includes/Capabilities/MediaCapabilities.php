<?php
/**
 * Media capabilities.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Manages custom capabilities for media post types.
 */
class MediaCapabilities {

	/**
	 * Option holding the admin's Permissions-matrix overrides
	 * (role => cap => 0|1). Written by PermissionsManager on save,
	 * re-applied by add_caps() so version-bump re-grants never wipe
	 * an admin's manual grant/revoke choices (Basecamp #9937811664).
	 *
	 * @since 1.6.0
	 */
	const OVERRIDES_OPTION = 'mvs_role_caps_overrides';

	/**
	 * Singular caps with a matching plural CPT cap (edit_mvs_media →
	 * edit_mvs_medias). Both must stay in sync so MediaController
	 * permission checks work.
	 *
	 * @since 1.6.0 Moved here from PermissionsManager (capabilities own
	 *              cap relationships); publish added.
	 */
	const PLURAL_CAP_MAP = array(
		'edit_mvs_media'          => 'edit_mvs_medias',
		'edit_others_mvs_media'   => 'edit_others_mvs_medias',
		'delete_mvs_media'        => 'delete_mvs_medias',
		'delete_others_mvs_media' => 'delete_others_mvs_medias',
		'publish_mvs_media'       => 'publish_mvs_medias',
	);

	/**
	 * Capability mapping per role.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_role_caps(): array {
		return array(
			'administrator' => array(
				'upload_mvs_media',
				'edit_mvs_media',
				'edit_others_mvs_media',
				'delete_mvs_media',
				'delete_others_mvs_media',
				'moderate_mvs_media',
				'manage_mvs_settings',
				'manage_mvs_access',
				'read_mvs_media',
				'publish_mvs_media',
				// Legacy plural caps (kept for backward compatibility).
				'edit_mvs_medias',
				'edit_others_mvs_medias',
				'edit_published_mvs_medias',
				'edit_private_mvs_medias',
				'delete_mvs_medias',
				'delete_others_mvs_medias',
				'delete_published_mvs_medias',
				'delete_private_mvs_medias',
				'publish_mvs_medias',
				'read_private_mvs_medias',
			),
			'editor'        => array(
				'upload_mvs_media',
				'edit_mvs_media',
				'edit_others_mvs_media',
				'delete_mvs_media',
				'delete_others_mvs_media',
				'moderate_mvs_media',
				'manage_mvs_access',
				'read_mvs_media',
				'publish_mvs_media',
				'edit_mvs_medias',
				'edit_others_mvs_medias',
				'edit_published_mvs_medias',
				'edit_private_mvs_medias',
				'delete_mvs_medias',
				'delete_others_mvs_medias',
				'delete_published_mvs_medias',
				'delete_private_mvs_medias',
				'publish_mvs_medias',
				'read_private_mvs_medias',
			),
			'author'        => array(
				'upload_mvs_media',
				'edit_mvs_media',
				'delete_mvs_media',
				'read_mvs_media',
				'publish_mvs_media',
				'edit_mvs_medias',
				'edit_published_mvs_medias',
				'delete_mvs_medias',
				'delete_published_mvs_medias',
				'publish_mvs_medias',
			),
			'contributor'   => array(
				'upload_mvs_media',
				'edit_mvs_media',
				'delete_mvs_media',
				'read_mvs_media',
				'edit_mvs_medias',
				'delete_mvs_medias',
			),
			'subscriber'    => array(
				'upload_mvs_media',
				'read_mvs_media',
				'edit_mvs_medias',
				'delete_mvs_medias',
			),
		);
	}

	/**
	 * Base upload/edit/delete caps granted to every role.
	 *
	 * @return string[]
	 */
	private static function get_base_member_caps(): array {
		return array(
			'upload_mvs_media',
			'edit_mvs_media',
			'delete_mvs_media',
			'read_mvs_media',
			'edit_mvs_medias',
			'delete_mvs_medias',
		);
	}

	/**
	 * Add capabilities to roles (on activation).
	 *
	 * Named roles get their full cap set from get_role_caps().
	 * All other roles (custom, BuddyPress, bbPress, etc.) get base member caps.
	 *
	 * Admin Permissions-matrix overrides are re-applied LAST, so the
	 * version-gated re-grant in Plugin::init() / Migrator no longer resets
	 * an admin's manual revocations on every update (Basecamp #9937811664).
	 */
	public static function add_caps(): void {
		$named = self::get_role_caps();

		foreach ( $named as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		// Grant base caps to all other roles not in the named list.
		global $wp_roles;
		$base_caps = self::get_base_member_caps();
		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			if ( isset( $named[ $role_slug ] ) ) {
				continue;
			}
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $base_caps as $cap ) {
				$role->add_cap( $cap );
			}
		}

		self::apply_admin_overrides();
	}

	/**
	 * Re-apply the admin's saved Permissions-matrix choices on top of the
	 * default grants.
	 *
	 * No-op until the admin saves the matrix at least once (the option is
	 * absent on existing sites, so default behaviour is unchanged).
	 *
	 * @since 1.6.0
	 */
	public static function apply_admin_overrides(): void {
		$overrides = get_option( self::OVERRIDES_OPTION, array() );
		if ( empty( $overrides ) || ! is_array( $overrides ) ) {
			return;
		}

		foreach ( $overrides as $role_slug => $caps ) {
			$role = get_role( (string) $role_slug );
			if ( ! $role || ! is_array( $caps ) ) {
				continue;
			}
			foreach ( $caps as $cap => $granted ) {
				$cap     = (string) $cap;
				$applies = array( $cap );
				if ( isset( self::PLURAL_CAP_MAP[ $cap ] ) ) {
					$applies[] = self::PLURAL_CAP_MAP[ $cap ];
				}
				foreach ( $applies as $apply_cap ) {
					if ( $granted ) {
						$role->add_cap( $apply_cap );
					} else {
						$role->remove_cap( $apply_cap );
					}
				}
			}
		}
	}

	/**
	 * Remove capabilities from roles (on uninstall).
	 */
	public static function remove_caps(): void {
		$all_caps = array_merge(
			self::get_base_member_caps(),
			array(
				'edit_others_mvs_media',
				'delete_others_mvs_media',
				'moderate_mvs_media',
				'manage_mvs_settings',
				'manage_mvs_access',
				'publish_mvs_media',
				'edit_others_mvs_medias',
				'edit_published_mvs_medias',
				'edit_private_mvs_medias',
				'delete_others_mvs_medias',
				'delete_published_mvs_medias',
				'delete_private_mvs_medias',
				'publish_mvs_medias',
				'read_private_mvs_medias',
			)
		);

		global $wp_roles;
		foreach ( $wp_roles->roles as $role_slug => $role_data ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
