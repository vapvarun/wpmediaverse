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
	const BLOCKS = array(
		'media-upload',
		'media-grid',
		'media-player',
		'album-viewer',
		'story-viewer',
		'media-stats',
		'explore-feed',
		'lock-overlay',
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
