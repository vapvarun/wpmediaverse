<?php
/**
 * Template helper functions.
 *
 * Shared rendering utilities used by all templates and BuddyPress integration
 * to ensure consistent thumbnail resolution, placeholders, and grid markup.
 *
 * All media lookups go through mvs_media_index / MediaMeta -- never get_post().
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\MediaMeta;

/**
 * Static helper methods for template rendering.
 */
class TemplateHelpers {

	/**
	 * Resolve the best thumbnail URL for a media item.
	 *
	 * Priority: WP attachment thumbnail > file_url (images only).
	 * For video/audio, tries attachment_id first (generated poster).
	 *
	 * @param int    $media_id Media ID (mvs_media_index.media_id).
	 * @param string $size     WordPress image size.
	 * @return string Thumbnail URL or empty string.
	 */
	public static function get_thumb_url( int $media_id, string $size = 'large' ): string {
		$attach_id  = (int) MediaMeta::get( $media_id, 'attachment_id' );
		$file_url   = MediaMeta::get( $media_id, 'file_url' );
		$media_type = self::get_media_type( $media_id );

		// Try WP attachment thumbnail (works for all types including video posters).
		if ( $attach_id ) {
			$thumb_src = wp_get_attachment_image_url( $attach_id, $size );
			if ( $thumb_src ) {
				return set_url_scheme( $thumb_src );
			}
		}

		// For images, fall back to the file URL directly.
		if ( 'image' === $media_type && $file_url ) {
			return set_url_scheme( $file_url );
		}

		return '';
	}

	/**
	 * Get the media type (image, video, audio, document) for a media item.
	 *
	 * @param int $media_id Media ID (mvs_media_index.media_id).
	 * @return string One of: image, video, audio, document.
	 */
	public static function get_media_type( int $media_id ): string {
		$media_type = MediaMeta::get( $media_id, 'media_type' );
		if ( $media_type ) {
			return $media_type;
		}

		// Fallback: derive from MIME type.
		$file_type = MediaMeta::get( $media_id, 'file_type' );
		if ( $file_type ) {
			if ( strpos( $file_type, 'image/' ) === 0 ) {
				return 'image';
			}
			if ( strpos( $file_type, 'video/' ) === 0 ) {
				return 'video';
			}
			if ( strpos( $file_type, 'audio/' ) === 0 ) {
				return 'audio';
			}
		}

		return 'document';
	}

	/**
	 * Render a grid item's thumbnail or type-appropriate placeholder.
	 *
	 * @param int    $media_id Media ID (mvs_media_index.media_id).
	 * @param string $size     WordPress image size.
	 * @param string $alt      Alt text for the image.
	 */
	public static function render_grid_thumbnail( int $media_id, string $size = 'large', string $alt = '' ): void {
		$thumb_url  = self::get_thumb_url( $media_id, $size );
		$media_type = self::get_media_type( $media_id );

		if ( ! $alt ) {
			$title = MediaMeta::get( $media_id, 'title' );
			$alt   = $title ? $title : '';
		}

		if ( $thumb_url ) {
			echo '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $alt ) . '" loading="lazy" />';
			if ( 'video' === $media_type ) {
				echo '<span class="mvs-grid-play-icon">&#9654;</span>';
			}
		} elseif ( 'video' === $media_type ) {
			echo '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video">';
			echo '<span class="mvs-grid-play-icon">&#9654;</span>';
			echo '</div>';
		} elseif ( 'audio' === $media_type ) {
			echo '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio">';
			echo '<span class="mvs-grid-audio-icon">&#9835;</span>';
			echo '</div>';
		} else {
			echo '<div class="mvs-grid-item-placeholder">';
			echo '<span class="dashicons dashicons-media-default"></span>';
			echo '</div>';
		}
	}

