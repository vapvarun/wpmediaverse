<?php
/**
 * Main plugin bootstrap.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\PostTypes\Media;
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
use WPMediaVerse\Services\PaymentBridgeService;
use WPMediaVerse\Services\WatermarkService;
use WPMediaVerse\REST\Controller\CheckoutController;
use WPMediaVerse\Admin\ModerationQueue;
use WPMediaVerse\Admin\StatsPage;
use WPMediaVerse\Social\ReactionService;
use WPMediaVerse\Social\CommentService;
use WPMediaVerse\Social\FavoriteService;
use WPMediaVerse\Social\MentionService;
use WPMediaVerse\Social\ShareService;
use WPMediaVerse\Services\StatsService;
use WPMediaVerse\Services\AccessRulesService;
use WPMediaVerse\Integrations\BuddyPressIntegration;
use WPMediaVerse\Integrations\WebhookService;

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
		// Load textdomain.
		load_plugin_textdomain( 'wpmediaverse', false, 'wpmediaverse/languages' );

		// Build service container.
		self::$container = new ServiceContainer();
		self::register_services();

		// Register CPTs, taxonomies, and blocks.
		add_action( 'init', array( self::class, 'register_types' ) );

		$blocks = new BlockRegistrar();
		$blocks->init();

		$shortcodes = new Shortcodes();
		$shortcodes->init();

		$templates = new TemplateLoader();
		$templates->init();

		// Admin hooks.
		if ( is_admin() ) {
			self::$container->get( 'admin.settings' );
			self::$container->get( 'admin.moderation' );
			self::$container->get( 'admin.stats' );
		}

		// Register REST API routes.
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

		// Initialize story cleanup cron.
		self::$container->get( 'stories' );

		// Initialize moderation hooks.
		self::$container->get( 'moderation' );

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

		// Integrations (conditionally loaded).
		self::$container->get( 'integration.buddypress' );
		self::$container->get( 'integration.webhooks' );

		// Action Scheduler callback for async webhook delivery.
		add_action( 'mvs_deliver_webhook', array( self::class, 'deliver_webhook' ), 10, 3 );

		// Flush rewrite rules if needed (after activation).
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 99 );

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
			'payments',
			function ( ServiceContainer $c ) {
				$service = new PaymentBridgeService( $c->get( 'access_rules' ) );
				$service->init();
				return $service;
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
		$payments     = self::$container->get( 'payments' );

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
			new SignedUrlController( $signed_urls ),
			new CheckoutController( $payments ),
		);

		foreach ( $controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Register custom post types and taxonomies.
	 */
	public static function register_types(): void {
		Media::register();
		Album::register();
		Collection::register();
		MediaTag::register();
		MediaCategory::register();
	}

	/**
	 * Flush rewrite rules once after activation.
	 */
	public static function maybe_flush_rewrites(): void {
		if ( get_transient( 'mvs_flush_rewrite' ) ) {
			delete_transient( 'mvs_flush_rewrite' );
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
	 */
	public static function deliver_webhook( string $url, string $body, array $headers ): void {
		$webhooks = self::$container->get( 'integration.webhooks' );
		$webhooks->send( $url, $body, $headers );
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

	/**
	 * Get the service container.
	 *
	 * @return ServiceContainer
	 */
	public static function container(): ServiceContainer {
		return self::$container;
	}
}
