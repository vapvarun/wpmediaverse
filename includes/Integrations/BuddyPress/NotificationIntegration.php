<?php
/**
 * BuddyPress notification integration.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Integrations\BuddyPress;

defined( 'ABSPATH' ) || exit;


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
	 * BP component_action names these three types have always used. Existing
	 * bp_notifications rows carry them, so they stay; every other type is
	 * mirrored as `mvs_<type>` (see bp_action()).
	 */
	private const TYPE_TO_BP_ACTION = array(
		'media_reaction' => 'mvs_new_reaction',
		'media_comment'  => 'mvs_new_comment',
		'media_mention'  => 'mvs_new_mention',
	);

	/**
	 * Types BuddyPress does not carry. Direct messages have their own unread
	 * badge on the chat button; everything else reaches the one BP bell.
	 */
	private const IN_APP_ONLY = array( 'new_message' );

	/**
	 * BP notification meta key holding the MediaVerse row's object id (2.6.0).
	 */
	private const OBJECT_META = 'mvs_object_id';

	/**
	 * BP component_action for a notification type.
	 *
	 * By rule, not by list: a new type (including every Pro type registered
	 * through `mvs_notification_types`) reaches BuddyPress without anyone
	 * remembering to add it here. With BP notifications on, the MediaVerse
	 * bell is hidden, so an unmirrored type is a notification nobody sees
	 * (follows and favorites were, Basecamp 10296867984).
	 *
	 * @param string $type Notification type.
	 * @return string
	 */
	private static function bp_action( string $type ): string {
		return self::TYPE_TO_BP_ACTION[ $type ] ?? 'mvs_' . $type;
	}

	/**
	 * Notification type for a stored BP component_action (inverse of bp_action()).
	 *
	 * @param string $action BP component_action.
	 * @return string Type, or '' when the action is not ours.
	 */
	private static function type_from_action( string $action ): string {
		$legacy = array_search( $action, self::TYPE_TO_BP_ACTION, true );
		if ( false !== $legacy ) {
			return (string) $legacy;
		}
		return 0 === strpos( $action, 'mvs_' ) ? substr( $action, 4 ) : '';
	}

	/**
	 * Register all notification hooks.
	 */
	public function init(): void {
		if ( ! function_exists( 'buddypress' ) || ! bp_is_active( 'notifications' ) ) {
			return;
		}

		add_action( 'mvs_notification_created', array( $this, 'on_notification_created' ), 10, 8 );
		add_action( 'mvs_media_deleted', array( $this, 'cleanup_notifications' ), 10, 2 );
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
	 * @param string $message         Unused.
	 * @param string $link            Unused.
	 * @param int    $object_id       The row's comment_id slot. Kept as BP meta
	 *                                (item_id holds the media id, and a media id
	 *                                and a competition id can be the same number).
	 */
	public function on_notification_created( int $notification_id, int $user_id, string $type, int $actor_id, int $media_id, string $message = '', string $link = '', int $object_id = 0 ): void {
		unset( $notification_id );

		if ( defined( 'MVS_RUNNING_TESTS' ) || ! function_exists( 'bp_notifications_add_notification' ) ) {
			return;
		}

		if ( in_array( $type, self::IN_APP_ONLY, true ) ) {
			return;
		}

		// No media-id or self check: a follow has no media, and create() only
		// writes a self-notification when the type asks for it (Pro results).
		if ( $user_id <= 0 || $actor_id <= 0 ) {
			return;
		}

		// Duplicate-notification guard (Basecamp #10077983779). When the media
		// has a linked BP activity, a comment on it is also posted as a BP
		// activity comment (ActivitySyncIntegration::sync_media_comment_to_activity),
		// and BP's own notification system already notifies the activity author
		// (= media owner) with a native "replied" notification. Mirroring the MVS
		// media_comment on top of that gives the owner two dropdown entries for
		// one comment. Skip the mirror when BP already covers it; the native MVS
		// in-app notification (mvs_notifications) is untouched. Sites can restore
		// the old double-notify behavior via the filter.
		if ( 'media_comment' === $type && $this->bp_activity_covers_comment( $media_id ) ) {
			/**
			 * Filter whether to suppress the BP-mirrored media_comment notification
			 * when a BP-native activity-comment notification already fires.
			 *
			 * @param bool $suppress Default true (suppress the duplicate).
			 * @param int  $media_id Media ID.
			 * @param int  $user_id  Recipient (media owner) user ID.
			 * @param int  $actor_id Commenter user ID.
			 */
			if ( apply_filters( 'mvs_suppress_bp_comment_notification', true, $media_id, $user_id, $actor_id ) ) {
				return;
			}
		}

		$bp_id = bp_notifications_add_notification(
			array(
				'user_id'           => $user_id,
				'item_id'           => $media_id,
				'secondary_item_id' => $actor_id,
				'component_name'    => 'wpmediaverse',
				'component_action'  => self::bp_action( $type ),
			)
		);
		if ( $bp_id && $object_id > 0 ) {
			bp_notifications_update_meta( (int) $bp_id, self::OBJECT_META, $object_id );
		}
	}

	/**
	 * Whether a BP-native activity-comment notification already covers a comment
	 * on this media (so the MVS mirror would be a duplicate).
	 *
	 * True only when the activity component is active AND the media has a linked
	 * BP activity — the exact condition under which
	 * ActivitySyncIntegration::sync_media_comment_to_activity() posts a BP
	 * activity comment and BP notifies the activity author.
	 *
	 * @param int $media_id Media ID.
	 * @return bool
	 */
	private function bp_activity_covers_comment( int $media_id ): bool {
		if ( ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'activity' ) ) {
			return false;
		}

		$activity_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'bp_activity_id' );

		return $activity_id > 0;
	}

	/**
	 * Remove BP notifications mirrored for a media item when it is deleted.
	 *
	 * Mirrors ActivitySyncIntegration's mvs_media_deleted cleanup: without this,
	 * bp_notifications rows created by on_notification_created (component
	 * 'wpmediaverse', item_id = media_id) dangle after the media is gone and keep
	 * rendering in the owner's dropdown (Basecamp #10077983779).
	 *
	 * @param int $media_id  Deleted media ID.
	 * @param int $author_id Media author (unused; kept for hook signature).
	 */
	public function cleanup_notifications( int $media_id, int $author_id ): void {
		unset( $author_id );

		if ( $media_id <= 0 || ! function_exists( 'bp_notifications_delete_all_notifications_by_type' ) ) {
			return;
		}

		// Delete every wpmediaverse notification pointing at this media, across
		// all recipients. bp_notifications_delete_notifications_by_item_id() is
		// the WRONG helper here — it requires a specific user_id + component_action;
		// _all_notifications_by_type() clears the item for the whole component.
		bp_notifications_delete_all_notifications_by_type( $media_id, 'wpmediaverse' );
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

		$type = self::type_from_action( (string) $component_action );
		if ( '' === $type ) {
			return $content;
		}

		// Same words and link as MediaVerse's own notification list.
		$media_id = (int) $item_id;
		$repo     = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$rendered = \WPMediaVerse\Core\Plugin::container()->get( 'notifications' )
			->build_message_and_link( $type, (int) $secondary_item_id, $media_id > 0 && $repo->exists( $media_id ) ? $media_id : 0, (int) bp_notifications_get_meta( (int) $id, self::OBJECT_META ) );
		$text     = $rendered['message'];
		$link     = '' !== $rendered['link'] ? $rendered['link'] : bp_get_notifications_permalink();

		if ( 'string' === $format ) {
			return '<a href="' . esc_url( $link ) . '">' . esc_html( $text ) . '</a>';
		}

		return array(
			'text' => esc_html( $text ),
			'link' => esc_url( $link ),
		);
	}
}
