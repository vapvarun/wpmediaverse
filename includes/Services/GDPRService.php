<?php
/**
 * GDPR compliance — personal data exporter and eraser.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Repository\MediaRepository;

class GDPRService {

	/**
	 * Register hooks.
	 */
	public function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
		add_action( 'admin_init', array( $this, 'add_privacy_policy' ) );
	}

	/**
	 * Register data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( array $exporters ): array {
		$exporters['wpmediaverse-media'] = array(
			'exporter_friendly_name' => __( 'WPMediaVerse Media', 'wpmediaverse' ),
			'callback'               => array( $this, 'export_media' ),
		);

		$exporters['wpmediaverse-social'] = array(
			'exporter_friendly_name' => __( 'WPMediaVerse Social Data', 'wpmediaverse' ),
			'callback'               => array( $this, 'export_social' ),
		);

		return $exporters;
	}

	/**
	 * Register data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_erasers( array $erasers ): array {
		$erasers['wpmediaverse-social'] = array(
			'eraser_friendly_name' => __( 'WPMediaVerse Social Data', 'wpmediaverse' ),
			'callback'             => array( $this, 'erase_social' ),
		);

		$erasers['wpmediaverse-media'] = array(
			'eraser_friendly_name' => __( 'WPMediaVerse Media', 'wpmediaverse' ),
			'callback'             => array( $this, 'erase_media' ),
		);

		return $erasers;
	}

	/**
	 * Export user's media items.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{data: array, done: bool}
	 */
	public function export_media( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;

		$per_page = 50;
		$offset   = ( $page - 1 ) * $per_page;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id, title, description, media_type, privacy, file_url, created_at
				FROM {$wpdb->prefix}mvs_media_index
				WHERE post_author = %d
				ORDER BY media_id ASC
				LIMIT %d OFFSET %d",
				$user->ID,
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$export_items = array();
		foreach ( $rows as $row ) {
			$export_items[] = array(
				'group_id'    => 'wpmediaverse-media',
				'group_label' => __( 'Media Items', 'wpmediaverse' ),
				'item_id'     => "mvs-media-{$row['media_id']}",
				'data'        => array(
					array(
						'name'  => __( 'Title', 'wpmediaverse' ),
						'value' => $row['title'] ?? '',
					),
					array(
						'name'  => __( 'Description', 'wpmediaverse' ),
						'value' => $row['description'] ?? '',
					),
					array(
						'name'  => __( 'Type', 'wpmediaverse' ),
						'value' => $row['media_type'] ?? '',
					),
					array(
						'name'  => __( 'Privacy', 'wpmediaverse' ),
						'value' => $row['privacy'] ?? '',
					),
					array(
						'name'  => __( 'Date', 'wpmediaverse' ),
						'value' => $row['created_at'] ?? '',
					),
					array(
						'name'  => __( 'File URL', 'wpmediaverse' ),
						'value' => $row['file_url'] ?? '',
					),
				),
			);
		}

		return array(
			'data' => $export_items,
			'done' => count( $rows ) < $per_page,
		);
	}

	/**
	 * Export user's social data (reactions, comments, follows).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{data: array, done: bool}
	 */
	public function export_social( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		global $wpdb;
		$export_items = array();

		if ( 1 === $page ) {
			// Reactions.
			$reactions = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, reaction_type, created_at FROM {$wpdb->prefix}mvs_reactions WHERE user_id = %d",
					$user->ID
				)
			);
			foreach ( $reactions as $r ) {
				$export_items[] = array(
					'group_id'    => 'wpmediaverse-reactions',
					'group_label' => __( 'Reactions', 'wpmediaverse' ),
					'item_id'     => "mvs-reaction-{$r->media_id}",
					'data'        => array(
						array(
							'name'  => __( 'Media ID', 'wpmediaverse' ),
							'value' => $r->media_id,
						),
						array(
							'name'  => __( 'Type', 'wpmediaverse' ),
							'value' => $r->reaction_type,
						),
						array(
							'name'  => __( 'Date', 'wpmediaverse' ),
							'value' => $r->created_at,
						),
					),
				);
			}

			// Follows.
			$follows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT following_id, created_at FROM {$wpdb->prefix}mvs_follows WHERE follower_id = %d",
					$user->ID
				)
			);
			foreach ( $follows as $f ) {
				$followed_user  = get_userdata( $f->following_id );
				$export_items[] = array(
					'group_id'    => 'wpmediaverse-follows',
					'group_label' => __( 'Following', 'wpmediaverse' ),
					'item_id'     => "mvs-follow-{$f->following_id}",
					'data'        => array(
						array(
							'name'  => __( 'User', 'wpmediaverse' ),
							'value' => $followed_user ? $followed_user->display_name : "#{$f->following_id}",
						),
						array(
							'name'  => __( 'Date', 'wpmediaverse' ),
							'value' => $f->created_at,
						),
					),
				);
			}

			// Favorites.
			$favorites = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"SELECT media_id, created_at FROM {$wpdb->prefix}mvs_favorites WHERE user_id = %d",
					$user->ID
				)
			);
			foreach ( $favorites as $fav ) {
				$export_items[] = array(
					'group_id'    => 'wpmediaverse-favorites',
					'group_label' => __( 'Favorites', 'wpmediaverse' ),
					'item_id'     => "mvs-fav-{$fav->media_id}",
					'data'        => array(
						array(
							'name'  => __( 'Media ID', 'wpmediaverse' ),
							'value' => $fav->media_id,
						),
						array(
							'name'  => __( 'Date', 'wpmediaverse' ),
							'value' => $fav->created_at,
						),
					),
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => true,
		);
	}

	/**
	 * Erase user's social data.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public function erase_social( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;
		$removed = 0;

		$tables = array(
			'mvs_reactions'     => 'user_id',
			'mvs_favorites'     => 'user_id',
			'mvs_follows'       => 'follower_id',
			'mvs_notifications' => 'user_id',
			'mvs_reports'       => 'reporter_id',
			'mvs_blocks'        => 'blocker_id',
			'mvs_activity'      => 'user_id',
			'mvs_mentions'      => 'mentioned_user_id',
		);

		foreach ( $tables as $table => $column ) {
			$count    = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"DELETE FROM {$wpdb->prefix}{$table} WHERE {$column} = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$user->ID
				)
			);
			$removed += (int) $count;
		}

		// Also remove as following target.
		$removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_follows WHERE following_id = %d",
				$user->ID
			)
		);

		// Remove as notification actor.
		$removed += (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}mvs_notifications WHERE actor_id = %d",
				$user->ID
			)
		);

		// Delete comments.
		$comments = get_comments(
			array(
				'user_id' => $user->ID,
				'type'    => 'mvs_comment',
				'fields'  => 'ids',
			)
		);
		foreach ( $comments as $cid ) {
			wp_delete_comment( $cid, true );
			++$removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Erase user's media (anonymize rather than delete).
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Page number.
	 * @return array{items_removed: int, items_retained: int, messages: array, done: bool}
	 */
	public function erase_media( string $email_address, int $page = 1 ): array {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'items_removed'  => 0,
				'items_retained' => 0,
				'messages'       => array(),
				'done'           => true,
			);
		}

		global $wpdb;

		$per_page  = 50;
		$media_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT media_id FROM {$wpdb->prefix}mvs_media_index WHERE post_author = %d ORDER BY media_id ASC LIMIT %d",
				$user->ID,
				$per_page
			)
		);

		$removed = 0;
		foreach ( $media_ids as $media_id ) {
			$media_id = (int) $media_id;

			// Remove all custom table data (index + meta).
			MediaRepository::delete_all( $media_id );

			// Clean up stats row.
			$wpdb->delete( $wpdb->prefix . 'mvs_media_stats', array( 'media_id' => $media_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

			++$removed;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => 0,
			'messages'       => array(),
			'done'           => count( $media_ids ) < $per_page,
		);
	}

	/**
	 * Add privacy policy suggested text.
	 */
	public function add_privacy_policy(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			'<h2>%s</h2><p>%s</p><p>%s</p><p>%s</p>',
			__( 'WPMediaVerse', 'wpmediaverse' ),
			__( 'When you upload media to this site using WPMediaVerse, we store the file and associated metadata (title, description, tags, privacy settings). Images may be processed for EXIF data removal.', 'wpmediaverse' ),
			__( 'Social interactions (reactions, comments, follows, favorites) are stored in our database and linked to your user account. You can manage these through your profile settings.', 'wpmediaverse' ),
			__( 'When you request deletion of your personal data, all media files, social interactions, and associated metadata will be permanently removed from our systems.', 'wpmediaverse' )
		);

		wp_add_privacy_policy_content( 'WPMediaVerse', $content );
	}
}
