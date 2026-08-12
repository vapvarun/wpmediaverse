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
	 * Hand a departing member's team-drive documents to a successor.
	 *
	 * MediaVerse cannot answer "who should own this now" — it does not know what
	 * a Space is, and by design it never queries `bn_*` (plan §23.3). So it asks,
	 * and whatever integration owns the drive answers. The fallback is a site
	 * administrator rather than deletion: a file whose successor nobody claims is
	 * still the team's file, and losing it is the exact harm T1 exists to stop.
	 *
	 * A successor who is themselves being deleted, or who no longer exists, is
	 * refused — otherwise the reassignment quietly re-creates the orphan it was
	 * meant to prevent.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id The departing member.
	 * @return int[] Media ids that were reassigned and must NOT be cascaded.
	 */
	private function reassign_team_drive_media( int $user_id ): array {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$rows = $repo->author_team_drive_media( $user_id );

		if ( empty( $rows ) ) {
			return array();
		}

		$reassigned = array();

		foreach ( $rows as $row ) {
			/**
			 * Who inherits a document when its author's account is deleted.
			 *
			 * Answered by whatever owns the drive — for a BuddyNext Space that is
			 * the Space owner. Return 0 to decline, which falls through to a site
			 * administrator rather than to deletion.
			 *
			 * @since 2.4.0
			 *
			 * @param int    $successor_id Default 0 — nobody claimed it yet.
			 * @param string $drive_type   Drive type, e.g. 'space'.
			 * @param int    $drive_id     Drive id.
			 * @param int    $user_id      The departing member.
			 */
			$successor = (int) apply_filters(
				'mvs_document_drive_successor',
				0,
				$row['drive_type'],
				$row['drive_id'],
				$user_id
			);

			if ( $successor === $user_id || ( $successor > 0 && ! get_userdata( $successor ) ) ) {
				$successor = 0;
			}

			if ( $successor <= 0 ) {
				$successor = $this->fallback_successor( $user_id );
			}

			if ( $successor <= 0 ) {
				// No administrator to hand it to — a site in a state this service
				// cannot repair. Left for the cascade, and said out loud rather
				// than dropped silently.
				\WPMediaVerse\Services\LoggerService::error(
					'user-deletion',
					sprintf(
						'Document %d on %s:%d has no successor; falling through to deletion with its author.',
						$row['media_id'],
						$row['drive_type'],
						$row['drive_id']
					),
					array(
						'media_id'   => $row['media_id'],
						'drive_type' => $row['drive_type'],
						'drive_id'   => $row['drive_id'],
					)
				);
				continue;
			}

			$repo->set( (int) $row['media_id'], 'post_author', $successor );

			$reassigned[] = (int) $row['media_id'];

			/**
			 * Fires after a team-drive document changes hands on account deletion.
			 *
			 * @since 2.4.0
			 *
			 * @param int    $media_id   The document.
			 * @param int    $successor  Its new author.
			 * @param int    $user_id    The departing member.
			 * @param string $drive_type Drive type.
			 * @param int    $drive_id   Drive id.
			 */
			do_action( 'mvs_document_reassigned', (int) $row['media_id'], $successor, $user_id, $row['drive_type'], (int) $row['drive_id'] );
		}

		return $reassigned;
	}

	/**
	 * A site administrator to inherit documents nobody else claimed.
	 *
	 * Lowest id first, so the answer is stable across runs rather than depending
	 * on how WordPress happened to order the query.
	 *
	 * @since 2.4.0
	 *
	 * @param int $exclude The departing member.
	 * @return int
	 */
	private function fallback_successor( int $exclude ): int {
		static $cached = null;

		if ( null === $cached ) {
			$admins = get_users(
				array(
					'role'    => 'administrator',
					'fields'  => 'ID',
					'orderby' => 'ID',
					'order'   => 'ASC',
					'number'  => 5,
				)
			);

			$cached = array_map( 'intval', (array) $admins );
		}

		foreach ( $cached as $admin_id ) {
			if ( $admin_id !== $exclude ) {
				return $admin_id;
			}
		}

		return 0;
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
		// UNFILTERED on purpose — deleting an account must reach everything the
		// person authored, whatever its privacy or status. See
		// MediaRepository::author_media_ids().
		$media_ids = \WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->author_media_ids( $user_id );

		// Phase 1a — HAND BACK what was never personally theirs, before anything
		// is deleted (§15 T1).
		//
		// A document uploaded into a Space belongs to that Space. The departing
		// member is its author, not its owner, and the cascade below reads
		// `post_author` alone — so leaving a team took the team's files with you.
		// Silent, permanent, and triggered by an ordinary event: someone leaves.
		//
		// Reassignment rather than retention-in-place, because the row must stop
		// naming a person who has been erased. The successor is asked for, never
		// derived: MediaVerse does not know what a Space is, let alone who owns
		// one. Anything not reassigned falls through to the cascade unchanged.
		$reassigned = $this->reassign_team_drive_media( $user_id );

		if ( ! empty( $reassigned ) ) {
			$media_ids = array_values( array_diff( $media_ids, $reassigned ) );
		}

		foreach ( $media_ids as $media_id ) {
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_cascade( (int) $media_id );
		}

		// Phase 1b — delete the user's albums and collections (CPTs). These were
		// never removed, orphaning the album's mvs_album_items rows and (for
		// collections) Pro's mvs_pro_collection_items rows (audit 2026-06-04,
		// #12). wp_delete_post fires the before_delete_post handlers in
		// PostTypes\Album / PostTypes\Collection, which do the custom-table
		// cleanup + fire mvs_album_deleted / mvs_collection_deleted.
		// Direct query on purpose: data-erasure must see every row regardless
		// of query filters (multilingual or visibility plugins must not hide
		// posts from the cleanup), matching the other queries in this service.
		$cpt_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ( 'mvs_album', 'mvs_collection' ) AND post_author = %d",
				$user_id
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
		//
		// mvs_reports is NOT in this list: a report is moderation evidence another
		// member filed, so it is RETAINED (anonymised) rather than deleted — the
		// same classification MemberDataMap::retain_map() applies on the formal
		// GDPR-erasure path. Deleting it would let a reported member wipe the case
		// against them simply by leaving. See the anonymisation block below.
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

		// A notification the deleted user CAUSED ("X liked your photo") carries
		// their id in actor_id and lives in someone else's queue; the loop above
		// only cleared rows they RECEIVED (user_id). Erase the actor side too, or
		// the deleted member stays scattered through other members' notifications
		// (erase_map lists mvs_notifications = [user_id, actor_id]).
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_notifications',
			array( 'actor_id' => $user_id ),
			array( '%d' )
		);

		// Reports are RETAINED, anonymised — both the report this member filed
		// (reporter_id) and the report filed ABOUT them (target_type='user',
		// target_id). The case survives for the moderator; the person is removed
		// from it. target_id is scoped by target_type so a media/comment report
		// whose id collides with this user id is never touched.
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reports',
			array( 'reporter_id' => 0 ),
			array( 'reporter_id' => $user_id ),
			array( '%d' ),
			array( '%d' )
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_reports',
			array( 'target_id' => 0 ),
			array(
				'target_type' => 'user',
				'target_id'   => $user_id,
			),
			array( '%d' ),
			array( '%s', '%d' )
		);

		// Conversations that just lost their last participant are dead shells:
		// remove them and any residual messages. The per-user purge above clears
		// participants + messages but never the thread row, so deleting every
		// member of a conversation previously left an orphaned mvs_conversations
		// row behind. This sweeps any now-empty thread (cheap; cleanup path only).
		$empty_conversation_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT c.id FROM {$wpdb->prefix}mvs_conversations c
			 LEFT JOIN {$wpdb->prefix}mvs_conversation_participants p ON p.conversation_id = c.id
			 WHERE p.conversation_id IS NULL" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		foreach ( $empty_conversation_ids as $conversation_id ) {
			$conversation_id = (int) $conversation_id;
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_messages',
				array( 'conversation_id' => $conversation_id ),
				array( '%d' )
			);
			$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_conversations',
				array( 'id' => $conversation_id ),
				array( '%d' )
			);
		}

		// The deleted member created conversations that still have other active
		// participants (the empty-shell sweep above only removed threads with
		// nobody left). Those shared threads survive, but created_by must not keep
		// pointing at a deleted account — otherwise GDPRService reports erasure
		// "done" while the member's id lives on in mvs_conversations.created_by.
		// Anonymise it: the container stays, the originator is removed (mirrors the
		// mvs_competitions.winner_id retain policy).
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_conversations',
			array( 'created_by' => 0 ),
			array( 'created_by' => $user_id ),
			array( '%d' ),
			array( '%d' )
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
