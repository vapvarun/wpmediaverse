<?php
/**
 * Admin overview / dashboard page.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Core\TemplateHelpers;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Overview page — the landing page under WPMediaVerse admin menu.
 *
 * Shows at-a-glance stats, quick links, recent uploads, and system status
 * so a site owner can understand plugin health immediately.
 */
class OverviewPage {

	const PAGE_SLUG = 'wpmediaverse';

	/**
	 * Constructor. Registers the admin submenu.
	 */
	public function __construct() {
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

		// Enqueue on any WPMediaVerse admin page.
		$is_mvs_page = (
			'mvs_album' === $screen->post_type ||
			'mvs_collection' === $screen->post_type ||
			false !== strpos( $screen->id, 'wpmediaverse' ) ||
			false !== strpos( $screen->id, 'mvs-' )
		);

		if ( $is_mvs_page ) {
			wp_enqueue_style(
				'mvs-admin',
				MVS_PLUGIN_URL . 'assets/css/admin.css',
				array(),
				MVS_VERSION
			);

			wp_enqueue_script(
				'lucide',
				MVS_PLUGIN_URL . 'assets/js/vendor/lucide.min.js',
				array(),
				'0.460.0',
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);

			wp_enqueue_script(
				'mvs-icons',
				MVS_PLUGIN_URL . 'assets/js/admin/icons.js',
				array( 'lucide' ),
				MVS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);

			wp_enqueue_script(
				'mvs-toast',
				MVS_PLUGIN_URL . 'assets/js/admin/toast.js',
				array(),
				MVS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
		}
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
		<div class="wrap wpmediaverse-admin">
			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="images"></i>
						<?php esc_html_e( 'WPMediaVerse', 'wpmediaverse' ); ?>
						<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
					</h1>
					<p class="mvs-page-header__desc"><?php esc_html_e( 'Your media sharing platform at a glance.', 'wpmediaverse' ); ?></p>
				</div>
			</div>

			<?php $this->render_getting_started(); ?>

			<?php // --- Stat Cards --- ?>
			<div class="mvs-admin-stats">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>" class="mvs-stat-card mvs-stat-card--accent">
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-moderation' ) ); ?>" class="mvs-stat-card <?php echo esc_attr( $pending_class ); ?>">
					<span class="mvs-stat-number"><?php echo esc_html( number_format_i18n( $stats['pending_moderation'] ) ); ?></span>
					<span class="mvs-stat-label"><?php esc_html_e( 'Pending Review', 'wpmediaverse' ); ?></span>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-stats' ) ); ?>" class="mvs-stat-card mvs-stat-card--accent">
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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>" class="mvs-btn mvs-btn--primary">
									<i data-lucide="images"></i>
									<?php esc_html_e( 'All Media', 'wpmediaverse' ); ?>
								</a>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-settings' ) ); ?>" class="mvs-btn">
									<i data-lucide="settings"></i>
									<?php esc_html_e( 'Settings', 'wpmediaverse' ); ?>
								</a>
								<?php if ( current_user_can( 'moderate_mvs_media' ) ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-moderation' ) ); ?>" class="mvs-btn">
										<i data-lucide="shield"></i>
										<?php esc_html_e( 'Moderation', 'wpmediaverse' ); ?>
										<?php if ( $stats['pending_moderation'] > 0 ) : ?>
											<span class="mvs-badge"><?php echo esc_html( $stats['pending_moderation'] ); ?></span>
										<?php endif; ?>
									</a>
								<?php endif; ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-stats' ) ); ?>" class="mvs-btn">
									<i data-lucide="bar-chart-3"></i>
									<?php esc_html_e( 'Stats', 'wpmediaverse' ); ?>
								</a>
							</div>

							<?php if ( 0 === (int) $stats['total_media'] ) : ?>
								<div class="mvs-demo-import mvs-section-divider">
									<h4 class="mvs-demo-title"><?php esc_html_e( 'Quick Start with Demo Content', 'wpmediaverse' ); ?></h4>
									<p class="mvs-demo-desc">
										<?php esc_html_e( 'Import 12 sample media items to see how everything works — albums, reactions, and your explore page will come alive.', 'wpmediaverse' ); ?>
									</p>
									<button type="button" class="mvs-btn mvs-btn--primary" id="mvs-import-demo-btn"
										data-nonce="<?php echo esc_attr( wp_create_nonce( 'mvs_import_demo' ) ); ?>">
										<i data-lucide="download"></i>
										<?php esc_html_e( 'Import Demo Data', 'wpmediaverse' ); ?>
									</button>
									<span id="mvs-import-demo-status" class="mvs-status-inline"></span>
									<div class="mvs-import-progress mvs-progress-container mvs-hidden" id="mvs-import-progress">
										<div class="mvs-import-progress-bar mvs-progress-bar" id="mvs-import-progress-bar" style="width:0%;"></div>
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
									progress.classList.remove('mvs-hidden');
									bar.style.width = '30%';
									var xhr = new XMLHttpRequest();
									xhr.open('POST', ajaxurl);
									xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
									xhr.onload = function() {
										bar.style.width = '100%';
										var data;
										try {
											data = JSON.parse(xhr.responseText);
										} catch (err) {
											status.textContent = '<?php echo esc_js( __( 'Import failed — server returned an invalid response. Check wp-content/debug.log.', 'wpmediaverse' ) ); ?>';
											status.className = 'mvs-status-inline mvs-btn-text-danger';
											btn.disabled = false;
											btn.textContent = '<?php echo esc_js( __( 'Import Demo Data', 'wpmediaverse' ) ); ?>';
											progress.classList.add('mvs-hidden');
											console.error('mvs_import_demo_data: invalid JSON response', err, xhr.responseText);
											return;
										}
										if (data.success) {
											status.textContent = data.data.message;
											status.className = 'mvs-status-inline mvs-icon-success';
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
											status.className = 'mvs-status-inline mvs-btn-text-danger';
											btn.disabled = false;
											btn.textContent = '<?php echo esc_js( __( 'Import Demo Data', 'wpmediaverse' ) ); ?>';
											progress.classList.add('mvs-hidden');
										}
									};
									setTimeout(function() { bar.style.width = '60%'; }, 500);
									xhr.send('action=mvs_import_demo_data&_nonce=' + btn.getAttribute('data-nonce'));
								});
								</script>
							<?php else : ?>
								<?php if ( current_user_can( 'manage_mvs_settings' ) ) : ?>
									<div class="mvs-demo-cleanup mvs-section-divider">
										<button type="button" class="mvs-btn mvs-btn--danger" id="mvs-cleanup-demo-btn"
											data-nonce="<?php echo esc_attr( wp_create_nonce( 'mvs_cleanup_demo' ) ); ?>">
											<i data-lucide="trash-2"></i>
											<?php esc_html_e( 'Delete Demo Data', 'wpmediaverse' ); ?>
										</button>
										<span id="mvs-cleanup-demo-status" class="mvs-status-inline"></span>
									</div>
									<script>
									document.getElementById('mvs-cleanup-demo-btn').addEventListener('click', function() {
										if (!confirm('<?php echo esc_js( __( 'Delete all demo users and the media, albums, and collections they own? Your real user data will not be touched. This cannot be undone.', 'wpmediaverse' ) ); ?>')) {
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
											var data;
											try {
												data = JSON.parse(xhr.responseText);
											} catch (err) {
												status.textContent = '<?php echo esc_js( __( 'Cleanup failed — server returned an invalid response. Check wp-content/debug.log.', 'wpmediaverse' ) ); ?>';
												status.className = 'mvs-status-inline mvs-btn-text-danger';
												btn.disabled = false;
												btn.textContent = '<?php echo esc_js( __( 'Delete Demo Data', 'wpmediaverse' ) ); ?>';
												console.error('mvs_cleanup_demo: invalid JSON response', err, xhr.responseText);
												return;
											}
											if (data.success) {
												status.textContent = data.data.message;
												status.className = 'mvs-status-inline mvs-icon-success';
												setTimeout(function() { location.reload(); }, 1500);
											} else {
												status.textContent = data.data ? data.data.message : 'Cleanup failed.';
												status.className = 'mvs-status-inline mvs-btn-text-danger';
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
					<div class="mvs-admin-widget mvs-widget-spaced">
						<div class="mvs-widget-header">
							<h2><?php esc_html_e( 'Frontend Pages', 'wpmediaverse' ); ?></h2>
						</div>
						<div class="mvs-widget-body">
							<?php
							$pages = array(
								'mvs_page_explore'   => array(
									'label' => __( 'Explore Page', 'wpmediaverse' ),
									'icon'  => 'images',
									'code'  => '[mvs_gallery]',
								),
								'mvs_page_dashboard' => array(
									'label' => __( 'My Media Page', 'wpmediaverse' ),
									'icon'  => 'users',
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
											<i data-lucide="<?php echo esc_attr( $page_info['icon'] ); ?>"></i>
											<?php echo esc_html( $page_info['label'] ); ?>
										</span>
										<span class="mvs-status-value">
											<?php if ( $exists && $page_url ) : ?>
												<a href="<?php echo esc_url( $page_url ); ?>" target="_blank" class="mvs-status-ok mvs-link-plain">
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
								<div class="notice notice-warning inline mvs-notice-mt is-dismissible">
									<p>
										<?php esc_html_e( 'Some pages are missing. Deactivate and reactivate the plugin to create them, or add pages manually with shortcodes:', 'wpmediaverse' ); ?>
									</p>
									<ul class="mvs-bulleted-list">
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
									<i data-lucide="image"></i>
									<p><?php esc_html_e( 'No media uploaded yet.', 'wpmediaverse' ); ?></p>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>" class="mvs-btn mvs-btn--primary">
										<?php esc_html_e( 'Upload First Media', 'wpmediaverse' ); ?>
									</a>
								</div>
							<?php else : ?>
								<table class="mvs-recent-table">
									<thead>
										<tr>
											<th class="column-thumb">&nbsp;</th>
											<th><?php esc_html_e( 'Title', 'wpmediaverse' ); ?></th>
											<th><?php esc_html_e( 'Author', 'wpmediaverse' ); ?></th>
											<th><?php esc_html_e( 'Date', 'wpmediaverse' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $recent_media as $item ) : ?>
											<?php
											$item_media_id = (int) ( $item['media_id'] ?? 0 );
											$item_title    = $item['title'] ?? '';
											$item_author   = (int) ( $item['post_author'] ?? 0 );
											$item_date     = $item['created_at'] ?? '';
											$thumb_url     = $item_media_id ? TemplateHelpers::get_thumb_url( $item_media_id, 'thumbnail' ) : '';
											$view_url      = $item_media_id ? MediaRepository::get_permalink( $item_media_id ) : '';
											$link_url      = $view_url;
											?>
											<tr>
												<td>
													<?php if ( $thumb_url ) : ?>
														<img src="<?php echo esc_url( $thumb_url ); ?>"
															alt="<?php echo esc_attr( $item_title ); ?>"
															class="mvs-thumb"
															loading="lazy" />
													<?php else : ?>
														<span class="mvs-thumb-placeholder">
															<i data-lucide="image"></i>
														</span>
													<?php endif; ?>
												</td>
												<td>
													<?php if ( $link_url ) : ?>
														<a href="<?php echo esc_url( $link_url ); ?>">
															<?php echo esc_html( $item_title ? $item_title : __( '(no title)', 'wpmediaverse' ) ); ?>
														</a>
													<?php else : ?>
														<?php echo esc_html( $item_title ? $item_title : __( '(no title)', 'wpmediaverse' ) ); ?>
													<?php endif; ?>
												</td>
												<td><?php echo esc_html( get_the_author_meta( 'display_name', $item_author ) ); ?></td>
												<td>
													<time datetime="<?php echo esc_attr( $item_date ); ?>">
														<?php echo esc_html( $item_date ? human_time_diff( strtotime( $item_date ), time() ) : '' ); ?>
														<?php if ( $item_date ) : ?>
															<?php esc_html_e( 'ago', 'wpmediaverse' ); ?>
														<?php endif; ?>
													</time>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							<?php endif; ?>
						</div>
						<div class="mvs-widget-footer">
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-media' ) ); ?>">
								<?php esc_html_e( 'View all media', 'wpmediaverse' ); ?> &rarr;
							</a>
						</div>
					</div>

					<?php // System Status Widget. ?>
					<div class="mvs-admin-widget mvs-widget-spaced">
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

			<?php
			/**
			 * Fires after the overview page widgets, for third-party dashboard widgets.
			 *
			 * @since 1.1.0
			 */
			do_action( 'mvs_dashboard_widgets' );
			?>

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

		$settings_url    = admin_url( 'admin.php?page=mvs-settings' );
		$explore_page_id = (int) get_option( 'mvs_page_explore', 0 );
		$explore_url_wb  = $explore_page_id ? get_permalink( $explore_page_id ) : home_url( '/media/' );
		$permissions_url = admin_url( 'admin.php?page=mvs-settings#permissions' );
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
					<a href="<?php echo esc_url( $explore_url_wb ); ?>" class="mvs-welcome-step">
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
				<i data-lucide="x"></i>
			</button>
		</div>
		<script>
		document.getElementById('mvs-dismiss-welcome').addEventListener('click', function() {
			var banner = document.getElementById('mvs-welcome-banner');
			banner.classList.add('mvs-hidden');
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
			<i data-lucide="check-circle" class="mvs-icon-success"></i>
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

		$total_media  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$total_albums = (int) wp_count_posts( 'mvs_album' )->publish;

		$pending_moderation = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE moderation_status = 'pending'"
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
	 * Fetch the 5 most recent published media items from custom table.
	 *
	 * @return array[] Array of media rows.
	 */
	private function get_recent_media(): array {
		global $wpdb;

		$cache_key = 'mvs_overview_recent';
		$cached    = wp_cache_get( $cache_key, 'wpmediaverse' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			"SELECT * FROM {$wpdb->prefix}mvs_media_index WHERE status = 'publish' ORDER BY created_at DESC LIMIT 5",
			ARRAY_A
		);

		$result = $rows ?: array();
		wp_cache_set( $cache_key, $result, 'wpmediaverse', 5 * MINUTE_IN_SECONDS );

		return $result;
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

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wpmediaverse' ) ) );
		}

		$seeder = MVS_PLUGIN_DIR . 'seed-demo-data.php';
		if ( ! file_exists( $seeder ) ) {
			wp_send_json_error( array( 'message' => __( 'Demo data seeder not found.', 'wpmediaverse' ) ) );
		}

		// Buffer and discard any stray output (PHP notices, DB errors with
		// WP_DEBUG_DISPLAY on, etc.) so the JSON response stays clean.
		ob_start();
		require_once $seeder;
		ob_end_clean();

		// The seeder always terminates via wp_send_json_success()/wp_die() when
		// DOING_AJAX is true (see seed-demo-data.php line 1533). If execution
		// reaches this line, the seeder returned without sending a response.
		wp_send_json_error( array( 'message' => __( 'Seeder did not return a response.', 'wpmediaverse' ) ) );
	}

	/**
	 * AJAX handler for demo data cleanup.
	 */
	public function handle_cleanup_demo(): void {
		check_ajax_referer( 'mvs_cleanup_demo', '_nonce' );

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wpmediaverse' ) ) );
		}

		$cleanup = MVS_PLUGIN_DIR . 'cleanup-demo-data.php';
		if ( ! file_exists( $cleanup ) ) {
			wp_send_json_error( array( 'message' => __( 'Cleanup script not found.', 'wpmediaverse' ) ) );
		}

		// Buffer and discard stray output so the JSON response stays clean.
		ob_start();
		require_once $cleanup;
		ob_end_clean();

		// The cleanup script terminates via wp_send_json_success()/wp_die() when
		// DOING_AJAX is true. Reaching this point means the script is broken.
		wp_send_json_error( array( 'message' => __( 'Cleanup did not return a response.', 'wpmediaverse' ) ) );
	}
}
