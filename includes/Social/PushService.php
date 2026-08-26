<?php
/**
 * Native push notifications — the plugin's half.
 *
 * MediaVerse owns the device tokens and fires ONE dispatch signal when a member
 * gets a notification. It does NOT talk to FCM or APNs itself: the credentials
 * and platform SDKs belong to a delivery integration (the app's backend), which
 * hooks `mvs_push_send` and does the actual sending. So this class is the seam —
 * store tokens, and hand a delivery layer the "who + what" on every notification.
 *
 * Basecamp 9667082225.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Device-token store + notification->push dispatch.
 *
 * @since 2.4.0
 */
class PushService {

	/**
	 * Platforms a token may register for.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	private const PLATFORMS = array( 'ios', 'android', 'web' );

	/**
	 * Hook the dispatch onto notification creation.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register(): void {
		add_action( 'mvs_notification_created', array( $this, 'dispatch' ), 10, 7 );
	}

	/**
	 * The device-tokens table name.
	 *
	 * @since 2.4.0
	 * @return string
	 */
	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mvs_device_tokens';
	}

	/**
	 * The longest device token the store will accept (matches the column).
	 *
	 * @since 2.4.0
	 * @var int
	 */
	private const MAX_TOKEN = 255;

	/**
	 * Register (or refresh) a device token for a member.
	 *
	 * A token already registered to a DIFFERENT member is REFUSED, not moved:
	 * re-pointing it would redirect that member's device to someone else's
	 * notifications and silence their own — a hijack, given the token can leak
	 * through logs, crash reports or a shared device. A device that genuinely
	 * changes hands is issued a fresh OS token, so this never blocks a real
	 * re-registration. The same member re-registering just refreshes the row.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $user_id  Owner.
	 * @param string $platform ios|android|web.
	 * @param string $token    Opaque device token.
	 * @return bool True on success.
	 */
	public function register_token( int $user_id, string $platform, string $token ): bool {
		global $wpdb;

		$platform = in_array( $platform, self::PLATFORMS, true ) ? $platform : '';
		$token    = trim( $token );

		// Reject rather than silently truncate to the column width — a truncated
		// token stores fine, returns success, and then never receives a push.
		if ( $user_id <= 0 || '' === $platform || '' === $token || strlen( $token ) > self::MAX_TOKEN ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is code-controlled; value is prepared.
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT user_id FROM ' . $this->table() . ' WHERE token = %s', $token )
		);

		if ( $owner > 0 && $owner !== $user_id ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is code-controlled; values are prepared.
		$ok = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . $this->table() . ' (user_id, platform, token, created_at, updated_at)
				 VALUES (%d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE platform = VALUES(platform), updated_at = VALUES(updated_at)',
				$user_id,
				$platform,
				$token,
				current_time( 'mysql' ),
				current_time( 'mysql' )
			)
		);

		return false !== $ok;
	}

	/**
	 * Remove one of a member's OWN device tokens (logout / uninstall).
	 *
	 * Scoped to the owner: deleting by token alone let any member delete another
	 * member's token (silencing their pushes) and turned the return value into a
	 * token-existence oracle. A caller may only remove a token registered to
	 * themselves.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $user_id Owner making the request.
	 * @param string $token   Device token.
	 * @return bool True when a row was removed.
	 */
	public function unregister_token( int $user_id, string $token ): bool {
		global $wpdb;

		$token = trim( $token );
		if ( $user_id <= 0 || '' === $token ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (bool) $wpdb->delete(
			$this->table(),
			array(
				'token'   => $token,
				'user_id' => $user_id,
			),
			array( '%s', '%d' )
		);
	}

	/**
	 * Every device token registered to a member.
	 *
	 * @since 2.4.0
	 *
	 * @param int $user_id Member.
	 * @return array<int, array{platform:string, token:string}>
	 */
	public function tokens_for( int $user_id ): array {
		global $wpdb;

		if ( $user_id <= 0 ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name is code-controlled; value is prepared.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT platform, token FROM ' . $this->table() . ' WHERE user_id = %d', $user_id ),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Hand the delivery layer a push to send when a member is notified.
	 *
	 * Fires `mvs_push_send( int $user_id, array $tokens, array $payload )`. No
	 * listener, no tokens, or a muted type → a clean no-op. The message/link come
	 * pre-rendered from `NotificationService::build_message_and_link()`, so a push
	 * and the in-app notification always say the same thing.
	 *
	 * @since 2.4.0
	 *
	 * @param int    $notification_id Notification row id.
	 * @param int    $user_id         Recipient.
	 * @param string $type            Notification type (new_follower, reaction, …).
	 * @param int    $actor_id        Who triggered it.
	 * @param int    $media_id        Related media, if any.
	 * @param string $message         Rendered message.
	 * @param string $link            Deep link.
	 * @return void
	 */
	public function dispatch( $notification_id, $user_id, $type, $actor_id, $media_id, $message, $link ): void {
		$user_id = (int) $user_id;
		$type    = (string) $type;

		/**
		 * Whether a push should be sent for this notification type to this user.
		 *
		 * The seam for per-type mute preferences (a client toggling off 'reaction'
		 * pushes). Default true — every notification pushes unless something opts
		 * it out.
		 *
		 * @since 2.4.0
		 *
		 * @param bool   $send    Whether to send.
		 * @param int    $user_id Recipient.
		 * @param string $type    Notification type.
		 */
		if ( ! (bool) apply_filters( 'mvs_push_should_send', true, $user_id, $type ) ) {
			return;
		}

		$tokens = $this->tokens_for( $user_id );
		if ( empty( $tokens ) ) {
			return;
		}

		$payload = array(
			'notification_id' => (int) $notification_id,
			'type'            => $type,
			'message'         => (string) $message,
			'link'            => (string) $link,
			'media_id'        => (int) $media_id,
			'actor_id'        => (int) $actor_id,
		);

		/**
		 * Deliver a push to a member's devices.
		 *
		 * A delivery integration (the app backend, holding the FCM/APNs
		 * credentials) hooks this and sends. MediaVerse never sends itself.
		 *
		 * @since 2.4.0
		 *
		 * @param int   $user_id Recipient.
		 * @param array $tokens  [ [ platform, token ], … ] for this user.
		 * @param array $payload Notification payload (type/message/link/…).
		 */
		do_action( 'mvs_push_send', $user_id, $tokens, $payload );
	}
}
