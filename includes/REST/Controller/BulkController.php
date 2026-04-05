<?php
/**
 * Bulk operations REST controller.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\Core\Plugin;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Repository\MediaRepository;

/**
 * REST controller for bulk media operations.
 */
class BulkController extends WP_REST_Controller {

	/**
	 * REST API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * REST API base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'media/bulk';

	/**
	 * Register routes.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_bulk' ),
				'permission_callback' => array( $this, 'bulk_permissions_check' ),
				'args'                => array(
					'action'    => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => array( 'delete', 'move_to_album', 'change_privacy' ),
					),
					'media_ids' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'integer' ),
					),
					'album_id'  => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'privacy'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Handle bulk operations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_bulk( $request ) {
		$rate_check = RateLimiter::check( 'bulk_action', 10, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$action    = $request->get_param( 'action' );
		$media_ids = array_map( 'absint', $request->get_param( 'media_ids' ) );
		$user_id   = get_current_user_id();

		if ( empty( $media_ids ) ) {
			return new WP_Error( 'mvs_no_ids', __( 'No media IDs provided.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		if ( count( $media_ids ) > 100 ) {
			return new WP_Error( 'mvs_too_many', __( 'Maximum 100 items per bulk operation.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		// Filter to items the user can modify.
		$allowed_ids = $this->filter_allowed_ids( $media_ids, $user_id );

		switch ( $action ) {
			case 'delete':
				return $this->bulk_delete( $allowed_ids );

			case 'move_to_album':
				$album_id = $request->get_param( 'album_id' );
				if ( ! $album_id ) {
					return new WP_Error( 'mvs_missing_album', __( 'album_id is required for move_to_album.', 'wpmediaverse' ), array( 'status' => 400 ) );
				}
				return $this->bulk_move_to_album( $allowed_ids, $album_id );

			case 'change_privacy':
				$privacy = $request->get_param( 'privacy' );
				if ( ! $privacy ) {
					return new WP_Error( 'mvs_missing_privacy', __( 'privacy is required for change_privacy.', 'wpmediaverse' ), array( 'status' => 400 ) );
				}
				return $this->bulk_change_privacy( $allowed_ids, $privacy );

			default:
				return new WP_Error( 'mvs_invalid_action', __( 'Invalid bulk action.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}
	}

	/**
	 * Bulk delete media items.
	 *
	 * @param int[] $media_ids Media IDs.
	 * @return WP_REST_Response
	 */
	private function bulk_delete( array $media_ids ): WP_REST_Response {
		global $wpdb;
		$deleted = 0;

		foreach ( $media_ids as $media_id ) {
			$author_id = MediaRepository::get_author( $media_id );
			$file_path = MediaRepository::get( $media_id, 'file_path' );

			// Delete stored file.
			if ( $file_path ) {
				$storage = Plugin::container()->get( 'storage' );
				$storage->get_driver()->delete( $file_path );
			}

			// Delete from custom tables.
			MediaRepository::delete_all( $media_id );
			$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'mvs_reactions', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'mvs_favorites', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'mvs_album_items', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			++$deleted;
			do_action( 'mvs_media_deleted', $media_id, $author_id );
		}

		return rest_ensure_response(
			array(
				'action'    => 'delete',
				'processed' => $deleted,
				'total'     => count( $media_ids ),
			)
		);
	}

	/**
	 * Bulk move items to album.
	 *
	 * @param int[] $media_ids Media IDs.
	 * @param int   $album_id  Target album ID.
	 * @return WP_REST_Response|WP_Error
	 */
	private function bulk_move_to_album( array $media_ids, int $album_id ) {
		$album = get_post( $album_id );
		if ( ! $album || 'mvs_album' !== $album->post_type ) {
			return new WP_Error( 'mvs_not_found', __( 'Album not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		// Verify the current user owns or can edit the target album.
		$album_user_id = get_current_user_id();
		if ( (int) $album->post_author !== $album_user_id && ! current_user_can( 'edit_others_mvs_medias' ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have permission to add items to this album.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}

		global $wpdb;

		$max_pos = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT MAX(position) FROM {$wpdb->prefix}mvs_album_items WHERE album_id = %d",
				$album_id
			)
		);

		$added = 0;
		foreach ( $media_ids as $media_id ) {
			++$max_pos;
			$result = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prefix . 'mvs_album_items',
				array(
					'album_id' => $album_id,
					'media_id' => $media_id,
					'position' => $max_pos,
					'added_at' => current_time( 'mysql', true ),
				),
				array( '%d', '%d', '%d', '%s' )
			);
			if ( false !== $result ) {
				++$added;
			}
		}

		return rest_ensure_response(
			array(
				'action'    => 'move_to_album',
				'album_id'  => $album_id,
				'processed' => $added,
				'total'     => count( $media_ids ),
			)
		);
	}

	/**
	 * Bulk change privacy level.
	 *
	 * @param int[]  $media_ids Media IDs.
	 * @param string $privacy   Privacy level.
	 * @return WP_REST_Response
	 */
	private function bulk_change_privacy( array $media_ids, string $privacy ): WP_REST_Response {
		global $wpdb;
		$updated = 0;

		foreach ( $media_ids as $media_id ) {
			MediaRepository::set( $media_id, 'privacy', $privacy );
			++$updated;
		}

		return rest_ensure_response(
			array(
				'action'    => 'change_privacy',
				'privacy'   => $privacy,
				'processed' => $updated,
				'total'     => count( $media_ids ),
			)
		);
	}

	/**
	 * Filter media IDs to only those the user can modify.
	 *
	 * @param int[] $media_ids Media IDs.
	 * @param int   $user_id   Current user ID.
	 * @return int[]
	 */
	private function filter_allowed_ids( array $media_ids, int $user_id ): array {
		$can_edit_others = current_user_can( 'edit_others_mvs_medias' );

		return array_filter(
			$media_ids,
			function ( $media_id ) use ( $user_id, $can_edit_others ) {
				if ( ! MediaRepository::exists( $media_id ) ) {
					return false;
				}
				return $can_edit_others || MediaRepository::get_author( $media_id ) === $user_id;
			}
		);
	}

	/**
	 * Permissions: bulk operations require login and basic edit capability.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public function bulk_permissions_check( $request ) {
		if ( ! current_user_can( 'edit_mvs_medias' ) ) {
			return new WP_Error( 'mvs_forbidden', __( 'You do not have permission for bulk operations.', 'wpmediaverse' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
