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
		$source = (string) get_option( 'mvs_lightbox_image_source', 'large' );
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

		$source = (string) get_option( 'mvs_lightbox_image_source', 'large' );
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

		$source = (string) get_option( 'mvs_lightbox_image_source', 'large' );
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
		// Standalone default: the plugin's own @handle profile route. Core is
		// platform-agnostic — no bp_* calls here. Social platforms (BuddyPress,
		// BuddyNext) override this via the filter below; the BuddyPress
		// integration's ProfileTabIntegration::filter_user_profile_url() returns
		// the BP member URL when BP is active.
		//
		// SECURITY: use user_nicename, never user_login. user_login is the
		// wp-login.php credential and must never be exposed in a public URL —
		// it enables username enumeration for brute-force/credential-stuffing
		// attacks. user_nicename is already public (WP core exposes it via
		// /author/{nicename}/ author archives), so this carries no new exposure.
		$user_nicename = get_the_author_meta( 'user_nicename', $user_id );
		$url           = home_url( '/media/@' . $user_nicename . '/' );

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
				// preload="none" and NO `#t=` fragment, deliberately.
				//
				// This used to be preload="metadata" with `#t=0.1` so a bad
				// poster URL would degrade to the video's own first frame. That
				// backfired: a video element paints its current frame as soon as
				// it has one, and the poster is only shown UNTIL then — so the
				// 0.1s frame was painted over every poster, good ones included.
				// Any video that opens on a fade-in, a title card or a white
				// intro therefore rendered a blank tile no matter how good its
				// poster was, and the bundled fallback below could never be seen
				// either. That is the "blank video cover" customers report.
				//
				// $poster_url is never empty — it falls back to the bundled SVG
				// on this very line above — so there is nothing left for the
				// frame-grab to rescue. Dropping it also means a grid of videos
				// no longer fetches a moov atom per tile.
				return '<video class="' . esc_attr( $vid_class ) . '" preload="none" muted playsinline disablepictureinpicture aria-hidden="true"' . $poster_attr . ' src="' . esc_url( $file_url ) . '"></video>' . $play_icon;
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

		if ( 'document' === $media_type ) {
			// A document reaches the grid when a member SAVES one into a
			// collection — the media library itself never lists documents
			// (MediaTypes::MEDIA_LIBRARY). Before this branch it fell through
			// to the generic placeholder below, whose glyph is `icon_image()`:
			// a PICTURE icon, on a dark tile, for a spreadsheet.
			//
			// "Absent beats broken" settled the library case and does NOT apply
			// here. That rule is about untyped rows nobody chose to publish; a
			// document in a collection is there because its owner deliberately
			// put it there, so hiding it would lose something they meant to
			// keep. It needs a tile that says what it is instead.
			//
			// Same shape as the audio card above — glyph, title, meta — which is
			// already this method's answer to "has no picture of its own".
			$repo  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
			$title = (string) $repo->get( $media_id, 'title' );
			$mime  = (string) $repo->get( $media_id, 'file_type' );
			$bytes = (int) $repo->get( $media_id, 'file_size' );
			$group = \WPMediaVerse\Core\DocumentTypes::group_for_mime( $mime );
			$glyph = \WPMediaVerse\Core\DocumentTypes::icon( $group );

			$meta = $group ? \WPMediaVerse\Core\DocumentTypes::label( $group ) : '';
			if ( $bytes > 0 ) {
				$meta = trim( $meta . ( '' !== $meta ? ' &middot; ' : '' ) . size_format( $bytes ) );
			}

			$out  = '<div class="mvs-grid-item-placeholder mvs-grid-item-placeholder--document mvs-doc-card">';
			$out .= '<span class="mvs-doc-card__glyph mvs-doc-glyph mvs-doc-glyph--' . esc_attr( $glyph ) . '" aria-hidden="true"></span>';
			if ( '' !== $title ) {
				$out .= '<span class="mvs-doc-card__title">' . esc_html( $title ) . '</span>';
			}
			if ( '' !== $meta ) {
				$out .= '<span class="mvs-doc-card__meta">' . $meta . '</span>';
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
	 * BuddyNext-aware login URL.
	 *
	 * Identity in this stack lives in BuddyNext (login/register/reset/2FA/social),
	 * so every "log in" link MediaVerse renders must route to BuddyNext's paged
	 * front-end auth screen when that plugin is active — NOT wp-login.php. Falls
	 * back to core `wp_login_url()` when BuddyNext is absent (Free running
	 * standalone). Override the final URL via the `mvs_login_url` filter.
	 *
	 * @since 2.0.0
	 *
	 * @param string $redirect Optional URL to return to after login.
	 * @return string Login URL.
	 */
	public static function login_url( string $redirect = '' ): string {
		$url = '';

		if ( class_exists( '\BuddyNext\Core\PageRouter' ) && method_exists( '\BuddyNext\Core\PageRouter', 'auth_url' ) ) {
			$bn = (string) \BuddyNext\Core\PageRouter::auth_url();
			if ( '' !== $bn ) {
				$url = $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $bn ) : $bn;
			}
		}

		if ( '' === $url ) {
			$url = wp_login_url( $redirect );
		}

		/**
		 * Filters the login URL MediaVerse links to across every surface.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url      Resolved login URL (BuddyNext auth page when active).
		 * @param string $redirect Post-login return URL.
		 */
		return (string) apply_filters( 'mvs_login_url', $url, $redirect );
	}

	/**
	 * BuddyNext-aware registration URL.
	 *
	 * BuddyNext's auth screen carries both sign-in and sign-up, so registration
	 * routes to the same paged auth URL when BuddyNext is active; otherwise falls
	 * back to core `wp_registration_url()`. Override via `mvs_registration_url`.
	 *
	 * @since 2.0.0
	 *
	 * @param string $redirect Optional URL to return to after registration.
	 * @return string Registration URL.
	 */
	public static function registration_url( string $redirect = '' ): string {
		$url = '';

		if ( class_exists( '\BuddyNext\Core\PageRouter' ) && method_exists( '\BuddyNext\Core\PageRouter', 'auth_url' ) ) {
			$bn = (string) \BuddyNext\Core\PageRouter::auth_url();
			if ( '' !== $bn ) {
				$url = $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $bn ) : $bn;
			}
		}

		if ( '' === $url ) {
			$url = wp_registration_url();
		}

		/**
		 * Filters the registration URL MediaVerse links to.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url      Resolved registration URL.
		 * @param string $redirect Post-registration return URL.
		 */
		return (string) apply_filters( 'mvs_registration_url', $url, $redirect );
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
		return $this->get_variant_url( $media_id, $size, $jpeg_url, \WPMediaVerse\Services\VariantSpec::FORMAT_WEBP );
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
		return $this->get_variant_url( $media_id, $size, $jpeg_url, \WPMediaVerse\Services\VariantSpec::FORMAT_AVIF );
	}

	/**
	 * Meta key holding a modern-format sibling for a rendered size.
	 *
	 * Normalises the template-side size vocabulary ('' / full / thumbnail) onto
	 * the storage-side key vocabulary, then defers to the canonical mapper in
	 * `Core\MediaUrl` so the size+format -> key rule lives in one place.
	 *
	 * @since 2.3.1
	 *
	 * @param string $size   Size key (large|medium|thumb|thumbnail|full|'').
	 * @param string $format `VariantSpec::FORMAT_WEBP` or `FORMAT_AVIF`.
	 * @return string Meta key, or '' when the size has no variant.
	 */
	private function variant_meta_key_for_size( string $size, string $format ): string {
		switch ( $size ) {
			case '':
			case 'full':
				return 'original_' . $format;
			case 'thumbnail':
				return MediaUrl::variant_meta_key( 'thumb', $format );
			case 'large':
			case 'medium':
			case 'thumb':
				return MediaUrl::variant_meta_key( $size, $format );
			default:
				return '';
		}
	}

	/**
	 * Resolve a modern-format sibling URL (WebP/AVIF) for an already-rendered
	 * primary (JPEG/PNG) URL.
	 *
	 * Single implementation behind `get_webp_variant_url()` and
	 * `get_avif_variant_url()`, which previously differed only by format.
	 *
	 * Resolution mirrors the primary path in
	 * `SignedUrlService::maybe_direct_cloud_thumbnail_url()`, because the two
	 * must agree on WHERE a file lives:
	 *
	 *   1. `<key>_path` -- the driver-agnostic relative path (source of truth
	 *      since 1.4.0) -- resolved through the driver the file actually lives
	 *      on. This is what puts the variant on the CDN when the primary is on
	 *      the CDN.
	 *   2. The legacy absolute URL meta, for rows that never got a `_path`:
	 *      pre-1.4.0 rows the Migrator v14 backfill has not reached, and
	 *      imported MediaPress / rtMedia / BuddyBoss records that
	 *      `MediaVariantWriter::path_meta_ok()` deliberately skips because
	 *      their `file_path` is absolute.
	 *
	 * Then one invariant, which is the actual fix for Basecamp #10162798416:
	 * **never emit a variant on a different host from the primary.** Before
	 * this, a Bunny-CDN JPEG was paired with the stale local `_webp` URL, which
	 * points inside wp-content/uploads/wpmediaverse/ -- the directory MediaVerse
	 * itself locks with `Deny from all` -- so every WebP returned 403 on Apache
	 * (AH01797) and rendered as a broken image. The host check is deliberately
	 * broader than the CDN case: it also covers any future arrangement where
	 * primary and variant could diverge.
	 *
	 * @since 2.3.1
	 *
	 * @param int    $media_id    Media id.
	 * @param string $size        Size key (large|medium|thumb|thumbnail|full|'').
	 * @param string $primary_url The resolved JPEG/PNG URL being paired with.
	 * @param string $format      `VariantSpec::FORMAT_WEBP` or `FORMAT_AVIF`.
	 * @return string Browser-safe variant URL, or '' to fall back to the primary.
	 */
	private function get_variant_url( int $media_id, string $size, string $primary_url, string $format ): string {
		if ( $media_id <= 0 || '' === $primary_url ) {
			return '';
		}

		// Skip when the primary routes through the gated /serve REST endpoint:
		// /serve does its own Accept-header negotiation (1.3.0), and emitting a
		// sibling URL that bypasses it would break privacy enforcement.
		if ( false !== strpos( $primary_url, '/mvs/v1/serve' ) || false !== strpos( $primary_url, '/serve?' ) ) {
			return '';
		}

		$meta_key = $this->variant_meta_key_for_size( $size, $format );
		if ( '' === $meta_key ) {
			return '';
		}

		$repo         = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$original_key = 'original_' . $format;

		// 1. Where the variant actually lives — `_path` through the driver,
		// falling back to the legacy URL meta. Single implementation in the
		// read-side facade, shared with the admin variant links.
		$url = MediaUrl::variant_url( $media_id, $meta_key );

		// Fall back to the original-format sibling when the requested size has
		// neither a variant nor a primary thumb of its own (cloud uploads, where
		// thumbnail generation needs a local source file). Serving the original
		// at thumbnail dimensions is still a strict byte win. Skipped when the
		// requested size IS the original, to avoid recursing onto itself.
		if ( '' === $url && $original_key !== $meta_key ) {
			$thumb_meta_key = ( 'thumbnail' === $size ) ? 'thumb_thumb' : 'thumb_' . $size;
			$has_size_thumb = '' !== (string) $repo->get_raw( $media_id, $thumb_meta_key )
				|| '' !== (string) $repo->get_raw( $media_id, $thumb_meta_key . '_path' );
			if ( ! $has_size_thumb ) {
				$url = MediaUrl::variant_url( $media_id, $original_key );
			}
		}

		if ( '' === $url ) {
			return '';
		}

		// 2. The invariant. A variant on a different host from the primary is
		// unreachable by definition -- the primary is what the site has proven
		// it can serve. Relative URLs parse to an empty host on both sides and
		// compare equal, which is correct: same origin.
		if ( (string) wp_parse_url( $url, PHP_URL_HOST ) !== (string) wp_parse_url( $primary_url, PHP_URL_HOST ) ) {
			return '';
		}

		return $url;
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

	/**
	 * The document listing page URL, or '' when there is not one.
	 *
	 * Returns an empty string rather than a fallback on purpose: with no document
	 * page there is nowhere sensible to send a member, and sending them to the
	 * MEDIA grid — which by design contains no documents — would be worse than
	 * leaving the link as Explore.
	 *
	 * @since 2.4.0
	 *
	 * @return string
	 */
	public function resolve_documents_url(): string {
		$page_id = (int) get_option( 'mvs_page_explore_documents', 0 );

		if ( $page_id ) {
			$url = get_permalink( $page_id );
			if ( $url ) {
				return (string) $url;
			}
		}

		return '';
	}

	/**
	 * Whether a media row is a document of either kind.
	 *
	 * Uses DOCUMENT_LIBRARY, so a quarantined `legacy_document` also returns to
	 * the document page — it is not in a media grid either, so Explore would be
	 * just as wrong for it.
	 *
	 * @since 2.4.0
	 *
	 * @param int $media_id Media id.
	 * @return bool
	 */
	private function is_document( int $media_id ): bool {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		if ( ! $repo->exists( $media_id ) ) {
			return false;
		}

		return in_array(
			(string) $repo->get( $media_id, 'media_type' ),
			\WPMediaVerse\Core\MediaTypes::DOCUMENT_LIBRARY,
			true
		);
	}

	public function get_parent_route( string $context, array $args = array() ): ?array {
		$parent = null;

		switch ( $context ) {
			case 'single-media':
				// A document goes back to the DOCUMENT page, never to Explore.
				// `media-single.php` is shared by both — that reuse is deliberate
				// (design §10) — but the back link is the one place where sharing
				// a template would tell a member the wrong thing about where they
				// came from, and offer them a grid their item is not in.
				// (Owner, 2026-08-09.)
				$media_id = isset( $args['media_id'] ) ? (int) $args['media_id'] : 0;

				if ( $media_id && $this->is_document( $media_id ) ) {
					// The OWNER came from their own drive, so send them back
					// there — not to the public Explore Documents listing, which
					// excludes private rows and would drop them on an empty "No
					// documents" grid the instant after previewing their own
					// private file (Basecamp 10230967864). Everyone else (a
					// non-owner member, a logged-out viewer) gets the public
					// listing, which is the only documents surface they share.
					$viewer = get_current_user_id();
					$owner  = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'post_author' );

					if ( $viewer > 0 && $viewer === $owner ) {
						$drive_url = \WPMediaVerse\Core\DashboardSections::url( 'documents' );

						if ( '' !== $drive_url ) {
							$parent = array(
								'url'   => $drive_url,
								'label' => __( 'My documents', 'wpmediaverse' ),
							);
							break;
						}
					}

					$documents_url = $this->resolve_documents_url();

					if ( '' !== $documents_url ) {
						$parent = array(
							'url'   => $documents_url,
							'label' => __( 'Documents', 'wpmediaverse' ),
						);
						break;
					}
				}

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
	 * Render the toolbar that sits above a panel's list or grid.
	 *
	 * ONE shape for every list surface: search, count, filters, sort, direction.
	 * Before this there was exactly one toolbar in the whole dashboard — the
	 * document drive's — and it was a private method on a Pro class. Media,
	 * Albums, Favourites and Collections had no search, no sort, no filter and
	 * no count at all, so "the same product" meant five different answers to
	 * "how do I find one of these".
	 *
	 * BOTH RENDERING MODELS ARE SERVED, deliberately, because the dashboard runs
	 * two: the document drive is a server-rendered GET form (works with
	 * JavaScript off, every view a shareable URL), and the other four panels are
	 * client-side Interactivity. This helper emits the markup; the caller says
	 * how it is driven. `form` wraps it in a GET form for the server-rendered
	 * side; `attrs` on any control carries the Interactivity bindings for the
	 * client side. Both write the SAME query keys — `s`, `orderby`, `order` —
	 * so a URL from one panel reads the same as a URL from another.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args {
	 *     @type string $id       Id prefix for label/control association. Required.
	 *     @type bool   $form     Wrap in a GET form (server-rendered panels).
	 *     @type array  $hidden   [ name => value ] hidden fields carried on submit.
	 *     @type array  $search   [ name, value, label, placeholder, attrs ].
	 *     @type string $count    Pre-formatted, already-pluralised count line.
	 *     @type array  $filters  List of [ name, label, value, options, attrs ].
	 *     @type array  $sort     [ name, label, value, options, attrs ].
	 *     @type array  $order    [ name, label, value, options, attrs ].
	 *     @type string $submit   Submit label. Omit for client-driven panels,
	 *                            which apply on change and need no button.
	 *     @type string $class    Extra wrapper class(es).
	 * }
	 * @return string Escaped HTML.
	 */
	public function render_panel_toolbar( array $args = array() ): string {
		$id     = isset( $args['id'] ) ? (string) $args['id'] : 'mvs-panel';
		$form   = ! empty( $args['form'] );
		$extra  = isset( $args['class'] ) ? ' ' . (string) $args['class'] : '';
		$submit = isset( $args['submit'] ) ? (string) $args['submit'] : '';

		$html = $form
			? '<form class="mvs-panel-toolbar' . esc_attr( $extra ) . '" method="get" role="search">'
			: '<div class="mvs-panel-toolbar' . esc_attr( $extra ) . '" role="search">';

		if ( $form && ! empty( $args['hidden'] ) && is_array( $args['hidden'] ) ) {
			foreach ( $args['hidden'] as $name => $value ) {
				// Carrying the view across is what stops a filter from teleporting
				// the member out of the folder or the trash they were looking at.
				$html .= sprintf(
					'<input type="hidden" name="%1$s" value="%2$s" />',
					esc_attr( (string) $name ),
					esc_attr( (string) $value )
				);
			}
		}

		if ( ! empty( $args['search'] ) && is_array( $args['search'] ) ) {
			$html .= $this->toolbar_search( $id, $args['search'] );
		}

		if ( ! empty( $args['count'] ) ) {
			// A string for a server-rendered panel; an array for a client-driven
			// one, which needs somewhere to hang `data-wp-text` so the number
			// follows the search rather than freezing at whatever the page was
			// built with. The comment below always claimed the panels rewrote
			// this — there was no way for them to.
			$count = is_array( $args['count'] ) ? $args['count'] : array( 'text' => $args['count'] );

			// aria-live: a client-driven panel rewrites this after a search, and
			// "3 albums" changing silently is the result a screen reader misses.
			$html .= '<span class="mvs-panel-toolbar__count" aria-live="polite"'
				. $this->toolbar_attrs( $count ) . '>'
				. esc_html( (string) ( $count['text'] ?? '' ) )
				. '</span>';
		}

		$selects = array();

		if ( ! empty( $args['filters'] ) && is_array( $args['filters'] ) ) {
			foreach ( $args['filters'] as $filter ) {
				$selects[] = $filter;
			}
		}

		foreach ( array( 'sort', 'order' ) as $key ) {
			if ( ! empty( $args[ $key ] ) && is_array( $args[ $key ] ) ) {
				$selects[] = $args[ $key ];
			}
		}

		foreach ( $selects as $select ) {
			$html .= $this->toolbar_select( $id, $select );
		}

		if ( '' !== $submit ) {
			$html .= '<button type="submit" class="mvs-btn mvs-btn--secondary mvs-panel-toolbar__apply">' . esc_html( $submit ) . '</button>';
		}

		$html .= $form ? '</form>' : '</div>';

		return $html;
	}

	/**
	 * The toolbar's search field.
	 *
	 * @since 2.4.0
	 *
	 * @param string $id     Id prefix.
	 * @param array  $search Field config.
	 * @return string
	 */
	private function toolbar_search( string $id, array $search ): string {
		$name        = isset( $search['name'] ) ? (string) $search['name'] : 's';
		$value       = isset( $search['value'] ) ? (string) $search['value'] : '';
		$label       = isset( $search['label'] ) ? (string) $search['label'] : __( 'Search', 'wpmediaverse' );
		$placeholder = isset( $search['placeholder'] ) ? (string) $search['placeholder'] : $label;
		$field_id    = $id . '-search';

		return sprintf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label>'
			. '<input id="%1$s" class="mvs-panel-toolbar__search" type="search" name="%3$s" value="%4$s" placeholder="%5$s"%6$s />',
			esc_attr( $field_id ),
			esc_html( $label ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			$this->toolbar_attrs( $search )
		);
	}

	/**
	 * One labelled select in the toolbar.
	 *
	 * @since 2.4.0
	 *
	 * @param string $id     Id prefix.
	 * @param array  $select Select config.
	 * @return string
	 */
	private function toolbar_select( string $id, array $select ): string {
		$name    = isset( $select['name'] ) ? (string) $select['name'] : '';
		$label   = isset( $select['label'] ) ? (string) $select['label'] : '';
		$value   = isset( $select['value'] ) ? (string) $select['value'] : '';
		$options = ( isset( $select['options'] ) && is_array( $select['options'] ) ) ? $select['options'] : array();

		if ( '' === $name || ! $options ) {
			return '';
		}

		$field_id = $id . '-' . sanitize_key( $name );

		$html = sprintf(
			'<label class="screen-reader-text" for="%1$s">%2$s</label><select id="%1$s" class="mvs-panel-toolbar__select" name="%3$s"%4$s>',
			esc_attr( $field_id ),
			esc_html( $label ),
			esc_attr( $name ),
			$this->toolbar_attrs( $select )
		);

		foreach ( $options as $option_value => $option_label ) {
			$html .= sprintf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( (string) $option_value ),
				selected( $value, (string) $option_value, false ),
				esc_html( (string) $option_label )
			);
		}

		return $html . '</select>';
	}

	/**
	 * Extra attributes for a toolbar control.
	 *
	 * This is the seam that lets one markup helper serve both engines: the
	 * client-side panels pass their `data-wp-on--*` bindings through here and
	 * the server-rendered drive passes nothing.
	 *
	 * Attribute NAMES are restricted to `data-*` and `aria-*`. A caller cannot
	 * reach in and set `onclick`, and cannot overwrite `name`, `value` or `id`
	 * and quietly repoint the control at a different parameter.
	 *
	 * @since 2.4.0
	 *
	 * @param array $config Control config, possibly carrying `attrs`.
	 * @return string Leading-space-prefixed attribute string, or ''.
	 */
	private function toolbar_attrs( array $config ): string {
		if ( empty( $config['attrs'] ) || ! is_array( $config['attrs'] ) ) {
			return '';
		}

		$out = '';

		foreach ( $config['attrs'] as $attr => $value ) {
			$attr = (string) $attr;

			if ( ! preg_match( '/^(data|aria)-[a-z0-9_-]+$/i', $attr ) ) {
				continue;
			}

			$out .= sprintf( ' %s="%s"', esc_attr( $attr ), esc_attr( (string) $value ) );
		}

		return $out;
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

	/**
	 * Seed PHP-translated strings for the mvs/media-social Interactivity store.
	 *
	 * The store (src/blocks/media-social/view.js) is a script MODULE, so
	 * window.wp.i18n.__() is English-locked there (wp_set_script_translations()
	 * can't reach a module). We inject the translated strings into interactivity
	 * state; the store reads state.i18n.<key> with an English fallback. Called
	 * before the first data-wp-interactive="mvs/media-social" root element in
	 * media-single.php and album.php so the state is seeded during render.
	 * Basecamp 10073528834.
	 *
	 * @return void
	 */
	public static function media_social_i18n_state(): void {
		wp_interactivity_state(
			'mvs/media-social',
			array(
				'i18n' => array(
					// Reactions.
					'loginToReact'         => __( 'Please log in to react.', 'wpmediaverse' ),
					'reactionSaveFailed'   => __( 'Could not save reaction.', 'wpmediaverse' ),
					'networkError'         => __( 'Network error.', 'wpmediaverse' ),
					// Favorites.
					'loginToFavorite'      => __( 'Please log in to favorite.', 'wpmediaverse' ),
					'favoriteUpdateFailed' => __( 'Could not update favorite.', 'wpmediaverse' ),
					// Comments.
					'loginToComment'       => __( 'Please log in to comment.', 'wpmediaverse' ),
					'commentPostFailed'    => __( 'Could not post comment.', 'wpmediaverse' ),
					'commentUpdated'       => __( 'Comment updated.', 'wpmediaverse' ),
					'editFailed'           => __( 'Edit failed.', 'wpmediaverse' ),
					'deleteCommentConfirm' => __( 'Delete this comment?', 'wpmediaverse' ),
					'commentDeleted'       => __( 'Comment deleted.', 'wpmediaverse' ),
					// Share.
					'shareCopied'          => __( '✓ Copied!', 'wpmediaverse' ),
					'linkCopiedClipboard'  => __( 'Link copied to clipboard!', 'wpmediaverse' ),
					// Plain text — the share button's <i data-lucide="share-2"> supplies
					// the icon, so an emoji here re-created the double-icon two seconds
					// after every share (card 10127321228).
					'shareResetLabel'      => __( 'Share', 'wpmediaverse' ),
					'copyLinkFailed'       => __( 'Could not copy link. Please copy the URL manually.', 'wpmediaverse' ),
					// Follow.
					'followFailed'         => __( 'Follow action failed.', 'wpmediaverse' ),
					// Owner edit.
					'savedRedirecting'     => __( 'Saved! Redirecting to the new URL…', 'wpmediaverse' ),
					'albumSaved'           => __( 'Album saved!', 'wpmediaverse' ),
					'saved'                => __( 'Saved!', 'wpmediaverse' ),
					'saveFailed'           => __( 'Save failed.', 'wpmediaverse' ),
					// Owner delete.
					'deleteAlbumConfirm'   => __( 'Delete this album? Media items will not be deleted.', 'wpmediaverse' ),
					'deleteMediaConfirm'   => __( 'Delete this media item? This cannot be undone.', 'wpmediaverse' ),
					'deleteAction'         => __( 'Delete', 'wpmediaverse' ),
					// Report.
					'loginToReport'        => __( 'Please log in to report content.', 'wpmediaverse' ),
					'reasonSpam'           => __( 'Spam', 'wpmediaverse' ),
					'reasonHarassment'     => __( 'Harassment', 'wpmediaverse' ),
					'reasonNudity'         => __( 'Nudity or sexual content', 'wpmediaverse' ),
					'reasonViolence'       => __( 'Violence or dangerous acts', 'wpmediaverse' ),
					'reasonCopyright'      => __( 'Copyright infringement', 'wpmediaverse' ),
					'reasonMisinformation' => __( 'Misinformation', 'wpmediaverse' ),
					'reasonOther'          => __( 'Other', 'wpmediaverse' ),
					'reportPrompt'         => __( 'Why are you reporting this media?', 'wpmediaverse' ),
					'reportSubmitted'      => __( 'Report submitted. Thank you.', 'wpmediaverse' ),
					'reportAlready'        => __( 'Already reported or error occurred.', 'wpmediaverse' ),
					'reportAction'         => __( 'Report', 'wpmediaverse' ),
				),
			)
		);
	}

	/**
	 * Should this request render the block-theme document shell?
	 *
	 * @return bool
	 */
	private static function use_block_shell(): bool {
		return function_exists( 'wp_is_block_theme' )
			&& wp_is_block_theme()
			&& function_exists( 'block_header_area' )
			&& function_exists( 'block_footer_area' );
	}

	/**
	 * Open a plugin-owned page: document shell + the site header.
	 *
	 * `get_header()` predates block themes. Under one — which is every default
	 * theme since Twenty Twenty-Two — there is no `header.php`, so the call
	 * emits "Theme without header.php is deprecated" on EVERY plugin page load
	 * and renders no site chrome whatsoever: measured on Twenty Twenty-Five,
	 * our pages had no site header, no navigation and no site title. That is a
	 * large part of why a single media page reads as "a random attachment page"
	 * rather than part of the community.
	 *
	 * Swapping the call for `block_header_area()` alone does NOT work, and the
	 * failure is silent: those functions render only the header *template
	 * part*, never the `<!DOCTYPE>`/`<html>`/`<head>` shell or `wp_head()`,
	 * which `get_header()` supplies via theme-compat. Doing that produced a
	 * page with the right navigation and zero stylesheets. So on block themes
	 * we emit the shell ourselves and then the header part, wrapped in
	 * `.wp-site-blocks` so the theme's own layout and spacing rules apply.
	 *
	 * Classic themes are untouched — they keep `get_header()` exactly as before.
	 *
	 * @since 2.3.0
	 *
	 * @param string|null $name Optional specialised header name (classic themes only).
	 */
	public static function site_header( ?string $name = null ): void {
		if ( ! self::use_block_shell() ) {
			get_header( $name );
			return;
		}
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>" />
		<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
		<?php wp_body_open(); ?>
<div class="wp-site-blocks">
		<?php
		block_header_area();
	}

	/**
	 * Close a plugin-owned page: site footer + document shell.
	 *
	 * Block-theme counterpart of {@see self::site_header()}. Must be called
	 * exactly once for each site_header() call or the document is left unclosed.
	 *
	 * @since 2.3.0
	 *
	 * @param string|null $name Optional specialised footer name (classic themes only).
	 */
	public static function site_footer( ?string $name = null ): void {
		if ( ! self::use_block_shell() ) {
			get_footer( $name );
			return;
		}
		block_footer_area();
		?>
</div>
		<?php wp_footer(); ?>
</body>
</html>
		<?php
	}
}
