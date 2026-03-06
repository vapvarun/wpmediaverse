<?php
/**
 * Admin overview / dashboard page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Overview page — the landing page under WPMediaVerse admin menu.
 *
 * Shows at-a-glance stats, quick links, recent uploads, and system status
 * so a site owner can understand plugin health immediately.
 */
class OverviewPage {

	const PAGE_SLUG = 'mvs-overview';

	/**
	 * Constructor. Registers the admin submenu.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 5 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Enqueue shared admin CSS on all WPMediaVerse admin pages.
	 *
	 * @param string $hook_suffix The current admin page.
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Enqueue on any mvs_media admin page or our custom pages.
		$is_mvs_page = (
			'mvs_media' === $screen->post_type ||
			'mvs_album' === $screen->post_type ||
			false !== strpos( $screen->id, 'mvs-overview' ) ||
			false !== strpos( $screen->id, 'mvs-settings' ) ||
			false !== strpos( $screen->id, 'mvs-moderation' ) ||
			false !== strpos( $screen->id, 'mvs-stats' )
		);

		if ( $is_mvs_page ) {
			wp_enqueue_style(
				'mvs-admin',
				MVS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				MVS_VERSION
			);
		}
	}

	/**
	 * Add the Overview page as the first submenu under the mvs_media CPT.
	 *
	 * Uses priority 5 on admin_menu to register before other submenus.
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'edit.php?post_type=mvs_media',
			__( 'WPMediaVerse Overview', 'wpmediaverse' ),
			__( 'Overview', 'wpmediaverse' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the overview page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$stats        = $this->get_stats();
		$recent_media = $this->get_recent_media();
		$system_info  = $this->get_system_info();
		?>
		<div class="wrap">
			<div class="mvs-page-header">
				<h1><?php esc_html_e( 'WPMediaVerse', 'wpmediaverse' ); ?></h1>
				<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
			</div>
			<p class="description">
				<?php esc_html_e( 'Your media sharing platform at a glance.', 'wpmediaverse' ); ?>
			</p>

			<?php $this->render_getting_started(); ?>

			<?php // --- Stat Cards --- ?>
			<div class="mvs-admin-stats">
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media' ) ); ?>" class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_media'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Total Media', 'wpmediaverse' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_album' ) ); ?>" class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_albums'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Albums', 'wpmediaverse' ); ?></span>
				</a>
				<?php
				$pending_class = $stats['pending_moderation'] > 0 ? 'mvs-stat-card--danger' : 'mvs-stat-card--success';
				?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media&page=mvs-moderation' ) ); ?>" class="mvs-stat-card <?php echo esc_attr( $pending_class ); ?>">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $stats['pending_moderation'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Pending Review', 'wpmediaverse' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media&page=mvs-stats' ) ); ?>" class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $stats['total_views'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Total Views', 'wpmediaverse' ); ?></span>
				</a>
				<div class="mvs-stat-card mvs-stat-card--accent">
					<span class="mvs-stat-number"><?php echo esc_html( $stats['storage_used'] ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Storage Used', 'wpmediaverse' ); ?></span>
				</div>
			</div>

			<?php // --- Main Content Grid --- ?>
			<div class="mvs-admin-columns mvs-admin-columns--2">

				<?php // --- Left Column --- ?>
				<div>
					<?php // Quick Links Widget ?>
					<div class="mvs-admin-widget">
						<div class="mvs-widget-header">
							<h2><?php esc_html_e( 'Quick Links', 'wpmediaverse' ); ?></h2>
						</div>
						<div class="mvs-widget-body">
							<div class="mvs-quick-links">
								<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mvs_media' ) ); ?>" class="button button-primary">
									<span class="dashicons dashicons-plus-alt2"></span>
									<?php esc_html_e( 'Add Media', 'wpmediaverse' ); ?>
								</a>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media&page=mvs-settings' ) ); ?>" class="button">
									<span class="dashicons dashicons-admin-generic"></span>
									<?php esc_html_e( 'Settings', 'wpmediaverse' ); ?>
								</a>
								<?php if ( current_user_can( 'moderate_mvs_media' ) ) : ?>
									<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media&page=mvs-moderation' ) ); ?>" class="button">
										<span class="dashicons dashicons-shield"></span>
										<?php esc_html_e( 'Moderation', 'wpmediaverse' ); ?>
										<?php if ( $stats['pending_moderation'] > 0 ) : ?>
											<span class="mvs-badge"><?php echo esc_html( $stats['pending_moderation'] ); ?></span>
										<?php endif; ?>
									</a>
								<?php endif; ?>
								<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media&page=mvs-stats' ) ); ?>" class="button">
									<span class="dashicons dashicons-chart-bar"></span>
									<?php esc_html_e( 'Stats', 'wpmediaverse' ); ?>
								</a>
							</div>
						</div>
					</div>

					<?php // Frontend Pages Widget ?>
					<div class="mvs-admin-widget" style="margin-top:20px;">
						<div class="mvs-widget-header">
							<h2><?php esc_html_e( 'Frontend Pages', 'wpmediaverse' ); ?></h2>
						</div>
						<div class="mvs-widget-body">
							<?php
							$pages = array(
								'mvs_page_explore'   => array(
									'label' => __( 'Explore Page', 'wpmediaverse' ),
									'icon'  => 'dashicons-images-alt2',
									'code'  => '[mvs_gallery]',
								),
								'mvs_page_upload'    => array(
									'label' => __( 'Upload Page', 'wpmediaverse' ),
									'icon'  => 'dashicons-upload',
									'code'  => '[mvs_upload]',
								),
								'mvs_page_dashboard' => array(
									'label' => __( 'My Media Page', 'wpmediaverse' ),
									'icon'  => 'dashicons-admin-users',
									'code'  => '[mvs_dashboard]',
								),
							);

							$all_ok = true;
							?>
							<ul class="mvs-status-list">
								<?php foreach ( $pages as $option_key => $page_info ) : ?>
									<?php
									$page_id  = (int) get_option( $option_key, 0 );
									$page_url = $page_id ? get_permalink( $page_id ) : '';
									$exists   = $page_id > 0 && 'publish' === get_post_status( $page_id );
									if ( ! $exists ) {
										$all_ok = false;
									}
									?>
									<li>
										<span class="mvs-status-label">
											<span class="dashicons <?php echo esc_attr( $page_info['icon'] ); ?>" style="font-size:16px;width:16px;height:16px;margin-right:4px;vertical-align:text-bottom;"></span>
											<?php echo esc_html( $page_info['label'] ); ?>
										</span>
										<span class="mvs-status-value">
											<?php if ( $exists && $page_url ) : ?>
												<a href="<?php echo esc_url( $page_url ); ?>" target="_blank" class="mvs-status-ok" style="text-decoration:none;">
													<?php esc_html_e( 'Active', 'wpmediaverse' ); ?> &#8599;
												</a>
											<?php else : ?>
												<span class="mvs-status-bad"><?php esc_html_e( 'Missing', 'wpmediaverse' ); ?></span>
											<?php endif; ?>
										</span>
									</li>
								<?php endforeach; ?>
							</ul>

							<?php if ( ! $all_ok ) : ?>
								<div class="notice notice-warning inline" style="margin-top:12px;">
									<p>
										<?php esc_html_e( 'Some pages are missing. Deactivate and reactivate the plugin to create them, or add pages manually with shortcodes:', 'wpmediaverse' ); ?>
									</p>
									<ul style="list-style:disc;margin:4px 0 0 1.5em;">
										<?php foreach ( $pages as $option_key => $page_info ) : ?>
											<?php
											$page_id = (int) get_option( $option_key, 0 );
											if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
												continue;
											}
											?>
											<li><code><?php echo esc_html( $page_info['code'] ); ?></code> &mdash; <?php echo esc_html( $page_info['label'] ); ?></li>
										<?php endforeach; ?>
									</ul>
								</div>
							<?php endif; ?>
						</div>
					</div>
				</div>

				<?php // --- Right Column --- ?>
				<div>
					<?php // Recent Uploads Widget ?>
					<div class="mvs-admin-widget">
						<div class="mvs-widget-header">
							<h2><?php esc_html_e( 'Recent Uploads', 'wpmediaverse' ); ?></h2>
						</div>
						<div class="mvs-widget-body mvs-widget-body--flush">
							<?php if ( empty( $recent_media ) ) : ?>
								<div class="mvs-empty-state">
									<span class="dashicons dashicons-format-image"></span>
									<p><?php esc_html_e( 'No media uploaded yet.', 'wpmediaverse' ); ?></p>
									<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mvs_media' ) ); ?>" class="button button-primary">
										<?php esc_html_e( 'Upload First Media', 'wpmediaverse' ); ?>
									</a>
								</div>
							<?php else : ?>
								<table class="mvs-recent-table">
									<thead>
										<tr>
											<th style="width:56px;">&nbsp;</th>
											<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
											<th><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
											<th><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $recent_media as $item ) : ?>
											<?php
											$thumb_id  = (int) get_post_meta( $item->ID, '_mvs_attachment_id', true );
											$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
											$edit_url  = get_edit_post_link( $item->ID, 'raw' );
											$view_url  = get_permalink( $item->ID );
											$link_url  = $edit_url ? $edit_url : $view_url;
											?>
											<tr>
												<td>
													<?php if ( $thumb_url ) : ?>
														<img src="<?php echo esc_url( $thumb_url ); ?>"
															alt="<?php echo esc_attr( $item->post_title ); ?>"
															class="mvs-thumb"
															loading="lazy" />
													<?php else : ?>
														<span class="mvs-thumb-placeholder">
															<span class="dashicons dashicons-format-image"></span>
														</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( $link_url ) : ?>
														<a href="<?php echo esc_url( $link_url ); ?>">
															<?php echo esc_html( $item->post_title ? $item->post_title : __( '(no title)', 'wpmediaverse' ) ); ?>
														</a>
													<?php else : ?>
														<?php echo esc_html( $item->post_title ? $item->post_title : __( '(no title)', 'wpmediaverse' ) ); ?>
													<?php endif; ?>
												</td>
												<td><?php echo esc_html( get_the_author_meta( 'display_name', $item->post_author ) ); ?></td>
												<td>
													<time datetime="<?php echo esc_attr( $item->post_date ); ?>">
														<?php echo esc_html( human_time_diff( strtotime( $item->post_date ), current_time( 'timestamp' ) ) ); ?>
														<?php esc_html_e( 'ago', 'wpmediaverse' ); ?>
													</time>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
						<div class="mvs-widget-footer">
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mvs_media' ) ); ?>">
								<?php esc_html_e( 'View all media', 'wpmediaverse' ); ?> &rarr;
							</a>
						</div>
					</div>

					<?php // System Status Widget ?>
					<div class="mvs-admin-widget" style="margin-top:20px;">
						<div class="mvs-widget-header">
							<h2><?php esc_html_e( 'System Status', 'wpmediaverse' ); ?></h2>
						</div>
						<div class="mvs-widget-body">
							<ul class="mvs-status-list">
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'PHP Version', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value <?php echo version_compare( PHP_VERSION, '7.4', '>=' ) ? 'mvs-status-ok' : 'mvs-status-bad'; ?>">
										<?php echo esc_html( PHP_VERSION ); ?>
									</span>
								</li>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'WordPress', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value mvs-status-ok"><?php echo esc_html( get_bloginfo( 'version' ) ); ?></span>
								</li>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'Upload Limit', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value"><?php echo esc_html( size_format( (int) get_option( 'mvs_max_upload_size', 104857600 ) ) ); ?></span>
								</li>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'Storage Driver', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value"><?php echo esc_html( ucfirst( get_option( 'mvs_storage_driver', 'local' ) ) ); ?></span>
								</li>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'AI Provider', 'wpmediaverse' ); ?></span>
									<?php
									$ai_key = get_option( 'mvs_openai_api_key', '' );
									if ( defined( 'MVS_OPENAI_API_KEY' ) && MVS_OPENAI_API_KEY ) {
										$ai_key = MVS_OPENAI_API_KEY;
									}
									?>
									<span class="mvs-status-value <?php echo $ai_key ? 'mvs-status-ok' : 'mvs-status-warn'; ?>">
										<?php echo $ai_key ? esc_html__( 'Configured', 'wpmediaverse' ) : esc_html__( 'Not set', 'wpmediaverse' ); ?>
									</span>
								</li>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'BuddyPress', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value <?php echo $system_info['buddypress'] ? 'mvs-status-ok' : ''; ?>">
										<?php echo $system_info['buddypress'] ? esc_html__( 'Active', 'wpmediaverse' ) : esc_html__( 'Inactive', 'wpmediaverse' ); ?>
									</span>
								</li>
							</ul>
						</div>
					</div>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Render a getting-started notice if the plugin has no media yet.
	 */
	private function render_getting_started(): void {
		$total = (int) wp_count_posts( 'mvs_media' )->publish;
		if ( $total > 0 ) {
			return;
		}
		?>
		<div class="mvs-getting-started">
			<h3><?php esc_html_e( 'Welcome to WPMediaVerse!', 'wpmediaverse' ); ?></h3>
			<ol>
				<li><?php esc_html_e( 'Configure your settings (upload limits, privacy defaults, allowed file types).', 'wpmediaverse' ); ?></li>
				<li><?php esc_html_e( 'Upload your first media item via the Upload page or Add New in the admin.', 'wpmediaverse' ); ?></li>
				<li><?php esc_html_e( 'Create albums to organize your media into collections.', 'wpmediaverse' ); ?></li>
				<li><?php esc_html_e( 'Share the Explore page URL with your community!', 'wpmediaverse' ); ?></li>
			</ol>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Data helpers
	// -------------------------------------------------------------------------

	/**
	 * Gather at-a-glance counts.
	 *
	 * @return array{total_media:int, total_albums:int, pending_moderation:int, total_views:int, storage_used:string}
	 */
	private function get_stats(): array {
		$cache_key = 'mvs_overview_stats';
		$cached    = wp_cache_get( $cache_key, 'wpmediaverse' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;

		$total_media  = (int) wp_count_posts( 'mvs_media' )->publish;
		$total_albums = (int) wp_count_posts( 'mvs_album' )->publish;

		$pending_moderation = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'mvs_media',
				'pending'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total_views_row = $wpdb->get_var( "SELECT SUM(views) FROM {$wpdb->prefix}mvs_media_stats" );
		$total_views     = $total_views_row ? (int) $total_views_row : 0;

		// Storage used.
		$storage_size = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_mvs_file_size'
			)
		);
		$storage_used = size_format( (int) $storage_size );

		$stats = compact( 'total_media', 'total_albums', 'pending_moderation', 'total_views', 'storage_used' );
		wp_cache_set( $cache_key, $stats, 'wpmediaverse', 5 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Fetch the 5 most recent published media items.
	 *
	 * @return \WP_Post[]
	 */
	private function get_recent_media(): array {
		$cache_key = 'mvs_overview_recent';
		$cached    = wp_cache_get( $cache_key, 'wpmediaverse' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$query = new \WP_Query(
			array(
				'post_type'              => 'mvs_media',
				'post_status'            => 'publish',
				'posts_per_page'         => 5,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
			)
		);

		$posts = $query->posts;
		wp_cache_set( $cache_key, $posts, 'wpmediaverse', 5 * MINUTE_IN_SECONDS );

		return $posts;
	}

	/**
	 * Get system information.
	 *
	 * @return array
	 */
	private function get_system_info(): array {
		return array(
			'buddypress' => class_exists( 'BuddyPress' ),
		);
	}
}
