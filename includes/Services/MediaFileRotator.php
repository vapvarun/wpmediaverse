<?php
/**
 * Give a media item's files new random names when fewer people may see it.
 *
 * With direct delivery (DirectDelivery) a file's own URL works for whoever
 * holds it. When an item's privacy is tightened, or it is trashed, flagged or
 * rejected, everyone who saw it earlier may still have that URL. Renaming the
 * files to a new random stem makes every earlier URL a 404, which is how a
 * capability URL is revoked. Only that one item's files move; nothing is ever
 * renamed in bulk.
 *
 * @package WPMediaVerse
 * @since   2.6.1
 */

declare( strict_types=1 );

namespace WPMediaVerse\Services;

use WPMediaVerse\Core\Plugin;
use WPMediaVerse\Repository\MediaRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Listens for visibility changes and rotates the affected item's file names.
 */
final class MediaFileRotator {

	/**
	 * Background hook that rotates a chunk of media ids.
	 */
	public const HOOK = 'mvs_rotate_media_files';

	/**
	 * Up to this many items rotate at the end of the request; more (an album
	 * made private) go to the background in chunks, so the owner's request
	 * stays fast however big the album is.
	 */
	private const INLINE = 10;

	/**
	 * Media ids waiting for the end of the request.
	 *
	 * @var array<int, true>
	 */
	private static array $pending = array();

	/**
	 * Hook the events that make an item visible to fewer people.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'mvs_media_privacy_changed', array( self::class, 'on_privacy_changed' ), 20, 3 );
		add_action( 'mvs_moderation_changed', array( self::class, 'on_moderation_changed' ), 20, 3 );
		// The AI reviewer writes moderation_status directly, without mvs_moderation_changed.
		add_action( 'mvs_media_flagged', array( self::class, 'queue' ), 20, 1 );
		add_action( 'mvs_media_trashed', array( self::class, 'queue' ), 20, 1 );
		add_action( self::HOOK, array( self::class, 'rotate_many' ) );
	}

	/**
	 * Mark an item for rotation at the end of this request. Several changes to
	 * one item in a request rotate it once.
	 *
	 * @param int $media_id Media id.
	 * @return void
	 */
	public static function queue( int $media_id ): void {
		if ( array() === self::$pending ) {
			add_action( 'shutdown', array( self::class, 'flush' ) );
		}
		self::$pending[ $media_id ] = true;
	}

	/**
	 * Rotate what was queued: a few inline, the rest in background chunks
	 * (Action Scheduler when present, else a single WP-Cron event per chunk).
	 *
	 * @return void
	 */
	public static function flush(): void {
		$ids           = array_keys( self::$pending );
		self::$pending = array();
		if ( count( $ids ) <= self::INLINE ) {
			self::rotate_many( $ids );
			return;
		}
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action( self::HOOK, array( 'ids' => $chunk ), 'wpmediaverse' );
			} else {
				wp_schedule_single_event( time(), self::HOOK, array( $chunk ) );
			}
		}
	}

	/**
	 * Rotate a list of media ids.
	 *
	 * @param array $ids Media ids.
	 * @return void
	 */
	public static function rotate_many( array $ids ): void {
		foreach ( $ids as $id ) {
			self::rotate( (int) $id );
		}
	}

	/**
	 * Rotate when the new privacy is narrower than the old one.
	 *
	 * Widening (private to public) needs no rotation: everyone who had the URL
	 * may still see the item.
	 *
	 * @param int    $media_id    Media id.
	 * @param string $new_privacy New privacy.
	 * @param string $old_privacy Old privacy.
	 * @return void
	 */
	public static function on_privacy_changed( int $media_id, string $new_privacy, string $old_privacy = '' ): void {
		if ( self::rank( $new_privacy ) > self::rank( '' === $old_privacy ? 'public' : $old_privacy ) ) {
			self::queue( $media_id );
		}
	}

	/**
	 * Rotate when an approved item stops being approved (flagged, rejected,
	 * sent back to pending). An item that was never approved was never shown to
	 * anyone else, so it has no URLs out there to revoke.
	 *
	 * @param int    $media_id   Media id.
	 * @param string $status     New moderation status.
	 * @param string $old_status Old moderation status.
	 * @return void
	 */
	public static function on_moderation_changed( int $media_id, string $status, string $old_status = '' ): void {
		if ( ModerationService::STATUS_APPROVED !== $status && in_array( $old_status, array( '', ModerationService::STATUS_APPROVED ), true ) ) {
			self::queue( $media_id );
		}
	}

	/**
	 * Rename every local, random-named file of one media item to a new stem and
	 * update its stored paths and URLs.
	 *
	 * Files on a cloud driver and files with readable (legacy) names are left
	 * alone: neither is ever served directly, so there is nothing to revoke. If a
	 * rename fails part-way, the files already renamed for that stem are moved
	 * back and the stored paths are left as they were, so the item never points
	 * at a file that is not there.
	 *
	 * @param int $media_id Media id.
	 * @return void
	 */
	public static function rotate( int $media_id ): void {
		$container = Plugin::container();
		if ( ! ( $container->get( 'storage' )->get_driver_for_location( $media_id ) instanceof LocalDriver ) ) {
			return;
		}
		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return;
		}
		$base = trailingslashit( $upload['basedir'] ) . 'wpmediaverse/';

		/** @var MediaRepository $repo */
		$repo = $container->get( 'media_repository' );

		// One entry per (folder, stem): the original's set and the poster's set.
		$stems = array();
		foreach ( $repo->stored_file_paths( $media_id ) as $path ) {
			if ( '/' === $path[0] || ! DirectDelivery::is_random_name( $path ) ) {
				continue;
			}
			$stem                        = (string) strtok( basename( $path ), '-.' );
			$dir                         = dirname( $path );
			$stems[ $dir . '/' . $stem ] = array( '.' === $dir ? '' : trailingslashit( $dir ), $stem );
		}

		foreach ( $stems as list( $dir, $stem ) ) {
			$new   = bin2hex( random_bytes( 8 ) );
			$moved = array();
			$files = glob( $base . $dir . $stem . '*' );
			foreach ( false === $files ? array() : $files as $file ) {
				if ( 1 !== preg_match( '/^' . $stem . '(-[0-9]+x[0-9]+)?\.[a-z0-9]+$/i', basename( $file ) ) ) {
					continue;
				}
				$target = dirname( $file ) . '/' . $new . substr( basename( $file ), strlen( $stem ) );
				if ( ! @rename( $file, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- failure handled below.
					foreach ( $moved as $from => $to ) {
						@rename( $to, $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- best-effort rollback.
					}
					LoggerService::warning( 'storage', sprintf( 'Could not rename files of media #%d; kept the old names', $media_id ), array( 'media_id' => $media_id ) );
					continue 2;
				}
				$moved[ $file ] = $target;
			}
			if ( array() !== $moved ) {
				$repo->replace_in_file_paths( $media_id, $stem, $new );
			}
		}
	}

	/**
	 * How narrow a privacy is (higher = fewer viewers). Unknown values such as
	 * 'dm' and 'custom' count as the narrowest.
	 *
	 * @param string $privacy Privacy slug.
	 * @return int
	 */
	private static function rank( string $privacy ): int {
		$rank = array_search( $privacy, MediaRepository::PRIVACY_ORDER, true );
		return false === $rank ? count( MediaRepository::PRIVACY_ORDER ) : (int) $rank;
	}
}
