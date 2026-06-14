<?php
/**
 * Owns every write to `uploads/wpmediaverse/posters/` — video Path A (getID3
 * cover atom), Path B (ffmpeg first-frame), audio ID3 art, and client-side
 * canvas-captured frames.
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
	 * Extract a poster frame from a video using ffmpeg (Path B).
	 *
	 * Tries the 1-second mark first (avoids the all-black opening frame
	 * common in fade-in intros). Falls back to frame 0 when the video is
	 * shorter than 1s. Writes the JPEG to the same posters/ directory used
	 * by stage_bytes so the rest of the pipeline doesn't need to special-case it.
	 *
	 * @param int    $media_id   Media ID (filename anchor).
	 * @param string $video_path Absolute path to the video on local disk.
	 * @return string|null Absolute poster path, or null when ffmpeg is missing /
	 *                     extraction failed / output was too small to be useful.
	 */
	public function extract_via_ffmpeg( int $media_id, string $video_path ): ?string {
		/**
		 * Filter the ffmpeg binary path used for video poster extraction.
		 *
		 * Default (1.3.0+): the helper auto-detects common ffmpeg install
		 * locations (Homebrew, /usr/local, /usr/bin) when bare `ffmpeg`
		 * isn't on the PATH PHP-FPM inherits. Web SAPI typically gets a
		 * minimal PATH from the launching service that omits Homebrew, so
		 * a bare `ffmpeg` exec returns 127 even when the binary is
		 * reachable from a shell.
		 *
		 * @since 1.2.2
		 *
		 * @param string $binary Default 'ffmpeg' (or auto-resolved absolute path).
		 */
		$ffmpeg = (string) apply_filters( 'mvs_ffmpeg_binary', self::resolve_ffmpeg_binary() );

		if ( ! function_exists( 'proc_open' ) ) {
			return null;
		}

		$dir = $this->dir();
		if ( null === $dir ) {
			return null;
		}
		$dest = trailingslashit( $dir ) . $media_id . '.jpg';

		if ( ! $this->run_ffmpeg_extract( $ffmpeg, $video_path, $dest, true ) ) {
			LoggerService::warning(
				'upload',
				sprintf( 'ffmpeg poster extraction failed (binary not found or exec non-zero) for media #%d', $media_id ),
				array(
					'media_id' => $media_id,
					'binary'   => $ffmpeg,
				)
			);
			return null;
		}

		if ( ! file_exists( $dest ) || filesize( $dest ) < 200 ) {
			// Some videos are shorter than 1s — retry from frame 0.
			$this->run_ffmpeg_extract( $ffmpeg, $video_path, $dest, false );
		}

		if ( ! file_exists( $dest ) || filesize( $dest ) < 200 ) {
			LoggerService::info(
				'upload',
				sprintf( 'ffmpeg poster extraction produced no usable frame for media #%d', $media_id ),
				array( 'media_id' => $media_id )
			);
			return null;
		}

		return $dest;
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

	/**
	 * Resolve a usable ffmpeg binary path for the current SAPI. Cached
	 * per-request because bulk-regenerate paths may invoke this many times.
	 *
	 * @return string Absolute path when one resolved, else 'ffmpeg'.
	 */
	private static function resolve_ffmpeg_binary(): string {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		// First-match-wins. Prefer Homebrew on macOS (dev environment),
		// then standard system locations. Sites with non-standard paths
		// override via the `mvs_ffmpeg_binary` filter.
		$candidates = array(
			'/opt/homebrew/bin/ffmpeg',  // macOS Apple Silicon Homebrew
			'/usr/local/bin/ffmpeg',     // macOS Intel Homebrew + many Linux
			'/usr/bin/ffmpeg',           // Distro packages (apt, dnf)
			'/opt/ffmpeg/bin/ffmpeg',    // Some shared-hosting layouts
		);

		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				$cached = $candidate;
				return $cached;
			}
		}

		$cached = 'ffmpeg';
		return $cached;
	}

	/**
	 * Whether ffmpeg is usable for poster extraction in the current SAPI.
	 *
	 * Surfaced by Site Health: when ffmpeg is missing, cover-less videos get no
	 * generated poster and fall back to the bundled default SVG (the upload
	 * still succeeds — this is a quality, not a failure, condition). Mirrors the
	 * resolution extract_via_ffmpeg() uses: proc_open must exist and the
	 * filtered binary must be executable (absolute path) or resolve on PATH.
	 *
	 * @since 1.7.0
	 *
	 * @return bool
	 */
	public function is_ffmpeg_available(): bool {
		if ( ! function_exists( 'proc_open' ) ) {
			return false;
		}

		$ffmpeg = (string) apply_filters( 'mvs_ffmpeg_binary', self::resolve_ffmpeg_binary() );
		if ( '' === $ffmpeg ) {
			return false;
		}

		// Absolute path → a direct executable check is authoritative and cheap.
		if ( false !== strpos( $ffmpeg, '/' ) ) {
			return is_executable( $ffmpeg );
		}

		// Bare 'ffmpeg' → confirm it resolves on PATH with a fast version probe.
		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.PHP.ForbiddenFunctions.Found, WordPress.PHP.NoSilencedErrors.Discouraged
		$process = @proc_open( array( $ffmpeg, '-version' ), $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return false;
		}
		foreach ( $pipes as $pipe ) {
			if ( is_resource( $pipe ) ) {
				fclose( $pipe );
			}
		}
		return 0 === proc_close( $process );
	}

	/**
	 * Invoke ffmpeg via proc_open with an arg array (no shell, no injection
	 * surface). Mirrors the pattern used in Pro's TranscodeService.
	 *
	 * @param string $binary           ffmpeg binary path or name.
	 * @param string $video_path       Source video path.
	 * @param string $dest             Output JPEG path.
	 * @param bool   $seek_one_second  When true, pass `-ss 1` to skip opening fades.
	 * @return bool True when the process exited 0.
	 */
	private function run_ffmpeg_extract( string $binary, string $video_path, string $dest, bool $seek_one_second ): bool {
		$args = array( $binary, '-loglevel', 'error' );
		if ( $seek_one_second ) {
			$args[] = '-ss';
			$args[] = '1';
		}
		$args[] = '-i';
		$args[] = $video_path;
		$args[] = '-vframes';
		$args[] = '1';
		$args[] = '-q:v';
		$args[] = '2';
		$args[] = '-y';
		$args[] = $dest;

		$descriptors = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);

		// proc_open with array $cmd bypasses shell interpretation on PHP 7.4+
		// — each argument is passed to execve() directly. No string escaping
		// concerns even if $video_path / $dest contain shell metacharacters.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open, Generic.PHP.ForbiddenFunctions.Found, WordPress.PHP.NoSilencedErrors.Discouraged
		$process = @proc_open( $args, $descriptors, $pipes );
		if ( ! is_resource( $process ) ) {
			return false;
		}
		// Close stdin; drain stdout/stderr so the child can exit.
		if ( isset( $pipes[0] ) && is_resource( $pipes[0] ) ) {
			fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( isset( $pipes[1] ) && is_resource( $pipes[1] ) ) {
			stream_get_contents( $pipes[1] );
			fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( isset( $pipes[2] ) && is_resource( $pipes[2] ) ) {
			stream_get_contents( $pipes[2] );
			fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}

		$status = proc_close( $process );
		return 0 === $status;
	}
}
