<?php
/**
 * The single source of truth for every member-bearing table.
 *
 * @package WPMediaVerse
 * @since   2.1.0
 */

namespace WPMediaVerse\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Classifies every user-keyed mvs_* table as ERASE or RETAIN.
 *
 * This implements the Wbcom Data Lifecycle Standard §9/§9a. Two rules from it
 * shape everything here, and both were learned the expensive way:
 *
 * 1. **Every user-keyed table is on exactly one of two lists.** Deciding to keep
 *    a table is a fine answer; never deciding is not. RETAIN is a first-class
 *    outcome with a stated reason and legal basis — what the gate rejects is
 *    *silence*, because silence is indistinguishable from an oversight, and on a
 *    compliance path telling a deliberate retention apart from a bug is the
 *    entire game. `bin/check-erasure.php` fails the build on any user-keyed
 *    table that appears on neither list.
 *
 * 2. **Erasure must cover the person as a TARGET, not only as an actor.** A
 *    purge that deletes rows where `actor_id = X` but leaves rows where
 *    `target_id = X` leaves the erased member scattered through other members'
 *    data. Every entry below therefore lists *all* of its user columns —
 *    `mvs_follows` has both directions, `mvs_blocks` has both, `mvs_reports` has
 *    the reporter and the reported, `mvs_notifications` has the recipient and
 *    the actor.
 *
 * The export, the eraser, the account-deletion purge, and the residue verifier
 * are all generated from this one map. That is deliberate: the delete list and
 * the verify list must be the same list, or the purge can report `done` while a
 * registered table still holds the member. Core takes `done` at its word and
 * emails the member to say their data is gone — that is the one lie a compliance
 * path cannot tell.
 *
 * Pro contributes its own tables through the `mvs_member_erase_map` /
 * `mvs_member_retain_map` filters, so Pro data is covered by export, erasure,
 * purge and verification without Pro duplicating any of them.
 */
final class MemberDataMap {

