<?php
/**
 * BP Verified Member badge integration.
 *
 * Customer ask (Basecamp #9872031539): the bp-verified-member plugin renders
 * a "verified" badge next to author names in BuddyPress activity, but WPMV's
 * media surfaces don't show the badge. Customers reading a media single page
 * or lightbox can't tell that the uploader is verified.
 *
 * The badge is sourced from bp-verified-member's global instance via
 * `$bp_verified_member->get_user_badge( $user_id )`. We hook it onto MVS's
 * own `mvs_user_display_name` filter — the one
 * TemplateHelpers::get_display_name() already fires — so every surface that
 * renders a display name through the canonical helper picks up the badge
 * automatically.
 *
 * The badge returned by bp-verified-member is trusted HTML (an <img> with
 * srcset pointing at its bundled SVG). It's already escaped for output, so
 * MVS templates that emit the value must use wp_kses_post(), not esc_html().
 *
 * @since 1.2.1
 *
 * @package WPMediaVerse\Integrations\BPVerifiedMember
 */

namespace WPMediaVerse\Integrations\BPVerifiedMember;

defined( 'ABSPATH' ) || exit;

/**
 * Append the verified-member badge to MVS display names.
 */
final class BadgeIntegration {

	/**
	 * Wire both filters. Safe to call on every request — the callbacks
	 * themselves guard against a missing badge helper.
	 *
	 * - `mvs_user_display_name` (existing): used by PHP templates that
	 *   route the author through `TemplateHelpers::get_display_name()`.
	 *   The badge is appended into the name string for backward-compat
	 *   with the 1.2.1 single-media path.
	 *
	 * - `mvs_user_badge_html` (new in 1.2.2): used by REST consumers
	 *   that need the badge as a discrete payload field — e.g. the
	 *   media lightbox renders `author_data.name` as plain text and a
	 *   sibling node for `author_data.badge_html`. Any plugin can
	 *   append its own badge HTML by hooking this filter; the returned
	 *   string is concatenated decorations only (no name).
	 */
	public function init(): void {
		add_filter( 'mvs_user_display_name', array( $this, 'append_badge' ), 10, 2 );
		add_filter( 'mvs_user_badge_html', array( $this, 'append_badge_html' ), 10, 2 );
	}

	/**
	 * Append the verified badge HTML to the display name.
	 *
	 * @param string $name    Current display name. May already contain HTML
	 *                        from other listeners — we always append, never
	 *                        replace.
	 * @param int    $user_id User ID being rendered.
	 * @return string Display name with optional appended badge.
	 */
	public function append_badge( string $name, int $user_id ): string {
		$badge = self::get_verified_badge_html( $user_id );
		if ( '' === $badge ) {
			return $name;
		}

		// Dedupe. bp-verified-member also hooks `bp_core_get_user_displayname`,
		// which `get_the_author_meta('display_name')` flows through on many WP
		// surfaces — so the incoming $name may already carry the badge markup.
		// Without this check the MVS template renders the badge twice.
		if ( false !== strpos( $name, 'bp-verified-badge' ) || false !== strpos( $name, 'bp-unverified-badge' ) ) {
			return $name;
		}

		return $name . ' ' . $badge;
	}

	/**
	 * Append the verified badge HTML to the accumulated badges string.
	 *
	 * Callback for `mvs_user_badge_html`. The filter accumulates decoration
	 * markup across listeners (verified, VIP, role badges, etc.) — each
	 * callback prepends/appends to the running string and returns it.
	 *
	 * @param string $badges_html Current accumulated badge HTML.
	 * @param int    $user_id     User ID being rendered.
	 * @return string Accumulated badge HTML with this listener's contribution.
	 */
	public function append_badge_html( string $badges_html, int $user_id ): string {
		$badge = self::get_verified_badge_html( $user_id );
		if ( '' === $badge ) {
			return $badges_html;
		}

		// Dedupe — see comment on append_badge() above.
		if ( false !== strpos( $badges_html, 'bp-verified-badge' ) || false !== strpos( $badges_html, 'bp-unverified-badge' ) ) {
			return $badges_html;
		}

		return '' === $badges_html ? $badge : $badges_html . ' ' . $badge;
	}

	/**
	 * Read the bp-verified-member badge HTML for a user (or '').
	 *
	 * The bp-verified-member plugin exposes the per-user badge as a method on
	 * the global `$bp_verified_member` instance (set in the plugin's
	 * `bp_include` hook). There is no standalone
	 * `bp_verified_member_get_user_badge()` function — earlier drafts of
	 * this integration assumed there was.
	 *
	 * @param int $user_id User ID being rendered.
	 * @return string Trusted badge HTML, or '' when the plugin is inactive
	 *                or the user isn't verified.
	 */
	private static function get_verified_badge_html( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}

		$bp_verified = $GLOBALS['bp_verified_member'] ?? null;
		if ( ! is_object( $bp_verified ) || ! method_exists( $bp_verified, 'get_user_badge' ) ) {
			return '';
		}

		$badge = $bp_verified->get_user_badge( $user_id );
		return is_string( $badge ) ? $badge : '';
	}
}
