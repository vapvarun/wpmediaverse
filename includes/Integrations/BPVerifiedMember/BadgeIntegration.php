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
	 * Wire the filter. Safe to call on every request — the filter callback
	 * itself guards against a missing badge helper.
	 */
	public function init(): void {
		add_filter( 'mvs_user_display_name', array( $this, 'append_badge' ), 10, 2 );
	}

	/**
	 * Append the verified badge HTML to the display name.
	 *
	 * bp-verified-member exposes the per-user badge as a method on the global
	 * `$bp_verified_member` instance (set in the plugin's `bp_include` hook).
	 * There is no standalone `bp_verified_member_get_user_badge()` function —
	 * earlier drafts of this integration assumed there was. Empty string when
	 * the plugin isn't active or the user isn't verified, so we can append
	 * unconditionally and rely on the empty-check below.
	 *
	 * @param string $name    Current display name. May already contain HTML
	 *                        from other listeners — we always append, never
	 *                        replace.
	 * @param int    $user_id User ID being rendered.
	 * @return string Display name with optional appended badge.
	 */
	public function append_badge( string $name, int $user_id ): string {
		if ( $user_id <= 0 ) {
			return $name;
		}

		$bp_verified = $GLOBALS['bp_verified_member'] ?? null;
		if ( ! is_object( $bp_verified ) || ! method_exists( $bp_verified, 'get_user_badge' ) ) {
			return $name;
		}

		$badge = $bp_verified->get_user_badge( $user_id );
		if ( ! is_string( $badge ) || '' === $badge ) {
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
}
