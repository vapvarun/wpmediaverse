<?php
/**
 * User deletion cleanup service.
 *
 * Hooks into WordPress's user-deletion lifecycle and removes every row
 * owned by the deleted user across wpmediaverse custom tables, preventing
 * orphaned records that previously lived on after `deleted_user` fired.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Registers WordPress hooks that cascade user deletion across MVS tables.
 */
class UserDeletionService {

	/**
	 * Register WordPress hooks.
	 */
	public function init(): void {
		add_action( 'deleted_user', array( $this, 'handle_user_deletion' ), 10, 1 );
		add_action( 'remove_user_from_blog', array( $this, 'handle_user_removed_from_blog' ), 10, 2 );
	}

	/**
	 * Clean up all MVS data owned by a deleted user.
	 *
	 * Runs in two phases:
	 *   1. Cascade-delete every media item the user owned (via MediaRepository::delete_cascade,
	 *      which reuses the centralised teardown for media-scoped rows).
	 *   2. Purge rows that reference the user directly (reactions, favorites, follows, blocks,
	 *      reports, access grants, mentions, conversation participation, messages).
	 *
	 * @param int $user_id User ID being deleted.
	 */
	public function handle_user_deletion( int $user_id ): void {
		if ( $user_id <= 0 ) {
			return;
		}

		global $wpdb;

		// Phase 1 — cascade-delete every media item owned by the user. Each
		// call reuses MediaRepository::delete_cascade(), which tears down
		// access rules/grants, reactions, favorites, stats, etc.
		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d",
				$user_id
			)
		);
		foreach ( $media_ids as $media_id ) {
			MediaRepository::delete_cascade( (int) $media_id );
		}

		// Phase 2 — purge per-user rows across all user-scoped tables.
		$user_scoped_tables = array(
			'mvs_media_views'               => 'user_id',
			'mvs_favorites'                 => 'user_id',
			'mvs_reactions'                 => 'user_id',
			'mvs_notifications'             => 'user_id',
			'mvs_activity'                  => 'user_id',
			'mvs_access_grants'             => 'user_id',
			'mvs_conversation_participants' => 'user_id',
			'mvs_mentions'                  => 'mentioned_user_id',
			'mvs_follows'                   => 'follower_id',
			'mvs_blocks'                    => 'blocker_id',
			'mvs_reports'                   => 'reporter_id',
			'mvs_messages'                  => 'sender_id',
		);
		foreach ( $user_scoped_tables as $table => $column ) {
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . $table,
				array( $column => $user_id ),
				array( '%d' )
			);
		}

		// Reverse-side cleanup (the user appears as the target, not the actor).
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_follows',
			array( 'following_id' => $user_id ),
			array( '%d' )
		);
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_blocks',
			array( 'blocked_id' => $user_id ),
			array( '%d' )
		);

		// Reports targeting the user (target_type='user', target_id=$user_id).
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reports',
			array(
				'target_type' => 'user',
				'target_id'   => $user_id,
			),
			array( '%s', '%d' )
		);

		/**
		 * Fires after a user's wpmediaverse data has been purged.
		 *
		 * @param int $user_id Deleted user ID.
		 */
		do_action( 'mvs_user_data_purged', $user_id );
	}

	/**
	 * Multisite variant — `remove_user_from_blog` fires with ( user_id, blog_id ).
	 * Delegates to the same cascade so per-site data is cleaned when a user is
	 * removed from an individual site without being deleted network-wide.
	 *
	 * @param int $user_id User being removed.
	 * @param int $blog_id Blog ID (unused — $wpdb already scopes to the current blog).
	 */
	public function handle_user_removed_from_blog( int $user_id, int $blog_id ): void {
		unset( $blog_id );
		$this->handle_user_deletion( $user_id );
	}
}
