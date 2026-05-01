<?php
/**
 * Media URL resolver — single point of control for routing
 * `wp-content/uploads/wpmediaverse/...` URLs through SignedUrlService.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Single helper for frontend / admin code paths that emit a media URL.
 *
 * Use one of:
 * - `MediaUrl::for_media( $media_id, $size )` — when you have the media ID
 * - `MediaUrl::for_thumbnail( $media_id, $size )` — explicit thumbnail
 * - `MediaUrl::for_file( $media_id )` — full file
 * - `MediaUrl::resolve( $url, $media_id, $size )` — when you only have a stored URL
 *
 * All paths route through SignedUrlService when the URL points inside the
 * gated uploads directory. URLs outside that directory (avatars, theme
 * images, external CDN assets) pass through unchanged.
 *
 * Why this exists: WPMediaVerse 1.1.3 added `.htaccess` deny-all on
 * `wp-content/uploads/wpmediaverse/`. Every site that renders a media URL
 * (templates, blocks, REST responses, admin lists) MUST route through this
 * helper — a raw URL emission 403s for the user.
 */
class MediaUrl {

	/**
	 * Get a signed thumbnail URL for a media item.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     'large', 'medium', or 'thumbnail'.
	 * @return string Signed URL or empty string when SignedUrlService is unavailable.
	 */
	public static function for_thumbnail( int $media_id, string $size = 'large' ): string {
		$signed = self::signed_urls();
		if ( ! $signed || $media_id <= 0 ) {
			return '';
		}
		// $skip_privacy_check = true — grid queries already enforce privacy
		// upstream; the serve endpoint re-applies access control regardless.
		$url = $signed->generate_thumbnail( $media_id, get_current_user_id(), $size, 0, true );
		return $url ?: '';
	}

	/**
	 * Get a signed full-file URL for a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return string Signed URL or empty string.
	 */
	public static function for_file( int $media_id ): string {
		$signed = self::signed_urls();
		if ( ! $signed || $media_id <= 0 ) {
			return '';
		}
		$url = $signed->generate( $media_id, get_current_user_id() );
		return $url ?: '';
	}

	/**
	 * Long-lived signed URL for "broadcast" surfaces (BP activity feed,
	 * notification emails, embeds) where the URL is baked into HTML that
	 * is read days or months later.
	 *
	 * Bypasses the short `mvs_signed_url_ttl` (default 1h) which makes any
	 * activity feed older than 1 hour render with broken images. Signed
	 * with `user_id = 0` (anonymous viewer) so the URL works for everyone
	 * who can see the activity — privacy is enforced at sign time
	 * (`PrivacyService::can_view`) so private media silently returns ''
	 * and the caller falls back to no image, which is correct: private
	 * media should not be broadcast in a public feed.
	 *
	 * @param int $media_id Media ID.
	 * @return string Signed URL valid for ~1 year, or empty string when the
	 *                media is non-public or signing service is unavailable.
	 */
	public static function for_broadcast( int $media_id ): string {
		$signed = self::signed_urls();
		if ( ! $signed || $media_id <= 0 ) {
			return '';
		}
		$url = $signed->generate( $media_id, 0, YEAR_IN_SECONDS );
		return $url ?: '';
	}

	/**
	 * Long-lived signed thumbnail URL for "broadcast" surfaces.
	 *
	 * See `for_broadcast()` for rationale. Same TTL + user_id semantics.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     'large', 'medium', or 'thumbnail'.
	 * @return string Signed URL valid for ~1 year, or empty string.
	 */
	public static function for_broadcast_thumbnail( int $media_id, string $size = 'large' ): string {
		$signed = self::signed_urls();
		if ( ! $signed || $media_id <= 0 ) {
			return '';
		}
		// $skip_privacy_check = false — broadcast emission MUST verify privacy
		// at sign time so private media never gets a broadcast URL.
		$url = $signed->generate_thumbnail( $media_id, 0, $size, YEAR_IN_SECONDS, false );
		return $url ?: '';
	}

	/**
	 * Resolve a raw URL string. Pass-through if it isn't inside the gated
	 * uploads directory (e.g. avatars, theme images, external CDN URLs).
	 *
	 * Use this when callers have a stored URL but no media ID — common in
	 * legacy schema columns like `cover_image_url` that store raw URLs.
	 *
	 * @param string $url      Raw URL.
	 * @param int    $media_id Optional media ID to skip the URL → ID lookup.
	 * @param string $size     Thumbnail size when signing as a thumbnail.
	 * @return string Signed URL, or pass-through for non-gated URLs.
	 */
	public static function resolve( string $url, int $media_id = 0, string $size = 'large' ): string {
		if ( '' === $url ) {
			return '';
		}

		// Pass-through for URLs outside the gated uploads directory.
		if ( false === strpos( $url, '/wp-content/uploads/wpmediaverse/' ) ) {
			return $url;
		}

		$signed = self::signed_urls();
		if ( ! $signed ) {
			return $url; // No service yet — fail open (URL still 403s under .htaccess).
		}

		if ( ! $media_id ) {
			$media_id = self::id_from_url( $url );
		}

		if ( $media_id ) {
			// Heuristic: if the size hints at a thumbnail (large/medium/thumbnail),
			// use generate_thumbnail; otherwise sign the full file.
			if ( in_array( $size, array( 'large', 'medium', 'thumbnail' ), true ) ) {
				$signed_url = $signed->generate_thumbnail( $media_id, get_current_user_id(), $size, 0, true );
			} else {
				$signed_url = $signed->generate( $media_id, get_current_user_id() );
			}
			if ( $signed_url ) {
				return $signed_url;
			}
		}

		return $url;
	}

	/**
	 * Look up media_id from a URL by checking mvs_media_index for matching file_url.
	 *
	 * @param string $url Raw URL.
	 * @return int Media ID or 0 when not found.
	 */
	private static function id_from_url( string $url ): int {
		global $wpdb;
		$index = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT media_id FROM {$index} WHERE file_url = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$url
			)
		);
		// phpcs:enable

		return (int) $id;
	}

	/**
	 * Resolve the SignedUrlService instance from the container.
	 *
	 * @return SignedUrlService|null
	 */
	private static function signed_urls() {
		if ( ! class_exists( '\\WPMediaVerse\\Core\\Plugin' ) ) {
			return null;
		}
		$container = Plugin::container();
		if ( ! $container || ! $container->has( 'signed_urls' ) ) {
			return null;
		}
		return $container->get( 'signed_urls' );
	}
}
