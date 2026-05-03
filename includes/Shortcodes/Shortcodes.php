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
		add_shortcode( 'mvs_profile_edit', array( $this, 'render_profile_edit' ) );
		add_shortcode( 'mvs_explore_feed', array( $this, 'render_explore_feed' ) );
		add_shortcode( 'mvs_lock_overlay', array( $this, 'render_lock_overlay' ) );
		add_shortcode( 'mvs_member_photos', array( $this, 'render_member_photos' ) );
		// [mvs_story_viewer] intentionally NOT registered — the story
		// create-flow (upload-form toggle + REST endpoint + expiry cron)
		// lands in 1.2.1; until then a story-viewer shortcode would
		// always render empty.
	}

	/**
	 * Render the [mvs_gallery] shortcode.
	 *
	 * Usage: [mvs_gallery type="image" category="" tag="" orderby="date"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_gallery( $atts ): string {
		$atts = shortcode_atts(
			array(
				'type'     => '',
				'category' => '',
				'tag'      => '',
				'orderby'  => 'date',
				'user_id'  => 0,
			),
			$atts,
			'mvs_gallery'
		);

		// Resolve user filter:
		// - explicit user_id="123" attribute wins,
		// - else fall back to BuddyPress displayed member if running inside
		// a BP template (matches the mvs/member-photos block's auto-detect),
		// - else 0 = no user filter.
		$mvs_resolved_user_id = absint( $atts['user_id'] );
		if ( ! $mvs_resolved_user_id && function_exists( 'bp_displayed_user_id' ) ) {
			$bp_user_id = (int) bp_displayed_user_id();
			if ( $bp_user_id > 0 ) {
				$mvs_resolved_user_id = $bp_user_id;
			}
		}

		// Columns and per_page always come from backend settings — shortcode
		// attributes must not override admin-configured display values.
		$block_attrs = array(
			'columns'       => absint( get_option( 'mvs_grid_columns', 3 ) ),
			'perPage'       => absint( get_option( 'mvs_items_per_page', 12 ) ),
			'mediaType'     => sanitize_text_field( $atts['type'] ),
			'category'      => sanitize_text_field( $atts['category'] ),
			'tag'           => sanitize_text_field( $atts['tag'] ),
			'orderBy'       => sanitize_text_field( $atts['orderby'] ),
			'showLightbox'  => true,
			'showReactions' => true,
			'gap'           => 8,
			'userId'        => $mvs_resolved_user_id,
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

		ob_start();

		/**
		 * Fires before the upload form is rendered.
		 *
		 * Pro uses this to display the quota usage widget.
		 *
		 * @since 1.1.0
		 */
		do_action( 'mvs_before_upload_form' );

		echo $this->render_block_template( 'media-upload', $block_attrs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		return ob_get_clean();
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
	 * Render the [mvs_explore_feed] shortcode.
	 *
	 * Usage: [mvs_explore_feed layout="grid" columns="3" per_page="12" filters="true" search="true"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_explore_feed( $atts ): string {
		$atts = shortcode_atts(
			array(
				'layout'   => 'grid',
				'columns'  => 3,
				'per_page' => 12,
				'filters'  => 'true',
				'search'   => 'true',
			),
			$atts,
			'mvs_explore_feed'
		);

		$block_attrs = array(
			'layout'      => sanitize_text_field( $atts['layout'] ),
			'columns'     => absint( $atts['columns'] ),
			'perPage'     => absint( $atts['per_page'] ),
			'showFilters' => filter_var( $atts['filters'], FILTER_VALIDATE_BOOLEAN ),
			'showSearch'  => filter_var( $atts['search'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'explore-feed', $block_attrs );
	}

	/**
	 * Render the [mvs_lock_overlay] shortcode.
	 *
	 * Usage: [mvs_lock_overlay id="123" blur="20" overlay_opacity="60" unlock_label="Restricted Content"]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_lock_overlay( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'              => 0,
				'blur'            => 20,
				'overlay_opacity' => 60,
				'unlock_label'    => '',
			),
			$atts,
			'mvs_lock_overlay'
		);

		$block_attrs = array(
			'mediaId'        => absint( $atts['id'] ),
			'blurAmount'     => absint( $atts['blur'] ),
			'overlayOpacity' => absint( $atts['overlay_opacity'] ),
			'unlockLabel'    => sanitize_text_field( $atts['unlock_label'] ),
		);

		return $this->render_block_template( 'lock-overlay', $block_attrs );
	}

	/**
	 * Render the [mvs_member_photos] shortcode.
	 *
	 * Usage: [mvs_member_photos user_id="123" columns="3" per_page="12" type="image" show_header="true"]
	 *
	 * Auto-detect order when user_id is empty (matches the block):
	 *   1. Explicit user_id attribute.
	 *   2. BuddyPress displayed member.
	 *   3. Post author on a single-author page.
	 *   4. Current logged-in user.
	 *   5. Empty state.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_member_photos( $atts ): string {
		$atts = shortcode_atts(
			array(
				'user_id'     => 0,
				'columns'     => 3,
				'per_page'    => 12,
				'type'        => '',
				'show_header' => 'true',
				'actions'     => 'true',
			),
			$atts,
			'mvs_member_photos'
		);

		$block_attrs = array(
			'userId'      => absint( $atts['user_id'] ),
			'columns'     => absint( $atts['columns'] ),
			'perPage'     => absint( $atts['per_page'] ),
			'mediaType'   => sanitize_text_field( $atts['type'] ),
			'showHeader'  => filter_var( $atts['show_header'], FILTER_VALIDATE_BOOLEAN ),
			'showActions' => filter_var( $atts['actions'], FILTER_VALIDATE_BOOLEAN ),
		);

		return $this->render_block_template( 'member-photos', $block_attrs );
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
			wp_enqueue_style( 'mvs-frontend' );
			if ( wp_script_is( 'mvs-lucide', 'registered' ) ) {
				wp_enqueue_script( 'mvs-lucide' );
			}
			$return_url = get_permalink();
			$login_url  = wp_login_url( $return_url );
			$signup_url = function_exists( 'wc_registration_url' )
				? wc_registration_url( $return_url )
				: wp_registration_url();

			ob_start();
			?>
			<div class="mvs-auth-gate">
				<div class="mvs-auth-gate__card">
					<div class="mvs-auth-gate__glyph" aria-hidden="true">
						<i data-lucide="layout-dashboard"></i>
					</div>
					<h2 class="mvs-auth-gate__title">
						<?php esc_html_e( 'Your creative space awaits', 'wpmediaverse' ); ?>
					</h2>
					<p class="mvs-auth-gate__lede">
						<?php esc_html_e( 'Sign in to manage your uploads, curate albums, track stats, and follow the creators you love.', 'wpmediaverse' ); ?>
					</p>
					<ul class="mvs-auth-gate__benefits">
						<li>
							<span class="mvs-auth-gate__benefit-icon" aria-hidden="true">
								<i data-lucide="folder-open"></i>
							</span>
							<span><?php esc_html_e( 'Organize uploads in albums &amp; collections', 'wpmediaverse' ); ?></span>
						</li>
						<li>
							<span class="mvs-auth-gate__benefit-icon" aria-hidden="true">
								<i data-lucide="heart"></i>
							</span>
							<span><?php esc_html_e( 'Save favorites and follow creators', 'wpmediaverse' ); ?></span>
						</li>
						<li>
							<span class="mvs-auth-gate__benefit-icon" aria-hidden="true">
								<i data-lucide="bar-chart-3"></i>
							</span>
							<span><?php esc_html_e( 'Track views, reactions and comments', 'wpmediaverse' ); ?></span>
						</li>
					</ul>
					<div class="mvs-auth-gate__actions">
						<a class="mvs-btn mvs-btn--primary mvs-auth-gate__primary" href="<?php echo esc_url( $login_url ); ?>">
							<?php esc_html_e( 'Log in to continue', 'wpmediaverse' ); ?>
						</a>
						<?php if ( get_option( 'users_can_register' ) && $signup_url ) : ?>
							<a class="mvs-auth-gate__secondary" href="<?php echo esc_url( $signup_url ); ?>">
								<?php
								printf(
									/* translators: %s: emphasised "Sign up free" link text. */
									esc_html__( 'No account yet? %s', 'wpmediaverse' ),
									'<strong>' . esc_html__( 'Create one — it\'s free.', 'wpmediaverse' ) . '</strong>'
								);
								?>
							</a>
						<?php endif; ?>
					</div>
				</div>
			</div>
			<?php
			return (string) ob_get_clean();
		}

		wp_enqueue_style( 'mvs-frontend' );

		// Enqueue Interactivity API stores.
		wp_enqueue_script_module(
			'@mvs/shared-ui',
			MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',
			array(
				array(
					'id'     => '@wordpress/interactivity',
					'import' => 'static',
				),
			),
			MVS_VERSION
		);

		wp_enqueue_script_module(
			'mvs-dashboard-view',
			MVS_PLUGIN_URL . 'src/blocks/dashboard-view/view.js',
			array(
				array(
					'id'     => '@wordpress/interactivity',
					'import' => 'static',
				),
			),
			MVS_VERSION
		);

		$mvs_dash_ctx = array(
			'restUrl'  => esc_url_raw( rest_url( 'mvs/v1/' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'userId'   => get_current_user_id(),
			'mediaUrl' => esc_url( home_url( '/media/' ) ),
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
		$allowed = array( 'media-grid', 'media-upload', 'album-viewer', 'media-player', 'media-stats', 'explore-feed', 'lock-overlay', 'member-photos' );
		if ( ! in_array( $block_name, $allowed, true ) ) {
			return '';
		}

		$template = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/render.php';

		if ( ! file_exists( $template ) ) {
			$template = MVS_PLUGIN_DIR . 'build/blocks/' . $block_name . '/render.php';
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
			$asset      = file_exists( $asset_file ) ? require $asset_file : array(
				'dependencies' => array(),
				'version'      => filemtime( $block_view ),
			);
			wp_enqueue_script_module(
				'mvs-block-' . $block_name . '-view',
				MVS_PLUGIN_URL . 'src/blocks/' . $block_name . '/view.js',
				array(
					array(
						'id'     => '@wordpress/interactivity',
						'import' => 'static',
					),
				),
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

		$container = \WPMediaVerse\Core\Plugin::container();
		$service   = $container->get( 'collections' );
		$type      = $service->get_type( $collection_id );
		$media_ids = array();

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
			if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
				continue;
			}
			$status = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'status' );
			if ( 'publish' !== $status ) {
				continue;
			}
			$title      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'title' );
			$media_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'media_type' ) ?: 'image';
			$permalink  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( $media_id );
			$sc_su      = \WPMediaVerse\Core\Plugin::container()->get( 'signed_urls' );
			$thumb_url  = $sc_su
				? $sc_su->generate_thumbnail( $media_id, get_current_user_id(), 'large' )
				: \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_thumb_url( $media_id, 'large' );

			$output .= '<div class="mvs-grid-item">';
			$output .= '<a href="' . esc_url( $permalink ) . '" class="mvs-grid-item-link">';
			if ( $thumb_url ) {
				$output .= '<img src="' . esc_url( $thumb_url ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" />';
			} else {
				$output .= '<div class="mvs-grid-placeholder mvs-grid-placeholder--' . esc_attr( $media_type ) . '">'
					. esc_html( strtoupper( $media_type ) ) . '</div>';
			}
			$output .= '</a>';
			$output .= '<div class="mvs-grid-item-title">' . esc_html( $title ?: __( '(Untitled)', 'wpmediaverse' ) ) . '</div>';
			$output .= '</div>';
		}

		$output .= '</div>';

		return $output;
	}

	/**
	 * Render the [mvs_profile_edit] shortcode.
	 *
	 * Usage: [mvs_profile_edit]
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_profile_edit( $atts ): string {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'Please log in to edit your profile.', 'wpmediaverse' ) . '</p>';
		}

		wp_enqueue_style( 'mvs-frontend' );

		$mvs_profile_asset_file = MVS_PLUGIN_DIR . 'build/blocks/profile-edit/view.asset.php';
		$mvs_profile_asset      = file_exists( $mvs_profile_asset_file )
			? require $mvs_profile_asset_file
			: array(
				'dependencies' => array(
					array(
						'id'     => '@wordpress/interactivity',
						'import' => 'static',
					),
				),
				'version'      => MVS_VERSION,
			);
		wp_enqueue_script_module(
			'mvs-profile-edit',
			MVS_PLUGIN_URL . 'assets/js/profile-edit.js',
			$mvs_profile_asset['dependencies'],
			$mvs_profile_asset['version']
		);

		$mvs_user       = wp_get_current_user();
		$mvs_user_id    = $mvs_user->ID;
		$mvs_avatar_url = get_avatar_url( $mvs_user_id, array( 'size' => 150 ) );

		$mvs_container  = \WPMediaVerse\Core\Plugin::container();
		$mvs_has_custom = false;
		if ( $mvs_container->has( 'profile' ) ) {
			$mvs_profile_svc = $mvs_container->get( 'profile' );
			$mvs_has_custom  = $mvs_profile_svc->has_custom_avatar( $mvs_user_id );
		}

		$mvs_profile_ctx = array(
			'restUrl'         => esc_url_raw( rest_url( 'mvs/v1/' ) ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'userId'          => $mvs_user_id,
			'firstName'       => $mvs_user->first_name,
			'lastName'        => $mvs_user->last_name,
			'displayName'     => $mvs_user->display_name,
			'bio'             => $mvs_user->description,
			'avatarUrl'       => $mvs_avatar_url,
			'hasCustomAvatar' => $mvs_has_custom,
			'saving'          => false,
			'uploadingAvatar' => false,
			'savedMessage'    => '',
			'errorMessage'    => '',
		);

		ob_start();
		?>
		<div class="mvs-profile-edit"
			data-wp-interactive="mvs/profile-edit"
			<?php echo wp_interactivity_data_wp_context( $mvs_profile_ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>

			<h2><?php esc_html_e( 'Edit Profile', 'wpmediaverse' ); ?></h2>

			<div class="mvs-profile-message mvs-profile-message--success"
				data-wp-bind--hidden="!context.savedMessage"
				data-wp-text="context.savedMessage"></div>
			<div class="mvs-profile-message mvs-profile-message--error"
				data-wp-bind--hidden="!context.errorMessage"
				data-wp-text="context.errorMessage"></div>

			<div class="mvs-profile-avatar-section">
				<div class="mvs-profile-avatar-preview">
					<img data-wp-bind--src="context.avatarUrl"
						alt="<?php esc_attr_e( 'Your avatar', 'wpmediaverse' ); ?>"
						width="150" height="150" class="mvs-profile-avatar-img" />
				</div>
				<div class="mvs-profile-avatar-actions">
					<label class="mvs-btn mvs-btn--secondary mvs-profile-avatar-upload-label">
						<span data-wp-bind--hidden="context.uploadingAvatar"><?php esc_html_e( 'Change Avatar', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!context.uploadingAvatar"><?php esc_html_e( 'Uploading...', 'wpmediaverse' ); ?></span>
						<input type="file"
							accept="image/jpeg,image/png,image/gif,image/webp"
							class="mvs-profile-avatar-input"
							data-wp-on--change="actions.uploadAvatar" />
					</label>
					<button type="button"
						class="mvs-btn mvs-btn--text mvs-profile-avatar-remove"
						data-wp-bind--hidden="!context.hasCustomAvatar"
						data-wp-on--click="actions.deleteAvatar">
						<?php esc_html_e( 'Remove (use Gravatar)', 'wpmediaverse' ); ?>
					</button>
					<p class="mvs-profile-avatar-hint">
						<?php esc_html_e( 'JPEG, PNG, GIF, or WebP. Max 2 MB.', 'wpmediaverse' ); ?>
					</p>
				</div>
			</div>

			<form class="mvs-profile-form" data-wp-on--submit="actions.saveProfile">
				<div class="mvs-profile-field">
					<label for="mvs-first-name"><?php esc_html_e( 'First Name', 'wpmediaverse' ); ?></label>
					<input type="text" id="mvs-first-name"
						data-wp-bind--value="context.firstName"
						data-wp-on--input="actions.updateFirstName"
						autocomplete="given-name" />
				</div>

				<div class="mvs-profile-field">
					<label for="mvs-last-name"><?php esc_html_e( 'Last Name', 'wpmediaverse' ); ?></label>
					<input type="text" id="mvs-last-name"
						data-wp-bind--value="context.lastName"
						data-wp-on--input="actions.updateLastName"
						autocomplete="family-name" />
				</div>

				<div class="mvs-profile-field">
					<label for="mvs-display-name"><?php esc_html_e( 'Display Name', 'wpmediaverse' ); ?></label>
					<input type="text" id="mvs-display-name"
						data-wp-bind--value="context.displayName"
						data-wp-on--input="actions.updateDisplayName"
						autocomplete="nickname" />
				</div>

				<div class="mvs-profile-field">
					<label for="mvs-bio"><?php esc_html_e( 'Bio', 'wpmediaverse' ); ?></label>
					<textarea id="mvs-bio" rows="4" maxlength="500"
						data-wp-on--input="actions.updateBio"
						data-wp-text="context.bio"></textarea>
				</div>

				<div class="mvs-profile-actions">
					<button type="submit" class="mvs-btn mvs-btn--primary"
						data-wp-bind--disabled="context.saving">
						<span data-wp-bind--hidden="context.saving"><?php esc_html_e( 'Save Changes', 'wpmediaverse' ); ?></span>
						<span data-wp-bind--hidden="!context.saving"><?php esc_html_e( 'Saving...', 'wpmediaverse' ); ?></span>
					</button>
				</div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
