<?php
/**
 * Fair-use storage limit per member.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Services;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * One optional storage allowance per member, counting every file type.
 *
 * A fair-use guard against one account filling the server, not a product
 * tier: the default is no limit (0). The owner sets a site limit in MB on
 * Settings > General, and can give one member a different limit on their
 * wp-admin profile (blank there means "use the site limit", 0 means "no
 * limit for this member").
 *
 * Usage is the sum of the member's file sizes, read live, so deleting or
 * trashing an upload frees its space at once. Replaces Pro's quota packages,
 * per-type counts and credits (2.6.0).
 *
 * @since 2.6.0
 */
class StorageLimitService {

	/** Site limit in MB; 0 = no limit. */
	public const OPTION = 'mvs_storage_limit_mb';

	/** Per-member limit in MB; absent or '' = the site limit, 0 = no limit. */
	public const USER_META = 'mvs_storage_limit_mb';

	/** Post meta on a chat attachment: its size in bytes, counted as the sender's. */
	public const DM_SIZE_META = '_mvs_dm_file_size';

	/** Object-cache group for per-member usage. */
	private const CACHE_GROUP = 'mvs_storage';

	/**
	 * Hook cache invalidation.
	 */
	public function init(): void {
		foreach ( array( 'mvs_media_uploaded', 'mvs_media_deleted', 'mvs_media_trashed', 'mvs_media_restored' ) as $hook ) {
			add_action( $hook, array( $this, 'forget_usage_for_media' ), 10, 2 );
		}
		add_action( 'delete_attachment', array( $this, 'forget_usage_for_attachment' ) );

		// Every media path applies this filter before storing anything: a
		// new upload, a file replacement (file_size is then the byte delta)
		// and Pro's document ingest.
		add_filter( 'mvs_upload_args', array( $this, 'filter_upload_args' ), 10, 2 );
	}

	/**
	 * The member's limit in bytes, 0 when there is none.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public function limit_bytes( int $user_id ): int {
		$own = $user_id ? get_user_meta( $user_id, self::USER_META, true ) : '';
		$mb  = ( '' !== $own && is_numeric( $own ) ) ? (float) $own : (float) get_option( self::OPTION, 0 );

		/**
		 * Filters a member's storage limit in bytes (0 = no limit).
		 *
		 * The seam for a site that wants limits from somewhere else, such as a
		 * membership plugin.
		 *
		 * @since 2.6.0
		 *
		 * @param int $bytes   Limit in bytes.
		 * @param int $user_id Member.
		 */
		return max( 0, (int) apply_filters( 'mvs_storage_limit_bytes', (int) round( max( 0.0, $mb ) * MB_IN_BYTES ), $user_id ) );
	}

	/**
	 * Bytes the member's files take now.
	 *
	 * @param int $user_id Member.
	 * @return int
	 */
	public function used_bytes( int $user_id ): int {
		if ( ! $user_id ) {
			return 0;
		}
		$cached = wp_cache_get( (string) $user_id, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}
		global $wpdb;
		$used  = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->storage_used_by( $user_id );
		$used += (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COALESCE( SUM( pm.meta_value ), 0 ) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_author = %d",
				self::DM_SIZE_META,
				$user_id
			)
		);
		wp_cache_set( (string) $user_id, $used, self::CACHE_GROUP, HOUR_IN_SECONDS );
		return $used;
	}

	/**
	 * Refuse an upload that would take the member past their limit.
	 *
	 * @param int $user_id Member.
	 * @param int $bytes   Size of the incoming file.
	 * @return WP_Error|null Refusal, or null to allow.
	 */
	public function check( int $user_id, int $bytes ): ?WP_Error {
		$limit = $this->limit_bytes( $user_id );
		if ( ! $limit || user_can( $user_id, 'manage_mvs_settings' ) ) {
			return null;
		}

		$used = $this->used_bytes( $user_id );
		if ( $used + max( 0, $bytes ) <= $limit ) {
			return null;
		}

		return new WP_Error(
			'mvs_storage_limit',
			sprintf(
				/* translators: 1: storage used, 2: storage limit, e.g. "48 MB". */
				__( 'You have used %1$s of %2$s. Delete something to upload more.', 'wpmediaverse' ),
				size_format( $used, 1 ),
				size_format( $limit, 1 )
			),
			array(
				'status' => 413,
				'used'   => $used,
				'limit'  => $limit,
			)
		);
	}

	/**
	 * Refuse an upload over the limit (mvs_upload_args).
	 *
	 * @param array|WP_Error $args    Upload arguments.
	 * @param int            $user_id Uploader.
	 * @return array|WP_Error
	 */
	public function filter_upload_args( $args, $user_id = 0 ) {
		if ( is_wp_error( $args ) || ! is_array( $args ) ) {
			return $args;
		}
		$bytes = (int) ( $args['file_size'] ?? 0 );
		if ( $bytes <= 0 ) {
			return $args; // A replacement that shrinks the file never needs room.
		}
		$refusal = $this->check( (int) $user_id, $bytes );
		return $refusal ? $refusal : $args;
	}

	/**
	 * Usage summary for the member (REST, templates).
	 *
	 * @param int $user_id Member.
	 * @return array{used:int, limit:int}
	 */
	public function summary( int $user_id ): array {
		return array(
			'used'  => $this->used_bytes( $user_id ),
			'limit' => $this->limit_bytes( $user_id ),
		);
	}

	/**
	 * Drop a member's cached usage.
	 *
	 * @param int $user_id Member.
	 */
	public function forget_usage( int $user_id ): void {
		wp_cache_delete( (string) $user_id, self::CACHE_GROUP );
	}

	/**
	 * Drop the cached usage when a chat attachment is deleted.
	 *
	 * @param int $post_id Attachment id.
	 */
	public function forget_usage_for_attachment( $post_id ): void {
		if ( get_post_meta( (int) $post_id, self::DM_SIZE_META, true ) ) {
			$this->forget_usage( (int) get_post_field( 'post_author', (int) $post_id ) );
		}
	}

	/**
	 * Drop the cached usage of a media item's owner.
	 *
	 * The trash, restore and delete actions pass the author second (on delete
	 * the row is already gone); the upload action passes its data array there,
	 * and the row exists, so the author is read from it.
	 *
	 * @param mixed $media_id Media id from the lifecycle action.
	 * @param mixed $second   Author id, or the upload data array.
	 */
	public function forget_usage_for_media( $media_id, $second = null ): void {
		$author = is_numeric( $second )
			? (int) $second
			: (int) \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( (int) $media_id );
		if ( $author ) {
			$this->forget_usage( $author );
		}
	}
}
