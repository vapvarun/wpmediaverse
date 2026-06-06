<?php
/**
 * Storage service — driver factory.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Storage service with configurable driver pattern.
 */
class StorageService {

	/**
	 * Cached driver instance.
	 *
	 * @var StorageDriverInterface|null
	 */
	private $driver;

	/**
	 * Get the configured storage driver.
	 *
	 * @return StorageDriverInterface
	 */
	public function get_driver(): StorageDriverInterface {
		if ( null === $this->driver ) {
			$driver_name = get_option( 'mvs_storage_driver', 'local' );

			/**
			 * Filter the storage driver instance.
			 *
			 * @param StorageDriverInterface|null $driver      Current driver.
			 * @param string                      $driver_name Configured driver name.
			 */
			$this->driver = apply_filters( 'mvs_storage_driver', null, $driver_name );

			if ( ! $this->driver instanceof StorageDriverInterface ) {
				$this->driver = new LocalDriver();
			}
		}

		return $this->driver;
	}

	/**
	 * The local-disk driver, regardless of the active driver setting.
	 *
	 * @return StorageDriverInterface
	 */
	public function get_local_driver(): StorageDriverInterface {
		return new LocalDriver();
	}

	/**
	 * Delete a relative path from BOTH local disk and the active cloud driver.
	 *
	 * Location-based storage routes public bytes to cloud and private bytes to
	 * local, and migrations move files between them, so a delete that only hit
	 * the active driver could strand the file on the other tier. Deleting from
	 * both (each driver's delete() no-ops when the file is absent) guarantees
	 * the bytes are reclaimed wherever they actually live.
	 *
	 * Drivers report failure by returning false (the interface never throws),
	 * so the result is returned to the caller — StorageCleanupService re-queues
	 * failed paths for retry instead of silently orphaning them (Basecamp
	 * #9966546394: a swallowed cloud failure left the file on R2 forever).
	 *
	 * @since 1.6.0
	 *
	 * @param string $rel_path Driver-agnostic relative path.
	 * @return bool True when every tier deleted (or had nothing to delete).
	 */
	public function delete_everywhere( string $rel_path ): bool {
		$rel_path = ltrim( (string) $rel_path, '/' );
		if ( '' === $rel_path ) {
			return true;
		}

		$ok = $this->get_local_driver()->delete( $rel_path );

		$active = $this->get_driver();
		if ( ! $active instanceof LocalDriver ) {
			$ok = $active->delete( $rel_path ) && $ok;
		}

		return $ok;
	}

	/**
	 * The driver that should STORE a media of the given privacy.
	 *
	 * Private/restricted media never leaves the local server — only public
	 * media is eligible for cloud storage. This is the single enforcement point
	 * for that policy: every write path (upload, thumbnails, variant publish)
	 * resolves its driver here instead of calling get_driver() directly, so a
	 * private upload can never land on a public cloud bucket. Mirrors the
	 * public-only filter already used by the migration flows.
	 *
	 * @param string $privacy Media privacy (public|members|friends|private|group|custom).
	 * @return StorageDriverInterface Active driver for public media, else local.
	 */
	public function get_driver_for_privacy( string $privacy ): StorageDriverInterface {
		return 'public' === $privacy ? $this->get_driver() : $this->get_local_driver();
	}

	/**
	 * The driver that should store a given media's bytes, by its stored privacy.
	 *
	 * @param int $media_id Media ID.
	 * @return StorageDriverInterface
	 */
	public function get_driver_for_media( int $media_id ): StorageDriverInterface {
		$privacy = (string) \WPMediaVerse\Core\Plugin::container()
			->get( 'media_repository' )
			->get_raw( $media_id, 'privacy' );

		return $this->get_driver_for_privacy( $privacy );
	}

	/**
	 * Localize a media row's stored URLs after privacy escalates from public
	 * to a restricted level.
	 *
	 * Fresh non-public uploads always route through `get_driver_for_privacy()`
	 * (LocalDriver), so their URLs are local from the start. The reproducible
	 * failure is the PRIVACY-CHANGE path: when an item uploaded as public on a
	 * cloud driver (e.g. BunnyCDN, S3) is later flipped to members/friends/
	 * private/group/custom, the `file_url` and `thumb_*` meta values stay as
	 * cloud URLs. `SignedUrlService::serve_thumbnail()` then rejects every
	 * read with a 403 because the cloud URL fails the `realpath()` containment
	 * check against `{uploads}/wpmediaverse/`. Card 9925110293.
	 *
	 * The Free upload pipeline already mirrors the original bytes to the
	 * canonical local path via the copy at `UploadService::handle()` (the
	 * `$local_source` guard), and `generate_thumbnails()` writes each variant
	 * locally before any cloud push — so the files we point at here are
	 * guaranteed to exist on disk.
	 *
	 * @since 1.4.0
	 *
	 * @param int    $media_id    Media ID.
	 * @param string $new_privacy New privacy slug.
	 * @param string $old_privacy Previous privacy slug.
	 */
	public function sync_urls_on_privacy_change( int $media_id, string $new_privacy, string $old_privacy ): void {
		if ( 'public' !== $old_privacy || 'public' === $new_privacy ) {
			return;
		}

		$this->relocalize_media_urls( $media_id );
	}

