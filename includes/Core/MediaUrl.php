<?php
/**
 * Read-side URL facade — single entry point for templates, blocks, REST
 * payload builders, and integrations that need a media URL.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\VariantSpec;

/**
 * MediaUrl is the long-promised choke point referenced in `CLAUDE.md` and the
 * `TemplateHelpers.php:95` comment ("Every return path routes through MediaUrl…").
 * Before 1.5.0 the file did not exist — callers either went through
 * `TemplateHelpers::get_thumb_url()` (the canonical path) or jumped straight
 * to `SignedUrlService::generate_thumbnail()` (5 known direct-call sites in
 * explore.php / media-single.php / Shortcodes.php / CollectionController.php /
 * lock-overlay/render.php).
 *
 * Phase 3 introduces MediaUrl, has `TemplateHelpers::get_thumb_url()` delegate
 * to it, and migrates the 5 direct callers. The size-key → meta-key map (today
 * hand-written 6 times across `TemplateHelpers` and `SignedUrlService`) is also
 * unified here so the read side has one place to ask "what meta key holds the
 * webp sibling for size=large?".
 *
 * @since 1.5.0
 */
final class MediaUrl {

	/**
	 * Signed `/serve` URL for a thumb variant. Same signature as
	 * `TemplateHelpers::get_thumb_url()` so callers can switch with no
	 * behavior change.
	 *
	 * @param int      $media_id     Media ID.
	 * @param string   $size         Size key: large|medium|thumb.
	 * @param int      $ttl          Signature TTL in seconds. 0 → service default.
	 * @param int|null $user_id      Viewer user ID. null → `get_current_user_id()`.
	 *                               0 → broadcast surface (long-TTL emails / feeds).
	 * @param bool     $skip_privacy When true (default), the caller asserts that
	 *                               their query already filtered by viewer
	 *                               privacy. Forced to false when uid resolves
	 *                               to 0 (no live viewer): non-public media
	 *                               must never get an anon-bound URL because
	 *                               the serve-time gate would deny it anyway.
	 *                               Note: long-TTL broadcast emission (BP
	 *                               activity, notification emails, RSS) goes
	 *                               through `MediaRepository::get_broadcast_thumbnail_url()`
	 *                               which already passes its own
	 *                               `skip_privacy_check=false` — this
	 *                               heuristic catches direct uid=0 callers
	 *                               that don't route through that helper.
	 * @return string Signed URL, or empty string when the SignedUrlService isn't
	 *                ready (very early bootstrap) or the caller's identity is
	 *                rejected by the privacy gate.
	 */
	public static function thumb(
		int $media_id,
		string $size = 'large',
		int $ttl = 0,
		?int $user_id = null,
		bool $skip_privacy = true
	): string {
		$container = Plugin::container();
		if ( ! $container->has( 'signed_urls' ) ) {
			return '';
		}
		$uid = $user_id ?? get_current_user_id();
		if ( 0 === $uid ) {
			$skip_privacy = false;
		}
		return (string) $container->get( 'signed_urls' )->generate_thumbnail( $media_id, $uid, $size, $ttl, $skip_privacy );
	}

	/**
	 * Signed `/serve` URL for the full file (original).
	 *
	 * @param int      $media_id Media ID.
	 * @param int|null $user_id  Viewer user ID. null → `get_current_user_id()`.
	 * @return string Signed URL, or empty string when service isn't ready.
	 */
	public static function file( int $media_id, ?int $user_id = null ): string {
		$container = Plugin::container();
		if ( ! $container->has( 'signed_urls' ) ) {
			return '';
		}
		$uid = $user_id ?? get_current_user_id();
		return (string) $container->get( 'signed_urls' )->generate( $media_id, $uid );
	}

	/**
	 * The meta key under which the variant's stored URL lives. Single
	 * source of truth for the size+format → key mapping (today hand-written
	 * 6 times across `TemplateHelpers` and `SignedUrlService`).
	 *
	 * Examples:
	 *   ('large', 'primary') → 'thumb_large'
	 *   ('large', 'webp')    → 'thumb_large_webp'
	 *   ('medium', 'avif')   → 'thumb_medium_avif'
	 *
	 * @param string $size   Size key: large|medium|thumb.
	 * @param string $format `VariantSpec::FORMAT_PRIMARY` (default), `_WEBP`, or `_AVIF`.
	 */
	public static function variant_meta_key( string $size, string $format = VariantSpec::FORMAT_PRIMARY ): string {
		$base = 'thumb_' . $size;
		if ( VariantSpec::FORMAT_PRIMARY === $format ) {
			return $base;
		}
		return $base . '_' . $format;
	}

	/**
	 * The meta key under which the variant's driver-agnostic relative path
	 * lives. Mirrors `variant_meta_key()` with a `_path` suffix.
	 *
	 * Examples:
	 *   ('large', 'primary') → 'thumb_large_path'
	 *   ('large', 'webp')    → 'thumb_large_webp_path'
	 *
	 * @param string $size   Size key.
	 * @param string $format Format key.
	 */
	public static function variant_path_meta_key( string $size, string $format = VariantSpec::FORMAT_PRIMARY ): string {
		return self::variant_meta_key( $size, $format ) . '_path';
	}

	/**
	 * Where a stored variant actually lives, as a URL.
	 *
	 * Cloud path is for display; all processing happens on the local path. This
	 * is the display side of that rule for stored variants, and the single
	 * implementation behind every caller that needs one.
	 *
	 * Resolution order matches
	 * `SignedUrlService::maybe_direct_cloud_thumbnail_url()`, because the
	 * primary and its siblings must agree on where the file is:
	 *
	 *   1. `<key>_path` — the driver-agnostic relative path, source of truth
	 *      since 1.4.0 — resolved through the driver the file actually lives
	 *      on. On a CDN site this yields the CDN URL.
	 *   2. The legacy absolute URL meta, for rows that never got a `_path`:
	 *      pre-1.4.0 rows the Migrator v14 backfill has not reached, and
	 *      imported MediaPress / rtMedia / BuddyBoss records that
	 *      `MediaVariantWriter::path_meta_ok()` deliberately skips because
	 *      their `file_path` is absolute.
	 *
	 * Callers pairing this with an already-rendered primary URL must also check
	 * the two share a host — see `TemplateHelpers::get_variant_url()`. A variant
	 * on a different host than its primary is unreachable, which is what made
	 * every WebP 403 behind a CDN (Basecamp #10162798416).
	 *
	 * @since 2.3.1
	 *
	 * @param int    $media_id Media ID.
	 * @param string $meta_key Variant meta key, e.g. `thumb_large_webp` or
	 *                         `original_webp`. Use `variant_meta_key()` to build it.
	 * @return string Resolved URL, or '' when the variant is not stored.
	 */
	public static function variant_url( int $media_id, string $meta_key ): string {
		if ( $media_id <= 0 || '' === $meta_key ) {
			return '';
		}

		// `ServiceContainer::get()` throws on an unregistered key rather than
		// returning null, so there is nothing to guard against here.
		$container = Plugin::container();
		$repo      = $container->get( 'media_repository' );

		$rel_path = (string) $repo->get_raw( $media_id, $meta_key . '_path' );
		if ( '' !== $rel_path ) {
			$url = (string) $container->get( 'storage' )->get_driver_for_location( $media_id )->url( $rel_path );
			if ( '' !== $url ) {
				return $url;
			}
		}

		return (string) $repo->get_raw( $media_id, $meta_key );
	}
}
