<?php
/**
 * Profile service.
 *
 * Standalone profile management — works without BuddyPress.
 * Handles avatar upload/storage and profile field updates using
 * native WordPress user fields.
 *
 * @package    WPMediaVerse
 * @subpackage Services
 * @since      1.1.0
 */

namespace WPMediaVerse\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Manages user profile data and custom avatars.
 */
class ProfileService {

	/**
	 * Usermeta key for custom avatar attachment ID.
	 *
	 * @var string
	 */
	const AVATAR_META_KEY = '_mvs_custom_avatar';

	/**
	 * Allowed profile fields (WordPress native).
	 *
	 * @var string[]
	 */
	const ALLOWED_FIELDS = array(
		'first_name',
		'last_name',
		'display_name',
		'description',
	);

	/**
	 * Initialize hooks.
	 *
	 * @since 1.1.0
	 */
	public function init(): void {
		add_filter( 'pre_get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
		add_filter( 'get_avatar_url', array( $this, 'filter_avatar_url' ), 10, 3 );
	}

	/**
	 * Get profile data for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return array|false Profile data or false if user not found.
	 */
	public function get_profile( int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return false;
		}

		$profile = array(
			'id'           => $user_id,
			'first_name'   => $user->first_name,
			'last_name'    => $user->last_name,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'email'        => $user->user_email,
			'bio'          => $user->description,
			'avatar'       => get_avatar_url( $user_id, array( 'size' => 150 ) ),
			'has_custom_avatar' => $this->has_custom_avatar( $user_id ),
		);

		/**
		 * Filters the profile data returned by the profile service.
		 *
		 * @since 1.1.0
		 *
		 * @param array $profile Profile data.
		 * @param int   $user_id User ID.
		 */
		return apply_filters( 'mvs_profile_data', $profile, $user_id );
	}

