<?php
/**
 * First-time setup wizard.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-step setup wizard shown on first plugin activation.
 */
class SetupWizard {

	const PAGE_SLUG = 'mvs-setup';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_hidden_page' ) );
		add_action( 'admin_init', array( $this, 'handle_wizard_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'hide_from_menu' ) );
	}

	/**
	 * Add a hidden admin page (not in menu).
	 *
	 * Registered under the 'wpmediaverse' parent so the admin screen $title
	 * global is populated correctly — passing an empty parent left $title
	 * null, which triggered a strip_tags() deprecation notice in
	 * wp-admin/admin-header.php on PHP 8.1+. The submenu entry itself is
	 * hidden via CSS (see hide_from_menu()) so the capability check in
	 * user_can_access_admin_page() still finds the page.
	 */
	public function add_hidden_page(): void {
		add_submenu_page(
			'wpmediaverse',
			__( 'WPMediaVerse Setup', 'wpmediaverse' ),
			__( 'Setup', 'wpmediaverse' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_wizard' )
		);
	}

	/**
	 * Hide the wizard submenu entry from the admin sidebar.
	 *
	 * The entry is kept in the $submenu global so that
	 * user_can_access_admin_page() can still resolve the capability when a
	 * user visits the wizard via direct URL. We use CSS instead of
	 * remove_submenu_page() because the latter drops the entry from
	 * $submenu, which breaks the capability lookup in recent WordPress.
	 *
	 * The rule is attached to the core 'common' admin stylesheet (loaded on
	 * every admin page) via wp_add_inline_style so no inline <style> is emitted.
	 */
	public function hide_from_menu(): void {
		$css = '#toplevel_page_wpmediaverse .wp-submenu a[href$="page=' . self::PAGE_SLUG . '"]{display:none!important;}';
		wp_add_inline_style( 'common', $css );
	}

	/**
	 * Handle wizard form submission.
	 */
	public function handle_wizard_save(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via check_admin_referer on the next branch.
		if ( ! isset( $_POST['mvs_wizard_step'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- check_admin_referer pulls the nonce from $_REQUEST and verifies it.
		if ( ! check_admin_referer( 'mvs_setup_wizard', 'mvs_wizard_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}

		$step = sanitize_text_field( wp_unslash( $_POST['mvs_wizard_step'] ) );

		switch ( $step ) {
			case 'display':
				$this->save_display();
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=done' ) );
				exit;

			case 'done':
				update_option( 'mvs_setup_complete', true );
				wp_safe_redirect( admin_url( 'admin.php?page=wpmediaverse' ) );
				exit;
		}
	}

	/**
	 * Save display step.
	 */
	private function save_display(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST['mvs_grid_columns'] ) ) {
			update_option( 'mvs_grid_columns', absint( $_POST['mvs_grid_columns'] ) );
		}
		if ( isset( $_POST['mvs_items_per_page'] ) ) {
			update_option( 'mvs_items_per_page', absint( $_POST['mvs_items_per_page'] ) );
		}
		if ( isset( $_POST['mvs_thumbnail_style'] ) ) {
			update_option( 'mvs_thumbnail_style', sanitize_text_field( wp_unslash( $_POST['mvs_thumbnail_style'] ) ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Render the wizard page.
	 */
	public function render_wizard(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		// Enqueue admin CSS.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'welcome';

		$steps         = array(
			'welcome' => __( 'Welcome', 'wpmediaverse' ),
			'pages'   => __( 'Pages', 'wpmediaverse' ),
			'display' => __( 'Display', 'wpmediaverse' ),
			'done'    => __( 'Done', 'wpmediaverse' ),
		);
		$step_keys     = array_keys( $steps );
		$current_index = array_search( $step, $step_keys, true );
		if ( false === $current_index ) {
			$step          = 'welcome';
			$current_index = 0;
		}
		?>
		<div class="mvs-setup-wizard">
			<div class="mvs-setup-header">
				<h1><?php esc_html_e( 'WPMediaVerse', 'wpmediaverse' ); ?></h1>
				<span class="mvs-version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
			</div>

			<!-- Progress Steps -->
			<div class="mvs-setup-progress">
				<?php
				foreach ( $steps as $key => $label ) :
					$index = array_search( $key, $step_keys, true );
					$class = '';
					if ( $index < $current_index ) {
						$class = 'completed';
					} elseif ( $index === $current_index ) {
						$class = 'active';
					}
					?>
					<div class="mvs-setup-progress-step <?php echo esc_attr( $class ); ?>">
						<span class="mvs-setup-progress-number"><?php echo esc_html( $index + 1 ); ?></span>
						<span class="mvs-setup-progress-label"><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mvs-setup-body">
				<?php
				switch ( $step ) {
					case 'welcome':
						$this->render_step_welcome();
						break;
					case 'pages':
						$this->render_step_pages();
						break;
					case 'display':
						$this->render_step_display();
						break;
					case 'done':
						$this->render_step_done();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 1: Welcome.
	 */
	private function render_step_welcome(): void {
		?>
		<div class="mvs-setup-step">
			<h2><?php esc_html_e( 'Welcome to WPMediaVerse!', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'Transform your WordPress site into a media sharing platform. Upload, organize, and share images, videos, and audio with your community.', 'wpmediaverse' ); ?></p>
			<ul class="mvs-setup-features">
				<li><i data-lucide="image"></i> <?php esc_html_e( 'Upload and organize media in albums and collections', 'wpmediaverse' ); ?></li>
				<li><i data-lucide="users"></i> <?php esc_html_e( 'Social features: reactions, comments, favorites, follows', 'wpmediaverse' ); ?></li>
				<li><i data-lucide="shield"></i> <?php esc_html_e( 'AI-powered moderation and privacy controls', 'wpmediaverse' ); ?></li>
				<li><i data-lucide="message-square"></i> <?php esc_html_e( 'Optional BuddyPress integration', 'wpmediaverse' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'This quick setup will help you configure the essentials. You can change any setting later.', 'wpmediaverse' ); ?></p>
			<div class="mvs-setup-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=pages' ) ); ?>"
					class="mvs-btn mvs-btn--primary mvs-btn--hero">
					<?php esc_html_e( "Let's Get Started", 'wpmediaverse' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpmediaverse' ) ); ?>"
					class="mvs-setup-skip">
					<?php esc_html_e( 'Skip setup', 'wpmediaverse' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 2: Pages confirmation.
	 */
	private function render_step_pages(): void {
		$pages = array(
			'mvs_page_explore'   => array(
				'label' => __( 'Explore Media', 'wpmediaverse' ),
				'icon'  => 'images',
				'desc'  => __( 'Public gallery page where visitors browse all media.', 'wpmediaverse' ),
			),
			'mvs_page_dashboard' => array(
				'label' => __( 'My Media', 'wpmediaverse' ),
				'icon'  => 'users',
				'desc'  => __( 'Personal dashboard for users to manage their uploads.', 'wpmediaverse' ),
			),
			'mvs_page_upload'    => array(
				'label' => __( 'Upload Media', 'wpmediaverse' ),
				'icon'  => 'upload-cloud',
				'desc'  => __( 'Frontend upload form linked from the Explore page header.', 'wpmediaverse' ),
			),
		);
		?>
		<div class="mvs-setup-step">
			<h2><?php esc_html_e( 'Frontend Pages', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'These pages have been automatically created for your media hub:', 'wpmediaverse' ); ?></p>

			<div class="mvs-setup-pages-list">
				<?php
				foreach ( $pages as $option_key => $page_info ) :
					$page_id = (int) get_option( $option_key, 0 );
					$exists  = $page_id > 0 && 'publish' === get_post_status( $page_id );
					$url     = $exists ? get_permalink( $page_id ) : '';
					$slug    = $exists ? get_post_field( 'post_name', $page_id ) : '';
					?>
					<div class="mvs-setup-page-card">
						<i data-lucide="<?php echo esc_attr( $page_info['icon'] ); ?>"></i>
						<div class="mvs-setup-page-info">
							<strong><?php echo esc_html( $page_info['label'] ); ?></strong>
							<span><?php echo esc_html( $page_info['desc'] ); ?></span>
							<?php if ( $exists ) : ?>
								<code>/<?php echo esc_html( $slug ); ?>/</code>
							<?php else : ?>
								<span class="mvs-text-danger"><?php esc_html_e( 'Not created. Please reactivate the plugin.', 'wpmediaverse' ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $exists ) : ?>
							<span class="mvs-setup-page-status mvs-text-success">
								<i data-lucide="check-circle"></i>
							</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mvs-setup-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=display' ) ); ?>"
					class="mvs-btn mvs-btn--primary">
					<?php esc_html_e( 'Continue', 'wpmediaverse' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 3: Display settings.
	 */
	private function render_step_display(): void {
		$columns  = (int) get_option( 'mvs_grid_columns', 3 );
		$per_page = (int) get_option( 'mvs_items_per_page', 24 );
		$style    = get_option( 'mvs_thumbnail_style', 'square' );
		?>
		<div class="mvs-setup-step">
			<h2><?php esc_html_e( 'Display Settings', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'Configure how your media gallery looks on the frontend.', 'wpmediaverse' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mvs_setup_wizard', 'mvs_wizard_nonce' ); ?>
				<input type="hidden" name="mvs_wizard_step" value="display" />

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Grid Columns', 'wpmediaverse' ); ?></th>
						<td>
							<select name="mvs_grid_columns">
								<option value="2" <?php selected( $columns, 2 ); ?>><?php esc_html_e( '2 columns', 'wpmediaverse' ); ?></option>
								<option value="3" <?php selected( $columns, 3 ); ?>><?php esc_html_e( '3 columns', 'wpmediaverse' ); ?></option>
								<option value="4" <?php selected( $columns, 4 ); ?>><?php esc_html_e( '4 columns', 'wpmediaverse' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Items Per Page', 'wpmediaverse' ); ?></th>
						<td>
							<select name="mvs_items_per_page">
								<option value="12" <?php selected( $per_page, 12 ); ?>>12</option>
								<option value="24" <?php selected( $per_page, 24 ); ?>>24</option>
								<option value="48" <?php selected( $per_page, 48 ); ?>>48</option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Thumbnail Style', 'wpmediaverse' ); ?></th>
						<td>
							<label>
								<input type="radio" name="mvs_thumbnail_style" value="square" <?php checked( $style, 'square' ); ?> />
								<?php esc_html_e( 'Square (cropped)', 'wpmediaverse' ); ?>
							</label><br>
							<label>
								<input type="radio" name="mvs_thumbnail_style" value="original" <?php checked( $style, 'original' ); ?> />
								<?php esc_html_e( 'Original aspect ratio', 'wpmediaverse' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<div class="mvs-setup-actions">
					<button type="submit" class="mvs-btn mvs-btn--primary">
						<?php esc_html_e( 'Continue', 'wpmediaverse' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Step 5: Done.
	 */
	private function render_step_done(): void {
		$explore_id   = (int) get_option( 'mvs_page_explore', 0 );
		$dashboard_id = (int) get_option( 'mvs_page_dashboard', 0 );
		?>
		<div class="mvs-setup-step mvs-setup-step--done">
			<div class="mvs-setup-done-icon">
				<i data-lucide="check-circle"></i>
			</div>
			<h2><?php esc_html_e( 'Your Media Hub is Ready!', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'WPMediaVerse is configured and ready to use. Here are some next steps:', 'wpmediaverse' ); ?></p>

			<div class="mvs-setup-done-links">
				<?php if ( $explore_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $explore_id ) ); ?>" class="mvs-btn">
						<i data-lucide="images"></i>
						<?php esc_html_e( 'Visit Explore Page', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $dashboard_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $dashboard_id ) ); ?>" class="mvs-btn">
						<i data-lucide="users"></i>
						<?php esc_html_e( 'My Dashboard', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<form method="post" class="mvs-setup-finish-form">
				<?php wp_nonce_field( 'mvs_setup_wizard', 'mvs_wizard_nonce' ); ?>
				<input type="hidden" name="mvs_wizard_step" value="done" />
				<button type="submit" class="mvs-btn mvs-btn--primary mvs-btn--hero">
					<?php esc_html_e( 'Go to Overview', 'wpmediaverse' ); ?>
				</button>
			</form>
		</div>
		<?php
	}
}
