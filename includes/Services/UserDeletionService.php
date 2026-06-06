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
	 *   1. Cascade-delete every media item the user owned (via \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_cascade,
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
		// call reuses \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_cascade(), which tears down
		// access rules/grants, reactions, favorites, stats, etc.
		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d",
				$user_id
			)
		);
		foreach ( $media_ids as $media_id ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_cascade( (int) $media_id );
		}

		// Phase 1b — delete the user's albums and collections (CPTs). These were
		// never removed, orphaning the album's mvs_album_items rows and (for
		// collections) Pro's mvs_pro_collection_items rows (audit 2026-06-04,
		// #12). wp_delete_post fires the before_delete_post handlers in
		// PostTypes\Album / PostTypes\Collection, which do the custom-table
		// cleanup + fire mvs_album_deleted / mvs_collection_deleted.
		$cpt_ids = get_posts(
			array(
				'post_type'        => array( 'mvs_album', 'mvs_collection' ),
				'author'           => $user_id,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		foreach ( $cpt_ids as $cpt_id ) {
			wp_delete_post( (int) $cpt_id, true );
		}

		// Capture the media this user VIEWED (on other people's media) before we
		// delete those view rows, so we can recompute the affected aggregates
		// afterwards — otherwise mvs_media_stats.views stays inflated by the
		// deleted user forever (audit 2026-06-04, #28).
		$viewed_media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT DISTINCT media_id FROM {$wpdb->prefix}mvs_media_views WHERE user_id = %d",
				$user_id
			)
		);

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
			'mvs_message_reactions'         => 'user_id', // audit 2026-06-04 — was orphaned.
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

		// Recompute view aggregates for media the deleted user had viewed, now
		// that their raw mvs_media_views rows are gone — otherwise the public
		// view count stays inflated by the deleted user (audit 2026-06-04, #28).
		// The media itself belongs to OTHER (still-existing) users.
		foreach ( $viewed_media_ids as $viewed_media_id ) {
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}mvs_media_stats
					SET views = ( SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_views WHERE media_id = %d )
					WHERE media_id = %d",
					(int) $viewed_media_id,
					(int) $viewed_media_id
				)
			);
		}

		// Custom profile avatar — delete the attachment + its file. Without
		// this the _mvs_custom_avatar attachment (and the uploaded image on
		// disk) was orphaned on user deletion (audit 2026-06-04).
		$mvs_container = \WPMediaVerse\Core\Plugin::container();
		if ( $mvs_container->has( 'profile' ) ) {
			$mvs_container->get( 'profile' )->delete_avatar( $user_id );
		}

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
