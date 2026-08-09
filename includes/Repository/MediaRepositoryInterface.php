<?php
/**
 * Free/Pro boundary contract for MediaRepository.
 *
 * Pro accesses Free's media data layer exclusively through this interface,
 * resolved via `Plugin::free_service('media_repository')`. Direct
 * `use WPMediaVerse\Repository\MediaRepository` imports in Pro are
 * forbidden by CI (Phase 1e guard).
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Centralised data-access contract for media items.
 *
 * Mirrors the public surface of {@see MediaRepository}. Every Free + Pro
 * caller that reads or writes media via this layer depends on this
 * interface — never on the concrete class — so the implementation can
 * evolve (caching layer, repository pattern, sharding) without touching
 * call sites.
 */
interface MediaRepositoryInterface {

	/**
	 * Get a single field for a media item.
	 *
	 * URL fields (`file_url`, `thumb_large`/`thumb_medium`/`thumb_thumb`) are
	 * always returned as signed URLs. Internal callers that need the raw stored
	 * value MUST use `get_raw()`.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name.
	 * @return mixed Value or null when not found. Signed string for URL fields.
	 */
	public function get( int $media_id, string $key );

	/**
	 * Get the raw stored value of a field — bypasses URL signing.
	 *
	 * INTERNAL USE ONLY (signing service, filesystem readers, find_by_url).
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name.
	 * @return mixed Raw value or null if not found.
	 */
	public function get_raw( int $media_id, string $key );

	/**
	 * Reverse-lookup a media_id from a stored file_url.
	 *
	 * @param string $url Raw URL.
	 * @return int Media ID, or 0 when the URL doesn't match any indexed media.
	 */
	public function find_by_url( string $url ): int;

	/**
	 * Long-lived signed URL for "broadcast" surfaces (BP activity feed,
	 * notification emails, embeds).
	 *
	 * @param int $media_id Media ID.
	 * @return string Signed URL valid for ~1 year, or empty string.
	 */
	public function get_broadcast_url( int $media_id ): string;

	/**
	 * Long-lived signed thumbnail URL for "broadcast" surfaces.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     'thumb_large' | 'thumb_medium' | 'thumb_thumb' or SignedUrlService size.
	 * @return string Signed URL valid for ~1 year, or empty string.
	 */
	public function get_broadcast_thumbnail_url( int $media_id, string $size = 'thumb_large' ): string;

	/**
	 * Viewer-aware signed thumbnail URL.
	 *
	 * Returns a signed thumbnail URL when the given viewer may see the media
	 * (owner / admin / permitted audience), else ''. Unlike
	 * get_broadcast_thumbnail_url() (anonymous, '' for any non-public media),
	 * this authorizes against a specific viewer so app-layer consumers can show
	 * the real poster for Members/Friends/Only-Me media to allowed viewers.
	 * Privacy is enforced at sign time and re-verified by /serve.
	 *
	 * @since 1.8.1
	 *
	 * @param int      $media_id  Media ID.
	 * @param string   $size      'thumb_large' | 'thumb_medium' | 'thumb_thumb' or SignedUrlService size.
	 * @param int|null $viewer_id Viewer to authorize against. Null = current user.
	 * @return string Signed URL when the viewer may view the media, else ''.
	 */
	public function get_thumbnail_url_for_viewer( int $media_id, string $size = 'thumb_large', ?int $viewer_id = null ): string;

	/**
	 * Viewer-aware signed URL for the FULL / original media file.
	 *
	 * The full-file analogue of get_thumbnail_url_for_viewer(): returns a signed
	 * `/serve` URL for the original when the viewer may see the media, else ''.
	 * Privacy is enforced at sign time and re-verified by /serve per request.
	 * Part of the Free<->Pro<->BuddyNext contract: BuddyNext's private-media
	 * lightbox resolves the full file strictly through this interface method
	 * (BN 1.0.7 MediaUrlResolver::descriptor()), so it must live on the contract,
	 * not just the concrete class.
	 *
	 * @since 2.0.0
	 *
	 * @param int      $media_id  Media ID.
	 * @param int|null $viewer_id Viewer to authorize against. Null = current user.
	 * @return string Signed full-file URL when the viewer may view the media, else ''.
	 */
	public function get_url_for_viewer( int $media_id, ?int $viewer_id = null ): string;

