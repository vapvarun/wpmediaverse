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
	}

	/**
	 * Add a hidden admin page (not in menu).
	 */
	public function add_hidden_page(): void {
		add_submenu_page(
			'', // Hidden — no parent.
			__( 'WPMediaVerse Setup', 'wpmediaverse' ),
			__( 'Setup', 'wpmediaverse' ),
			'manage_mvs_settings',
			self::PAGE_SLUG,
			array( $this, 'render_wizard' )
		);
	}

	/**
	 * Handle wizard form submission.
	 */
	public function handle_wizard_save(): void {
		if ( ! isset( $_POST['mvs_wizard_step'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		if ( ! check_admin_referer( 'mvs_setup_wizard', 'mvs_wizard_nonce' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			return;
		}

		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			return;
		}

		$step = sanitize_text_field( wp_unslash( $_POST['mvs_wizard_step'] ) );

		switch ( $step ) {
			case 'permissions':
				$this->save_permissions();
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=display' ) );
				exit;

			case 'display':
				$this->save_display();
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=done' ) );
				exit;

			case 'done':
				update_option( 'mvs_setup_complete', true );
				wp_safe_redirect( admin_url( 'admin.php?page=mvs-overview' ) );
				exit;
		}
	}

	/**
	 * Save permissions step.
	 */
	private function save_permissions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$upload_roles = isset( $_POST['mvs_upload_roles'] ) && is_array( $_POST['mvs_upload_roles'] )
			? array_map( 'sanitize_key', wp_unslash( $_POST['mvs_upload_roles'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			: array( 'administrator', 'editor', 'author' );

		$all_roles = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber' );

		foreach ( $all_roles as $role_slug ) {
			$role = get_role( $role_slug );
			if ( ! $role ) {
				continue;
			}

			if ( in_array( $role_slug, $upload_roles, true ) ) {
				$role->add_cap( 'upload_mvs_media' );
				$role->add_cap( 'edit_mvs_media' );
				$role->add_cap( 'delete_mvs_media' );
			} else {
				$role->remove_cap( 'upload_mvs_media' );
			}
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
		if ( ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		// Enqueue admin CSS.
		wp_enqueue_style(
			'mvs-admin',
			MVS_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			MVS_VERSION
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : 'welcome';

		$steps         = array(
			'welcome'     => __( 'Welcome', 'wpmediaverse' ),
			'pages'       => __( 'Pages', 'wpmediaverse' ),
			'permissions' => __( 'Permissions', 'wpmediaverse' ),
			'display'     => __( 'Display', 'wpmediaverse' ),
			'done'        => __( 'Done', 'wpmediaverse' ),
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
					case 'permissions':
						$this->render_step_permissions();
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
				<li><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Upload and organize media in albums and collections', 'wpmediaverse' ); ?></li>
				<li><span class="dashicons dashicons-groups"></span> <?php esc_html_e( 'Social features: reactions, comments, favorites, follows', 'wpmediaverse' ); ?></li>
				<li><span class="dashicons dashicons-shield"></span> <?php esc_html_e( 'AI-powered moderation and privacy controls', 'wpmediaverse' ); ?></li>
				<li><span class="dashicons dashicons-buddicons-buddypress-logo"></span> <?php esc_html_e( 'Optional BuddyPress integration', 'wpmediaverse' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'This quick setup will help you configure the essentials. You can change any setting later.', 'wpmediaverse' ); ?></p>
			<div class="mvs-setup-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=pages' ) ); ?>"
					class="button button-primary button-hero">
					<?php esc_html_e( "Let's Get Started", 'wpmediaverse' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-overview' ) ); ?>"
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
				'icon'  => 'dashicons-images-alt2',
				'desc'  => __( 'Public gallery page where visitors browse all media.', 'wpmediaverse' ),
			),
			'mvs_page_upload'    => array(
				'label' => __( 'Upload Media', 'wpmediaverse' ),
				'icon'  => 'dashicons-upload',
				'desc'  => __( 'Frontend upload page for community members.', 'wpmediaverse' ),
			),
			'mvs_page_dashboard' => array(
				'label' => __( 'My Media', 'wpmediaverse' ),
				'icon'  => 'dashicons-admin-users',
				'desc'  => __( 'Personal dashboard for users to manage their uploads.', 'wpmediaverse' ),
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
						<span class="dashicons <?php echo esc_attr( $page_info['icon'] ); ?>"></span>
						<div class="mvs-setup-page-info">
							<strong><?php echo esc_html( $page_info['label'] ); ?></strong>
							<span><?php echo esc_html( $page_info['desc'] ); ?></span>
							<?php if ( $exists ) : ?>
								<code>/<?php echo esc_html( $slug ); ?>/</code>
							<?php else : ?>
								<span style="color:#d63638;"><?php esc_html_e( 'Not created — please reactivate the plugin.', 'wpmediaverse' ); ?></span>
							<?php endif; ?>
						</div>
						<?php if ( $exists ) : ?>
							<span class="mvs-setup-page-status" style="color:#00a32a;">
								<span class="dashicons dashicons-yes-alt"></span>
							</span>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
			</div>

			<div class="mvs-setup-actions">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&step=permissions' ) ); ?>"
					class="button button-primary">
					<?php esc_html_e( 'Continue', 'wpmediaverse' ); ?>
				</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Step 3: Permissions.
	 */
	private function render_step_permissions(): void {
		$all_roles = array(
			'subscriber'  => __( 'Subscriber', 'wpmediaverse' ),
			'contributor' => __( 'Contributor', 'wpmediaverse' ),
			'author'      => __( 'Author', 'wpmediaverse' ),
			'editor'      => __( 'Editor', 'wpmediaverse' ),
		);

		$default_upload = array( 'author', 'editor' );
		?>
		<div class="mvs-setup-step">
			<h2><?php esc_html_e( 'Who Can Upload?', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'Choose which user roles can upload media to your site. Administrators always have full access.', 'wpmediaverse' ); ?></p>

			<form method="post">
				<?php wp_nonce_field( 'mvs_setup_wizard', 'mvs_wizard_nonce' ); ?>
				<input type="hidden" name="mvs_wizard_step" value="permissions" />

				<div class="mvs-setup-role-list">
					<?php
					foreach ( $all_roles as $slug => $label ) :
						$role    = get_role( $slug );
						$has_cap = $role && $role->has_cap( 'upload_mvs_media' );
						$checked = $has_cap || in_array( $slug, $default_upload, true );
						?>
						<label class="mvs-setup-role-item">
							<input type="checkbox" name="mvs_upload_roles[]"
								value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( $checked ); ?> />
							<span><?php echo esc_html( $label ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>

				<p class="description"><?php esc_html_e( 'You can fine-tune permissions later in Settings > Permissions.', 'wpmediaverse' ); ?></p>

				<div class="mvs-setup-actions">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Continue', 'wpmediaverse' ); ?>
					</button>
				</div>
			</form>
		</div>
		<?php
	}

	/**
	 * Step 4: Display settings.
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
					<button type="submit" class="button button-primary">
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
		$upload_id    = (int) get_option( 'mvs_page_upload', 0 );
		$dashboard_id = (int) get_option( 'mvs_page_dashboard', 0 );
		?>
		<div class="mvs-setup-step mvs-setup-step--done">
			<div class="mvs-setup-done-icon">
				<span class="dashicons dashicons-yes-alt"></span>
			</div>
			<h2><?php esc_html_e( 'Your Media Hub is Ready!', 'wpmediaverse' ); ?></h2>
			<p><?php esc_html_e( 'WPMediaVerse is configured and ready to use. Here are some next steps:', 'wpmediaverse' ); ?></p>

			<div class="mvs-setup-done-links">
				<?php if ( $upload_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $upload_id ) ); ?>" class="button button-primary">
						<span class="dashicons dashicons-upload"></span>
						<?php esc_html_e( 'Upload Media', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $explore_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $explore_id ) ); ?>" class="button">
						<span class="dashicons dashicons-images-alt2"></span>
						<?php esc_html_e( 'Visit Explore Page', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $dashboard_id ) : ?>
					<a href="<?php echo esc_url( get_permalink( $dashboard_id ) ); ?>" class="button">
						<span class="dashicons dashicons-admin-users"></span>
						<?php esc_html_e( 'My Dashboard', 'wpmediaverse' ); ?>
					</a>
				<?php endif; ?>
			</div>

			<form method="post" style="margin-top:24px;">
				<?php wp_nonce_field( 'mvs_setup_wizard', 'mvs_wizard_nonce' ); ?>
				<input type="hidden" name="mvs_wizard_step" value="done" />
				<button type="submit" class="button button-hero button-primary">
					<?php esc_html_e( 'Go to Overview', 'wpmediaverse' ); ?>
				</button>
			</form>
		</div>
		<?php
	}
}
