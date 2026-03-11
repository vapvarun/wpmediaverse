<?php
/**
 * WordPress Abilities API registration (WP 6.9+).
 *
 * Registers WPMediaVerse abilities so mobile apps, AI agents, and other
 * clients can discover plugin features via the REST Abilities API.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Abilities registration for WPMediaVerse.
 */
class Abilities {

	/**
	 * Hook into Abilities API registration.
	 */
	public static function init(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
	}

	/**
	 * Register the WPMediaVerse ability category.
	 */
	public static function register_categories(): void {
		wp_register_ability_category(
			'wpmediaverse',
			array(
				'label'       => __( 'WPMediaVerse', 'wpmediaverse' ),
				'description' => __( 'Media management, social, and content abilities.', 'wpmediaverse' ),
			)
		);
	}

	/**
	 * Register all WPMediaVerse abilities.
	 */
	public static function register_abilities(): void {
		$abilities = self::get_ability_definitions();

		foreach ( $abilities as $name => $args ) {
			wp_register_ability( $name, $args );
		}
	}

	/**
	 * Get all ability definitions.
	 *
	 * @return array<string, array>
	 */
	private static function get_ability_definitions(): array {
		return array(
			// --- Media Management ---
			'wpmediaverse/upload-media'      => array(
				'label'               => __( 'Upload Media', 'wpmediaverse' ),
				'description'         => __( 'Upload images, videos, audio, and documents.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'can_upload' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/list-media'        => array(
				'label'               => __( 'List Media', 'wpmediaverse' ),
				'description'         => __( 'Browse and search media items with filters.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			),
			'wpmediaverse/manage-media'      => array(
				'label'               => __( 'Manage Media', 'wpmediaverse' ),
				'description'         => __( 'Edit, delete, and set privacy on owned media.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/draft-media'       => array(
				'label'               => __( 'Draft & Schedule Media', 'wpmediaverse' ),
				'description'         => __( 'Save media as draft or schedule for future publishing.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'can_upload' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),

			// --- Albums & Collections ---
			'wpmediaverse/manage-albums'     => array(
				'label'               => __( 'Manage Albums', 'wpmediaverse' ),
				'description'         => __( 'Create, edit, and delete media albums and playlists.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/manage-collections' => array(
				'label'               => __( 'Manage Collections', 'wpmediaverse' ),
				'description'         => __( 'Create smart collections with auto-matching rules.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),

			// --- Social ---
			'wpmediaverse/reactions'          => array(
				'label'               => __( 'React to Media', 'wpmediaverse' ),
				'description'         => __( 'Add reactions (like, love, haha, wow, sad, angry) to media.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/comments'          => array(
				'label'               => __( 'Comment on Media', 'wpmediaverse' ),
				'description'         => __( 'Post threaded comments with 15-minute edit window.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/favorites'         => array(
				'label'               => __( 'Favorite Media', 'wpmediaverse' ),
				'description'         => __( 'Save media to favorites for quick access.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/follow-users'      => array(
				'label'               => __( 'Follow Users', 'wpmediaverse' ),
				'description'         => __( 'Follow and unfollow users to see their content in your feed.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/share-media'       => array(
				'label'               => __( 'Share Media', 'wpmediaverse' ),
				'description'         => __( 'Share media to social platforms (Facebook, Twitter, LinkedIn, email).', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			),
			'wpmediaverse/mentions'          => array(
				'label'               => __( '@Mention Users', 'wpmediaverse' ),
				'description'         => __( 'Mention users in comments and media descriptions.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),

			// --- Safety ---
			'wpmediaverse/report-content'    => array(
				'label'               => __( 'Report Content', 'wpmediaverse' ),
				'description'         => __( 'Report media or users for policy violations.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),
			'wpmediaverse/block-users'       => array(
				'label'               => __( 'Block Users', 'wpmediaverse' ),
				'description'         => __( 'Block users to hide them from feed, search, and comments.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => false ),
				),
			),

			// --- Discovery ---
			'wpmediaverse/activity-feed'     => array(
				'label'               => __( 'Activity Feed', 'wpmediaverse' ),
				'description'         => __( 'View public or following-scoped activity feed.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			),
			'wpmediaverse/notifications'     => array(
				'label'               => __( 'Notifications', 'wpmediaverse' ),
				'description'         => __( 'View and manage notification center with unread counts.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => array( self::class, 'is_logged_in' ),
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			),
			'wpmediaverse/user-profiles'     => array(
				'label'               => __( 'User Profiles', 'wpmediaverse' ),
				'description'         => __( 'View public user profiles with stats, media, and follow counts.', 'wpmediaverse' ),
				'category'            => 'wpmediaverse',
				'execute_callback'    => array( self::class, 'noop' ),
				'permission_callback' => '__return_true',
				'meta'                => array(
					'show_in_rest' => true,
					'annotations'  => array( 'readonly' => true ),
				),
			),
		);
	}

	/**
	 * No-op execute callback for declarative abilities.
	 *
	 * @return true
	 */
	public static function noop() {
		return true;
	}

	/**
	 * Permission: user can upload media.
	 *
	 * @return bool
	 */
	public static function can_upload(): bool {
		return current_user_can( 'upload_mvs_media' );
	}

	/**
	 * Permission: user is logged in.
	 *
	 * @return bool
	 */
	public static function is_logged_in(): bool {
		return is_user_logged_in();
	}
}
