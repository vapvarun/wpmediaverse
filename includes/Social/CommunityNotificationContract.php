<?php
/**
 * BuddyNext / community notification contract.
 *
 * A host community plugin (BuddyNext) is a display + push layer, never a
 * second copy of this plugin's own rules: the payload is built ONCE, from the
 * SAME message/link NotificationService already renders for its own bell and
 * for `mvs_notification_created`'s existing 6th/7th arguments, and visibility
 * answers through the SAME `PrivacyService::can_view()` every other privacy
 * decision in this plugin goes through — never a second access rule.
 *
 * Four of this plugin's own types are a HOST's screen, not this plugin's:
 * follows, media comments, favorites and direct messages already have their
 * own first-class surface in a community plugin, so the host tells this
 * plugin to skip creating its own copy (`mvs_should_send_notification`) and
 * sends its own bell row + email + push. This class never carries a contract
 * payload for those four either way, so a host that forgets the skip filter
 * still cannot get a double notification from the contract side.
 *
 * @package    WPMediaVerse
 * @subpackage Social
 * @since      2.6.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the shared community-notification payload and registers the three
 * remaining contract seams (types, visibility, removal).
 */
final class CommunityNotificationContract {

	/**
	 * Types a host community plugin already owns its own bell row + email +
	 * push for. This plugin's `mvs_should_send_notification` filter already
	 * lets a host skip creating these entirely (see e.g. BuddyNext's
	 * `WPMediaVerseBridge::skip_duplicate_mvs_notification()`), so on a site
	 * with that filter wired these never even reach `NotificationService::
	 * create()`. Excluded here too, defensively, so a host that has NOT
	 * wired the skip filter still never gets a double notification from the
	 * contract side.
	 */
	private const HOST_OWNED_TYPES = array( 'new_follower', 'media_comment', 'media_favorite', 'new_message' );

	/**
	 * Bell object type for a WPMediaVerse media item.
	 */
	private const OBJECT_TYPE = 'media';

	/**
	 * Register the three contract seams a host needs beyond the payload
	 * itself (which NotificationService builds inline via self::payload()).
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'mvs_community_notification_types', array( __CLASS__, 'declare_types' ) );
		add_filter( 'mvs_community_notification_visible', array( __CLASS__, 'filter_visible' ), 10, 3 );
		add_action(
			'mvs_media_deleted',
			static function ( $media_id ): void {
				$media_id = (int) $media_id;
				if ( $media_id > 0 ) {
					do_action( 'mvs_community_notification_removed', self::OBJECT_TYPE, $media_id );
				}
			},
			10,
			1
		);
	}

	/**
	 * Build the contract payload for one notification, or `array()` when it
	 * is not community-notification material: no real actor/recipient, the
	 * recipient acting on themselves, a type a host already owns, or one
	 * with no message/destination to show (`report_resolved` has neither —
	 * it names no media and links nowhere a host's bell could deep-link to,
	 * so it is a plugin-native-only notification and never declared below).
	 *
	 * @since 2.6.0
	 *
	 * @param int    $notification_id This plugin's own notification row id.
	 * @param int    $user_id         Recipient user id.
	 * @param string $type            Notification type.
	 * @param int    $actor_id        User who triggered it.
	 * @param int    $media_id        Related media id (0 if none).
	 * @param string $message         Rendered message (NotificationService::build_message_and_link()).
	 * @param string $link            Rendered deep link (same builder).
	 * @return array<string,mixed>
	 */
	public static function payload( int $notification_id, int $user_id, string $type, int $actor_id, int $media_id, string $message, string $link ): array {
		if ( $user_id <= 0 || $actor_id <= 0 || $actor_id === $user_id ) {
			return array();
		}

		$type = sanitize_key( $type );
		if ( '' === $type || in_array( $type, self::HOST_OWNED_TYPES, true ) ) {
			return array();
		}

		$message = trim( wp_strip_all_tags( $message ) );
		$link    = trim( $link );
		if ( '' === $message || '' === $link ) {
			return array();
		}

		return array(
			'recipient_id'    => $user_id,
			'type'            => $type,
			'actor_id'        => $actor_id,
			'object_type'     => $media_id > 0 ? self::OBJECT_TYPE : '',
			'object_id'       => $media_id,
			'message'         => $message,
			'url'             => $link,
			'context'         => $media_id > 0 ? array(
				'type' => self::OBJECT_TYPE,
				'id'   => $media_id,
			) : array(),
			'notification_id' => $notification_id,
		);
	}

	/**
	 * Declare the types this plugin sends through the contract. Only types
	 * that actually reach payload() with a real message + link — see the
	 * docblock on payload() for why `report_resolved` is not here.
	 *
	 * @param array<string,array<string,mixed>> $types Existing declared types.
	 * @return array<string,array<string,mixed>>
	 */
	public static function declare_types( array $types ): array {
		$types['media_reaction'] = array(
			'label'       => __( 'Reactions to your media', 'wpmediaverse' ),
			'description' => __( 'Someone reacted to a photo, video or audio clip you shared.', 'wpmediaverse' ),
			'default_on'  => true,
		);
		$types['media_mention']  = array(
			'label'       => __( 'Mentions', 'wpmediaverse' ),
			'description' => __( 'Someone mentioned you in a comment on a media item.', 'wpmediaverse' ),
			'default_on'  => true,
		);
		return $types;
	}

	/**
	 * Answer visibility per bell page: the SAME `PrivacyService::can_view()`
	 * check every other privacy decision in this plugin goes through — never
	 * a second rule for the community bell. Bounded to a bell page (~20
	 * rows, per the contract), matching the one-call-per-row shape
	 * `PrivacyService` already caches internally per request.
	 *
	 * @param array<int|string,bool>                $visible Answer so far (every key true).
	 * @param int                                   $viewer_id Viewer reading their bell.
	 * @param array<int|string,array<string,mixed>> $targets Rows to answer for.
	 * @return array<int|string,bool>
	 */
	public static function filter_visible( array $visible, int $viewer_id, array $targets ): array {
		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'privacy' );
		if ( ! is_object( $privacy ) || ! method_exists( $privacy, 'can_view' ) ) {
			return $visible;
		}

		foreach ( $targets as $key => $target ) {
			if ( self::OBJECT_TYPE !== (string) ( $target['object_type'] ?? '' ) ) {
				continue;
			}
			$media_id        = (int) ( $target['object_id'] ?? 0 );
			$visible[ $key ] = $media_id > 0 && $privacy->can_view( $media_id, $viewer_id );
		}

		return $visible;
	}
}
