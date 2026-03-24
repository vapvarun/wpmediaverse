<?php
/**
 * Notification listener for messaging events.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Messaging;

defined( 'ABSPATH' ) || exit;

class NotificationListener {

	/**
	 * @var MessagingService
	 */
	private $service;

	/**
	 * Constructor.
	 *
	 * @param MessagingService $service Messaging service instance.
	 */
	public function __construct( MessagingService $service ) {
		$this->service = $service;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_action( 'mvs_message_sent', array( $this, 'on_message_sent' ), 10, 4 );

		// GDPR hooks.
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	/**
	 * Handle new message notification.
	 *
	 * @param int   $message_id      Message ID.
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $sender_id       Sender user ID.
	 * @param int[] $recipient_ids   Recipient user IDs.
	 */
	public function on_message_sent( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids ): void {
		foreach ( $recipient_ids as $recipient_id ) {
			// Skip muted conversations.
			$conv = $this->service->get_conversation( $conversation_id, $recipient_id );
			if ( $conv && $conv->is_muted ) {
				// Check if mute has expired.
				if ( $conv->muted_until && strtotime( $conv->muted_until ) < time() ) {
					// Mute expired, unmute and proceed.
					$this->service->update_participant(
						$conversation_id,
						$recipient_id,
						array(
							'is_muted'    => 0,
							'muted_until' => null,
						)
					);
				} else {
					continue;
				}
			}

			// Coalesce: one notification per conversation per 30 seconds.
			$coalesce_key = "mvs_dm_notif_{$conversation_id}_{$recipient_id}";
			if ( get_transient( $coalesce_key ) ) {
				continue;
			}
			set_transient( $coalesce_key, true, MessagingService::COALESCE_SECONDS );

			// Invalidate unread cache.
			delete_transient( 'mvs_dm_unread_' . $recipient_id );

			// Create notification via free plugin's NotificationService if available.
			$notif_service = \WPMediaVerse\Core\Plugin::container()->get( 'notifications' );
			if ( $notif_service && method_exists( $notif_service, 'create' ) ) {
				$notif_service->create( $recipient_id, 'new_message', $sender_id, 0, 0 );
			}
		}
	}

	/**
	 * Register GDPR exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['mvs-messages'] = array(
			'exporter_friendly_name' => 'WPMediaVerse Messages',
			'callback'               => array( $this, 'export_data' ),
		);
		return $exporters;
	}

	/**
	 * Register GDPR eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['mvs-messages'] = array(
			'eraser_friendly_name' => 'WPMediaVerse Messages',
			'callback'             => array( $this, 'erase_data' ),
		);
		return $erasers;
	}

	/**
	 * GDPR export callback.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function export_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		return array(
			'data' => $this->service->export_user_data( $user->ID ),
			'done' => true,
		);
	}

	/**
	 * GDPR erasure callback.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function erase_data( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$count = $this->service->erase_user_data( $user->ID );

		return array(
			'items_removed'  => $count,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
