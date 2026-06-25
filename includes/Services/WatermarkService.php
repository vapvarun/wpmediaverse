<?php
/**
 * Watermark service (Pro stub).
 *
 * Provides watermarking hooks and preview image generation for gated media.
 * The actual watermark rendering is deferred to Pro via filters.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;


/**
 * Watermark service — generates watermarked preview images for gated content.
 */
class WatermarkService {

	/**
	 * Watermark position constants.
	 *
	 * @var string
	 */
	const POSITION_CENTER       = 'center';
	const POSITION_BOTTOM_RIGHT = 'bottom-right';
	const POSITION_BOTTOM_LEFT  = 'bottom-left';
	const POSITION_TOP_RIGHT    = 'top-right';
	const POSITION_TOP_LEFT     = 'top-left';
	const POSITION_TILE         = 'tile';

	/**
	 * Valid positions.
	 *
	 * @var string[]
	 */
	const POSITIONS = array( 'center', 'bottom-right', 'bottom-left', 'top-right', 'top-left', 'tile' );

	/**
	 * Access rules service.
	 *
	 * @var AccessRulesService
	 */
	private $access_rules;

	/**
	 * Per-request cache for preview URLs.
	 *
	 * @var array<int, string>
	 */
	private $preview_cache = array();

	/**
	 * Constructor.
	 *
	 * @param AccessRulesService $access_rules Access rules service.
	 */
	public function __construct( AccessRulesService $access_rules ) {
		$this->access_rules = $access_rules;
	}

	/**
	 * Initialize watermark hooks.
	 */
	public function init(): void {
		// Filter media REST response to add preview_url for gated images.
		add_filter( 'mvs_media_response', array( $this, 'add_preview_url' ), 30, 2 );
	}

