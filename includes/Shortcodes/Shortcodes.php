<?php
/**
 * Shortcode registrations.
 *
 * Provides shortcode alternatives to Gutenberg blocks for classic editor users.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Shortcodes;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders all WPMediaVerse shortcodes.
 */
class Shortcodes {

	/**
	 * Initialize shortcode registration.
	 */
	public function init(): void {
		add_shortcode( 'mvs_gallery', array( $this, 'render_gallery' ) );
		add_shortcode( 'mvs_upload', array( $this, 'render_upload' ) );
		add_shortcode( 'mvs_album', array( $this, 'render_album' ) );
		add_shortcode( 'mvs_player', array( $this, 'render_player' ) );
		add_shortcode( 'mvs_stats', array( $this, 'render_stats' ) );
	}

	/**
	 * Render the [mvs_gallery] shortcode.
	 *
	 * Usage: [mvs_gallery columns="3" per_page="12" type="image" category="" tag="" orderby="date"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_gallery( $atts ): string {
		$atts = shortcode_atts(
			array(
				'columns'  => 3,
				'per_page' => 12,
				'type'     => '',
				'category' => '',
				'tag'      => '',
				'orderby'  => 'date',
			),
			$atts,
			'mvs_gallery'
		);

		$block_attrs = array(
			'columns'       => absint( $atts['columns'] ),
			'perPage'       => absint( $atts['per_page'] ),
			'mediaType'     => sanitize_text_field( $atts['type'] ),
			'category'      => sanitize_text_field( $atts['category'] ),
			'tag'           => sanitize_text_field( $atts['tag'] ),
			'orderBy'       => sanitize_text_field( $atts['orderby'] ),
			'showLightbox'  => true,
			'showReactions' => true,
			'gap'           => 8,
		);

		return $this->render_block_template( 'media-grid', $block_attrs );
	}

	/**
	 * Render the [mvs_upload] shortcode.
	 *
	 * Usage: [mvs_upload max_files="10" show_privacy="true"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_upload( $atts ): string {
		$atts = shortcode_atts(
			array(
				'max_files'    => 10,
				'show_privacy' => 'true',
			),
			$atts,
			'mvs_upload'
		);

		$block_attrs = array(
			'maxFiles'    => absint( $atts['max_files'] ),
			'showPrivacy' => filter_var( $atts['show_privacy'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'media-upload', $block_attrs );
	}

	/**
	 * Render the [mvs_album] shortcode.
	 *
	 * Usage: [mvs_album id="123" columns="3" show_title="true" show_description="true"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_album( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'               => 0,
				'columns'          => 3,
				'show_title'       => 'true',
				'show_description' => 'true',
			),
			$atts,
			'mvs_album'
		);

		$block_attrs = array(
			'albumId'         => absint( $atts['id'] ),
			'columns'         => absint( $atts['columns'] ),
			'showTitle'       => filter_var( $atts['show_title'], FILTER_VALIDATE_BOOLEAN ),
			'showDescription' => filter_var( $atts['show_description'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'album-viewer', $block_attrs );
	}

	/**
	 * Render the [mvs_player] shortcode.
	 *
	 * Usage: [mvs_player id="123" autoplay="false" loop="false" download="false"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_player( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'autoplay' => 'false',
				'loop'     => 'false',
				'download' => 'false',
			),
			$atts,
			'mvs_player'
		);

		$block_attrs = array(
			'mediaId'      => absint( $atts['id'] ),
			'autoplay'     => filter_var( $atts['autoplay'], FILTER_VALIDATE_BOOLEAN ),
			'loop'         => filter_var( $atts['loop'], FILTER_VALIDATE_BOOLEAN ),
			'showDownload' => filter_var( $atts['download'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'media-player', $block_attrs );
	}

	/**
	 * Render the [mvs_stats] shortcode.
	 *
	 * Usage: [mvs_stats views="true" downloads="true" reactions="true" top="true" top_count="5"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_stats( $atts ): string {
		$atts = shortcode_atts(
			array(
				'views'     => 'true',
				'downloads' => 'true',
				'reactions' => 'true',
				'top'       => 'true',
				'top_count' => 5,
			),
			$atts,
			'mvs_stats'
		);

		$block_attrs = array(
			'showViews'     => filter_var( $atts['views'], FILTER_VALIDATE_BOOLEAN ),
			'showDownloads' => filter_var( $atts['downloads'], FILTER_VALIDATE_BOOLEAN ),
			'showReactions' => filter_var( $atts['reactions'], FILTER_VALIDATE_BOOLEAN ),
			'showTopMedia'  => filter_var( $atts['top'], FILTER_VALIDATE_BOOLEAN ),
			'topCount'      => absint( $atts['top_count'] ),
		);

		return $this->render_block_template( 'media-stats', $block_attrs );
	}

	/**
	 * Render a block's server-side template with given attributes.
	 *
	 * @param string $block_name Block name (without namespace).
	 * @param array  $attributes Block attributes.
	 * @return string Rendered HTML.
	 */
	private function render_block_template( string $block_name, array $attributes ): string {
		$allowed = array( 'media-grid', 'media-upload', 'album-viewer', 'media-player', 'media-stats' );
		if ( ! in_array( $block_name, $allowed, true ) ) {
			return '';
		}

		$template = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/render.php';

		if ( ! file_exists( $template ) ) {
			$template = MVS_PLUGIN_DIR . 'src/blocks/' . $block_name . '/render.php';
		}

		// Ensure frontend styles are loaded.
		wp_enqueue_style( 'mvs-frontend' );

		if ( ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		// Make $attributes available to the template.
		$content = '';
		$block   = null;
		include $template;
		return ob_get_clean();
	}
}
