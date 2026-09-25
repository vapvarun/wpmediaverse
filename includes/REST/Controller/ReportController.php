<?php
/**
 * Report and block REST controller.
 *
 * @package    WPMediaVerse
 * @subpackage REST
 * @since      1.1.0
 */

namespace WPMediaVerse\REST\Controller;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WPMediaVerse\REST\RateLimiter;
use WPMediaVerse\Social\ReportService;

/**
 * REST controller for reporting content/users and blocking users.
 */
class ReportController extends WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $namespace = 'mvs/v1';

	/**
	 * @var ReportService
	 */
	private $reports;

	/**
	 * Constructor.
	 *
	 * @param ReportService $reports Report service.
	 */
	public function __construct( ReportService $reports ) {
		$this->reports = $reports;
	}

	/**
	 * Register routes.
	 *
	 * @since 1.1.0
	 */
	public function register_routes(): void {
		$auth = function () {
			return is_user_logged_in();
		};

		$report_args = array(
			'id'      => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'reason'  => array(
				'type'     => 'string',
				'required' => true,
				'enum'     => ReportService::REASONS,
			),
			'details' => array(
				'type'    => 'string',
				'default' => '',
			),
		);

		// POST /{media|users|comments|messages}/{id}/report.
		$targets = array(
			'media'    => 'report_media',
			'users'    => 'report_user',
			'comments' => 'report_comment',
			'messages' => 'report_message',
		);
		foreach ( $targets as $base => $callback ) {
			register_rest_route(
				$this->namespace,
				'/' . $base . '/(?P<id>[\d]+)/report',
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, $callback ),
					'permission_callback' => $auth,
					'args'                => $report_args,
				)
			);
		}

		// POST /users/{id}/block — block.
		// DELETE /users/{id}/block — unblock.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/block',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'block_user' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unblock_user' ),
					'permission_callback' => $auth,
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// GET /me/blocked.
		register_rest_route(
			$this->namespace,
			'/me/blocked',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_blocked' ),
				'permission_callback' => $auth,
			)
		);
	}

	/**
	 * Guard the report write path.
	 *
	 * Defers to ReportService::reports_enabled() so the endpoint and the UI that
	 * posts to it read the same switch. Reporting is on by default; a site that
	 * turns it off in Settings refuses even a hand-crafted POST rather than
	 * piling up reports nobody will read.
	 *
	 * @since 1.6.0
	 *
	 * @return WP_Error|null WP_Error when reporting is disabled, null otherwise.
	 */
	private function reports_disabled() {
		if ( ReportService::reports_enabled() ) {
			return null;
		}

		return new WP_Error(
			'mvs_reports_disabled',
			__( 'Reporting is not available on this site.', 'wpmediaverse' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Report a media item.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_media( $request ) {
		$media_id = (int) $request->get_param( 'id' );

		// Only something the reporter can see: reporting a hidden item filed a
		// report and confirmed that it exists (QA, 2.6.0). Same as comments.
		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id )
			|| ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return $this->file_report( 'media', $media_id, $request );
	}

	/**
	 * Report a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_user( $request ) {
		$target_id = (int) $request->get_param( 'id' );

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error( 'mvs_user_not_found', __( 'User not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		return $this->file_report( 'user', $target_id, $request );
	}

	/**
	 * Report a comment on a media item the reporter can open.
	 *
	 * A comment the reporter cannot see answers exactly like one that does not
	 * exist. Your own comment cannot be reported (delete it instead).
	 *
	 * @since 2.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_comment( $request ) {
		$comment_id = (int) $request->get_param( 'id' );
		$comment    = get_comment( $comment_id );
		$media_id   = $comment ? \WPMediaVerse\Social\CommentService::comment_media_id( $comment_id ) : 0;

		if ( ! $comment || \WPMediaVerse\Social\CommentService::COMMENT_TYPE !== $comment->comment_type || '1' !== (string) $comment->comment_approved
			|| ! $media_id || ! \WPMediaVerse\Core\Plugin::container()->get( 'privacy' )->can_view( $media_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Comment not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $comment->user_id === get_current_user_id() ) {
			return new WP_Error( 'mvs_report_own', __( 'You cannot report your own comment.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return $this->file_report( 'comment', $comment_id, $request );
	}

	/**
	 * Report a message in a conversation the reporter is part of.
	 *
	 * @since 2.6.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_message( $request ) {
		$message_id = (int) $request->get_param( 'id' );
		$messaging  = \WPMediaVerse\Core\Plugin::container()->get( 'messaging' );
		$preview    = $messaging->get_message_preview( $message_id );
		$convo_id   = $messaging->get_message_conversation_id( $message_id );

		if ( ! $preview || ! $convo_id || '' === $messaging->get_participant_role( $convo_id, get_current_user_id() ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Message not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		if ( (int) $preview['sender_id'] === get_current_user_id() ) {
			return new WP_Error( 'mvs_report_own', __( 'You cannot report your own message.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return $this->file_report( 'message', $message_id, $request );
	}

	/**
	 * Shared tail of every report route: switch, rate limit, store.
	 *
	 * @param string          $type    Target type.
	 * @param int             $id      Target id (already checked).
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private function file_report( string $type, int $id, $request ) {
		$disabled = $this->reports_disabled();
		if ( is_wp_error( $disabled ) ) {
			return $disabled;
		}

		$rate_check = RateLimiter::check( 'report', 10, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$result = $this->reports->report(
			get_current_user_id(),
			$type,
			$id,
			sanitize_text_field( $request->get_param( 'reason' ) ),
			sanitize_textarea_field( $request->get_param( 'details' ) )
		);

		if ( false === $result ) {
			return new WP_Error( 'mvs_report_failed', __( 'Unable to submit report. You may have already reported this.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'reported' => true ) );
	}

	/**
	 * Block a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function block_user( $request ) {
		$rate_check = RateLimiter::check( 'block_user', 10, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$target_id = $request->get_param( 'id' );

		$result = $this->reports->block_user( get_current_user_id(), $target_id );

		if ( ! $result ) {
			return new WP_Error( 'mvs_block_failed', __( 'Unable to block this user.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'blocked' => true ) );
	}

	/**
	 * Unblock a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function unblock_user( $request ) {
		$target_id = $request->get_param( 'id' );
		$this->reports->unblock_user( get_current_user_id(), $target_id );

		return rest_ensure_response( array( 'blocked' => false ) );
	}

	/**
	 * Get blocked users list.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_blocked( $request ) {
		$blocked_ids = $this->reports->get_blocked_ids( get_current_user_id() );

		$users = array();
		foreach ( $blocked_ids as $uid ) {
			$user = get_userdata( $uid );
			if ( $user ) {
				$users[] = array(
					'id'          => $uid,
					'name'        => $user->display_name,
					'avatar'      => get_avatar_url( $uid, array( 'size' => 48 ) ),
					'profile_url' => \WPMediaVerse\Core\Plugin::container()->get( 'template_helpers' )->get_user_profile_url( $uid ),
				);
			}
		}

		return rest_ensure_response( $users );
	}
}