	/**
	 * Render a complete grid item (thumbnail + overlay + info row).
	 *
	 * Reads media data from mvs_media_index via MediaMeta -- no get_post().
	 *
	 * @param int   $media_id Media ID (mvs_media_index.media_id).
	 * @param array $stats    Optional stats array with 'views', 'reactions', 'comments' keys.
	 * @param array $options  Optional rendering options:
	 *                        - 'show_author' (bool) Show author row below thumbnail. Default true.
	 *                        - 'show_overlay' (bool) Show stats overlay on hover. Default true.
	 *                        - 'data_attrs' (array) Extra data-* attributes for the grid item div.
	 *                        - 'size' (string) Image size. Default 'medium'.
	 */
	public static function render_grid_item( int $media_id, array $stats = array(), array $options = array() ): void {
		$show_author  = $options['show_author'] ?? true;
		$show_overlay = $options['show_overlay'] ?? true;
		$data_attrs   = $options['data_attrs'] ?? array();
		$size         = $options['size'] ?? get_option( 'mvs_thumbnail_size', 'large' );

		// Read core fields from mvs_media_index.
		$media_row = MediaMeta::get_all( $media_id );
		if ( empty( $media_row ) || empty( $media_row['media_id'] ) ) {
			return;
		}
		$media_status = $media_row['status'] ?? 'publish';
		if ( 'publish' !== $media_status ) {
			return;
		}

		$media_title  = $media_row['title'] ?? '';
		$author_id    = (int) ( $media_row['post_author'] ?? 0 );
		$permalink    = MediaMeta::get_permalink( $media_id );
		$views        = isset( $stats['views'] ) ? (int) $stats['views'] : 0;
		$reactions    = isset( $stats['reactions'] ) ? (int) $stats['reactions'] : 0;
		$comments     = isset( $stats['comments'] ) ? (int) $stats['comments'] : 0;

		// Build data attributes string.
		$data_attrs['media-id']   = $media_id;
		$data_attrs['media-type'] = self::get_media_type( $media_id );
		$data_str                 = '';
		foreach ( $data_attrs as $key => $val ) {
			$data_str .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		// Check if this is a gallery group cover.
		$media_group  = MediaMeta::get( $media_id, 'media_group' );
		$group_count  = 0;
		$is_gallery   = false;
		if ( $media_group ) {
			$is_gallery  = true;
			$group_count = (int) MediaMeta::get( $media_id, 'group_count_cache' );
			if ( ! $group_count ) {
				global $wpdb;
				$group_count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = 'media_group' AND meta_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$media_group
					)
				);
			}
		}

		$item_class = 'mvs-grid-item' . ( $is_gallery ? ' mvs-grid-item--gallery' : '' );

		// Interactivity API context for lightbox.
		$lightbox_ctx = wp_interactivity_data_wp_context(
			array(
				'mediaId' => $media_id,
				'restUrl' => esc_url_raw( rest_url( 'mvs/v1/' ) ),
			)
		);

		echo '<div class="' . esc_attr( $item_class ) . '"' . $data_str // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. ' data-wp-interactive="mvs/shared-ui" '
			. $lightbox_ctx // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '>';
		echo '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link"'
			. ' data-wp-on--click="actions.openLightbox"'
			. '>';

		self::render_grid_thumbnail( $media_id, $size, $media_title );

		// Gallery badge showing item count.
		if ( $is_gallery && $group_count > 1 ) {
			echo '<span class="mvs-gallery-badge" title="' . esc_attr( sprintf( '%d photos', $group_count ) ) . '">';
			echo '<span class="dashicons dashicons-images-alt2"></span> ' . esc_html( $group_count );
			echo '</span>';
		}

		if ( $show_overlay ) {
			echo '<div class="mvs-grid-item-overlay"><div class="mvs-grid-item-stats">';
			if ( $views ) {
				echo '<span class="mvs-grid-stat">&#x1F441;&#xFE0F; ' . esc_html( number_format_i18n( $views ) ) . '</span>';
			}
			echo '<span class="mvs-grid-stat">&#x2764;&#xFE0F; ' . esc_html( number_format_i18n( $reactions ) ) . '</span>';
			echo '<span class="mvs-grid-stat">&#x1F4AC; ' . esc_html( number_format_i18n( $comments ) ) . '</span>';
			echo '</div></div>';
		}

		echo '</a>';

		if ( $show_author && $author_id ) {
			echo '<div class="mvs-grid-item-info">';
			echo get_avatar( $author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) );
			echo '<span class="mvs-grid-item-author">' . esc_html( get_the_author_meta( 'display_name', $author_id ) ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Bulk-fetch stats for an array of media IDs.
	 *
	 * @param int[] $media_ids Array of media IDs (mvs_media_index.media_id).
	 * @return array Associative array keyed by media_id with views/reactions/comments.
	 */
	public static function bulk_get_stats( array $media_ids ): array {
		if ( empty( $media_ids ) ) {
			return array();
		}

		global $wpdb;
		$stats_table  = $wpdb->prefix . 'mvs_media_stats';
		$placeholders = implode( ',', array_fill( 0, count( $media_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT media_id, views, reactions, comments FROM {$stats_table} WHERE media_id IN ({$placeholders})",
				...$media_ids
			),
			ARRAY_A
		);
		// phpcs:enable

		$map = array();
		foreach ( $rows as $row ) {
			$map[ (int) $row['media_id'] ] = $row;
		}

		return $map;
	}
}
