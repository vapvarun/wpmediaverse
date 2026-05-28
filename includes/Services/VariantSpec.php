<?php
/**
 * Value object describing a single media variant (thumb size, sibling format,
 * or original-format alternate). One spec → one meta key family.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Variant descriptor + canonical rel-path computation.
 *
 * Collapses the historic 5+ ways the upload pipeline computed
 * "the relative path of variant X of media Y" (UploadService:915 / :993 /
 * :1014, ImageOptimizationService:521 / :845 / :876). Every new variant
 * write routes through one of the factory methods so the path can never
 * disagree across siblings of the same variant.
 *
 * @since 1.5.0
 */
final class VariantSpec {

	public const CLASS_THUMB    = 'thumb';
	public const CLASS_ORIGINAL = 'original';

	public const FORMAT_PRIMARY = 'primary';
	public const FORMAT_WEBP    = 'webp';
	public const FORMAT_AVIF    = 'avif';

	/**
	 * @var int
	 */
	private $media_id;

	/**
	 * @var string CLASS_THUMB | CLASS_ORIGINAL
	 */
	private $variant_class;

	/**
	 * @var string Size key ('large'|'medium'|'thumb') — empty for originals.
	 */
	private $size_key;

	/**
	 * @var string FORMAT_PRIMARY | FORMAT_WEBP | FORMAT_AVIF
	 */
	private $format;

	/**
	 * @var string Relative-to-`wpmediaverse/` path, e.g. `2026/05/foo-1024x683.jpg`.
	 */
	private $rel_path;

	/**
	 * @var string Absolute on-disk path of the file.
	 */
	private $local_path;

	private function __construct( int $media_id, string $variant_class, string $size_key, string $format, string $rel_path, string $local_path ) {
		$this->media_id      = $media_id;
		$this->variant_class = $variant_class;
		$this->size_key      = $size_key;
		$this->format        = $format;
		$this->rel_path      = $rel_path;
		$this->local_path    = $local_path;
	}

	/**
	 * Primary (JPEG/source) thumb variant.
	 *
	 * @param int    $media_id   Media ID.
	 * @param string $size_key   Size key: large|medium|thumb.
	 * @param string $rel_path   Relative-to-wpmediaverse/ path of the variant file.
	 * @param string $local_path Absolute on-disk path of the variant file.
	 */
	public static function for_image_variant( int $media_id, string $size_key, string $rel_path, string $local_path ): self {
		return new self( $media_id, self::CLASS_THUMB, $size_key, self::FORMAT_PRIMARY, $rel_path, $local_path );
	}

	/**
	 * WebP sibling of an existing primary variant (same dir + basename, .webp ext).
	 *
	 * @param self   $base       The primary variant this is a sibling of.
	 * @param string $local_path Absolute on-disk path of the webp file.
	 */
	public static function for_webp_sibling( self $base, string $local_path ): self {
		$rel = self::swap_extension( $base->rel_path, 'webp' );
		return new self( $base->media_id, $base->variant_class, $base->size_key, self::FORMAT_WEBP, $rel, $local_path );
	}

	/**
	 * AVIF sibling of an existing primary variant.
	 *
	 * @param self   $base       The primary variant this is a sibling of.
	 * @param string $local_path Absolute on-disk path of the avif file.
	 */
	public static function for_avif_sibling( self $base, string $local_path ): self {
		$rel = self::swap_extension( $base->rel_path, 'avif' );
		return new self( $base->media_id, $base->variant_class, $base->size_key, self::FORMAT_AVIF, $rel, $local_path );
	}

	/**
	 * Original-size WebP sibling of the source file.
	 *
	 * @param int    $media_id   Media ID.
	 * @param string $rel_path   Relative-to-wpmediaverse/ path of the webp file.
	 * @param string $local_path Absolute on-disk path of the webp file.
	 */
	public static function for_original_webp( int $media_id, string $rel_path, string $local_path ): self {
		return new self( $media_id, self::CLASS_ORIGINAL, '', self::FORMAT_WEBP, $rel_path, $local_path );
	}

