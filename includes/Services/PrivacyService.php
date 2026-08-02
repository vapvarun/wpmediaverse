<?php
/**
 * Privacy service.
 *
 * Handles media visibility checks based on privacy levels.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;


/**
 * Handles media visibility checks based on privacy levels.
 */
class PrivacyService {

	/**
	 * Per-request cache for access check results.
	 *
	 * @var array<string, bool>
	 */
	private $cache = array();

	/**
	 * Numeric restrictiveness of a privacy slug. Higher is more restrictive.
	 *
	 * Canonical home for this ordering. It grew up as a static on
	 * Integrations\BuddyPress\ActivitySyncIntegration, which meant general
	 * privacy logic lived inside a BuddyPress integration and was unavailable
	 * to anything that did not want to load BP. That static now delegates here.
	 *
	 * @since 2.3.0
	 *
	 * @param string $privacy Privacy slug.
	 * @return int 0 public, 20 members, 40 friends, 60 group, 80 private, 90 custom.
	 */
	public static function privacy_to_level( string $privacy ): int {
		switch ( $privacy ) {
			case 'public':
				return 0;
			case 'members':
				return 20;
			case 'friends':
				return 40;
			case 'group':
				return 60;
			case 'private':
				return 80;
			case 'custom':
				return 90;
			default:
				return 0;
		}
	}

	/**
	 * Return whichever of two privacy slugs is more restrictive.
	 *
	 * @since 2.3.0
	 *
	 * @param string $a First slug.
	 * @param string $b Second slug.
	 * @return string The more restrictive slug.
	 */
	public static function more_restrictive( string $a, string $b ): string {
		return self::privacy_to_level( $a ) >= self::privacy_to_level( $b ) ? $a : $b;
	}

	/**
	 * Effective privacy for a media item — most restrictive of its own privacy
	 * and its parent album's.
	 *
	 * @since 2.3.0
	 *
	 * @param int $media_id Media ID.
	 * @return string Privacy slug.
	 */
	public static function effective_privacy_for_media( int $media_id ): string {
		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$media_privacy = (string) $repo->get( $media_id, 'privacy' );
		if ( '' === $media_privacy ) {
			$media_privacy = 'public';
		}

		$album_id = (int) $repo->get( $media_id, 'album_id' );
		if ( $album_id <= 0 ) {
			return $media_privacy;
		}

		$album_privacy = (string) $repo->get( $album_id, 'privacy' );
		if ( '' === $album_privacy || 'public' === $album_privacy ) {
			return $media_privacy;
		}

		return self::more_restrictive( $album_privacy, $media_privacy );
	}

	/**
	 * Check if a user can view a media item.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID (0 for anonymous).
	 * @return bool
	 */
	public function can_view( int $media_id, int $user_id = 0 ): bool {
		$cache_key = "{$media_id}:{$user_id}";

		if ( isset( $this->cache[ $cache_key ] ) ) {
			return $this->cache[ $cache_key ];
		}

		$result = $this->check_access( $media_id, $user_id );

		$this->cache[ $cache_key ] = $result;

		return $result;
	}

	/**
	 * Perform the actual access check.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  User ID.
	 * @return bool
	 */
	private function check_access( int $media_id, int $user_id ): bool {
		// Resolve the item's author. A real media item lives in mvs_media_index
		// with a concrete media_type (image/video/audio). Albums and collections
		// are CPTs whose authoritative author is wp_posts.post_author — but they
		// may ALSO get an mvs_media_index row that only stores their privacy
		// (media_type left empty). That row's post_author is unreliable (0 or
		// stale), so an indexed album must NOT be treated as media, or the owner
		// is denied their own non-public album (Basecamp 10071824547). Keying on
		// media_type keeps the hot media-grid path (media_type set) fast — no
		// get_post() — and only albums/collections fall through to the CPT branch.
		$repo       = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$in_index   = $repo->exists( $media_id );
		$media_type = $in_index ? (string) $repo->get( $media_id, 'media_type' ) : '';

		if ( $in_index && '' !== $media_type ) {
			$author_id = $repo->get_author( $media_id );
		} else {
			// Album / collection CPT (no index row, or a privacy-only row).
			$post          = get_post( $media_id );
			$allowed_types = array( 'mvs_album', 'mvs_collection' );
			if ( ! $post || ! in_array( $post->post_type, $allowed_types, true ) ) {
				return false;
			}
			$author_id = (int) $post->post_author;
		}

		// Owners and admins always have access.
		if ( $user_id && ( $author_id === $user_id || user_can( $user_id, 'moderate_mvs_media' ) ) ) {
			return true;
		}

		$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'privacy' );
		if ( ! $privacy ) {
			$privacy = 'public';
		}

