<?php
/**
 * Move an MP4's index (moov) in front of its media data, in pure PHP.
 *
 * A camera or editor often writes the moov box last. A player then needs the
 * end of the file before it can show a frame, which works only while the
 * server answers Range requests. On a host whose page cache keeps the Range
 * header back from PHP (nginx fastcgi_cache honouring `Cache-Control: private`,
 * the case behind members-only videos that never started), the player gets the
 * whole file in order and waits for all of it. With moov first, playback starts
 * as soon as the first bytes arrive, Range or not.
 *
 * This is what ffmpeg's `-movflags +faststart` / qt-faststart do, without
 * ffmpeg: many hosts have no ffmpeg binary. Only the box order and the chunk
 * offsets change; no media sample is touched. Anything it cannot handle safely
 * (fragmented MP4, compressed moov, 32-bit offsets that would overflow) is left
 * exactly as uploaded.
 *
 * @package WPMediaVerse
 * @since   2.6.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Faststart rewrite for MP4 / MOV / M4V / M4A files.
 */
final class Mp4Faststart {

	/** MIME types that use the ISO base media (MP4/QuickTime) container. */
	public const MIMES = array( 'video/mp4', 'video/quicktime', 'video/x-m4v', 'audio/mp4', 'audio/x-m4a' );

	/** Container boxes on the path from moov to the chunk offset tables. */
	private const CONTAINERS = array( 'moov', 'trak', 'mdia', 'minf', 'stbl' );

	/** Largest moov read into memory (real indexes are kilobytes to a few MB). */
	private const MAX_MOOV = 16 * 1024 * 1024;

	/** Real files nest moov > trak > mdia > minf > stbl: never descend deeper. */
	private const MAX_DEPTH = 5;

	/** Chunk-offset entries patched per slice, so a large table never becomes one huge array. */
	private const SLICE = 65536;