	/**
	 * Check if watermarking is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = (bool) get_option( 'mvs_watermark_enabled', false );

		/**
		 * Filter whether watermarking is enabled.
		 *
		 * The option value is read first, then passed through this filter
		 * so Pro or third-party code can override if needed.
		 *
		 * @param bool $enabled Whether watermarking is enabled.
		 */
		return (bool) apply_filters( 'mvs_watermark_enabled', $enabled );
	}

	/**
	 * Get the watermark configuration.
	 *
	 * The image watermark is stored by the admin UI as a Media Library
	 * attachment ID under `mvs_watermark_image_id`. Pro's Watermarker consumes
	 * `image_id`; `image_url` is resolved from the same attachment for any
	 * third-party consumer of that key (empty string when no image is set).
	 *
	 * @return array{type: string, text: string, image_id: int, image_url: string, position: string, opacity: int, font_size: int, color: string}
	 */
	public function get_config(): array {
		$image_id = (int) get_option( 'mvs_watermark_image_id', 0 );

		$defaults = array(
			'type'      => get_option( 'mvs_watermark_type', 'text' ),
			'text'      => get_option( 'mvs_watermark_text', get_bloginfo( 'name' ) ),
			'image_id'  => $image_id,
			'image_url' => $image_id > 0 ? (string) wp_get_attachment_url( $image_id ) : '',
			'position'  => get_option( 'mvs_watermark_position', self::POSITION_BOTTOM_RIGHT ),
			'opacity'   => (int) get_option( 'mvs_watermark_opacity', 40 ),
			'font_size' => (int) get_option( 'mvs_watermark_font_size', 24 ),
			'color'     => get_option( 'mvs_watermark_color', '#ffffff' ),
		);

		/**
		 * Filter the watermark configuration.
		 *
		 * @param array $config Watermark configuration.
		 */
		return apply_filters( 'mvs_watermark_config', $defaults );
	}

	/**
	 * Get or generate a watermarked preview URL for a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @return string Preview URL, or empty string if not applicable.
	 */
	public function get_preview_url( int $media_id ): string {
		if ( isset( $this->preview_cache[ $media_id ] ) ) {
			return $this->preview_cache[ $media_id ];
		}

		// Only watermark images.
		$file_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
		if ( ! $file_type || 0 !== strpos( $file_type, 'image/' ) ) {
			$this->preview_cache[ $media_id ] = '';
			return '';
		}

		// Only watermark gated media.
		if ( ! $this->access_rules->has_active_rules( $media_id ) ) {
			$this->preview_cache[ $media_id ] = '';
			return '';
		}

		// Check for cached watermarked file.
		$cached_url = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'watermark_url' );
		if ( $cached_url ) {
			$this->preview_cache[ $media_id ] = $cached_url;
			return $cached_url;
		}

		// Short-circuit when the source file isn't reachable on local disk
		// (e.g. cloud-stored media, deleted file). Pro's Watermarker fails
		// gracefully on its own, but skipping here avoids the round trip.
		if ( null === \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_filesystem_path( $media_id ) ) {
			$this->preview_cache[ $media_id ] = '';
			return '';
		}

		// Pro's Watermarker filter contract: relative `file_path` (composed
		// against {uploads}/wpmediaverse/ on the Pro side). Pass the raw
		// stored value — the filter argument is never emitted to a browser.
		$file_path = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'file_path' );
		$file_url  = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_raw( $media_id, 'file_url' );
		$config    = $this->get_config();

		/**
		 * Filter to generate a watermarked preview image.
		 *
		 * Pro plugins should hook here to generate the watermarked image and return its URL.
		 * The generated image should be stored persistently (e.g., alongside the original).
		 *
		 * @param string $preview_url Generated preview URL. Empty string if not generated.
		 * @param int    $media_id    Media post ID.
		 * @param string $file_path   Relative file path.
		 * @param string $file_url    Original file URL.
		 * @param array  $config      Watermark configuration.
		 */
		$preview_url = apply_filters( 'mvs_generate_watermark', '', $media_id, $file_path, $file_url, $config );

		if ( $preview_url ) {
			// Persist the raw URL returned by Pro's Watermarker, then
			// re-fetch via get() so the caller emits the signed variant
			// (Phase 0a item 3 — watermark_url always-signed at the data layer).
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'watermark_url', $preview_url );
			$preview_url = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'watermark_url' );
		}

		$this->preview_cache[ $media_id ] = $preview_url;

		return $preview_url;
	}

	/**
	 * Invalidate the cached watermark for a media item.
	 *
	 * Call this when the original file or watermark settings change.
	 *
	 * @param int $media_id Media post ID.
	 */
	public function invalidate( int $media_id ): void {
		\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete( $media_id, 'watermark_url' );
		unset( $this->preview_cache[ $media_id ] );

		/**
		 * Fires when a watermark is invalidated.
		 *
		 * Pro plugins should hook here to delete the cached watermarked file.
		 *
		 * @param int $media_id Media post ID.
		 */
		do_action( 'mvs_watermark_invalidated', $media_id );
	}

	/**
	 * Invalidate all cached watermarks (e.g., when settings change).
	 */
	public function invalidate_all(): void {
		delete_metadata( 'post', 0, '_mvs_watermark_url', '', true );
		$this->preview_cache = array();

		/**
		 * Fires when all watermarks are invalidated.
		 */
		do_action( 'mvs_watermarks_invalidated_all' );
	}

	/**
	 * Add preview_url to media REST response for gated images.
	 *
	 * Hooks into mvs_media_response at priority 30 (after signed URL filter at 10).
	 *
	 * @param array $data     Response data.
	 * @param int   $media_id Media post ID.
	 * @return array Modified response data.
	 */
	public function add_preview_url( array $data, int $media_id ): array {
		if ( ! $this->is_enabled() ) {
			return $data;
		}

		$preview_url = $this->get_preview_url( $media_id );

		if ( $preview_url ) {
			$data['preview_url'] = $preview_url;
			$data['watermarked'] = true;
		}

		return $data;
	}

	/**
	 * Check if a media item has a watermarked preview.
	 *
	 * @param int $media_id Media post ID.
	 * @return bool
	 */
	public function has_preview( int $media_id ): bool {
		return ! empty( $this->get_preview_url( $media_id ) );
	}
}
