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


/**
 * Instance helper methods for template rendering.
 *
 * Resolved via the service container under the `template_helpers` key.
 * Pro callers obtain the instance through `Plugin::free_service('template_helpers')`.
 * Phase 1b of the 1.2.0 refactor flipped this from static utilities to an
 * instance-methods class implementing TemplateHelpersInterface so the
 * implementation can evolve (caching, instrumentation, decorator wrappers)
 * without touching call sites.
 */
class TemplateHelpers implements TemplateHelpersInterface {

	/**
	 * Resolve the best thumbnail URL for a media item.
	 *
	 * Priority: custom thumbnail meta (thumb_large/thumb_medium/thumb_thumb) >
	 * fallback to larger sizes > file_url (images only).
	 *
	 * @param int    $media_id Media ID (mvs_media_index.media_id).
	 * @param string $size     WordPress image size.
	 * @param int    $ttl      Optional TTL override in seconds. 0 = use the
	 *                         `mvs_signed_url_ttl` option default (typically 1h).
	 *                         Pass `YEAR_IN_SECONDS` for "broadcast" surfaces
	 *                         (BP activity feed, notification emails) where the
	 *                         URL is baked into HTML read days/months later.
	 * @param int    $user_id  Optional user_id override. Default
	 *                         `get_current_user_id()`. Pass `0` for broadcast
	 *                         surfaces — privacy is still enforced at sign
	 *                         time so private media silently returns ''.
	 * @return string Thumbnail URL or empty string.
	 */
	public function get_thumb_url( int $media_id, string $size = 'large', int $ttl = 0, ?int $user_id = null ): string {
		// Single read-side facade — see `Core\MediaUrl` for the privacy /
		// skip-privacy / broadcast-surface logic and the choke-point comment
		// referenced at the top of this method. Direct uploads URLs are
		// blocked by the .htaccess in wp-content/uploads/wpmediaverse/, so
		// every consumer of this helper gets a signed `/serve` URL.
		return \WPMediaVerse\Core\MediaUrl::thumb( $media_id, $size, $ttl, $user_id );
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
	public function get_lightbox_url( int $media_id, string $file_url = '' ): string {
		$media_type = $this->get_media_type( $media_id );
		if ( 'image' !== $media_type ) {
			return '';
		}

		// Every return path routes through MediaUrl so the .htaccess deny-all
		// in /wp-content/uploads/wpmediaverse/ doesn't 403 the lightbox image.
		// $file_url parameter is ignored — the helper resolves the right URL
		// from $media_id alone, signed.
		$source = (string) get_option( 'mvs_lightbox_image_source', 'original' );
		if ( 'auto' === $source ) {
			$source = wp_is_mobile() ? 'large' : 'original';
		}

		// Original = signed full-file URL. Otherwise = signed sized thumbnail.
		if ( 'original' === $source ) {
			$signed = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' );
			if ( '' !== $signed ) {
				return $signed;
			}
		}

		// Sized lightbox: try the requested size, fall back to large, fall
		// back to full file.
		$size_url = $this->get_thumb_url( $media_id, $source );
		if ( $size_url ) {
			return $size_url;
		}
		return (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' );
	}

	/**
	 * Resolve the WebP sibling for the lightbox image, matching the size
	 * choice made by get_lightbox_url(). Returns '' when no WebP variant
	 * exists or when the JPEG URL routes through the gated /serve endpoint.
	 *
	 * Used by the REST media payload so the Interactivity-API lightbox can
	 * bind a `<picture><source>` element and serve WebP to capable browsers
	 * without changing the canonical fallback URL.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $media_id     Media ID.
	 * @param string $lightbox_url The resolved JPEG/PNG lightbox URL.
	 * @return string WebP variant URL or empty string.
	 */
	public function get_lightbox_webp_url( int $media_id, string $lightbox_url ): string {
		if ( $media_id <= 0 || '' === $lightbox_url ) {
			return '';
		}
		if ( 'image' !== $this->get_media_type( $media_id ) ) {
			return '';
		}

		$source = (string) get_option( 'mvs_lightbox_image_source', 'original' );
		if ( 'auto' === $source ) {
			$source = wp_is_mobile() ? 'large' : 'original';
		}

		// Map the lightbox source choice to the size key understood by
		// get_webp_variant_url(). 'original' → '' (full), everything else
		// passes through as-is (large/medium/thumb).
		$size = ( 'original' === $source ) ? '' : $source;

		return $this->get_webp_variant_url( $media_id, $size, $lightbox_url );
	}

	/**
	 * Resolve the AVIF sibling for the lightbox image, matching the size choice
	 * made by `get_lightbox_url()`. Returns '' when no AVIF variant exists or
	 * when the JPEG URL routes through the gated /serve endpoint.
	 *
	 * Used by the REST media payload so the Interactivity-API lightbox can
	 * bind an `<source type="image/avif">` element above the WebP source.
	 * AVIF-capable browsers will pick this and skip the WebP/JPEG fallback.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $media_id     Media ID.
	 * @param string $lightbox_url The resolved JPEG/PNG lightbox URL.
	 * @return string AVIF variant URL or empty string.
	 */
	public function get_lightbox_avif_url( int $media_id, string $lightbox_url ): string {
		if ( $media_id <= 0 || '' === $lightbox_url ) {
			return '';
		}
		if ( 'image' !== $this->get_media_type( $media_id ) ) {
			return '';
		}

		$source = (string) get_option( 'mvs_lightbox_image_source', 'original' );
		if ( 'auto' === $source ) {
			$source = wp_is_mobile() ? 'large' : 'original';
		}

		$size = ( 'original' === $source ) ? '' : $source;

		return $this->get_avif_variant_url( $media_id, $size, $lightbox_url );
	}

	/**
	 * Get the media type (image, video, audio, document) for a media item.
	 *
	 * @param int $media_id Media ID (mvs_media_index.media_id).
	 * @return string One of: image, video, audio, document.
	 */
	public function get_media_type( int $media_id ): string {
		$media_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_type' );
		if ( $media_type ) {
			return $media_type;
		}

		// Fallback: derive from MIME type.
		$file_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
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
	public function get_user_profile_url( int $user_id ): string {
		// Standalone default: the plugin's own @username profile route. Core is
		// platform-agnostic — no bp_* calls here. Social platforms (BuddyPress,
		// BuddyNext) override this via the filter below; the BuddyPress
		// integration's ProfileTabIntegration::filter_user_profile_url() returns
		// the BP member URL when BP is active.
		$user_login = get_the_author_meta( 'user_login', $user_id );
		$url        = home_url( '/media/@' . $user_login . '/' );

		/**
		 * Filter the user profile URL.
		 *
		 * @param string $url     Profile URL (standalone default).
		 * @param int    $user_id User ID.
		 */
		return (string) apply_filters( 'mvs_user_profile_url', $url, $user_id );
	}

	/**
	 * Get the display name for a user with optional badge/decoration.
	 *
	 * Use on surfaces that have room for the badge: the single-media page
	 * (author header) and the lightbox sidebar. Compact surfaces — grid
	 * cards, profile lists — should use `get_display_name_plain()` so the
	 * badge stays a deliberate identity signal rather than visual noise on
	 * every thumbnail.
	 *
	 * @param int $user_id User ID.
	 * @return string Display name (may contain HTML from filters).
	 */
	public function get_display_name( int $user_id ): string {
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
	 * Plain display name with all decoration stripped.
	 *
	 * `get_the_author_meta( 'display_name' )` flows through BuddyPress's
	 * `bp_core_get_user_displayname` filter on every BP install — and the
	 * bp-verified-member plugin hooks that filter to append its badge
	 * `<span>` markup. That's the right call on author-focused surfaces
	 * (single-media, lightbox) but creates visual clutter when repeated
	 * on every grid card. Use this helper on compact surfaces — guarantees
	 * a plain string regardless of which third-party filters are active.
	 *
	 * @since 1.2.2
	 *
	 * @param int $user_id User ID.
	 * @return string Display name with HTML tags stripped.
	 */
	public function get_display_name_plain( int $user_id ): string {
		return wp_strip_all_tags( (string) get_the_author_meta( 'display_name', $user_id ) );
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
	 *                        - 'size'         string WP image size slug (default 'large'). Validated against
	 *                                                the registered image-size whitelist; invalid values fall
	 *                                                back to 'large'.
	 *                        - 'alt'          string Pre-escaped alt-attribute value. Caller MUST escape with
	 *                                                esc_attr() before passing — the helper does not re-escape.
	 *                                                When empty, the media's title is fetched and escaped here.
	 *                        - 'classes'      string Extra classes for the <img>/<video>.
	 *                        - 'show_play'    bool   Append play-icon overlay for videos (default true).
	 *                        - 'lazy'         bool   Add loading="lazy" on <img> (default true).
	 * @return string Inner HTML ready for echo.
	 */
	/**
	 * Resolve the best alt text for a media image: prefer the AI-generated
	 * description, fall back to the title. Pre-escaped (esc_attr) for direct
	 * use in an alt="" attribute.
	 *
	 * This is where the AI auto-describe output finally does its job — it was
	 * stored as ai_description meta but never surfaced anywhere (audit
	 * 2026-06-04, #30). Filterable so themes/Pro can override.
	 *
	 * @since 1.6.0
	 *
	 * @param int $media_id Media ID.
	 * @return string esc_attr-escaped alt text.
	 */
	public function resolve_alt_text( int $media_id ): string {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$alt  = (string) $repo->get( $media_id, 'ai_description' );
		if ( '' === trim( $alt ) ) {
			$alt = (string) $repo->get( $media_id, 'title' );
		}

		/**
		 * Filter the resolved alt text for a media image.
		 *
		 * @since 1.6.0
		 *
		 * @param string $alt      Resolved alt (AI description or title).
		 * @param int    $media_id Media ID.
		 */
		$alt = (string) apply_filters( 'mvs_media_alt_text', $alt, $media_id );

		return esc_attr( $alt );
	}

	public function media_thumbnail( int $media_id, array $args = array() ): string {
		$args = wp_parse_args(
			$args,
			array(
				// Default to the admin-configured grid size (medium) instead of a
				// hardcoded 'large' — see SettingsHelper::get_grid_thumb_size_key().
				'size'      => \WPMediaVerse\Core\SettingsHelper::get_grid_thumb_size_key(),
				'alt'       => '',
				'classes'   => '',
				'show_play' => true,
				'lazy'      => true,
				'ttl'       => 0,    // 0 = use mvs_signed_url_ttl option default. Pass YEAR_IN_SECONDS for broadcast surfaces.
				'user_id'   => null, // null = current user. Pass 0 for broadcast (anonymous-viewable) URLs.
			)
		);

		$valid_sizes = array_merge( get_intermediate_image_sizes(), array( 'full' ) );
		$size        = in_array( (string) $args['size'], $valid_sizes, true ) ? (string) $args['size'] : 'large';

		$media_type = $this->get_media_type( $media_id );
		$thumb_url  = $this->get_thumb_url( $media_id, $size, (int) $args['ttl'], $args['user_id'] );
		$alt        = '' !== (string) $args['alt'] ? (string) $args['alt'] : $this->resolve_alt_text( $media_id );

		$extra_class = trim( (string) $args['classes'] );
		$loading     = $args['lazy'] ? ' loading="lazy"' : '';
		$play_icon   = $args['show_play'] ? $this->icon_play() : '';

		// VIDEO — always render <video> with the cloud thumb as the poster.
		// Rationale: cloud poster generation is best-effort. When the upload
		// silently fails (or the file gets cleaned up later) the meta still
		// holds a CDN URL. <img src="bad-url"> shows the OS broken-image
		// icon; <video poster="bad-url"> degrades to the video's first frame
		// (preload="metadata" fetches only the moov atom, ~few KB) and then
		// to a black background with the play overlay if even that fails.
		// This makes the render path self-validating instead of trusting the
		// stored URL.
		//
		// 1.3.0: when no per-upload poster exists (cover-less + ffmpeg-less +
		// no JS canvas frame) we substitute the plugin-bundled default poster
		// SVG. Same asset URL for every cover-less video site-wide, browser-
		// cached on first hit, never written to mvs_media_meta and never
		// pushed to cloud storage. Mirrors the audio waveform pattern.
		if ( 'video' === $media_type ) {
			$file_url   = \WPMediaVerse\Core\MediaUrl::file( $media_id );
			$poster_url = '' !== $thumb_url ? $thumb_url : self::default_video_poster_url();
			if ( $file_url ) {
				$vid_class   = trim( 'mvs-grid-video-preview ' . $extra_class );
				$poster_attr = ' poster="' . esc_url( $poster_url ) . '"';
				return '<video class="' . esc_attr( $vid_class ) . '" preload="metadata" muted playsinline disablepictureinpicture aria-hidden="true"' . $poster_attr . ' src="' . esc_url( $file_url ) . '#t=0.1"></video>' . $play_icon;
			}
			// No streamable URL (access-rules locked the file). Show the
			// default poster as a still image with the play overlay.
			$img_alt = $alt;
			return '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--video">'
				. '<img class="mvs-grid-default-poster" src="' . esc_url( $poster_url ) . '" alt="' . $img_alt . '"' . $loading . ' />'
				. $play_icon
				. '</div>';
		}

		// IMAGE / non-video media — keep the <img> fast path. A broken cloud
		// thumb here is still a broken image, but that's the meta-row bug
		// surfacing visibly (which is what we want for images — a cleanup
		// CLI can repair). Videos are different because we have a perfectly
		// good fallback (the video file itself) the browser knows how to use.
		if ( $thumb_url ) {
			$img_class = trim( 'mvs-media-thumb ' . $extra_class );
			$img_tag   = '<img class="' . esc_attr( $img_class ) . '" src="' . esc_url( $thumb_url ) . '" alt="' . $alt . '"' . $loading . ' />';

			// WebP sibling — wrap in <picture> so browsers that accept WebP
			// fetch the smaller copy and older browsers keep the JPEG.
			// <picture> delegates the choice to the browser, so there's no
			// Accept-header sniffing on the server and no Vary cache issues
			// for page/CDN caches.
			$webp_url = $this->get_webp_variant_url( $media_id, $size, $thumb_url );
			if ( '' !== $webp_url ) {
				return '<picture><source type="image/webp" srcset="' . esc_url( $webp_url ) . '">' . $img_tag . '</picture>';
			}

			return $img_tag;
		}

		if ( 'audio' === $media_type ) {
			// Stylized fallback when no embedded album art was extracted.
			// Renders a deterministic waveform from the media_id so each track
			// gets a unique visual fingerprint that stays stable across
			// re-renders. Same shape every time, no audio analysis required.
			$repo     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
			$title    = (string) $repo->get( $media_id, 'title' );
			$artist   = (string) $repo->get( $media_id, 'artist' );
			$duration = (float) $repo->get( $media_id, 'duration' );
			$mins     = $duration > 0 ? floor( $duration / 60 ) : 0;
			$secs     = $duration > 0 ? (int) $duration % 60 : 0;
			$dur_txt  = $duration > 0 ? sprintf( '%d:%02d', $mins, $secs ) : '';

			$out  = '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--audio mvs-audio-card">';
			$out .= '<span class="mvs-audio-card__waveform" aria-hidden="true">' . $this->render_audio_waveform_svg( $media_id ) . '</span>';
			if ( '' !== $title ) {
				$out .= '<span class="mvs-audio-card__title">' . esc_html( $title ) . '</span>';
			}
			if ( '' !== $artist || '' !== $dur_txt ) {
				$out .= '<span class="mvs-audio-card__meta">';
				if ( '' !== $artist ) {
					$out .= esc_html( $artist );
				}
				if ( '' !== $artist && '' !== $dur_txt ) {
					$out .= ' &middot; ';
				}
				if ( '' !== $dur_txt ) {
					$out .= esc_html( $dur_txt );
				}
				$out .= '</span>';
			}
			$out .= '</div>';
			return $out;
		}

		return '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--generic">' . $this->icon_image() . '</div>';
	}

	/**
	 * Render a SoundCloud-style waveform SVG for audio fallback cards.
	 *
	 * Heights are derived from a SHA-1 hash of the media_id so each track
	 * gets a unique-but-stable pattern. No audio analysis or external tools
	 * needed — runs in microseconds per call.
	 *
	 * @param int $media_id Media id used as the visual seed.
	 *
	 * @return string Inline SVG markup.
	 */
	public function render_audio_waveform_svg( int $media_id ): string {
		$bars   = 48;
		$width  = 240;
		$height = 64;
		$gap    = 2;
		$bar_w  = max( 2.0, ( $width - ( $bars - 1 ) * $gap ) / $bars );

		// SHA-1 hex → 40 chars. Cycle through it so 48 bars is fine.
		$seed = sha1( 'mvs-wave-' . (string) $media_id );
		$len  = strlen( $seed );

		$rects = '';
		for ( $i = 0; $i < $bars; $i++ ) {
			// Two hex digits → 0..255 → normalize to 12..56px (preserves a
			// floor of presence so bars never disappear, keeps a peak
			// headroom for visual breathing room at the card edges).
			$pair   = hexdec( $seed[ ( $i * 2 ) % $len ] . $seed[ ( $i * 2 + 1 ) % $len ] );
			$bar_h  = 12 + (int) round( $pair / 255 * 44 );
			$y      = (int) round( ( $height - $bar_h ) / 2 );
			$x      = (int) round( $i * ( $bar_w + $gap ) );
			$rects .= sprintf(
				'<rect x="%d" y="%d" width="%s" height="%d" rx="1" />',
				$x,
				$y,
				number_format( $bar_w, 2, '.', '' ),
				$bar_h
			);
		}

		return sprintf(
			'<svg class="mvs-audio-waveform-svg" viewBox="0 0 %d %d" preserveAspectRatio="xMidYMid meet" xmlns="http://www.w3.org/2000/svg" fill="currentColor">%s</svg>',
			$width,
			$height,
			$rects
		);
	}

	/**
	 * URL of the plugin-bundled default video poster.
	 *
	 * Used as the `<video poster=...>` source AND as the standalone fallback
	 * image when a video has no per-upload poster (no embedded cover atom,
	 * no ffmpeg extraction, no client-side canvas frame).
	 *
	 * Critical contract: this URL is ALWAYS the plugin's own static asset.
	 * It must never be written to `mvs_media_meta` and must never be pushed
	 * to cloud storage — one URL serves every cover-less video site-wide,
	 * browser-cached on first hit, identical to how the audio waveform SVG
	 * is rendered inline at template time.
	 *
	 * @since 1.3.0
	 *
	 * @return string Absolute URL of the bundled SVG asset.
	 */
	public static function default_video_poster_url(): string {
		/**
		 * Filter the default video poster URL.
		 *
		 * Sites that want a different placeholder (custom branding, animated
		 * GIF, theme-matching gradient) can override via this filter. The
		 * returned URL must be a single asset reused across every cover-less
		 * video; do not return a per-media URL or anything that would pollute
		 * the meta table.
		 *
		 * @since 1.3.0
		 *
		 * @param string $url Default: plugin-bundled SVG asset URL.
		 */
		// Resolve via the plugin URL constant (defined in the entry file),
		// matching how every other asset URL is built across the plugin.
		$default = MVS_PLUGIN_URL . 'assets/images/default-video-poster.svg';
		return (string) apply_filters( 'mvs_default_video_poster_url', $default );
	}

	/**
	 * Render a `<picture>` element that prefers WebP when a variant exists.
	 *
	 * Single-image renderers (media-single, lightbox, custom templates) that
	 * already hold a resolved JPEG URL should call this instead of emitting
	 * `<img>` directly so visitors with modern browsers fetch the smaller
	 * WebP copy.
	 *
	 * @param int    $media_id       Media id.
	 * @param string $jpeg_url       Resolved JPEG/source URL (already signed if needed).
	 * @param string $alt            Alt text. Caller must pre-escape if it contains markup.
	 * @param string $extra_classes  Optional space-separated classes added to the <img>.
	 * @param string $size           Size key to look up the right `*_webp` meta. Default 'full' = original.
	 * @param array  $extra_attrs    Extra attributes for the <img> tag (key => value). Pre-sanitized.
	 *
	 * @return string `<picture>...</picture>` when WebP is available, otherwise a plain `<img>`.
	 */
	public function picture_or_img( int $media_id, string $jpeg_url, string $alt = '', string $extra_classes = '', string $size = 'full', array $extra_attrs = array() ): string {
		if ( '' === $jpeg_url ) {
			return '';
		}
		$attrs_html = '';
		foreach ( $extra_attrs as $k => $v ) {
			$attrs_html .= ' ' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		$class_attr = '' !== $extra_classes ? ' class="' . esc_attr( $extra_classes ) . '"' : '';
		$img_tag    = '<img' . $class_attr . ' src="' . esc_url( $jpeg_url ) . '" alt="' . esc_attr( $alt ) . '"' . $attrs_html . ' />';

		$webp_url = $this->get_webp_variant_url( $media_id, $size, $jpeg_url );
		$avif_url = $this->get_avif_variant_url( $media_id, $size, $jpeg_url );

		if ( '' === $webp_url && '' === $avif_url ) {
			return $img_tag;
		}

		// AVIF first — browsers walk `<source>` elements in document order and
		// pick the first match they support. Modern browsers prefer AVIF; the
		// WebP source below catches the (now-narrow) gap of browsers with WebP
		// but no AVIF support. Older browsers fall through to the `<img>` JPEG.
		$sources = '';
		if ( '' !== $avif_url ) {
			$sources .= '<source type="image/avif" srcset="' . esc_url( $avif_url ) . '">';
		}
		if ( '' !== $webp_url ) {
			$sources .= '<source type="image/webp" srcset="' . esc_url( $webp_url ) . '">';
		}
		return '<picture>' . $sources . $img_tag . '</picture>';
	}

	/**
	 * Resolve the WebP variant URL for a thumbnail, or return '' to keep the JPEG.
	 *
	 * The WebP files are written by ImageOptimizationService at upload time
	 * (1.2.2) and stored as `thumb_<size>_webp` / `original_webp` meta keys.
	 * This helper returns the variant URL only when it is browser-safe to
	 * embed in a <source> element. We skip cases where the underlying JPEG
	 * URL flows through the gated /serve route — that route doesn't speak
	 * WebP yet (cloud-aware /serve negotiation is 1.3.0). For everything else
	 * (direct CDN URLs, local NGINX uploads paths, BunnyCDN/S3 direct mode,
	 * future R2) the WebP URL is a sibling at the same path and is safe to
	 * emit directly.
	 *
	 * @param int    $media_id   Media id.
	 * @param string $size       Size key (large/medium/thumb/full).
	 * @param string $jpeg_url   The resolved JPEG thumb URL — used to detect
	 *                           the gated /serve case and skip WebP for it.
	 *
	 * @return string Browser-safe WebP variant URL or empty string.
	 */
	public function get_webp_variant_url( int $media_id, string $size, string $jpeg_url ): string {
		if ( $media_id <= 0 || '' === $jpeg_url ) {
			return '';
		}

		// Skip when the JPEG URL routes through the gated /serve REST
		// endpoint. The endpoint reads `file_path` from mvs_media_index and
		// streams the bytes; it has no WebP variant negotiation yet. Emitting
		// a `_webp` URL that bypasses /serve would break privacy enforcement.
		if ( false !== strpos( $jpeg_url, '/mvs/v1/serve' ) || false !== strpos( $jpeg_url, '/serve?' ) ) {
			return '';
		}

		// Resolve which meta key holds the WebP sibling for this size. The
		// 'full' / empty cases hit the original WebP; the named intermediate
		// sizes (large/medium/thumb) map to thumb_<size>_webp. Anything else
		// gracefully falls through (returns '' so the JPEG stays).
		$meta_key = '';
		switch ( $size ) {
			case '':
			case 'full':
				$meta_key = \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP;
				break;
			case 'thumbnail':
				$meta_key = 'thumb_thumb_webp';
				break;
			case 'large':
			case 'medium':
			case 'thumb':
				$meta_key = 'thumb_' . $size . '_webp';
				break;
			default:
				return '';
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$url  = (string) $repo->get_raw( $media_id, $meta_key );

		// Fallback: if the requested size has no WebP sibling AND the JPEG
		// renderer fell back to the original (i.e. no thumb_<size> exists
		// either, which currently happens on cloud uploads where thumbnail
		// generation needs a local source file), reuse `original_webp`.
		// Browsers downloading the original WebP at thumbnail-size container
		// dimensions is still a strict win because the WebP is dramatically
		// smaller than the original JPEG/PNG. Skip when the requested size
		// IS `full` to avoid recursion.
		if ( '' === $url && \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP !== $meta_key ) {
			$thumb_meta_key = ( 'thumbnail' === $size ) ? 'thumb_thumb' : 'thumb_' . $size;
			$has_size_thumb = '' !== (string) $repo->get_raw( $media_id, $thumb_meta_key );
			if ( ! $has_size_thumb ) {
				$url = (string) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_WEBP );
			}
		}

		return '' !== $url ? $url : '';
	}

	/**
	 * Resolve the AVIF variant URL for a thumbnail, or return '' to fall back.
	 *
	 * Mirrors `get_webp_variant_url()`. AVIF siblings are emitted by
	 * `ImageOptimizationService::emit_avif_sibling()` when the
	 * `mvs_generate_avif` setting is on AND the editor supports AVIF; they
	 * are stored in `original_avif` / `thumb_<size>_avif` meta keys. As with
	 * WebP, we skip the gated /serve URL case — /serve handles AVIF/WebP
	 * negotiation server-side via the Accept header (see SignedUrlService).
	 *
	 * @since 1.3.0
	 *
	 * @param int    $media_id Media id.
	 * @param string $size     Size key (large/medium/thumb/full).
	 * @param string $jpeg_url The resolved JPEG/PNG URL — used to detect the
	 *                         gated /serve case and skip AVIF for it.
	 * @return string Browser-safe AVIF variant URL or empty string.
	 */
	public function get_avif_variant_url( int $media_id, string $size, string $jpeg_url ): string {
		if ( $media_id <= 0 || '' === $jpeg_url ) {
			return '';
		}

		if ( false !== strpos( $jpeg_url, '/mvs/v1/serve' ) || false !== strpos( $jpeg_url, '/serve?' ) ) {
			return '';
		}

		$meta_key = '';
		switch ( $size ) {
			case '':
			case 'full':
				$meta_key = \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_AVIF;
				break;
			case 'thumbnail':
				$meta_key = 'thumb_thumb_avif';
				break;
			case 'large':
			case 'medium':
			case 'thumb':
				$meta_key = 'thumb_' . $size . '_avif';
				break;
			default:
				return '';
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$url  = (string) $repo->get_raw( $media_id, $meta_key );

		// Same original-AVIF fallback as the WebP path: if the requested size
		// has no AVIF sibling AND no JPEG sibling either (cloud uploads, etc.),
		// fall back to the original AVIF. Downloading the original AVIF at
		// thumbnail dimensions is still a win — AVIF is so much smaller than
		// JPEG/PNG that even at full resolution it beats the smaller-format
		// thumb in bytes.
		if ( '' === $url && \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_AVIF !== $meta_key ) {
			$thumb_meta_key = ( 'thumbnail' === $size ) ? 'thumb_thumb' : 'thumb_' . $size;
			$has_size_thumb = '' !== (string) $repo->get_raw( $media_id, $thumb_meta_key );
			if ( ! $has_size_thumb ) {
				$url = (string) $repo->get_raw( $media_id, \WPMediaVerse\Services\ImageOptimizationService::META_ORIGINAL_AVIF );
			}
		}

		return '' !== $url ? $url : '';
	}

	/**
	 * Lucide-style "play" SVG — inner SVG markup only (no wrapping span).
	 *
	 * Callers that already own a `<span class="mvs-grid-play-icon">` (the
	 * dashboard Interactivity template uses one for `data-wp-bind--hidden`)
	 * should use `icon_play_svg()` and wrap it themselves. Callers that just
	 * need a complete play icon (the thumbnail helper below) use `icon_play()`.
	 */
	public function icon_play_svg(): string {
		// Path is a triangle with vertices (8,5), (20,12), (8,19) — centroid
		// is exactly (12,12), the center of the 24x24 viewBox. This keeps the
		// play icon visually centered in its circular background without
		// needing CSS margin hacks.
		return '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" stroke="none" focusable="false" aria-hidden="true"><path d="M8 5l12 7-12 7z"/></svg>';
	}

	/**
	 * Lucide-style "music" SVG — inner SVG markup only (no wrapping span).
	 */
	public function icon_music_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
	}

	/**
	 * Lucide-style "image" SVG — inner SVG markup only (no wrapping span).
	 */
	public function icon_image_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
	}

	/**
	 * Lucide-style "image-plus" SVG — rendered inline so the icon never
	 * depends on Lucide JS hydration. Used on the BP activity attach-media
	 * button which is sometimes wiped by BP Nouveau's Backbone re-render
	 * before the MutationObserver re-hydrates (card #8).
	 */
	public function icon_image_plus_svg(): string {
		return '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7"/><line x1="16" x2="22" y1="5" y2="5"/><line x1="19" x2="19" y1="2" y2="8"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';
	}

	/**
	 * Full play icon with the standard wrapper span.
	 */
	private function icon_play(): string {
		return '<span class="mvs-grid-play-icon" aria-hidden="true">' . $this->icon_play_svg() . '</span>';
	}

	/**
	 * Full generic-image icon with the standard wrapper span.
	 */
	private function icon_image(): string {
		return '<span class="mvs-grid-generic-icon" aria-hidden="true">' . $this->icon_image_svg() . '</span>';
	}

	/**
	 * Thin wrapper around media_thumbnail() kept for backward compatibility
	 * with callers that used to pass positional args.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     WP image size.
	 * @param string $alt      Alt text.
	 */
	public function render_grid_thumbnail( int $media_id, string $size = '', string $alt = '' ): void {
		// Empty size → fall back to the admin-configured grid size (1.7.0). Was a
		// hardcoded 'large', which ignored the mvs_thumbnail_size setting.
		if ( '' === $size ) {
			$size = \WPMediaVerse\Core\SettingsHelper::get_grid_thumb_size_key();
		}
		$valid_sizes = array_merge( get_intermediate_image_sizes(), array( 'full' ) );
		$safe_size   = in_array( $size, $valid_sizes, true ) ? $size : \WPMediaVerse\Core\SettingsHelper::get_grid_thumb_size_key();
		echo $this->media_thumbnail( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- helper returns markup with all attribute values already escaped (size whitelisted + esc_attr'd, alt pre-escaped here).
			$media_id,
			array(
				'size' => esc_attr( $safe_size ),
				'alt'  => esc_attr( $alt ),
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
	public function render_grid_item( int $media_id, array $stats = array(), array $options = array() ): void {
		$show_author  = $options['show_author'] ?? true;
		$show_overlay = $options['show_overlay'] ?? true;
		$show_actions = $options['show_actions'] ?? false;
		$data_attrs   = $options['data_attrs'] ?? array();
		$size         = $options['size'] ?? \WPMediaVerse\Core\SettingsHelper::get_grid_thumb_size_key();

		// Read core fields from mvs_media_index.
		$media_row = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_all( $media_id );
		if ( empty( $media_row ) || empty( $media_row['media_id'] ) ) {
			return;
		}
		$media_status = $media_row['status'] ?? 'publish';
		if ( 'publish' !== $media_status ) {
			return;
		}

		$media_title = $media_row['title'] ?? '';
		$author_id   = (int) ( $media_row['post_author'] ?? 0 );
		$permalink   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
		$views       = isset( $stats['views'] ) ? (int) $stats['views'] : 0;
		$reactions   = isset( $stats['reactions'] ) ? (int) $stats['reactions'] : 0;
		$comments    = isset( $stats['comments'] ) ? (int) $stats['comments'] : 0;

		// Build data attributes string.
		$data_attrs['media-id']   = $media_id;
		$data_attrs['media-type'] = $this->get_media_type( $media_id );
		$data_str                 = '';
		foreach ( $data_attrs as $key => $val ) {
			$data_str .= ' data-' . esc_attr( $key ) . '="' . esc_attr( $val ) . '"';
		}

		// Check if this is a gallery group cover.
		$media_group = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_group' );
		$group_count = 0;
		$is_gallery  = false;
		if ( $media_group ) {
			$is_gallery  = true;
			$group_count = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'group_count_cache' );
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

		// Interactivity API context for lightbox. currentUserId is included
		// so the shared-ui view.js can correctly gate authenticated UI
		// (favorite status read on lightbox open, etc.); without it the
		// "lightboxIsFavorited" state would never reflect server truth for
		// logged-in viewers — see the favorite-modal desync fix.
		$lightbox_ctx = wp_interactivity_data_wp_context(
			array(
				'mediaId'       => $media_id,
				'restUrl'       => esc_url_raw( rest_url( 'mvs/v1/' ) ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'currentUserId' => get_current_user_id(),
			)
		);

		echo '<div class="' . esc_attr( $item_class ) . '"' . $data_str // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_str is assembled from esc_attr()-wrapped key/value pairs above; the leading space + pre-escaped attrs are safe.
			. ' data-wp-interactive="mvs/shared-ui" '
			. $lightbox_ctx // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context() output (encoded + escaped JSON for the data-wp-context attribute).
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
			echo '<button type="button" class="mvs-grid-item-action mvs-media-edit-btn" data-media-id="' . esc_attr( (string) $media_id ) . '" aria-label="' . esc_attr__( 'Edit media settings', 'wpmediaverse' ) . '" title="' . esc_attr__( 'Edit settings', 'wpmediaverse' ) . '">';
			echo '<i data-lucide="settings" aria-hidden="true"></i>';
			echo '</button>';
			echo '<button type="button" class="mvs-grid-item-action mvs-grid-item-action--danger mvs-media-delete-btn" data-media-id="' . esc_attr( (string) $media_id ) . '" aria-label="' . esc_attr__( 'Delete media', 'wpmediaverse' ) . '" title="' . esc_attr__( 'Delete media', 'wpmediaverse' ) . '">';
			echo '<i data-lucide="trash-2" aria-hidden="true"></i>';
			echo '</button>';
			echo '</div>';
		}

		echo '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link">';

		$this->render_grid_thumbnail( $media_id, $size, $media_title );

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
			// Plain name only — badges belong on author-focused surfaces
			// (single-media header, lightbox), not on every grid thumbnail.
			// The avatar + name link to the uploader's profile (BuddyPress, or
			// the /media/@user fallback) so visitors can reach the poster from
			// the grid, matching the single-media header (card #9962508646).
			// This block is OUTSIDE the media permalink <a> above, so the
			// profile link does not nest.
			$author_url  = $this->get_user_profile_url( $author_id );
			$author_name = $this->get_display_name_plain( $author_id );
			echo '<div class="mvs-grid-item-info">';
			if ( '' !== $author_url ) {
				echo '<a class="mvs-grid-item-author-link" href="' . esc_url( $author_url ) . '">';
				echo get_avatar( $author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) );
				echo '<span class="mvs-grid-item-author">' . esc_html( $author_name ) . '</span>';
				echo '</a>';
			} else {
				echo get_avatar( $author_id, 24, '', '', array( 'class' => 'mvs-grid-avatar' ) );
				echo '<span class="mvs-grid-item-author">' . esc_html( $author_name ) . '</span>';
			}
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
	public function bulk_get_stats( array $media_ids ): array {
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
	/**
	 * Resolve the canonical Explore page URL.
	 *
	 * Prefers the configured `mvs_page_explore` page permalink (matching
	 * OverviewPage / SetupWizard), falling back to the /media/ rewrite only when
	 * no explore page exists. The back-link previously hardcoded /media/, which
	 * sent users to the rewrite instead of the real page on sites whose explore
	 * page has a different slug (e.g. /explore-media/).
	 *
	 * @return string
	 */
	private function resolve_explore_url(): string {
		$explore_id = (int) get_option( 'mvs_page_explore', 0 );
		if ( $explore_id ) {
			$url = get_permalink( $explore_id );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/media/' );
	}

	public function get_parent_route( string $context, array $args = array() ): ?array {
		$parent = null;

		switch ( $context ) {
			case 'single-media':
				$parent = array(
					'url'   => $this->resolve_explore_url(),
					'label' => __( 'Explore', 'wpmediaverse' ),
				);
				break;

			case 'album':
				$author_id = isset( $args['author_id'] ) ? (int) $args['author_id'] : 0;
				if ( $author_id ) {
					$parent = array(
						'url'   => $this->get_user_profile_url( $author_id ),
						'label' => __( 'Profile', 'wpmediaverse' ),
					);
				} else {
					$parent = array(
						'url'   => $this->resolve_explore_url(),
						'label' => __( 'Explore', 'wpmediaverse' ),
					);
				}
				break;

			case 'edit-profile':
				$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
				if ( $user_id ) {
					$parent = array(
						'url'   => $this->get_user_profile_url( $user_id ),
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
	public function render_back_link( string $context, array $args = array() ): void {
		$parent = $this->get_parent_route( $context, $args );
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

	/**
	 * Render a frontend / block empty-state panel (Coding Rule #11).
	 *
	 * One canonical empty state for every front-end "nothing here yet" surface,
	 * replacing the ~5 hand-rolled variants (`.mvs-no-media`, ad-hoc
	 * `.mvs-empty-state-frontend` blocks, etc.). Token-driven + dark-mode-safe
	 * via the shared `.mvs-empty-state-frontend` styles.
	 *
	 * @param array $args {
	 *     @type string $icon    Lucide icon name. Default 'image'.
	 *     @type string $title   Heading (optional).
	 *     @type string $message Body line (optional).
	 *     @type array  $actions List of [ 'url' => , 'label' => , 'variant' => ] buttons.
	 *     @type string $class   Extra wrapper class(es).
	 * }
	 * @return string Escaped HTML.
	 */
	public function render_block_empty_state( array $args = array() ): string {
		$icon    = isset( $args['icon'] ) ? (string) $args['icon'] : 'image';
		$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
		$message = isset( $args['message'] ) ? (string) $args['message'] : '';
		$actions = ( isset( $args['actions'] ) && is_array( $args['actions'] ) ) ? $args['actions'] : array();
		$extra   = isset( $args['class'] ) ? ' ' . (string) $args['class'] : '';

		$html  = '<div class="mvs-empty-state-frontend' . esc_attr( $extra ) . '" role="status">';
		$html .= '<span class="mvs-empty-state-icon" aria-hidden="true"><i data-lucide="' . esc_attr( $icon ) . '"></i></span>';
		if ( '' !== $title ) {
			$html .= '<h3 class="mvs-empty-state-title">' . esc_html( $title ) . '</h3>';
		}
		if ( '' !== $message ) {
			$html .= '<p class="mvs-empty-state-message">' . esc_html( $message ) . '</p>';
		}
		if ( ! empty( $actions ) ) {
			$html .= '<div class="mvs-empty-state-actions">';
			foreach ( $actions as $action ) {
				if ( empty( $action['url'] ) || empty( $action['label'] ) ) {
					continue;
				}
				$variant = isset( $action['variant'] ) ? (string) $action['variant'] : 'primary';
				$html   .= sprintf(
					'<a href="%1$s" class="mvs-btn mvs-btn--%2$s">%3$s</a>',
					esc_url( $action['url'] ),
					esc_attr( $variant ),
					esc_html( $action['label'] )
				);
			}
			$html .= '</div>';
		}
		$html .= '</div>';

		return $html;
	}

	/**
	 * Render an admin-screen empty-state panel (Coding Rule #11).
	 *
	 * Companion to render_block_empty_state() for wp-admin list tables and
	 * dashboard panels, using the `.mvs-empty-state-admin` styles.
	 *
	 * @param array $args { @type string $icon, @type string $title, @type string $message }
	 * @return string Escaped HTML.
	 */
	public function render_admin_empty_state( array $args = array() ): string {
		$icon    = isset( $args['icon'] ) ? (string) $args['icon'] : 'inbox';
		$title   = isset( $args['title'] ) ? (string) $args['title'] : '';
		$message = isset( $args['message'] ) ? (string) $args['message'] : '';

		$html  = '<div class="mvs-empty-state-admin" role="status">';
		$html .= '<span class="mvs-empty-state-icon" aria-hidden="true"><i data-lucide="' . esc_attr( $icon ) . '"></i></span>';
		if ( '' !== $title ) {
			$html .= '<p class="mvs-empty-state-admin__title">' . esc_html( $title ) . '</p>';
		}
		if ( '' !== $message ) {
			$html .= '<p class="mvs-empty-state-admin__message">' . esc_html( $message ) . '</p>';
		}
		$html .= '</div>';

		return $html;
	}
}
