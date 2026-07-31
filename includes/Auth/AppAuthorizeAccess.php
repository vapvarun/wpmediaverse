<?php
/**
 * Keep WordPress core's Application Passwords authorize screen usable for the
 * mobile app, on every site.
 *
 * The MediaVerse app signs in with WP core Application Passwords, and core's
 * authorize screen (`wp-admin/authorize-application.php`) is the interactive
 * door that mints the first one. Two things break that door in the wild:
 *
 * 1. Core renders the return URL through `esc_url()`, which drops any scheme
 *    not in the kses allowlist. `mediaverseapp` is not a core protocol, so
 *    the hidden `success_url` field rendered EMPTY: the member approved and
 *    landed on a page showing a raw password instead of being handed back to
 *    the app. This hit every role on every site — it is why the app has
 *    carried a manual copy-paste fallback.
 *
 * 2. WooCommerce (and membership plugins like it) redirect every user without
 *    `edit_posts` / `manage_woocommerce` / `view_admin_dashboard` away from
 *    all of wp-admin, exempting only admin-post.php and admin-ajax.php. A
 *    plain member on such a site can neither approve the app nor create a
 *    password by hand. Not a capability problem — core allows Application
 *    Passwords for subscribers — only the screen is unreachable.
 *
 * Both filters are scoped to the authorize request itself. A global protocol
 * allowance would make the app scheme linkable in post and comment content
 * site-wide, which is unnecessary surface for a one-screen need. The
 * WooCommerce filter only ever turns the block OFF for that one screen; every
 * other wp-admin script stays blocked.
 *
 * Ported from the Learnomy implementation of the Wbcom App Auth standard.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Scoped repairs that keep core's authorize screen working for members.
 *
 * @since 2.3.0
 */
class AppAuthorizeAccess {

	/**
	 * The core screen that mints a member's first Application Password.
	 */
	private const AUTHORIZE_SCRIPT = 'authorize-application.php';

	/**
	 * Default deep-link scheme the mobile app is registered for.
	 *
	 * A white-labelled build ships its own scheme, which is why this is a
	 * starting value behind a filter and not a hardcoded constant.
	 */
	private const DEFAULT_APP_SCHEME = 'mediaverseapp';

	/**
	 * Wire both filters.
	 */
	public static function init(): void {
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'allow_authorize_screen' ) );
		add_filter( 'kses_allowed_protocols', array( self::class, 'allow_app_scheme' ) );
	}

	/**
	 * Is the current request the core authorize screen?
	 *
	 * Matched on SCRIPT_FILENAME rather than a query arg or referer, because
	 * that is what actually determines which PHP file is executing and is not
	 * something a visitor can spoof into pointing at a different screen.
	 */
	public static function is_authorize_request(): bool {
		if ( ! isset( $_SERVER['SCRIPT_FILENAME'] ) ) {
			return false;
		}

		$script = basename( sanitize_text_field( wp_unslash( $_SERVER['SCRIPT_FILENAME'] ) ) );

		return self::AUTHORIZE_SCRIPT === $script;
	}

	/**
	 * The app's deep-link scheme.
	 *
	 * @return string Lowercase scheme with no separator, e.g. `mediaverseapp`.
	 */
	public static function app_scheme(): string {
		/**
		 * Filter the mobile app's deep-link scheme.
		 *
		 * @since 2.3.0
		 *
		 * @param string $scheme Scheme with no `://` separator.
		 */
		$scheme = (string) apply_filters( 'mvs_app_scheme', self::DEFAULT_APP_SCHEME );

		return strtolower( (string) preg_replace( '/[^a-z0-9+.-]/i', '', $scheme ) );
	}

	/**
	 * Stop WooCommerce redirecting a member away from the authorize screen.
	 *
	 * Only ever turns the block OFF, and only for that one script. It never
	 * turns a block ON, so a site that already allows admin access is
	 * unchanged.
	 *
	 * @param bool $prevent Whether WooCommerce intends to block this request.
	 * @return bool
	 */
	public static function allow_authorize_screen( $prevent ) {
		if ( ! $prevent ) {
			return $prevent;
		}

		return self::is_authorize_request() ? false : $prevent;
	}

	/**
	 * Let `esc_url()` keep the app scheme on the authorize screen.
	 *
	 * Scoped to that request: everywhere else the allowlist is untouched, so
	 * the scheme never becomes linkable in post or comment content.
	 *
	 * @param string[] $protocols Allowed protocols.
	 * @return string[]
	 */
	public static function allow_app_scheme( $protocols ) {
		if ( ! is_array( $protocols ) || ! self::is_authorize_request() ) {
			return $protocols;
		}

		$scheme = self::app_scheme();

		if ( '' !== $scheme && ! in_array( $scheme, $protocols, true ) ) {
			$protocols[] = $scheme;
		}

		return $protocols;
	}
}
