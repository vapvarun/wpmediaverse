<?php
/**
 * Block registrar.
 *
 * Registers all Gutenberg blocks from the build directory.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Registers all WPMediaVerse Gutenberg blocks.
 */
class BlockRegistrar {

	/**
	 * Block names to register.
	 *
	 * @var string[]
	 */
	/**
	 * Registered Gutenberg blocks.
	 *
	 * `story-viewer` is intentionally NOT in this list for 1.2.0 — the
	 * StoryService primitives exist server-side but there's no upload-flow
	 * UI to mark media as a story, no REST endpoint, and no scheduled
	 * expiry cron. Shipping the viewer would surface an empty-by-default
	 * block to customers. Re-enable once the create-flow lands (planned
	 * for 1.2.1; tracked in plan/archive/1.2.0.md "Out of scope" list).
	 *
	 * @var string[]
	 */
	const BLOCKS = array(
		'media-upload',
		'media-grid',
		'media-player',
		'album-viewer',
		'media-stats',
		'explore-feed',
		'lock-overlay',
		'member-photos',
		'pdf-viewer',
	);

	/**
	 * Initialize block registration.
	 */
	public function init(): void {
		add_action( 'init', array( $this, 'register_blocks' ) );
		add_filter( 'block_categories_all', array( $this, 'register_category' ) );
		add_filter( 'block_type_metadata', array( StandardAttributes::class, 'inject' ) );
		MVS_CSS::init();
	}

	/**
	 * Register all blocks.
	 */
	public function register_blocks(): void {
		$build_dir = MVS_PLUGIN_DIR . 'build/blocks/';

		foreach ( self::BLOCKS as $block_name ) {
			$block_path = $build_dir . $block_name;

			if ( file_exists( $block_path . '/block.json' ) ) {
				register_block_type( $block_path );
			}
		}
	}

	/**
	 * Register custom block category.
	 *
	 * @param array $categories Existing categories.
	 * @return array
	 */
	public function register_category( array $categories ): array {
		array_unshift(
			$categories,
			array(
				'slug'  => 'wpmediaverse',
				'title' => __( 'WPMediaVerse', 'wpmediaverse' ),
				'icon'  => 'format-gallery',
			)
		);

		return $categories;
	}
}
