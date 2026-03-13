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
		add_shortcode( 'mvs_dashboard', array( $this, 'render_dashboard' ) );
		add_shortcode( 'mvs_collection', array( $this, 'render_collection' ) );
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
	 * Render the [mvs_dashboard] shortcode.
	 *
	 * Usage: [mvs_dashboard]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_dashboard( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to access your media dashboard.', 'wpmediaverse' ) . '</p>';
		}

		wp_enqueue_style( 'mvs-frontend' );

		// Enqueue Interactivity API stores.
		$shared_asset_file = MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php';
		$shared_asset      = file_exists( $shared_asset_file ) ? require $shared_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
		wp_enqueue_script_module(
			'mvs-shared-ui',
			MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
			$shared_asset['dependencies'],
			$shared_asset['version']
		);

		$dash_asset_file = MVS_PLUGIN_DIR . 'build/blocks/dashboard-view/view.asset.php';
		$dash_asset      = file_exists( $dash_asset_file ) ? require $dash_asset_file : array( 'dependencies' => array(), 'version' => MVS_VERSION );
		wp_enqueue_script_module(
			'mvs-dashboard-view',
			MVS_PLUGIN_URL . 'build/blocks/dashboard-view/view.js',
			$dash_asset['dependencies'],
			$dash_asset['version']
		);

		$mvs_dash_ctx = array(
			'restUrl'  => esc_url_raw( rest_url( 'mvs/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'mediaUrl' => esc_url( get_post_type_archive_link( 'mvs_media' ) ),
		);

		ob_start();
		include MVS_PLUGIN_DIR . 'templates/partials/dashboard-content.php';
		return ob_get_clean();
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

		// Ensure frontend + block styles are loaded.
		wp_enqueue_style( 'mvs-frontend' );
		$block_style = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/style-index.css';
		if ( file_exists( $block_style ) ) {
			wp_enqueue_style(
				'mvs-block-' . $block_name,
				MVS_PLUGIN_URL . 'build/blocks/' . $block_name . '/style-index.css',
				array(),
				filemtime( $block_style )
			);
		}
		// Enqueue the block's view script (Interactivity API store).
		$block_view = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/view.js';
		if ( file_exists( $block_view ) ) {
			$asset_file = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/view.asset.php';
			$asset      = file_exists( $asset_file ) ? require $asset_file : array( 'dependencies' => array(), 'version' => filemtime( $block_view ) );
			wp_enqueue_script_module(
				'mvs-block-' . $block_name . '-view',
				MVS_PLUGIN_URL . 'build/blocks/' . $block_name . '/view.js',
				$asset['dependencies'],
				$asset['version']
			);
		}

		if ( ! file_exists( $template ) ) {
			return '';
		}

		ob_start();
		// Make $attributes available to the template.
		$content = '';
		$block   = null;
		// Flag so render.php can skip get_block_wrapper_attributes() (causes warning outside block context).
		$mvs_shortcode_context = true;
		include $template;
		return ob_get_clean();
	}

	/**
	 * Render the [mvs_collection] shortcode.
	 *
	 * Usage: [mvs_collection id="123" columns="3" per_page="20"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_collection( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'columns'  => 3,
				'per_page' => 20,
			),
			$atts,
			'mvs_collection'
		);

		$collection_id = (int) $atts['id'];
		if ( ! $collection_id ) {
			return '<p>' . esc_html__( 'Please provide a collection ID: [mvs_collection id="123"]', 'wpmediaverse' ) . '</p>';
		}

		$post = get_post( $collection_id );
		if ( ! $post || 'mvs_collection' !== $post->post_type || 'publish' !== $post->post_status ) {
			return '<p>' . esc_html__( 'Collection not found.', 'wpmediaverse' ) . '</p>';
		}

		$container  = \WPMediaVerse\Core\Plugin::container();
		$service    = $container->get( 'collections' );
		$type       = $service->get_type( $collection_id );
		$media_ids  = array();

		if ( 'smart' === $type ) {
			$resolved  = $service->resolve( $collection_id, (int) $atts['per_page'], 1 );
			$media_ids = array_column( $resolved['items'], 'media_id' );
		} else {
			global $wpdb;
			$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id FROM {$wpdb->prefix}mvs_favorites WHERE collection_id = %d ORDER BY created_at DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$collection_id,
					(int) $atts['per_page']
				)
			);
		}

		if ( empty( $media_ids ) ) {
			return '<p class="mvs-no-media">' . esc_html__( 'No media in this collection.', 'wpmediaverse' ) . '</p>';
		}

		wp_enqueue_style( 'mvs-frontend' );

		$columns = (int) $atts['columns'];
		$output  = '<div class="mvs-media-grid mvs-collection-embed" style="--mvs-grid-cols: ' . $columns . '">';

		foreach ( $media_ids as $media_id ) {
			$media_post = get_post( $media_id );
			if ( ! $media_post || 'publish' !== $media_post->post_status ) {
				continue;
			}
			$file_url   = get_post_meta( $media_id, '_mvs_file_url', true );
			$media_type = get_post_meta( $media_id, '_mvs_media_type', true ) ?: 'image';
			$permalink  = get_permalink( $media_id );

			$output .= '<div class="mvs-grid-item">';
			$output .= '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link">';
			if ( $file_url && 'image' === $media_type ) {
				$output .= '<img src="' . esc_url( $file_url ) . '" alt="' . esc_attr( $media_post->post_title ) . '" loading="lazy" />';
			} else {
				$output .= '<div class="mvs-grid-placeholder mvs-grid-placeholder--' . esc_attr( $media_type ) . '">'
					. esc_html( strtoupper( $media_type ) ) . '</div>';
			}
			$output .= '</a>';
			$output .= '<div class="mvs-grid-item-title">' . esc_html( $media_post->post_title ?: __( '(Untitled)', 'wpmediaverse' ) ) . '</div>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}
}
