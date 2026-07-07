<?php
/**
 * Watermark service.
 *
 * Resolves the admin watermark configuration and scope. The watermark is
 * stamped into the image at upload time by Pro's Watermarker (via the
 * `mvs_watermark_stamp_file` filter), so it is baked into the stored file and
 * every derivative — there is no per-media or serve-time watermarking.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Watermark service — admin config + upload-scope resolution.
 */
class WatermarkService {

	/**
	 * Watermark position constants.
	 *
	 * @var string
	 */
	const POSITION_CENTER       = 'center';
	const POSITION_BOTTOM_RIGHT = 'bottom-right';
	const POSITION_BOTTOM_LEFT  = 'bottom-left';
	const POSITION_TOP_RIGHT    = 'top-right';
	const POSITION_TOP_LEFT     = 'top-left';
	const POSITION_TILE         = 'tile';

	/**
	 * Valid positions.
	 *
	 * @var string[]
	 */
	const POSITIONS = array( 'center', 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'tile' );

	/**
	 * Check if watermarking is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = (bool) get_option( 'mvs_watermark_enabled', false );

		/**
		 * Filter whether watermarking is enabled.
		 *
		 * @param bool $enabled Whether watermarking is enabled.
		 */
		return (bool) apply_filters( 'mvs_watermark_enabled', $enabled );
	}

	/**
	 * Whether an upload by the given user should be watermarked, per the admin
	 * scope setting. `all` (default) stamps every upload; `roles` stamps only
	 * uploads from users whose role is in mvs_watermark_roles. Admin decides
	 * once; every matching upload is stamped at ingest so everyone sees it.
	 *
	 * @param int $user_id Uploader user ID.
	 * @return bool
	 */
	public function applies_to_user( int $user_id ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$mode = (string) get_option( 'mvs_watermark_apply', 'all' );
		if ( 'roles' !== $mode ) {
			return true; // 'all' — every upload is watermarked.
		}

		$roles = (array) get_option( 'mvs_watermark_roles', array() );
		if ( empty( $roles ) || $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		return (bool) array_intersect( (array) $user->roles, $roles );
	}
}
