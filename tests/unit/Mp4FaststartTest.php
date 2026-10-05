<?php
/**
 * Index-first MP4 rewrite, in pure PHP.
 *
 * @package WPMediaVerse
 */

// phpcs:disable WordPress.WP.AlternativeFunctions -- tests read and write their own temp fixtures.

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Services\Mp4Faststart;

/**
 * A video whose moov sits after mdat must start playing before the whole file
 * arrives (Basecamp 10370404673: members-only videos never started on a host
 * whose page cache keeps Range from PHP). Built from synthetic boxes so it
 * runs without ffmpeg.
 */
class Mp4FaststartTest extends WP_UnitTestCase {

	/**
	 * Temp files to remove.
	 *
	 * @var string[]
	 */
	private array $files = array();

	public function tear_down(): void {
		foreach ( $this->files as $f ) {
			if ( file_exists( $f ) ) {
				wp_delete_file( $f );
			}
		}
		parent::tear_down();
	}

	private function box( string $type, string $body ): string {
		return pack( 'N', 8 + strlen( $body ) ) . $type . $body;
	}

	/**
	 * moov -> trak -> mdia -> minf -> stbl -> stco with the given offsets.
	 *
	 * @param int[] $offsets Chunk offsets.
	 */
	private function moov( array $offsets, string $table = 'stco' ): string {
		$fmt  = 'stco' === $table ? 'N' : 'J';
		$body = "\0\0\0\0" . pack( 'N', count( $offsets ) );
		foreach ( $offsets as $o ) {
			$body .= pack( $fmt, $o );
		}
		$stbl = $this->box( 'stbl', $this->box( $table, $body ) );
		return $this->box( 'moov', $this->box( 'trak', $this->box( 'mdia', $this->box( 'minf', $stbl ) ) ) );
	}

	private function write( string $bytes ): string {
		$f             = wp_tempnam( 'mvs-faststart' ) . '.mp4';
		$this->files[] = $f;
		file_put_contents( $f, $bytes );
		return $f;
	}

	/**
	 * Read the chunk offsets back out of a file's moov.
	 *
	 * @return int[]
	 */
	private function offsets_in( string $file ): array {
		$bytes = file_get_contents( $file );
		$at    = strpos( $bytes, 'stco' );
		$count = unpack( 'N', substr( $bytes, $at + 8, 4 ) )[1];
		$out   = array();
		for ( $i = 0; $i < $count; $i++ ) {
			$out[] = unpack( 'N', substr( $bytes, $at + 12 + 4 * $i, 4 ) )[1];
		}
		return $out;
	}

	public function test_moov_moves_first_and_offsets_still_hit_the_same_samples(): void {
		$ftyp = $this->box( 'ftyp', 'isom' . "\0\0\2\0" . 'isomiso2' );
		$free = $this->box( 'free', '' );
		$mdat = $this->box( 'mdat', 'xxSAMPLE-Axx' . 'yySAMPLE-Byy' );
		// Sample markers' absolute offsets in the ORIGINAL layout.
		$base = strlen( $ftyp . $free ) + 8;
		$a    = $base + 2;
		$b    = $base + 14;
		$file = $this->write( $ftyp . $free . $mdat . $this->moov( array( $a, $b ) ) );
		$size = filesize( $file );

		$this->assertTrue( Mp4Faststart::apply( $file, 'video/mp4' ) );
		$this->assertSame( array( 'ftyp', 'moov', 'free', 'mdat' ), array_column( Mp4Faststart::top_level_boxes( $file ), 'type' ) );
		$this->assertSame( $size, filesize( $file ), 'Same size: only order and offsets change.' );

		$bytes           = file_get_contents( $file );
		list( $na, $nb ) = $this->offsets_in( $file );
		$this->assertSame( 'SAMPLE-A', substr( $bytes, $na, 8 ) );
		$this->assertSame( 'SAMPLE-B', substr( $bytes, $nb, 8 ) );

		$this->assertFalse( Mp4Faststart::apply( $file, 'video/mp4' ), 'Already faststart: left alone.' );
	}

	public function test_co64_tables_are_shifted_too(): void {
		$ftyp = $this->box( 'ftyp', 'isom' );
		$mdat = $this->box( 'mdat', 'zzSAMPLE-Czz' );
		$c    = strlen( $ftyp ) + 8 + 2;
		$file = $this->write( $ftyp . $mdat . $this->moov( array( $c ), 'co64' ) );

		$this->assertTrue( Mp4Faststart::apply( $file, 'video/mp4' ) );
		$bytes = file_get_contents( $file );
		$at    = strpos( $bytes, 'co64' );
		$new   = (int) unpack( 'J', substr( $bytes, $at + 12, 8 ) )[1];
		$this->assertSame( 'SAMPLE-C', substr( $bytes, $new, 8 ) );
	}

	public function test_files_it_cannot_safely_rewrite_are_untouched(): void {
		$ftyp = $this->box( 'ftyp', 'isom' );
		$mdat = $this->box( 'mdat', 'data' );

		// A 32-bit offset that would overflow after the shift.
		$overflow = $this->write( $ftyp . $mdat . $this->moov( array( 0xFFFFFFF0 ) ) );
		$before   = md5_file( $overflow );
		$this->assertFalse( Mp4Faststart::apply( $overflow, 'video/mp4' ) );
		$this->assertSame( $before, md5_file( $overflow ) );

		// Not an MP4 container type.
		$webm = $this->write( $ftyp . $mdat . $this->moov( array( 20 ) ) );
		$this->assertFalse( Mp4Faststart::apply( $webm, 'video/webm' ) );

		// Not a box structure at all.
		$junk = $this->write( str_repeat( 'not an mp4 ', 20 ) );
		$this->assertFalse( Mp4Faststart::apply( $junk, 'video/mp4' ) );
		$this->assertSame( array(), Mp4Faststart::top_level_boxes( $junk ) );
	}
}
