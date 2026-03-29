<?php
/**
 * Admin overview / dashboard page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\MediaMeta;

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
		add_action( 'wp_ajax_mvs_import_demo_data', array( $this, 'ajax_import_demo_data' ) );
		add_action( 'wp_ajax_mvs_cleanup_demo_data', array( $this, 'handle_cleanup_demo' ) );
		add_action( 'wp_ajax_mvs_dismiss_welcome', array( $this, 'ajax_dismiss_welcome' ) );
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
			false !== strpos( $screen->id, 'mvs-stats' ) ||
			false !== strpos( $screen->id, 'mvs-logs' )
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
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		$stats        = $this->get_stats();
		$recent_media = $this->get_recent_media();
		$system_info  = $this->get_system_info();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">
				<?php esc_html_e( 'WPMediaVerse', 'wpmediaverse' ); ?>
				<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
			</h1>
			<hr class="wp-header-end">
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

				<?php // --- Left Column. ?>
				<div>
					<?php // Quick Links Widget. ?>
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

							<?php if ( 0 === (int) $stats['total_media'] ) : ?>
								<div class="mvs-demo-import" style="margin-top:16px;padding-top:16px;border-top:1px solid #eee;">
									<h4 style="margin:0 0 4px;"><?php esc_html_e( 'Quick Start with Demo Content', 'wpmediaverse' ); ?></h4>
									<p style="margin:0 0 12px;color:#666;">
										<?php esc_html_e( 'Import 12 sample media items to see how everything works — albums, reactions, and your explore page will come alive.', 'wpmediaverse' ); ?>
									</p>
									<button type="button" class="button button-primary" id="mvs-import-demo-btn"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'mvs_import_demo' ) ); ?>">
										<span class="dashicons dashicons-download" style="margin-top:4px;"></span>
										<?php esc_html_e( 'Import Demo Data', 'wpmediaverse' ); ?>
									</button>
									<span id="mvs-import-demo-status" style="margin-left:8px;"></span>
									<div class="mvs-import-progress" id="mvs-import-progress" style="display:none;margin-top:8px;">
										<div class="mvs-import-progress-bar" id="mvs-import-progress-bar" style="width:0%;height:4px;background:#2271b1;border-radius:2px;transition:width 0.3s;"></div>
									</div>
								</div>
								<script>
								document.getElementById('mvs-import-demo-btn').addEventListener('click', function() {
									var btn = this;
									var status = document.getElementById('mvs-import-demo-status');
									var progress = document.getElementById('mvs-import-progress');
									var bar = document.getElementById('mvs-import-progress-bar');
									btn.disabled = true;
									btn.textContent = '<?php echo esc_js( __( 'Importing...', 'wpmediaverse' ) ); ?>';
									status.textContent = '';
									progress.style.display = 'block';
									bar.style.width = '30%';
									var xhr = new XMLHttpRequest();
									xhr.open('POST', ajaxurl);
									xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
									xhr.onload = function() {
										bar.style.width = '100%';
										var data = JSON.parse(xhr.responseText);
										if (data.success) {
											status.textContent = data.data.message;
											status.style.color = '#00a32a';
											<?php
											$explore_page_id = (int) get_option( 'mvs_page_explore', 0 );
											$explore_url     = $explore_page_id ? get_permalink( $explore_page_id ) : '';
											if ( $explore_url ) :
												?>
											status.textContent += ' <?php echo esc_js( __( 'Redirecting to Explore page...', 'wpmediaverse' ) ); ?>';
											setTimeout(function() { window.location.href = '<?php echo esc_url( $explore_url ); ?>'; }, 1500);
											<?php else : ?>
											setTimeout(function() { location.reload(); }, 1500);
											<?php endif; ?>
										} else {
											status.textContent = data.data ? data.data.message : 'Import failed.';
											status.style.color = '#d63638';
											btn.disabled = false;
											btn.textContent = '<?php echo esc_js( __( 'Import Demo Data', 'wpmediaverse' ) ); ?>';
											progress.style.display = 'none';
										}
									};
									setTimeout(function() { bar.style.width = '60%'; }, 500);
									xhr.send('action=mvs_import_demo_data&_nonce=' + btn.getAttribute('data-nonce'));
								});
								</script>
							<?php else : ?>
								<?php if ( current_user_can( 'manage_mvs_settings' ) ) : ?>
									<div class="mvs-demo-cleanup" style="margin-top:16px;padding-top:16px;border-top:1px solid #eee;">
										<button type="button" class="button" id="mvs-cleanup-demo-btn"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'mvs_cleanup_demo' ) ); ?>"
											style="color:#b32d2e;border-color:#b32d2e;">
											<span class="dashicons dashicons-trash" style="margin-top:4px;"></span>
											<?php esc_html_e( 'Delete Demo Data', 'wpmediaverse' ); ?>
										</button>
										<span id="mvs-cleanup-demo-status" style="margin-left:8px;"></span>
									</div>
									<script>
									document.getElementById('mvs-cleanup-demo-btn').addEventListener('click', function() {
										if (!confirm('<?php echo esc_js( __( 'Delete ALL media, albums, collections, and custom table data? This cannot be undone.', 'wpmediaverse' ) ); ?>')) {
											return;
										}
										var btn = this;
										var status = document.getElementById('mvs-cleanup-demo-status');
										btn.disabled = true;
										btn.textContent = '<?php echo esc_js( __( 'Deleting...', 'wpmediaverse' ) ); ?>';
										status.textContent = '';
										var xhr = new XMLHttpRequest();
										xhr.open('POST', ajaxurl);
										xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
										xhr.onload = function() {
											var data = JSON.parse(xhr.responseText);
											if (data.success) {
												status.textContent = data.data.message;
												status.style.color = '#00a32a';
												setTimeout(function() { location.reload(); }, 1500);
											} else {
												status.textContent = data.data ? data.data.message : 'Cleanup failed.';
												status.style.color = '#d63638';
												btn.disabled = false;
												btn.textContent = '<?php echo esc_js( __( 'Delete Demo Data', 'wpmediaverse' ) ); ?>';
											}
										};
										xhr.send('action=mvs_cleanup_demo_data&_nonce=' + btn.getAttribute('data-nonce'));
									});
									</script>
								<?php endif; ?>
							<?php endif; ?>
						</div>
					</div>

					<?php // Frontend Pages Widget. ?>
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

				<?php // --- Right Column. ?>
				<div>
					<?php // Recent Uploads Widget. ?>
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
											$thumb_id  = (int) MediaMeta::get( $item->ID, 'attachment_id' );
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
														<?php echo esc_html( human_time_diff( strtotime( $item->post_date ), time() ) ); ?>
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

					<?php // System Status Widget. ?>
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
									<span class="mvs-status-value <?php echo esc_attr( $ai_key ? 'mvs-status-ok' : 'mvs-status-warn' ); ?>">
										<?php echo $ai_key ? esc_html__( 'Configured', 'wpmediaverse' ) : esc_html__( 'Not set', 'wpmediaverse' ); ?>
									</span>
								</li>
								<li>
									<span class="mvs-status-label"><?php esc_html_e( 'BuddyPress', 'wpmediaverse' ); ?></span>
									<span class="mvs-status-value <?php echo esc_attr( $system_info['buddypress'] ? 'mvs-status-ok' : '' ); ?>">
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
	 * Render a dismissible welcome banner for new installs.
	 */
	private function render_getting_started(): void {
		// Don't show if user already dismissed.
		if ( get_user_meta( get_current_user_id(), '_mvs_welcome_dismissed', true ) ) {
			return;
		}

		$settings_url    = admin_url( 'edit.php?post_type=mvs_media&page=mvs-settings' );
		$upload_page_id  = (int) get_option( 'mvs_page_upload', 0 );
		$upload_url      = $upload_page_id ? get_permalink( $upload_page_id ) : admin_url( 'post-new.php?post_type=mvs_media' );
		$permissions_url = admin_url( 'edit.php?post_type=mvs_media&page=mvs-settings#permissions' );
		?>
		<div class="mvs-welcome-banner" id="mvs-welcome-banner">
			<div class="mvs-welcome-banner__content">
				<h3><?php esc_html_e( 'Welcome to WPMediaVerse!', 'wpmediaverse' ); ?></h3>
				<p><?php esc_html_e( 'Your media sharing platform is ready. Follow these steps to get started:', 'wpmediaverse' ); ?></p>
				<div class="mvs-welcome-steps">
					<a href="<?php echo esc_url( $settings_url ); ?>" class="mvs-welcome-step">
						<span class="mvs-welcome-step__number">1</span>
						<span class="mvs-welcome-step__text">
							<strong><?php esc_html_e( 'Configure settings', 'wpmediaverse' ); ?></strong>
							<span><?php esc_html_e( 'Upload limits, privacy, file types', 'wpmediaverse' ); ?></span>
						</span>
					</a>
					<a href="<?php echo esc_url( $upload_url ); ?>" class="mvs-welcome-step">
						<span class="mvs-welcome-step__number">2</span>
						<span class="mvs-welcome-step__text">
							<strong><?php esc_html_e( 'Upload your first media', 'wpmediaverse' ); ?></strong>
							<span><?php esc_html_e( 'Images, videos, or audio files', 'wpmediaverse' ); ?></span>
						</span>
					</a>
					<a href="<?php echo esc_url( $permissions_url ); ?>" class="mvs-welcome-step">
						<span class="mvs-welcome-step__number">3</span>
						<span class="mvs-welcome-step__text">
							<strong><?php esc_html_e( 'Customize permissions', 'wpmediaverse' ); ?></strong>
							<span><?php esc_html_e( 'Control who can upload and manage', 'wpmediaverse' ); ?></span>
						</span>
					</a>
				</div>
				<?php $this->render_pages_created_notice(); ?>
			</div>
			<button type="button" class="mvs-welcome-banner__dismiss" id="mvs-dismiss-welcome"
				data-nonce="<?php echo esc_attr( wp_create_nonce( 'mvs_dismiss_welcome' ) ); ?>"
				aria-label="<?php esc_attr_e( 'Dismiss welcome banner', 'wpmediaverse' ); ?>">
				<span class="dashicons dashicons-no-alt"></span>
			</button>
		</div>
		<script>
		document.getElementById('mvs-dismiss-welcome').addEventListener('click', function() {
			var banner = document.getElementById('mvs-welcome-banner');
			banner.style.display = 'none';
			var xhr = new XMLHttpRequest();
			xhr.open('POST', ajaxurl);
			xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			xhr.send('action=mvs_dismiss_welcome&_nonce=' + this.getAttribute('data-nonce'));
		});
		</script>
		<?php
	}

	/**
	 * Render success notice showing auto-created frontend pages.
	 */
	private function render_pages_created_notice(): void {
		$pages = array(
			'mvs_page_explore'   => __( 'Explore Media', 'wpmediaverse' ),
			'mvs_page_upload'    => __( 'Upload Media', 'wpmediaverse' ),
			'mvs_page_dashboard' => __( 'My Media', 'wpmediaverse' ),
		);

		$active_pages = array();
		foreach ( $pages as $option_key => $label ) {
			$page_id = (int) get_option( $option_key, 0 );
			if ( $page_id > 0 && 'publish' === get_post_status( $page_id ) ) {
				$active_pages[] = array(
					'label' => $label,
					'url'   => get_permalink( $page_id ),
				);
			}
		}

		if ( empty( $active_pages ) ) {
			return;
		}
		?>
		<div class="mvs-welcome-pages">
			<span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
			<span><?php esc_html_e( 'Frontend pages created:', 'wpmediaverse' ); ?></span>
			<?php foreach ( $active_pages as $page ) : ?>
				<a href="<?php echo esc_url( $page['url'] ); ?>" target="_blank">
					<?php echo esc_html( $page['label'] ); ?> &#8599;
				</a>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * AJAX handler for dismissing the welcome banner.
	 */
	public function ajax_dismiss_welcome(): void {
		check_ajax_referer( 'mvs_dismiss_welcome', '_nonce' );
		update_user_meta( get_current_user_id(), '_mvs_welcome_dismissed', 1 );
		wp_send_json_success();
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

		// Storage used (from mvs_media_index table).
		$storage_size = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT SUM(file_size) FROM {$wpdb->prefix}mvs_media_index" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
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

	/**
	 * AJAX handler for demo data import.
	 */
	public function ajax_import_demo_data(): void {
		check_ajax_referer( 'mvs_import_demo', '_nonce' );

		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$seeder = MVS_PLUGIN_DIR . 'seed-demo-data.php';
		if ( ! file_exists( $seeder ) ) {
			wp_send_json_error( array( 'message' => 'Demo data seeder not found.' ) );
		}

		require_once $seeder;

		// If the seeder didn't send a response (shouldn't happen), send one.
		wp_send_json_success( array( 'message' => 'Import complete.' ) );
	}

	/**
	 * AJAX handler for demo data cleanup.
	 */
	public function handle_cleanup_demo(): void {
		check_ajax_referer( 'mvs_cleanup_demo', '_nonce' );

		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ) );
		}

		$cleanup = MVS_PLUGIN_DIR . 'cleanup-demo-data.php';
		if ( ! file_exists( $cleanup ) ) {
			wp_send_json_error( array( 'message' => 'Cleanup script not found.' ) );
		}

		require_once $cleanup;

		// If the cleanup didn't send a response (shouldn't happen), send one.
		wp_send_json_success( array( 'message' => 'Cleanup complete.' ) );
	}
}
