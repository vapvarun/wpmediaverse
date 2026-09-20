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
	 * THE one list. It was three lists, then four, and Explore Documents was
	 * added to none of them when it shipped in 2.4.0 — so the page existed, was
	 * created on activation, was linked from the menu, and then rendered with no
	 * plugin CSS at all (a search icon at its viewBox size, 848px square), the
	 * theme's blog sidebar beside it, and no way for the owner to remap it.
	 * Every consumer now reads this instead of writing its own copy.
	 *
	 * @var array<string, string>
	 */
	public const PAGE_SLOT_OPTIONS = array(
		'dashboard'         => 'mvs_page_dashboard',
		'explore'           => 'mvs_page_explore',
		'upload'            => 'mvs_page_upload',
		'explore_documents' => 'mvs_page_explore_documents',
	);

	/**
	 * The stored option name for every page slot.
	 *
	 * @since 2.4.0
	 *
	 * @return string[]
	 */
	public static function page_options(): array {
		return array_values( self::PAGE_SLOT_OPTIONS );
	}

	/**
	 * The configured page id for every slot, skipping the unset ones.
	 *
	 * @since 2.4.0
	 *
	 * @return int[]
	 */
	public static function all_page_ids(): array {
		$ids = array();

		foreach ( array_keys( self::PAGE_SLOT_OPTIONS ) as $slot ) {
			$id = self::get_page_id( $slot );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}

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
		// Default 'large', matching the registered default and the rung grids
		// have served since 1.8.0.
		$size = (string) get_option( 'mvs_thumbnail_size', 'large' );
		if ( ! in_array( $size, self::ALLOWED_THUMBNAIL_SIZES, true ) ) {
			$size = 'large';
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
			$size = 'large';
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
	 * 1.8.0 made grids serve 'large' whatever the setting said, because a 300px
	 * rung upscales and looks soft on HiDPI. That was the right DEFAULT and the
	 * wrong mechanism: it also swallowed the owner's explicit choice, so
	 * Thumbnail Quality could not change anything on the surfaces its own
	 * description named. The crispness now comes from the default being 'large'
	 * (2.5.1), and an owner who picks Medium gets Medium.
	 *
	 * 'full' still maps to 'large': the original file is not a thumbnail rung and
	 * has no business inside a tile.
	 *
	 * @return string One of 'medium', 'large'.
	 */
	public static function get_grid_thumb_size_key(): string {
		$configured = self::get_thumbnail_size();
		$key        = ( 'full' === $configured ) ? 'large' : $configured;

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
	 * feed shows every image at its native aspect ratio (justified rows)
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
		$allowed = self::GRID_LAYOUTS;

		/**
		 * Filter the default grid layout for sites that have not chosen one.
		 *
		 * @since 1.8.0
		 *
		 * @param string $default 'original' (masonry), 'square' (uniform crop)
		 *                        or 'list' (one row per item).
		 */
		$default = (string) apply_filters( 'mvs_default_thumbnail_style', 'original' );
		if ( ! in_array( $default, $allowed, true ) ) {
			$default = 'original';
		}

		$style = (string) get_option( 'mvs_thumbnail_style', $default );
		return in_array( $style, $allowed, true ) ? $style : $default;
	}

	/**
	 * The grid layouts a site may choose.
	 *
	 * 'list' joined the pair in 2.4.2 when the Explore Feed block's dead List
	 * option was made real. An unknown stored value still falls back, so a site
	 * that downgrades keeps working.
	 *
	 * @since 2.4.2
	 * @var string[]
	 */
	public const GRID_LAYOUTS = array( 'square', 'original', 'list' );

	/**
	 * Resolve the layout for one grid, site default plus optional override.
	 *
	 * TWO VOCABULARIES, ONE MAPPING, IN ONE PLACE. The site setting has always
	 * stored 'square' / 'original'; the Explore Feed block's editor control has
	 * always saved 'grid' / 'masonry' / 'list'. Both spellings are in the wild -
	 * the option on every install, the attribute in every saved post - so
	 * neither can be renamed (production rule 2). What can be fixed is having
	 * only one place that knows they are the same three states.
	 *
	 * The site setting is the DEFAULT; a block that sets its own layout
	 * overrides it for that block only. An empty override means inherit, which
	 * is why the block attribute's default had to stop being 'grid': "not set"
	 * and "deliberately grid" must be distinguishable.
	 *
	 * @since 2.4.2
	 *
	 * @param string $override Block-level layout: '', 'grid', 'masonry', 'list',
	 *                         or the stored spellings 'square' / 'original'.
	 * @return string One of GRID_LAYOUTS.
	 */
	public static function resolve_grid_layout( string $override = '' ): string {
		$map = array(
			'grid'     => 'square',
			'masonry'  => 'original',
			'list'     => 'list',
			'square'   => 'square',
			'original' => 'original',
		);

		$override = strtolower( trim( $override ) );

		if ( '' !== $override && isset( $map[ $override ] ) ) {
			return $map[ $override ];
		}

		return self::get_thumbnail_style();
	}

	/**
	 * The CSS modifier for a resolved layout, or '' for the default grid.
	 *
	 * One emitter, so a new layout cannot reach some grids and miss others -
	 * which is exactly what happened to the block's 'masonry', whose class was
	 * bound in one template and styled nowhere.
	 *
	 * @since 2.4.2
	 *
	 * @param string $override Block-level layout override, if any.
	 * @return string '', 'mvs-grid--original' or 'mvs-grid--list'.
	 */
	public static function grid_layout_class( string $override = '' ): string {
		switch ( self::resolve_grid_layout( $override ) ) {
			case 'original':
				return 'mvs-grid--original';
			case 'list':
				return 'mvs-grid--list';
			default:
				return '';
		}
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
