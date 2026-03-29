<?php
/**
 * Media custom post type.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\PostTypes;

defined( 'ABSPATH' ) || exit;

use WPMediaVerse\Services\MediaMeta;

/**
 * Registers the mvs_media custom post type.
 */
class Media {

	/**
	 * Register the mvs_media post type.
	 */
	public static function register(): void {
		$labels = array(
			'name'                  => __( 'Media Items', 'wpmediaverse' ),
			'singular_name'         => __( 'Media Item', 'wpmediaverse' ),
			'add_new'               => __( 'Add New', 'wpmediaverse' ),
			'add_new_item'          => __( 'Add Media', 'wpmediaverse' ),
			'edit_item'             => __( 'Edit Media Item', 'wpmediaverse' ),
			'new_item'              => __( 'New Media Item', 'wpmediaverse' ),
			'view_item'             => __( 'View Media Item', 'wpmediaverse' ),
			'search_items'          => __( 'Search Media Items', 'wpmediaverse' ),
			'not_found'             => __( 'No media items found.', 'wpmediaverse' ),
			'not_found_in_trash'    => __( 'No media items found in Trash.', 'wpmediaverse' ),
			'all_items'             => __( 'All Media', 'wpmediaverse' ),
			'archives'              => __( 'Media Archives', 'wpmediaverse' ),
			'insert_into_item'      => __( 'Insert into media item', 'wpmediaverse' ),
			'uploaded_to_this_item' => __( 'Uploaded to this media item', 'wpmediaverse' ),
			'menu_name'             => __( 'WPMediaVerse', 'wpmediaverse' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => true,
			'has_archive'        => true,
			'show_in_rest'       => true,
			'rest_base'          => 'mvs-media',
			'supports'           => array( 'title', 'author', 'thumbnail', 'custom-fields' ),
			'capability_type'    => array( 'mvs_media', 'mvs_medias' ),
			'map_meta_cap'       => true,
			'rewrite'            => array(
				'slug'       => 'media',
				'with_front' => false,
			),
			'menu_icon'          => 'dashicons-format-gallery',
			'show_in_menu'       => true,
			'menu_position'      => 25,
		);

		register_post_type( 'mvs_media', $args );
	}

	/**
	 * Register admin column hooks.
	 */
	public static function register_admin_columns(): void {
		add_filter( 'manage_mvs_media_posts_columns', array( __CLASS__, 'add_columns' ) );
		add_action( 'manage_mvs_media_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
	}

	/**
	 * Add custom columns to the media list table.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['mvs_thumb'] = __( 'Thumb', 'wpmediaverse' );
			}
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['mvs_type']    = __( 'Type', 'wpmediaverse' );
				$new['mvs_privacy'] = __( 'Privacy', 'wpmediaverse' );
				$new['mvs_status']  = __( 'Status', 'wpmediaverse' );
			}
		}
		return $new;
	}

	/**
	 * Render custom column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( string $column, int $post_id ): void {
		switch ( $column ) {
			case 'mvs_thumb':
				$url  = MediaMeta::get( $post_id, 'file_url' );
				$type = MediaMeta::get( $post_id, 'media_type' ) ?: 'image';
				if ( $url && 'image' === $type ) {
					printf(
						'<img src="%s" alt="" style="width:40px;height:40px;object-fit:cover;border-radius:4px;" loading="lazy" />',
						esc_url( $url )
					);
				} else {
					$icons = array(
						'video'    => 'dashicons-video-alt3',
						'audio'    => 'dashicons-format-audio',
						'document' => 'dashicons-media-document',
						'image'    => 'dashicons-format-image',
					);
					$icon  = $icons[ $type ] ?? 'dashicons-media-default';
					printf( '<span class="dashicons %s" style="font-size:28px;width:40px;height:40px;line-height:40px;color:#646970;"></span>', esc_attr( $icon ) );
				}
				break;

			case 'mvs_type':
				$type   = MediaMeta::get( $post_id, 'media_type' ) ?: 'image';
				$colors = array(
					'image'    => '#2271b1',
					'video'    => '#9b59b6',
					'audio'    => '#e67e22',
					'document' => '#27ae60',
				);
				$color  = $colors[ $type ] ?? '#646970';
				printf(
					'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:%s;">%s</span>',
					esc_attr( $color ),
					esc_html( ucfirst( $type ) )
				);
				break;

			case 'mvs_privacy':
				$privacy = MediaMeta::get( $post_id, 'privacy' ) ?: 'public';
				$labels  = array(
					'public'  => array( '#00a32a', __( 'Public', 'wpmediaverse' ) ),
					'members' => array( '#2271b1', __( 'Members', 'wpmediaverse' ) ),
					'private' => array( '#d63638', __( 'Private', 'wpmediaverse' ) ),
					'group'   => array( '#9b59b6', __( 'Group', 'wpmediaverse' ) ),
					'friends' => array( '#e67e22', __( 'Friends', 'wpmediaverse' ) ),
				);
				$info    = $labels[ $privacy ] ?? array( '#646970', ucfirst( $privacy ) );
				printf(
					'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:%s;">%s</span>',
					esc_attr( $info[0] ),
					esc_html( $info[1] )
				);
				break;

			case 'mvs_status':
				$status = MediaMeta::get( $post_id, 'moderation_status' ) ?: 'approved';
				$labels = array(
					'approved' => array( '#00a32a', __( 'Approved', 'wpmediaverse' ) ),
					'pending'  => array( '#dba617', __( 'Pending', 'wpmediaverse' ) ),
					'flagged'  => array( '#d63638', __( 'Flagged', 'wpmediaverse' ) ),
					'rejected' => array( '#646970', __( 'Rejected', 'wpmediaverse' ) ),
				);
				$info   = $labels[ $status ] ?? array( '#646970', ucfirst( $status ) );
				printf(
					'<span style="display:inline-block;padding:2px 8px;border-radius:3px;font-size:11px;font-weight:600;color:#fff;background:%s;">%s</span>',
					esc_attr( $info[0] ),
					esc_html( $info[1] )
				);
				break;
		}
	}
}
