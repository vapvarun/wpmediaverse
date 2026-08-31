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
						'enum'     => array( 'delete', 'move_to_album', 'change_privacy', 'add_tags' ),
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
					'tags'      => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
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
		$requested   = count( $media_ids );
		$allowed_ids = $this->filter_allowed_ids( $media_ids, $user_id );

		switch ( $action ) {
			case 'delete':
				$response = $this->bulk_delete( $allowed_ids );
				break;

			case 'move_to_album':
				$album_id = $request->get_param( 'album_id' );
				if ( ! $album_id ) {
					return new WP_Error( 'mvs_missing_album', __( 'album_id is required for move_to_album.', 'wpmediaverse' ), array( 'status' => 400 ) );
				}
				$response = $this->bulk_move_to_album( $allowed_ids, $album_id );
				break;

			case 'change_privacy':
				$privacy = $request->get_param( 'privacy' );
				if ( ! $privacy ) {
					return new WP_Error( 'mvs_missing_privacy', __( 'privacy is required for change_privacy.', 'wpmediaverse' ), array( 'status' => 400 ) );
				}
				$response = $this->bulk_change_privacy( $allowed_ids, $privacy );
				break;

			case 'add_tags':
				$tags = $request->get_param( 'tags' );
				if ( ! is_array( $tags ) || ! array_filter( array_map( 'trim', array_map( 'strval', $tags ) ) ) ) {
					return new WP_Error( 'mvs_missing_tags', __( 'tags is required for add_tags.', 'wpmediaverse' ), array( 'status' => 400 ) );
				}
				$response = $this->bulk_add_tags( $allowed_ids, $tags );
				break;

			default:
				return new WP_Error( 'mvs_invalid_action', __( 'Invalid bulk action.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return $this->with_counts( $response, $requested );
	}

	/**
	 * Say how many items the caller asked for, not just how many we touched.
	 *
	 * `filter_allowed_ids()` silently drops anything the member may not edit,
	 * and every handler then reported `total` as the count of what SURVIVED
	 * that filter. Select twelve, own four, and the response was
	 * `processed: 4, total: 4` — an unqualified success for an operation that
	 * ignored two thirds of the request. That is the same "accepted and never
	 * applied" shape as the space-privacy and categories bugs, and a client
	 * cannot detect it because nothing in the payload disagrees.
	 *
	 * The UI is capped to selectable-and-owned items, so in practice `skipped`
	 * should be 0 — which is exactly why it must be reported rather than
	 * assumed. A number that is always zero costs nothing; a silent drop costs
	 * a member their trust in the button.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Response|WP_Error $response  Handler result.
	 * @param int                       $requested How many ids the caller sent.
	 * @return WP_REST_Response|WP_Error
	 */
	private function with_counts( $response, int $requested ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = (array) $response->get_data();

		$data['requested'] = $requested;
		$data['skipped']   = max( 0, $requested - (int) ( $data['processed'] ?? 0 ) );

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Add tags to every selected item, KEEPING the tags already there.
	 *
	 * Append, not replace, and the action is named Add Tags for the same
	 * reason: applying one tag to two hundred items must not silently wipe
	 * every other tag those items carried. There is no bulk remove — taking
	 * tags away in bulk is a separate, more dangerous action and deserves its
	 * own decision rather than being the accidental side effect of this one.
	 *
	 * @since 2.4.0
	 *
	 * @param int[]    $media_ids Media IDs.
	 * @param string[] $tags      Tag names.
	 * @return WP_REST_Response
	 */
	private function bulk_add_tags( array $media_ids, array $tags ): WP_REST_Response {
		$clean = array_values(
			array_unique(
				array_filter(
					array_map(
						static function ( $tag ) {
							return sanitize_text_field( trim( (string) $tag ) );
						},
						$tags
					),
					// Not `'strlen'` (PHPStan: needs a bool-returning callable) and
					// not a bare array_filter, which would drop a tag literally
					// named "0". This keeps exactly the old behaviour.
					static function ( $tag ) {
						return '' !== $tag;
					}
				)
			)
		);

		$repo    = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' );
		$updated = 0;

		foreach ( $media_ids as $media_id ) {
			// `true` is the append flag. Without it this is a replace, which is
			// the destructive version of the same call.
			$result = wp_set_object_terms( $media_id, $clean, 'mvs_tag', true );

			if ( is_wp_error( $result ) ) {
				continue;
			}

			// Re-read rather than merge in PHP: the cached list must match the
			// taxonomy exactly, and the taxonomy is what just decided how the
			// names resolved (existing terms, slug collisions, capitalisation).
			$names = wp_get_object_terms( $media_id, 'mvs_tag', array( 'fields' => 'names' ) );

			if ( ! is_wp_error( $names ) ) {
				$repo->set( $media_id, 'tags', wp_json_encode( array_values( $names ) ) );
			}

			++$updated;
		}

		return rest_ensure_response(
			array(
				'action'    => 'add_tags',
				'tags'      => $clean,
				'processed' => $updated,
				'total'     => count( $media_ids ),
			)
		);
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
			$file_path = \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get( $media_id, 'file_path' );

			// Delete stored file.
			if ( $file_path ) {
				$storage = Plugin::container()->get( 'storage' );
				$storage->get_driver()->delete( $file_path );
			}

			// Delete from custom tables.
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->delete_all( $media_id );
			$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'mvs_reactions', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'mvs_favorites', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->prefix . 'mvs_album_items', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			++$deleted;
			// mvs_media_deleted is fired inside delete_cascade() (the single
			// funnel) — not fired here to avoid a double-fire (audit 2026-06-04).
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
			\WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->set( $media_id, 'privacy', $privacy );
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
				if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
					return false;
				}
				return $can_edit_others || \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->get_author( $media_id ) === $user_id;
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
