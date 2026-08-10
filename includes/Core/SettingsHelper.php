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
	 * Allowed thumbnail-size values (registered in SettingsRegistrar).
	 *
	 * @var string[]
	 */
	private const ALLOWED_THUMBNAIL_SIZES = array( 'medium', 'large', 'full' );

	/**
	 * Resolve the configured thumbnail size used for grids/feeds.
	 *
	 * Returns the registered `mvs_thumbnail_size` option, validated against
	 * the registered enum (medium/large/full) so a corrupted DB value never
	 * propagates downstream. Filterable via `mvs_thumbnail_size_resolved`
	 * for themes/Pro that need to switch quality on a per-context basis
	 * (e.g. high-DPI feed, video poster strip).
	 *
	 * @return string One of 'medium', 'large', 'full'.
	 */
	public static function get_thumbnail_size(): string {
		// Default 'medium' to match the registered setting default — grid/feed
		// tiles render at ~150-300px, so 'large' (1024px) was wasted bytes. (1.7.0)
		$size = (string) get_option( 'mvs_thumbnail_size', 'medium' );
		if ( ! in_array( $size, self::ALLOWED_THUMBNAIL_SIZES, true ) ) {
			$size = 'medium';
		}

		/**
		 * Filter the resolved thumbnail size.
		 *
		 * Themes / Pro layouts may downgrade or upgrade the size based on
		 * context. Filtered value is re-validated against the registered enum.
		 *
		 * @param string $size One of 'medium', 'large', 'full'.
		 */
		$size = (string) apply_filters( 'mvs_thumbnail_size_resolved', $size );
		if ( ! in_array( $size, self::ALLOWED_THUMBNAIL_SIZES, true ) ) {
			$size = 'medium';
		}

		return $size;
	}

	/**
	 * Resolve the configured grid/feed size to a /serve thumbnail size key.
	 *
	 * The get_thumbnail_size() enum is medium|large|full, but the signed-URL +
	 * serve_thumbnail vocabulary is medium|large|thumbnail. 'full'
	 * (the original file) is not a thumbnail rung and is wasteful inside a tile,
	 * so it maps to 'large'. Every grid/feed thumbnail URL builder routes
	 * through this so the mvs_thumbnail_size setting actually takes effect — it
	 * previously did not, because grids hardcoded 'large' and never called
	 * get_thumbnail_size() at all.
	 *
	 * @since 1.7.0
	 *
	 * Updated 1.8.0: grid/masonry tiles render large (up to ~half the viewport on
	 * a 2-column masonry), so the 'medium' (300px) rung visibly upscales and looks
	 * soft on HiDPI/retina screens. Serve 'large' (1024px) for the grid by default
	 * so tiles stay crisp at retina density; byte-conscious sites can drop back to
	 * 'medium' with the mvs_grid_thumb_size_key filter. The configured
	 * mvs_thumbnail_size is passed to the filter so it can still drive the choice.
	 *
	 * @return string One of 'medium', 'large'.
	 */
	public static function get_grid_thumb_size_key(): string {
		$configured = self::get_thumbnail_size();
		$key        = ( 'medium' === $configured || 'full' === $configured ) ? 'large' : $configured;

		/**
		 * Filter the thumbnail rung used for grid/masonry tiles.
		 *
		 * @since 1.8.0
		 *
		 * @param string $key        Resolved rung ('medium' or 'large').
		 * @param string $configured The mvs_thumbnail_size setting value.
		 */
		$key = (string) apply_filters( 'mvs_grid_thumb_size_key', $key, $configured );
		return in_array( $key, array( 'medium', 'large' ), true ) ? $key : 'large';
	}

	/**
	 * Resolve the explore/grid thumbnail style.
	 *
	 * The default flipped square -> original in 1.8.0 so the explore + media-grid
	 * feed shows every image at its native aspect ratio (Pinterest-style masonry)
	 * instead of a center-cropped square. A site that prefers the old uniform
	 * square crop can restore it in one line, without touching the setting:
	 *
	 *     add_filter( 'mvs_default_thumbnail_style', static fn() => 'square' );
	 *
	 * (Production Rule #3 escape hatch.) An explicitly saved option always wins
	 * over the filtered default. register_setting only runs on admin_init, so the
	 * front-end relies on this resolved default — route every grid read here.
	 *
	 * @since 1.8.0
	 *
	 * @return string 'square' or 'original'.
	 */
	public static function get_thumbnail_style(): string {
		$allowed = array( 'square', 'original' );

		/**
		 * Filter the default grid thumbnail style for sites that have not chosen one.
		 *
		 * @since 1.8.0
		 *
		 * @param string $default 'original' (masonry) or 'square' (uniform crop).
		 */
		$default = (string) apply_filters( 'mvs_default_thumbnail_style', 'original' );
		if ( ! in_array( $default, $allowed, true ) ) {
			$default = 'original';
		}

		$style = (string) get_option( 'mvs_thumbnail_style', $default );
		return in_array( $style, $allowed, true ) ? $style : $default;
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

	/**
	 * The maximum upload size in bytes, already filtered.
	 *
	 * Exists so Pro's document ingest reads this the same way Free's media
	 * ingest does. Pro reading `get_option( 'mvs_max_upload_size' )` itself is an
	 * architecture violation (A4) for a good reason rather than a stylistic one:
	 * two readers of one setting drift, and the one that forgets to apply
	 * `mvs_max_upload_size` silently enforces a different ceiling from the one
	 * the site owner configured.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id User the limit applies to.
	 * @return int Bytes.
	 */
	public static function get_max_upload_size( int $user_id = 0 ): int {
		$max_size = (int) get_option( 'mvs_max_upload_size', 104857600 );

		/** This filter is documented in includes/Services/UploadService.php */
		return (int) apply_filters( 'mvs_max_upload_size', $max_size, $user_id );
	}

	/**
	 * The privacy a newly uploaded media item lands on.
	 *
	 * The third of media's three upload settings to gain a code-level override.
	 * Size and allowed types already had one — `mvs_max_upload_size` and
	 * `mvs_allowed_file_types` — and privacy was the odd one out, read straight
	 * from the option at two call sites with no way for a site to change it
	 * without a settings write.
	 *
	 * Introduced alongside the document library's `mvs_document_default_privacy`
	 * so the two features are uniform. They stay SEPARATE on purpose: a photo is
	 * posted and a document is private until shared, so one control answering for
	 * both is how an owner publishes files they thought were private.
	 *
	 * Purely additive — the option still decides where a site has set one, so no
	 * shipped install changes behaviour.
	 *
	 * @since 2.4.0
	 *
	 * @return string Privacy slug.
	 */
	public static function get_default_privacy(): string {
		$privacy = (string) get_option( 'mvs_default_privacy', 'public' );

		/**
		 * The privacy a new upload is created with.
		 *
		 * @since 2.4.0
		 *
		 * @param string $privacy Privacy slug from the site's settings.
		 */
		return (string) apply_filters( 'mvs_default_privacy', $privacy );
	}
}
