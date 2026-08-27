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


/**
 * Static helpers for rendering media thumbnails and type labels inside
 * BuddyPress activity streams.
 */
class MediaDisplayHelper {

	/**
	 * Resolve a BuddyPress group's URL across BP versions and forks.
	 *
	 * The bp_get_group_url() function is the BuddyPress 12.0+ URL API. BuddyBoss and
	 * BuddyPress < 12 never define it and use bp_get_group_permalink() instead — so
	 * calling bp_get_group_url() unguarded fataled ("Call to undefined function ...
	 * bp_get_group_url()") on a BuddyBoss site. This shim prefers the modern
	 * function and falls back to the legacy one, mirroring the guarded member-URL
	 * resolution in ProfileTabIntegration::filter_user_profile_url(). Because it is
	 * function_exists-guarded it can never fatal regardless of which BP fork is active.
	 *
	 * @param object $group A BP group object (e.g. from groups_get_current_group()).
	 * @return string The group's URL, or '' when neither BP function is available.
	 */
	public static function group_url( $group ): string {
		if ( function_exists( 'bp_get_group_url' ) ) {
			return (string) bp_get_group_url( $group );
		}
		if ( function_exists( 'bp_get_group_permalink' ) ) {
			return (string) bp_get_group_permalink( $group );
		}
		return '';
	}

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
		$media_type = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_type' );
		// Documents are admitted here, and that is the fix for the blank card:
		// this guard used to answer '' for anything that was not image/video/
		// audio, so a document upload produced an activity that said "uploaded
		// a new file" over an EMPTY body — announcing a file while giving no
		// way to reach it. `legacy_document` and untyped rows still return ''
		// on purpose: those are data defects, not content (see MediaTypes).
		if ( ! in_array( $media_type, array( 'image', 'video', 'audio', 'document' ), true ) ) {
			return '';
		}

