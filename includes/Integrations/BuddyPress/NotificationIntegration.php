<?php
/**
 * BuddyPress notification integration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Handles BuddyPress notifications for WPMediaVerse events.
 *
 * - Sends BP notifications for reactions, comments, and @mentions.
 * - Registers the wpmediaverse notification component.
 * - Registers notification dropdown filters for BP Nouveau.
 * - Formats notification content and links.
 */
class NotificationIntegration {

	/**
	 * Register all notification hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		add_action( 'mvs_reaction_added', array( $this, 'notify_reaction' ), 10, 3 );
		add_action( 'mvs_comment_created', array( $this, 'notify_comment' ), 10, 3 );
		add_action( 'mvs_mentions_created', array( $this, 'notify_mentions' ), 10, 2 );
		add_filter( 'bp_notifications_get_registered_components', array( $this, 'register_notification_component' ) );
		add_filter( 'bp_notifications_get_notifications_for_user', array( $this, 'format_notifications' ), 10, 8 );
		add_action( 'bp_nouveau_notifications_init_filters', array( $this, 'register_notification_filters' ) );
	}

	/**
	 * Send a BP notification when someone reacts to media.
	 *
	 * @param int    $media_id      Media post ID.
	 * @param int    $user_id       User who reacted.
	 * @param string $reaction_type Reaction type.
	 */
	public function notify_reaction( int $media_id, int $user_id, string $reaction_type ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$owner_id = MediaRepository::get_author( $media_id );
		if ( ! $owner_id || $owner_id === $user_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => $owner_id,
				'item_id'           => $media_id,
				'secondary_item_id' => $user_id,
				'component_name'    => 'wpmediaverse',
				'component_action'  => 'mvs_new_reaction',
			)
		);
	}

	/**
	 * Send a BP notification when someone comments on media.
	 *
	 * Hook signature: mvs_comment_created( $media_id, $user_id, $comment_id, $content ).
	 *
	 * @param int $media_id   Media post ID.
	 * @param int $user_id    Commenter user ID.
	 * @param int $comment_id Comment ID.
	 */
	public function notify_comment( int $media_id, int $user_id, int $comment_id ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$comment  = get_comment( $comment_id );
		$owner_id = MediaRepository::get_author( $media_id );
		if ( ! $comment || ! $owner_id || $owner_id === (int) $comment->user_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => $owner_id,
				'item_id'           => $media_id,
				'secondary_item_id' => (int) $comment->user_id,
				'component_name'    => 'wpmediaverse',
				'component_action'  => 'mvs_new_comment',
			)
		);
	}

	/**
	 * Send BP notifications for @mentions.
	 *
	 * @param int   $media_id      Media post ID.
	 * @param array $mentioned_ids Array of mentioned user IDs.
	 */
	public function notify_mentions( int $media_id, array $mentioned_ids ): void {
		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		$actor_id = MediaRepository::get_author( $media_id );

		foreach ( $mentioned_ids as $mentioned_id ) {
			if ( (int) $mentioned_id === $actor_id ) {
				continue;
			}

			bp_notifications_add_notification(
				array(
					'user_id'           => (int) $mentioned_id,
					'item_id'           => $media_id,
					'secondary_item_id' => $actor_id,
					'component_name'    => 'wpmediaverse',
					'component_action'  => 'mvs_new_mention',
				)
			);
		}
	}

	/**
	 * Register the notification component.
	 *
	 * @param array $components Registered components.
	 * @return array
	 */
	public function register_notification_component( array $components ): array {
		$components[] = 'wpmediaverse';
		return $components;
	}

	/**
	 * Register notification filters for BP Nouveau's notification dropdown.
	 */
	public function register_notification_filters(): void {
		if ( ! function_exists( 'bp_nouveau_notifications_register_filter' ) ) {
			return;
		}

		bp_nouveau_notifications_register_filter(
			array(
				'id'       => 'mvs_new_reaction',
				'label'    => __( 'Media Reactions', 'wpmediaverse' ),
				'position' => 80,
			)
		);

		bp_nouveau_notifications_register_filter(
			array(
				'id'       => 'mvs_new_comment',
				'label'    => __( 'Media Comments', 'wpmediaverse' ),
				'position' => 85,
			)
		);

		bp_nouveau_notifications_register_filter(
			array(
				'id'       => 'mvs_new_mention',
				'label'    => __( 'Media Mentions', 'wpmediaverse' ),
				'position' => 90,
			)
		);
	}

	/**
	 * Format notification content.
	 *
	 * @param string $content           Notification content.
	 * @param int    $item_id           Item ID.
	 * @param int    $secondary_item_id Secondary item ID.
	 * @param int    $total_items       Total items.
	 * @param string $format            Format (string or array).
	 * @param string $component_action  Action name.
	 * @param string $component_name    Component name.
	 * @param int    $id                Notification ID.
	 * @return string|array
	 */
	public function format_notifications( $content, $item_id, $secondary_item_id, $total_items, $format, $component_action, $component_name, $id ) {
		if ( 'wpmediaverse' !== $component_name ) {
			return $content;
		}

		$user_name = bp_core_get_user_displayname( $secondary_item_id );
		$link      = MediaRepository::exists( $item_id ) ? MediaRepository::get_permalink( $item_id ) : '';

		switch ( $component_action ) {
			case 'mvs_new_reaction':
				/* translators: %s: user display name */
				$text = sprintf( __( '%s reacted to your media', 'wpmediaverse' ), $user_name );
				break;
			case 'mvs_new_comment':
				/* translators: %s: user display name */
				$text = sprintf( __( '%s commented on your media', 'wpmediaverse' ), $user_name );
				break;
			case 'mvs_new_mention':
				/* translators: %s: user display name */
				$text = sprintf( __( '%s mentioned you', 'wpmediaverse' ), $user_name );
				break;
			default:
				return $content;
		}

		if ( 'string' === $format ) {
			return '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
		}

		return array(
			'text' => esc_html( $text ),
			'link' => esc_url( $link ),
		);
	}
}
