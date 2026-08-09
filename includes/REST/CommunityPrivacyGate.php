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
 * Routes whose credential travels WITH the request (the HMAC-signed `/serve`
 * media route, token-authenticated webhooks) are exempt via
 * `mvs_rest_gate_exempt_route_prefixes` — a cookie-session gate is the wrong
 * auth model for them and blocks legitimate callers (see gate() body).
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
	 * Wire the pre-dispatch gate and the page-layer gate.
	 *
	 * The template_redirect@3 hook runs before Pro's compete loader (@4) and
	 * Free's TemplateLoader (@5), so a gated visitor is redirected before any
	 * MVS template can emit media markup.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'rest_pre_dispatch', array( self::class, 'gate' ), 10, 3 );
		add_action( 'template_redirect', array( self::class, 'gate_page' ), 3 );
	}

	/**
	 * Redirect unauthorised visitors off MediaVerse's server-rendered pages
	 * while the host community is private.
	 *
	 * The REST gate alone cannot seal the surface: the explore archive,
	 * profile and single-media pages are server-rendered with working signed
	 * media URLs in the HTML, so a logged-out visitor would read a private
	 * community's media without ever touching a gated JSON route.
	 *
	 * @return void
	 */
	public static function gate_page(): void {
		if ( ! self::page_needs_login() ) {
			return;
		}

		global $wp;
		$current = home_url( '/' . ltrim( (string) ( $wp->request ?? '' ), '/' ) );

		/**
		 * Filter the login destination for visitors turned away from a private
		 * community's MediaVerse pages.
		 *
		 * Defaults to wp-login.php with a return-to. A community layer with its
		 * own auth surface (BuddyNext's /login/ hub) points this at that page,
		 * matching where its native gated pages send guests.
		 *
		 * @since 2.3.2
		 *
		 * @param string $login_url Destination URL.
		 * @param string $current   The URL the visitor asked for.
		 */
		$login_url = (string) apply_filters( 'mvs_community_login_url', wp_login_url( $current ), $current );

		nocache_headers();
		wp_safe_redirect( $login_url );
		exit;
	}

	/**
	 * Should the current front-end request be redirected to login?
	 *
	 * Same two community filters as the REST gate; scope is MediaVerse's own
	 * server-rendered surfaces only (virtual routes, the mapped explore page,
	 * album/collection singles). Blocks placed by the owner on arbitrary WP
	 * pages are deliberately NOT gated here — redirecting a homepage because
	 * it embeds a media grid would take the whole site hostage; their DATA is
	 * still behind the REST gate, and their server-rendered tiles are the
	 * owner's explicit choice of public placement.
	 *
	 * @return bool
	 */
	public static function page_needs_login(): bool {
		// Default false: standalone MediaVerse has no community-wide privacy gate.
		if ( ! apply_filters( 'mvs_rest_require_auth', false, null ) ) {
			return false;
		}
		if ( (bool) apply_filters( 'mvs_rest_can_access', is_user_logged_in(), null ) ) {
			return false;
		}

		$is_gated_page = (bool) (
			get_query_var( 'mvs_media_slug' )
			|| get_query_var( 'mvs_media_archive' )
			|| get_query_var( 'mvs_profile_user' )
			|| get_query_var( 'mvs_edit_profile' )
			|| is_singular( array( 'mvs_album', 'mvs_collection' ) )
		);

		if ( ! $is_gated_page ) {
			$explore_page_id = (int) get_option( 'mvs_page_explore', 0 );
			if ( $explore_page_id && is_page( $explore_page_id ) ) {
				$is_gated_page = true;
			}
		}

		/**
		 * Declare additional pages gated by the private community.
		 *
		 * Pro puts its compete pages (/media/battles/ etc.) behind the same
		 * gate via this filter; a host can add its own surfaces.
		 *
		 * @since 2.3.2
		 *
		 * @param bool $is_gated_page Whether the current request is a gated MVS page.
		 */
		return (bool) apply_filters( 'mvs_community_gated_page', $is_gated_page );
	}

	/**
	 * Return 401 for any mvs/v1 route when the community is private and the
	 * visitor is not authorised.
	 *
	 * Runs before the controller callback, so no data is assembled for a blocked
	 * request. Only the prefixes returned by `mvs_rest_gated_route_prefixes` are
	 * touched (Free's own namespace by default; Pro appends its namespace via the
	 * filter). Third-party namespaces are left to their own gates.
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

		/**
		 * Route prefixes covered by the private-community gate.
		 *
		 * Defaults to Free's own namespace. Pro appends `/mvs-pro/v1/` via this
		 * filter (same product, same privacy expectations - e.g. public
		 * tournament-bracket reads must not leak on a private community).
		 * Third-party namespaces stay out; they own their own gates.
		 *
		 * @since 2.2.0
		 *
		 * @param string[]         $prefixes Gated route prefixes.
		 * @param \WP_REST_Request $request  Current request.
		 */
		$prefixes = (array) apply_filters( 'mvs_rest_gated_route_prefixes', array( '/mvs/v1/' ), $request );

		$route = (string) $request->get_route();
		$gated = false;
		foreach ( $prefixes as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $route, (string) $prefix ) ) {
				$gated = true;
				break;
			}
		}
		if ( ! $gated ) {
			return $result;
		}

		/**
		 * Route prefixes EXEMPT from the private-community gate.
		 *
		 * For routes whose credential is carried by the request itself rather
		 * than the cookie session. `/serve` is the built-in case: browsers
		 * fetch it as a subresource (<img>, <video>) which sends cookies but
		 * can never send X-WP-Nonce, and core anonymizes cookie-authed REST
		 * requests without a nonce — so a session gate 401s every media file
		 * for members and guests alike. The route's real auth is the HMAC
		 * signature on the URL, validated in serve_file() together with a
		 * per-item privacy re-check; signed URLs are only minted through
		 * surfaces the gate (or the host community's page gate) covers.
		 *
		 * The pre-signed-URL tradeoff is deliberate: a signed link to a
		 * PUBLIC item keeps working for whoever holds it, exactly like an S3
		 * pre-signed URL. A site wanting a harder line can remove the
		 * exemption here; other plugins with token-authenticated routes
		 * (e.g. a payment webhook) append theirs.
		 *
		 * `/app/config` is the second built-in case, added in 2.4.0, and it is a
		 * BOOTSTRAP problem rather than a policy one: that route is where a
		 * native client learns how to sign in — the `auth` block naming the
		 * connect URL and whether password login is available. Gating it makes
		 * private-community mode unenterable from an app: the client needs the
		 * config to authenticate, and needed to authenticate to read the config.
		 *
		 * Found while building P4.4's ETag check, where an armed gate returned
		 * 401 to an anonymous config request.
		 *
		 * It carries no member content — branding, feature flags, legal URLs, the
		 * auth block and the document capability list, all of which a login
		 * screen needs before anyone has logged in. A site wanting the harder
		 * line can drop it through this same filter.
		 *
		 * @since 2.3.2
		 *
		 * @param string[]         $exempt  Exempt route prefixes.
		 * @param \WP_REST_Request $request Current request.
		 */
		$exempt = (array) apply_filters(
			'mvs_rest_gate_exempt_route_prefixes',
			array( '/mvs/v1/serve', '/mvs/v1/app/config' ),
			$request
		);

		foreach ( $exempt as $prefix ) {
			$prefix = (string) $prefix;

			// Match on a PATH BOUNDARY, not a bare string prefix. A plain
			// strpos() makes every exemption wider than the route it names:
			// `/mvs/v1/app/config` would also exempt a future
			// `/mvs/v1/app/configuration`, silently, and an exemption that grows
			// on its own is how a privacy gate develops a hole nobody added.
			// Caught by the test written for the 2.4.0 config exemption.
			$matches = ( '' !== $prefix )
				&& ( $route === $prefix || 0 === strpos( $route, rtrim( $prefix, '/' ) . '/' ) );

			if ( $matches ) {
				return $result;
			}
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
