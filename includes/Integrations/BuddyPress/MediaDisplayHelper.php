<?php
/**
 * MediaDisplayHelper — shared thumbnail and label utilities for BuddyPress integration.
 *
 * Shared thumbnail and label utilities reused by activity-stream callbacks
 * and other BuddyPress sub-integrations.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Repository\MediaRepository;
use WPMediaVerse\Services\MediaUrl;

/**
 * Static helpers for rendering media thumbnails and type labels inside
 * BuddyPress activity streams.
 */
class MediaDisplayHelper {

	/**
	 * Build the thumbnail HTML for a single media item inside an activity entry.
	 *
	 * Returns an empty string when no renderable content is available.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     Image size slug. Default 'medium'.
	 * @return string Escaped HTML ready for output.
	 */
	public static function get_media_thumbnail_html( int $media_id, string $size = 'medium' ): string {
		$media_type = (string) MediaRepository::get( $media_id, 'media_type' );
		if ( ! in_array( $media_type, array( 'image', 'video', 'audio' ), true ) ) {
			return '';
		}

		// `$file_url` is used purely as a fallback for the link `href` when
		// the media has no permalink (e.g. cleanup state). Must be signed —
		// raw `/wp-content/uploads/wpmediaverse/` URLs hit the .htaccess gate.
		// We use `for_broadcast()` (1-year TTL, user_id=0) instead of
		// `for_file()` (1-hour TTL, current user) because this thumbnail HTML
		// gets baked into bp_activity.content and read by anyone scrolling
		// the activity feed days later. Short-TTL URLs would 403 on every
		// activity older than 1 hour. Privacy is enforced at sign time so
		// non-public media silently returns '' and the link omits the href.
		$permalink = MediaRepository::get_permalink( $media_id );
		$file_url  = MediaUrl::for_broadcast( $media_id );
		$title     = MediaRepository::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		$href      = $permalink ?: $file_url;
		$data_mid  = ' data-mvs-media-id="' . esc_attr( $media_id ) . '"';

		// Audio gets a compact card that isn't suitable for the canonical
		// thumbnail helper (which targets square-ish grid cells). Keep it local.
		if ( 'audio' === $media_type ) {
			$artist   = MediaRepository::get( $media_id, 'artist' );
			$duration = MediaRepository::get( $media_id, 'duration' );
			$sub      = '';
			if ( $artist ) {
				$sub .= esc_html( $artist );
			}
			if ( $duration ) {
				$minutes = floor( (float) $duration / 60 );
				$seconds = (int) $duration % 60;
				$sub    .= ( $sub ? ' &middot; ' : '' ) . sprintf( '%d:%02d', $minutes, $seconds );
			}
			return '<div class="mvs-activity-media mvs-activity-media--audio"' . $data_mid . ' style="border-radius:12px;"><a href="' . esc_url( $href ) . '" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;"><span class="mvs-activity-audio-icon" style="font-size:1.5em;flex-shrink:0;">&#9835;</span><span class="mvs-activity-audio-info" style="min-width:0;"><span class="mvs-activity-audio-title" style="display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html( $title ) . '</span>' . ( $sub ? '<span class="mvs-activity-audio-meta" style="display:block;font-size:.8em;color:#666;">' . $sub . '</span>' : '' ) . '</span></a></div>';
		}

		// Images + videos: delegate to the canonical helper so BP activity, the
		// Explore grid, and Pro layouts all share one branching logic (img /
		// native <video> preview / placeholder). `ttl` + `user_id` force the
		// inner <img src> through the same broadcast-TTL signing as $file_url
		// above — otherwise the inner thumbnail would still 403 after 1h even
		// though the outer link href stays valid for a year.
		$inner = TemplateHelpers::media_thumbnail(
			$media_id,
			array(
				'size'      => $size,
				'alt'       => esc_attr( $title ),
				'show_play' => true,
				'ttl'       => YEAR_IN_SECONDS,
				'user_id'   => 0,
			)
		);

		return '<div class="mvs-activity-media mvs-activity-media--' . esc_attr( $media_type ) . '"' . $data_mid . '><a href="' . esc_url( $href ) . '">' . $inner . '</a></div>';
	}

	/**
	 * Get a human-readable label for a MIME type.
	 *
	 * @param string|null $file_type MIME type string, or null.
	 * @return string Translated label.
	 */
	public static function get_media_type_label( ?string $file_type ): string {
		if ( ! $file_type ) {
			return __( 'media', 'wpmediaverse' );
		}
		if ( strpos( $file_type, 'image/' ) === 0 ) {
			return __( 'photo', 'wpmediaverse' );
		}
		if ( strpos( $file_type, 'video/' ) === 0 ) {
			return __( 'video', 'wpmediaverse' );
		}
		if ( strpos( $file_type, 'audio/' ) === 0 ) {
			return __( 'audio file', 'wpmediaverse' );
		}
		return __( 'file', 'wpmediaverse' );
	}
}
