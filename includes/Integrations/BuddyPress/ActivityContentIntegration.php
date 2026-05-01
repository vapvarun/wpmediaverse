<?php
/**
 * ActivityContentIntegration — activity content transformation for BuddyPress.
 *
 * Handles detection and rendering of legacy media plugin activity HTML
 * (rtMedia, MediaPress, BuddyBoss) into consistent MVS markup, plus inline
 * video player injection and thumbnail rendering for imported media.
 *
 * @package WPMediaVerse\Integrations\BuddyPress
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Transforms legacy activity content and renders media thumbnails inside
 * BuddyPress activity streams.
 */
class ActivityContentIntegration {

	/**
	 * Register hooks for activity content transformation.
	 */
	public function init(): void {
		// Enhance activity content: transform legacy plugin HTML (rtMedia/MediaPress) to MVS
		// rendering. Priority 0: runs before bp_activity_filter_kses (priority 1).
		add_filter( 'bp_get_activity_content_body', array( $this, 'enhance_activity_media_content' ), 0, 2 );

		// Inject media thumbnails into activities with empty content (imported media).
		// Uses bp_activity_entry_content ACTION (not filter) because BP Nouveau skips
		// bp_get_activity_content_body entirely when content is empty.
		add_action( 'bp_activity_entry_content', array( $this, 'render_activity_media_thumbnail' ) );

		// Inject inline video player for MVS video activities.
		add_filter( 'bp_get_activity_content_body', array( $this, 'inject_video_player_in_activity' ), 0, 2 );

		// Whitelist our MVS tags/attrs so kses (priority 1) preserves our transformed output.
		add_filter( 'bp_activity_allowed_tags', array( $this, 'allow_mvs_activity_tags' ) );
	}

	/**
	 * Whitelist MVS HTML tags and attributes in BP activity content.
	 *
	 * Hooked to bp_activity_allowed_tags so kses preserves our transformed grid markup.
	 *
	 * @param array $tags Allowed tags array.
	 * @return array Extended allowed tags.
	 */
	public function allow_mvs_activity_tags( array $tags ): array {
		// Grid container and per-item wrappers.
		$tags['div'] = array(
			'class'               => array(),
			'style'               => array(),
			'data-mvs-media-id'   => array(),
			'data-mvs-src'        => array(),
			'data-mvs-permalink'  => array(),
			'data-wp-interactive' => array(),
			'data-wp-context'     => array(),
			'data-wp-on--click'   => array(),
		);

		// Allow inline <video> player.
		$tags['video'] = array(
			'src'      => array(),
			'controls' => array(),
			'preload'  => array(),
			'poster'   => array(),
			'style'    => array(),
			'class'    => array(),
			'width'    => array(),
			'height'   => array(),
		);

		// Allow <source> inside <video>/<audio>.
		$tags['source'] = array(
			'src'  => array(),
			'type' => array(),
		);

		// Allow inline <audio> player.
		$tags['audio'] = array(
			'src'      => array(),
			'controls' => array(),
			'preload'  => array(),
			'style'    => array(),
			'class'    => array(),
		);

		// Allow class/style and Interactivity API attrs on <a> for MVS media links.
		$tags['a']['class']               = array();
		$tags['a']['style']               = array();
		$tags['a']['href']                = array();
		$tags['a']['data-mvs-permalink']  = array();
		$tags['a']['data-wp-interactive'] = array();
		$tags['a']['data-wp-context']     = array();
		$tags['a']['data-wp-on--click']   = array();
		$tags['img']['src']               = array();
		$tags['img']['alt']               = array();
		$tags['img']['width']             = array();
		$tags['img']['height']            = array();
		$tags['img']['style']             = array();
		$tags['img']['loading']           = array();
		$tags['img']['class']             = array();
		$tags['span']                     = array(
			'class'       => array(),
			'aria-hidden' => array(),
		);

		return $tags;
	}

