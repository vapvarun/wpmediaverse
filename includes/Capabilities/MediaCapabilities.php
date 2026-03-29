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
				// Mapped meta caps for CPT.
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
				'read_mvs_media',
				'edit_mvs_medias',
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
	 * Add capabilities to roles (on activation).
	 */
	public static function add_caps(): void {
		foreach ( self::get_role_caps() as $role_name => $caps ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				$role->add_cap( $cap );
			}
		}
	}

	/**
	 * Remove capabilities from roles (on uninstall).
	 */
	public static function remove_caps(): void {
		$all_caps = array();
		foreach ( self::get_role_caps() as $caps ) {
			$all_caps = array_merge( $all_caps, $caps );
		}
		$all_caps = array_unique( $all_caps );

		$roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );
		foreach ( $roles as $role_name ) {
			$role = get_role( $role_name );
			if ( ! $role ) {
				continue;
			}
			foreach ( $all_caps as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}
}
