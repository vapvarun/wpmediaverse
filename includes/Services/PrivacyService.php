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
	 * The privacy levels this site will ACCEPT on a write.
	 *
	 * One list, asked by every write path. It used to be an inline array in
	 * UploadService and nothing at all on `PUT /media/{id}`, so the update route
	 * stored whatever string it was handed — `banana` included, which then failed
	 * closed in `check_access()`'s default arm and read as private forever. A
	 * value the API accepts and never applies is worse than one it rejects
	 * (Basecamp 10220491230).
	 *
	 * Filterable rather than a hard enum because the vocabulary genuinely is
	 * extensible: `mvs_privacy_can_view` lets an extension answer for a level
	 * this class has never heard of. An extension that adds one must add it here
	 * too, or writes of it are refused at the edge — which is the intended
	 * failure, not an accident.
	 *
	 * @since 2.4.0
	 *
	 * @return string[]
	 */
	public static function supported_levels(): array {
		return array_values(
			array_unique(
				array_map(
					'strval',
					(array) apply_filters(
						'mvs_privacy_levels',
						array( 'public', 'members', 'loggedin', 'friends', 'group', 'space', 'private', 'dm', 'custom' )
					)
				)
			)
		);
	}

	/**
	 * WHICH DRIVE a new media item lands on: personal, or a Space.
	 *
	 * A client posting media into a Space answers `mvs_media_drive` with
	 * array( 'space', <id> ), and the row is stamped so `privacy = space`
	 * resolves through the drive bridge in `check_space()`. MediaVerse never
	 * queries `bn_*` — the bridge answers, the same seam documents use.
	 *
	 * AUTHORIZED HERE, not by the caller. A member must not file media into a
	 * Space they cannot write to, or it becomes readable by that Space's members.
	 * The binding is admitted only when the drive-access bridge grants
	 * `write`/`own` (the SAME gate documents use); anything else falls back to
	 * the personal drive.
	 *
	 * FROZEN CONTRACT (pairs with BuddyNext card 10220148861, and declared in
	 * Pro's DriveContract::FILTER_MEDIA_DRIVE):
	 * apply_filters( 'mvs_media_drive', array( 'user', 0 ), int $user_id, array $args )
	 * returns array( string $drive_type, int $drive_id ).
	 *
	 * @since 2.4.0
	 *
	 * @param int                  $user_id User doing the write.
	 * @param array<string, mixed> $args    Write args, passed to the filter unchanged.
	 * @return array{0:string, 1:int} Drive type and id; array( 'user', 0 ) when personal.
	 */
	public static function resolve_drive_for_user( int $user_id, array $args = array() ): array {
		$drive = apply_filters( 'mvs_media_drive', array( 'user', 0 ), $user_id, $args );

		if ( ! is_array( $drive ) || ! isset( $drive[0] ) || 'user' === (string) $drive[0] ) {
			return array( 'user', 0 );
		}

		$type = (string) $drive[0];
		$id   = (int) ( $drive[1] ?? 0 );

		if ( $id <= 0 ) {
			return array( 'user', 0 );
		}

		$level = (string) apply_filters( 'mvs_document_drive_access', 'none', $type, $id, $user_id );

		return in_array( $level, array( 'write', 'own' ), true ) ? array( $type, $id ) : array( 'user', 0 );
	}

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
			case 'space':
				// The same restriction as a BuddyPress group, because it is the
				// same shape of restriction: readable by a defined membership.
				// `group` stays BuddyPress-only and `space` is BuddyNext's — a
				// space id must never be written into `group_id` (plan §23.3).
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

		// Album privacy is a property of the album post, not of any media row.
		// Before 2.4.0 this read mvs_media_index at media_id = <album post ID>, which
		// on a colliding ID returned an unrelated PHOTO's privacy and clamped this
		// item against it. Plan: plan/2026-08-08-cpt-id-collision-fix-plan.md §4.0.
		$album_privacy = \WPMediaVerse\Core\Plugin::container()->get( 'albums' )->get_privacy( $album_id );
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
		// Resolve the item's author, and with it which KIND of thing this ID is.
		//
		// Albums and collections are CPTs; media lives in mvs_media_index. Since
		// 2.4.0 those are two clean ID spaces — a CPT never writes an index row — so
		// the post type is authoritative and cheap to trust.
		//
		// This replaces the media_type sniff added by 3cfff321 for Basecamp
		// 10071824547 ("owner denied their own private album"). That workaround read
		// the index row and, when media_type was empty, treated the ID as a CPT. It
		// could not survive a collision: where an album's post ID landed on a real
		// photo, media_type was 'image', so the album was access-checked as that
		// photo — resolving to the PHOTO's owner and the PHOTO's privacy. The album's
		// owner was denied their own album and an unrelated member was granted it.
		// Root cause, not symptom: plan/2026-08-08-cpt-id-collision-fix-plan.md §4.0.
		$repo          = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$post_type     = get_post_type( $media_id );
		$allowed_types = array( 'mvs_album', 'mvs_collection' );

		if ( $post_type && in_array( $post_type, $allowed_types, true ) ) {
			// Album / collection CPT — wp_posts.post_author is authoritative.
			$author_id = (int) get_post_field( 'post_author', $media_id );
		} elseif ( $repo->exists( $media_id ) ) {
			$author_id = $repo->get_author( $media_id );
		} else {
			return false;
		}

		// Owners and admins always have access.
		if ( $user_id && ( $author_id === $user_id || user_can( $user_id, 'moderate_mvs_media' ) ) ) {
			return true;
		}

		// Same split for the privacy value itself: an album's lives in post meta,
		// a media item's in its index row.
		if ( $post_type && in_array( $post_type, $allowed_types, true ) ) {
			$privacy = \WPMediaVerse\Core\Plugin::container()->get( 'albums' )->get_privacy( $media_id );
		} else {
			$privacy = (string) $repo->get( $media_id, 'privacy' );
		}
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
		if ( $user_id > 0 && in_array( $privacy, array( 'private', 'dm', 'friends', 'group', 'space', 'custom' ), true ) ) {
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

			case 'space':
				return $this->check_space( $media_id, $user_id );

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
	 * Check membership of the space this media lives on.
	 *
	 * NOT a second authority. It asks the frozen drive filter — the same one
	 * `GET /drives` and every document gate ask — so a space's library and its
	 * privacy cannot answer differently for the same viewer. MediaVerse still
	 * never queries `bn_*`; the bridge answers.
	 *
	 * The drive columns are the source, never `group_id`: those are BuddyPress's,
	 * they die with BuddyPress, and an importer would read a space id there as a
	 * BP group (plan §23.3 anti-pattern 1).
	 *
	 * Falls back to private when nothing answers, which is what an unbridged site
	 * should do with a privacy level it cannot evaluate.
	 *
	 * @since 2.4.0
	 *
	 * @param int $media_id Media id.
	 * @param int $user_id  Requesting user.
	 * @return bool
	 */
	private function check_space( int $media_id, int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}

		$repo       = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$drive_type = (string) $repo->get( $media_id, 'drive_type' );
		$drive_id   = (int) $repo->get( $media_id, 'drive_id' );

		if ( 'user' === $drive_type || $drive_id <= 0 ) {
			// Not on a team drive at all — nothing to be a member of.
			return false;
		}

		$level = (string) apply_filters( 'mvs_document_drive_access', 'none', $drive_type, $drive_id, $user_id );

		return 'none' !== $level;
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
