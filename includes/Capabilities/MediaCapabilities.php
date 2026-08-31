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
	 * Whether a role may use the document library at all.
	 *
	 * Documents shipped riding on `upload_mvs_media`, which conflated two
	 * different questions: "may this member upload" and "is this feature for
	 * this member". A site that wants photos for everyone but documents for
	 * staff had no way to say so.
	 *
	 * GRANTED TO EVERY MEMBER ROLE BY DEFAULT, and that is load-bearing rather
	 * than generous: this cap is introduced on an existing feature, so a
	 * default-denied cap would empty every member's drive the moment the site
	 * updated — data still there, feature gone, indistinguishable from a
	 * regression. The owner takes it away deliberately or not at all.
	 *
	 * It lives in Free, next to the caps it sits beside, because Free already
	 * owns the document vocabulary (`DocumentTypes`, `DocumentListPage`,
	 * `Plugin::documents_enabled()`) and because the version-gated `add_caps()`
	 * here is the only thing that grants a new cap to roles that already exist.
	 * Pro owns the implementation, not the permission.
	 *
	 * @since 2.4.0
	 */
	const USE_DOCUMENTS_CAP = 'use_mvs_documents';

	/**
	 * Capability mapping per role.
	 *
	 * @return array<string, string[]>
	 */
	public static function get_role_caps(): array {
		return array(
			'administrator' => array(
				'upload_mvs_media',
				self::USE_DOCUMENTS_CAP,
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
				self::USE_DOCUMENTS_CAP,
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
				self::USE_DOCUMENTS_CAP,
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
				self::USE_DOCUMENTS_CAP,
				'edit_mvs_media',
				'delete_mvs_media',
				'read_mvs_media',
				// Community platform, not a blog: contributor publishes too
				// (no WP submit-for-review semantics here). See subscriber note.
				'publish_mvs_media',
				'edit_mvs_medias',
				'delete_mvs_medias',
				'publish_mvs_medias',
			),
			'subscriber'    => array(
				'upload_mvs_media',
				self::USE_DOCUMENTS_CAP,
				'read_mvs_media',
				// MediaVerse is a community/social platform, NOT a blog/CMS:
				// any logged-in member uploads media and it publishes
				// immediately (Instagram/Facebook model) - there is no
				// submit-for-review or pre-moderation step. publish_mvs_media is
				// granted to every member role (here + contributor + base) so the
				// Settings -> Permissions matrix matches the actual behaviour and
				// there is no upload-can-but-publish-cannot mismatch
				// (Basecamp #9962830813).
				'publish_mvs_media',
				'edit_mvs_medias',
				'delete_mvs_medias',
				'publish_mvs_medias',
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
			self::USE_DOCUMENTS_CAP,
			'edit_mvs_media',
			'delete_mvs_media',
			'read_mvs_media',
			// Every member role publishes by default (community platform).
			// Pre-moderation is opt-in site-wide via the
			// mvs_hold_uploads_for_moderation filter, not per-role.
			'publish_mvs_media',
			'edit_mvs_medias',
			'delete_mvs_medias',
			'publish_mvs_medias',
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
	 * Apply a role => cap => granted matrix, and record it as an override.
	 *
	 * THE ONLY WRITE PATH FOR A MANAGED CAP. Two screens now grant the same
	 * capability — the Permissions matrix, and Pro's "Who can use documents"
	 * field on the Documents settings screen — and a capability written two
	 * ways is a capability whose two screens will eventually disagree. Both go
	 * through here instead.
	 *
	 * Does three things that all have to happen together, which is the other
	 * reason this is one method rather than a convention:
	 *
	 * 1. writes the live `WP_Role`, so the change takes effect this request;
	 * 2. keeps the plural CPT twin in sync (`edit_mvs_media` ↔
	 *    `edit_mvs_medias`), because `MediaController` checks the plural;
	 * 3. records the choice in `OVERRIDES_OPTION`, which `add_caps()` replays
	 *    AFTER its default grants — without this a plugin update silently
	 *    restores a cap the owner deliberately revoked (Basecamp #9937811664).
	 *
	 * The option is merged, never replaced, so a caller passing one cap for one
	 * role does not erase what another screen recorded.
	 *
	 * @since 2.4.0
	 *
	 * @param array<string, array<string, bool>> $matrix Role slug => cap => granted.
	 * @return int Number of roles whose effective caps actually changed.
	 */
	public static function apply_role_caps( array $matrix ): int {
		$overrides = get_option( self::OVERRIDES_OPTION, array() );
		if ( ! is_array( $overrides ) ) {
			$overrides = array();
		}

		$changed_roles = 0;

		foreach ( $matrix as $role_slug => $caps ) {
			$role = get_role( (string) $role_slug );
			if ( ! $role || ! is_array( $caps ) ) {
				continue;
			}

			$changed = false;

			foreach ( $caps as $cap => $granted ) {
				$cap     = (string) $cap;
				$granted = (bool) $granted;

				if ( $granted !== $role->has_cap( $cap ) ) {
					$changed = true;
				}

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

				$overrides[ (string) $role_slug ][ $cap ] = $granted ? 1 : 0;
			}

			if ( $changed ) {
				++$changed_roles;
			}
		}

		update_option( self::OVERRIDES_OPTION, $overrides, false );

		return $changed_roles;
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
