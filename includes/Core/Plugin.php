<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\PostTypes\Album;
use WPMediaVerse\PostTypes\Collection;
use WPMediaVerse\Taxonomies\MediaTag;
use WPMediaVerse\Taxonomies\MediaCategory;
use WPMediaVerse\Admin\SettingsPage;
use WPMediaVerse\Services\UploadService;
use WPMediaVerse\Services\StorageService;
use WPMediaVerse\Services\PrivacyService;
use WPMediaVerse\Services\AlbumService;
use WPMediaVerse\Services\CollectionService;
use WPMediaVerse\Services\StoryService;
use WPMediaVerse\Services\AIService;
use WPMediaVerse\Services\OpenAIProvider;
use WPMediaVerse\Services\ModerationService;
use WPMediaVerse\Blocks\BlockRegistrar;
use WPMediaVerse\Shortcodes\Shortcodes;
use WPMediaVerse\REST\Controller\MediaController;
use WPMediaVerse\REST\Controller\AlbumController;
use WPMediaVerse\REST\Controller\CollectionController;
use WPMediaVerse\REST\Controller\BulkController;
use WPMediaVerse\REST\Controller\ReactionController;
use WPMediaVerse\REST\Controller\CommentController;
use WPMediaVerse\REST\Controller\FavoriteController;
use WPMediaVerse\REST\Controller\StatsController;
use WPMediaVerse\REST\Controller\TagController;
use WPMediaVerse\REST\Controller\ModerationController;
use WPMediaVerse\REST\Controller\AccessController;
use WPMediaVerse\REST\Controller\SignedUrlController;
use WPMediaVerse\Services\SignedUrlService;
use WPMediaVerse\Services\WatermarkService;
use WPMediaVerse\Admin\ModerationQueue;
use WPMediaVerse\Admin\OverviewPage;
use WPMediaVerse\Admin\StatsPage;
use WPMediaVerse\Admin\LogViewerPage;
use WPMediaVerse\Admin\SetupWizard;
use WPMediaVerse\Admin\CollectionMetaBox;
use WPMediaVerse\Social\ReactionService;
use WPMediaVerse\Social\CommentService;
use WPMediaVerse\Social\FavoriteService;
use WPMediaVerse\Social\MentionService;
use WPMediaVerse\Social\ShareService;
use WPMediaVerse\Services\StatsService;
use WPMediaVerse\Services\AccessRulesService;
use WPMediaVerse\Integrations\BuddyPressIntegration;
use WPMediaVerse\Integrations\WebhookService;
use WPMediaVerse\Services\CacheService;
use WPMediaVerse\Social\FollowService;
use WPMediaVerse\Social\NotificationService;
use WPMediaVerse\Social\ReportService;
use WPMediaVerse\Social\ActivityService;
use WPMediaVerse\REST\Controller\FollowController;
use WPMediaVerse\REST\Controller\NotificationController;
use WPMediaVerse\REST\Controller\UserController;
use WPMediaVerse\REST\Controller\ReportController;
use WPMediaVerse\REST\Controller\ActivityController;
use WPMediaVerse\REST\Controller\ProfileController;
use WPMediaVerse\Services\ProfileService;
use WPMediaVerse\Repository\MediaRepository;

/**
 * Main plugin bootstrap class.
 */
class Plugin {

	/**
	 * Service container instance.
	 *
	 * @var ServiceContainer
	 */
	private static $container;

