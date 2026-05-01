<?php
/**
 * Notification service.
 *
 * Native notification center — works standalone, optionally syncs with BuddyPress.
 *
 * @package    WPMediaVerse
 * @subpackage Social
 * @since      1.1.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Handles notification creation, listing, read status, and counts.
 */
class NotificationService {

	/**
	 * Notification types.
	 *
	 * @var string[]
	 */
	const TYPES = array(
		'new_follower',
		'media_reaction',
		'media_comment',
		'media_mention',
		'media_favorite',
		'new_message',
	);

	/**
	 * Register hooks for auto-creating notifications.
	 *
	 * Guarded with a static flag so accidental double-instantiation (via the
	 * service container or a stray `new NotificationService()`) cannot register
	 * the same callbacks twice — which would produce duplicate rows.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		static $registered = false;
		if ( $registered ) {
			return;
		}
		$registered = true;

		add_action( 'mvs_user_followed', array( $this, 'on_follow' ), 10, 2 );
		add_action( 'mvs_reaction_added', array( $this, 'on_reaction' ), 10, 3 );
		add_action( 'mvs_comment_created', array( $this, 'on_comment' ), 10, 3 );
		add_action( 'mvs_mentions_created', array( $this, 'on_mentions' ), 10, 4 );
		add_action( 'mvs_favorite_added', array( $this, 'on_favorite' ), 10, 2 );
		add_action( 'mvs_message_sent', array( $this, 'on_message' ), 10, 4 );
	}

	/**
	 * Create a notification.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id    Recipient user ID.
	 * @param string $type       Notification type.
	 * @param int    $actor_id   User who triggered it.
	 * @param int    $media_id   Related media ID (0 if none).
	 * @param int    $comment_id Related comment ID (0 if none).
	 * @return int|false Notification ID or false.
	 */
	public function create( int $user_id, string $type, int $actor_id, int $media_id = 0, int $comment_id = 0 ) {
		// Don't notify yourself.
		if ( $user_id === $actor_id ) {
			return false;
		}

		/**
		 * Filters the allowed notification type list.
		 *
		 * Pro extensions hook this to register additional notification types
		 * (e.g. challenge_entry_received) without having to fork the service.
		 *
		 * @since 1.1.2
		 *
		 * @param string[] $types Allowed notification type strings.
		 */
		$allowed_types = (array) apply_filters( 'mvs_notification_types', self::TYPES );

		if ( ! in_array( $type, $allowed_types, true ) ) {
			return false;
		}

		/**
		 * Filters whether a notification should be sent.
		 *
		 * Return false to suppress the notification.
		 *
		 * @since 1.1.0
		 *
		 * @param bool   $should_send Whether to send the notification.
		 * @param int    $user_id     Recipient user ID.
		 * @param string $type        Notification type.
		 * @param int    $actor_id    User who triggered it.
		 * @param int    $media_id    Related media ID (0 if none).
		 */
		if ( ! apply_filters( 'mvs_should_send_notification', true, $user_id, $type, $actor_id, $media_id ) ) {
			return false;
		}

		/**
		 * Filters the notification data before it is stored.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $data Notification data array.
		 * @param string $type Notification type.
		 */
		$notification_data = apply_filters(
			'mvs_notification_data',
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'actor_id'   => $actor_id,
				'media_id'   => $media_id,
				'comment_id' => $comment_id,
				'created_at' => current_time( 'mysql', true ),
			),
			$type
		);

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_notifications',
			$notification_data,
			array( '%d', '%s', '%d', '%d', '%d', '%s' )
		);

		if ( $wpdb->insert_id ) {
			wp_cache_delete( 'mvs_notif_count_' . $user_id, 'mvs' );

			/**
			 * Fires after a notification is created.
			 *
			 * @since 1.1.0
			 *
			 * @param int    $notification_id New notification ID.
			 * @param int    $user_id         Recipient user ID.
			 * @param string $type            Notification type.
			 * @param int    $actor_id        User who triggered it.
			 * @param int    $media_id        Related media ID.
			 */
			do_action( 'mvs_notification_created', $wpdb->insert_id, $user_id, $type, $actor_id, $media_id );

			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get notifications for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id  User ID.
	 * @param int    $per_page Results per page.
	 * @param int    $page     Page number.
	 * @param string $filter   'all', 'unread', or a specific type.
	 * @return array { notifications: array, total: int }
	 */
	public function get_notifications( int $user_id, int $per_page = 20, int $page = 1, string $filter = 'all' ): array {
		global $wpdb;

		$offset = ( $page - 1 ) * $per_page;
		$where  = 'user_id = %d';
		$params = array( $user_id );

		if ( 'unread' === $filter ) {
			$where .= ' AND read_at IS NULL';
		} elseif ( in_array( $filter, self::TYPES, true ) ) {
			$where   .= ' AND type = %s';
			$params[] = $filter;
		}

		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}mvs_notifications WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		// Prime caches in bulk to avoid N+1 queries.
		$actor_ids = array();
		$media_ids = array();
		foreach ( $rows as $row ) {
			$actor_ids[] = (int) $row->actor_id;
			if ( $row->media_id ) {
				$media_ids[] = (int) $row->media_id;
			}
		}
		if ( $actor_ids ) {
			// Pre-load all actor user objects in one query.
			new \WP_User_Query(
				array(
					'include' => array_unique( $actor_ids ),
					'fields'  => 'all',
				)
			);
		}
		if ( $media_ids ) {
			_prime_post_caches( array_unique( $media_ids ), false, false );
		}

		$notifications = array();
		foreach ( $rows as $row ) {
			$notifications[] = $this->format_notification( $row );
		}

		return array(
			'notifications' => $notifications,
			'total'         => $total,
		);
	}

	/**
	 * Get unread notification count for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int
	 */
	public function get_unread_count( int $user_id ): int {
		$cached = wp_cache_get( 'mvs_notif_count_' . $user_id, 'mvs' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		global $wpdb;

		$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_notifications WHERE user_id = %d AND read_at IS NULL",
				$user_id
			)
		);

		wp_cache_set( 'mvs_notif_count_' . $user_id, $count, 'mvs', 300 );

		return $count;
	}

	/**
	 * Mark notifications as read.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $user_id User ID.
	 * @param int[] $ids     Notification IDs to mark read. Empty = mark all.
	 * @return int Number of notifications marked.
	 */
	public function mark_read( int $user_id, array $ids = array() ): int {
		global $wpdb;

		$now = current_time( 'mysql', true );

		if ( empty( $ids ) ) {
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}mvs_notifications SET read_at = %s WHERE user_id = %d AND read_at IS NULL",
					$now,
					$user_id
				)
			);
		} else {
			$id_list = implode( ',', array_map( 'absint', $ids ) );
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$wpdb->prefix}mvs_notifications SET read_at = %s WHERE user_id = %d AND id IN ({$id_list})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$now,
					$user_id
				)
			);
		}

		wp_cache_delete( 'mvs_notif_count_' . $user_id, 'mvs' );

		return (int) $updated;
	}

	/**
	 * Handle follow notification.
	 *
	 * @param int $follower_id  Follower.
	 * @param int $following_id Followed user.
	 */
	public function on_follow( int $follower_id, int $following_id ): void {
		$this->create( $following_id, 'new_follower', $follower_id );
	}

	/**
	 * Handle reaction notification.
	 *
	 * @param int    $media_id Media ID.
	 * @param int    $user_id  Reactor.
	 * @param string $type     Reaction type.
	 */
	public function on_reaction( int $media_id, int $user_id, string $type ): void {
		$owner = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'post_author' );
		$this->create( $owner, 'media_reaction', $user_id, $media_id );
	}

	/**
	 * Handle comment notification.
	 *
	 * @param int $media_id   Media ID.
	 * @param int $user_id    Commenter.
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment( int $media_id, int $user_id, int $comment_id ): void {
		$owner = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'post_author' );
		$this->create( $owner, 'media_comment', $user_id, $media_id, $comment_id );
	}

	/**
	 * Handle mention notifications.
	 *
	 * @param int    $media_id      Media ID.
	 * @param int[]  $mentioned_ids Mentioned user IDs.
	 * @param string $context      Context.
	 * @param int    $comment_id    Comment ID.
	 */
	public function on_mentions( int $media_id, array $mentioned_ids, string $context, int $comment_id ): void {
		$actor = get_current_user_id();
		foreach ( $mentioned_ids as $uid ) {
			$this->create( (int) $uid, 'media_mention', $actor, $media_id, $comment_id );
		}
	}

	/**
	 * Handle favorite notification.
	 *
	 * @param int $media_id Media ID.
	 * @param int $user_id  User who favorited.
	 */
	public function on_favorite( int $media_id, int $user_id ): void {
		$owner = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'post_author' );
		$this->create( $owner, 'media_favorite', $user_id, $media_id );
	}

	/**
	 * Handle new DM sent event.
	 *
	 * @param int   $message_id      Message ID.
	 * @param int   $conversation_id Conversation ID.
	 * @param int   $sender_id       Sender user ID.
	 * @param int[] $recipient_ids   Recipient user IDs.
	 */
	public function on_message( int $message_id, int $conversation_id, int $sender_id, array $recipient_ids ): void {
		foreach ( $recipient_ids as $recipient_id ) {
			$this->create( (int) $recipient_id, 'new_message', $sender_id );
		}
	}

	/**
	 * Format a notification row for REST output.
	 *
	 * @param object $row Database row.
	 * @return array
	 */
	private function format_notification( object $row ): array {
		$actor       = get_userdata( (int) $row->actor_id );
		$actor_name  = $actor ? $actor->display_name : __( 'Someone', 'wpmediaverse' );
		$media_title = '';
		if ( $row->media_id ) {
			$media_title = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( (int) $row->media_id, 'title' ) ?: '';
		}

		$message = $this->build_notification_message( $row->type, $actor_name, $media_title );

		$url = '';
		if ( $row->media_id ) {
			$url = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_permalink( (int) $row->media_id );
		} elseif ( 'new_follower' === $row->type ) {
			$actor_login = get_the_author_meta( 'user_login', (int) $row->actor_id );
			$url         = $actor_login ? home_url( '/media/@' . $actor_login . '/' ) : '';
		} elseif ( 'new_message' === $row->type ) {
			$url = home_url( '/messages/' );
		}

		return array(
			'id'         => (int) $row->id,
			'type'       => $row->type,
			'message'    => $message,
			'url'        => $url,
			'actor'      => array(
				'id'     => (int) $row->actor_id,
				'name'   => $actor_name,
				'avatar' => get_avatar_url( (int) $row->actor_id, array( 'size' => 48 ) ),
			),
			'media_id'   => (int) $row->media_id,
			'comment_id' => (int) $row->comment_id,
			'is_read'    => ! empty( $row->read_at ),
			'created_at' => $row->created_at,
		);
	}

	/**
	 * Build a human-readable notification message.
	 *
	 * @param string $type       Notification type.
	 * @param string $actor_name Actor display name.
	 * @param string $media_title Media title (if applicable).
	 * @return string
	 */
	private function build_notification_message( string $type, string $actor_name, string $media_title ): string {
		/**
		 * Allows Pro / extensions to supply a label for custom notification types
		 * without having to extend the switch below. Return a non-empty string to
		 * override the default label; return null to fall through.
		 *
		 * @since 1.1.2
		 *
		 * @param string|null $label       Custom label or null to use the default switch.
		 * @param string      $type        Notification type.
		 * @param string      $actor_name  Actor display name.
		 * @param string      $media_title Media title (if any).
		 */
		$custom_label = apply_filters( 'mvs_notification_message', null, $type, $actor_name, $media_title );
		if ( is_string( $custom_label ) && '' !== $custom_label ) {
			return $custom_label;
		}

		switch ( $type ) {
			case 'new_follower':
				/* translators: %s: user name */
				return sprintf( __( '%s started following you', 'wpmediaverse' ), $actor_name );

			case 'media_reaction':
				if ( $media_title ) {
					/* translators: 1: user name, 2: media title */
					return sprintf( __( '%1$s reacted to %2$s', 'wpmediaverse' ), $actor_name, $media_title );
				}
				/* translators: %s: user name */
				return sprintf( __( '%s reacted to your media', 'wpmediaverse' ), $actor_name );

			case 'media_comment':
				if ( $media_title ) {
					/* translators: 1: user name, 2: media title */
					return sprintf( __( '%1$s commented on %2$s', 'wpmediaverse' ), $actor_name, $media_title );
				}
				/* translators: %s: user name */
				return sprintf( __( '%s commented on your media', 'wpmediaverse' ), $actor_name );

			case 'media_mention':
				/* translators: %s: user name */
				return sprintf( __( '%s mentioned you', 'wpmediaverse' ), $actor_name );

			case 'media_favorite':
				if ( $media_title ) {
					/* translators: 1: user name, 2: media title */
					return sprintf( __( '%1$s favorited %2$s', 'wpmediaverse' ), $actor_name, $media_title );
				}
				/* translators: %s: user name */
				return sprintf( __( '%s favorited your media', 'wpmediaverse' ), $actor_name );

			case 'new_message':
				/* translators: %s: user name */
				return sprintf( __( '%s sent you a message', 'wpmediaverse' ), $actor_name );

			default:
				/* translators: %s: user name */
				return sprintf( __( '%s interacted with your content', 'wpmediaverse' ), $actor_name );
		}
	}
}
