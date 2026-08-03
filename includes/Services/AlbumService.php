<?php
/**
 * Album service.
 *
 * Manages album items, ordering, and cover images.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;


/**
 * Manages album item membership, ordering, and metadata.
 */
class AlbumService {

	/**
	 * Create a new album.
	 *
	 * Wraps the `mvs_album` CPT insertion + privacy / album_type / group
	 * association meta writes that REST and template callers used to repeat
	 * inline. Single canonical entry point so seeders, WP-CLI, and any
	 * future caller produce identical state to the REST flow.
	 *
	 * @since 1.2.0
	 *
	 * @param int   $author_id Album author user ID. Must be > 0.
	 * @param array $args      {
	 *     Album attributes.
	 *
	 *     @type string $title       Required. Album title.
	 *     @type string $description Optional. Album description (kses_post-ed).
	 *     @type string $privacy     Optional. 'public' (default) | 'members' | 'private' | 'group'.
	 *     @type string $album_type  Optional. Free-form album category. Default 'default'.
	 *     @type int    $group_id    Optional. BP group association. When non-zero
	 *                               and the user is a group member, privacy is
	 *                               forced to 'group' and the group_id is
	 *                               written to both mvs_media_meta AND
	 *                               post_meta `_mvs_group_id` (BP group tab
	 *                               listings WP_Query against post_meta).
	 *     @type array  $categories  Optional. mvs_category term IDs.
	 * }
	 * @return int|\WP_Error Album post ID on success, WP_Error on validation
	 *                      failure or DB error.
	 */
	public function create( int $author_id, array $args ) {
		$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '';
		if ( '' === $title ) {
			return new \WP_Error( 'mvs_missing_title', __( 'Album title is required.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$album_id = wp_insert_post(
			array(
				'post_type'    => 'mvs_album',
				'post_title'   => $title,
				'post_content' => isset( $args['description'] ) ? wp_kses_post( $args['description'] ) : '',
				'post_status'  => 'publish',
				'post_author'  => $author_id,
			),
			true
		);

		if ( is_wp_error( $album_id ) ) {
			return $album_id;
		}

		$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

		$privacy    = isset( $args['privacy'] ) ? sanitize_text_field( $args['privacy'] ) : 'public';
		$album_type = isset( $args['album_type'] ) ? sanitize_text_field( $args['album_type'] ) : 'default';

		// Create the index row with a unique slug in the first write. Calling
		// set() per-key would INSERT a row with slug left at its DEFAULT '' —
		// and mvs_media_index has a UNIQUE KEY on slug, so the first album would
		// take slug='' and every subsequent album would collide on that key and
		// silently fail, losing privacy/album_type (read back as public/default).
		$repo->set_many(
			$album_id,
			array(
				'slug'       => $repo->generate_unique_slug( $title ),
				'privacy'    => $privacy,
				'album_type' => $album_type,
			)
		);

		// Group association — only honour when the author is actually a member.
		$group_id = isset( $args['group_id'] ) ? absint( $args['group_id'] ) : 0;
		if ( $group_id > 0 && function_exists( 'groups_is_user_member' ) && groups_is_user_member( $author_id, $group_id ) ) {
			$repo->set( $album_id, 'privacy', 'group' );
			$repo->set( $album_id, 'group_id', $group_id );
			update_post_meta( $album_id, '_mvs_group_id', $group_id );
		}

		// Categories (mvs_category taxonomy on the mvs_album CPT).
		if ( isset( $args['categories'] ) && is_array( $args['categories'] ) ) {
			wp_set_object_terms( $album_id, array_map( 'absint', array_filter( $args['categories'] ) ), 'mvs_category' );
		}

		return (int) $album_id;
	}

	/**
	 * Update an album's own post fields.
	 *
	 * The counterpart to create(). Until this existed the only way to change an
	 * album's title or description was AlbumController::update_item() building a
	 * wp_update_post() array inline, so any other caller -- our own code or an
	 * integration such as BuddyNext, which renders albums as a community surface --
	 * had to reach past this service and write the post itself. One of them wrote
	 * the description to post_excerpt, which nothing here ever reads, so the value
	 * was stored and never shown.
	 *
	 * `description` maps to post_content. That is where create() puts it and where
	 * the REST reader takes it from; the pairing is the contract, and it belongs in
	 * one place rather than being restated at every call site.
	 *
	 * Only keys PRESENT in $args are written, so a caller updating the title alone
	 * cannot blank a description it never sent.
	 *
	 * @param int                                         $album_id Album post ID.
	 * @param array{title?: string, description?: string} $args     Fields to update.
	 * @return true|\WP_Error True on success (including a no-op), WP_Error otherwise.
	 */
	public function update( int $album_id, array $args ) {
		$post = get_post( $album_id );
		if ( ! $post || 'mvs_album' !== $post->post_type ) {
			return new \WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$update = array( 'ID' => $album_id );

		// NOTE: create() rejects an empty title, this does not. That asymmetry is
		// pre-existing behaviour in the REST update path and is preserved here on
		// purpose -- tightening it is a separate change, not a side effect of
		// moving the write into the service.
		if ( array_key_exists( 'title', $args ) ) {
			$update['post_title'] = sanitize_text_field( (string) $args['title'] );
		}

		if ( array_key_exists( 'description', $args ) ) {
			$update['post_content'] = wp_kses_post( (string) $args['description'] );
		}

		if ( 1 === count( $update ) ) {
			return true;
		}

		$result = wp_update_post( $update, true );

		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Get items for an album, ordered by position.
	 *
	 * @param int $album_id Album post ID.
	 * @return array Array of items with media_id and position.
	 */
	public function get_items( int $album_id ): array {
		global $wpdb;

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// Trashed media is excluded here rather than by each caller. An
				// album item row survives its media being trashed, so without this
				// join a deleted photo stayed in every album it belonged to - as a
				// broken tile, and counted in get_item_count() below.
				"SELECT ai.media_id, ai.position
				FROM {$wpdb->prefix}mvs_album_items ai
				INNER JOIN {$wpdb->prefix}mvs_media_index idx ON idx.media_id = ai.media_id
				WHERE ai.album_id = %d AND idx.status <> 'trash'
				ORDER BY ai.position ASC",
				$album_id
			),
			ARRAY_A
		);
	}

	/**
	 * Get full media rows for an album, in album order, filtered by status.
	 *
	 * Convenience wrapper around `get_items()` + `MediaRepository::get_batch()`
	 * that templates can call directly without doing the join themselves.
	 *
	 * @since 1.3.0
	 *
	 * @param int    $album_id Album post ID.
	 * @param string $status   Status filter (default 'publish'). Pass '' to skip filtering.
	 * @return array<int, array> Numerically-indexed list of media rows in album order.
	 */
	public function get_items_with_data( int $album_id, string $status = 'publish' ): array {
		$items = $this->get_items( $album_id );
		if ( empty( $items ) ) {
			return array();
		}

		$media_ids = array_column( $items, 'media_id' );
		$rows      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_batch( $media_ids );

		$ordered = array();
		foreach ( $items as $item ) {
			$mid = (int) $item['media_id'];
			if ( ! isset( $rows[ $mid ] ) ) {
				continue;
			}
			if ( '' !== $status && ( $rows[ $mid ]['status'] ?? '' ) !== $status ) {
				continue;
			}
			$ordered[] = $rows[ $mid ];
		}
		return $ordered;
	}

	/**
	 * Get the item count for an album.
	 *
	 * @param int $album_id Album post ID.
	 * @return int
	 */
	public function get_item_count( int $album_id ): int {
		global $wpdb;

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				// Must apply the SAME filter as get_items(), or an album reports
				// twelve items and renders nine.
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}mvs_album_items ai
				INNER JOIN {$wpdb->prefix}mvs_media_index idx ON idx.media_id = ai.media_id
				WHERE ai.album_id = %d AND idx.status <> 'trash'",
				$album_id
			)
		);
	}

	/**
	 * Add media items to an album.
	 *
	 * Validates post type and enforces audio-only for playlist albums.
	 *
	 * @param int   $album_id  Album post ID.
	 * @param int[] $media_ids Array of media post IDs.
	 * @return int Number of items successfully added.
	 */
	public function add_items( int $album_id, array $media_ids ): int {
		global $wpdb;

		$is_playlist = 'playlist' === \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $album_id, 'album_type' );

		$max_pos = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(position) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);

		$added = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;

			if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
				continue;
			}

			// Playlist albums only accept audio media.
			if ( $is_playlist ) {
				$file_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
				if ( $file_type && 0 !== strpos( $file_type, 'audio/' ) ) {
					continue;
				}
			}

			++$max_pos;
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array(
					'album_id' => $album_id,
					'media_id' => $media_id,
					'position' => $max_pos,
					'added_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%s' )
			);

			if ( false !== $result ) {
				++$added;
			}
		}

		// Store album association on each media item.
		if ( $added > 0 ) {
			$repo = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );

			/**
			 * Filters whether media added to an album inherits the album's privacy.
			 *
			 * A member who creates a Private album and uploads into it expects the
			 * contents to be private. Before 2.3.0 nothing carried the album's
			 * privacy down: the item kept its own value, which for the album-page
			 * and BuddyPress-tab uploaders was the site default (usually public),
			 * because neither of those uploaders sent a privacy field at all. The
			 * album showed a "Private" badge while its photos sat in the public
			 * Explore feed (Basecamp #10149366902).
			 *
			 * Clamping is one-way and only ever tightens: an item that is already
			 * more restrictive than the album keeps its own setting.
			 *
			 * Return false to keep album and item privacy fully independent, which
			 * is how Free behaved before 2.3.0 (Production Rule 3).
			 *
			 * @since 2.3.0
			 *
			 * @param bool  $inherit   Whether to clamp. Default true.
			 * @param int   $album_id  Album ID.
			 * @param int[] $media_ids Media IDs being added.
			 */
			$inherit       = (bool) apply_filters( 'mvs_album_inherit_privacy', true, $album_id, $media_ids );
			$album_privacy = $inherit ? (string) $repo->get( $album_id, 'privacy' ) : '';

			foreach ( $media_ids as $mid ) {
				$mid = (int) $mid;
				$repo->set( $mid, 'album_id', $album_id );

				if ( '' === $album_privacy || 'public' === $album_privacy ) {
					continue;
				}

				$media_privacy = (string) $repo->get( $mid, 'privacy' );
				if ( '' === $media_privacy ) {
					$media_privacy = 'public';
				}

				$effective = PrivacyService::more_restrictive( $album_privacy, $media_privacy );
				if ( $effective !== $media_privacy ) {
					$repo->set( $mid, 'privacy', $effective );

					/**
					 * Fires when an item's privacy is tightened by its album.
					 *
					 * @since 2.3.0
					 *
					 * @param int    $media_id   Media ID.
					 * @param string $from       Previous privacy slug.
					 * @param string $to         New privacy slug.
					 * @param int    $album_id   Album that caused the clamp.
					 */
					do_action( 'mvs_media_privacy_clamped_by_album', $mid, $media_privacy, $effective, $album_id );
				}
			}

			// The actor may be a co-collaborator, not the album owner — keep it
			// distinct from the author lookup so gamification adapters can award
			// the right user.
			$actor_id = get_current_user_id();

			/**
			 * Fires after media items are added to an album.
			 *
			 * @since 1.1.0
			 * @since 1.2.3 Added $actor_id as positional arg 2 — was previously
			 *              ($album_id, $media_ids, $added). The in-tree
			 *              ActivitySyncIntegration listener was updated in the
			 *              same change.
			 *
			 * @param int   $album_id  Album post ID.
			 * @param int   $actor_id  User who added the items (may differ from
			 *                         album owner on co-collaborated albums).
			 * @param array $media_ids Media post IDs that were added.
			 * @param int   $added     Number of items successfully added.
			 */
			do_action( 'mvs_album_items_added', $album_id, $actor_id, $media_ids, $added );
		}

		return $added;
	}

	/**
	 * Remove a media item from an album.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media post ID.
	 * @return bool True if removed, false if not found.
	 */
	public function remove_item( int $album_id, int $media_id ): bool {
		global $wpdb;

		$deleted = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_album_items',
			array(
				'album_id' => $album_id,
				'media_id' => $media_id,
			),
			array( '%d', '%d' )
		);

		return $deleted > 0;
	}

	/**
	 * Reorder album items.
	 *
	 * @param int   $album_id Album post ID.
	 * @param int[] $order    Ordered array of media IDs.
	 */
	public function reorder( int $album_id, array $order ): void {
		global $wpdb;

		foreach ( $order as $position => $media_id ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array( 'position' => (int) $position ),
				array(
					'album_id' => $album_id,
					'media_id' => (int) $media_id,
				),
				array( '%d' ),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * Post meta key storing the user-chosen cover media ID for an album.
	 * When unset, get_cover_url falls back to the first image item.
	 */
	const COVER_META_KEY = '_mvs_album_cover_media_id';

	/**
	 * Set the cover image for an album.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media post ID to use as cover. Pass 0 to clear.
	 * @return true|\WP_Error True on success, WP_Error with surfaced reason otherwise.
	 */
	public function set_cover( int $album_id, int $media_id ) {
		if ( $album_id <= 0 || 'mvs_album' !== get_post_type( $album_id ) ) {
			return new \WP_Error( 'mvs_album_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// 0 clears the pinned cover so get_cover_url falls back to first-image.
		if ( 0 === $media_id ) {
			delete_post_meta( $album_id, self::COVER_META_KEY );
			return true;
		}

		// Reject cover requests for media that doesn't exist at all.
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new \WP_Error(
				'mvs_media_not_found',
				__( 'Media item not found.', 'wpmediaverse' ),
				array( 'status' => 404 )
			);
		}

		// Only image-type media makes sense as a cover. Validate before any mutation.
		$file_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
		if ( ! is_string( $file_type ) || 0 !== strpos( $file_type, 'image/' ) ) {
			return new \WP_Error(
				'mvs_cover_not_image',
				__( 'Cover image must be an image (not video or audio).', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		// If the media is not yet in this album, add it atomically. This removes
		// the client-side ordering dance (add_items before /cover) that caused
		// FAB and dashboard edit flows to 400 when choosing a new cover.
		if ( ! $this->is_item_in_album( $album_id, $media_id ) ) {
			$this->add_items( $album_id, array( $media_id ) );
			if ( ! $this->is_item_in_album( $album_id, $media_id ) ) {
				return new \WP_Error(
					'mvs_cover_add_failed',
					__( 'Could not add the selected media to this album (the album type may not accept it).', 'wpmediaverse' ),
					array( 'status' => 409 )
				);
			}
		}

		update_post_meta( $album_id, self::COVER_META_KEY, $media_id );

		/**
		 * Fires after an album's cover image is explicitly set.
		 *
		 * @param int $album_id Album post ID.
		 * @param int $media_id Media ID now pinned as cover.
		 */
		do_action( 'mvs_album_cover_set', $album_id, $media_id );

		return true;
	}

	/**
	 * Get the resolved cover media ID for an album (pinned, falling back to first image).
	 *
	 * Use this when the caller wants the cover URL regardless of whether it
	 * was explicitly pinned (e.g. signed-URL routing for any album cover). For
	 * the explicit pinned-only value, use get_cover_media_id() instead.
	 *
	 * @param int $album_id Album post ID.
	 * @return int|null Media ID or null.
	 */
	public function get_resolved_cover_media_id( int $album_id ): ?int {
		return $this->get_first_image_item( $album_id );
	}

	/**
	 * Get the cover image URL for an album.
	 *
	 * Resolution order: pinned cover → first image item → first item of any
	 * type → null. Stale pinned meta is cleaned on read.
	 *
	 * @param int    $album_id Album post ID.
	 * @param string $size     Image size key (e.g. 'large', 'thumbnail').
	 * @return string|null Cover URL, or null when the album has no renderable media.
	 */
	public function get_cover_url( int $album_id, string $size = 'large' ): ?string {
		$pinned_id = (int) get_post_meta( $album_id, self::COVER_META_KEY, true );
		if ( $pinned_id && $this->is_item_in_album( $album_id, $pinned_id ) ) {
			$url = $this->resolve_media_image_url( $pinned_id, $size );
			if ( $url ) {
				return $url;
			}
		}

		if ( $pinned_id ) {
			delete_post_meta( $album_id, self::COVER_META_KEY );
		}

		$first_media_id = $this->get_first_image_item( $album_id );
		if ( $first_media_id ) {
			return $this->resolve_media_image_url( $first_media_id, $size );
		}

		return null;
	}

	/**
	 * Get the pinned cover media ID, or 0 if none/invalid.
	 *
	 * Returns the explicit pin only — never falls back to the first item.
	 * Use this when callers need to distinguish between an explicitly pinned
	 * cover and an auto-selected one (e.g. the edit modal highlighting the
	 * current cover thumbnail, the REST `cover_media_id` field consumed by
	 * the dashboard block). For the resolved cover (pinned or first item),
	 * use get_resolved_cover_media_id() instead.
	 *
	 * @param int $album_id Album post ID.
	 * @return int Media ID of the pinned cover, or 0 when no valid pin exists.
	 */
	public function get_cover_media_id( int $album_id ): int {
		$pinned_id = (int) get_post_meta( $album_id, self::COVER_META_KEY, true );
		if ( $pinned_id && $this->is_item_in_album( $album_id, $pinned_id ) ) {
			return $pinned_id;
		}
		return 0;
	}

	/**
	 * Resolve a single media ID to its thumbnail URL, with an image-file fallback.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     Image size.
	 * @return string|null
	 */
	private function resolve_media_image_url( int $media_id, string $size ): ?string {
		$thumb = \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_thumb_url( $media_id, $size );
		if ( $thumb ) {
			return $thumb;
		}

		// Fallback for image-type media: signed file URL passes the
		// .htaccess gate. Previously returned a raw URL that 403s.
		$file_type = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_type' );
		if ( is_string( $file_type ) && 0 === strpos( $file_type, 'image/' ) ) {
			$signed = (string) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_url' );
			if ( '' !== $signed ) {
				return $signed;
			}
		}

		return null;
	}

	/**
	 * Check whether a given media ID is part of an album's items table.
	 *
	 * @param int $album_id Album post ID.
	 * @param int $media_id Media ID.
	 */
	private function is_item_in_album( int $album_id, int $media_id ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d AND media_id = %d",
				$album_id,
				$media_id
			)
		) > 0;
	}

	/**
	 * Get the first image-type media item in an album.
	 *
	 * Falls back to the first item of any type if no images exist.
	 *
	 * @param int $album_id Album post ID.
	 * @return int|null Media post ID or null.
	 */
	private function get_first_image_item( int $album_id ): ?int {
		global $wpdb;

		$index_table = $wpdb->prefix . 'mvs_media_index';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$media_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT ai.media_id
				FROM {$wpdb->prefix}mvs_album_items ai
				INNER JOIN {$index_table} idx ON idx.media_id = ai.media_id
				WHERE ai.album_id = %d AND idx.file_type LIKE %s AND idx.status <> 'trash'
				ORDER BY ai.position ASC
				LIMIT 1",
				$album_id,
				'image/%'
			)
		);

		// If no image item, fall back to just the first item (for video thumbnails etc).
		if ( ! $media_id ) {
			$media_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ai.media_id
					FROM {$wpdb->prefix}mvs_album_items ai
					INNER JOIN {$wpdb->prefix}mvs_media_index idx ON idx.media_id = ai.media_id
					WHERE ai.album_id = %d AND idx.status <> 'trash'
					ORDER BY ai.position ASC
					LIMIT 1",
					$album_id
				)
			);
		}
		// phpcs:enable

		return $media_id ? (int) $media_id : null;
	}

	/**
	 * The albums a media item belongs to.
	 *
	 * Exists so a consumer can tell somebody what deleting a photo is about to
	 * affect. Album membership is a fact about this join table, so answering it
	 * here keeps callers from querying mvs_album_items themselves.
	 *
	 * @param int $media_id Media id.
	 * @return int[] Album post ids, in album order.
	 */
	public function albums_for_media( int $media_id ): array {
		global $wpdb;

		if ( $media_id <= 0 ) {
			return array();
		}

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT album_id FROM {$wpdb->prefix}mvs_album_items WHERE media_id = %d ORDER BY album_id ASC",
				$media_id
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Delete all items for an album (cascade).
	 *
	 * @param int $album_id Album post ID.
	 * @return int Number of rows deleted.
	 */
	public function delete_all_items( int $album_id ): int {
		global $wpdb;

		return (int) $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_album_items',
			array( 'album_id' => $album_id ),
			array( '%d' )
		);
	}
}
