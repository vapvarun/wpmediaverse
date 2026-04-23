<?php
/**
 * Template helper functions.
 *
 * Shared rendering utilities used by all templates and BuddyPress integration
 * to ensure consistent thumbnail resolution, placeholders, and grid markup.
 *
 * All media lookups go through mvs_media_index / MediaRepository -- never get_post().
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Static helper methods for template rendering.
 */
class TemplateHelpers {

	/**
	 * Resolve the best thumbnail URL for a media item.
	 *
	 * Priority: custom thumbnail meta (thumb_large/thumb_medium/thumb_thumb) >
	 * fallback to larger sizes > file_url (images only).
	 *
	 * @param int    $media_id Media ID (mvs_media_index.media_id).
	 * @param string $size     WordPress image size.
	 * @return string Thumbnail URL or empty string.
	 */
	public static function get_thumb_url( int $media_id, string $size = 'large' ): string {
		$media_type = self::get_media_type( $media_id );

		// Map WP size names to our meta keys.
		$size_map = array(
			'large'     => 'thumb_large',
			'medium'    => 'thumb_medium',
			'thumbnail' => 'thumb_thumb',
		);

		$meta_key = $size_map[ $size ] ?? 'thumb_large';

		// Try our custom thumbnail first.
		$thumb = MediaRepository::get( $media_id, $meta_key );
		if ( $thumb ) {
			return set_url_scheme( $thumb );
		}

		// Fallback: try larger sizes, then file_url for images.
		if ( 'thumb_thumb' === $meta_key ) {
			$thumb = MediaRepository::get( $media_id, 'thumb_medium' );
			if ( $thumb ) {
				return set_url_scheme( $thumb );
			}
		}
		if ( 'thumb_thumb' === $meta_key || 'thumb_medium' === $meta_key ) {
			$thumb = MediaRepository::get( $media_id, 'thumb_large' );
			if ( $thumb ) {
				return set_url_scheme( $thumb );
			}
		}

		// For images, raw file_url works as final fallback.
		$file_url = MediaRepository::get( $media_id, 'file_url' );
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
		$media_type = MediaRepository::get( $media_id, 'media_type' );
		if ( $media_type ) {
			return $media_type;
		}

		// Fallback: derive from MIME type.
		$file_type = MediaRepository::get( $media_id, 'file_type' );
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
	 * Get the profile URL for a user.
	 *
	 * Filterable via `mvs_user_profile_url` so BuddyPress, BuddyNext,
	 * or any 3rd-party profile plugin can override.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return string Profile URL.
	 */
	public static function get_user_profile_url( int $user_id ): string {
		// BuddyPress: use bp_members_get_user_url() if available.
		if ( function_exists( 'bp_members_get_user_url' ) ) {
			$url = bp_members_get_user_url( $user_id );
		} elseif ( function_exists( 'bp_core_get_user_domain' ) ) {
			$url = bp_core_get_user_domain( $user_id );
		} else {
			$user_login = get_the_author_meta( 'user_login', $user_id );
			$url        = home_url( '/media/@' . $user_login . '/' );
		}

		/**
		 * Filter the user profile URL.
		 *
		 * @param string $url     Profile URL.
		 * @param int    $user_id User ID.
		 */
		return (string) apply_filters( 'mvs_user_profile_url', $url, $user_id );
	}

	/**
	 * Get the display name for a user with optional badge/decoration.
	 *
	 * @param int $user_id User ID.
	 * @return string Display name (may contain HTML from filters).
	 */
	public static function get_display_name( int $user_id ): string {
		$name = get_the_author_meta( 'display_name', $user_id );
		/**
		 * Filter the user display name for media contexts.
		 *
		 * @param string $name    Display name.
		 * @param int    $user_id User ID.
		 */
		return (string) apply_filters( 'mvs_user_display_name', $name, $user_id );
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
			$title = MediaRepository::get( $media_id, 'title' );
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
	 * Reads media data from mvs_media_index via MediaRepository -- no get_post().
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
		$media_row = MediaRepository::get_all( $media_id );
		if ( empty( $media_row ) || empty( $media_row['media_id'] ) ) {
			return;
		}
		$media_status = $media_row['status'] ?? 'publish';
		if ( 'publish' !== $media_status ) {
			return;
		}

		$media_title = $media_row['title'] ?? '';
		$author_id   = (int) ( $media_row['post_author'] ?? 0 );
		$permalink   = MediaRepository::get_permalink( $media_id );
		$views       = isset( $stats['views'] ) ? (int) $stats['views'] : 0;
		$reactions   = isset( $stats['reactions'] ) ? (int) $stats['reactions'] : 0;
		$comments    = isset( $stats['comments'] ) ? (int) $stats['comments'] : 0;

		// Build data attributes string.
		$data_attrs['media-id']   = $media_id;
		$data_attrs['media-type'] = self::get_media_type( $media_id );
		$data_str                 = '';
		foreach ( $data_attrs as $key => $val ) {
			$data_str .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		// Check if this is a gallery group cover.
		$media_group = MediaRepository::get( $media_id, 'media_group' );
		$group_count = 0;
		$is_gallery  = false;
		if ( $media_group ) {
			$is_gallery  = true;
			$group_count = (int) MediaRepository::get( $media_id, 'group_count_cache' );
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
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		echo '<div class="' . esc_attr( $item_class ) . '"' . $data_str // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. ' data-wp-interactive="mvs/shared-ui" '
			. $lightbox_ctx // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '>';

		// Owner actions (delete) — rendered when the viewer authored this media
		// or has site-wide manage_options. Shown on all server-rendered grids
		// (profile, group, shortcodes); CSS hides them at rest and reveals on
		// hover inside BP screens. The card-builders.js mirror of this logic
		// keeps the same markup on lazy-loaded cards.
		$viewer_id = get_current_user_id();
		$can_edit  = $viewer_id > 0 && ( $viewer_id === $author_id || user_can( $viewer_id, 'manage_options' ) );
		if ( $can_edit ) {
			echo '<div class="mvs-grid-item-actions">';
			echo '<button type="button" class="mvs-grid-item-action mvs-grid-item-action--danger mvs-media-delete-btn" data-media-id="' . esc_attr( (string) $media_id ) . '" aria-label="' . esc_attr__( 'Delete media', 'wpmediaverse' ) . '" title="' . esc_attr__( 'Delete media', 'wpmediaverse' ) . '">';
			echo '<i data-lucide="trash-2" aria-hidden="true"></i>';
			echo '</button>';
			echo '</div>';
		}

		echo '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link">';

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
			echo '<span class="mvs-grid-item-author">' . wp_kses(
				self::get_display_name( $author_id ),
				array(
					'span' => array(
						'class' => true,
						'title' => true,
					),
				)
			) . '</span>';
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

	/**
	 * Resolve the parent route for a detail/edit page so the template can render
	 * a back-link in its header.
	 *
	 * Defined by docs/MOBILE_UX_GUIDELINE.md §6.3. Pages call this with a
	 * context string and receive a `[ 'url' => ..., 'label' => ... ]` pair (or
	 * null if the parent can't be resolved — in which case the template should
	 * skip the back link instead of rendering a broken one).
	 *
	 * Pro extensions can register their own contexts via the
	 * `mvs_parent_url` filter.
	 *
	 * @param string $context Page context — one of:
	 *                        'single-media', 'album', 'edit-profile',
	 *                        'challenge', 'battle', 'tournament'.
	 * @param array  $args    Optional context-specific args (e.g. media_id).
	 * @return array{url: string, label: string}|null
	 */
	public static function get_parent_route( string $context, array $args = array() ): ?array {
		$parent = null;

		switch ( $context ) {
			case 'single-media':
				$parent = array(
					'url'   => home_url( '/media/' ),
					'label' => __( 'Explore', 'wpmediaverse' ),
				);
				break;

			case 'album':
				$author_id = isset( $args['author_id'] ) ? (int) $args['author_id'] : 0;
				if ( $author_id ) {
					$parent = array(
						'url'   => self::get_user_profile_url( $author_id ),
						'label' => __( 'Profile', 'wpmediaverse' ),
					);
				} else {
					$parent = array(
						'url'   => home_url( '/media/' ),
						'label' => __( 'Explore', 'wpmediaverse' ),
					);
				}
				break;

			case 'edit-profile':
				$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
				if ( $user_id ) {
					$parent = array(
						'url'   => self::get_user_profile_url( $user_id ),
						'label' => __( 'My profile', 'wpmediaverse' ),
					);
				}
				break;

			case 'challenge':
				$parent = array(
					'url'   => home_url( '/media/challenges/' ),
					'label' => __( 'Challenges', 'wpmediaverse' ),
				);
				break;

			case 'battle':
				$parent = array(
					'url'   => home_url( '/media/battles/' ),
					'label' => __( 'Battles', 'wpmediaverse' ),
				);
				break;

			case 'tournament':
				$parent = array(
					'url'   => home_url( '/media/tournaments/' ),
					'label' => __( 'Tournaments', 'wpmediaverse' ),
				);
				break;
		}

		/**
		 * Filter the parent route resolved for a back-link.
		 *
		 * Pro extensions register their own contexts here (e.g. 'leaderboard').
		 *
		 * @param array|null $parent  ['url' => ..., 'label' => ...] or null.
		 * @param string     $context Context string passed by the template.
		 * @param array      $args    Context args.
		 */
		return apply_filters( 'mvs_parent_route', $parent, $context, $args );
	}

	/**
	 * Render a back-link header for a detail/edit page.
	 *
	 * Echoes `<a class="mvs-back-link" ...>` per docs/MOBILE_UX_GUIDELINE.md §6.2.
	 * Silently no-ops if the parent route can't be resolved.
	 *
	 * @param string $context See get_parent_route().
	 * @param array  $args    See get_parent_route().
	 */
	public static function render_back_link( string $context, array $args = array() ): void {
		$parent = self::get_parent_route( $context, $args );
		if ( ! $parent || empty( $parent['url'] ) ) {
			return;
		}
		$label = ! empty( $parent['label'] ) ? (string) $parent['label'] : __( 'Back', 'wpmediaverse' );
		printf(
			'<a class="mvs-back-link" href="%1$s" aria-label="%2$s" data-mvs-tooltip="%2$s"><i data-lucide="arrow-left" aria-hidden="true"></i><span class="mvs-back-link__label">%3$s</span></a>',
			esc_url( $parent['url'] ),
			esc_attr( sprintf( /* translators: %s: parent page label */ __( 'Back to %s', 'wpmediaverse' ), $label ) ),
			esc_html( $label )
		);
	}
}