	/**
	 * Transform legacy media plugin activity HTML (rtMedia, etc.) to MVS rendering.
	 *
	 * Hooked to bp_get_activity_content_body. Detects known legacy HTML markers,
	 * extracts media items, and rewrites the content with inline-styled MVS HTML
	 * so that images/videos render consistently with the MVS UI (including lightbox).
	 *
	 * @param string $content Raw activity content.
	 * @return string Transformed content, or original if no legacy marker found.
	 */
	public function inject_video_player_in_activity( string $content ): string {
		// Only process content that has our video placeholder markup.
		if ( false === strpos( $content, 'mvs-activity-media--video' ) ) {
			return $content;
		}

		// Extract media ID from data-mvs-media-id attribute.
		if ( ! preg_match( '/data-mvs-media-id="(\d+)"/', $content, $matches ) ) {
			return $content;
		}

		$media_id   = (int) $matches[1];
		$media_type = MediaRepository::get( $media_id, 'media_type' );

		if ( 'video' !== $media_type ) {
			return $content;
		}

		$aci_su   = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
		$file_url = $aci_su ? $aci_su->generate( $media_id, get_current_user_id() ) : '';

		if ( ! $file_url ) {
			return $content;
		}

		$permalink  = MediaRepository::get_permalink( $media_id );
		$poster     = '';
		$poster_url = $aci_su ? $aci_su->generate_thumbnail( $media_id, get_current_user_id(), 'large' ) : '';
		if ( $poster_url ) {
			$poster = ' poster="' . esc_url( $poster_url ) . '"';
		}

		$video_html = '<div class="mvs-activity-media mvs-activity-media--video" data-mvs-media-id="' . esc_attr( $media_id ) . '">'
			. '<video controls preload="metadata"' . $poster . ' style="width:100%;max-height:400px;border-radius:8px;display:block;">'
			. '<source src="' . esc_url( $file_url ) . '" type="' . esc_attr( MediaRepository::get( $media_id, 'file_type' ) ?: 'video/mp4' ) . '">'
			. '</video>'
			. '<a href="' . esc_url( $permalink ) . '" class="mvs-activity-media-link" style="display:block;text-align:center;margin-top:4px;font-size:13px;">' . esc_html__( 'View full media', 'wpmediaverse' ) . '</a>'
			. '</div>';

		// Replace the existing thumbnail/placeholder with the video player.
		$content = preg_replace( '/<div class="mvs-activity-media mvs-activity-media--video[^"]*"[^>]*>.*?<\/div>/s', $video_html, $content );

		return $content;
	}