		/**
		 * Filter the privacy access check result.
		 *
		 * Return a non-null boolean to short-circuit the built-in check.
		 *
		 * @param bool|null $result   Access result. Null to use default logic.
		 * @param int       $media_id Media post ID.
		 * @param int       $user_id  User ID.
		 * @param string    $privacy  Privacy level.
		 */
		$filtered = apply_filters( 'mvs_privacy_can_view', null, $media_id, $user_id, $privacy );
		if ( null !== $filtered ) {
			return (bool) $filtered;
		}

		// DM grant: media shared into a direct-message conversation must be
		// viewable by that conversation's participants regardless of the media's
		// own privacy — otherwise the recipient's thumbnail AND download both
		// fail ("We couldn't find that media"). Consulted only for restrictive
		// levels (public/members/loggedin already resolve below) and only when
		// the messaging engine is loaded. Owner/admin were granted earlier.
		if ( $user_id > 0 && in_array( $privacy, array( 'private', 'dm', 'friends', 'group', 'custom' ), true ) ) {
			$container = \WPMediaVerse\Core\Plugin::container();
			if ( $container->has( 'messaging' ) ) {
				$messaging = $container->get( 'messaging' );
				if ( method_exists( $messaging, 'user_received_media' ) && $messaging->user_received_media( $user_id, $media_id ) ) {
					return true;
				}
			}
		}

		switch ( $privacy ) {
			case 'public':
				return true;

			case 'members':
			case 'loggedin':
				return $user_id > 0;

			case 'friends':
				return $this->check_friends( $author_id, $user_id );

			case 'group':
				return $this->check_group( $media_id, $user_id );

			case 'private':
				// Owner/admin already handled above.
				return false;

			case 'dm':
				// Conversation-scoped media (DM attachments). Owner/admin were
				// granted above; the DM grant above admits the conversation's
				// participants. Everyone else is denied — these never appear on
				// any public surface, activity feed, or wall.
				return false;

			case 'custom':
				return $this->check_custom( $media_id, $user_id );

			default:
				return false;
		}
	}

	/**
	 * Check BuddyPress friendship. Falls back to private if BP inactive.
	 *
	 * @param int $owner_id Media owner user ID.
	 * @param int $user_id  Requesting user ID.
	 * @return bool
	 */
	private function check_friends( int $owner_id, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		if ( ! function_exists( 'friends_check_friendship' ) ) {
			// BuddyPress not active — fall back to private.
			return false;
		}

		return friends_check_friendship( $owner_id, $user_id );
	}

	/**
	 * Check BuddyPress group membership. Falls back to private if BP inactive.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  Requesting user ID.
	 * @return bool
	 */
	private function check_group( int $media_id, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		if ( ! function_exists( 'groups_is_user_member' ) ) {
			// BuddyPress not active — fall back to private.
			return false;
		}

		$group_id = (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'group_id' );
		if ( ! $group_id ) {
			return false;
		}

		return groups_is_user_member( $user_id, $group_id );
	}

	/**
	 * Check custom user ID access list.
	 *
	 * @param int $media_id Media post ID.
	 * @param int $user_id  Requesting user ID.
	 * @return bool
	 */
	private function check_custom( int $media_id, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		$allowed_users = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'custom_access' );
		if ( ! is_array( $allowed_users ) ) {
			return false;
		}

		return in_array( $user_id, array_map( 'intval', $allowed_users ), true );
	}

	/**
	 * Clear the per-request cache.
	 */
	public function flush_cache(): void {
		$this->cache = array();
	}
}
