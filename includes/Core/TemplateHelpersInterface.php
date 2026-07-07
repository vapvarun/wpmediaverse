<?php
/**
 * Free/Pro boundary contract for TemplateHelpers.
 *
 * Pro accesses Free's template-rendering utilities exclusively through
 * this interface, resolved via `Plugin::free_service('template_helpers')`.
 * Direct `use WPMediaVerse\Core\TemplateHelpers` imports in Pro are
 * forbidden by CI (Phase 1e guard).
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Shared template-rendering primitives reused across templates, blocks,
 * shortcodes, BuddyPress integrations, and Pro feed cards.
 */
interface TemplateHelpersInterface {

	/**
	 * Get a signed thumbnail URL for a media item at the requested size.
	 *
	 * @param int      $media_id Media ID.
	 * @param string   $size     'large' | 'medium' | 'thumbnail'.
	 * @param int      $ttl      Optional TTL override. 0 = signing service default.
	 * @param int|null $user_id  Optional viewer override; null = current user.
	 * @return string Signed URL or empty string.
	 */
	public function get_thumb_url( int $media_id, string $size = 'large', int $ttl = 0, ?int $user_id = null ): string;

	/**
	 * Get the lightbox URL respecting the admin-chosen image source setting.
	 *
	 * @param int    $media_id Media ID.
	 * @param string $file_url Legacy parameter — ignored; resolution happens from $media_id.
	 * @return string Signed URL or empty string.
	 */
	public function get_lightbox_url( int $media_id, string $file_url = '' ): string;

	/**
	 * Get the canonical media type ('image' | 'video' | 'audio' | 'document').
	 *
	 * @param int $media_id Media ID.
	 */
	public function get_media_type( int $media_id ): string;

	/**
	 * Get the public profile URL for a user.
	 *
	 * @param int $user_id User ID.
	 */
	public function get_user_profile_url( int $user_id ): string;

	/**
	 * Get the display name for a user with sensible fallbacks.
	 *
	 * @param int $user_id User ID.
	 */
	public function get_display_name( int $user_id ): string;

	/**
	 * Render a media thumbnail (image / video poster / audio card / placeholder).
	 *
	 * @param int   $media_id Media ID.
	 * @param array $args     Render arguments (size, alt, class, etc.).
	 * @return string Rendered HTML.
	 */
	public function media_thumbnail( int $media_id, array $args = array() ): string;

	/**
	 * SVG icon — play indicator overlaid on video thumbnails.
	 */
	public function icon_play_svg(): string;

	/**
	 * SVG icon — music note for audio cards.
	 */
	public function icon_music_svg(): string;

	/**
	 * SVG icon — image placeholder for empty states.
	 */
	public function icon_image_svg(): string;

	/**
	 * SVG icon — image-with-plus for upload affordances.
	 */
	public function icon_image_plus_svg(): string;

	/**
	 * Echo a grid thumbnail directly (template-friendly).
	 *
	 * @param int    $media_id Media ID.
	 * @param string $size     Size token.
	 * @param string $alt      Alt text.
	 */
	public function render_grid_thumbnail( int $media_id, string $size = 'large', string $alt = '' ): void;

	/**
	 * Echo a full grid card (thumbnail + stats + caption).
	 *
	 * @param int   $media_id Media ID.
	 * @param array $stats    Pre-fetched stats payload (avoids N+1).
	 * @param array $options  Render options.
	 */
	public function render_grid_item( int $media_id, array $stats = array(), array $options = array() ): void;

	/**
	 * Bulk-load engagement stats for a list of media IDs.
	 *
	 * @param array $media_ids Media IDs.
	 * @return array Map keyed by media_id.
	 */
	public function bulk_get_stats( array $media_ids ): array;

	/**
	 * Resolve the parent route (back-link target) for a given context.
	 *
	 * @param string $context Context slug.
	 * @param array  $args    Context-specific args.
	 * @return array|null { url, label } or null.
	 */
	public function get_parent_route( string $context, array $args = array() ): ?array;

	/**
	 * Echo the `← Back to {parent}` link for a given context.
	 *
	 * @param string $context Context slug.
	 * @param array  $args    Context-specific args.
	 */
	public function render_back_link( string $context, array $args = array() ): void;

	/**
	 * Return a canonical frontend/block empty-state panel (Coding Rule #11).
	 *
	 * @param array $args { icon, title, message, actions[], class }
	 * @return string Escaped HTML.
	 */
	public function render_block_empty_state( array $args = array() ): string;

	/**
	 * Return a canonical admin empty-state panel (Coding Rule #11).
	 *
	 * @param array $args { icon, title, message }
	 * @return string Escaped HTML.
	 */
	public function render_admin_empty_state( array $args = array() ): string;
}
