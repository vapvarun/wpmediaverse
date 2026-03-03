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
use WPMediaVerse\REST\Controller\MediaController;
use WPMediaVerse\REST\Controller\AlbumController;
use WPMediaVerse\REST\Controller\CollectionController;
use WPMediaVerse\REST\Controller\BulkController;
use WPMediaVerse\REST\Controller\ReactionController;
use WPMediaVerse\REST\Controller\CommentController;
use WPMediaVerse\REST\Controller\FavoriteController;
use WPMediaVerse\REST\Controller\StatsController;
use WPMediaVerse\Social\ReactionService;
use WPMediaVerse\Social\CommentService;
use WPMediaVerse\Social\FavoriteService;
use WPMediaVerse\Social\MentionService;
use WPMediaVerse\Social\ShareService;
use WPMediaVerse\Services\StatsService;

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

		// Register CPTs and taxonomies.
		add_action( 'init', array( self::class, 'register_types' ) );

		// Admin hooks.
		if ( is_admin() ) {
			self::$container->get( 'admin.settings' );
		}

		// Register REST API routes.
		add_action( 'rest_api_init', array( self::class, 'register_rest_routes' ) );

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
	}

	/**
	 * Register REST API routes.
	 */
	public static function register_rest_routes(): void {
		$privacy = self::$container->get( 'privacy' );

		$reactions = self::$container->get( 'reactions' );
		$comments  = self::$container->get( 'comments' );

		$controllers = array(
			new MediaController( $privacy ),
			new AlbumController(),
			new CollectionController(),
			new BulkController(),
			new ReactionController( $reactions ),
			new CommentController( $comments ),
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
	 * Get the service container.
	 *
	 * @return ServiceContainer
	 */
	public static function container(): ServiceContainer {
		return self::$container;
	}
}