	/**
	 * Resolve the absolute filesystem path for a media file.
	 *
	 * INTERNAL USE ONLY. Frontend / template / REST callers MUST use
	 * `get($id, 'file_url')` so URLs flow through SignedUrlService.
	 *
	 * @param int $media_id Media ID.
	 * @return string|null Absolute path, or null when no valid file is reachable.
	 */
	public function get_filesystem_path( int $media_id ): ?string;

	/**
	 * Set a single field for a media item.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name.
	 * @param mixed  $value    Value to store.
	 */
	public function set( int $media_id, string $key, $value ): void;

	/**
	 * Set multiple fields atomically.
	 *
	 * @param int   $media_id Media ID.
	 * @param array $data     Field => value map.
	 */
	public function set_many( int $media_id, array $data ): void;

	/**
	 * Delete a single field.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $key      Field name.
	 */
	public function delete( int $media_id, string $key ): void;

	/**
	 * Get every field (index columns + meta) for a media item.
	 *
	 * @param int $media_id Media ID.
	 * @return array Row data; empty array when not found.
	 */
	public function get_all( int $media_id ): array;

	/**
	 * Whether a media row exists.
	 *
	 * @param int $media_id Media ID.
	 * @return bool
	 */
	public function exists( int $media_id ): bool;

	/**
	 * Look up a media row by slug.
	 *
	 * @param string $slug   Slug.
	 * @param string $status Status filter (default 'publish').
	 * @return array|null Row data or null.
	 */
	public function get_by_slug( string $slug, string $status = 'publish' ): ?array;

	/**
	 * Bulk-load media rows.
	 *
	 * @param array $media_ids Media IDs.
	 * @return array Map of media_id → row data.
	 */
	public function get_batch( array $media_ids ): array;

	/**
	 * Look up a media_id by an arbitrary meta key/value pair.
	 *
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @return int|null Media ID or null.
	 */
	public function find_by_meta( string $meta_key, string $meta_value ): ?int;

	/**
	 * Count published media items.
	 */
	public function count_published(): int;

	/**
	 * Count media items authored by a user.
	 *
	 * @param int    $user_id User ID.
	 * @param string $status  Status filter.
	 */
	public function count_by_author( int $user_id, string $status = 'publish' ): int;

	/**
	 * Count media items in a moderation status.
	 *
	 * @param string $status Moderation status.
	 */
	public function count_by_moderation( string $status ): int;

	/**
	 * Get moderation counts grouped by status.
	 *
	 * @return array<string,int>
	 */
	public function get_moderation_counts(): array;

	/**
	 * Count media items in a BP group.
	 *
	 * @param string $group_id Group ID (string for compatibility).
	 */
	public function count_by_group( string $group_id ): int;

	/**
	 * Get aggregated statistics for a media item.
	 *
	 * @param int $media_id Media ID.
	 */
	public function get_stats( int $media_id ): ?array;

	/**
	 * Get aggregated statistics for a user across all their media.
	 *
	 * @param int $user_id User ID.
	 */
	public function get_user_stats( int $user_id ): array;

	/**
	 * Initialise the stats row for a new media item.
	 *
	 * @param int $media_id Media ID.
	 */
	public function init_stats( int $media_id ): bool;

	/**
	 * Increment a stats column.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $column   Column name.
	 */
	public function increment_stat( int $media_id, string $column ): bool;

	/**
	 * Set a stats column to an absolute value.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $column   Column name.
	 * @param int    $value    New value.
	 */
	public function set_stat( int $media_id, string $column, int $value ): bool;

	/**
	 * Record a per-viewer event row.
	 *
	 * @param int    $media_id   Media ID.
	 * @param int    $user_id    User ID.
	 * @param string $ip_hash    Hashed IP for de-duplication.
	 * @param string $event_type 'view' | 'play' | 'download' | …
	 */
	public function record_event( int $media_id, int $user_id, string $ip_hash, string $event_type = 'view' ): void;

	/**
	 * Prune events older than $days_old.
	 *
	 * @param int $days_old Retention days.
	 * @return int Rows deleted.
	 */
	public function prune_events( int $days_old = 90 ): int;

	/**
	 * Get a media item's author ID.
	 *
	 * @param int $media_id Media ID.
	 */
	public function get_author( int $media_id ): int;

	/**
	 * Get the public permalink for a media item.
	 *
	 * @param int $media_id Media ID.
	 */
	public function get_permalink( int $media_id ): string;

	/**
	 * Insert a media row.
	 *
	 * @param array $data Field map.
	 * @return int|false Inserted ID or false.
	 */
	public function insert( array $data );

