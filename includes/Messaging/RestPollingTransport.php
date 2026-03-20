<?php
/**
 * Default REST polling transport.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Messaging;

defined( 'ABSPATH' ) || exit;

class RestPollingTransport implements TransportInterface {

	/**
	 * Poll for new data by querying the database.
	 *
	 * @param int    $user_id User ID.
	 * @param string $since   ISO 8601 datetime.
	 * @return array
	 */
	public function poll( int $user_id, string $since ): array {
		$service = new MessagingService();
		return $service->poll( $user_id, $since );
	}

	/**
	 * No-op for polling transport — clients fetch via poll.
	 *
	 * @param int   $conversation_id Conversation ID.
	 * @param int[] $recipient_ids   Recipient user IDs.
	 * @param array $message         Message data.
	 */
	public function push( int $conversation_id, array $recipient_ids, array $message ): void {
		// No-op: REST polling transport relies on client-side polling.
	}

	/**
	 * Get client-side polling configuration.
	 *
	 * @return array
	 */
	public function get_client_config(): array {
		$intervals = apply_filters( 'mvs_messaging_poll_intervals', array(
			'active'     => 3000,  // Active conversation: 3s.
			'list'       => 10000, // Conversation list: 10s.
			'background' => 30000, // Chat closed: 30s.
		) );

		return array(
			'type'      => 'polling',
			'intervals' => $intervals,
		);
	}
}
