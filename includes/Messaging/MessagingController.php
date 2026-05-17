<?php
/**
 * REST controller for messaging.
 *
 * @package WPMediaVerse
 * @since   1.1.0
 */

namespace WPMediaVerse\Messaging;

defined( 'ABSPATH' ) || exit;

use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class MessagingController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var MessagingService
	 */
	private $service;

	/**
	 * @var TransportInterface
	 */
	private $transport;

	/**
	 * Constructor.
	 *
	 * @param MessagingService   $service   Messaging service.
	 * @param TransportInterface $transport Transport adapter.
	 */
	public function __construct( MessagingService $service, TransportInterface $transport ) {
		$this->service   = $service;
		$this->transport = $transport;
	}

	/**
	 * Register routes.
	 */
	public function register_routes(): void {

		// GET /me/conversations
		register_rest_route(
			$this->namespace,
			'/me/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_conversations' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'tab'      => array(
						'type'    => 'string',
						'default' => 'all',
						'enum'    => array( 'all', 'unread', 'requests' ),
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 50,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);

		// POST /conversations
		register_rest_route(
			$this->namespace,
			'/conversations',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_conversation' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'recipient_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// GET /conversations/{id}
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conversation' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// PATCH /conversations/{id}
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_conversation' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// DELETE /conversations/{id}
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_conversation' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// GET /conversations/{id}/messages
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_messages' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'before'   => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		// POST /conversations/{id}/messages
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)/messages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_message' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'content'       => array(
						'type'    => 'string',
						'default' => '',
					),
					'message_type'  => array(
						'type'    => 'string',
						'default' => 'text',
					),
					'attachment_id' => array( 'type' => 'integer' ),
					'media_id'      => array( 'type' => 'integer' ),
					'parent_id'     => array( 'type' => 'integer' ),
					'metadata'      => array( 'type' => 'object' ),
				),
			)
		);

		// DELETE /messages/{id}
		register_rest_route(
			$this->namespace,
			'/messages/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_message' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// DELETE /messages/{id}/unsend
		register_rest_route(
			$this->namespace,
			'/messages/(?P<id>\d+)/unsend',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'unsend_message' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// POST /conversations/{id}/read
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// POST /conversations/{id}/typing
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)/typing',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'typing' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// POST /messages/{id}/reactions
		register_rest_route(
			$this->namespace,
			'/messages/(?P<id>\d+)/reactions',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_reaction' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'emoji' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		// DELETE /messages/{id}/reactions
		register_rest_route(
			$this->namespace,
			'/messages/(?P<id>\d+)/reactions',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'remove_reaction' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// GET /messages/poll
		register_rest_route(
			$this->namespace,
			'/messages/poll',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => array( $this, 'check_auth' ),
				'args'                => array(
					'since'           => array(
						'type'     => 'string',
						'required' => true,
					),
					'conversation_id' => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);

		// GET /me/messages/unread-count
		register_rest_route(
			$this->namespace,
			'/me/messages/unread-count',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'unread_count' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// POST /conversations/{id}/accept
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)/accept',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'accept_request' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// POST /conversations/{id}/decline
		register_rest_route(
			$this->namespace,
			'/conversations/(?P<id>\d+)/decline',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'decline_request' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);

		// POST /messages/upload — file upload for DM attachments (any logged-in user).
		register_rest_route(
			$this->namespace,
			'/messages/upload',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload_attachment' ),
				'permission_callback' => array( $this, 'check_auth' ),
			)
		);
	}

	/**
	 * Permission callback: require logged-in user.
	 *
	 * @return bool|WP_Error
	 */
	public function check_auth() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', 'Authentication required.', array( 'status' => 401 ) );
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Conversation endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /me/conversations
	 */
	public function list_conversations( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$tab     = $request->get_param( 'tab' );
		$results = $this->service->get_conversations(
			$user_id,
			$tab,
			(int) $request->get_param( 'per_page' ),
			(int) $request->get_param( 'page' )
		);

		return new WP_REST_Response( $results, 200 );
	}

	/**
	 * POST /conversations
	 */
	public function create_conversation( WP_REST_Request $request ): WP_REST_Response {
		$user_id      = get_current_user_id();
		$recipient_id = (int) $request->get_param( 'recipient_id' );

		if ( ! get_userdata( $recipient_id ) ) {
			return new WP_REST_Response( array( 'error' => 'invalid_recipient' ), 400 );
		}

		// Block check: prevent DM if either user has blocked the other.
		$container = \WPMediaVerse\Core\Plugin::container();
		if ( $container->has( 'reports' ) ) {
			$report_svc = $container->get( 'reports' );
			if ( $report_svc->is_blocked_either_way( $user_id, $recipient_id ) ) {
				return new WP_REST_Response( array( 'error' => 'blocked' ), 403 );
			}
		}

		$result = $this->service->find_or_create_conversation( $user_id, $recipient_id );

		if ( ! $result['conversation_id'] ) {
			$status = 'rate_limited' === $result['status'] ? 429 : 403;
			return new WP_REST_Response( array( 'error' => $result['status'] ), $status );
		}

		// Return full conversation data.
		$conv = $this->service->get_conversation( $result['conversation_id'], $user_id );

		return new WP_REST_Response(
			array(
				'conversation' => $conv,
				'created'      => $result['created'],
			),
			$result['created'] ? 201 : 200
		);
	}

	/**
	 * GET /conversations/{id}
	 */
	public function get_conversation( WP_REST_Request $request ): WP_REST_Response {
		$conv = $this->service->get_conversation( (int) $request['id'], get_current_user_id() );

		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		return new WP_REST_Response( $conv, 200 );
	}

	/**
	 * PATCH /conversations/{id}
	 */
	public function update_conversation( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$conv_id = (int) $request['id'];

		// Verify access.
		$conv = $this->service->get_conversation( $conv_id, $user_id );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$data = array();
		$json = $request->get_json_params();

		if ( isset( $json['is_muted'] ) ) {
			$data['is_muted'] = (int) (bool) $json['is_muted'];
			if ( $data['is_muted'] && isset( $json['muted_until'] ) ) {
				$data['muted_until'] = sanitize_text_field( $json['muted_until'] );
			} elseif ( ! $data['is_muted'] ) {
				$data['muted_until'] = null;
			}
		}

		if ( isset( $json['is_pinned'] ) ) {
			$data['is_pinned'] = (int) (bool) $json['is_pinned'];
		}

		if ( isset( $json['is_archived'] ) ) {
			$data['is_archived'] = (int) (bool) $json['is_archived'];
		}

		$result = $this->service->update_participant( $conv_id, $user_id, $data );

		if ( ! $result ) {
			return new WP_REST_Response( array( 'error' => 'update_failed' ), 400 );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * DELETE /conversations/{id}
	 */
	public function delete_conversation( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->leave_conversation( (int) $request['id'], get_current_user_id() );

		if ( ! $result ) {
			return new WP_REST_Response( array( 'error' => 'delete_failed' ), 400 );
		}

		return new WP_REST_Response( null, 204 );
	}

	// -------------------------------------------------------------------------
	// Message endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /conversations/{id}/messages
	 */
	public function list_messages( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$conv_id = (int) $request['id'];

		// Verify access.
		$conv = $this->service->get_conversation( $conv_id, $user_id );
		if ( ! $conv ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		$messages = $this->service->get_messages(
			$conv_id,
			$user_id,
			(int) $request->get_param( 'before' ),
			(int) $request->get_param( 'per_page' )
		);

		return new WP_REST_Response( $messages, 200 );
	}

	/**
	 * POST /conversations/{id}/messages
	 */
	public function send_message( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$conv_id = (int) $request['id'];

		// Block check: prevent messaging if either participant has blocked the other.
		$container = \WPMediaVerse\Core\Plugin::container();
		if ( $container->has( 'reports' ) ) {
			$report_svc   = $container->get( 'reports' );
			$participants = $this->service->get_participants( $conv_id );
			foreach ( $participants as $p ) {
				$pid = (int) ( $p['id'] ?? $p['user_id'] ?? 0 );
				if ( $pid && $pid !== $user_id && $report_svc->is_blocked_either_way( $user_id, $pid ) ) {
					return new WP_REST_Response( array( 'error' => 'blocked' ), 403 );
				}
			}
		}

		$result = $this->service->send_message(
			$conv_id,
			$user_id,
			array(
				'content'       => $request->get_param( 'content' ),
				'message_type'  => $request->get_param( 'message_type' ),
				'attachment_id' => $request->get_param( 'attachment_id' ),
				'media_id'      => $request->get_param( 'media_id' ),
				'parent_id'     => $request->get_param( 'parent_id' ),
				'metadata'      => $request->get_param( 'metadata' ),
			)
		);

		if ( ! $result['success'] ) {
			$status_map = array(
				'not_participant' => 403,
				'rate_limited'    => 429,
			);
			$status     = $status_map[ $result['error'] ] ?? 400;
			return new WP_REST_Response( array( 'error' => $result['error'] ), $status );
		}

		// Return the full message.
		$messages = $this->service->get_messages( $conv_id, $user_id, 0, 1 );
		$message  = ! empty( $messages ) ? end( $messages ) : null;

		return new WP_REST_Response( $message, 201 );
	}

	/**
	 * DELETE /messages/{id}
	 */
	public function delete_message( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->delete_message( (int) $request['id'], get_current_user_id() );

		return new WP_REST_Response(
			$result ? array( 'success' => true ) : array( 'error' => 'delete_failed' ),
			$result ? 200 : 400
		);
	}

	/**
	 * DELETE /messages/{id}/unsend
	 */
	public function unsend_message( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->unsend_message( (int) $request['id'], get_current_user_id() );

		if ( ! $result['success'] ) {
			$status_map = array(
				'not_found'      => 404,
				'not_sender'     => 403,
				'window_expired' => 410,
			);
			return new WP_REST_Response( array( 'error' => $result['error'] ), $status_map[ $result['error'] ] ?? 400 );
		}

		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /conversations/{id}/read
	 */
	public function mark_read( WP_REST_Request $request ): WP_REST_Response {
		$this->service->mark_read( (int) $request['id'], get_current_user_id() );
		// Invalidate unread cache.
		delete_transient( 'mvs_dm_unread_' . get_current_user_id() );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	/**
	 * POST /conversations/{id}/typing
	 */
	public function typing( WP_REST_Request $request ): WP_REST_Response {
		$this->service->set_typing( (int) $request['id'], get_current_user_id() );
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}

	// -------------------------------------------------------------------------
	// Reaction endpoints
	// -------------------------------------------------------------------------

	/**
	 * POST /messages/{id}/reactions
	 */
	public function add_reaction( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->add_reaction(
			(int) $request['id'],
			get_current_user_id(),
			$request->get_param( 'emoji' )
		);

		return new WP_REST_Response(
			$result ? array( 'success' => true ) : array( 'error' => 'reaction_failed' ),
			$result ? 200 : 400
		);
	}

	/**
	 * DELETE /messages/{id}/reactions
	 */
	public function remove_reaction( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->remove_reaction( (int) $request['id'], get_current_user_id() );

		return new WP_REST_Response(
			$result ? array( 'success' => true ) : array( 'error' => 'remove_failed' ),
			$result ? 200 : 400
		);
	}

	// -------------------------------------------------------------------------
	// Polling & Status endpoints
	// -------------------------------------------------------------------------

	/**
	 * GET /messages/poll
	 */
	public function poll( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();

		// Update online status.
		$this->service->update_online_status( $user_id );

		$since    = $request->get_param( 'since' );
		$conv_id  = (int) $request->get_param( 'conversation_id' );
		$messages = $this->transport->poll( $user_id, $since );

		// If polling for a specific conversation, get typing indicators.
		$typing = array();
		if ( $conv_id ) {
			$typing = $this->service->get_typing_users( $conv_id, $user_id );
		}

		// Get online status for active conversation participants.
		$online_users = array();
		if ( $conv_id ) {
			$participants = $this->service->get_participants( $conv_id );
			foreach ( $participants as $p ) {
				if ( $p['id'] !== $user_id ) {
					$online_users[ $p['id'] ] = array(
						'is_online'   => $p['is_online'],
						'last_active' => $p['last_active'],
					);
				}
			}
		}

		return new WP_REST_Response(
			array(
				'messages'     => $messages,
				'typing'       => $typing,
				'online_users' => $online_users,
				'server_time'  => gmdate( 'c' ),
			),
			200
		);
	}

	/**
	 * GET /me/messages/unread-count
	 */
	public function unread_count( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$this->service->update_online_status( $user_id );

		return new WP_REST_Response(
			array(
				'unread' => $this->service->get_total_unread( $user_id ),
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Message Request endpoints
	// -------------------------------------------------------------------------

	/**
	 * POST /conversations/{id}/accept
	 */
	public function accept_request( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->accept_request( (int) $request['id'], get_current_user_id() );

		return new WP_REST_Response(
			$result ? array( 'success' => true ) : array( 'error' => 'accept_failed' ),
			$result ? 200 : 400
		);
	}

	/**
	 * POST /conversations/{id}/decline
	 */
	public function decline_request( WP_REST_Request $request ): WP_REST_Response {
		$result = $this->service->decline_request( (int) $request['id'], get_current_user_id() );

		return new WP_REST_Response(
			$result ? array( 'success' => true ) : array( 'error' => 'decline_failed' ),
			$result ? 200 : 400
		);
	}

	// -------------------------------------------------------------------------
	// File upload
	// -------------------------------------------------------------------------

	/**
	 * POST /messages/upload
	 *
	 * Allows any logged-in user to upload DM attachments (images, audio, files)
	 * without requiring the `upload_files` capability.
	 */
	public function upload_attachment( WP_REST_Request $request ): WP_REST_Response {
		$files = $request->get_file_params();

		if ( empty( $files['file'] ) ) {
			return new WP_REST_Response( array( 'message' => 'No file provided.' ), 400 );
		}

		$file = $files['file'];

		// Validate MIME type.
		$allowed = apply_filters(
			'mvs_dm_allowed_file_types',
			array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
				'audio/webm',
				'audio/mp4',
				'audio/mpeg',
				'audio/ogg',
				'video/mp4',
				'video/webm',
				'application/pdf',
			)
		);

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		// PHP 8.5 deprecated finfo_close — handle is GC'd at end of scope.

		if ( ! in_array( $real_mime, $allowed, true ) ) {
			return new WP_REST_Response( array( 'message' => 'File type not allowed.' ), 400 );
		}

		// Max 10 MB.
		$max_size = apply_filters( 'mvs_dm_max_upload_size', 10 * MB_IN_BYTES );
		if ( $file['size'] > $max_size ) {
			return new WP_REST_Response( array( 'message' => 'File too large.' ), 400 );
		}

		// Temporarily grant upload_files so wp_handle_upload works.
		$grant_cap = function ( $allcaps ) {
			$allcaps['upload_files'] = true;
			return $allcaps;
		};
		add_filter( 'user_has_cap', $grant_cap );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$upload = wp_handle_upload( $file, array( 'test_form' => false ) );

		if ( isset( $upload['error'] ) ) {
			remove_filter( 'user_has_cap', $grant_cap );
			return new WP_REST_Response( array( 'message' => $upload['error'] ), 500 );
		}

		// Create WP attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sanitize_file_name( $file['name'] ),
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			remove_filter( 'user_has_cap', $grant_cap );
			return new WP_REST_Response( array( 'message' => 'Failed to create attachment.' ), 500 );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		remove_filter( 'user_has_cap', $grant_cap );

		$thumb = wp_get_attachment_image_url( $attachment_id, 'medium' );

		return new WP_REST_Response(
			array(
				'id'         => $attachment_id,
				'source_url' => set_url_scheme( $upload['url'] ),
				'thumbnail'  => $thumb ? set_url_scheme( $thumb ) : null,
				'type'       => $upload['type'],
			),
			201
		);
	}
}
