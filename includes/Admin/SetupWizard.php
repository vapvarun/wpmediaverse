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
			__( 'MediaVerse Setup', 'wpmediaverse' ),
			__( 'Setup', 'wpmediaverse' ),
			'mvs_settings_screen',
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
			\WPMediaVerse\Core\Plugin::asset_version( 'assets/css/admin.css' )
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
			\WPMediaVerse\Core\Plugin::asset_version( 'assets/js/admin/icons.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'welcome';

		// The Pages step (a read-only list of the pages activation created)
		// was removed in 2.6.0; the Overview screen shows the same thing. An
		// old link to it lands on the next step instead of the Welcome screen.
		if ( 'pages' === $step ) {
			$step = 'display';
		}

		$steps         = array(
			'welcome' => __( 'Welcome', 'wpmediaverse' ),
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
				<h1><?php esc_html_e( 'MediaVerse', 'wpmediaverse' ); ?></h1>
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
			<h2><?php esc_html_e( 'Welcome to MediaVerse!', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'Transform your WordPress site into a media sharing platform. Upload, organize, and share images, videos, and audio with your community.', 'wpmediaverse' ); ?></p>
			<ul class="mvs-setup-features">
				<li><i data-lucide="image"></i> <?php esc_html_e( 'Group your uploads into albums and gather media into collections', 'wpmediaverse' ); ?></li>
				<li><i data-lucide="users"></i> <?php esc_html_e( 'Social features: reactions, comments, favorites, follows', 'wpmediaverse' ); ?></li>
				<li><i data-lucide="shield"></i> <?php esc_html_e( 'AI-powered moderation and privacy controls', 'wpmediaverse' ); ?></li>
				<li><i data-lucide="message-square"></i> <?php esc_html_e( 'Optional BuddyPress integration', 'wpmediaverse' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'This quick setup will help you configure the essentials. You can change any setting later.', 'wpmediaverse' ); ?></p>
			<div class="mvs-setup-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=display' ) ); ?>"
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
	 * Step 2: Display settings.
	 */
	private function render_step_display(): void {
		// 12, matching the registered default and every other read site. This
		// was 24, and a passed default suppresses the registered one, so the
		// wizard preselected 24 on a fresh install and Continue wrote a value
		// the owner never chose - the Display tab then disagreed with the
		// documented default for the life of the site.
		$per_page = (int) get_option( 'mvs_items_per_page', 12 );
		$style    = \WPMediaVerse\Core\SettingsHelper::get_thumbnail_style();
		?>
		<div class="mvs-setup-step">
			<h2><?php esc_html_e( 'Display Settings', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'Configure how your media gallery looks on the frontend.', 'wpmediaverse' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mvs_setup_wizard', 'mvs_wizard_nonce' ); ?>
				<input type="hidden" name="mvs_wizard_step" value="display" />

				<table class="form-table">
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
						<th scope="row"><?php esc_html_e( 'Default Layout', 'wpmediaverse' ); ?></th>
						<td>
							<label>
								<input type="radio" name="mvs_thumbnail_style" value="square" <?php checked( $style, 'square' ); ?> />
								<?php esc_html_e( 'Grid - square crops', 'wpmediaverse' ); ?>
							</label><br>
							<label>
								<input type="radio" name="mvs_thumbnail_style" value="original" <?php checked( $style, 'original' ); ?> />
								<?php esc_html_e( 'Justified rows - original proportions', 'wpmediaverse' ); ?>
							</label><br>
							<label>
								<input type="radio" name="mvs_thumbnail_style" value="list" <?php checked( $style, 'list' ); ?> />
								<?php esc_html_e( 'List - one row per item', 'wpmediaverse' ); ?>
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
	 * Step 3: Done.
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
			<p><?php esc_html_e( 'MediaVerse is configured and ready to use. Here are some next steps:', 'wpmediaverse' ); ?></p>

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