	/**
	 * Re-point a media row's URL meta to its local copies.
	 *
	 * Driver-agnostic: walks `file_url`, `thumb_<size>`, sibling `_webp`/
	 * `_avif`, and `original_webp`/`original_avif`. Cloud URLs are rewritten
	 * to local-driver equivalents when the file exists on disk; otherwise
	 * the meta is set to either the canonical original URL (for JPEG
	 * thumbnails — same shape `ensure_fallback_thumbs()` uses) or cleared
	 * (for WebP/AVIF siblings — `<picture>` falls back to the JPEG).
	 *
	 * Used by the privacy-change action handler AND by the
	 * `wp mvs relocalize-private` cleanup command for legacy rows uploaded
	 * before the 1.4.x privacy-change listener shipped.
	 *
	 * @since 1.4.0
	 *
	 * @param int $media_id Media ID.
	 * @return bool True when at least one meta value was changed.
	 */
	public function relocalize_media_urls( int $media_id ): bool {
		$repo      = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$file_path = (string) $repo->get_raw( $media_id, 'file_path' );
		if ( '' === $file_path ) {
			return false;
		}

		$changed      = false;
		$local        = $this->get_local_driver();
		$upload_dir   = wp_upload_dir();
		$wpmv_basedir = trailingslashit( $upload_dir['basedir'] ) . 'wpmediaverse/';
		$wpmv_baseurl = trailingslashit( $upload_dir['baseurl'] ) . 'wpmediaverse/';
		$rel_dir      = dirname( $file_path );
		$rel_prefix   = ( '.' === $rel_dir || '' === $rel_dir ) ? '' : trailingslashit( $rel_dir );

		// file_url — canonical local URL via LocalDriver.
		$desired_file_url = $local->url( $file_path );
		$current_file_url = (string) $repo->get_raw( $media_id, 'file_url' );
		if ( $current_file_url !== $desired_file_url ) {
			$repo->set( $media_id, 'file_url', $desired_file_url );
			$changed = true;
		}

		// thumb_<size> JPEG variants. The local file is at the same basename
		// as the cloud URL but inside `wpmediaverse/{dirname(file_path)}/`.
		foreach ( array( 'large', 'medium', 'thumb' ) as $size ) {
			$key     = 'thumb_' . $size;
			$current = (string) $repo->get_raw( $media_id, $key );
			if ( '' === $current ) {
				continue;
			}
			if ( 0 === strpos( $current, $wpmv_baseurl ) ) {
				continue; // Already local.
			}

			$filename = basename( (string) wp_parse_url( $current, PHP_URL_PATH ) );
			if ( '' === $filename ) {
				continue;
			}

			$local_path = $wpmv_basedir . $rel_prefix . $filename;
			if ( file_exists( $local_path ) ) {
				$repo->set( $media_id, $key, $wpmv_baseurl . $rel_prefix . $filename );
				$changed = true;
			} elseif ( file_exists( $wpmv_basedir . $file_path ) ) {
				// Variant missing locally (rare: cloud-only WebP/AVIF cleanup
				// path) — fall back to the canonical original so the read
				// path serves SOMETHING rather than emitting a stale CDN URL
				// that 403s. ensure_fallback_thumbs() uses the same shape.
				$repo->set( $media_id, $key, $desired_file_url );
				$changed = true;
			}
		}

		// WebP/AVIF siblings: `UploadService` unlinks the local sibling after
		// pushing it to cloud (see `generate_thumbnails()` lines ~946/961).
		// On privacy escalation the bytes are stranded on the cloud bucket,
		// so the only safe action is to clear the meta and let `<picture>`
		// fall back to the JPEG. The next thumbnail regeneration will rewrite.
		foreach ( array( 'large', 'medium', 'thumb' ) as $size ) {
			foreach ( array( '_webp', '_avif' ) as $suffix ) {
				$key     = 'thumb_' . $size . $suffix;
				$current = (string) $repo->get_raw( $media_id, $key );
				if ( '' === $current || 0 === strpos( $current, $wpmv_baseurl ) ) {
					continue;
				}
				$local_path = $wpmv_basedir . $rel_prefix . basename( (string) wp_parse_url( $current, PHP_URL_PATH ) );
				if ( file_exists( $local_path ) ) {
					$repo->set( $media_id, $key, $wpmv_baseurl . $rel_prefix . basename( $local_path ) );
				} else {
					$repo->set( $media_id, $key, '' );
				}
				$changed = true;
			}
		}

		// Original WebP/AVIF: same treatment.
		foreach ( array( 'original_webp', 'original_avif' ) as $key ) {
			$current = (string) $repo->get_raw( $media_id, $key );
			if ( '' === $current || 0 === strpos( $current, $wpmv_baseurl ) ) {
				continue;
			}
			$local_path = $wpmv_basedir . $rel_prefix . basename( (string) wp_parse_url( $current, PHP_URL_PATH ) );
			if ( file_exists( $local_path ) ) {
				$repo->set( $media_id, $key, $wpmv_baseurl . $rel_prefix . basename( $local_path ) );
			} else {
				$repo->set( $media_id, $key, '' );
			}
			$changed = true;
		}

		return $changed;
	}
}
