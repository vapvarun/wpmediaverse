<?php
/**
 * Data access for direct messages and conversation membership.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Named queries over mvs_messages and mvs_conversation_participants.
 *
 * MessagingService owns the rules; this class owns the SQL it needs, so other
 * plugins read messages through MessagingService rather than querying tables.
 *
 * @since 2.6.2
 */
class MessageRepository {

	/**
	 * Conversation a message belongs to.
	 *
	 * @param int $message_id Message id.
	 * @return int Conversation id, 0 when the message is gone.
	 */
	public function conversation_of( int $message_id ): int {
		global $wpdb;
		if ( $message_id <= 0 ) {
			return 0;
		}
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT conversation_id FROM {$wpdb->prefix}mvs_messages WHERE id = %d", $message_id )
		);
	}

	/**
	 * Sender of each message, in one query.
	 *
	 * @param int[] $message_ids Message ids.
	 * @return array<int,int> message id => sender id (missing messages are left out).
	 */
	public function senders_of( array $message_ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $message_ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}
		$in   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = (array) $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT id, sender_id FROM {$wpdb->prefix}mvs_messages WHERE id IN ({$in})", ...$ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (int) $row->id ] = (int) $row->sender_id;
		}
		return $out;
	}

	/**
	 * How many members are active in a conversation.
	 *
	 * @param int $conversation_id Conversation id.
	 * @return int
	 */
	public function active_participant_count( int $conversation_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}mvs_conversation_participants WHERE conversation_id = %d AND status = 'active'", $conversation_id )
		);
	}
}
