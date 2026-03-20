<?php
/**
 * Template helper functions.
 *
 * Shared rendering utilities used by all templates and BuddyPress integration
 * to ensure consistent thumbnail resolution, placeholders, and grid markup.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper methods for template rendering.
 */
class TemplateHelpers {

	/**
	 * Resolve the best thumbnail URL for a media item.
	 *
	 * Priority: WP attachment thumbnail > file_url (images only).
	 * For video/audio, tries _mvs_attachment_id first (generated poster).
	 *
	 * @param int    $media_id Media post ID.
	 * @param string $size     WordPress image size.
	 * @return string Thumbnail URL or empty string.
	 */
	public static function get_thumb_url( int $media_id, string $size = 'medium' ): string {
		$attach_id  = (int) get_post_meta( $media_id, '_mvs_attachment_id', true );
		$file_url   = get_post_meta( $media_id, '_mvs_file_url', true );
		$media_type = self::get_media_type( $media_id );

		// Try WordPress featured image first (set via admin editor).
		$featured_id = (int) get_post_thumbnail_id( $media_id );
		if ( $featured_id ) {
			$featured_src = wp_get_attachment_image_url( $featured_id, $size );
			if ( $featured_src ) {
				return set_url_scheme( $featured_src );
			}
		}

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
	 * @param int $media_id Media post ID.
	 * @return string One of: image, video, audio, document.
	 */
	public static function get_media_type( int $media_id ): string {
		$media_type = get_post_meta( $media_id, '_mvs_media_type', true );
		if ( $media_type ) {
			return $media_type;
		}

		// Fallback: derive from MIME type.
		$file_type = get_post_meta( $media_id, '_mvs_file_type', true );
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
	 * Outputs either an <img> tag (with optional video play icon overlay)
	 * or a placeholder <div> with the correct icon for the media type.
	 *
	 * @param int    $media_id Media post ID.
	 * @param string $size     WordPress image size.
	 * @param string $alt      Alt text for the image.
	 */
	public static function render_grid_thumbnail( int $media_id, string $size = 'medium', string $alt = '' ): void {
		$thumb_url  = self::get_thumb_url( $media_id, $size );
		$media_type = self::get_media_type( $media_id );

		if ( ! $alt ) {
			$alt = get_the_title( $media_id );
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
	 * Used for explore, collection, BP profile, and BP group media grids.
	 *
	 * @param int   $media_id Media post ID.
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
		$size         = $options['size'] ?? 'medium';
		$media_post   = get_post( $media_id );

		if ( ! $media_post || 'publish' !== $media_post->post_status ) {
			return;
		}

		$permalink = get_permalink( $media_id );
		$views     = isset( $stats['views'] ) ? (int) $stats['views'] : 0;
		$reactions = isset( $stats['reactions'] ) ? (int) $stats['reactions'] : 0;
		$comments  = isset( $stats['comments'] ) ? (int) $stats['comments'] : 0;

		// Build data attributes string — always include media-id and media-type for lightbox.
		$data_attrs['media-id']   = $media_id;
		$data_attrs['media-type'] = self::get_media_type( $media_id );
		$data_str = '';
		foreach ( $data_attrs as $key => $val ) {
			$data_str .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		echo '<div class="mvs-grid-item"' . $data_str . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link">';

		self::render_grid_thumbnail( $media_id, $size, $media_post->post_title );

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

		if ( $show_author ) {
			echo '<div class="mvs-grid-item-info">';
			echo get_avatar( (int) $media_post->post_author, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) );
			echo '<span class="mvs-grid-item-author">' . esc_html( get_the_author_meta( 'display_name', $media_post->post_author ) ) . '</span>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Bulk-fetch stats for an array of media IDs.
	 *
	 * @param int[] $media_ids Array of media post IDs.
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