		// `$file_url` is the fallback for the link `href` when the media has
		// no permalink (e.g. cleanup state). Broadcast TTL (1 year, user_id=0)
		// because this thumbnail HTML gets baked into bp_activity.content and
		// read by anyone scrolling the feed days later — short-TTL URLs would
		// 403 on activities older than 1 hour. Privacy is enforced at sign
		// time so non-public media silently returns '' and the link omits
		// the href.
		$permalink = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
		$file_url  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_broadcast_url( $media_id );
		$title     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'title' ) ?: __( 'Untitled', 'wpmediaverse' );
		$href      = $permalink ?: $file_url;
		$data_mid  = ' data-mvs-media-id="' . esc_attr( $media_id ) . '"';

		// A document has no picture, so the canonical thumbnail helper has
		// nothing to return and the generic placeholder would draw a PHOTO
		// glyph on a spreadsheet. Same shape as the audio card below — icon,
		// name, meta — because that is already this file's answer to "renders
		// as a card, not an image".
		//
		// THE LINK IS THE PERMALINK ONLY, never $file_url. Every other branch
		// falls back to a broadcast-signed file URL, which is right for media
		// and wrong here: that URL would be baked into bp_activity.content for
		// a year and points straight AT the file, which is precisely what the
		// gated /serve endpoint exists to stop. With no permalink the card
		// renders unlinked rather than handing out a bypass.
		if ( 'document' === $media_type ) {
			$doc_mime = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
			$doc_icon = \WPMediaVerse\Core\DocumentTypes::icon_for_mime( $doc_mime );
			$doc_group = \WPMediaVerse\Core\DocumentTypes::group_for_mime( $doc_mime );
			$doc_size = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_size' );

			$doc_meta = $doc_group ? \WPMediaVerse\Core\DocumentTypes::label( $doc_group ) : '';
			if ( $doc_size > 0 ) {
				$doc_meta = trim( $doc_meta . ( $doc_meta ? ' &middot; ' : '' ) . size_format( $doc_size ) );
			}

			// The glyph rides on a CLASS, not on `data-lucide`, and that is not a
			// style preference — it is what makes the card work.
			//
			// Two things forced it. kses strips `data-lucide` on save (BP's
			// allowed-tags list carries `data-mvs-media-id` and not this), so
			// the element came back as a bare `<i></i>`. And activity content
			// is BAKED into bp_activity.content and travels: the sitewide feed,
			// group feeds, a single-activity permalink, another theme's
			// template. Lucide is enqueued on none of those — measured
			// `window.lucide === undefined` on the member activity stream — so
			// an icon needing a JS hydrator would be an empty box everywhere
			// the card actually goes. A CSS mask needs nothing but the
			// stylesheet, and `class` is already allowed through kses.
			$doc_inner = '<span class="mvs-activity-doc-icon" aria-hidden="true"><span class="mvs-doc-glyph mvs-doc-glyph-' . esc_attr( '-' . $doc_icon ) . '"></span></span>'
				. '<span class="mvs-activity-doc-info">'
				. '<span class="mvs-activity-doc-title">' . esc_html( $title ) . '</span>'
				. ( $doc_meta ? '<span class="mvs-activity-doc-meta">' . $doc_meta . '</span>' : '' )
				. '</span>';

			$doc_body = $permalink
				? '<a href="' . esc_url( (string) $permalink ) . '">' . $doc_inner . '</a>'
				: $doc_inner;

			return '<div class="mvs-activity-media mvs-activity-media--document"' . $data_mid . '>' . $doc_body . '</div>';
		}

		// Audio gets a compact card that isn't suitable for the canonical
		// thumbnail helper (which targets square-ish grid cells). Keep it local.
		if ( 'audio' === $media_type ) {
			$artist   = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'artist' );
			$duration = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'duration' );
			$sub      = '';
			if ( $artist ) {
				$sub .= esc_html( $artist );
			}
			if ( $duration ) {
				$minutes = floor( (float) $duration / 60 );
				$seconds = (int) $duration % 60;
				$sub    .= ( $sub ? ' &middot; ' : '' ) . sprintf( '%d:%02d', $minutes, $seconds );
			}
			return '<div class="mvs-activity-media mvs-activity-media--audio"' . $data_mid . '><a href="' . esc_url( $href ) . '"><span class="mvs-activity-audio-icon">&#9835;</span><span class="mvs-activity-audio-info"><span class="mvs-activity-audio-title">' . esc_html( $title ) . '</span>' . ( $sub ? '<span class="mvs-activity-audio-meta">' . $sub . '</span>' : '' ) . '</span></a></div>';
		}

		// Images + videos: delegate to the canonical helper so BP activity, the
		// Explore grid, and Pro layouts all share one branching logic (img /
		// native <video> preview / placeholder). `ttl` + `user_id` force the
		// inner <img src> through the same broadcast-TTL signing as $file_url
		// above — otherwise the inner thumbnail would still 403 after 1h even
		// though the outer link href stays valid for a year.
		$inner = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->media_thumbnail(
			$media_id,
			array(
				'size'      => $size,
				'alt'       => esc_attr( $title ),
				'show_play' => true,
				'ttl'       => YEAR_IN_SECONDS,
				'user_id'   => 0,
			)
		);

		// For private/members media, the user_id=0 sign above fails the privacy
		// check and template_helpers returns a placeholder div (no <img>). When
		// a logged-in viewer who CAN see the media lands on a stored-content
		// activity (broadcast cache, late-rendered template), fall back to a
		// per-viewer signed URL so the thumbnail shows. The activity stream's
		// SQL filter (ActivityPrivacyFilter) already prevents the activity from
		// reaching unauthorized viewers; this guard handles the case where the
		// SQL filter passes the activity through but the cached URL was signed
		// for an anonymous viewer. Refresh-on-render handles the short TTL.
		// Originally drafted by Nitin Patil (1.2.1 commit edfc643).
		if ( false !== strpos( $inner, 'mvs-grid-item-placeholder' ) ) {
			$current_user_id = get_current_user_id();
			if ( $current_user_id > 0 && \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( $media_id, $current_user_id ) ) {
				$inner = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->media_thumbnail(
					$media_id,
					array(
						'size'      => $size,
						'alt'       => esc_attr( $title ),
						'show_play' => true,
						'ttl'       => 0,
						'user_id'   => $current_user_id,
					)
				);
			}
		}

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
