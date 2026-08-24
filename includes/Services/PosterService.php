<?php
/**
 * Owns every write to `uploads/wpmediaverse/posters/` — a video's embedded
 * getID3 cover atom, audio ID3 art, and client-side canvas-captured frames.
 * No ffmpeg: MediaVerse embeds media, it does not process it, so a cover-less
 * video keeps the play-icon placeholder / default poster rather than being
 * frame-grabbed with a shell-out.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * PosterService consolidates the three previously-scattered hardcodes of the
 * `wpmediaverse/posters` directory (UploadService:1206 / :1367, MediaController:649)
 * and the four code paths that put bytes there. Public methods are intentionally
 * narrow — one per source — so callers don't reimplement the directory choice
 * or filename convention.
 *
 * Filename convention: `<media_id>.<ext>` for source bytes; size variants
 * (`<media_id>-WxH.jpg`) are produced by the standard `generate_thumbnails`
 * pipeline against the staged source.
 *
 * @since 1.5.0
 */
class PosterService {

	/**
	 * Path relative to the WordPress uploads basedir, without trailing slash.
	 * Single source of truth for the posters subdirectory. The relative shape
	 * (no leading slash) is what `LocalDriver` / `BunnyCDN` / `R2` drivers
	 * accept.
	 */
	public const REL_DIR = 'wpmediaverse/posters';

	/**
	 * Absolute filesystem path to the posters directory; ensures it exists.
	 * Returns null when the uploads dir itself is unavailable or `mkdir` fails.
	 */
	public function dir(): ?string {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return null;
		}
		$dir = trailingslashit( $upload_dir['basedir'] ) . self::REL_DIR;
		return wp_mkdir_p( $dir ) ? $dir : null;
	}

	/**
	 * Persist a video / audio embedded cover (Path A — getID3 / ID3 art).
	 *
	 * @param int   $media_id Media ID (filename anchor).
	 * @param array $image    `image` array from wp_read_video_metadata() / ID3 reader.
	 *                        Must contain `data` (raw bytes) and optionally `mime`.
	 * @return string|null Absolute path of the staged poster, or null on failure.
	 */
	public function stage_bytes( int $media_id, array $image ): ?string {
		if ( empty( $image['data'] ) || ! is_string( $image['data'] ) ) {
			return null;
		}

		$mime = isset( $image['mime'] ) ? (string) $image['mime'] : 'image/jpeg';
		$ext  = 'image/png' === $mime ? 'png' : ( 'image/webp' === $mime ? 'webp' : 'jpg' );

		$dir = $this->dir();
		if ( null === $dir ) {
			LoggerService::warning(
				'upload',
				sprintf( 'Could not create posters directory for media #%d', $media_id ),
				array( 'media_id' => $media_id )
			);
			return null;
		}

		$path = trailingslashit( $dir ) . $media_id . '.' . $ext;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$bytes = file_put_contents( $path, $image['data'] );

		if ( false === $bytes || 0 === $bytes ) {
			LoggerService::warning(
				'upload',
				sprintf( 'Failed to write video poster for media #%d', $media_id ),
				array( 'media_id' => $media_id )
			);
			return null;
		}

		return $path;
	}

	/**
	 * Stage a client-captured video thumbnail (JS canvas first-frame) into
	 * the posters directory. Caller is expected to feed the returned path
	 * back into `UploadService::generate_thumbnails()` for size variants.
	 *
	 * The source `$tmp_path` is moved (copy + unlink) into place — this
	 * matches the pre-1.5.0 inline behavior at `MediaController:649-657`.
	 *
	 * @param int    $media_id Media ID (filename anchor).
	 * @param string $tmp_path Absolute path to the uploaded tmpfile.
	 * @return string|null Absolute path of the staged poster, or null on failure.
	 */
	public function stage_client_frame( int $media_id, string $tmp_path ): ?string {
		$dir = $this->dir();
		if ( null === $dir ) {
			return null;
		}
		$dest = trailingslashit( $dir ) . $media_id . '.jpg';
		if ( ! copy( $tmp_path, $dest ) ) {
			return null;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		@unlink( $tmp_path );
		return $dest;
	}

}
