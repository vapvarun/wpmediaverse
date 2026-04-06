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
		$file_type  = MediaRepository::get( $media_id, 'file_type' );
		$media_type = MediaRepository::get( $media_id, 'media_type' );
		$file_url   = (string) MediaRepository::get( $media_id, 'file_url' );

		$thumb_url = TemplateHelpers::get_thumb_url( $media_id, $size );
		if ( ! $thumb_url && $file_url && strpos( $file_type, 'image/' ) === 0 ) {
			$thumb_url = $file_url;
		}

		$permalink = MediaRepository::get_permalink( $media_id );
		$title     = MediaRepository::get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );

		// Link to the media single page. The lightbox is handled by the shared-ui
		// Interactivity API module when the user clicks from the activity stream.
		$href     = $permalink ?: $file_url;
		$data_mid = ' data-mvs-media-id="' . esc_attr( $media_id ) . '"';

		// Image or video with poster thumbnail.
		if ( $thumb_url ) {
			$thumb_url = set_url_scheme( $thumb_url );
			$overlay   = '';
			if ( 'video' === $media_type ) {
				$overlay = '<span class="mvs-activity-play-icon" aria-hidden="true"></span>';
			}

			return '<div class="mvs-activity-media mvs-activity-media--' . esc_attr( $media_type ) . '"' . $data_mid . '><a href="' . esc_url( $href ) . '">' . $overlay . '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" /></a></div>';
		}

		// Video without poster: show dark placeholder with play icon.
		if ( 'video' === $media_type ) {
			// translators: %s: video title.
			return '<div class="mvs-activity-media mvs-activity-media--video mvs-activity-media--placeholder"' . $data_mid . ' style="position:relative;overflow:hidden;"><a href="' . esc_url( $href ) . '" class="mvs-activity-vid-link" aria-label="' . esc_attr( sprintf( __( 'Play video: %s', 'wpmediaverse' ), $title ) ) . '"><span class="mvs-activity-play-icon" aria-hidden="true"></span><span class="mvs-activity-media-label">' . esc_html( $title ) . '</span></a></div>';
		}

		// Audio: show compact audio card.
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

		return '';
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
