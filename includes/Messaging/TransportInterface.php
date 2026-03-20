<?php
/**
 * Transport interface for messaging.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Messaging;

defined( 'ABSPATH' ) || exit;

interface TransportInterface {

	/**
	 * Poll for new data since a timestamp.
	 *
	 * @param int    $user_id User ID.
	 * @param string $since   ISO 8601 datetime.
	 * @return array
	 */
	public function poll( int $user_id, string $since ): array;

	/**
	 * Push a message to recipients (no-op for polling transport).
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int[] $recipient_ids   Recipient user IDs.
	 * @param array $message         Message data.
	 */
	public function push( int $conversation_id, array $recipient_ids, array $message ): void;

	/**
	 * Get client-side transport configuration.
	 *
	 * @return array
	 */
	public function get_client_config(): array;
}
