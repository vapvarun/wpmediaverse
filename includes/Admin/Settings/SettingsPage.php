<?php
/**
 * Admin settings page orchestrator.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Admin settings page for WPMediaVerse.
 *
 * Registered as a submenu under the mvs_media CPT menu. Provides sidebar
 * navigation with grouped section cards. Delegates registration to
 * SettingsRegistrar, field rendering to FieldRenderer, sanitization to
 * Sanitizers, and permissions matrix to PermissionsManager.
 */
class SettingsPage {

	const PAGE_SLUG    = 'mvs-settings';
	const OPTION_GROUP = 'mvs_settings';

	/**
	 * Settings registrar instance.
	 *
	 * @var SettingsRegistrar
	 */
	private $registrar;

	/**
	 * Permissions manager instance.
	 *
	 * @var PermissionsManager
	 */
	private $permissions;

	/**
	 * Constructor. Hooks admin menu and settings registration.
	 */
	public function __construct() {
		$this->registrar   = new SettingsRegistrar();
		$this->permissions = new PermissionsManager();

		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_menu', array( $this, 'cleanup_admin_menu' ), 999 );
		add_action( 'admin_init', array( $this->registrar, 'register_all' ) );
		add_action( 'admin_init', array( $this, 'track_settings_changes' ) );
		add_action( 'admin_notices', array( $this, 'handle_settings_notices' ), 1 );

		$this->permissions->init();
	}

	/**
	 * Remove redundant submenu items for a cleaner admin menu.
	 *
	 * Pages remain accessible via direct URL — only the menu links are removed.
	 * Keeps: Overview, All Media, Albums, Collections, Moderation, Stats, Settings.
	 */
	public function cleanup_admin_menu(): void {
		$parent = \WPMediaVerse\Core\Plugin::ADMIN_SLUG;

		// Hide tool/config pages from menu via CSS instead of remove_submenu_page().
		// This preserves page titles and menu highlighting while keeping the menu clean.
		// Pages are accessible via Settings sidebar links.
		$hide_slugs = array(
			'mvs-challenges',
			'mvs-tournaments',
			'mvs-battles',
			'mvs-reports',
			'mvs-analytics',
		);

		add_action(
			'admin_head',
			function () use ( $hide_slugs, $parent ) {
				echo '<style>';
				foreach ( $hide_slugs as $slug ) {
					echo '.toplevel_page_' . esc_attr( $parent ) . ' .wp-submenu a[href$="page=' . esc_attr( $slug ) . '"]{display:none!important}';
				}
				echo '</style>';
			}
		);
	}

