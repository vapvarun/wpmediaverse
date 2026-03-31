<?php
/**
 * Share service.
 *
 * Handles internal and external sharing of media items.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Social;

use WPMediaVerse\Services\MediaMeta;

defined( 'ABSPATH' ) || exit;

/**
 * Handles media sharing and share count tracking.
 */
class ShareService {

	/**
	 * Record a share event and increment the stats counter.
	 *
	 * @param int    $media_id Media post ID.
	 * @param int    $user_id  User ID (0 for anonymous).
	 * @param string $platform Platform: 'internal', 'facebook', 'twitter', 'link', etc.
	 * @return array{media_id: int, platform: string, share_url: string}
	 */
	public function record_share( int $media_id, int $user_id, string $platform = 'link' ): array {
		global $wpdb;

		// Increment share count in stats.
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}mvs_media_stats SET shares = shares + 1, updated_at = %s WHERE media_id = %d",
				current_time( 'mysql', true ),
				$media_id
			)
		);

		$share_url = $this->generate_share_url( $media_id );

		/**
		 * Fires after a media share is recorded.
		 *
		 * @param int    $media_id Media post ID.
		 * @param int    $user_id  User ID.
		 * @param string $platform Share platform.
		 */
		do_action( 'mvs_media_shared', $media_id, $user_id, $platform );

		return array(
			'media_id'  => $media_id,
			'platform'  => $platform,
			'share_url' => $share_url,
		);
	}

	/**
	 * Generate a shareable URL for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return string
	 */
	public function generate_share_url( int $media_id ): string {
		return MediaMeta::get_permalink( $media_id );
	}

	/**
	 * Get share count for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return int
	 */
	public function get_share_count( int $media_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT shares FROM {$wpdb->prefix}mvs_media_stats WHERE media_id = %d",
				$media_id
			)
		);
	}

	/**
	 * Get external share links for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return array<string, string> Keyed by platform name.
	 */
	public function get_share_links( int $media_id ): array {
		$url   = rawurlencode( $this->generate_share_url( $media_id ) );
		$title = rawurlencode( get_the_title( $media_id ) );

		return array(
			'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $url,
			'twitter'  => 'https://twitter.com/intent/tweet?url=' . $url . '&text=' . $title,
			'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $url,
			'email'    => 'mailto:?subject=' . $title . '&body=' . $url,
		);
	}
}
