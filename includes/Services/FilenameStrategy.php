<?php
/**
 * Filename strategy — decides how an uploaded file is named on disk.
 *
 * Customer ask (Crisp #NZRSBX, card 9862532897 Item 1):
 *   - Long filenames break uploads silently (OS / S3 path-length limits).
 *   - Filenames with emojis / special chars trip AJAX/DB edge cases.
 *   - Original filenames are an enumeration / fingerprinting vector.
 *
 * Two strategies:
 *
 *   `original_sanitized` (legacy — opt-in since 1.6.0)
 *     `wp_unique_filename( sanitize_file_name(...) )` clamped to a
 *     hard 100-char cap. Was the upgrade-install default pre-1.6.0;
 *     now opt-in via the `mvs_filename_strategy_upgrade_default` filter
 *     because the original filename in the URL is an info-leak vector.
 *     Existing on-disk names stay valid either way (no rename, no
 *     migration, no broken URLs).
 *
 *   `hashed` (recommended — default for ALL installs since 1.6.0)
 *     16-char random hex + sanitized extension. Unpredictable, OS-safe,
 *     S3-safe, no enumeration vector. The original filename is preserved
 *     in `mvs_media_meta.original_filename` for display + Content-
 *     Disposition headers, so the user-facing filename is unchanged.
 *
 * Existing media is NEVER renamed. Only the strategy applied to NEW
 * uploads after the setting flips. This is the explicit constraint
 * the user asked for: "long term solution without breaking existing
 * uploaded media."
 *
 * Filter `mvs_filename_strategy` lets sites swap in a custom impl —
 * e.g. content-hash for dedup, or UUIDv4 for cross-system correlation.
 *
 * @since 1.2.1
 *
 * @package WPMediaVerse\Services
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Static utility — picks and applies a filename strategy.
 */
final class FilenameStrategy {

	/**
	 * Setting key. Enum: 'original_sanitized' | 'hashed'.
	 */
	public const SETTING = 'mvs_filename_strategy';

	/**
	 * Default for upgrade installs (existing sites). Preserves prior
	 * behavior so 1.2.0 → 1.2.1 doesn't silently change naming.
	 */
	public const DEFAULT_UPGRADE = 'original_sanitized';

	/**
	 * Default for fresh installs. The customer-recommended target — the
	 * Activator picks this when no media has ever been inserted.
	 */
	public const DEFAULT_FRESH = 'hashed';

	/**
	 * Hard cap for stored basename length. Protects against:
	 *   - OS path-length budgets (255 typical)
	 *   - S3 object key 1024-byte limit when prefixed with bucket / driver path
	 *   - DB column widths in dependent tables
	 *
	 * 100 is conservative and matches what the customer asked for.
	 */
	public const MAX_BASENAME_LENGTH = 100;

	/**
	 * The effective default strategy for any site that has NOT explicitly
	 * persisted one.
	 *
	 * Hashed since 1.6.0 (was `original_sanitized` for upgrade installs
	 * pre-1.6.0) — exposing the original client filename in the media URL is
	 * an information-leak and enumeration vector (audit 2026-06-04, card
	 * #9962530792). Only NEW uploads are affected; existing on-disk files are
	 * never renamed and their stored URLs never change.
	 *
	 * Escape hatch (Production Rule #3) — a site that depends on readable
	 * filenames in URLs restores the pre-1.6.0 default with one line:
	 *   add_filter( 'mvs_filename_strategy_upgrade_default', fn() => 'original_sanitized' );
	 * or forces it for every upload regardless of setting via the
	 * `mvs_filename_strategy` filter below.
	 *
	 * @since 1.6.0
	 */
	public static function effective_default(): string {
		/**
		 * Filter the default filename strategy applied when a site has not
		 * explicitly chosen one. Defaults to 'hashed' since 1.6.0.
		 *
		 * @since 1.6.0
		 *
		 * @param string $default One of 'hashed' | 'original_sanitized'.
		 */
		$default = (string) apply_filters( 'mvs_filename_strategy_upgrade_default', self::DEFAULT_FRESH );
		return self::is_valid_strategy( $default ) ? $default : self::DEFAULT_FRESH;
	}

	/**
	 * Resolve the active strategy slug. Filterable per-upload so
	 * specialized callers (CLI imports, batch tools) can force a strategy.
	 *
	 * @since 1.2.1
	 *
	 * @param int $user_id Uploading user ID (for filter context).
	 */
	public static function resolve_strategy( int $user_id = 0 ): string {
		$strategy = (string) get_option( self::SETTING, self::effective_default() );

		/**
		 * Filter the active filename strategy.
		 *
		 * Return one of: 'hashed' | 'original_sanitized'. Unknown values
		 * fall back to the default.
		 *
		 * @since 1.2.1
		 *
		 * @param string $strategy Current strategy.
		 * @param int    $user_id  User ID performing the upload.
		 */
		$strategy = (string) apply_filters( 'mvs_filename_strategy', $strategy, $user_id );

		return self::is_valid_strategy( $strategy ) ? $strategy : self::effective_default();
	}