	/**
	 * Initialize the plugin.
	 */
	public static function init(): void {
		// Load textdomain — still needed for plugin-bundled translations;
		// WordPress auto-loads from wp-content/languages/plugins/ since 4.6.
		// phpcs:ignore WordPress.WP.I18n.LowLevelTranslationFunction -- Required for bundled .mo files in languages/.
		load_plugin_textdomain( 'wpmediaverse', false, 'wpmediaverse/languages' );

		// Ensure capabilities are registered. The activation hook can fail
		// on some hosts (fatal error, WP-CLI without --activate, etc.).
		// This version-gated check runs once per version and is a no-op
		// after the first successful load.
		if ( get_option( 'mvs_caps_version' ) !== MVS_VERSION ) {
			\WPMediaVerse\Capabilities\MediaCapabilities::add_caps();
			update_option( 'mvs_caps_version', MVS_VERSION, true );
		}

		// Run pending DB migrations on every load (cheap version check).
		$migrator = new Migrator();
		$migrator->run();

		// Build service container.
		self::$container = new ServiceContainer();
		self::register_services();

		// Register post types, taxonomies, and blocks.
		add_action( 'init', array( self::class, 'register_types' ) );

		// Custom admin menu (replaces CPT-based menu).
		add_action( 'admin_menu', array( self::class, 'register_admin_menu' ), 5 );

		$blocks = new BlockRegistrar();
		$blocks->init();

		$shortcodes = new Shortcodes();
		$shortcodes->init();

		$templates = new TemplateLoader();
		$templates->init();

		// Redirect to overview page on first load after activation.
		add_action( 'admin_init', array( self::class, 'maybe_redirect_after_activation' ) );

		// Admin hooks.
		if ( is_admin() ) {
			self::$container->get( 'admin.overview' );
			self::$container->get( 'admin.settings' );
			self::$container->get( 'admin.moderation' );
			self::$container->get( 'admin.stats' );
			self::$container->get( 'admin.logs' );
			self::$container->get( 'admin.setup_wizard' );
			self::$container->get( 'admin.collection_metabox' );

			// Reorder submenu so Overview is first, then separator, then content, then tools.
			add_action( 'admin_menu', array( self::class, 'reorder_submenu' ), 999 );
		}

		// Register REST API routes.
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		// Initialize story cleanup cron.
		self::$container->get( 'stories' );

		// Defer moderation service — only load on admin or when processing uploads.
		if ( is_admin() ) {
			self::$container->get( 'moderation' );
		}
		add_action(
			'mvs_media_uploaded',
			function () {
				self::$container->get( 'moderation' );
			},
			5
		);

		// AI processing hooks.
		add_action( 'mvs_media_uploaded', array( self::class, 'maybe_queue_ai' ), 10, 1 );
		add_action( 'mvs_ai_process_media', array( self::class, 'handle_ai_process' ), 10, 1 );

		// Access rules privacy filter (priority 20 — after default privacy at 10).
		$access_rules = self::$container->get( 'access_rules' );
		add_filter( 'mvs_privacy_can_view', array( $access_rules, 'filter_privacy_can_view' ), 20, 4 );

		// Signed URL filter — replaces file_url in REST responses for gated media.
		add_filter( 'mvs_media_response', array( self::class, 'maybe_sign_file_url' ), 10, 2 );

		// Initialize watermark service (adds preview_url filter at priority 30).
		self::$container->get( 'watermark' );

		// Initialize cache service (hooks for invalidation).
		self::$container->get( 'cache' );

		// Initialize profile service (avatar filter hooks).
		self::$container->get( 'profile' );

		// Integrations (conditionally loaded).
		self::$container->get( 'integration.buddypress' );
		self::$container->get( 'integration.webhooks' );

		// Action Scheduler callback for async webhook delivery.
		add_action( 'mvs_deliver_webhook', array( self::class, 'deliver_webhook' ), 10, 4 );

		// Note: ensure_media_rows hooks removed — media is now in custom tables, not CPT.

		// Enqueue frontend styles.
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_frontend_assets' ) );

		// Fix nav menu items when BuddyPress is not active.
		if ( ! function_exists( 'buddypress' ) ) {
			add_filter( 'wp_nav_menu_objects', array( self::class, 'filter_nav_menu_objects' ), 10 );
		}

		// Flush rewrite rules if needed (after activation).
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 99 );

		// Register Abilities API (WP 6.9+).
		Abilities::init();

		// Plugin-level theme.json — design tokens at lowest priority (theme always wins).
		add_filter( 'wp_theme_json_data_default', array( self::class, 'register_theme_json' ) );

		// Shared UI shell — FAB, upload modal, lightbox (all frontend pages).
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_shared_ui_assets' ) );
		add_action( 'wp_footer', array( self::class, 'render_shared_ui_shell' ) );

		// Messaging — DM engine.
		self::init_messaging();

