<?php
/**
 * Settings sanitize callbacks.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless utility class for settings sanitization callbacks.
 */
class Sanitizers {

	/**
	 * Sanitize size field — converts MB input to bytes.
	 *
	 * @param mixed $value Input value in MB.
	 * @return int Value in bytes.
	 */
	public static function sanitize_size_mb( $value ): int {
		$mb = absint( $value );
		if ( $mb < 1 ) {
			$mb = 100;
		}
		return $mb * MB_IN_BYTES;
	}

	/**
	 * Sanitize file types — merge checkbox values with custom textarea.
	 *
	 * @param mixed $value Input (array from checkboxes).
	 * @return string Comma-separated MIME types.
	 */
	public static function sanitize_file_types( $value ): string {
		$types = array();

		if ( is_array( $value ) ) {
			$types = array_map( 'sanitize_mime_type', $value );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! empty( $_POST['mvs_allowed_file_types_custom'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$custom     = sanitize_text_field( wp_unslash( $_POST['mvs_allowed_file_types_custom'] ) );
			$custom_arr = array_map( 'trim', explode( ',', $custom ) );
			$custom_arr = array_map( 'sanitize_mime_type', $custom_arr );
			$types      = array_merge( $types, $custom_arr );
		}

		$types = array_unique( array_filter( $types ) );

		// Submitting the form with zero checkboxes checked and no custom types
		// would otherwise persist '' and overwrite the registered default —
		// after which the form page renders every checkbox unchecked and uploads
		// stop working. Treat an empty submission as "no change" by preserving
		// the current stored value (which is the registered default on fresh
		// installs). Users who genuinely want to lock everything down can still
		// enter a single MIME type in the custom textarea.
		if ( empty( $types ) ) {
			return (string) get_option( 'mvs_allowed_file_types', SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES );
		}

		return implode( ',', $types );
	}

	/**
	 * Sanitize a password/API key option — preserve existing value when empty.
	 *
	 * @param string $value New value.
	 * @return string
	 */
	public static function sanitize_password_option( $value ): string {
		$value = sanitize_text_field( $value );
		if ( '' === $value ) {
			$option = str_replace( 'sanitize_option_', '', current_filter() );
			return get_option( $option, '' );
		}
		return $value;
	}

	/**
	 * Sanitize the lightbox image source choice — must be one of the allowed keys.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_lightbox_image_source( $value ): string {
		$allowed = array( 'original', 'large', 'medium', 'auto' );
		$value   = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : 'original';
	}

	/**
	 * Sanitize webhook settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitized webhooks.
	 */
	public static function sanitize_webhooks( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $input as $webhook ) {
			$url = isset( $webhook['url'] ) ? esc_url_raw( $webhook['url'] ) : '';
			if ( empty( $url ) ) {
				continue;
			}

			$events = isset( $webhook['events'] ) && is_array( $webhook['events'] )
				? array_map( 'sanitize_text_field', $webhook['events'] )
				: array( '*' );

			$sanitized[] = array(
				'url'    => $url,
				'secret' => isset( $webhook['secret'] ) ? sanitize_text_field( $webhook['secret'] ) : '',
				'events' => $events,
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize grid columns: integer 1-6, default 3.
	 *
	 * @param mixed $value Raw input.
	 * @return int Sanitized columns.
	 */
	public static function sanitize_grid_columns( $value ): int {
		$value = absint( $value );
		if ( $value < 1 || $value > 6 ) {
			return 3;
		}
		return $value;
	}

	/**
	 * Sanitize thumbnail style: `square` or `original`.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized style.
	 */
	public static function sanitize_thumbnail_style( $value ): string {
		$allowed = array( 'square', 'original' );
		$value   = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : 'square';
	}

	/**
	 * Sanitize DM access level. Whitelist must stay in lockstep with the
	 * dropdown choices in SettingsRegistrar::register_messaging_settings()
	 * AND the consumer switch in MessagingService::check_can_message().
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized access level.
	 */
	public static function sanitize_dm_access( $value ): string {
		$allowed = array( 'everyone', 'followers', 'mutual', 'nobody' );
		$value   = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : 'everyone';
	}

	/**
	 * Sanitize online-status visibility. Whitelist must stay in lockstep with
	 * the dropdown choices in SettingsRegistrar::register_messaging_settings()
	 * AND the consumer in Plugin::init_messaging() filter callback.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized visibility level.
	 */
	public static function sanitize_show_online_status( $value ): string {
		$allowed = array( 'everyone', 'followers', 'nobody' );
		$value   = is_string( $value ) ? $value : '';
		return in_array( $value, $allowed, true ) ? $value : 'everyone';
	}

	/**
	 * Sanitize comment edit window in seconds. Clamp to [60, 24h].
	 *
	 * @param mixed $value Raw input (seconds).
	 * @return int Sanitized window in seconds.
	 */
	public static function sanitize_comment_edit_window( $value ): int {
		$value = absint( $value );
		if ( $value < MINUTE_IN_SECONDS ) {
			return 15 * MINUTE_IN_SECONDS;
		}
		if ( $value > DAY_IN_SECONDS ) {
			return DAY_IN_SECONDS;
		}
		return $value;
	}
}