	/**
	 * Update profile fields for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $fields  Associative array of field => value.
	 * @return true|\WP_Error True on success or WP_Error.
	 */
	public function update_profile( int $user_id, array $fields ) {
		/**
		 * Filters the profile fields before saving.
		 *
		 * @since 1.1.0
		 *
		 * @param array $fields  Fields being updated.
		 * @param int   $user_id User ID.
		 */
		$fields = apply_filters( 'mvs_profile_update_fields', $fields, $user_id );

		$userdata = array( 'ID' => $user_id );

		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, self::ALLOWED_FIELDS, true ) ) {
				continue;
			}
			$userdata[ $key ] = sanitize_text_field( $value );
		}

		// Bio/description can have more content.
		if ( isset( $fields['description'] ) ) {
			$userdata['description'] = sanitize_textarea_field( $fields['description'] );
		}

		if ( count( $userdata ) <= 1 ) {
			return new \WP_Error( 'mvs_no_fields', __( 'No valid fields to update.', 'wpmediaverse' ), array( 'status' => 400 ) );
		}

		$result = wp_update_user( $userdata );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		/**
		 * Fires after a user profile is updated via MVS.
		 *
		 * @since 1.1.0
		 *
		 * @param int   $user_id User ID.
		 * @param array $fields  Fields that were updated.
		 */
		do_action( 'mvs_profile_updated', $user_id, $fields );

		return true;
	}

	/**
	 * Upload and set a custom avatar for a user.
	 *
	 * @since 1.1.0
	 *
	 * @param int   $user_id User ID.
	 * @param array $file    $_FILES array item.
	 * @return int|\WP_Error Attachment ID or WP_Error.
	 */
	public function upload_avatar( int $user_id, array $file ) {
		// Validate file type.
		$allowed_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

		/**
		 * Filters the allowed MIME types for avatar uploads.
		 *
		 * @since 1.1.0
		 *
		 * @param string[] $allowed_types Allowed MIME types.
		 * @param int      $user_id      User ID.
		 */
		$allowed_types = apply_filters( 'mvs_avatar_allowed_types', $allowed_types, $user_id );

		$filetype = wp_check_filetype( $file['name'] );
		if ( ! $filetype['type'] || ! in_array( $filetype['type'], $allowed_types, true ) ) {
			return new \WP_Error(
				'mvs_invalid_avatar_type',
				__( 'Invalid file type. Please upload a JPEG, PNG, GIF, or WebP image.', 'wpmediaverse' ),
				array( 'status' => 400 )
			);
		}

		// Max file size: 2MB default.
		$max_size = apply_filters( 'mvs_avatar_max_size', 2 * MB_IN_BYTES, $user_id );
		if ( $file['size'] > $max_size ) {
			return new \WP_Error(
				'mvs_avatar_too_large',
				sprintf(
					/* translators: %s: maximum file size */
					__( 'Avatar file is too large. Maximum size is %s.', 'wpmediaverse' ),
					size_format( $max_size )
				),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		// Handle upload.
		$upload = wp_handle_upload( $file, array( 'test_form' => false ) );

		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'mvs_avatar_upload_failed', $upload['error'], array( 'status' => 500 ) );
		}

		// Create attachment.
		$attachment_id = wp_insert_attachment(
			array(
				'post_title'     => sprintf( 'MVS Avatar — User %d', $user_id ),
				'post_mime_type' => $upload['type'],
				'post_status'    => 'inherit',
			),
			$upload['file']
		);

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Generate metadata (thumbnails).
		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// Delete old avatar attachment if exists.
		$old_avatar_id = $this->get_custom_avatar_id( $user_id );
		if ( $old_avatar_id ) {
			wp_delete_attachment( $old_avatar_id, true );
		}

		// Store new avatar.
		update_user_meta( $user_id, self::AVATAR_META_KEY, $attachment_id );

		/**
		 * Fires after a custom avatar is uploaded.
		 *
		 * @since 1.1.0
		 *
		 * @param int $user_id       User ID.
		 * @param int $attachment_id Attachment ID.
		 */
		do_action( 'mvs_avatar_uploaded', $user_id, $attachment_id );

		return $attachment_id;
	}

	/**
	 * Delete the custom avatar for a user (reverts to Gravatar).
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool True if deleted, false if no custom avatar.
	 */
	public function delete_avatar( int $user_id ): bool {
		$attachment_id = $this->get_custom_avatar_id( $user_id );

		if ( ! $attachment_id ) {
			return false;
		}

		wp_delete_attachment( $attachment_id, true );
		delete_user_meta( $user_id, self::AVATAR_META_KEY );

		/**
		 * Fires after a custom avatar is deleted.
		 *
		 * @since 1.1.0
		 *
		 * @param int $user_id User ID.
		 */
		do_action( 'mvs_avatar_deleted', $user_id );

		return true;
	}

	/**
	 * Check if a user has a custom avatar.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	public function has_custom_avatar( int $user_id ): bool {
		return (bool) $this->get_custom_avatar_id( $user_id );
	}

	/**
	 * Get the custom avatar attachment ID.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @return int Attachment ID or 0.
	 */
	public function get_custom_avatar_id( int $user_id ): int {
		$id = (int) get_user_meta( $user_id, self::AVATAR_META_KEY, true );

		// Verify attachment still exists.
		if ( $id && ! wp_attachment_is_image( $id ) ) {
			delete_user_meta( $user_id, self::AVATAR_META_KEY );
			return 0;
		}

		return $id;
	}

	/**
	 * Get the custom avatar URL.
	 *
	 * @since 1.1.0
	 *
	 * @param int $user_id User ID.
	 * @param int $size    Avatar size in pixels.
	 * @return string|false URL or false if no custom avatar.
	 */
	public function get_custom_avatar_url( int $user_id, int $size = 96 ) {
		$attachment_id = $this->get_custom_avatar_id( $user_id );

		if ( ! $attachment_id ) {
			return false;
		}

		// Find best matching thumbnail size.
		$url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );

		if ( ! $url ) {
			$url = wp_get_attachment_url( $attachment_id );
		}

		return $url ? set_url_scheme( $url ) : false;
	}

	/**
	 * Filter avatar data to use custom avatar when available.
	 *
	 * Hooks into `pre_get_avatar_data` so all `get_avatar()` and
	 * `get_avatar_url()` calls automatically serve the custom avatar.
	 *
	 * @since 1.1.0
	 *
	 * @param array $args        Avatar data arguments.
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post, or WP_Comment.
	 * @return array Modified arguments.
	 */
	public function filter_avatar_data( array $args, $id_or_email ): array {
		$user_id = $this->resolve_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $args;
		}

		$attachment_id = $this->get_custom_avatar_id( $user_id );

		if ( ! $attachment_id ) {
			return $args;
		}

		$size = ! empty( $args['size'] ) ? (int) $args['size'] : 96;
		$url  = $this->get_custom_avatar_url( $user_id, $size );

		if ( $url ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * Filter avatar URL as a fallback for plugins that bypass pre_get_avatar_data.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url         Current avatar URL.
	 * @param mixed  $id_or_email User ID, email, or object.
	 * @param array  $args        Avatar arguments.
	 * @return string Modified URL.
	 */
	public function filter_avatar_url( string $url, $id_or_email, $args ): string {
		$user_id = $this->resolve_user_id( $id_or_email );

		if ( ! $user_id ) {
			return $url;
		}

		$size      = ! empty( $args['size'] ) ? (int) $args['size'] : 96;
		$custom_url = $this->get_custom_avatar_url( $user_id, $size );

		return $custom_url ? $custom_url : $url;
	}

	/**
	 * Resolve a user ID from various input types.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed $id_or_email User ID, email, WP_User, WP_Post, or WP_Comment.
	 * @return int User ID or 0.
	 */
	private function resolve_user_id( $id_or_email ): int {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}

		if ( $id_or_email instanceof \WP_User ) {
			return $id_or_email->ID;
		}

		if ( $id_or_email instanceof \WP_Post ) {
			return (int) $id_or_email->post_author;
		}

		if ( $id_or_email instanceof \WP_Comment ) {
			if ( ! empty( $id_or_email->user_id ) ) {
				return (int) $id_or_email->user_id;
			}
			// Fall through to email lookup.
			$id_or_email = $id_or_email->comment_author_email;
		}

		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? $user->ID : 0;
		}

		return 0;
	}
}
