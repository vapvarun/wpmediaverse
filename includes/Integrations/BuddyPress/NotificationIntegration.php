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
 * Subscribes to the single `mvs_notification_created` signal emitted by
 * NotificationService::create() and mirrors applicable types into BP's
 * notification system. Does NOT listen on raw plugin hooks (mvs_reaction_added
 * etc.) — that pattern produced duplicate notifications when both this class
 * and NotificationService ran for the same event.
 */
class NotificationIntegration {

	/**
	 * Map of NotificationService types to BP component_action values.
	 * Types not in this map are in-app only (e.g. new_message, new_follower).
	 */
	private const TYPE_TO_BP_ACTION = array(
		'media_reaction' => 'mvs_new_reaction',
		'media_comment'  => 'mvs_new_comment',
		'media_mention'  => 'mvs_new_mention',
	);

	/**
	 * Register all notification hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		add_action( 'mvs_notification_created', array( $this, 'on_notification_created' ), 10, 5 );
		add_filter( 'bp_notifications_get_registered_components', array( $this, 'register_notification_component' ) );
		add_filter( 'bp_notifications_get_notifications_for_user', array( $this, 'format_notifications' ), 10, 8 );
		add_action( 'bp_nouveau_notifications_init_filters', array( $this, 'register_notification_filters' ) );
	}

	/**
	 * Mirror a newly created in-app notification into BP notifications.
	 *
	 * Emitted by NotificationService::create() after the mvs_notifications row
	 * is inserted. One subscriber per event eliminates the duplicate-hook
	 * registration bug.
	 *
	 * @param int    $notification_id Row ID from mvs_notifications (unused here).
	 * @param int    $user_id         Recipient user ID.
	 * @param string $type            Notification type (e.g. media_reaction).
	 * @param int    $actor_id        User who triggered the event.
	 * @param int    $media_id        Related media ID (0 if none).
	 */
	public function on_notification_created( int $notification_id, int $user_id, string $type, int $actor_id, int $media_id ): void {
		unset( $notification_id );

		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) ) {
			return;
		}

		if ( ! isset( self::TYPE_TO_BP_ACTION[ $type ] ) ) {
			return;
		}

		if ( $user_id <= 0 || $actor_id <= 0 || $media_id <= 0 || $user_id === $actor_id ) {
			return;
		}

		bp_notifications_add_notification(
			array(
				'user_id'           => $user_id,
				'item_id'           => $media_id,
				'secondary_item_id' => $actor_id,
				'component_name'    => 'wpmediaverse',
				'component_action'  => self::TYPE_TO_BP_ACTION[ $type ],
			)
		);
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
		$link      = MediaRepository::exists( $item_id )
			? MediaRepository::get_permalink( $item_id )
			: bp_get_notifications_permalink();

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
