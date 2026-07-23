<?php
/**
 * Community-wide REST privacy gate.
 *
 * MediaVerse's own routes enforce PER-ITEM privacy (a private album/media checks
 * access), but many read routes are intentionally public (`permission_callback`
 * is `__return_true`) so a public gallery works logged-out. That is correct for a
 * standalone MediaVerse.
 *
 * When MediaVerse runs inside a community that the owner has marked PRIVATE, that
 * assumption flips: nothing should be readable logged-out, and per-item privacy is
 * not enough because public items are still enumerable. MediaVerse has no
 * community-privacy setting of its own — the community layer (BuddyNext) owns
 * that. So this gate is default-OPEN and driven entirely by two filters the
 * community layer sets:
 *
 *   - `mvs_rest_require_auth`  (bool, default false) — is the whole mvs/v1 surface
 *                              login-only right now?
 *   - `mvs_rest_can_access`    (bool, default is_user_logged_in()) — may THIS
 *                              visitor through? Lets a membership plugin refine
 *                              "logged in" to "logged in AND a member".
 *
 * BuddyNext maps these to its existing `buddynext_private_community` setting and
 * its `buddynext_private_community_can_access` seam, so one toggle gates BuddyNext
 * AND MediaVerse without MediaVerse depending on BuddyNext or gaining a duplicate
 * setting. Standalone MediaVerse is unaffected: nobody sets the filter, so the
 * gate never fires.
 *
 * @package WPMediaVerse\REST
 */

namespace WPMediaVerse\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks mvs/v1 for unauthorised visitors when the host community is private.
 */
class CommunityPrivacyGate {

	/**
	 * Wire the pre-dispatch gate.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate' ), 10, 3 );
	}

	/**
	 * Return 401 for any mvs/v1 route when the community is private and the
	 * visitor is not authorised.
	 *
	 * Runs before the controller callback, so no data is assembled for a blocked
	 * request. Only the mvs/v1 namespace is touched; other plugins' namespaces are
	 * left to their own gates.
	 *
	 * @param mixed            $result  Pre-dispatch short-circuit (WP_Error/response) or null.
	 * @param mixed            $server  REST server (unused).
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed Unchanged result, or a 401 WP_Error for a gated route.
	 */
	public static function gate( $result, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- $server is part of the rest_pre_dispatch signature.
		if ( null !== $result || ! ( $request instanceof \WP_REST_Request ) ) {
			return $result;
		}

		// Default false: standalone MediaVerse has no community-wide privacy gate.
		if ( ! apply_filters( 'mvs_rest_require_auth', false, $request ) ) {
			return $result;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/mvs/v1/' ) ) {
			return $result;
		}

		// The community layer decides who counts as authorised (default: logged in).
		if ( (bool) apply_filters( 'mvs_rest_can_access', is_user_logged_in(), $request ) ) {
			return $result;
		}

		return new \WP_Error(
			'mvs_community_private',
			__( 'This community is private. Please log in to view it.', 'wpmediaverse' ),
			array( 'status' => 401 )
		);
	}
}
