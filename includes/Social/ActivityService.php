<?php
/**
 * Activity service.
 *
 * Native activity feed — works standalone, uses BuddyPress activity when available.
 *
 * @package    WPMediaVerse
 * @subpackage Social
 * @since      1.1.0
 */

namespace WPMediaVerse\Social;

defined( 'ABSPATH' ) || exit;

/**
 * Records and queries activity feed items.
 */
class ActivityService {

	/**
	 * Activity types.
	 *
	 * @var string[]
	 */
	const TYPES = array(
		'media_upload',
		'media_reaction',
		'media_comment',
		'media_favorite',
		'user_follow',
		'album_created',
	);

	/**
	 * Register hooks to auto-record activities.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		add_action( 'mvs_media_uploaded', array( $this, 'on_upload' ), 10, 2 );
		add_action( 'mvs_reaction_added', array( $this, 'on_reaction' ), 10, 3 );
		add_action( 'mvs_comment_created', array( $this, 'on_comment' ), 10, 3 );
		add_action( 'mvs_user_followed', array( $this, 'on_follow' ), 10, 2 );
	}

	/**
	 * Record an activity.
	 *
	 * @since 1.1.0
	 *
	 * @param int    $user_id  User who performed the action.
	 * @param string $type     Activity type.
	 * @param int    $media_id Related media ID (0 if none).
	 * @param int    $album_id Related album ID (0 if none).
	 * @param string $content  Optional text content.
	 * @return int|false Activity ID or false.
	 */
	public function record( int $user_id, string $type, int $media_id = 0, int $album_id = 0, string $content = '' ) {
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return false;
		}

		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prefix . 'mvs_activity',
			array(
				'user_id'    => $user_id,
				'type'       => $type,
				'media_id'   => $media_id,
				'album_id'   => $album_id,
				'content'    => sanitize_text_field( $content ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%d', '%d', '%s', '%s' )
		);

		return $wpdb->insert_id ? $wpdb->insert_id : false;
	}

	/**
	 * Get activity feed.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args {
	 *     Query arguments.
	 *     @type string $scope    'public' (all), 'following' (followed users), 'user' (single user).
	 *     @type int    $user_id  For 'user' scope or 'following' scope base.
	 *     @type int    $per_page Results per page.
	 *     @type int    $page     Page number.
	 * }
	 * @return array { activities: array, total: int }
	 */
	public function get_feed( array $args = array() ): array {
		global $wpdb;

		$scope    = isset( $args['scope'] ) ? $args['scope'] : 'public';
		$user_id  = isset( $args['user_id'] ) ? (int) $args['user_id'] : 0;
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		$offset   = ( $page - 1 ) * $per_page;

		$where  = '1=1';
		$params = array();

		if ( 'user' === $scope && $user_id ) {
			$where   .= ' AND a.user_id = %d';
			$params[] = $user_id;
		} elseif ( 'following' === $scope && $user_id ) {
			$follows = \WPMediaVerse\Core\Plugin::container()->get( 'follows' );
			$ids     = $follows->get_following_ids( $user_id );

			if ( empty( $ids ) ) {
				return array( 'activities' => array(), 'total' => 0 );
			}

			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$where       .= " AND a.user_id IN ({$placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$params       = array_merge( $params, $ids );
		}

		// Exclude blocked users if viewer is logged in.
		$viewer_id = get_current_user_id();
		if ( $viewer_id ) {
			$reports    = \WPMediaVerse\Core\Plugin::container()->get( 'reports' );
			$blocked    = $reports->get_blocked_ids( $viewer_id );
			if ( ! empty( $blocked ) ) {
				$block_placeholders = implode( ',', array_fill( 0, count( $blocked ), '%d' ) );
				$where             .= " AND a.user_id NOT IN ({$block_placeholders})"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params             = array_merge( $params, $blocked );
			}
		}

		$count_params = $params;
		$total = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_activity a WHERE {$where}", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$count_params
			)
		);

		$params[] = $per_page;
		$params[] = $offset;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT a.* FROM {$wpdb->prefix}mvs_activity a WHERE {$where} ORDER BY a.created_at DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				...$params
			)
		);

		$activities = array();
		foreach ( $rows as $row ) {
			$activities[] = $this->format_activity( $row );
		}

		return array(
			'activities' => $activities,
			'total'      => $total,
		);
	}

	/**
	 * Handle upload activity.
	 *
	 * @param int   $media_id Media ID.
	 * @param array $file_data File data.
	 */
	public function on_upload( int $media_id, array $file_data ): void {
		$author = (int) get_post_field( 'post_author', $media_id );
		if ( $author ) {
			$this->record( $author, 'media_upload', $media_id );
		}
	}

	/**
	 * Handle reaction activity.
	 *
	 * @param int    $media_id Media ID.
	 * @param int    $user_id  Reactor.
	 * @param string $type     Reaction type.
	 */
	public function on_reaction( int $media_id, int $user_id, string $type ): void {
		$this->record( $user_id, 'media_reaction', $media_id, 0, $type );
	}

	/**
	 * Handle comment activity.
	 *
	 * @param int $media_id   Media ID.
	 * @param int $user_id    Commenter.
	 * @param int $comment_id Comment ID.
	 */
	public function on_comment( int $media_id, int $user_id, int $comment_id ): void {
		$this->record( $user_id, 'media_comment', $media_id );
	}

	/**
	 * Handle follow activity.
	 *
	 * @param int $follower_id Follower.
	 * @param int $following_id Followed.
	 */
	public function on_follow( int $follower_id, int $following_id ): void {
		$this->record( $follower_id, 'user_follow', 0, 0, (string) $following_id );
	}

	/**
	 * Format an activity row for REST output.
	 *
	 * @param object $row Database row.
	 * @return array
	 */
	private function format_activity( object $row ): array {
		$user = get_userdata( (int) $row->user_id );

		$activity = array(
			'id'         => (int) $row->id,
			'type'       => $row->type,
			'user'       => array(
				'id'     => (int) $row->user_id,
				'name'   => $user ? $user->display_name : '',
				'avatar' => get_avatar_url( (int) $row->user_id, array( 'size' => 48 ) ),
			),
			'media_id'   => (int) $row->media_id,
			'album_id'   => (int) $row->album_id,
			'content'    => $row->content,
			'created_at' => $row->created_at,
		);

		// Attach media summary if present.
		if ( $row->media_id ) {
			$media_post = get_post( (int) $row->media_id );
			if ( $media_post ) {
				$thumb_url  = '';
				$attach_id  = (int) get_post_meta( $media_post->ID, '_mvs_attachment_id', true );
				if ( $attach_id ) {
					$thumb_src = wp_get_attachment_image_url( $attach_id, 'thumbnail' );
					if ( $thumb_src ) {
						$thumb_url = set_url_scheme( $thumb_src );
					}
				}

				$activity['media'] = array(
					'title'      => $media_post->post_title,
					'type'       => get_post_meta( $media_post->ID, '_mvs_media_type', true ),
					'thumbnail'  => $thumb_url,
					'link'       => get_permalink( $media_post->ID ),
				);
			}
		}

		return $activity;
	}
}