	/**
	 * Enhance activity media content.
	 *
	 * Single unified filter for all activity media rendering:
	 * 1. Transform rtMedia legacy HTML to MVS rendering (when rtMedia deactivated)
	 * 2. Transform MediaPress activities via _mpp_attached_media_id meta lookup
	 * 3. Inject thumbnails for imported/empty mvs_media_upload activities
	 *
	 * @param string      $content  Raw activity content.
	 * @param object|null $activity BP activity object (passed by ref from BP).
	 * @return string Enhanced content.
	 */
	public function enhance_activity_media_content( string $content, $activity = null ): string {
		// Activity already has MVS media markup. Refresh the URLs in place
		// before returning — saved markup may carry expired signed URLs
		// (the `mvs_signed_url_ttl` default is 1h, but activity HTML lives
		// in bp_activity.content for months). Without this, every activity
		// older than 1h renders broken images. New activities (post-fix)
		// store YEAR_IN_SECONDS URLs via MediaRepository::get_broadcast_url(); this
		// pass keeps already-saved short-TTL URLs working for legacy data.
		if ( strpos( $content, 'mvs-activity-media' ) !== false ) {
			return $this->refresh_broadcast_urls( $content );
		}

		// --- 1. rtMedia legacy transform ---
		if ( strpos( $content, 'rtmedia-activity-container' ) !== false ) {
			return $this->transform_rtmedia_content( $content );
		}

		// Resolve activity object.
		if ( ! is_object( $activity ) || empty( $activity->id ) ) {
			global $activities_template;
			$activity = ! empty( $activities_template->activity ) ? $activities_template->activity : null;
		}
		if ( ! $activity || empty( $activity->id ) ) {
			return $content;
		}

		// --- 2. MediaPress activity transform ---
		if ( ! function_exists( 'mediapress' ) && ! class_exists( 'MediaPress' ) ) {
			$mpp_media_id = bp_activity_get_meta( (int) $activity->id, '_mpp_attached_media_id', true );
			if ( $mpp_media_id ) {
				$thumbnail = $this->resolve_imported_thumbnail( (int) $mpp_media_id, '_mvs_mpp_id', '_mvs_attachment_id' );
				if ( $thumbnail ) {
					return $content . $thumbnail;
				}
			}
		}

		// --- 3. BuddyBoss activity transform (bp_media_ids meta) ---
		if ( ! function_exists( 'buddyboss_platform_plugin_basename' ) ) {
			$bb_media_ids = bp_activity_get_meta( (int) $activity->id, 'bp_media_ids', true );
			if ( $bb_media_ids ) {
				$bb_ids    = array_filter( array_map( 'intval', explode( ',', $bb_media_ids ) ) );
				$grid_html = '';
				foreach ( $bb_ids as $bb_id ) {
					$thumbnail = $this->resolve_imported_thumbnail( $bb_id, '_mvs_bb_media_id', '_mvs_attachment_id' );
					if ( $thumbnail ) {
						$grid_html .= $thumbnail;
					}
				}
				if ( $grid_html ) {
					$count      = count( $bb_ids );
					$grid_class = 'mvs-activity-media-grid mvs-activity-grid-' . min( $count, 6 );
					return $content . '<div class="' . esc_attr( $grid_class ) . '">' . $grid_html . '</div>';
				}
			}
		}

		// --- 4. Imported/empty mvs_media_upload thumbnail injection ---
		if ( 'mvs_media_upload' === ( $activity->type ?? '' ) && strlen( trim( wp_strip_all_tags( $content ) ) ) <= 10 ) {
			$media_id = 0;
			if ( 'wpmediaverse' === $activity->component && $activity->item_id > 0 ) {
				$media_id = (int) $activity->item_id;
			} elseif ( 'groups' === $activity->component && $activity->secondary_item_id > 0 ) {
				$media_id = (int) $activity->secondary_item_id;
			}
			if ( $media_id && MediaRepository::exists( $media_id ) ) {
				$thumbnail = MediaDisplayHelper::get_media_thumbnail_html( $media_id, 'large' );
				if ( $thumbnail ) {
					return $content . $thumbnail;
				}
			}
		}

		// --- 5. Recover activities whose saved content lost its media markup ---
		// Activities posted via the BP composer on 1.1.2 baked thumbnail HTML
		// into `content` at post time. If thumb_* meta was missing then, the
		// saved markup has empty `<img src="">`. When that happens the markup
		// check at the top of this method will NOT match, so we rebuild the
		// grid from the `_mvs_media_ids` activity meta on the fly.
		$saved_ids = bp_activity_get_meta( (int) $activity->id, '_mvs_media_ids', true );
		if ( $saved_ids ) {
			$ids       = array_filter( array_map( 'absint', explode( ',', (string) $saved_ids ) ) );
			$grid_html = '';
			foreach ( $ids as $mid ) {
				if ( ! MediaRepository::exists( $mid ) ) {
					continue;
				}
				$grid_html .= MediaDisplayHelper::get_media_thumbnail_html( $mid, 'large' );
			}
			if ( $grid_html ) {
				$count      = count( $ids );
				$grid_class = 'mvs-activity-media-grid mvs-activity-grid-' . min( $count, 6 );
				return $content . '<div class="' . esc_attr( $grid_class ) . '">' . $grid_html . '</div>';
			}
		}

		return $content;
	}