	/**
	 * Generate a unique slug from a title.
	 *
	 * @param string $title            Title.
	 * @param int    $exclude_media_id Optional. media_id to exclude from
	 *                                 the collision check (use when
	 *                                 re-slugging an existing media so its
	 *                                 own row doesn't trigger a suffix).
	 */
	public function generate_unique_slug( string $title, int $exclude_media_id = 0 ): string;

	/**
	 * Hard-delete a media row plus its meta + stats.
	 *
	 * @param int $media_id Media ID.
	 */
	public function delete_all( int $media_id ): void;

	/**
	 * Move a media row to the trash status.
	 *
	 * @param int $media_id Media ID.
	 */
	public function trash( int $media_id ): bool;

	/**
	 * Restore a media row from trash.
	 *
	 * @param int $media_id Media ID.
	 */
	public function restore( int $media_id ): bool;

	/**
	 * Cascading delete (row + meta + stats + downstream tables).
	 *
	 * @param int $media_id Media ID.
	 */
	public function delete_cascade( int $media_id ): bool;

	/**
	 * Purge the mvs_media_index + mvs_media_meta rows for an id and drop its row
	 * cache. Targeted row/meta cleanup for album/collection privacy rows on
	 * delete (they never touch the downstream tables delete_cascade() clears).
	 *
	 * @param int $media_id The mvs_media_index PK.
	 */
	public function purge_index_record( int $media_id ): void;

	/**
	 * Tighten the privacy of every document sitting in the given folders.
	 *
	 * On the boundary interface because Pro's FolderService drives the T2
	 * cascade and must reach this WITHOUT writing `mvs_media_index` itself —
	 * Free owns that table (architecture invariant 6).
	 *
	 * Tightening only: rows already at or beyond the target are left alone, so
	 * an explicit `private` on one file outranks its container.
	 *
	 * @since 2.4.0
	 *
	 * @param int[]  $folder_ids Folder ids whose documents are affected.
	 * @param string $privacy    Target privacy.
	 * @param int    $limit      0 for no limit; above 0 caps the update for batching.
	 * @return int Rows changed.
	 */
	public function tighten_document_privacy_in_folders( array $folder_ids, string $privacy, int $limit = 0 ): int;

	/**
	 * How many documents a privacy tightening would change.
	 *
	 * Backs the confirmation copy, counted the same way the UPDATE selects so the
	 * number a member is shown is the number that moves.
	 *
	 * @since 2.4.0
	 *
	 * @param int[]  $folder_ids Folder ids.
	 * @param string $privacy    Target privacy.
	 * @return int
	 */
	public function count_documents_to_tighten( array $folder_ids, string $privacy ): int;

	/**
	 * How closed a privacy value is. Higher is more restrictive; -1 if unknown.
	 *
	 * @since 2.4.0
	 *
	 * @param string $privacy Privacy value.
	 * @return int
	 */
	public function privacy_level( string $privacy ): int;

	/**
	 * Every privacy value strictly looser than the given one.
	 *
	 * Exposed so a caller can tighten its OWN table with the same definition of
	 * "looser" this repository uses. Without it Pro would have to re-implement
	 * the ordering, and two copies of a privacy comparison drifting apart is a
	 * security bug rather than an inconsistency.
	 *
	 * @since 2.4.0
	 *
	 * @param string $privacy Privacy value.
	 * @return string[] Empty when nothing is looser, or the value is unknown.
	 */
	public function privacy_levels_looser_than( string $privacy ): array;

	/**
	 * Documents in one drive, optionally inside one folder.
	 *
	 * On the boundary interface because Pro's document REST surface lists a drive
	 * and must not query `mvs_media_index` itself (architecture invariant 6).
	 *
	 * Applies NO document permissions — the caller resolves a whole page through
	 * PermissionService in two queries, which is the only way that stays within
	 * budget. Filtering per row here would reintroduce the N+1 the design avoids.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args author, folder_id, per_page, page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function drive_documents( array $args = array() ): array;

	/**
	 * Public documents, for the public document listing page.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args per_page, page, doc_type.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function public_documents( array $args = array() ): array;

	/**
	 * Documents shared WITH a viewer, by direct grant.
	 *
	 * On the interface because it JOINS `mvs_media_index`, which Free owns. Pro's
	 * `/me/shared` route built this query itself at first, assigning the table to
	 * a variable — the documented grep blind spot, which survived both the
	 * architecture check and the duplication gate.
	 *
	 * @since 2.4.0
	 *
	 * @param array $args user_id, roles, per_page, page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, pages: int}
	 */
	public function documents_shared_with( array $args = array() ): array;
}
