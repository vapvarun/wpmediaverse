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
	 * Resolve the lightbox image URL, honoring the admin `mvs_lightbox_image_source` setting.
	 *
	 * Returns the original file URL, a specific thumbnail size, or an auto-chosen URL
	 * depending on the setting. Falls back to file_url when the requested size is missing
	 * so the lightbox never shows a smaller-than-expected image.
	 *
	 * @param int    $media_id      Media ID.
	 * @param string $file_url      Original file URL (may be empty).
	 * @return string Lightbox image URL (empty string for video/audio).
	 */
	public static function get_lightbox_url( int $media_id, string $file_url = '' ): string {
		$media_type = self::get_media_type( $media_id );
		if ( 'image' !== $media_type ) {
			return '';
		}

		if ( '' === $file_url ) {
			$file_url = (string) MediaRepository::get( $media_id, 'file_url' );
		}

		$source = (string) get_option( 'mvs_lightbox_image_source', 'original' );

		if ( 'auto' === $source ) {
			$source = wp_is_mobile() ? 'large' : 'original';
		}

		if ( 'original' === $source && $file_url ) {
			return set_url_scheme( $file_url );
		}

		$size_url = self::get_thumb_url( $media_id, $source );
		if ( $size_url ) {
			return $size_url;
		}

		return $file_url ? set_url_scheme( $file_url ) : '';
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
	 * Canonical media-thumbnail renderer — single source of truth for every
	 * MVS surface (Explore grid, BP activity, album/collection, Pro premium
	 * layouts, single-media page, block templates).
	 *
	 * Always returns **inner** markup the caller wraps in its own `<a>`/link.
	 * Output is one of:
	 *   - `<img>` for media with a thumbnail
	 *   - `<video preload="metadata">` for videos without a thumbnail (the
	 *     browser paints the first frame — matches the single-media view)
	 *   - an audio card for audio
	 *   - a generic dashicon placeholder as last resort
	 *
	 * Use this instead of building ad-hoc `<img>` tags in templates so every
	 * surface handles poster-less videos the same way.
	 *
	 * @since 1.1.3
	 *
	 * @param int   $media_id Media ID (mvs_media_index.media_id).
	 * @param array $args     Options:
	 *                        - 'size'         string WP image size (default 'large').
	 *                        - 'alt'          string Override alt text.
	 *                        - 'classes'      string Extra classes for the <img>/<video>.
	 *                        - 'show_play'    bool   Append play-icon overlay for videos (default true).
	 *                        - 'lazy'         bool   Add loading="lazy" on <img> (default true).
	 * @return string Inner HTML ready for echo.
	 */
	public static function media_thumbnail( int $media_id, array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				'size'      => 'large',
				'alt'       => '',
				'classes'   => '',
				'show_play' => true,
				'lazy'      => true,
			)
		);

		$media_type = self::get_media_type( $media_id );
		$thumb_url  = self::get_thumb_url( $media_id, (string) $args['size'] );
		$alt        = (string) $args['alt'];
		if ( '' === $alt ) {
			$alt = (string) MediaRepository::get( $media_id, 'title' );
		}

		$extra_class = trim( (string) $args['classes'] );
		$loading     = $args['lazy'] ? ' loading="lazy"' : '';
		$play_icon   = $args['show_play'] ? self::icon_play() : '';

		if ( $thumb_url ) {
			$img_class = trim( 'mvs-media-thumb ' . $extra_class );
			$markup    = '<img class="' . esc_attr( $img_class ) . '" src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $alt ) . '"' . $loading . ' />';
			if ( 'video' === $media_type ) {
				$markup .= $play_icon;
			}
			return $markup;
		}

		if ( 'video' === $media_type ) {
			$file_url = (string) MediaRepository::get( $media_id, 'file_url' );
			if ( $file_url ) {
				$vid_class = trim( 'mvs-grid-video-preview ' . $extra_class );
				return '<video class="' . esc_attr( $vid_class ) . '" preload="metadata" muted playsinline disablepictureinpicture aria-hidden="true" src="' . esc_url( $file_url ) . '#t=0.1"></video>' . $play_icon;
			}
			return '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video">' . $play_icon . '</div>';
		}

		if ( 'audio' === $media_type ) {
			return '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio">' . self::icon_music() . '</div>';
		}

		return '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--generic">' . self::icon_image() . '</div>';
	}

	/**
	 * Lucide-style "play" SVG — inner SVG markup only (no wrapping span).
	 *
	 * Callers that already own a `<span class="mvs-grid-play-icon">` (the
	 * dashboard Interactivity template uses one for `data-wp-bind--hidden`)
	 * should use `icon_play_svg()` and wrap it themselves. Callers that just
	 * need a complete play icon (the thumbnail helper below) use `icon_play()`.
	 */
	public static function icon_play_svg(): string {
		// Path is a triangle with vertices (8,5), (20,12), (8,19) — centroid
		// is exactly (12,12), the center of the 24x24 viewBox. This keeps the
		// play icon visually centered in its circular background without
		// needing CSS margin hacks.
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="none" focusable="false" aria-hidden="true"><path d="M8 5l12 7-12 7z"/></svg>';
	}

	/**
	 * Lucide-style "music" SVG — inner SVG markup only (no wrapping span).
	 */
	public static function icon_music_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
	}

	/**
	 * Lucide-style "image" SVG — inner SVG markup only (no wrapping span).
	 */
	public static function icon_image_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
	}

	/**
	 * Lucide-style "image-plus" SVG — rendered inline so the icon never
	 * depends on Lucide JS hydration. Used on the BP activity attach-media
	 * button which is sometimes wiped by BP Nouveau's Backbone re-render
	 * before the MutationObserver re-hydrates (card #8).
	 */
	public static function icon_image_plus_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/><line x1="16" x2="22" y1="5" y2="5"/><line x1="19" x2="19" y1="2" y2="8"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';
	}

	/**
	 * Full play icon with the standard wrapper span.
	 */
	private static function icon_play(): string {
		return '<span class="mvs-grid-play-icon" aria-hidden="true">' . self::icon_play_svg() . '</span>';
	}

	/**
	 * Full music icon with the standard wrapper span.
	 */
	private static function icon_music(): string {
		return '<span class="mvs-grid-audio-icon" aria-hidden="true">' . self::icon_music_svg() . '</span>';
	}

	/**
	 * Full generic-image icon with the standard wrapper span.
	 */
	private static function icon_image(): string {
		return '<span class="mvs-grid-generic-icon" aria-hidden="true">' . self::icon_image_svg() . '</span>';
	}

	/**
	 * Thin wrapper around media_thumbnail() kept for backward compatibility
	 * with callers that used to pass positional args.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     WP image size.
	 * @param string $alt      Alt text.
	 */
	public static function render_grid_thumbnail( int $media_id, string $size = 'large', string $alt = '' ): void {
		echo self::media_thumbnail( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper escapes internally.
			$media_id,
			array(
				'size' => $size,
				'alt'  => $alt,
			)
		);
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
		$show_actions = $options['show_actions'] ?? false;
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

		// Owner actions (delete) — rendered only when the caller explicitly
		// opts in via `show_actions`. Delete belongs on the user's own
		// BuddyPress "My Media" tab and group media tabs, not on public grids
		// like Explore, Albums, or Collections. Styling is scoped to
		// `#buddypress .mvs-bp-screen` so the button has no default styling
		// outside BP — rendering it on public grids would surface a bare
		// browser-default button. Additionally the viewer must own the media
		// or hold `manage_options`.
		$viewer_id = get_current_user_id();
		$can_edit  = $viewer_id > 0 && ( $viewer_id === $author_id || user_can( $viewer_id, 'manage_options' ) );
		if ( $show_actions && $can_edit ) {
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
						'class'      => true,
						'title'      => true,
						'aria-label' => true,
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