	/**
	 * Tables whose rows are DELETED when a member is erased.
	 *
	 * Shape: table (unprefixed) => [
	 *   'columns' => string[]  every column that can hold this member's ID,
	 *   'label'   => string    human name, used in the export
	 * ]
	 *
	 * @return array<string, array{columns: string[], label: string}>
	 */
	public static function erase_map(): array {
		$map = array(
			// Content the member created. Deleting the member deletes their
			// media — a photo of a person *is* their personal data, and
			// anonymising the row does not de-identify the image.
			'mvs_media_index'               => array(
				'columns' => array( 'post_author' ),
				'label'   => __( 'Media', 'wpmediaverse' ),
			),
			'mvs_media_views'               => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Media views', 'wpmediaverse' ),
			),
			'mvs_favorites'                 => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Favorites', 'wpmediaverse' ),
			),
			'mvs_reactions'                 => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Reactions', 'wpmediaverse' ),
			),
			'mvs_activity'                  => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Activity', 'wpmediaverse' ),
			),
			'mvs_access_grants'             => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Access grants', 'wpmediaverse' ),
			),

			// Target, not just actor. `mentioned_user_id` is the member being
			// named by somebody else — erasing them must remove the mention.
			'mvs_mentions'                  => array(
				'columns' => array( 'mentioned_user_id' ),
				'label'   => __( 'Mentions', 'wpmediaverse' ),
			),

			// Both directions. Erasing a member must remove the people they
			// followed AND the fact that others followed them.
			'mvs_follows'                   => array(
				'columns' => array( 'follower_id', 'following_id' ),
				'label'   => __( 'Follows', 'wpmediaverse' ),
			),
			'mvs_blocks'                    => array(
				'columns' => array( 'blocker_id', 'blocked_id' ),
				'label'   => __( 'Blocks', 'wpmediaverse' ),
			),

			// Recipient and actor. A notification saying "X liked your photo"
			// carries X even though it belongs to somebody else's row.
			'mvs_notifications'             => array(
				'columns' => array( 'user_id', 'actor_id' ),
				'label'   => __( 'Notifications', 'wpmediaverse' ),
			),

			// Messaging. MediaVerse's policy is a uniform hard delete: leaving a
			// community removes the person and what they said. This matches the
			// existing account-delete path, and BuddyNext's identical policy, so
			// there is one answer rather than two.
			'mvs_conversation_participants' => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Conversations', 'wpmediaverse' ),
			),
			'mvs_messages'                  => array(
				'columns' => array( 'sender_id' ),
				'label'   => __( 'Messages', 'wpmediaverse' ),
			),
			'mvs_message_reactions'         => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Message reactions', 'wpmediaverse' ),
			),

			// Diagnostics are not a reason to keep personal data.
			'mvs_error_log'                 => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Error log', 'wpmediaverse' ),
			),
		);

		/**
		 * Filter the tables erased when a member is removed.
		 *
		 * The seam Pro uses to register its own member-bearing tables, so Pro
		 * data is covered by export, erasure, purge and verification without Pro
		 * reimplementing any of them.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, array{columns: string[], label: string}> $map Erase map.
		 */
		return (array) apply_filters( 'mvs_member_erase_map', $map );
	}

	/**
	 * Tables deliberately KEPT when a member is erased.
	 *
	 * Every entry must say why, and on what basis. "We didn't get round to it"
	 * is not an entry — that is what the gate is for.
	 *
	 * Retained rows are ANONYMISED, not left intact: the member's ID is set to 0
	 * so the record survives without identifying anyone. De-identification
	 * satisfies GDPR; destroying an audit trail or an accounting record does not.
	 *
	 * @return array<string, array{columns: string[], label: string, reason: string, basis: string}>
	 */
	public static function retain_map(): array {
		$map = array(
			// A report is a case somebody else filed. If erasing your account
			// deleted the reports about you, an abuser could wipe the evidence
			// simply by leaving — and the moderator who acted on it loses the
			// record of why. The reporter's identity is anonymised; the case
			// stays.
			'mvs_reports'      => array(
				'columns' => array( 'reporter_id', 'target_id' ),
				'label'   => __( 'Reports', 'wpmediaverse' ),
				'reason'  => 'Moderation evidence. A member must not be able to erase the report another member filed about them, and the moderator needs the record of why they acted.',
				'basis'   => 'Legitimate interest (safety of other users, integrity of the moderation record). Rows are anonymised: reporter_id / target_id set to 0.',
			),

			// A usage/billing ledger. Site owners have accounting obligations
			// that outlive a member's account.
			'mvs_transactions' => array(
				'columns' => array( 'user_id' ),
				'label'   => __( 'Usage ledger', 'wpmediaverse' ),
				'reason'  => 'Billing and usage ledger the site owner may be legally required to retain.',
				'basis'   => 'Legal obligation (accounting records). Rows are anonymised: user_id set to 0.',
			),

			// A conversation is a shared container other members are still in. The
			// participant rows and messages are hard-deleted (erase_map), and an
			// emptied thread is swept away entirely, but a thread that still has
			// other active participants survives — its created_by must be removed,
			// not the whole thread, or erasing one member would destroy a
			// conversation others are part of.
			'mvs_conversations' => array(
				'columns' => array( 'created_by' ),
				'label'   => __( 'Conversations created', 'wpmediaverse' ),
				'reason'  => 'A conversation with other active participants is a shared record; erasing its creator must not delete the thread for everyone else.',
				'basis'   => 'Legitimate interest (integrity of a shared record). Rows are anonymised: created_by set to 0.',
			),
		);

		/**
		 * Filter the tables retained (anonymised) when a member is erased.
		 *
		 * @since 2.1.0
		 *
		 * @param array<string, array{columns: string[], label: string, reason: string, basis: string}> $map Retain map.
		 */
		return (array) apply_filters( 'mvs_member_retain_map', $map );
	}

	/**
	 * Every user-keyed table, whichever list it is on.
	 *
	 * @return string[]
	 */
	public static function all_tables(): array {
		return array_merge(
			array_keys( self::erase_map() ),
			array_keys( self::retain_map() )
		);
	}
}
