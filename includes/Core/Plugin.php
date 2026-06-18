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
use WPMediaVerse\Admin\Settings\SettingsPage;
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
use WPMediaVerse\REST\Controller\AdminController;
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
use WPMediaVerse\Integrations\BuddyPress\BuddyPressManager;
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
use WPMediaVerse\REST\Controller\AuthController;
use WPMediaVerse\Services\ProfileService;
use WPMediaVerse\Core\TemplateHelpers;
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
	 * Force the shared UI frame (lightbox dialog) to render on a non-MVS page.
	 *
	 * Set by Pro feed Layout::enqueue_assets() so the lightbox dialog markup
	 * (and its `@mvs/shared-ui` Interactivity store) reaches ordinary shortcode
	 * / block pages that host a feed grid whose media tiles use
	 * `actions.openLightbox`. Without this, the lightbox is dead off MVS pages
	 * (Basecamp #9961961339 — same surface-miss class as the Load More fix
	 * #9961961337). Honored in render_shared_ui_frame().
	 *
	 * @var bool
	 */
	private static $force_shared_ui_frame = false;

	/**
	 * Mark the shared UI frame (lightbox dialog) to render on the current page.
	 *
	 * Public entry point for the Pro feed layouts: their `enqueue_assets()`
	 * runs on `wp_enqueue_scripts`, before `wp_footer`, so flipping this flag
	 * there makes `render_shared_ui_frame()` emit the dialog on a plain
	 * shortcode / block page. Idempotent — the footer renderer guards against
	 * double-output, so calling this from all four feed layouts on the same
	 * page prints the frame exactly once.
	 *
	 * @return void
	 */
	public static function force_shared_ui_frame(): void {
		self::$force_shared_ui_frame = true;
	}

	/**
	 * Enqueue the lightbox store + dialog frame for a Pro feed surface.
	 *
	 * Single entry point the Pro feed layouts call from their
	 * `enqueue_assets()` so the @mvs/shared-ui Interactivity store (which owns
	 * the lightbox) and its dialog-frame markup reach ordinary shortcode /
	 * block pages — not just MVS-native pages. Mirrors the Load More
	 * shared-handle fix: the module + frame CSS are registered globally in
	 * `enqueue_frontend_assets()`; here we enqueue them by handle and flip the
	 * frame-render flag. Idempotent — script-module / style handles dedupe and
	 * `render_shared_ui_frame()` guards against double-output, so calling this
	 * from all four feed layouts on one page is safe. No-op when the
	 * frontend-presence policy says MediaVerse must stand down (BuddyNext owns
	 * the UX) — the PHP_INT_MAX enforcer would dequeue the module anyway.
	 *
	 * @return void
	 */
	public static function enqueue_shared_ui_for_feed(): void {
		if ( self::frontend_ui_suppressed() ) {
			return;
		}

		// wp-i18n backs the runtime __() shim used by the shared-ui view
		// script (script modules cannot import @wordpress/i18n yet).
		wp_enqueue_script( 'wp-i18n' );

		// Lucide powers the <i data-lucide> icons in the lightbox action bar.
		// Registered globally on `wp_enqueue_scripts@1`.
		wp_enqueue_script( 'mvs-lucide' );

		// Dialog frame stylesheet (registered globally in enqueue_frontend_assets).
		wp_enqueue_style( 'mvs-shared-ui-frame' );

		// Lightbox Interactivity store — registered globally (build/) in the
		// non-MVS branch of enqueue_frontend_assets(); enqueue by id here.
		wp_enqueue_script_module( '@mvs/shared-ui' );

		// Print the dialog frame in wp_footer.
		self::force_shared_ui_frame();
	}

	/**
	 * Initialize the plugin.
	 */
	public static function init(): void {
		// WordPress 4.6+ auto-loads plugin textdomains from the standard
		// language directories (wp-content/languages/plugins/{slug}-{locale}.mo
		// and {plugin}/languages/{slug}-{locale}.mo). Calling
		// load_plugin_textdomain() here on `plugins_loaded` triggers the
		// `_load_textdomain_just_in_time was called incorrectly` notice on
		// WP 6.7+ which now requires textdomain loading on `init` or later.

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

		// Storage re-localization on privacy escalation. When a media row
		// flips from `public` to any restricted level, cloud-driver URLs in
		// `file_url` / `thumb_*` must be rewritten to local equivalents or
		// `SignedUrlService::serve_thumbnail()` rejects them on the next
		// read (card 9925110293). Priority 5 — runs BEFORE Pro listeners
		// (Bunny purge, S3 delete) so they see the post-localization state.
		add_action(
			'mvs_media_privacy_changed',
			array( self::$container->get( 'storage' ), 'sync_urls_on_privacy_change' ),
			5,
			3
		);

		// Access rules privacy filter (priority 20 — after default privacy at 10).
		$access_rules = self::$container->get( 'access_rules' );
		add_filter( 'mvs_privacy_can_view', array( $access_rules, 'filter_privacy_can_view' ), 20, 4 );

		// Signing now lives in \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get($id, 'file_url') — the
		// previous `mvs_media_response` listener at priority 10 was retired
		// in Phase 0a item 5. Other listeners (Pro chapters, privacy options,
		// watermark preview, captions) still fire on the filter.

		// Initialize watermark service (adds preview_url filter at priority 30).
		self::$container->get( 'watermark' );

		// Initialize cache service (hooks for invalidation).
		self::$container->get( 'cache' );

		// Initialize profile service (avatar filter hooks).
		self::$container->get( 'profile' );

		// Initialize user deletion cascade (deleted_user hook — cleans orphaned MVS rows).
		self::$container->get( 'user_deletion' );

		// Initialize the orphaned-file cleanup cycle (mvs_media_files_orphaned →
		// async delete of original + variants from local + cloud).
		self::$container->get( 'storage_cleanup' );

		// Integrations (conditionally loaded).
		self::$container->get( 'integration.buddypress' );
		self::$container->get( 'integration.bp_activity_linkage' );
		self::$container->get( 'integration.bp_verified_member' );
		self::$container->get( 'integration.webhooks' );

		// Action Scheduler callback for async webhook delivery.
		add_action( 'mvs_deliver_webhook', array( self::class, 'deliver_webhook' ), 10, 4 );

		// Note: ensure_media_rows hooks removed — media is now in custom tables, not CPT.

		// Enqueue frontend styles.
		// Register the Lucide icon runtime once, on both frontend and admin,
		// at earliest priority. Every integration just calls
		// `wp_enqueue_script( 'mvs-lucide' )` from here on — no integration
		// needs to re-register the script or re-add the hydration inline.
		add_action( 'wp_enqueue_scripts', array( self::class, 'register_lucide_script' ), 1 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'register_lucide_script' ), 1 );

		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_frontend_assets' ) );

		// Auto-pair the mvs-confirm stylesheet whenever its script is enqueued.
		// Runs late so any enqueue made by integrations (BP profile/group tabs,
		// Pro dashboard panel) is reflected. Frontend + admin both covered so
		// the helper is usable from any context.
		add_action( 'wp_enqueue_scripts', array( self::class, 'auto_enqueue_confirm_style' ), 100 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'auto_enqueue_confirm_style' ), 100 );

		// Optional cleanup of dead BuddyPress component links when BuddyPress is
		// inactive. OFF by default: WPMediaVerse must never modify a site owner's
		// authored navigation (Coding Rule #17) — removing or rewriting menu items
		// the owner added is their call, not the plugin's. Opt in per site with:
		// add_filter( 'mvs_strip_dead_bp_links', '__return_true' );
		if ( ! function_exists( 'buddypress' ) && apply_filters( 'mvs_strip_dead_bp_links', false ) ) {
			add_filter( 'wp_nav_menu_objects', array( self::class, 'filter_nav_menu_objects' ), 10 );
		}

		// Flush rewrite rules if needed (after activation).
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 99 );

		// Register Abilities API (WP 6.9+).
		Abilities::init();

		// Plugin-level theme.json — design tokens at lowest priority (theme always wins).
		add_filter( 'wp_theme_json_data_default', array( self::class, 'register_theme_json' ) );

		// Shared UI frame — FAB, upload modal, lightbox (all frontend pages).
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_shared_ui_assets' ) );
		add_action( 'wp_footer', array( self::class, 'render_shared_ui_frame' ) );

		// Frontend-presence policy — single enforcement point. When BuddyNext
		// owns the community UX, MediaVerse paints nothing on non-MVS surfaces.
		// Runs last so it can dequeue every `mvs-` handle (and `@mvs/` module)
		// regardless of which class enqueued it — free, Pro, or future code.
		add_action( 'wp_enqueue_scripts', array( self::class, 'enforce_frontend_presence' ), PHP_INT_MAX );

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
			'storage_router',
			function ( ServiceContainer $c ) {
				return new \WPMediaVerse\Services\StorageRouter( $c->get( 'storage' ) );
			}
		);

		self::$container->register(
			'variant_writer',
			function ( ServiceContainer $c ) {
				return new \WPMediaVerse\Services\MediaVariantWriter( $c->get( 'media_repository' ) );
			}
		);

		self::$container->register(
			'poster',
			function () {
				return new \WPMediaVerse\Services\PosterService();
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
			'admin.tags',
			function () {
				return new \WPMediaVerse\Admin\TagManagementPage();
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
				$bp = new BuddyPressManager();
				$bp->init();
				return $bp;
			}
		);

		self::$container->register(
			'integration.bp_verified_member',
			function () {
				$badge = new \WPMediaVerse\Integrations\BPVerifiedMember\BadgeIntegration();
				$badge->init();
				return $badge;
			}
		);

		self::$container->register(
			'integration.bp_activity_linkage',
			function ( $container ) {
				$linkage = new \WPMediaVerse\Integrations\BuddyPress\ActivityMediaLinkage(
					$container->get( 'media_repository' ),
					$container->get( 'template_helpers' )
				);
				$linkage->init();
				return $linkage;
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

		self::$container->register(
			'image_optimization',
			function () {
				return new \WPMediaVerse\Services\ImageOptimizationService();
			}
		);

		self::$container->register(
			'telemetry',
			function () {
				return new \WPMediaVerse\Services\TelemetryService();
			}
		);

		// Admin aggregates — single source of truth for site-wide counts
		// (total media, views, storage, etc.). Coding Rule #16: every
		// admin/CLI surface MUST read aggregates through this service so the
		// cache layer applies uniformly. Raw $wpdb SUM/COUNT against mvs_*
		// tables outside this service is forbidden by bin/coding-rules-check.sh.
		self::$container->register(
			'admin_aggregates',
			function () {
				return new \WPMediaVerse\Services\AdminAggregatesService(
					self::$container->get( 'cache' )
				);
			}
		);

		// Log pruning cron (daily).
		if ( ! wp_next_scheduled( 'mvs_prune_logs' ) ) {
			wp_schedule_event( time(), 'daily', 'mvs_prune_logs' );
		}
		add_action( 'mvs_prune_logs', array( \WPMediaVerse\Services\LoggerService::class, 'prune' ) );

		// View-retention cron (daily). Bounded growth on mvs_media_views —
		// every page view inserts a row, so without retention the table
		// grows linearly with traffic. Default: 90 days. Site owners can
		// raise (up to 730) or set 0 = retain forever.
		if ( ! wp_next_scheduled( 'mvs_purge_old_views' ) ) {
			wp_schedule_event( time(), 'daily', 'mvs_purge_old_views' );
		}
		add_action( 'mvs_purge_old_views', array( \WPMediaVerse\Services\ViewRetentionService::class, 'purge' ) );

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
			'user_deletion',
			function () {
				$service = new \WPMediaVerse\Services\UserDeletionService();
				$service->init();
				return $service;
			}
		);

		self::$container->register(
			'storage_cleanup',
			function ( $c ) {
				$service = new \WPMediaVerse\Services\StorageCleanupService( $c->get( 'storage' ) );
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

		// Provider-neutral object↔media linkage (1.6.0). Public seam for headless
		// consumers (e.g. BuddyNext) to attach/read media on their own objects
		// (bn_post, bn_space, …) without the BuddyPress save path.
		self::$container->register(
			'object_media',
			function () {
				return new \WPMediaVerse\Media\ObjectMediaLinkage();
			}
		);

		self::$container->register(
			'template_helpers',
			function () {
				return new TemplateHelpers();
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
			new AdminController(),
			new AuthController(),
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

		// All Media — custom listing page. Run row/bulk actions on the page's
		// `load-` hook (before any output) so their wp_safe_redirect() calls do
		// not fire after headers are sent — the optimize row action otherwise
		// triggered "Cannot modify header information - headers already sent".
		$media_hook = add_submenu_page(
			self::ADMIN_SLUG,
			__( 'All Media', 'wpmediaverse' ),
			__( 'All Media', 'wpmediaverse' ),
			'manage_options',
			'mvs-media',
			array( \WPMediaVerse\Admin\MediaListPage::class, 'render' )
		);
		if ( $media_hook ) {
			add_action( 'load-' . $media_hook, array( \WPMediaVerse\Admin\MediaListPage::class, 'handle_bulk_actions' ) );
		}

		// Tags — tag management page.
		add_submenu_page(
			self::ADMIN_SLUG,
			__( 'Tags', 'wpmediaverse' ),
			__( 'Tags', 'wpmediaverse' ),
			'manage_options',
			'mvs-tags',
			array( self::$container->get( 'admin.tags' ), 'render' )
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

	// Note: maybe_sign_file_url() removed in Phase 0a item 5. Signing now
	// lives in \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get($id, 'file_url'/'thumb_*'/'watermark_url')
	// — every emission site automatically gets a signed URL.

	// Note: ensure_media_rows methods removed — media is created directly in custom tables.

	/**
	 * Enqueue frontend styles and scripts on MVS pages.
	 */
	public static function enqueue_frontend_assets(): void {
		// Shared REST client — registered globally so any surface (Pro feed
		// shortcodes, BP tabs, explore, dashboard) can enqueue by handle.
		// Localized with restBase + a fresh nonce so window.mvsRest is ready
		// before any consumer script fires. Consumers add 'mvs-rest' to their
		// deps array to guarantee load order.
		wp_register_script(
			'mvs-rest',
			MVS_PLUGIN_URL . 'assets/js/frontend/mvs-rest.js',
			array(),
			filemtime( MVS_PLUGIN_DIR . 'assets/js/frontend/mvs-rest.js' ),
			array(
				'in_footer' => false,
			)
		);
		wp_localize_script(
			'mvs-rest',
			'mvsRestConfig',
			array(
				'restBase' => esc_url_raw( rest_url( 'mvs/v1' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Card builders + Load More are registered globally (enqueued by
		// handle below on MVS pages) because the Pro feed shortcodes
		// ([mvs_pro_flickr_feed] etc.) render the same grid + Load More
		// button on ordinary pages and enqueue these handles from their
		// Layout::enqueue_assets() (Basecamp #9941413137 — Load More was
		// dead on shortcode pages because these were enqueue-only inside
		// the MVS-page branch). Same shared-handle contract as
		// mvs-explore-search.
		wp_register_script(
			'mvs-card-builders',
			MVS_PLUGIN_URL . 'assets/js/frontend/card-builders.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'mvs-card-builders',
			'mvsCardBuildersI18n',
			array(
				'deleteMedia' => __( 'Delete media', 'wpmediaverse' ),
				'video'       => __( 'Video', 'wpmediaverse' ),
				'viewMedia'   => __( 'View media', 'wpmediaverse' ),
				'stats'       => __( 'Stats', 'wpmediaverse' ),
				'likes'       => __( 'Likes', 'wpmediaverse' ),
				'views'       => __( 'Views', 'wpmediaverse' ),
			)
		);
		wp_register_script(
			'mvs-load-more',
			MVS_PLUGIN_URL . 'assets/js/frontend/load-more.js',
			array( 'mvs-card-builders' ),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_style(
			'mvs-load-more',
			MVS_PLUGIN_URL . 'assets/css/frontend/load-more.css',
			array(),
			MVS_VERSION
		);

		$post_type  = get_post_type();
		$is_mvs     = in_array( $post_type, array( 'mvs_album', 'mvs_collection' ), true );
		$is_archive = is_post_type_archive( 'mvs_album' );
		$is_mvs_tax = is_tax( 'mvs_tag' ) || is_tax( 'mvs_category' );
		$is_mvs_tpl = ! empty( $GLOBALS['mvs_current_media'] )
			|| ! empty( $GLOBALS['mvs_is_media_archive'] )
			|| (bool) get_query_var( 'mvs_edit_profile' )
			|| (bool) get_query_var( 'mvs_profile_user' )
			|| (bool) get_query_var( 'mvs_media_archive' );

		// Detect mapped MVS pages (explore, dashboard, upload) — globals aren't set yet at enqueue time.
		$mvs_page_ids = array_filter(
			array_map(
				'absint',
				array(
					get_option( 'mvs_page_explore', 0 ),
					get_option( 'mvs_page_dashboard', 0 ),
					get_option( 'mvs_page_upload', 0 ),
				)
			)
		);
		$is_mvs_page  = is_page( $mvs_page_ids );

		// Always enqueue on MVS pages or pages with dashboard shortcode.
		if ( $is_mvs || $is_archive || $is_mvs_tax || $is_mvs_tpl || $is_mvs_page ) {
			// Shared REST client — must load before any consumer script.
			wp_enqueue_script( 'mvs-rest' );

			wp_enqueue_style(
				'mvs-frontend',
				MVS_PLUGIN_URL . 'assets/css/frontend.css',
				array(),
				MVS_VERSION
			);

			wp_enqueue_style( 'mvs-load-more' );

			// Lucide icon set — required by templates that use <i data-lucide>.
			// Self-contained on the frontend so we don't depend on the active theme
			// (Reign, BuddyX) loading lucide. Re-running createIcons() after page
			// load picks up icons added by every template helper.
			wp_enqueue_script(
				'mvs-lucide',
				MVS_PLUGIN_URL . 'assets/js/vendor/lucide.min.js',
				array(),
				MVS_VERSION,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_add_inline_script(
				'mvs-lucide',
				'document.addEventListener("DOMContentLoaded",function(){if(window.lucide&&typeof window.lucide.createIcons==="function"){window.lucide.createIcons();}});'
			);

			// wp-i18n exposes window.wp.i18n.__ which our Interactivity-API
			// view scripts (media-social, dashboard-view, shared-ui) call
			// via a runtime shim — script modules cannot import @wordpress/i18n
			// yet (WP limitation as of 6.6). Without wp-i18n present, the shim
			// falls back to the English source string, so this enqueue is the
			// difference between "user sees German toasts" and "user sees
			// English fallback toasts on a German site."
			wp_enqueue_script( 'wp-i18n' );

			// Registered (with mvsCardBuildersI18n) at the top of this method —
			// shared with the Pro feed shortcodes.
			wp_enqueue_script( 'mvs-load-more' );

			// Lightbox + shared UI store — needed on all MVS pages (logged in or out).
			// Use src/ (ESM source) not build/ (IIFE) — matches explore-view pattern.
			wp_enqueue_script_module(
				'@mvs/shared-ui',
				MVS_PLUGIN_URL . 'src/blocks/shared-ui/view.js',
				array( array( 'id' => '@wordpress/interactivity' ) ),
				MVS_VERSION
			);

			// Media social store — reactions, comments, favorites, follow, report on single media/album pages.
			wp_enqueue_script_module(
				'@mvs/media-social',
				MVS_PLUGIN_URL . 'src/blocks/media-social/view.js',
				array( array( 'id' => '@wordpress/interactivity' ) ),
				MVS_VERSION
			);

			wp_enqueue_style(
				'mvs-shared-ui-frame',
				MVS_PLUGIN_URL . 'assets/css/shared-ui-frame.css',
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

			// Lightbox store — registered globally (shared-handle contract,
			// same pattern as mvs-load-more above) so the Pro feed shortcodes
			// ([mvs_pro_flickr_feed] etc.) can enqueue it by id from their
			// Layout::enqueue_assets() on ordinary pages. Without this the
			// feed tiles' data-wp-on--click="actions.openLightbox" has no
			// store and the lightbox is dead off MVS pages (Basecamp
			// #9961961339). Uses build/ (compiled IIFE) — matches the
			// enqueue_shared_ui_assets() path; register-only here, so it is a
			// no-op on pages that never enqueue it by handle. The MVS-page
			// branch above intentionally keeps its own src/ enqueue, so this
			// global registration never pre-empts native-page behavior.
			$mvs_shared_ui_asset = file_exists( MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php' )
				? require MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php'
				: array(
					'dependencies' => array( array( 'id' => '@wordpress/interactivity' ) ),
					'version'      => MVS_VERSION,
				);
			wp_register_script_module(
				'@mvs/shared-ui',
				MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
				$mvs_shared_ui_asset['dependencies'],
				$mvs_shared_ui_asset['version']
			);

			// Lightbox dialog frame stylesheet — registered so the Pro feed
			// layouts (or any shortcode that forces the frame) can enqueue it
			// by handle. Register-only; harmless on pages that never use it.
			wp_register_style(
				'mvs-shared-ui-frame',
				MVS_PLUGIN_URL . 'assets/css/shared-ui-frame.css',
				array(),
				MVS_VERSION
			);
		}

		// BP-integration stylesheet is always registered (not enqueued) and
		// loaded by ProfileTabIntegration / GroupTabIntegration on their own
		// screens. Depending on `mvs-frontend` means it loads after the
		// shared tokens/components, so BP-scoped rules win the cascade and
		// our sub-tab/dropzone/grid-gap spacing is never defeated by a
		// theme stylesheet that happened to register later.
		wp_register_style(
			'mvs-bp-integration',
			MVS_PLUGIN_URL . 'assets/css/bp-integration.css',
			array( 'mvs-frontend' ),
			MVS_VERSION
		);

		// Lucide is registered globally via the `wp_enqueue_scripts@1` hook
		// set up in Plugin::init(). No per-integration re-registration
		// needed anywhere else in the codebase.

		// Frontend confirmation modal — exposes window.mvsConfirm() as a
		// promise-based replacement for native window.confirm(). Loaded as a
		// dep of any frontend script that needs styled confirmation
		// (bp-actions, dashboard-connectors). Idempotent: if Pro admin's
		// ConfirmDialog has already installed window.mvsConfirm, this is a
		// no-op. See assets/js/frontend/mvs-confirm.js for API contract.
		wp_register_script(
			'mvs-confirm',
			MVS_PLUGIN_URL . 'assets/js/frontend/mvs-confirm.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'mvs-confirm',
			'mvsConfirmI18n',
			array(
				'areYouSure' => __( 'Are you sure?', 'wpmediaverse' ),
				'confirm'    => __( 'Confirm', 'wpmediaverse' ),
				'cancel'     => __( 'Cancel', 'wpmediaverse' ),
				'delete'     => __( 'Delete', 'wpmediaverse' ),
			)
		);
		wp_register_style(
			'mvs-confirm',
			MVS_PLUGIN_URL . 'assets/css/mvs-confirm.css',
			array(),
			MVS_VERSION
		);

		// Explore search (media/users tab switch + debounced user search).
		// Shared by the Free explore template and the Pro feed layouts, which
		// each enqueue this handle in place of a duplicated inline <script>.
		// Config + translated strings flow through wp_localize_script.
		wp_register_script(
			'mvs-explore-search',
			MVS_PLUGIN_URL . 'assets/js/frontend/explore-search.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'mvs-explore-search',
			'mvsExploreSearch',
			array(
				'restUrl' => esc_url_raw( rest_url( 'mvs/v1/users/search' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'searchUsers' => __( 'Search users...', 'wpmediaverse' ),
					'searchMedia' => __( 'Search media...', 'wpmediaverse' ),
					'noUsers'     => __( 'No users found.', 'wpmediaverse' ),
					'media'       => __( 'media', 'wpmediaverse' ),
				),
			)
		);

		// Profile actions (Follow toggle + Message button). Shared by the
		// profile templates in place of the inline partials/profile-actions-js.
		// The follow button carries its own data-* config; only the toggle
		// labels are localized here.
		wp_register_script(
			'mvs-profile-actions',
			MVS_PLUGIN_URL . 'assets/js/frontend/profile-actions.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'mvs-profile-actions',
			'mvsProfileActions',
			array(
				'i18n' => array(
					'following' => __( 'Following', 'wpmediaverse' ),
					'follow'    => __( 'Follow', 'wpmediaverse' ),
				),
			)
		);

		// Album upload (owner-only dropzone). Registered globally; the album
		// template enqueues it and localizes the page-specific config (album id,
		// rest url, nonce) + translatable status strings via wp_localize_script.
		wp_register_script(
			'mvs-album-upload',
			MVS_PLUGIN_URL . 'assets/js/frontend/album-upload.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Dismissible callouts (logged-out CTA banner, profile prompt). No data
		// or strings — pure localStorage hide-and-remember. Templates that emit
		// a dismissible enqueue this handle in place of an inline snippet.
		wp_register_script(
			'mvs-dismissible',
			MVS_PLUGIN_URL . 'assets/js/frontend/dismissible.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Messages page scroll-into-view + collection grid filter — small,
		// config-free behavioral scripts the respective templates enqueue.
		wp_register_script(
			'mvs-messages-scroll',
			MVS_PLUGIN_URL . 'assets/js/frontend/messages-scroll.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_script(
			'mvs-collection-filter',
			MVS_PLUGIN_URL . 'assets/js/frontend/collection-filter.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// Album page scripts: audio playlist player + owner-only cover setter.
		// The album template enqueues each and localizes its page-specific data
		// (tracks / album id / rest url / nonce / labels) via wp_localize_script.
		wp_register_script(
			'mvs-album-playlist',
			MVS_PLUGIN_URL . 'assets/js/frontend/album-playlist.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_register_script(
			'mvs-album-cover',
			MVS_PLUGIN_URL . 'assets/js/frontend/album-cover.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// BP-integration script — wires up per-item delete/edit actions on
		// owner-visible grid cards. Declares `mvs-lucide` as a dep so the
		// `<i data-lucide="trash-2">` icons we emit on action buttons are
		// guaranteed to render even if the shared-ui shell didn't enqueue
		// it. Also depends on `mvs-confirm` because the destructive delete
		// flows call window.mvsConfirm() instead of window.confirm().
		// Config is injected inline so no extra request is needed.
		wp_register_script(
			'mvs-bp-actions',
			MVS_PLUGIN_URL . 'assets/js/frontend/bp-actions.js',
			array( 'mvs-lucide', 'mvs-confirm' ),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		// BP profile / group Media tab — enqueue confirm + BP-integration CSS
		// early, inside wp_enqueue_scripts, so they land in <head>.
		// The scripts (mvs-bp-actions, mvs-confirm) are enqueued later by
		// BaseBPTabIntegration::enqueue_assets() inside bp_template_content,
		// but CSS has no footer fallback, so it must be in the queue before
		// wp_head() fires. The auto_enqueue_confirm_style() hook at priority
		// 100 fires before the BP screen function adds its late script enqueue,
		// so the auto-pair misses it on BP media tab pages.
		$is_bp_media_tab =
			( function_exists( 'bp_is_user' ) && bp_is_user()
				&& function_exists( 'bp_current_component' ) && 'media' === bp_current_component() )
			|| ( function_exists( 'bp_is_group' ) && bp_is_group()
				&& function_exists( 'bp_current_action' ) && 'media' === bp_current_action() );

		if ( $is_bp_media_tab ) {
			wp_enqueue_style( 'mvs-confirm' );
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
	 * Clean up dead BuddyPress component links when BuddyPress is not active.
	 *
	 * Only runs when BuddyPress is inactive (see the registration guard). Its job
	 * is to drop menu items that point at BP component archives (/members/,
	 * /groups/, /activity/) which would 404 once BuddyPress is gone, and to
	 * rewrite "My Profile" (/members/me/) to the media dashboard.
	 *
	 * Per Coding Rule #17 the plugin must never delete a site owner's authored,
	 * working navigation. So this never removes an item that resolves to a real
	 * published page, and it bails entirely when another community plugin (e.g.
	 * BuddyNext) owns these routes as live pages. Both the cleanup itself and the
	 * pattern list are filterable so any behavior can be restored (Production
	 * Rule #3).
	 *
	 * @since 1.0.0
	 * @since 1.7.1 No longer removes items that resolve to a real page or when a
	 *              sibling community plugin is active; added escape-hatch filters.
	 *
	 * @param array $items Sorted menu items.
	 * @return array Filtered menu items.
	 */
	public static function filter_nav_menu_objects( array $items ): array {
		// Defensive: even when explicitly opted in, never touch the menu while a
		// sibling community plugin (e.g. BuddyNext) owns /activity/ + /members/
		// as live pages — removing those would delete working navigation.
		if ( apply_filters( 'mvs_buddynext_active', false ) ) {
			return $items;
		}

		/**
		 * URL fragments treated as dead BuddyPress component links when BP is off.
		 *
		 * @since 1.7.1
		 * @param string[] $patterns Default members/groups/activity archives.
		 */
		$bp_patterns = (array) apply_filters(
			'mvs_dead_bp_link_patterns',
			array( '/members/', '/groups/', '/activity/' )
		);

		$dashboard_url = '';
		$dashboard_id  = (int) get_option( 'mvs_page_dashboard' );
		if ( $dashboard_id ) {
			$dashboard_url = get_permalink( $dashboard_id );
		}

		$filtered = array();
		foreach ( $items as $item ) {
			$url = isset( $item->url ) ? (string) $item->url : '';

			$is_bp_link = false;
			foreach ( $bp_patterns as $pattern ) {
				if ( '' !== $pattern && false !== strpos( $url, $pattern ) ) {
					$is_bp_link = true;
					break;
				}
			}

			// Keep non-BP links and any BP-pattern link that resolves to a real
			// published page (a live page is the owner's content, never dead).
			if ( ! $is_bp_link || 0 !== url_to_postid( $url ) ) {
				$filtered[] = $item;
				continue;
			}

			// "My Profile" (/members/me/) — rewrite to the dashboard if set.
			if ( false !== strpos( $url, '/members/me' ) && '' !== $dashboard_url ) {
				$item->url   = $dashboard_url;
				$item->title = __( 'My Media', 'wpmediaverse' );
				$filtered[]  = $item;
				continue;
			}

			// Genuinely dead BP component link — drop it.
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

		// Online status visibility — the target user's per-user setting wins,
		// falling back to the site-wide option. Applied with ($show, $viewer_id,
		// $target_id) by MessagingService.
		add_filter(
			'mvs_show_online_status',
			function ( $show, $viewer_id = 0, $target_id = 0 ) {
				$setting = $target_id ? get_user_meta( $target_id, '_mvs_show_online', true ) : '';
				if ( '' === $setting || false === $setting ) {
					$setting = get_option( 'mvs_show_online_status', 'everyone' );
				}
				if ( 'nobody' === $setting ) {
					return false;
				}
				return $show;
			},
			5,
			3
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
		if ( function_exists( 'wp_set_script_module_translations' ) ) {
			wp_set_script_module_translations( 'mvs-messaging', 'wpmediaverse', MVS_PLUGIN_DIR . 'languages' );
		}
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
	 * Register the `mvs-lucide` script + hydration inline once per request.
	 *
	 * Central helper so every integration that emits `<i data-lucide="…">`
	 * can call `Plugin::register_lucide_script()` + `wp_enqueue_script(
	 * 'mvs-lucide' )` without re-implementing the registration and without
	 * diverging on the hydration logic.
	 *
	 * The inline hydration does two things that a single `createIcons()`
	 * on `DOMContentLoaded` cannot do:
	 *
	 *   1. Hydrates on DCL (covers the static initial render).
	 *   2. Installs a MutationObserver that calls `createIcons()` again
	 *      whenever a fresh `<i data-lucide>` enters the DOM. This is the
	 *      fix for BP Nouveau's Backbone-driven composer re-render, the
	 *      WP Interactivity API dynamic inserts, and any future dynamic
	 *      surface that injects Lucide placeholders after initial load.
	 *
	 * Prior pattern: every integration duplicated a `wp_register_script(
	 * 'mvs-lucide', ... ) + wp_add_inline_script( ... )` block. Four such
	 * blocks existed and any one forgetting or diverging produced the
	 * "icon renders as an empty box" class of bug we kept re-fixing.
	 */
	/**
	 * Late-priority callback that pairs the `mvs-confirm` stylesheet with its
	 * script automatically. Any code path that calls
	 * wp_enqueue_script('mvs-confirm') (directly or via a dep chain like
	 * mvs-bp-actions → mvs-confirm) gets the matching stylesheet without
	 * having to remember a second wp_enqueue_style call. Runs on both
	 * frontend and admin so the helper styles correctly in every context.
	 */
	public static function auto_enqueue_confirm_style(): void {
		if ( wp_script_is( 'mvs-confirm', 'enqueued' ) && wp_style_is( 'mvs-confirm', 'registered' ) && ! wp_style_is( 'mvs-confirm', 'enqueued' ) ) {
			wp_enqueue_style( 'mvs-confirm' );
		}
	}

	public static function register_lucide_script(): void {
		if ( wp_script_is( 'mvs-lucide', 'registered' ) ) {
			return;
		}

		wp_register_script(
			'mvs-lucide',
			MVS_PLUGIN_URL . 'assets/js/vendor/lucide.min.js',
			array(),
			MVS_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		$inline = <<<'JS'
(function () {
	function run() {
		if ( window.lucide && typeof window.lucide.createIcons === 'function' ) {
			window.lucide.createIcons();
		}
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', run );
	} else {
		run();
	}
	if ( ! window.MutationObserver ) { return; }
	var scheduled = false;
	var observer = new MutationObserver( function ( mutations ) {
		if ( scheduled ) { return; }
		for ( var i = 0; i < mutations.length; i++ ) {
			var added = mutations[ i ].addedNodes;
			for ( var j = 0; j < added.length; j++ ) {
				var n = added[ j ];
				if ( n.nodeType !== 1 ) { continue; }
				var hasLucide =
					( n.matches && n.matches( 'i[data-lucide]' ) ) ||
					( n.querySelector && n.querySelector( 'i[data-lucide]' ) );
				if ( hasLucide ) {
					scheduled = true;
					( window.requestAnimationFrame || window.setTimeout )( function () {
						scheduled = false;
						run();
					} );
					return;
				}
			}
		}
	} );
	function attach() {
		if ( document.body ) {
			observer.observe( document.body, { childList: true, subtree: true } );
		} else {
			setTimeout( attach, 50 );
		}
	}
	attach();
} )();
JS;

		wp_add_inline_script( 'mvs-lucide', $inline );
	}

	/**
	 * Whether the current front-end request is a MediaVerse-owned surface.
	 *
	 * Covers single media, albums, collections, the album archive, MVS
	 * taxonomies, profile/edit-profile, the media archive, and the mapped
	 * Explore / Dashboard / Upload pages. Used to decide whether the shared
	 * UI frame (FAB, upload modal, lightbox) should mount: when BuddyNext is
	 * active it owns the media UX on its own and all other pages, so the
	 * engine only keeps its shared UI on these dedicated surfaces.
	 *
	 * @return bool True when the request is a MediaVerse-owned page.
	 */
	private static function is_mvs_frontend_context(): bool {
		$post_type = get_post_type();
		if ( in_array( $post_type, array( 'mvs_album', 'mvs_collection' ), true ) ) {
			return true;
		}

		if ( is_post_type_archive( 'mvs_album' ) || is_tax( 'mvs_tag' ) || is_tax( 'mvs_category' ) ) {
			return true;
		}

		if ( ! empty( $GLOBALS['mvs_current_media'] )
			|| ! empty( $GLOBALS['mvs_is_media_archive'] )
			|| (bool) get_query_var( 'mvs_edit_profile' )
			|| (bool) get_query_var( 'mvs_profile_user' )
			|| (bool) get_query_var( 'mvs_media_archive' ) ) {
			return true;
		}

		$mvs_page_ids = array_filter(
			array_map(
				'absint',
				array(
					get_option( 'mvs_page_explore', 0 ),
					get_option( 'mvs_page_dashboard', 0 ),
					get_option( 'mvs_page_upload', 0 ),
				)
			)
		);

		return ! empty( $mvs_page_ids ) && is_page( $mvs_page_ids );
	}

	/**
	 * Whether MediaVerse must NOT paint its own front-end UI on this request.
	 *
	 * The single source of truth for the frontend-presence policy. When
	 * BuddyNext is the active community layer it owns the media UX on its own
	 * pages and every non-MediaVerse surface (blog, WooCommerce, landing
	 * pages); MediaVerse keeps its UI only on its own dedicated surfaces
	 * (see is_mvs_frontend_context()). The `mvs_suppress_frontend_ui` filter
	 * lets a site or Pro override the decision per request.
	 *
	 * @return bool True when MediaVerse front-end assets/markup must stand down.
	 */
	public static function frontend_ui_suppressed(): bool {
		$suppressed = apply_filters( 'mvs_buddynext_active', false )
			&& ! self::is_mvs_frontend_context();

		return (bool) apply_filters( 'mvs_suppress_frontend_ui', $suppressed );
	}

	/**
	 * Enforce the frontend-presence policy at one chokepoint.
	 *
	 * Runs on `wp_enqueue_scripts` at PHP_INT_MAX — after every class (free,
	 * Pro, and any future code) has registered its assets. When the policy
	 * says stand down, dequeue + deregister every enqueued handle prefixed
	 * `mvs-` and every script module id prefixed `@mvs/`. Keying on the
	 * handle-prefix naming contract means new enqueue points are covered
	 * automatically — no per-call guards to maintain.
	 *
	 * @return void
	 */
	public static function enforce_frontend_presence(): void {
		if ( is_admin() || ! self::frontend_ui_suppressed() ) {
			return;
		}

		// Classic styles + scripts: dequeue and deregister anything `mvs-*`.
		foreach ( array( wp_styles(), wp_scripts() ) as $registry ) {
			if ( ! $registry instanceof \WP_Dependencies ) {
				continue;
			}
			foreach ( (array) $registry->queue as $handle ) {
				if ( 0 === strpos( (string) $handle, 'mvs-' ) ) {
					if ( $registry === wp_styles() ) {
						wp_dequeue_style( $handle );
						wp_deregister_style( $handle );
					} else {
						wp_dequeue_script( $handle );
						wp_deregister_script( $handle );
					}
				}
			}
		}

		// Script modules (Interactivity API): `@mvs/...` ids. The modules
		// registry has no public queue accessor, so dequeue the known module
		// ids; registration is idempotent and dequeue of an unqueued id is a
		// no-op, so listing the engine's modules here is safe and complete.
		if ( function_exists( 'wp_dequeue_script_module' ) ) {
			foreach ( array( '@mvs/shared-ui', '@mvs/media-social', 'mvs-messaging' ) as $module_id ) {
				wp_dequeue_script_module( $module_id );
			}
		}
	}

	/**
	 * Enqueue shared UI shell assets (FAB, upload modal, lightbox).
	 */
	public static function enqueue_shared_ui_assets(): void {
		if ( ! is_user_logged_in() || is_admin() ) {
			return;
		}

		// Single policy: stand down on non-MediaVerse surfaces when BuddyNext
		// owns the UX. The PHP_INT_MAX enforcer is the catch-all; bailing here
		// too avoids registering assets we would only dequeue moments later.
		if ( self::frontend_ui_suppressed() ) {
			return;
		}

		wp_enqueue_style(
			'mvs-shared-ui-frame',
			MVS_PLUGIN_URL . 'assets/css/shared-ui-frame.css',
			array(),
			MVS_VERSION
		);

		// Deprecation shim: third-party callers using the legacy
		// `mvs-shared-ui-shell` handle still resolve to the new file. The
		// handle name itself is internal — no surface re-uses it — so the
		// shim is register-only (won't emit a `<link id="mvs-shared-ui-shell-css">`
		// unless someone explicitly enqueues it). Slated for removal in 1.3.0.
		// Customer ask (Crisp #NZRSBX): WAFs flag any "shell" token, so this
		// alias exists strictly so an unrelated theme/plugin enqueueing
		// `mvs-shared-ui-shell` doesn't 404 during the migration window.
		wp_register_style(
			'mvs-shared-ui-shell',
			MVS_PLUGIN_URL . 'assets/css/shared-ui-frame.css',
			array( 'mvs-shared-ui-frame' ),
			MVS_VERSION
		);

		// Lucide is already registered on `wp_enqueue_scripts@1` globally.
		wp_enqueue_script( 'mvs-lucide' );

		$mvs_shared_ui_asset = file_exists( MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php' )
			? require MVS_PLUGIN_DIR . 'build/blocks/shared-ui/view.asset.php'
			: array(
				'dependencies' => array(),
				'version'      => MVS_VERSION,
			);
		wp_enqueue_script_module(
			'@mvs/shared-ui',
			MVS_PLUGIN_URL . 'build/blocks/shared-ui/view.js',
			$mvs_shared_ui_asset['dependencies'],
			$mvs_shared_ui_asset['version']
		);
	}

	/**
	 * Render the shared UI frame in wp_footer (FAB, upload modal, lightbox).
	 */
	public static function render_shared_ui_frame(): void {
		static $rendered = false;

		if ( is_admin() || $rendered ) {
			return;
		}

		// Single policy (same predicate as the asset enforcer): do not print
		// the MediaVerse app shell on non-MVS surfaces when BuddyNext owns UX.
		if ( self::frontend_ui_suppressed() ) {
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
		$mvs_frame_page_ids = array_filter(
			array_map(
				'absint',
				array(
					get_option( 'mvs_page_explore', 0 ),
					get_option( 'mvs_page_dashboard', 0 ),
					get_option( 'mvs_page_upload', 0 ),
				)
			)
		);
		$is_mvs_frame_page  = ! empty( $mvs_frame_page_ids ) && is_page( $mvs_frame_page_ids );

		// Pro feed shortcodes / blocks placed on an ordinary page force the
		// frame so their lightbox-trigger tiles have a dialog to open
		// (Basecamp #9961961339). The frame's FAB stays gated to MVS pages
		// (handled inside the partial); only the lightbox dialog is needed
		// here.
		$forced = self::$force_shared_ui_frame;

		if ( ! $forced && ! is_user_logged_in() && ! $is_mvs && ! $is_archive && ! $is_mvs_tax && ! $is_mvs_tpl && ! $is_mvs_frame_page ) {
			return;
		}

		$template = MVS_PLUGIN_DIR . 'templates/partials/shared-ui-frame.php';
		if ( file_exists( $template ) ) {
			$rendered = true;
			include $template;
		}
	}

	/**
	 * Render the slide-out chat panel in wp_footer for logged-in users.
	 *
	 * The slide-out chat panel is the compact DM UI (the floating chat
	 * icon + the docked panel that opens from it). It used to appear on
	 * every page for logged-in users, which produced visual clutter on
	 * landing pages, blog posts, and other non-MVS surfaces. Visibility
	 * is now controlled by the `mvs_chat_panel_visibility` admin setting
	 * — see SettingsRegistrar::register_messaging_settings().
	 *
	 * Suppression order (any condition skips the render):
	 *   1. Anonymous viewer (panel needs auth state).
	 *   2. BuddyNext active (delegated DM UI).
	 *   3. The dedicated /messages/ full-page template — already renders
	 *      its own chat UI; a second mount would dual-init the
	 *      mvs/messaging Interactivity API store.
	 *   4. Setting = 'disabled'.
	 *   5. Setting = 'mvs_pages' AND the current request is NOT one of
	 *      the plugin-owned URLs (Explore archive, Dashboard, Album,
	 *      Collection, Profile, single-media, BP profile/group when MVS
	 *      tab is active).
	 *   6. Setting = 'bp_pages' AND we're not on a BuddyPress profile
	 *      or group page.
	 *   7. The `mvs_should_render_chat_panel` filter returns false.
	 *      Lets themes / plugins force a per-page override (e.g. hide on
	 *      checkout pages, on a WooCommerce single-product, etc.).
	 */
	public static function render_chat_panel(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}

		// Suppress when BuddyNext handles DM UI.
		if ( apply_filters( 'mvs_buddynext_active', false ) ) {
			return;
		}

		// Suppress on the dedicated `/messages/` full-page template.
		if ( (int) get_query_var( 'mvs_messages_page' ) ) {
			return;
		}

		$visibility = (string) get_option( 'mvs_chat_panel_visibility', 'everywhere' );

		if ( 'disabled' === $visibility ) {
			return;
		}

		if ( 'mvs_pages' === $visibility && ! self::is_mvs_page() ) {
			return;
		}

		if ( 'bp_pages' === $visibility && ! self::is_bp_page() ) {
			return;
		}

		/**
		 * Filter the chat panel render decision.
		 *
		 * Use this to force a per-page override that the visibility
		 * setting can't express — e.g. hide on WooCommerce checkout, on
		 * specific post IDs, or based on user role.
		 *
		 * @since 1.2.0
		 *
		 * @param bool   $should_render Default decision (true at this point).
		 * @param string $visibility    Resolved visibility setting that got us
		 *                              here — one of `everywhere`, `mvs_pages`,
		 *                              `bp_pages`. Lets callbacks scope their
		 *                              override by the admin's intent (e.g.
		 *                              suppress only when the admin chose
		 *                              `everywhere`, leave page-specific
		 *                              modes alone).
		 */
		if ( ! apply_filters( 'mvs_should_render_chat_panel', true, $visibility ) ) {
			return;
		}

		$template = MVS_PLUGIN_DIR . 'templates/partials/chat-panel.php';
		if ( file_exists( $template ) ) {
			include $template;
		}
	}

	/**
	 * Is the current request a WPMediaVerse-owned page surface?
	 *
	 * Used by `render_chat_panel()` when visibility = 'mvs_pages'.
	 * Recognises: media archive (Explore), single media, album CPT,
	 * collection CPT, dashboard page, upload page, member profile when
	 * the Media tab is the current sub-nav.
	 *
	 * @return bool
	 */
	private static function is_mvs_page(): bool {
		if ( ! empty( $GLOBALS['mvs_current_media'] ) ) {
			return true;
		}
		if ( ! empty( $GLOBALS['mvs_is_media_archive'] ) ) {
			return true;
		}
		if ( is_singular( array( 'mvs_album', 'mvs_collection' ) ) ) {
			return true;
		}
		// Plugin-owned WP Pages — IDs come from the standard slot map.
		$page_ids = array_filter(
			array(
				(int) get_option( 'mvs_page_dashboard', 0 ),
				(int) get_option( 'mvs_page_explore', 0 ),
				(int) get_option( 'mvs_page_upload', 0 ),
			)
		);
		if ( $page_ids && is_page( $page_ids ) ) {
			return true;
		}
		// BP member profile with the MVS Media tab active.
		if ( function_exists( 'bp_is_user' ) && bp_is_user() && function_exists( 'bp_current_component' ) && 'media' === bp_current_component() ) {
			return true;
		}
		// BP group with the Media tab active.
		if ( function_exists( 'bp_is_group' ) && bp_is_group() && function_exists( 'bp_current_action' ) && 'media' === bp_current_action() ) {
			return true;
		}
		return false;
	}

	/**
	 * Is the current request any BuddyPress page?
	 *
	 * @return bool
	 */
	private static function is_bp_page(): bool {
		return function_exists( 'is_buddypress' ) && is_buddypress();
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
