<?php
/**
 * Single writer for every variant meta key (thumb_*, original_*).
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

/**
 * Persists a `VariantSpec` to the media meta table. Writes both:
 *
 *   - the legacy URL meta (`thumb_<size>`, `original_webp`) — kept populated
 *     for ≥2 major releases per Production Rule #1,
 *   - the driver-agnostic relative path meta (`thumb_<size>_path`,
 *     `original_webp_path`) — current source of truth since 1.4.0.
 *
 * Owns the `path_meta_ok` 3-clause guard (today duplicated at
 * `UploadService:893-895` and `:1144`).
 *
 * @since 1.5.0
 */
class MediaVariantWriter {

	/**
	 * @var MediaRepository
	 */
	private $repo;

	public function __construct( MediaRepository $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Persist a variant's URL + path meta.
	 *
	 * @param VariantSpec $spec       Variant descriptor.
	 * @param string      $stored_url Public URL to write to the legacy meta key
	 *                                (CDN URL for cloud, local URL for local).
	 */
	public function record( VariantSpec $spec, string $stored_url ): void {
		$this->repo->set( $spec->media_id(), $spec->meta_key(), $stored_url );

		if ( $this->path_meta_ok( $spec->media_id() ) ) {
			$this->repo->set( $spec->media_id(), $spec->path_meta_key(), $spec->rel_path() );
		}
	}

	/**
	 * Clear a variant's meta keys (used on cloud-store failure for siblings —
	 * the read side falls back through the picture-sources chain).
	 *
	 * @param VariantSpec $spec Variant descriptor.
	 */
	public function clear( VariantSpec $spec ): void {
		$this->repo->set( $spec->media_id(), $spec->meta_key(), '' );
		$this->repo->set( $spec->media_id(), $spec->path_meta_key(), '' );
	}

	/**
	 * Whether to persist `_path` meta for this media.
	 *
	 * Imported records (MediaPress / rtMedia / BuddyBoss) may store ABSOLUTE
	 * paths in `file_path`; for those, persisting `_path` would yield bogus
	 * values like `/var/www/.../foo-1024.jpg` that the read side cannot
	 * resolve under `wpmediaverse/`. Falling through to the legacy URL
	 * branch keeps imported records working. Mirrors the guard at
	 * `UploadService::generate_thumbnails` (post-fix).
	 *
	 * @param int $media_id Media ID.
	 * @return bool True when the media's `file_path` is a clean relative shape.
	 */
	public function path_meta_ok( int $media_id ): bool {
		$file_path = (string) $this->repo->get_raw( $media_id, 'file_path' );
		if ( '' === $file_path ) {
			return false;
		}
		if ( 0 === strpos( $file_path, '/' ) ) {
			return false;
		}
		if ( preg_match( '#^[A-Za-z]:[\\\\/]#', $file_path ) ) {
			return false;
		}
		return true;
	}
}