	/**
	 * Resolve an imported media thumbnail from a source plugin attachment ID.
	 *
	 * Looks up the MVS media item by the source meta key, then generates thumbnail HTML.
	 *
	 * @param int    $source_id       Original attachment/media ID from the source plugin.
	 * @param string $primary_meta    Primary meta key to search (e.g. _mvs_mpp_id).
	 * @param string $fallback_meta   Fallback meta key (e.g. _mvs_attachment_id).
	 * @return string Thumbnail HTML or empty string.
	 */
	private function resolve_imported_thumbnail( int $source_id, string $primary_meta, string $fallback_meta ): string {
		// Strip _mvs_ prefix to get the custom table key.
		$primary_key  = preg_replace( '/^_mvs_/', '', $primary_meta );
		$fallback_key = preg_replace( '/^_mvs_/', '', $fallback_meta );

		$mvs_id = $this->find_media_by_meta_key( $primary_key, (string) $source_id );

		if ( ! $mvs_id ) {
			$mvs_id = $this->find_media_by_meta_key( $fallback_key, (string) $source_id );
		}

		if ( ! $mvs_id ) {
			return '';
		}

		return MediaDisplayHelper::get_media_thumbnail_html( $mvs_id, 'large' );
	}

	/**
	 * Find a media ID by a key/value in custom tables.
	 *
	 * Checks mvs_media_index first (for core columns like attachment_id),
	 * then mvs_media_meta (for sparse keys like mpp_id, bb_media_id).
	 *
	 * @param string $key   Meta key (without _mvs_ prefix).
	 * @param string $value Meta value to match.
	 * @return int Media ID, or 0 if not found.
	 */
	private function find_media_by_meta_key( string $key, string $value ): int {
		global $wpdb;

		// Check if this is an index column.
		$index_columns = array( 'media_type', 'privacy', 'file_url', 'file_type', 'file_size', 'moderation_status', 'post_author' );

		if ( in_array( $key, $index_columns, true ) ) {
			$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE `{$key}` = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$value
				)
			);
			return $result ? (int) $result : 0;
		}

		// Otherwise check mvs_media_meta.
		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_meta WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$key,
				$value
			)
		);

		return $result ? (int) $result : 0;
	}

	/**
	 * Transform rtMedia legacy activity HTML to MVS rendering.
	 *
	 * Extracts media items from rtMedia's container markup and rewrites as
	 * inline-styled MVS HTML for consistent rendering after rtMedia deactivation.
	 *
	 * @param string $content Activity content with rtMedia HTML.
	 * @return string Transformed content.
	 */
	private function transform_rtmedia_content( string $content ): string {
		// Only process content that has rtMedia's container class.
		if ( strpos( $content, 'rtmedia-activity-container' ) === false ) {
			return $content;
		}

		// Extract optional user text from rtMedia's text block.
		$text = '';
		if ( preg_match( '/<div[^>]+class="[^"]*rtmedia-activity-text[^"]*"[^>]*>.*?<span>(.*?)<\/span>/s', $content, $m ) ) {
			$text = trim( wp_strip_all_tags( $m[1] ) );
		}

		// Extract each <li class="rtmedia-list-item"> block.
		$media_html = '';
		$count      = 0;

		preg_match_all( '/<li[^>]+class="[^"]*rtmedia-list-item[^"]*"[^>]*>(.*?)<\/li>/s', $content, $items );

		foreach ( $items[1] as $item_html ) {
			$is_video = strpos( $item_html, 'media-type-video' ) !== false
						|| strpos( $item_html, '<video' ) !== false;
			$is_audio = strpos( $item_html, 'media-type-music' ) !== false
					|| strpos( $item_html, '<audio' ) !== false;

			// Primary link href (rtMedia's detail page — may 404 after deactivation).
			$href = '';
			if ( preg_match( '/href="([^"]+)"/', $item_html, $hm ) ) {
				$href = esc_url( $hm[1] );
			}

			if ( $is_video ) {
				// Get direct video src from <video src="..."> for deactivation-safe href.
				$src = '';
				if ( preg_match( '/<video[^>]+src="([^"]+)"/', $item_html, $sm ) ) {
					$src = esc_url( $sm[1] );
				}
				$title = '';
				if ( preg_match( '/title="([^"]+)"/', $item_html, $tm ) ) {
					$title = esc_html( $tm[1] );
				}
				$mvs_id = $src ? $this->get_mvs_id_from_file_url( $src ) : 0;
				if ( $mvs_id ) {
					$signed_video = (string) MediaRepository::get( $mvs_id, 'file_url' );
					if ( '' !== $signed_video ) {
						$src = $signed_video;
					}
				}
				$link     = $src ?: $href;
				$data_mid = $mvs_id ? ' data-mvs-media-id="' . $mvs_id . '"' : '';
				$data_src = $link ? ' data-mvs-src="' . esc_attr( $link ) . '"' : '';

				$lightbox_attrs = '';
				if ( $mvs_id ) {
					$lightbox_attrs = ' data-wp-interactive="mvs/shared-ui"'
						. ' data-wp-context=\'{"mediaId":' . $mvs_id . '}\''
						. ' data-wp-on--click="actions.openLightbox"';
				}

				$media_html .= '<div class="mvs-activity-media mvs-activity-media--video mvs-activity-media--placeholder"' . $data_mid . $data_src . '>'
							. '<a href="' . esc_url( $link ) . '" class="mvs-activity-vid-link"' . $lightbox_attrs . '>'
							. '<span class="mvs-activity-play-icon" aria-hidden="true"></span>'
							. ( $title ? '<span class="mvs-activity-media-label">' . $title . '</span>' : '' )
							. '</a></div>';

			} elseif ( $is_audio ) {
				// Extract direct audio file URL from <audio src="..."> — deactivation-safe.
				$src = '';
				if ( preg_match( '/<audio[^>]+src="([^"]+)"/', $item_html, $sm ) ) {
					$src = esc_url( $sm[1] );
				}
				$title = '';
				if ( preg_match( '/title="([^"]+)"/', $item_html, $tm ) ) {
					$title = esc_html( $tm[1] );
				}
				$mvs_id = $src ? $this->get_mvs_id_from_file_url( $src ) : 0;
				if ( $mvs_id ) {
					$signed_audio = (string) MediaRepository::get( $mvs_id, 'file_url' );
					if ( '' !== $signed_audio ) {
						$src = $signed_audio;
					}
				}
				$link     = $src ?: $href;
				$data_mid = $mvs_id ? ' data-mvs-media-id="' . $mvs_id . '"' : '';
				$data_src = $src ? ' data-mvs-src="' . esc_attr( $src ) . '"' : '';

				$lightbox_attrs = '';
				if ( $mvs_id ) {
					$lightbox_attrs = ' data-wp-interactive="mvs/shared-ui"'
						. ' data-wp-context=\'{"mediaId":' . $mvs_id . '}\''
						. ' data-wp-on--click="actions.openLightbox"';
				}

				$media_html .= '<div class="mvs-activity-media mvs-activity-media--audio"' . $data_mid . $data_src . ' style="border-radius:12px;">'
							. '<a href="' . esc_url( $link ) . '" style="display:flex;align-items:center;gap:8px;text-decoration:none;color:inherit;"' . $lightbox_attrs . '>'
							. '<span style="flex-shrink:0;display:inline-flex;">' . TemplateHelpers::icon_music_svg() . '</span>'
							. '<span style="min-width:0;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . $title . '</span>'
							. '</a></div>';

			} else {
				// Image: extract <img src> and alt.
				$src = '';
				$alt = '';
				if ( preg_match( '/<img[^>]+src="([^"]+)"/', $item_html, $im ) ) {
					$src = esc_url( $im[1] );
				}
				if ( preg_match( '/<img[^>]+alt="([^"]+)"/', $item_html, $am ) ) {
					$alt = esc_attr( $am[1] );
				}

				if ( $src ) {
					// Resolve MVS media ID — use permalink for link (same as regular uploads).
					$mvs_id   = $this->get_mvs_id_from_file_url( $src );
					$data_mid = $mvs_id ? ' data-mvs-media-id="' . $mvs_id . '"' : '';
					$full_src = preg_replace( '/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $src );
					$link     = $mvs_id ? MediaRepository::get_permalink( $mvs_id ) : ( $full_src ?: $src );

					// Sign protected MVS URLs; pass through avatars / theme images / external URLs unchanged.
					$img_src = $full_src ?: $src;
					if ( $mvs_id ) {
						$signed_full = (string) MediaRepository::get( $mvs_id, 'file_url' );
						if ( '' !== $signed_full ) {
							$img_src = $signed_full;
						}
					}

					// Output same format as regular MVS media upload activity.
					$media_html .= '<div class="mvs-activity-media mvs-activity-media--image"' . $data_mid . '>'
								. '<a href="' . esc_url( $link ) . '">'
								. '<img src="' . esc_url( $img_src ) . '" alt="' . $alt . '" loading="lazy" />'
								. '</a></div>';
				}
			}

			++$count;
		}

		if ( ! $media_html ) {
			return $content; // Parsing failed — return original safely.
		}

		$grid_class = 'mvs-activity-media-grid mvs-activity-grid-' . min( $count, 6 );
		$output     = '';
		if ( $text ) {
			$output .= '<p>' . esc_html( $text ) . '</p>';
		}
		$output .= '<div class="' . esc_attr( $grid_class ) . '">' . $media_html . '</div>';

		return $output;
	}

	/**
	 * Render media thumbnail into activity entries with empty content.
	 *
	 * Hooked to `bp_activity_entry_content` (action, not filter) because BP Nouveau
	 * skips `bp_get_activity_content_body` entirely when activity content is empty.
	 * This method outputs the thumbnail AND persists it to the DB so the filter
	 * is not needed on subsequent renders.
	 *
	 * Handles imported media from all 3 source plugins:
	 * - rtMedia (via _mvs_rtmedia_id on the media post)
	 * - MediaPress (via _mpp_attached_media_id activity meta)
	 * - BuddyBoss (via _mvs_bb_media_id on the media post)
	 *
	 * @since 1.1.0
	 */
	public function render_activity_media_thumbnail(): void {
		global $activities_template;

		if ( empty( $activities_template->activity ) ) {
			return;
		}

		$activity = $activities_template->activity;

		// Only for mvs_media_upload activities with empty content.
		if ( 'mvs_media_upload' !== ( $activity->type ?? '' ) ) {
			return;
		}
		if ( ! empty( trim( $activity->content ?? '' ) ) ) {
			return;
		}

		// Resolve media ID from activity.
		$media_id = 0;
		if ( 'wpmediaverse' === $activity->component && $activity->item_id > 0 ) {
			$media_id = (int) $activity->item_id;
		} elseif ( 'groups' === $activity->component && $activity->secondary_item_id > 0 ) {
			$media_id = (int) $activity->secondary_item_id;
		}

		if ( ! $media_id || ! MediaRepository::exists( $media_id ) ) {
			return;
		}

		$thumbnail = MediaDisplayHelper::get_media_thumbnail_html( $media_id, 'large' );
		if ( ! $thumbnail ) {
			return;
		}

		// Output the thumbnail (display-time only — does NOT write to DB).
		// To persist thumbnails into activity content permanently, run:
		// wp mvs backfill-activity-thumbnails
		// That command warns the site owner and requires explicit confirmation.
		echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped in get_media_thumbnail_html.
	}

	/**
	 * Backwards compatibility wrapper.
	 *
	 * @param string $content Content.
	 * @return string Content.
	 */
	public function transform_legacy_media_content( string $content ): string {
		return $this->enhance_activity_media_content( $content );
	}

	/**
	 * @deprecated Consolidated into enhance_activity_media_content().
	 */
	public function transform_mediapress_activity( string $content, $activity = null ): string {
		// Only process if MediaPress is NOT active (if active, let MediaPress handle its own rendering).
		if ( function_exists( 'mediapress' ) || class_exists( 'MediaPress' ) ) {
			return $content;
		}

		$activity_id = 0;
		if ( is_object( $activity ) && ! empty( $activity->id ) ) {
			$activity_id = (int) $activity->id;
		} elseif ( function_exists( 'bp_get_activity_id' ) ) {
			$activity_id = bp_get_activity_id();
		}
		if ( ! $activity_id ) {
			return $content;
		}

		// Check for MediaPress activity meta.
		$mpp_media_id = bp_activity_get_meta( $activity_id, '_mpp_attached_media_id', true );
		if ( ! $mpp_media_id ) {
			return $content;
		}

		// Find the imported MVS media that came from this MediaPress attachment.
		$mvs_id = $this->find_media_by_meta_key( 'mpp_id', (string) $mpp_media_id );

		if ( ! $mvs_id ) {
			// Try finding by the WP attachment ID — Pro importers store the
			// source post's attachment ID as `wp_attachment_id` meta on the
			// imported MVS record (see MigrationPage::import_mediapress).
			$mvs_id = $this->find_media_by_meta_key( 'wp_attachment_id', (string) $mpp_media_id );
		}

		if ( ! $mvs_id ) {
			return $content;
		}
		$thumbnail = MediaDisplayHelper::get_media_thumbnail_html( $mvs_id, 'large' );

		if ( ! $thumbnail ) {
			return $content;
		}

		// Append the thumbnail after existing content text.
		return $content . $thumbnail;
	}

	/**
	 * Inject thumbnail into imported media activities that currently show text-only.
	 *
	 * When media is imported via WP-CLI, the _mvs_imported_media flag skips the normal
	 * record_upload_activity() which sets thumbnail as content. This method retroactively
	 * adds the thumbnail for any mvs_media_upload activity that has empty content.
	 *
	 * @param string $content Activity content.
	 * @return string Content with thumbnail injected.
	 */
	public function inject_imported_media_thumbnail( string $content, $activity = null ): string {
		// Only process empty/minimal content.
		if ( strlen( trim( wp_strip_all_tags( $content ) ) ) > 10 ) {
			return $content;
		}

		// Already has media markup.
		if ( strpos( $content, 'mvs-activity-media' ) !== false ) {
			return $content;
		}

		// Get activity from param or global.
		if ( ! is_object( $activity ) || empty( $activity->type ) ) {
			global $activities_template;
			$activity = ! empty( $activities_template->activity ) ? $activities_template->activity : null;
		}
		if ( ! $activity || empty( $activity->type ) ) {
			return $content;
		}

		// Only for mvs_media_upload activities.
		if ( 'mvs_media_upload' !== $activity->type ) {
			return $content;
		}

		// The media ID is in item_id (profile uploads) or secondary_item_id (group uploads).
		$media_id = 0;
		if ( 'wpmediaverse' === $activity->component && $activity->item_id > 0 ) {
			$media_id = (int) $activity->item_id;
		} elseif ( 'groups' === $activity->component && $activity->secondary_item_id > 0 ) {
			$media_id = (int) $activity->secondary_item_id;
		}

		if ( ! $media_id || ! MediaRepository::exists( $media_id ) ) {
			return $content;
		}

		$thumbnail = MediaDisplayHelper::get_media_thumbnail_html( $media_id, 'large' );
		if ( ! $thumbnail ) {
			return $content;
		}

		return $content . $thumbnail;
	}

	/**
	 * Re-sign URLs inside already-rendered `mvs-activity-media` markup.
	 *
	 * Every `<div class="mvs-activity-media..." data-mvs-media-id="N">` block
	 * carries its media ID in markup. We use that ID to mint a fresh
	 * broadcast-TTL signed URL for the inner `<img src>` (and `<source src>`
	 * for video) and the outer `<a href>` when the href points into the
	 * gated uploads directory. Activities that are months old keep working.
	 *
	 * Idempotent: re-running this on already-fresh URLs is a no-op.
	 *
	 * @param string $content Activity HTML.
	 * @return string Refreshed HTML.
	 */
	private function refresh_broadcast_urls( string $content ): string {
		// Match every mvs-activity-media block — non-greedy, captures the
		// data-mvs-media-id and the full block body so we can rewrite URLs
		// inside without disturbing surrounding markup.
		return (string) preg_replace_callback(
			'~(<div\s+class="mvs-activity-media[^"]*"[^>]*\bdata-mvs-media-id="(\d+)"[^>]*>)(.*?)(</div>)~is',
			static function ( $m ) {
				$open     = $m[1];
				$media_id = (int) $m[2];
				$body     = $m[3];
				$close    = $m[4];

				if ( $media_id <= 0 ) {
					return $m[0];
				}

				$file_url  = MediaRepository::get_broadcast_url( $media_id );
				$thumb_url = MediaRepository::get_broadcast_thumbnail_url( $media_id, 'large' );

				// Refresh outer <a href="..."> when the href targets the gated
				// uploads dir. Permalinks (which point to the public single
				// view) are left alone — they don't 403.
				if ( $file_url ) {
					$body = preg_replace_callback(
						'~(<a\s+[^>]*href=")([^"]*?/wp-content/uploads/wpmediaverse/[^"]*?|[^"]*?[?&]mvs_serve=1[^"]*?)(")~i',
						static function ( $a ) use ( $file_url ) {
							return $a[1] . esc_url( $file_url ) . $a[3];
						},
						$body
					);
				}

				// Refresh inner <img src="...">.
				if ( $thumb_url ) {
					$body = preg_replace_callback(
						'~(<img\s+[^>]*src=")([^"]+)(")~i',
						static function ( $i ) use ( $thumb_url ) {
							return $i[1] . esc_url( $thumb_url ) . $i[3];
						},
						$body
					);
				}

				// Refresh inner <source src="..."> and <video src="...">.
				if ( $file_url ) {
					$body = preg_replace_callback(
						'~(<(?:source|video|audio)\s+[^>]*src=")([^"]+)(")~i',
						static function ( $s ) use ( $file_url ) {
							return $s[1] . esc_url( $file_url ) . $s[3];
						},
						$body
					);
				}

				return $open . $body . $close;
			},
			$content
		);
	}

	/**
	 * Find an MVS media post ID from a file URL (used to attach media IDs to transformed activity HTML).
	 *
	 * Looks up the WP attachment by URL (stripping thumbnail size suffixes),
	 * then finds the media item that references that attachment.
	 *
	 * @param string $url File or thumbnail URL.
	 * @return int MVS post ID, or 0 if not found.
	 */
	private function get_mvs_id_from_file_url( string $url ): int {
		global $wpdb;

		// Strip thumbnail size suffix (e.g. -320x240.png → .png).
		$clean = preg_replace( '/-\d+x\d+(\.[a-zA-Z]+)$/', '$1', $url );

		// 1. Direct lookup in mvs_media_index by file_url (handles imported media).
		$media_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE file_url = %s OR file_url = %s LIMIT 1",
				$clean,
				set_url_scheme( $clean )
			)
		);
		if ( $media_id ) {
			return $media_id;
		}

		// 2. Fallback: resolve WP attachment ID → find via wp_attachment_id meta.
		$attach_id = attachment_url_to_postid( $clean );
		if ( ! $attach_id && $clean !== $url ) {
			$attach_id = attachment_url_to_postid( $url );
		}
		if ( $attach_id ) {
			$found = $this->find_media_by_meta_key( 'wp_attachment_id', (string) $attach_id );
			if ( $found ) {
				return $found;
			}
		}

		return 0;
	}
}
