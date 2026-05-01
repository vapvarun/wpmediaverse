<?php
/**
 * Settings helper — canonical accessor for paired-plugin settings access.
 *
 * Pro plugins, themes, and integrations should resolve Free-owned settings
 * through this helper instead of calling `get_option()` directly. This keeps
 * Free as the single source of truth for option name, default, and shape, so
 * a future rename or restructure on the Free side does not silently break
 * downstream consumers.
 *
 * Architecture contract: this satisfies invariant A4 (no direct option reads
 * across the plugin boundary) for the page-id family of settings.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Static accessor for cross-plugin settings reads.
 */
class SettingsHelper {

	/**
	 * Map of supported page slots to their stored option names.
	 *
	 * Slots match the three pages registered in
	 * `Admin\Settings\SettingsRegistrar::register_pages_settings()`. Adding a
	 * new page setting means: register it there + add it here.
	 *
	 * @var array<string, string>
	 */
	private const PAGE_SLOT_OPTIONS = array(
		'dashboard' => 'mvs_page_dashboard',
		'explore'   => 'mvs_page_explore',
		'upload'    => 'mvs_page_upload',
	);

	/**
	 * Resolve a configured page id by slot.
	 *
	 * Filterable via `mvs_page_id_{$slot}` so themes and Pro can override the
	 * stored value (useful for staging a new layout, A/B testing, etc.) without
	 * touching the database.
	 *
	 * @param string $slot     One of 'dashboard', 'explore', 'upload'.
	 * @param int    $default  Fallback page id when no setting / unknown slot.
	 * @return int Resolved page id, 0 when none.
	 */
	public static function get_page_id( string $slot, int $default = 0 ): int {
		if ( ! isset( self::PAGE_SLOT_OPTIONS[ $slot ] ) ) {
			return $default;
		}

		$page_id = (int) get_option( self::PAGE_SLOT_OPTIONS[ $slot ], $default );

		/**
		 * Filter the resolved page id for a given slot.
		 *
		 * @param int    $page_id Stored page id.
		 * @param string $slot    Page slot ('dashboard'|'explore'|'upload').
		 */
		$page_id = (int) apply_filters( "mvs_page_id_{$slot}", $page_id, $slot );

		return max( 0, $page_id );
	}

	/**
	 * Get the list of supported page slots.
	 *
	 * @return string[]
	 */
	public static function get_page_slots(): array {
		return array_keys( self::PAGE_SLOT_OPTIONS );
	}

	/**
	 * Resolve the OpenAI API key.
	 *
	 * Reads from the registered `mvs_openai_api_key` option, then runs the
	 * `mvs_openai_api_key` filter (so site owners can override via constant /
	 * env var), and finally falls back to the `MVS_OPENAI_API_KEY` constant
	 * if defined. This is the same chain `Services\OpenAIProvider` uses
	 * internally — Pro callers (Whisper transcription, Vision providers) MUST
	 * resolve the key through this helper instead of `get_option()` directly,
	 * so a future option rename or constant-name change stays single-sourced.
	 *
	 * @return string The configured API key, or '' when not set.
	 */
	public static function get_openai_api_key(): string {
		$key = (string) get_option( 'mvs_openai_api_key', '' );

		/**
		 * Filter the OpenAI API key after it is loaded from options.
		 *
		 * Mirrors Services\OpenAIProvider::get_api_key() — kept here so cross-
		 * plugin readers go through the same filter chain.
		 *
		 * @param string $key API key from options.
		 */
		$key = (string) apply_filters( 'mvs_openai_api_key', $key );

		if ( '' === $key && defined( 'MVS_OPENAI_API_KEY' ) ) {
			$key = (string) MVS_OPENAI_API_KEY;
		}

		return $key;
	}
}