	/**
	 * Original-size AVIF sibling of the source file.
	 *
	 * @param int    $media_id   Media ID.
	 * @param string $rel_path   Relative-to-wpmediaverse/ path of the avif file.
	 * @param string $local_path Absolute on-disk path of the avif file.
	 */
	public static function for_original_avif( int $media_id, string $rel_path, string $local_path ): self {
		return new self( $media_id, self::CLASS_ORIGINAL, '', self::FORMAT_AVIF, $rel_path, $local_path );
	}

	/**
	 * Canonical "relative-to-wpmediaverse" path computation for a generated
	 * thumb variant. Single source of truth — every caller that previously
	 * derived this independently (5+ sites) routes here.
	 *
	 * Strategy: the variant lives in the same directory as the SOURCE file
	 * (`$rel_dir` derived from `dirname( $file_path )` minus the uploads
	 * basedir). For video posters that's `wpmediaverse/posters/`; for normal
	 * images it's `wpmediaverse/<Y>/<m>/`. We strip the leading
	 * `wpmediaverse/` because drivers operate on paths relative to
	 * `wpmediaverse/`. CLI backfill where the source is a temp path
	 * (`/var/folders/...`) falls back to `$cloud_rel_dir` (the media's
	 * stored `file_path` directory).
	 *
	 * @param string $rel_dir       Source dir relative to uploads basedir.
	 * @param string $cloud_rel_dir Fallback dir from media's stored file_path.
	 * @param string $filename      Variant filename (basename).
	 * @return string Relative-to-`wpmediaverse/` path.
	 */
	public static function compute_rel_path( string $rel_dir, string $cloud_rel_dir, string $filename ): string {
		$path_rel_dir = ( 0 === strpos( $rel_dir, 'wpmediaverse/' ) )
			? substr( $rel_dir, strlen( 'wpmediaverse/' ) )
			: $cloud_rel_dir;
		return ( '' === $path_rel_dir ? '' : trailingslashit( $path_rel_dir ) ) . $filename;
	}

	/**
	 * Replace a path's extension. Used to derive sibling rel paths from a
	 * base variant's rel path (e.g. `posters/101-1024x576.jpg` → `…-1024x576.webp`).
	 *
	 * @param string $path      Source path.
	 * @param string $new_ext   New extension (without leading dot).
	 * @return string
	 */
	private static function swap_extension( string $path, string $new_ext ): string {
		$dir      = dirname( $path );
		$filename = pathinfo( $path, PATHINFO_FILENAME );
		$prefix   = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );
		return $prefix . $filename . '.' . $new_ext;
	}

	/**
	 * The meta key under which `MediaRepository::set()` should persist the
	 * variant's stored URL. Examples: `thumb_large`, `thumb_medium_webp`,
	 * `original_avif`.
	 */
	public function meta_key(): string {
		$base = self::CLASS_THUMB === $this->variant_class
			? 'thumb_' . $this->size_key
			: 'original';
		if ( self::FORMAT_PRIMARY === $this->format ) {
			return $base;
		}
		return $base . '_' . $this->format;
	}

	/**
	 * The meta key under which `MediaRepository::set()` should persist the
	 * driver-agnostic relative path. Mirrors `meta_key()` with a `_path` suffix.
	 */
	public function path_meta_key(): string {
		return $this->meta_key() . '_path';
	}

	public function media_id(): int {
		return $this->media_id;
	}

	public function variant_class(): string {
		return $this->variant_class;
	}

	public function size_key(): string {
		return $this->size_key;
	}

	public function format(): string {
		return $this->format;
	}

	public function rel_path(): string {
		return $this->rel_path;
	}

	public function local_path(): string {
		return $this->local_path;
	}
}
