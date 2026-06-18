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
		// Check custom tables first (media items live in mvs_media_index).
		$is_media = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id );

		if ( $is_media ) {
			$author_id = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id );
		} else {
			// Albums and collections are still CPTs.
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
		if ( $user_id > 0 && in_array( $privacy, array( 'private', 'friends', 'group', 'custom' ), true ) ) {
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
