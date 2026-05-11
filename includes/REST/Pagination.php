<?php
/**
 * REST pagination helper — single clamp for per_page across every controller.
 *
 * WordPress REST schema validates `'maximum'` only when there is no
 * `sanitize_callback`. WPMediaVerse uses `'sanitize_callback' => 'absint'`
 * everywhere (canonical Wbcom pattern), which silently disables schema
 * validation of bounds. This helper provides the missing clamp so a
 * malicious caller cannot pass `per_page=999999` and DoS the server with
 * a 100k-row hydration loop.
 *
 * Every REST list endpoint MUST call `Pagination::resolve_per_page()`
 * instead of reading `per_page` directly. Coding rule #5 in
 * `bin/coding-rules-check.sh` enforces it.
 *
 * @since 1.2.1
 *
 * @package WPMediaVerse\REST
 */

namespace WPMediaVerse\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Static utility — no instance state.
 */
final class Pagination {

	/**
	 * Hard cap on per_page across all list endpoints. Filterable so a site
	 * owner can tune for their hardware (Redis-backed sites can comfortably
	 * raise to 200; budget shared hosts should drop to 50).
	 */
	public const DEFAULT_MAX = 100;

	/**
	 * Resolve a clamped, sanitized per_page value from a REST request.
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @param int              $default Default when not set or non-positive.
	 * @return int Clamped value in [1, max].
	 */
	public static function resolve_per_page( $request, int $default = 20 ): int {
		/**
		 * Filter the global per_page cap for every list endpoint.
		 *
		 * Identical filter name as the schema `'maximum'` declarations so
		 * raising it once raises both the schema bound and the runtime clamp.
		 *
		 * @since 1.1.0
		 *
		 * @param int $max Maximum per_page.
		 */
		$max = (int) apply_filters( 'mvs_rest_pagination_max', self::DEFAULT_MAX );
		if ( $max < 1 ) {
			$max = self::DEFAULT_MAX;
		}

		$value = (int) $request->get_param( 'per_page' );
		if ( $value <= 0 ) {
			$value = $default;
		}

		if ( $value > $max ) {
			$value = $max;
		}
		if ( $value < 1 ) {
			$value = 1;
		}

		return $value;
	}

	/**
	 * Resolve a clamped page number (always ≥ 1).
	 *
	 * @param \WP_REST_Request $request Incoming request.
	 * @return int Page number ≥ 1.
	 */
	public static function resolve_page( $request ): int {
		$page = (int) $request->get_param( 'page' );
		return $page > 0 ? $page : 1;
	}
}
