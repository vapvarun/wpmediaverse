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

	/**
	 * Stamp the admin watermark into bytes a member is publishing right now.
	 *
	 * THE RULE, stated once: stamp what the member publishes now; never
	 * re-process what is already in the library.
	 *
	 * This is the only place `mvs_watermark_stamp_file` is fired. Two ingest
	 * paths introduce new member bytes and both call it:
	 *
	 *   - `UploadService::handle()`            — a brand-new upload.
	 *   - `MediaController::replace_item()`    — a new file for an existing row.
	 *
	 * `UploadService::sideload_external_file()` deliberately does NOT call it.
	 * That path re-ingests bytes already in the library: `StorageRepairService`
	 * heals a stored file, and the Pro CLI importers migrate legacy MediaPress /
	 * rtMedia / BuddyBoss media. Those files are either already stamped — and
	 * nothing records that a file was stamped, so a second pass would draw a
	 * second watermark — or they are pre-existing content the admin never agreed
	 * to alter. Watermarking applies to new uploads only. The omission there is
	 * deliberate; do not "fix" it by adding a call.
	 *
	 * Callers must stamp BEFORE the bytes are stored and before any derivative
	 * (WebP/AVIF sibling, thumbnail) is cut, so every derivative inherits the
	 * mark from one draw. Callers that record a content hash of the user's
	 * source must take that hash BEFORE calling this — the stamp rewrites the
	 * file in place.
	 *
	 * @param string $path    Absolute path to the image bytes. Stamped IN PLACE.
	 * @param string $mime    Source mime type.
	 * @param int    $user_id Uploader — drives the {username} token and role scope.
	 * @return bool True when a watermark was actually drawn into the file.
	 */
	public function stamp_new_upload( string $path, string $mime, int $user_id ): bool {
		// Images only. Video and audio are never watermarked, and returning
		// early here keeps the failure log below from firing on every video.
		if ( 0 !== strpos( $mime, 'image/' ) ) {
			return false;
		}

		if ( ! $this->applies_to_user( $user_id ) ) {
			return false;
		}

		$stamped = (bool) apply_filters( 'mvs_watermark_stamp_file', false, $path, $mime, $user_id );

		// Do NOT fail open silently. When a stamper is registered (Pro active)
		// but the stamp did not succeed, a paid "protect my media" upload would
		// otherwise be stored and served UN-watermarked with no trace. Log an
		// error so the site owner sees it on the Logs screen and can act (most
		// often: the GD image library is unavailable). Basecamp 10073499080.
		if ( ! $stamped && has_filter( 'mvs_watermark_stamp_file' ) ) {
			LoggerService::error(
				'watermark',
				'Watermark stamp failed; the un-watermarked original was stored. Check that the GD image library is available on this server.',
				array(
					'user_id' => $user_id,
					'mime'    => $mime,
				)
			);
		}

		return $stamped;
	}
}