		/**
		 * Fires after WPMediaVerse has loaded.
		 *
		 * @param ServiceContainer $container The service container.
		 */
		do_action( 'mvs_loaded', self::$container );
	}

	/**
	 * Register services in the container.
	 */
	private static function register_services(): void {
		self::$container->register(
			'storage',
			function () {
				return new StorageService();
			}
		);

		self::$container->register(
			'upload',
			function ( ServiceContainer $c ) {
				return new UploadService( $c->get( 'storage' ) );
			}
		);

		self::$container->register(
			'admin.overview',
			function () {
				return new OverviewPage();
			}
		);

		self::$container->register(
			'admin.settings',
			function () {
				return new SettingsPage();
			}
		);

		self::$container->register(
			'privacy',
			function () {
				return new PrivacyService();
			}
		);

		self::$container->register(
			'reactions',
			function () {
				return new ReactionService();
			}
		);

		self::$container->register(
			'comments',
			function () {
				return new CommentService();
			}
		);

		self::$container->register(
			'favorites',
			function () {
				return new FavoriteService();
			}
		);

		self::$container->register(
			'mentions',
			function () {
				return new MentionService();
			}
		);

		self::$container->register(
			'shares',
			function () {
				return new ShareService();
			}
		);

		self::$container->register(
			'stats',
			function () {
				return new StatsService();
			}
		);

		self::$container->register(
			'albums',
			function () {
				return new AlbumService();
			}
		);

		self::$container->register(
			'collections',
			function () {
				return new CollectionService();
			}
		);

		self::$container->register(
			'stories',
			function () {
				$service = new StoryService();
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'ai',
			function () {
				$service = new AIService();
				$service->register_provider( new OpenAIProvider() );

				/**
				 * Fires after the AI service is created so additional providers can be registered.
				 *
				 * @param AIService $service The AI service instance.
				 */
				do_action( 'mvs_ai_providers', $service );

				return $service;
			}
		);

		self::$container->register(
			'moderation',
			function ( ServiceContainer $c ) {
				$service = new ModerationService( $c->get( 'ai' ) );
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'admin.moderation',
			function ( ServiceContainer $c ) {
				return new ModerationQueue( $c->get( 'moderation' ) );
			}
		);

		self::$container->register(
			'admin.stats',
			function ( ServiceContainer $c ) {
				return new StatsPage( $c->get( 'ai' ) );
			}
		);

		self::$container->register(
			'admin.logs',
			function () {
				return new LogViewerPage();
			}
		);

		self::$container->register(
			'admin.setup_wizard',
			function () {
				return new SetupWizard();
			}
		);

		self::$container->register(
			'admin.collection_metabox',
			function ( ServiceContainer $c ) {
				$metabox = new CollectionMetaBox( $c->get( 'collections' ) );
				$metabox->init();
				return $metabox;
			}
		);

		self::$container->register(
			'access_rules',
			function () {
				return new AccessRulesService();
			}
		);

		self::$container->register(
			'signed_urls',
			function ( ServiceContainer $c ) {
				return new SignedUrlService( $c->get( 'access_rules' ), $c->get( 'privacy' ) );
			}
		);

		self::$container->register(
			'watermark',
			function ( ServiceContainer $c ) {
				$service = new WatermarkService( $c->get( 'access_rules' ) );
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'integration.buddypress',
			function () {
				$bp = new BuddyPressIntegration();
				$bp->init();
				return $bp;
			}
		);

		self::$container->register(
			'integration.webhooks',
			function () {
				$webhooks = new WebhookService();
				$webhooks->init();
				return $webhooks;
			}
		);

		self::$container->register(
			'cache',
			function () {
				$service = new CacheService();
				$service->init();
				return $service;
			}
		);

		// Log pruning cron (daily).
		if ( ! wp_next_scheduled( 'mvs_prune_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'mvs_prune_logs' );
		}
		add_action( 'mvs_prune_logs', array( \WPMediaVerse\Services\LoggerService::class, 'prune' ) );

		// Wire LoggerService into key operations.
		\WPMediaVerse\Services\LoggerService::register_hooks();

		// GDPR compliance.
		$gdpr = new \WPMediaVerse\Services\GDPRService();
		$gdpr->init();

		// Site Health checks.
		$health = new \WPMediaVerse\Services\HealthCheckService();
		$health->init();

		self::$container->register(
			'follows',
			function () {
				return new FollowService();
			}
		);

		self::$container->register(
			'notifications',
			function () {
				$service = new NotificationService();
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'reports',
			function () {
				return new ReportService();
			}
		);

		self::$container->register(
			'activity',
			function () {
				$service = new ActivityService();
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'profile',
			function () {
				$service = new ProfileService();
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'media_repository',
			function () {
				return new MediaRepository();
			}
		);
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_rest_routes(): void {
		$privacy = self::$container->get( 'privacy' );

		$reactions = self::$container->get( 'reactions' );
		$comments  = self::$container->get( 'comments' );
		$favorites = self::$container->get( 'favorites' );
		$stats     = self::$container->get( 'stats' );
		$albums    = self::$container->get( 'albums' );

		$collections  = self::$container->get( 'collections' );
		$moderation   = self::$container->get( 'moderation' );
		$ai           = self::$container->get( 'ai' );
		$access_rules = self::$container->get( 'access_rules' );
		$signed_urls  = self::$container->get( 'signed_urls' );

		$follows       = self::$container->get( 'follows' );
		$notifications = self::$container->get( 'notifications' );
		$reports       = self::$container->get( 'reports' );
		$activity      = self::$container->get( 'activity' );
		$profile       = self::$container->get( 'profile' );

		$controllers = array(
			new MediaController( $privacy ),
			new AlbumController( $albums, $privacy ),
			new CollectionController( $collections ),
			new BulkController(),
			new ReactionController( $reactions ),
			new CommentController( $comments ),
			new FavoriteController( $favorites ),
			new StatsController( $stats, $privacy ),
			new TagController(),
			new ModerationController( $moderation, $ai ),
			new AccessController( $access_rules ),
			new SignedUrlController( $signed_urls, $privacy ),
			new FollowController( $follows ),
			new NotificationController( $notifications ),
			new UserController(),
			new ReportController( $reports ),
			new ActivityController( $activity ),
			new ProfileController( $profile ),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Register post types and taxonomies.
	 *
	 * Note: mvs_media is NOT a CPT — media lives in mvs_media_index custom table.
	 * Albums and collections remain as CPTs (low volume, CPT is fine).
	 */
	public static function register_types(): void {
		Album::register();
		Collection::register();
		MediaTag::register();
		MediaCategory::register();
	}

	/**
	 * Admin menu parent slug — all subpages register under this.
	 */
	const ADMIN_SLUG = 'wpmediaverse';

	/**
	 * Build admin URL for a WPMediaVerse page.
	 *
	 * @param string $page Page slug (e.g., 'mvs-settings'). Empty = overview.
	 * @param array  $args Additional query args.
	 * @return string Full admin URL.
	 */
	public static function admin_url( string $page = '', array $args = array() ): string {
		$slug = $page ? $page : self::ADMIN_SLUG;
		$url  = admin_url( 'admin.php?page=' . $slug );
		if ( ! empty( $args ) ) {
			$url = add_query_arg( $args, $url );
		}
		return $url;
	}

	/**
	 * Register the top-level WPMediaVerse admin menu and core subpages.
	 */
	public static function register_admin_menu(): void {
		// Top-level menu — renders overview page.
		add_menu_page(
			__( 'WPMediaVerse', 'wpmediaverse' ),
			__( 'WPMediaVerse', 'wpmediaverse' ),
			'manage_options',
			self::ADMIN_SLUG,
			array( self::$container->get( 'admin.overview' ), 'render_page' ),
			'dashicons-format-gallery',
			25
		);

		// First submenu replaces the auto-created duplicate.
		add_submenu_page(
			self::ADMIN_SLUG,
			__( 'Overview', 'wpmediaverse' ),
			__( 'Overview', 'wpmediaverse' ),
			'manage_options',
			self::ADMIN_SLUG,
			array( self::$container->get( 'admin.overview' ), 'render_page' )
		);

		// All Media — custom listing page.
		add_submenu_page(
			self::ADMIN_SLUG,
			__( 'All Media', 'wpmediaverse' ),
			__( 'All Media', 'wpmediaverse' ),
			'manage_options',
			'mvs-media',
			array( \WPMediaVerse\Admin\MediaListPage::class, 'render' )
		);

		// Albums and Collections register themselves via show_in_menu => 'wpmediaverse'.
	}

	/**
	 * Flush rewrite rules once after activation.
	 */
	public static function maybe_flush_rewrites(): void {
		$needs_flush = false;

		if ( get_transient( 'mvs_flush_rewrite' ) ) {
			delete_transient( 'mvs_flush_rewrite' );
			$needs_flush = true;
		}

		// Also flush when the plugin version changes (e.g., after update).
		$stored_version = get_option( 'mvs_rewrite_version', '' );
		if ( defined( 'MVS_VERSION' ) && MVS_VERSION !== $stored_version ) {
			update_option( 'mvs_rewrite_version', MVS_VERSION );
			$needs_flush = true;
		}

		if ( $needs_flush ) {
			flush_rewrite_rules();
		}
	}

	/**
	 * Queue AI processing for a newly uploaded media item.
	 *
	 * @param int $media_id Media post ID.
	 */
	public static function maybe_queue_ai( int $media_id ): void {
		if ( ! get_option( 'mvs_ai_auto_analyze', false ) ) {
			return;
		}

		$ai = self::$container->get( 'ai' );
		if ( ! $ai->get_active_provider() ) {
			return;
		}

		// Use Action Scheduler if available, otherwise process synchronously.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				'mvs_ai_process_media',
				array( 'media_id' => $media_id ),
				'wpmediaverse'
			);
		} else {
			$ai->process( $media_id );
		}
	}

	/**
	 * Handle the Action Scheduler callback for AI processing.
	 *
	 * @param int $media_id Media post ID.
	 */
	public static function handle_ai_process( int $media_id ): void {
		$ai = self::$container->get( 'ai' );
		$ai->process( $media_id );
	}

	/**
	 * Deliver a webhook via Action Scheduler.
	 *
	 * @param string $url     Webhook URL.
	 * @param string $body    JSON body.
	 * @param array  $headers HTTP headers.
	 * @param int    $attempt Delivery attempt number.
	 */
	public static function deliver_webhook( string $url, string $body, array $headers, int $attempt = 1 ): void {
		$webhooks = self::$container->get( 'integration.webhooks' );
		$webhooks->send( $url, $body, $headers, $attempt );
	}

	/**
	 * Replace file_url with signed URL for gated media in REST responses.
	 *
	 * @param array $data     Response data.
	 * @param int   $media_id Media post ID.
	 * @return array Modified response data.
	 */
	public static function maybe_sign_file_url( array $data, int $media_id ): array {
		$signed_urls = self::$container->get( 'signed_urls' );

		if ( ! $signed_urls->requires_signed_url( $media_id ) ) {
			return $data;
		}

		$user_id = get_current_user_id();

		// For gated media, replace direct URL with signed URL or remove it.
		$url = $signed_urls->generate( $media_id, $user_id );

		if ( $url ) {
			$data['file_url'] = $url;
			$data['gated']    = true;
		} else {
			// User doesn't have access — strip the file URL.
			$data['file_url'] = '';
			$data['gated']    = true;
		}

		return $data;
	}

	// Note: ensure_media_rows methods removed — media is created directly in custom tables.

	/**
	 * Enqueue frontend styles and scripts on MVS pages.
	 */
	public static function enqueue_frontend_assets(): void {
		$post_type  = get_post_type();
		$is_mvs     = in_array( $post_type, array( 'mvs_album', 'mvs_collection' ), true );
		$is_archive = is_post_type_archive( 'mvs_album' );
		$is_mvs_tax = is_tax( 'mvs_tag' ) || is_tax( 'mvs_category' );
		$is_mvs_tpl = ! empty( $GLOBALS['mvs_current_media'] ) || ! empty( $GLOBALS['mvs_is_media_archive'] );

		// Detect mapped MVS pages (explore, dashboard, upload) — globals aren't set yet at enqueue time.
		$mvs_page_ids = array_filter( array_map( 'absint', array(
			get_option( 'mvs_page_explore', 0 ),
			get_option( 'mvs_page_dashboard', 0 ),
			get_option( 'mvs_page_upload', 0 ),
		) ) );
		$is_mvs_page = is_page( $mvs_page_ids );

		// Always enqueue on MVS pages or pages with dashboard shortcode.
		if ( $is_mvs || $is_archive || $is_mvs_tax || $is_mvs_tpl || $is_mvs_page ) {
			wp_enqueue_style(
				'mvs-frontend',
				MVS_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				MVS_VERSION
			);

			wp_enqueue_style(
				'mvs-load-more',
				MVS_PLUGIN_URL . 'assets/css/frontend/load-more.css',
				array(),
				MVS_VERSION
			);

			wp_enqueue_script(
				'mvs-card-builders',
				MVS_PLUGIN_URL . 'assets/js/frontend/card-builders.js',
				array(),
				MVS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);

			wp_enqueue_script(
				'mvs-load-more',
				MVS_PLUGIN_URL . 'assets/js/frontend/load-more.js',
				array( 'mvs-card-builders' ),
				MVS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);

			// Lightbox + shared UI store — needed on all MVS pages (logged in or out).
			// Use src/ (ESM source) not build/ (IIFE) — matches explore-view pattern.
			wp_enqueue_script_module(
				'@mvs/shared-ui',
				MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',
				array( array( 'id' => '@wordpress/interactivity' ) ),
				MVS_VERSION
			);

			wp_enqueue_style(
				'mvs-shared-ui-shell',
				MVS_PLUGIN_URL . 'assets/css/shared-ui-shell.css',
				array(),
				MVS_VERSION
			);

			// Lightbox is handled by shared-ui Interactivity API module — no legacy JS needed.
		} else {
			// Register for on-demand loading by blocks/shortcodes.
			wp_register_style(
				'mvs-frontend',
				MVS_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				MVS_VERSION
			);
		}
	}

	/**
	 * Redirect to the Overview page once after plugin activation.
	 *
	 * The transient is set in Activator::activate() and has a 30-second TTL
	 * so it self-expires if somehow the redirect never fires.
	 */
	public static function maybe_redirect_after_activation(): void {
		if ( ! get_transient( 'mvs_activation_redirect' ) ) {
			return;
		}

		// Only redirect for human admin requests — not AJAX, CLI, or bulk activate.
		if ( wp_doing_ajax() || defined( 'WP_CLI' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['activate-multi'] ) ) {
			return;
		}

		delete_transient( 'mvs_activation_redirect' );

		// Redirect to setup wizard if first time, otherwise overview.
		if ( ! get_option( 'mvs_setup_complete' ) && current_user_can( 'manage_mvs_settings' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . SetupWizard::PAGE_SLUG ) );
		} else {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::ADMIN_SLUG ) );
		}
		exit;
	}

	/**
	 * Reorder the WPMediaVerse submenu for a logical admin experience.
	 *
	 * Order: Overview > separator > All Media > Add New > Tags > Categories >
	 * Albums > Collections > separator > Settings > Moderation > Stats.
	 */
	public static function reorder_submenu(): void {
		global $submenu;

		$parent = self::ADMIN_SLUG;
		if ( empty( $submenu[ $parent ] ) ) {
			return;
		}

		$order_map = array(
			self::ADMIN_SLUG => 1,
			'mvs-media'      => 5,
			'mvs-settings'   => 50,
			'mvs-moderation' => 51,
			'mvs-stats'      => 52,
		);

		usort(
			$submenu[ $parent ],
			function ( $a, $b ) use ( $order_map ) {
				$a_order = $order_map[ $a[2] ] ?? 30;
				$b_order = $order_map[ $b[2] ] ?? 30;
				return $a_order - $b_order;
			}
		);
	}

	/**
	 * Filter nav menu items when BuddyPress is not active.
	 *
	 * Hides BP-only items (Groups, Members, Activity) and rewrites
	 * "My Profile" (/members/me/) to the dashboard page.
	 *
	 * @param array $items Sorted menu items.
	 * @return array Filtered menu items.
	 */
	public static function filter_nav_menu_objects( array $items ): array {
		// BP-only URL patterns to remove entirely.
		$bp_patterns = array( '/members/', '/groups/', '/activity/' );

		$dashboard_url = '';
		$dashboard_id  = (int) get_option( 'mvs_page_dashboard' );
		if ( $dashboard_id ) {
			$dashboard_url = get_permalink( $dashboard_id );
		}

		$filtered = array();
		foreach ( $items as $item ) {
			$url = $item->url;

			// Check if this is a BP-only link.
			$is_bp_link = false;
			foreach ( $bp_patterns as $pattern ) {
				if ( false !== strpos( $url, $pattern ) ) {
					$is_bp_link = true;
					break;
				}
			}

			// "My Profile" (/members/me/) — rewrite to dashboard if available.
			if ( $is_bp_link && false !== strpos( $url, '/members/me' ) && $dashboard_url ) {
				$item->url   = $dashboard_url;
				$item->title = __( 'My Media', 'wpmediaverse' );
				$filtered[]  = $item;
				continue;
			}

			// Skip other BP-only links.
			if ( $is_bp_link ) {
				continue;
			}

			$filtered[] = $item;
		}

		return $filtered;
	}

	/**
	 * Get the service container.
	 *
	 * @return ServiceContainer
	 */
	public static function container(): ServiceContainer {
		return self::$container;
	}

	// -------------------------------------------------------------------------
	// Plugin-level theme.json
	// -------------------------------------------------------------------------

	/**
	 * Register plugin-level theme.json design tokens.
	 *
	 * Uses wp_theme_json_data_default (WP 6.1+) so the active theme's
	 * theme.json always overrides these tokens. Pro can extend via
	 * the mvs_theme_json filter.
	 *
	 * @param \WP_Theme_JSON_Data $theme_json Default theme JSON data.
	 * @return \WP_Theme_JSON_Data
	 */
	public static function register_theme_json( $theme_json ) {
		$plugin_json_path = MVS_PLUGIN_DIR . 'theme.json';
		if ( ! file_exists( $plugin_json_path ) ) {
			return $theme_json;
		}

		$plugin_data = wp_json_file_decode( $plugin_json_path, array( 'associative' => true ) );
		if ( ! $plugin_data ) {
			return $theme_json;
		}

		/**
		 * Filter plugin theme.json data before merging.
		 *
		 * Pro can extend with premium design tokens (extra colors, spacing, etc).
		 *
		 * @param array $plugin_data Decoded theme.json array.
		 */
		$plugin_data = apply_filters( 'mvs_theme_json', $plugin_data );

		$theme_json->update_with( $plugin_data );

		return $theme_json;
	}

	// -------------------------------------------------------------------------
	// Messaging
	// -------------------------------------------------------------------------

	/**
	 * Initialize the DM/messaging engine.
	 */
	private static function init_messaging(): void {
		$messaging_service = new \WPMediaVerse\Messaging\MessagingService();
		$transport         = apply_filters(
			'mvs_messaging_transport',
			new \WPMediaVerse\Messaging\RestPollingTransport()
		);
		$controller        = new \WPMediaVerse\Messaging\MessagingController( $messaging_service, $transport );

		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );

		$listener = new \WPMediaVerse\Messaging\NotificationListener( $messaging_service );
		$listener->init();

		// Register the service in the container for other components.
		self::$container->register(
			'messaging',
			function () use ( $messaging_service ) {
				return $messaging_service;
			}
		);

		// Online status visibility — read from option, respect per-setting.
		add_filter(
			'mvs_show_online_status',
			function ( $show ) {
				$setting = get_option( 'mvs_show_online_status', 'everyone' );
				if ( 'nobody' === $setting ) {
					return false;
				}
				return $show;
			},
			5
		);

		// Frontend assets + chat panel (only for logged-in users).
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_messaging_assets' ) );
		add_action( 'wp_head', array( self::class, 'print_messaging_config' ), 1 );
		add_action( 'wp_footer', array( self::class, 'render_chat_panel' ) );
		add_action( 'init', array( self::class, 'register_messages_page' ) );
	}

	/**
	 * Enqueue messaging CSS + JS for logged-in users.
	 */
	public static function enqueue_messaging_assets(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Suppress DM UI when BuddyNext handles it.
		if ( apply_filters( 'mvs_buddynext_active', false ) ) {
			return;
		}

		wp_enqueue_style(
			'mvs-messaging',
			MVS_PLUGIN_URL . 'assets/css/messaging.css',
			array(),
			MVS_VERSION
		);

		wp_register_script_module(
			'mvs-messaging',
			MVS_PLUGIN_URL . 'assets/js/messaging.js',
			array(
				array(
					'id'     => '@wordpress/interactivity',
					'import' => 'static',
				),
			),
			MVS_VERSION
		);
		wp_enqueue_script_module( 'mvs-messaging' );
	}

	/**
	 * Print messaging config as inline script in wp_head.
	 */
	public static function print_messaging_config(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( apply_filters( 'mvs_buddynext_active', false ) ) {
			return;
		}

		$user   = wp_get_current_user();
		$config = array(
			'restBase'    => esc_url_raw( rest_url( 'mvs/v1' ) ),
			'nonce'       => wp_create_nonce( 'wp_rest' ),
			'currentUser' => array(
				'id'           => $user->ID,
				'display_name' => $user->display_name,
				'avatar_url'   => get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			),
			'transport'   => apply_filters(
				'mvs_messaging_transport',
				new \WPMediaVerse\Messaging\RestPollingTransport()
			)->get_client_config(),
		);

		wp_print_inline_script_tag(
			'window.mvsMessagingConfig = ' . wp_json_encode( $config ) . ';',
			array( 'id' => 'mvs-messaging-config' )
		);
	}

	/**
	 * Enqueue shared UI shell assets (FAB, upload modal, lightbox).
	 */
	public static function enqueue_shared_ui_assets(): void {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}

		wp_enqueue_style(
			'mvs-shared-ui-shell',
			MVS_PLUGIN_URL . 'assets/css/shared-ui-shell.css',
			array(),
			MVS_VERSION
		);

		$mvs_shared_ui_asset = file_exists( MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php' )
			? require MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php'
			: array( 'dependencies' => array(), 'version' => MVS_VERSION );
		wp_enqueue_script_module(
			'@mvs/shared-ui',
			MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
			$mvs_shared_ui_asset['dependencies'],
			$mvs_shared_ui_asset['version']
		);
	}

	/**
	 * Render the shared UI shell in wp_footer (FAB, upload modal, lightbox).
	 */
	public static function render_shared_ui_shell(): void {
		if ( is_admin() ) {
			return;
		}

		// Render on all MVS frontend pages (lightbox needed for all users).
		// The template itself handles hiding upload FAB for logged-out users.
		$post_type  = get_post_type();
		$is_mvs     = in_array( $post_type, array( 'mvs_album', 'mvs_collection' ), true );
		$is_archive = is_post_type_archive( 'mvs_album' );
		$is_mvs_tax = is_tax( 'mvs_tag' ) || is_tax( 'mvs_category' );
		$is_mvs_tpl = ! empty( $GLOBALS['mvs_current_media'] ) || ! empty( $GLOBALS['mvs_is_media_archive'] );

		// Detect mapped MVS pages.
		$mvs_shell_page_ids = array_filter( array_map( 'absint', array(
			get_option( 'mvs_page_explore', 0 ),
			get_option( 'mvs_page_dashboard', 0 ),
			get_option( 'mvs_page_upload', 0 ),
		) ) );
		$is_mvs_shell_page = ! empty( $mvs_shell_page_ids ) && is_page( $mvs_shell_page_ids );

		if ( ! is_user_logged_in() && ! $is_mvs && ! $is_archive && ! $is_mvs_tax && ! $is_mvs_tpl && ! $is_mvs_shell_page ) {
			return;
		}

		$template = MVS_PLUGIN_DIR . 'templates/partials/shared-ui-shell.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Render the chat panel in wp_footer for logged-in users.
	 */
	public static function render_chat_panel(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Suppress when BuddyNext handles DM UI.
		if ( apply_filters( 'mvs_buddynext_active', false ) ) {
			return;
		}

		$template = MVS_PLUGIN_DIR . 'templates/partials/chat-panel.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Register the /messages/ page rewrite rule.
	 */
	public static function register_messages_page(): void {
		add_rewrite_rule(
			'^messages/?$',
			'index.php?mvs_messages_page=1',
			'top'
		);

		add_filter(
			'query_vars',
			function ( $vars ) {
				$vars[] = 'mvs_messages_page';
				return $vars;
			}
		);

		add_action(
			'template_redirect',
			function () {
				if ( ! get_query_var( 'mvs_messages_page' ) ) {
					return;
				}

				if ( ! is_user_logged_in() ) {
					auth_redirect();
					return;
				}

				// Suppress when BuddyNext handles DM UI.
				if ( apply_filters( 'mvs_buddynext_active', false ) ) {
					return;
				}

				$template = MVS_PLUGIN_DIR . 'templates/messages.php';
				if ( file_exists( $template ) ) {
					include $template;
					exit;
				}
			}
		);
	}
}