	/**
	 * Rewrite the file in place with moov first, when it is not already.
	 *
	 * @param string $path Absolute path of the file.
	 * @param string $mime Its MIME type.
	 * @return bool True when the file was rewritten.
	 */
	public static function apply( string $path, string $mime ): bool {
		if ( ! in_array( $mime, self::MIMES, true ) || ! is_readable( $path ) || ! is_writable( $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a plain stat check on the upload temp file.
			return false;
		}

		$found = self::locate( $path );
		if ( null === $found ) {
			return false;
		}
		list( $boxes, $moov ) = $found;

		$in = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $in ) {
			return false;
		}
		fseek( $in, $moov['offset'] );
		$moov_bytes = (string) fread( $in, $moov['size'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		if ( strlen( $moov_bytes ) !== $moov['size'] || false !== strpos( $moov_bytes, 'cmov' ) ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		// Everything between the insertion point and the old moov position moves
		// forward by the moov's size, and the media data sits in that span.
		$patched = self::shift_offsets( $moov_bytes, $moov['size'] );
		if ( null === $patched ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		// moov goes right after ftyp (or first, if the file has no ftyp).
		$ftyp   = ( 'ftyp' === $boxes[0]['type'] ) ? $boxes[0] : null;
		$insert = null !== $ftyp ? $ftyp['offset'] + $ftyp['size'] : 0;

		$tmp = $path . '.faststart';
		$out = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		$ok = self::copy_range( $in, $out, 0, $insert )
			&& false !== fwrite( $out, $patched ) // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			&& self::copy_range( $in, $out, $insert, $moov['offset'] - $insert )
			&& self::copy_range( $in, $out, $moov['offset'] + $moov['size'], (int) filesize( $path ) - $moov['offset'] - $moov['size'] );
		fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		clearstatcache( true, $tmp );
		// The rewritten file replaces the original, so it keeps the original's mode
		// (and owner, when run as root from WP-CLI), or a CLI run could leave a
		// file the web server cannot read.
		$perms = fileperms( $path );
		$owner = fileowner( $path );
		$group = filegroup( $path );
		if ( ! $ok || filesize( $tmp ) !== filesize( $path ) || ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			wp_delete_file( $tmp );
			return false;
		}
		if ( false !== $perms ) {
			@chmod( $path, $perms & 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod
		}
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() && false !== $owner ) {
			@chown( $path, $owner ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chown
			@chgrp( $path, $group ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chgrp
		}
		clearstatcache( true, $path );
		return true;
	}

	/**
	 * Does this file have its index after its media data (and can it be fixed)?
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public static function needs( string $path ): bool {
		return null !== self::locate( $path );
	}

	/**
	 * The boxes and the moov to move, or null when there is nothing to do: no
	 * index, already index-first, fragmented, or an implausibly large index.
	 *
	 * @param string $path File path.
	 * @return array{0: array<int, array{type: string, offset: int, size: int}>, 1: array{type: string, offset: int, size: int}}|null
	 */
	private static function locate( string $path ): ?array {
		$boxes = self::top_level_boxes( $path );
		$moov  = self::first( $boxes, 'moov' );
		$mdat  = self::first( $boxes, 'mdat' );
		if ( null === $moov || null === $mdat || $moov['offset'] < $mdat['offset'] || null !== self::first( $boxes, 'moof' ) || $moov['size'] > self::MAX_MOOV ) {
			return null;
		}
		// Every mdat must sit before the moov: the rewrite shifts every chunk
		// offset by the moov's size, which is only right for data that moves. A
		// chunk in an mdat after the moov stays put and would be pointed at the
		// wrong bytes, so such a file is left exactly as uploaded.
		foreach ( $boxes as $box ) {
			if ( 'mdat' === $box['type'] && $box['offset'] > $moov['offset'] ) {
				return null;
			}
		}
		return array( $boxes, $moov );
	}

	/**
	 * List the top-level boxes: type, offset, size.
	 *
	 * @param string $path File path.
	 * @return array<int, array{type: string, offset: int, size: int}>
	 */
	public static function top_level_boxes( string $path ): array {
		$size  = (int) filesize( $path );
		$fh    = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$boxes = array();
		if ( false === $fh ) {
			return $boxes;
		}
		$pos = 0;
		$n   = 0;
		while ( $pos + 8 <= $size && $n++ < 64 ) {
			fseek( $fh, $pos );
			$head = (string) fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( 8 !== strlen( $head ) ) {
				break;
			}
			$len  = unpack( 'N', substr( $head, 0, 4 ) )[1];
			$type = substr( $head, 4, 4 );
			if ( 1 === $len ) {
				$big = (string) fread( $fh, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$len = 8 === strlen( $big ) ? (int) unpack( 'J', $big )[1] : 0;
			} elseif ( 0 === $len ) {
				$len = $size - $pos; // Runs to the end of the file.
			}
			if ( $len < 8 || $pos + $len > $size ) {
				return array(); // Damaged or not an MP4: treat as unknown.
			}
			$boxes[] = array(
				'type'   => $type,
				'offset' => $pos,
				'size'   => $len,
			);
			$pos    += $len;
		}
		return $boxes;
	}

	/**
	 * Add $delta to every chunk offset (stco / co64) inside moov.
	 *
	 * @param string $moov  Raw moov box.
	 * @param int    $delta Bytes the media data moves forward.
	 * @return string|null Patched moov, or null when a 32-bit offset would overflow.
	 */
	private static function shift_offsets( string $moov, int $delta ): ?string {
		$ok = true;
		self::walk( $moov, 0, strlen( $moov ), $delta, $ok, 0 );
		return $ok ? $moov : null;
	}

	/**
	 * Walk boxes in $buf[$start, $end), descending into containers.
	 *
	 * @param string $buf   Buffer (patched in place).
	 * @param int    $start Start offset.
	 * @param int    $end   End offset.
	 * @param int    $delta Offset shift.
	 * @param bool   $ok    Cleared on overflow or a malformed box.
	 * @param int    $depth Container depth (bounded: a crafted file could nest
	 *                      thousands of containers and exhaust the stack).
	 * @return void
	 */
	private static function walk( string &$buf, int $start, int $end, int $delta, bool &$ok, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			$ok = false;
			return;
		}
		$pos = $start;
		while ( $ok && $pos + 8 <= $end ) {
			$len  = unpack( 'N', substr( $buf, $pos, 4 ) )[1];
			$type = substr( $buf, $pos + 4, 4 );
			$head = 8;
			if ( 1 === $len ) {
				$len  = (int) unpack( 'J', substr( $buf, $pos + 8, 8 ) )[1];
				$head = 16;
			} elseif ( 0 === $len ) {
				$len = $end - $pos;
			}
			if ( $len < $head || $pos + $len > $end ) {
				$ok = false;
				return;
			}
			if ( in_array( $type, self::CONTAINERS, true ) ) {
				self::walk( $buf, $pos + $head, $pos + $len, $delta, $ok, $depth + 1 );
			} elseif ( ( 'stco' === $type || 'co64' === $type ) && $len >= $head + 8 ) {
				$count = unpack( 'N', substr( $buf, $pos + $head + 4, 4 ) )[1];
				$width = 'stco' === $type ? 4 : 8;
				$at    = $pos + $head + 8;
				if ( $at + $count * $width > $pos + $len ) {
					$ok = false;
					return;
				}
				if ( $count > 0 ) {
					// One write per table, built slice by slice: patching entry by
					// entry copied the whole moov per entry (quadratic on a crafted
					// table), and one unpack of a 16 MB table made a 4M-entry array.
					$fmt     = 4 === $width ? 'N' : 'J';
					$patched = '';
					for ( $from = 0; $from < $count; $from += self::SLICE ) {
						$n      = min( self::SLICE, $count - $from );
						$values = array_values( unpack( $fmt . '*', substr( $buf, $at + $from * $width, $n * $width ) ) );
						foreach ( $values as $i => $value ) {
							$values[ $i ] = (int) $value + $delta;
							if ( 4 === $width && $values[ $i ] > 0xFFFFFFFF ) {
								$ok = false; // Would need co64; leave the file alone.
								return;
							}
						}
						$patched .= pack( $fmt . '*', ...$values );
					}
					$buf = substr_replace( $buf, $patched, $at, $count * $width );
				}
			}
			$pos += $len;
		}
	}

	/**
	 * First box of a type.
	 *
	 * @param array<int, array{type: string, offset: int, size: int}> $boxes Boxes.
	 * @param string                                                  $type  Type.
	 * @return array{type: string, offset: int, size: int}|null
	 */
	private static function first( array $boxes, string $type ): ?array {
		foreach ( $boxes as $box ) {
			if ( $type === $box['type'] ) {
				return $box;
			}
		}
		return null;
	}

	/**
	 * Copy $length bytes from $in at $offset to $out, in 1 MB chunks.
	 *
	 * @param resource $in     Source.
	 * @param resource $out    Destination.
	 * @param int      $offset Start offset in the source.
	 * @param int      $length Bytes to copy.
	 * @return bool
	 */
	private static function copy_range( $in, $out, int $offset, int $length ): bool {
		if ( $length <= 0 ) {
			return true;
		}
		fseek( $in, $offset );
		while ( $length > 0 ) {
			$chunk = fread( $in, min( 1048576, $length ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk || false === fwrite( $out, $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				return false;
			}
			$length -= strlen( $chunk );
		}
		return true;
	}
}
