<?php
/**
 * Give a media item's files new random names when fewer people may see it.
 *
 * With direct delivery (DirectDelivery) a file's own URL works for whoever
 * holds it. When an item's privacy is tightened, or it is trashed, flagged or
 * rejected, everyone who saw it earlier may still have that URL. Renaming the
 * files to a new random stem makes every earlier URL a 404, which is how a
 * capability URL is revoked.
 *
 * It also converts files saved before 2.6.1 under readable names (video covers
 * named after the media id, imports, uploads kept under their own names) to
 * random names, once, in a newest-first background job, so they can be served
 * directly too. Old /serve links keep working: they find the file by media id.
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
	 * Background hook for one batch of the legacy-name conversion.
	 */
	public const LEGACY_HOOK = 'mvs_convert_legacy_media_names';

	/**
	 * Conversion state: array{cursor: int, converted: int, done: bool}.
	 */
	public const LEGACY_OPTION = 'mvs_legacy_names';

	/**
	 * Items per conversion batch.
	 */
	private const LEGACY_BATCH = 50;

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
		add_action( self::LEGACY_HOOK, array( self::class, 'run_legacy_batch' ) );
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
	 * update its stored paths and URLs (revocation).
	 *
	 * Files on a cloud driver and files with readable (legacy) names are left
	 * alone here: neither is ever served directly, so there is nothing to revoke.
	 *
	 * @param int $media_id Media id.
	 * @return void
	 */
	public static function rotate( int $media_id ): void {
		$sets = array();
		foreach ( self::local_paths( $media_id ) as $path ) {
			if ( DirectDelivery::is_random_name( $path ) ) {
				$dir                        = self::dir_of( $path );
				$stem                       = (string) strtok( basename( $path ), '-.' );
				$sets[ $dir . '|' . $stem ] = array( $dir, $stem, $dir );
			}
		}
		self::move_sets( $media_id, $sets );

		/**
		 * Fires after an item's links were revoked (privacy tightened, or an
		 * approved item taken down), so add-ons that keep their own files for
		 * the item (captions, transcripts) can move them to new names too.
		 * Fires even when no media file moved: the item's own files may live on
		 * a cloud driver while the add-on's files are local.
		 *
		 * @since 2.6.1
		 *
		 * @param int $media_id Media id.
		 */
		do_action( 'mvs_media_files_rotated', $media_id );
	}

	/**
	 * Give one item's readable-named media files random names.
	 *
	 * Two legacy shapes: the original (and its sizes) under its own name, and a
	 * video cover named after the media id in the flat posters/ folder, which
	 * moves into posters/YYYY/MM/ like new covers. Only media file types;
	 * documents and anything else keep their names. The original name is kept
	 * in original_filename so downloads still offer it.
	 *
	 * @param int $media_id Media id.
	 * @return bool Whether anything was renamed.
	 */
	public static function convert_legacy( int $media_id ): bool {
		$paths = self::local_paths( $media_id );
		if ( array() === $paths ) {
			return false;
		}
		$repo     = Plugin::container()->get( 'media_repository' );
		$original = (string) $repo->get_raw( $media_id, 'file_path' );
		$sets     = array();
		foreach ( $paths as $path ) {
			if ( DirectDelivery::is_random_name( $path ) || ! self::is_media_file( $path ) ) {
				continue;
			}
			$dir = self::dir_of( $path );
			if ( 'posters/' === $dir && 1 === preg_match( '/^' . $media_id . '(-[0-9]+x[0-9]+)?\./', basename( $path ) ) ) {
				$sets['poster'] = array( $dir, (string) $media_id, 'posters/' . gmdate( 'Y/m' ) . '/' );
			} elseif ( $path === $original ) {
				// Readable names are not unique to one item ('my-photo-300x200.jpg'
				// may be another upload's original), so only the files this item's
				// own meta records are renamed; posters/<id>* belongs to one id.
				$sets['original'] = array( $dir, (string) pathinfo( $path, PATHINFO_FILENAME ), $dir, $paths );
			}
		}
		if ( array() === $sets ) {
			return false;
		}
		if ( isset( $sets['original'] ) && '' === (string) $repo->get_raw( $media_id, 'original_filename' ) ) {
			$repo->set( $media_id, 'original_filename', basename( $original ) );
		}
		$moved = self::move_sets( $media_id, $sets );
		// Pre-2.6.1 covers never recorded their source image (posters/<id>.jpg);
		// record it at its new place so deleting the video removes it too.
		foreach ( $moved as $from => $to ) {
			if ( 1 === preg_match( '~^posters/' . $media_id . '\.[a-z0-9]+$~i', $from ) ) {
				$repo->set( $media_id, 'poster_source_path', $to );
			}
		}
		return array() !== $moved;
	}

	/**
	 * Start the legacy-name conversion when it has not finished. Only from admin
	 * page loads and cron (the same gate as the delivery probe), and only when
	 * no batch is already waiting.
	 *
	 * @return void
	 */
	public static function maybe_start_legacy(): void {
		$state = get_option( self::LEGACY_OPTION, array() );
		if ( ! empty( $state['done'] ) || ! DirectDelivery::is_scheduling_request() ) {
			return;
		}
		$queued = function_exists( 'as_has_scheduled_action' )
			? as_has_scheduled_action( self::LEGACY_HOOK, null, 'wpmediaverse' )
			: (bool) wp_next_scheduled( self::LEGACY_HOOK );
		if ( ! $queued ) {
			self::schedule_legacy_batch();
		}
	}

	/**
	 * Convert one batch, newest media first, then queue the next or finish.
	 *
	 * Newest first because feeds show recent posts: the media people actually
	 * open is converted in the first minutes, the long tail follows quietly.
	 *
	 * @return void
	 */
	public static function run_legacy_batch(): void {
		$state = wp_parse_args(
			(array) get_option( self::LEGACY_OPTION, array() ),
			array(
				'cursor'    => 0,
				'converted' => 0,
				'done'      => false,
			)
		);
		if ( $state['done'] ) {
			return;
		}
		$ids = Plugin::container()->get( 'media_repository' )->media_ids_before( (int) $state['cursor'], self::LEGACY_BATCH );
		foreach ( $ids as $id ) {
			if ( self::convert_legacy( $id ) ) {
				++$state['converted'];
			}
		}
		$state['cursor'] = array() === $ids ? (int) $state['cursor'] : min( $ids );
		$state['done']   = count( $ids ) < self::LEGACY_BATCH;
		update_option( self::LEGACY_OPTION, $state, true );
		if ( ! $state['done'] ) {
			self::schedule_legacy_batch();
		}
	}

	/**
	 * Queue one conversion batch (Action Scheduler, else WP-Cron).
	 *
	 * @return void
	 */
	private static function schedule_legacy_batch(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::LEGACY_HOOK, array(), 'wpmediaverse' );
		} else {
			wp_schedule_single_event( time(), self::LEGACY_HOOK );
		}
	}

	/**
	 * Stored relative paths of a local media item ('' for a cloud item).
	 *
	 * @param int $media_id Media id.
	 * @return string[]
	 */
	private static function local_paths( int $media_id ): array {
		$container = Plugin::container();
		if ( ! ( $container->get( 'storage' )->get_driver_for_location( $media_id ) instanceof LocalDriver ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$container->get( 'media_repository' )->get_stored_file_paths( $media_id ),
				static fn( $path ) => '/' !== $path[0]
			)
		);
	}

	/**
	 * Folder part of a relative path, with trailing slash ('' at the root).
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function dir_of( string $path ): string {
		$dir = dirname( $path );
		return '.' === $dir ? '' : trailingslashit( $dir );
	}

	/**
	 * Whether a path has one of the media extensions direct delivery serves.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private static function is_media_file( string $path ): bool {
		return 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|mp4|m4v|mov|webm|ogv|mp3|m4a|ogg|oga|opus|wav|flac|aac)$/i', $path );
	}

	/**
	 * Rename file sets to new random stems and point the item's stored paths at
	 * them. A set is every file in one folder named `<stem>[-WxH].<ext>`: the
	 * file itself, its sizes and WebP/AVIF siblings, and (for covers) the source
	 * image that no meta records. A set may limit itself to listed paths. If a rename fails part-way, the set's files
	 * already moved are moved back and its paths are left as they were, so the
	 * item never points at a file that is not there.
	 *
	 * @param int                  $media_id Media id.
	 * @param array<string, array> $sets [ folder, stem, target folder, optional list of the only relative paths allowed ].
	 * @return array<string, string> Old relative path => new relative path, for every file moved.
	 */
	private static function move_sets( int $media_id, array $sets ): array {
		$upload = wp_upload_dir();
		if ( array() === $sets || ! empty( $upload['error'] ) ) {
			return array();
		}
		$base = trailingslashit( $upload['basedir'] ) . 'wpmediaverse/';
		$map  = array();
		foreach ( $sets as $set ) {
			list( $dir, $stem, $target_dir ) = $set;
			$only                            = $set[3] ?? null;
			$new                             = bin2hex( random_bytes( 8 ) );
			$moved                           = array();
			$files                           = glob( $base . $dir . addcslashes( $stem, '*?[]\\' ) . '*' );
			if ( $target_dir !== $dir && ! wp_mkdir_p( $base . $target_dir ) ) {
				continue;
			}
			foreach ( false === $files ? array() : $files as $file ) {
				if ( null !== $only && ! in_array( $dir . basename( $file ), $only, true ) ) {
					continue;
				}
				$suffix = substr( basename( $file ), strlen( $stem ) );
				if ( 1 !== preg_match( '/^(-[0-9]+x[0-9]+)?\.[a-z0-9]+$/i', $suffix ) ) {
					continue;
				}
				$to = $target_dir . $new . $suffix;
				if ( ! @rename( $file, $base . $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- failure handled below.
					foreach ( $moved as $from => $back ) {
						@rename( $base . $back, $base . $from ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- best-effort rollback.
					}
					LoggerService::warning( 'storage', sprintf( 'Could not rename files of media #%d; kept the old names', $media_id ), array( 'media_id' => $media_id ) );
					continue 2;
				}
				$moved[ $dir . basename( $file ) ] = $to;
			}
			$map = array_merge( $map, $moved );
		}
		if ( array() !== $map ) {
			Plugin::container()->get( 'media_repository' )->replace_file_paths( $media_id, $map );
		}
		return $map;
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