	/**
	 * True when `$value` is a recognised strategy.
	 */
	public static function is_valid_strategy( string $value ): bool {
		return in_array( $value, array( 'hashed', 'original_sanitized' ), true );
	}

	/**
	 * Sanitize callback for the Settings API.
	 */
	public static function sanitize_setting( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return self::is_valid_strategy( $value ) ? $value : self::effective_default();
	}

	/**
	 * Pick a stored basename for an upload, given the original client-
	 * provided filename and the active strategy.
	 *
	 * Returns the basename (no path) ready for `wp_unique_filename()` or
	 * direct concatenation with the destination subdir.
	 *
	 * @since 1.2.1
	 *
	 * @param string $original_name Raw client-provided filename.
	 * @param string $dest_dir      Absolute destination directory (used by
	 *                              `wp_unique_filename` to dedupe).
	 * @param int    $user_id       Uploading user ID (filter context).
	 * @return array{stored: string, original: string, strategy: string}
	 */
	public static function pick( string $original_name, string $dest_dir, int $user_id = 0 ): array {
		$strategy = self::resolve_strategy( $user_id );
		$display  = self::sanitize_display_name( $original_name );

		if ( 'hashed' === $strategy ) {
			$ext = self::extract_extension( $original_name );
			// 16 hex chars from a CSPRNG. Collision probability with this
			// space size is negligible at any plausible site scale.
			$random = bin2hex( random_bytes( 8 ) );
			$stored = $random . ( '' !== $ext ? '.' . $ext : '' );
			$stored = wp_unique_filename( $dest_dir, $stored );
		} else {
			$sanitized = sanitize_file_name( $original_name );
			$sanitized = self::truncate_with_extension( $sanitized );
			$stored    = wp_unique_filename( $dest_dir, $sanitized );
		}

		return array(
			'stored'   => $stored,
			'original' => $display,
			'strategy' => $strategy,
		);
	}

	/**
	 * Return a sanitized version of the original filename suitable for
	 * display to the user (Content-Disposition, REST response field).
	 * Strips control characters; clamps length but preserves meaningful
	 * characters (unlike `sanitize_file_name` which is overly aggressive
	 * for display purposes).
	 */
	private static function sanitize_display_name( string $name ): string {
		// Drop NUL + control chars + path-traversal markers.
		$name = preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		$name = str_replace( array( '/', '\\', "\0" ), '', (string) $name );
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return '';
		}
		// Clamp display to 200 chars so the value can fit in mvs_media_meta
		// without blowing the row size budget.
		if ( mb_strlen( $name, 'UTF-8' ) > 200 ) {
			$name = mb_substr( $name, 0, 200, 'UTF-8' );
		}
		return $name;
	}

	/**
	 * Pull the file extension (lowercase, alnum-only) out of a filename.
	 * Returns empty string when no recognisable extension exists.
	 */
	private static function extract_extension( string $name ): string {
		$ext = (string) pathinfo( $name, PATHINFO_EXTENSION );
		$ext = preg_replace( '/[^a-zA-Z0-9]/', '', $ext );
		return strtolower( (string) $ext );
	}

	/**
	 * Truncate to MAX_BASENAME_LENGTH while preserving the extension.
	 * Bare basename longer than the cap → cut from the middle of the
	 * stem so the prefix stays human-recognisable.
	 */
	private static function truncate_with_extension( string $sanitized ): string {
		if ( mb_strlen( $sanitized, 'UTF-8' ) <= self::MAX_BASENAME_LENGTH ) {
			return $sanitized;
		}
		$ext  = self::extract_extension( $sanitized );
		$stem = ( '' !== $ext )
			? mb_substr( $sanitized, 0, mb_strlen( $sanitized, 'UTF-8' ) - mb_strlen( $ext, 'UTF-8' ) - 1, 'UTF-8' )
			: $sanitized;

		$stem_budget = self::MAX_BASENAME_LENGTH - ( '' !== $ext ? mb_strlen( $ext, 'UTF-8' ) + 1 : 0 );
		if ( $stem_budget < 1 ) {
			$stem_budget = 1;
		}
		$stem = mb_substr( $stem, 0, $stem_budget, 'UTF-8' );

		return ( '' !== $ext ) ? ( $stem . '.' . $ext ) : $stem;
	}
}