	/**
	 * Track setting changes before they are saved (for contextual notices).
	 */
	public function track_settings_changes(): void {
		// Only on settings save.
		if ( empty( $_POST ) || ! isset( $_POST['option_page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$option_page = sanitize_text_field( wp_unslash( $_POST['option_page'] ) );
		if ( strpos( $option_page, 'mvs_settings' ) !== 0 ) {
			return;
		}

		/**
		 * Fires before WPMediaVerse settings are saved.
		 *
		 * @since 1.1.0
		 *
		 * @param string $option_page The settings page being saved.
		 */
		do_action( 'mvs_settings_before_save', $option_page );

		$old_driver = get_option( 'mvs_storage_driver', 'local' );
		set_transient( 'mvs_old_storage_driver_' . get_current_user_id(), $old_driver, 30 );
	}

	/**
	 * Handle all settings page notices in a single callback.
	 *
	 * WordPress generates one "Settings saved." notice per option group via the
	 * settings_errors transient. Since our page has multiple option groups, this
	 * produces duplicate notices. We suppress them here and render a single
	 * notice below the Save button in the template instead.
	 *
	 * Contextual notices (storage driver change, permissions) are rendered here
	 * as standard admin notices since they convey unique information.
	 */
	public function handle_settings_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'mvs-settings' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['settings-updated'] ) ) {
			return;
		}

		// Suppress WP's duplicate "Settings saved." notices.
		delete_transient( 'settings_errors' );
		global $wp_settings_errors;
		$wp_settings_errors = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

		// Storage driver change notice.
		$user_id    = get_current_user_id();
		$old_driver = get_transient( 'mvs_old_storage_driver_' . $user_id );
		$new_driver = get_option( 'mvs_storage_driver', 'local' );

		delete_transient( 'mvs_old_storage_driver_' . $user_id );

		if ( false !== $old_driver && $old_driver !== $new_driver ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %s: new storage driver name */
					esc_html__( 'Storage driver changed to %s. New uploads will use this driver.', 'wpmediaverse' ),
					'<strong>' . esc_html( ucfirst( $new_driver ) ) . '</strong>'
				)
			);
		}

		// Permissions save notice.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$perms_saved = isset( $_GET['permissions-saved'] ) ? absint( $_GET['permissions-saved'] ) : -1;
		if ( $perms_saved > 0 ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					esc_html(
						// translators: %d: number of roles whose permissions were updated.
						_n(
							'Permissions updated for %d role.',
							'Permissions updated for %d roles.',
							$perms_saved,
							'wpmediaverse'
						)
					),
					esc_html( $perms_saved )
				)
			);
		} elseif ( 0 === $perms_saved ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Permissions saved. No changes were needed.', 'wpmediaverse' )
			);
		}
	}

	/**
	 * Add settings page as submenu under WPMediaVerse (mvs_media CPT menu).
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			\WPMediaVerse\Core\Plugin::ADMIN_SLUG,
			__( 'WPMediaVerse Settings', 'wpmediaverse' ),
			__( 'Settings', 'wpmediaverse' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Whether WPMediaVerse Pro is active.
	 *
	 * @return bool
	 */
	private function is_pro_active(): bool {
		return defined( 'MVS_PRO_VERSION' );
	}

	// -------------------------------------------------------------------------
	// Section Registry
	// -------------------------------------------------------------------------

	/**
	 * Get all registered sidebar sections.
	 *
	 * Each section maps a sidebar nav item to its group, page slug, option group,
	 * and section IDs. Extensible via `mvs_settings_sections` filter.
	 *
	 * @return array<string,array>
	 */
	private function get_registered_sections(): array {
		$sections = array(
			'general'     => array(
				'group'        => 'general',
				'label'        => __( 'General', 'wpmediaverse' ),
				'icon'         => 'settings',
				'description'  => __( 'Upload limits, file types, privacy defaults, and page assignments.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_general',
				'page_slug'    => self::PAGE_SLUG . '-general',
				'section_ids'  => array( 'mvs_general', 'mvs_pages' ),
				'is_pro'       => false,
				'priority'     => 10,
			),
			'display'     => array(
				'group'        => 'general',
				'label'        => __( 'Display', 'wpmediaverse' ),
				'icon'         => 'images',
				'description'  => __( 'Grid layout, columns, thumbnails, and feed preferences.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_display',
				'page_slug'    => self::PAGE_SLUG . '-display',
				'section_ids'  => array( 'mvs_display' ),
				'is_pro'       => false,
				'priority'     => 20,
			),
			'social'      => array(
				'group'        => 'general',
				'label'        => __( 'Social', 'wpmediaverse' ),
				'icon'         => 'message-circle',
				'description'  => __( 'Direct messaging, privacy, and spam prevention.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_social',
				'page_slug'    => self::PAGE_SLUG . '-social',
				'section_ids'  => array( 'mvs_messaging' ),
				'is_pro'       => false,
				'priority'     => 30,
			),
			'storage'     => array(
				'group'        => 'storage',
				'label'        => __( 'Storage', 'wpmediaverse' ),
				'icon'         => 'database',
				'description'  => __( 'Choose where media files are stored.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_storage',
				'page_slug'    => self::PAGE_SLUG . '-storage',
				'section_ids'  => array( 'mvs_storage' ),
				'is_pro'       => false,
				'priority'     => 40,
			),
			'ai'          => array(
				'group'        => 'ai',
				'label'        => __( 'AI & Moderation', 'wpmediaverse' ),
				'icon'         => 'book-open',
				'description'  => __( 'AI providers, auto-analysis, tagging, and content moderation.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_ai',
				'page_slug'    => self::PAGE_SLUG . '-ai',
				'section_ids'  => array( 'mvs_ai', 'mvs_moderation' ),
				'is_pro'       => false,
				'priority'     => 50,
			),
			'watermark'   => array(
				'group'        => 'advanced',
				'label'        => __( 'Watermark', 'wpmediaverse' ),
				'icon'         => 'palette',
				'description'  => __( 'Add a text or image watermark to uploaded images.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_watermark',
				'page_slug'    => self::PAGE_SLUG . '-watermark',
				'section_ids'  => array( 'mvs_watermark' ),
				'is_pro'       => false,
				'priority'     => 60,
			),
			'permissions' => array(
				'group'        => 'advanced',
				'label'        => __( 'Permissions', 'wpmediaverse' ),
				'icon'         => 'users',
				'description'  => __( 'Role-based access control for media features.', 'wpmediaverse' ),
				'option_group' => '',
				'page_slug'    => '',
				'section_ids'  => array(),
				'is_pro'       => false,
				'priority'     => 70,
				'renderer'     => 'permissions',
			),
			'webhooks'    => array(
				'group'        => 'advanced',
				'label'        => __( 'Webhooks', 'wpmediaverse' ),
				'icon'         => 'plug',
				'description'  => __( 'Send event notifications to external services.', 'wpmediaverse' ),
				'option_group' => self::OPTION_GROUP . '_webhooks',
				'page_slug'    => self::PAGE_SLUG . '-webhooks',
				'section_ids'  => array( 'mvs_webhooks' ),
				'is_pro'       => false,
				'priority'     => 80,
			),
		);

		// Remove free watermark if Pro handles it on display tab.
		if ( $this->is_pro_active() ) {
			unset( $sections['watermark'] );
		}

		/**
		 * Filter the settings sections for sidebar navigation.
		 *
		 * Pro plugins add sections here (S3, BunnyCDN, Feed Layout, Captions, etc.).
		 *
		 * @param array $sections Section definitions keyed by ID.
		 */
		$sections = apply_filters( 'mvs_settings_sections', $sections );

		// Sort by priority.
		uasort(
			$sections,
			function ( $a, $b ) {
				return ( $a['priority'] ?? 50 ) - ( $b['priority'] ?? 50 );
			}
		);

		return $sections;
	}

	/**
	 * Group sections by their group key.
	 *
	 * @param array $sections Flat sections array.
	 * @return array<string,array> Grouped sections.
	 */
	private function group_sections( array $sections ): array {
		$group_labels = array(
			'general'  => __( 'General', 'wpmediaverse' ),
			'media'    => __( 'Media', 'wpmediaverse' ),
			'social'   => __( 'Social', 'wpmediaverse' ),
			'storage'  => __( 'Storage', 'wpmediaverse' ),
			'ai'       => __( 'AI & Moderation', 'wpmediaverse' ),
			'advanced' => __( 'Advanced', 'wpmediaverse' ),
			'license'  => __( 'License', 'wpmediaverse' ),
		);

		/**
		 * Filter group labels for sidebar navigation.
		 *
		 * @param array $group_labels Group labels keyed by group ID.
		 */
		$group_labels = apply_filters( 'mvs_settings_group_labels', $group_labels );

		$grouped = array();
		foreach ( $sections as $id => $section ) {
			$group = $section['group'] ?? 'general';
			if ( ! isset( $grouped[ $group ] ) ) {
				$grouped[ $group ] = array(
					'label'    => $group_labels[ $group ] ?? ucfirst( $group ),
					'sections' => array(),
				);
			}
			$grouped[ $group ]['sections'][ $id ] = $section;
		}

		return $grouped;
	}

	// -------------------------------------------------------------------------
	// Page renderer
	// -------------------------------------------------------------------------

	/**
	 * Render the settings page with sidebar navigation.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_mvs_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wpmediaverse' ) );
		}

		$sections = $this->get_registered_sections();
		$grouped  = $this->group_sections( $sections );

		wp_enqueue_script(
			'mvs-settings-nav',
			MVS_PLUGIN_URL . 'assets/js/settings-nav.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		?>
		<div class="wrap wpmediaverse-admin">
			<!-- Page Header -->
			<div class="mvs-settings-page-header">
				<div class="mvs-settings-page-header__left">
					<h1 class="mvs-settings-page-header__title">
						<?php esc_html_e( 'WPMediaVerse', 'wpmediaverse' ); ?>
						<span class="mvs-settings-page-header__version"><?php echo esc_html( 'v' . MVS_VERSION ); ?></span>
					</h1>
					<p class="mvs-settings-page-header__desc">
						<?php esc_html_e( 'Media platform settings and configuration', 'wpmediaverse' ); ?>
					</p>
				</div>
				<div class="mvs-settings-page-header__actions">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mvs-setup' ) ); ?>" class="mvs-btn">
						<i data-lucide="wand-2" class="mvs-icon--sm"></i>
						<?php esc_html_e( 'Run Setup Wizard', 'wpmediaverse' ); ?>
					</a>
				</div>
			</div>

		<div class="mvs-settings-wrap">
			<!-- Sidebar -->
			<div class="mvs-settings-sidebar">
				<div class="mvs-settings-sidebar__brand">
					<span class="mvs-settings-sidebar__logo"><i data-lucide="images"></i></span>
					<div>
						<strong><?php esc_html_e( 'WPMediaVerse', 'wpmediaverse' ); ?></strong>
						<span><?php esc_html_e( 'SETTINGS', 'wpmediaverse' ); ?></span>
					</div>
				</div>

				<?php foreach ( $grouped as $group_id => $group ) : ?>
					<div class="mvs-settings-nav-group">
						<span class="mvs-settings-nav-group__label"><?php echo esc_html( $group['label'] ); ?></span>
						<?php foreach ( $group['sections'] as $section_id => $section ) : ?>
							<a class="mvs-settings-nav-item" href="#<?php echo esc_attr( $section_id ); ?>" data-section="<?php echo esc_attr( $section_id ); ?>">
								<i data-lucide="<?php echo esc_attr( $section['icon'] ); ?>"></i>
								<?php echo esc_html( $section['label'] ); ?>
								<?php if ( ! empty( $section['is_pro'] ) ) : ?>
									<span class="mvs-pro-badge"><?php esc_html_e( 'Pro', 'wpmediaverse' ); ?></span>
								<?php endif; ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<?php
				/**
				 * Fires after the settings sidebar sections.
				 *
				 * @since 1.0.0
				 */
				do_action( 'mvs_settings_sidebar_after' );
				?>
			</div>

			<!-- Content -->
			<div class="mvs-settings-content">

				<?php foreach ( $sections as $section_id => $section ) : ?>
					<div class="mvs-settings-section" id="section-<?php echo esc_attr( $section_id ); ?>">

						<?php if ( ! empty( $section['renderer'] ) ) : ?>
							<div class="mvs-settings-card">
								<div class="mvs-settings-card__head">
									<p class="mvs-settings-card__title">
										<?php echo esc_html( strtoupper( $section['label'] ) ); ?>
										<?php if ( ! empty( $section['is_pro'] ) ) : ?>
											<span class="mvs-pro-badge"><?php esc_html_e( 'Pro', 'wpmediaverse' ); ?></span>
										<?php endif; ?>
									</p>
									<p class="mvs-settings-card__desc"><?php echo esc_html( $section['description'] ); ?></p>
								</div>
								<div class="mvs-settings-card__body">
									<?php
									if ( 'permissions' === $section['renderer'] ) {
										$this->permissions->render_permissions_tab();
									} else {
										/**
										 * Fires to render custom settings section content.
										 *
										 * @since 1.0.0
										 * @param array $section Section configuration.
										 */
										do_action( 'mvs_settings_render_' . $section['renderer'], $section );
									}
									?>
								</div>
							</div>
						<?php elseif ( ! empty( $section['page_slug'] ) ) : ?>
							<form action="options.php" method="post">
								<?php settings_fields( $section['option_group'] ); ?>
								<?php $this->render_section_cards( $section, $section_id ); ?>
								<div class="mvs-settings-section__footer">
									<?php submit_button( __( 'Save Changes', 'wpmediaverse' ), 'primary', 'submit', false ); ?>
									<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
										<div class="mvs-save-notice"><p><?php esc_html_e( 'Settings saved.', 'wpmediaverse' ); ?></p></div>
									<?php endif; ?>
								</div>
							</form>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>

				<?php
				if ( ! $this->is_pro_active() ) {
					$this->render_pro_upsell( 'general' );
				}
				if ( $this->is_pro_active() ) {
					$this->render_storage_toggle_script();
				}
				?>
			</div>
		</div>
		</div><!-- .wrap -->
		<?php
	}

	/**
	 * Render settings as card-based sections.
	 *
	 * Each section_id gets its own card with a header band (title + description)
	 * and a form-table body. For sidebar items with a single section_id, the
	 * sidebar item's label is used. For multiple section_ids, each card uses
	 * the WP section title registered via add_settings_section().
	 *
	 * @param array  $section    Sidebar section config.
	 * @param string $section_key Sidebar section key.
	 */
	private function render_section_cards( array $section, string $section_key ): void {
		global $wp_settings_sections, $wp_settings_fields;

		$page_slug = $section['page_slug'];
		$ids       = $section['section_ids'];
		$is_pro    = ! empty( $section['is_pro'] );

		if ( count( $ids ) <= 1 ) {
			// Single card: use the sidebar item's label as card header.
			?>
			<div class="mvs-settings-card">
				<div class="mvs-settings-card__head">
					<p class="mvs-settings-card__title">
						<?php echo esc_html( strtoupper( $section['label'] ) ); ?>
						<?php if ( $is_pro ) : ?>
							<span class="mvs-pro-badge"><?php esc_html_e( 'Pro', 'wpmediaverse' ); ?></span>
						<?php endif; ?>
					</p>
					<p class="mvs-settings-card__desc"><?php echo esc_html( $section['description'] ); ?></p>
				</div>
				<table class="form-table" role="presentation">
					<?php $this->render_section_fields( $page_slug, $ids ); ?>
				</table>
			</div>
			<?php
			return;
		}

		// Multiple section_ids: each gets its own card.
		$is_first = true;
		foreach ( $ids as $sid ) {
			if ( empty( $wp_settings_fields[ $page_slug ][ $sid ] ) ) {
				continue;
			}

			$wp_section = $wp_settings_sections[ $page_slug ][ $sid ] ?? null;
			$title      = $wp_section ? $wp_section['title'] : $section['label'];

			// Get description: try WP section callback output, fall back to sidebar description for first card.
			$desc_html = '';
			if ( $wp_section && is_callable( $wp_section['callback'] ) && '__return_null' !== $wp_section['callback'] ) {
				ob_start();
				call_user_func( $wp_section['callback'], $wp_section );
				$desc_html = ob_get_clean();
			} elseif ( $is_first && ! empty( $section['description'] ) ) {
				$desc_html = '<p>' . esc_html( $section['description'] ) . '</p>';
			}
			?>
			<div class="mvs-settings-card">
				<div class="mvs-settings-card__head">
					<p class="mvs-settings-card__title">
						<?php echo esc_html( strtoupper( $title ) ); ?>
						<?php if ( $is_pro ) : ?>
							<span class="mvs-pro-badge"><?php esc_html_e( 'Pro', 'wpmediaverse' ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $desc_html ) : ?>
						<div class="mvs-settings-card__desc"><?php echo $desc_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
					<?php endif; ?>
				</div>
				<table class="form-table" role="presentation">
					<?php $this->render_section_fields( $page_slug, array( $sid ) ); ?>
				</table>
			</div>
			<?php
			$is_first = false;
		}
	}

	/**
	 * Render only specific section fields from a page slug.
	 *
	 * Uses WordPress globals to render fields for specific section IDs,
	 * allowing one sidebar item to show a subset of a page slug's fields.
	 *
	 * @param string   $page_slug   The settings page slug.
	 * @param string[] $section_ids Section IDs to render.
	 */
	private function render_section_fields( string $page_slug, array $section_ids ): void {
		global $wp_settings_fields;

		foreach ( $section_ids as $section_id ) {
			if ( empty( $wp_settings_fields[ $page_slug ][ $section_id ] ) ) {
				continue;
			}
			foreach ( $wp_settings_fields[ $page_slug ][ $section_id ] as $field ) {
				$class = '';
				if ( ! empty( $field['args']['class'] ) ) {
					$class = ' class="' . esc_attr( $field['args']['class'] ) . '"';
				}
				echo '<tr' . $class . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				if ( ! empty( $field['title'] ) ) {
					echo '<th scope="row">';
					if ( ! empty( $field['args']['label_for'] ) ) {
						echo '<label for="' . esc_attr( $field['args']['label_for'] ) . '">' . esc_html( $field['title'] ) . '</label>';
					} else {
						echo esc_html( $field['title'] );
					}
					echo '</th>';
				}
				echo '<td>';
				call_user_func( $field['callback'], $field['args'] );
				echo '</td></tr>';
			}
		}
	}

	// -------------------------------------------------------------------------
	// Settings Panels Renderer (General tab)
	// -------------------------------------------------------------------------

	/**
	 * Render inline JS for toggling storage driver panels.
	 */
	private function render_storage_toggle_script(): void {
		?>
		<script>
		(function(){
			var sel = document.querySelector('select[name="mvs_storage_driver"]');
			if (!sel) return;
			function toggle() {
				var panels = document.querySelectorAll('[data-mvs-driver]');
				for (var i = 0; i < panels.length; i++) {
					if (panels[i].getAttribute('data-mvs-driver') === sel.value) {
						panels[i].removeAttribute('hidden');
					} else {
						panels[i].setAttribute('hidden', '');
					}
				}
			}
			sel.addEventListener('change', toggle);
			toggle();
		})();
		</script>
		<?php
	}

	/**
	 * Render the Pro upsell section below settings, tailored to the active tab.
	 *
	 * @param string $active_tab Current tab slug.
	 */
	private function render_pro_upsell( string $active_tab ): void {
		$pro_url  = 'https://store.wbcomdesigns.com/wpmediaverse-pro/';
		$features = $this->get_pro_features_for_tab( $active_tab );

		if ( empty( $features ) ) {
			return;
		}
		?>
		<div class="mvs-pro-section">
			<h3>
				<?php esc_html_e( 'Unlock More with WPMediaVerse Pro', 'wpmediaverse' ); ?>
				<span class="mvs-pro-badge"><?php esc_html_e( 'Pro', 'wpmediaverse' ); ?></span>
			</h3>
			<ul>
				<?php foreach ( $features as $feature ) : ?>
					<li><?php echo esc_html( $feature ); ?></li>
				<?php endforeach; ?>
			</ul>
			<a href="<?php echo esc_url( $pro_url ); ?>" class="mvs-pro-cta" target="_blank" rel="noopener">
				<?php esc_html_e( 'Upgrade to Pro', 'wpmediaverse' ); ?> &rarr;
			</a>
		</div>
		<?php
	}

	/**
	 * Get Pro feature list for a specific settings tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string[] Feature descriptions.
	 */
	private function get_pro_features_for_tab( string $tab ): array {
		$features = array(
			'general'  => array(
				__( 'Amazon S3 cloud storage for unlimited scalability', 'wpmediaverse' ),
				__( 'BunnyCDN integration for global content delivery', 'wpmediaverse' ),
				__( 'Image watermarking with custom position and opacity', 'wpmediaverse' ),
				__( 'Advanced privacy controls per media item', 'wpmediaverse' ),
			),
			'display'  => array(
				__( 'Custom player skins and branding', 'wpmediaverse' ),
				__( 'Video chapters and resume playback', 'wpmediaverse' ),
				__( 'Auto-generated video captions (Whisper AI)', 'wpmediaverse' ),
			),
			'ai'       => array(
				__( 'Google Vision AI provider', 'wpmediaverse' ),
				__( 'AWS Rekognition AI provider', 'wpmediaverse' ),
				__( 'AI provider fallback chains and comparison mode', 'wpmediaverse' ),
				__( 'Per-category moderation thresholds', 'wpmediaverse' ),
			),
			'webhooks' => array(
				__( 'Multiple webhook endpoints', 'wpmediaverse' ),
				__( 'Webhook delivery logs and retry management', 'wpmediaverse' ),
				__( 'Custom webhook event filters', 'wpmediaverse' ),
			),
		);

		return $features[ $tab ] ?? array();
	}
}
