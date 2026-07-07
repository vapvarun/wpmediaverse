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

		// POST /media/{id}/report.
		register_rest_route(
			$this->namespace,
			'/media/(?P<id>[\d]+)/report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'report_media' ),
				'permission_callback' => $auth,
				'args'                => array(
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
				),
			)
		);

		// POST /users/{id}/report.
		register_rest_route(
			$this->namespace,
			'/users/(?P<id>[\d]+)/report',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'report_user' ),
				'permission_callback' => $auth,
				'args'                => array(
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
				),
			)
		);

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
	 * Guard the report write path behind the `mvs_reports_enabled` filter.
	 *
	 * Reporting is a Pro feature: Free has no queue/UI to read or resolve
	 * reports, so Free must not collect them. The filter defaults to false in
	 * Free; Pro (or a site) opts in by returning true. When disabled, even a
	 * hand-crafted POST is refused with a clear, distinct error instead of
	 * piling up unreadable reports.
	 *
	 * @since 1.6.0
	 *
	 * @return WP_Error|null WP_Error when reporting is disabled, null otherwise.
	 */
	private function reports_disabled() {
		/** This filter is documented in templates/media-single.php */
		if ( apply_filters( 'mvs_reports_enabled', false ) ) {
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
		$disabled = $this->reports_disabled();
		if ( is_wp_error( $disabled ) ) {
			return $disabled;
		}

		$rate_check = RateLimiter::check( 'report', 10, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$media_id = $request->get_param( 'id' );

		if ( ! \WPMediaVerse\Core\Plugin::container()->get( 'media_repository' )->exists( $media_id ) ) {
			return new WP_Error( 'mvs_not_found', __( 'Media not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$result = $this->reports->report(
			get_current_user_id(),
			'media',
			$media_id,
			sanitize_text_field( $request->get_param( 'reason' ) ),
			sanitize_textarea_field( $request->get_param( 'details' ) )
		);

		if ( false === $result ) {
			return new WP_Error( 'mvs_report_failed', __( 'Unable to submit report. You may have already reported this item.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( array( 'reported' => true ) );
	}

	/**
	 * Report a user.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_user( $request ) {
		$disabled = $this->reports_disabled();
		if ( is_wp_error( $disabled ) ) {
			return $disabled;
		}

		$rate_check = RateLimiter::check( 'report', 10, 60 );
		if ( is_wp_error( $rate_check ) ) {
			return $rate_check;
		}

		$target_id = $request->get_param( 'id' );

		if ( ! get_userdata( $target_id ) ) {
			return new WP_Error( 'mvs_user_not_found', __( 'User not found.', 'wpmediaverse' ), array( 'status' => 404 ) );
		}

		$result = $this->reports->report(
			get_current_user_id(),
			'user',
			$target_id,
			sanitize_text_field( $request->get_param( 'reason' ) ),
			sanitize_textarea_field( $request->get_param( 'details' ) )
		);

		if ( false === $result ) {
			return new WP_Error( 'mvs_report_failed', __( 'Unable to submit report. You may have already reported this user.', 'wpmediaverse' ), array( 'status' => 400 ) );
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
