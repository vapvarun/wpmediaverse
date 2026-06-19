<?php
/**
 * Integrations admin page - WPMediaVerse companion stack.
 *
 * Registers the "Integrations" submenu under the WPMediaVerse top-level menu,
 * enqueues no extra assets (the shared mvs-admin stylesheet registered by
 * OverviewPage::enqueue_admin_assets() already fires on every wpmediaverse/*
 * screen), handles the admin-post install action, and renders the cards view.
 *
 * @package WPMediaVerse\Admin
 */

namespace WPMediaVerse\Admin;

use WPMediaVerse\Integrations\Companions\CompanionRegistry;
use WPMediaVerse\Integrations\Companions\CompanionInstaller;

defined( 'ABSPATH' ) || exit;

class IntegrationsPage {

	/**
	 * Page slug - used as the ?page= value and for screen-id matching.
	 */
	const PAGE_SLUG = 'mvs-integrations';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_integrations_assets' ) );
		add_action( 'admin_post_wpmediaverse_install_companion', array( $this, 'handle_install_companion' ) );
	}

	/**
	 * Enqueue the integrations-specific stylesheet, scoped to this page only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_integrations_assets( string $hook_suffix ): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'mvs-admin-integrations',
			MVS_PLUGIN_URL . 'assets/css/admin/integrations.css',
			array( 'mvs-admin' ),
			MVS_VERSION
		);
	}

	/**
	 * Add the Integrations submenu under the WPMediaVerse top-level menu.
	 */
	public function register_submenu(): void {
		add_submenu_page(
			'wpmediaverse',
			__( 'Integrations', 'wpmediaverse' ),
			__( 'Integrations', 'wpmediaverse' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the integrations page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		$companions   = CompanionRegistry::all();
		$logo_base    = MVS_PLUGIN_URL . 'assets/img/companions/';
		$logo_dir     = MVS_PLUGIN_DIR . 'assets/img/companions/';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only redirect-back status flags, no state change.
		$install_state = isset( $_GET['mvs_install'] ) ? sanitize_key( wp_unslash( $_GET['mvs_install'] ) ) : '';
		$install_msg   = isset( $_GET['mvs_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['mvs_msg'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap wpmediaverse-admin mvs-integrations-page">

			<div class="mvs-page-header">
				<div class="mvs-page-header__left">
					<h1 class="mvs-page-header__title">
						<i data-lucide="blocks"></i>
						<?php esc_html_e( 'Integrations', 'wpmediaverse' ); ?>
					</h1>
					<p class="mvs-page-header__desc">
						<?php esc_html_e( 'Extend WPMediaVerse with the Wbcom stack. Each plugin works on its own - installing one here does not tie it to WPMediaVerse.', 'wpmediaverse' ); ?>
					</p>
				</div>
			</div>
			<hr class="wp-header-end">

			<?php if ( 'ok' === $install_state ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Integration installed and activated.', 'wpmediaverse' ); ?></p>
				</div>
			<?php elseif ( 'error' === $install_state && '' !== $install_msg ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $install_msg ); ?></p>
				</div>
			<?php endif; ?>

			<div class="mvs-fam-header">
				<img
					class="mvs-fam-header__mark"
					src="<?php echo esc_url( $logo_base . 'wbcom.svg' ); ?>"
					alt="<?php esc_attr_e( 'Wbcom', 'wpmediaverse' ); ?>"
					width="52"
					height="52"
				/>
				<div class="mvs-fam-header__body">
					<h2 class="mvs-fam-header__title"><?php esc_html_e( 'Part of the Wbcom family', 'wpmediaverse' ); ?></h2>
					<p class="mvs-fam-header__desc">
						<?php esc_html_e( 'MediaVerse is part of the Wbcom community stack: gamification, discussions, courses, listings, jobs, and more. Every plugin works on its own, and you can add any of them below in one click. The family keeps growing, so check back for new releases.', 'wpmediaverse' ); ?>
					</p>
					<a
						class="mvs-fam-header__link"
						href="https://wbcomdesigns.com/downloads/"
						target="_blank"
						rel="noopener noreferrer"
					>
						<?php esc_html_e( 'Explore the full Wbcom family', 'wpmediaverse' ); ?>
						<i data-lucide="arrow-right" aria-hidden="true"></i>
					</a>
				</div>
			</div>

			<div class="mvs-integrations-grid">
				<?php foreach ( $companions as $slug => $companion ) : ?>
					<?php
					$status    = CompanionRegistry::status( $slug );
					$label     = (string) ( $companion['label'] ?? $slug );
					$why       = (string) ( $companion['why'] ?? '' );
					$unlocks   = (string) ( $companion['unlocks'] ?? '' );
					$store_url = (string) ( $companion['store_url'] ?? '' );

					// Resolve logo: only emit the <img> if the file is present on disk.
					$logo_file = sanitize_file_name( $slug ) . '.svg';
					$logo_url  = file_exists( $logo_dir . $logo_file )
						? $logo_base . $logo_file
						: '';

					// Status badge variant + label.
					if ( 'active' === $status ) {
						$badge_class = 'mvs-status-badge mvs-status-badge--success';
						$badge_label = __( 'Connected', 'wpmediaverse' );
					} elseif ( 'installed_inactive' === $status ) {
						$badge_class = 'mvs-status-badge mvs-status-badge--warning';
						$badge_label = __( 'Installed, activate', 'wpmediaverse' );
					} else {
						$badge_class = 'mvs-status-badge mvs-status-badge--muted';
						$badge_label = __( 'Not installed', 'wpmediaverse' );
					}
					?>
					<div class="mvs-integration-card">
						<div class="mvs-integration-card__head">
							<?php if ( '' !== $logo_url ) : ?>
								<img
									class="mvs-companion-logo"
									src="<?php echo esc_url( $logo_url ); ?>"
									alt="<?php echo esc_attr( $label ); ?>"
									width="40"
									height="40"
									loading="lazy"
								/>
							<?php endif; ?>
							<h2 class="mvs-integration-card__title"><?php echo esc_html( $label ); ?></h2>
							<span class="<?php echo esc_attr( $badge_class ); ?>"><?php echo esc_html( $badge_label ); ?></span>
						</div>

						<?php if ( '' !== $why ) : ?>
							<p class="mvs-integration-card__why"><?php echo esc_html( $why ); ?></p>
						<?php endif; ?>

						<?php if ( 'active' === $status && '' !== $unlocks ) : ?>
							<p class="mvs-integration-card__unlocks">
								<i data-lucide="check-circle-2" aria-hidden="true"></i>
								<?php echo esc_html( $unlocks ); ?>
							</p>
						<?php endif; ?>

						<div class="mvs-integration-card__actions">
							<?php if ( 'active' === $status ) : ?>
								<span class="mvs-btn mvs-btn--ghost" aria-disabled="true">
									<i data-lucide="check" aria-hidden="true"></i>
									<?php esc_html_e( 'Connected', 'wpmediaverse' ); ?>
								</span>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="wpmediaverse_install_companion">
									<input type="hidden" name="companion" value="<?php echo esc_attr( $slug ); ?>">
									<input type="hidden" name="tier" value="free">
									<?php wp_nonce_field( 'wpmediaverse_install_companion_' . $slug ); ?>
									<button type="submit" class="mvs-btn mvs-btn--primary">
										<?php
										echo 'installed_inactive' === $status
											? esc_html__( 'Activate', 'wpmediaverse' )
											: esc_html__( 'Install free', 'wpmediaverse' );
										?>
									</button>
								</form>
							<?php endif; ?>

							<?php if ( '' !== $store_url ) : ?>
								<a
									href="<?php echo esc_url( $store_url ); ?>"
									target="_blank"
									rel="noopener noreferrer"
									class="mvs-btn"
								>
									<?php esc_html_e( 'Learn more', 'wpmediaverse' ); ?>
								</a>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			</div>

			<p class="description mvs-integrations-footnote">
				<?php esc_html_e( 'These are standalone Wbcom products. WPMediaVerse detects them and lights up the matching features when present.', 'wpmediaverse' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * admin-post handler: install (or activate) a companion from the Integrations
	 * page. Caps + nonce gate every call; redirects back to the page with a status
	 * that the render method surfaces as an admin notice.
	 */
	public function handle_install_companion(): void {
		$slug = isset( $_POST['companion'] ) ? sanitize_key( wp_unslash( $_POST['companion'] ) ) : '';
		$tier = isset( $_POST['tier'] ) && 'pro' === $_POST['tier'] ? 'pro' : 'free';

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to install plugins.', 'wpmediaverse' ), 403 );
		}
		check_admin_referer( 'wpmediaverse_install_companion_' . $slug );

		$license = isset( $_POST['license'] ) ? sanitize_text_field( wp_unslash( $_POST['license'] ) ) : '';
		$result  = CompanionInstaller::install( $slug, $tier, $license );

		$args = array( 'page' => self::PAGE_SLUG );
		if ( is_wp_error( $result ) ) {
			$args['mvs_install'] = 'error';
			$args['mvs_msg']     = rawurlencode( $result->get_error_message() );
		} else {
			$args['mvs_install'] = 'ok';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
