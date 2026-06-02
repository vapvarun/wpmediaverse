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
	 * Per-option whitelist registry for fixed-choice (select dropdown) settings.
	 *
	 * Keys are option names, values are the array of allowed values that the
	 * sanitizer enforces. The order matches the registered_setting() default
	 * (first entry is the default fallback when an unknown value is submitted).
	 *
	 * SettingsContractTest reads from this map to verify R2-R4:
	 *   R2 — every select field's choices array == this whitelist
	 *   R3 — every select field uses a named whitelist sanitizer (not sanitize_text_field)
	 *   R4 — choice key types match the option's declared `type`
	 *
	 * Adding a new select setting? Add a method below AND register the
	 * whitelist here. Do NOT use sanitize_text_field for fixed-choice dropdowns
	 * — it accepts any string and silently lets garbage through.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private const WHITELISTS = array(
		'mvs_default_privacy'        => array( 'public', 'members', 'private' ),
		'mvs_duplicate_action'       => array( 'warn', 'skip', 'allow' ),
		'mvs_storage_driver'         => array( 'local', 's3', 'bunnycdn', 'r2', 'dospaces' ),
		'mvs_thumbnail_style'        => array( 'square', 'original' ),
		'mvs_thumbnail_size'         => array( 'medium', 'large', 'full' ),
		'mvs_lightbox_image_source'  => array( 'original', 'large', 'medium', 'auto' ),
		'mvs_grid_columns'           => array( 2, 3, 4, 5 ),
		'mvs_items_per_page'         => array( 12, 24, 48 ),
		'mvs_ai_provider'            => array( 'openai', 'google_vision', 'rekognition' ),
		'mvs_openai_model'           => array( 'gpt-4o-mini', 'gpt-4o' ),
		'mvs_moderation_auto_action' => array( 'flag', 'hide', 'reject' ),
		'mvs_dm_access'              => array( 'everyone', 'followers', 'mutual', 'nobody' ),
		'mvs_show_online_status'     => array( 'everyone', 'followers', 'nobody' ),
		'mvs_chat_panel_visibility'  => array( 'everywhere', 'mvs_pages', 'bp_pages', 'disabled' ),
		'mvs_filename_strategy'      => array( 'original_sanitized', 'hashed' ),
	);

	/**
	 * Get the whitelist for a select-driven option.
	 *
	 * SettingsContractTest calls this to introspect each select field's
	 * sanitize callback without parsing PHP source. Returns null if the option
	 * is not a fixed-choice dropdown.
	 *
	 * @param string $option Option name.
	 * @return array<int, mixed>|null Allowed values, or null if not whitelisted.
	 */
	public static function get_whitelist( string $option ): ?array {
		return self::WHITELISTS[ $option ] ?? null;
	}

	/**
	 * Get the full whitelist map (for tests + introspection).
	 *
	 * @return array<string, array<int, mixed>>
	 */
	public static function get_all_whitelists(): array {
		return self::WHITELISTS;
	}

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

		$types = array_unique( array_filter( $types ) );

		// Submitting the form with zero checkboxes checked would otherwise
		// persist '' and overwrite the registered default — after which the form
		// page renders every checkbox unchecked and uploads stop working. Treat
		// an empty submission as "no change" by preserving the current stored
		// value (which is the registered default on fresh installs). Developers
		// who need additional MIME types use the `mvs_allowed_file_types` filter.
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
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_lightbox_image_source'], true ) ? $value : 'original';
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

		// The secret field renders empty with a "leave empty to keep the current
		// secret" hint (password-field UX). Without preserving it here, every
		// save would blank the stored HMAC secret and break signature
		// verification. Map existing secrets by URL so an empty submission keeps
		// the current value (matching sanitize_password_option's contract).
		$existing_secrets = array();
		foreach ( (array) get_option( 'mvs_webhooks', array() ) as $existing ) {
			if ( ! empty( $existing['url'] ) ) {
				$existing_secrets[ $existing['url'] ] = $existing['secret'] ?? '';
			}
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

			$secret = isset( $webhook['secret'] ) ? sanitize_text_field( $webhook['secret'] ) : '';
			if ( '' === $secret && isset( $existing_secrets[ $url ] ) ) {
				$secret = $existing_secrets[ $url ];
			}

			$sanitized[] = array(
				'url'    => $url,
				'secret' => $secret,
				'events' => $events,
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize grid columns: integer in {2,3,4,5}, default 3.
	 *
	 * @param mixed $value Raw input.
	 * @return int Sanitized columns.
	 */
	public static function sanitize_grid_columns( $value ): int {
		$value = (int) $value;
		return in_array( $value, self::WHITELISTS['mvs_grid_columns'], true ) ? $value : 3;
	}

	/**
	 * Sanitize items per page: integer in {12,24,48}, default 12.
	 *
	 * @param mixed $value Raw input.
	 * @return int Sanitized count.
	 */
	public static function sanitize_items_per_page( $value ): int {
		$value = (int) $value;
		return in_array( $value, self::WHITELISTS['mvs_items_per_page'], true ) ? $value : 12;
	}

	/**
	 * Sanitize thumbnail style: `square` or `original`.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized style.
	 */
	public static function sanitize_thumbnail_style( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_thumbnail_style'], true ) ? $value : 'square';
	}

	/**
	 * Sanitize thumbnail size choice. Whitelist must stay in lockstep with the
	 * dropdown choices in SettingsRegistrar::register_display_settings()
	 * AND consumers in TemplateHelpers/SettingsHelper.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized size.
	 */
	public static function sanitize_thumbnail_size( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_thumbnail_size'], true ) ? $value : 'large';
	}

	/**
	 * Sanitize default privacy choice.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized privacy level.
	 */
	public static function sanitize_default_privacy( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_default_privacy'], true ) ? $value : 'public';
	}

	/**
	 * Sanitize duplicate-detection action.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized action.
	 */
	public static function sanitize_duplicate_action( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_duplicate_action'], true ) ? $value : 'warn';
	}

	/**
	 * Sanitize storage driver choice. Whitelist accepts cloud drivers even
	 * when Pro is inactive — Pro determines which drivers are actually
	 * registered; the option enforces only valid values, not licensing.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized driver.
	 */
	public static function sanitize_storage_driver( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_storage_driver'], true ) ? $value : 'local';
	}

	/**
	 * Sanitize AI provider choice. Same Pro-licensing reasoning as
	 * sanitize_storage_driver — option enforces enum, not licensing.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized provider id.
	 */
	public static function sanitize_ai_provider( $value ): string {
		$value = is_string( $value ) ? $value : '';
		// Back-compat: the Google Vision provider's id is `google_vision`; an
		// earlier build stored `google`, which never matched the registered
		// provider and silently fell back to OpenAI. Normalize the legacy value.
		if ( 'google' === $value ) {
			$value = 'google_vision';
		}
		return in_array( $value, self::WHITELISTS['mvs_ai_provider'], true ) ? $value : 'openai';
	}

	/**
	 * Sanitize OpenAI model choice.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized model.
	 */
	public static function sanitize_openai_model( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_openai_model'], true ) ? $value : 'gpt-4o-mini';
	}

	/**
	 * Sanitize moderation auto-action choice.
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized action.
	 */
	public static function sanitize_moderation_auto_action( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_moderation_auto_action'], true ) ? $value : 'flag';
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
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_dm_access'], true ) ? $value : 'everyone';
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
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_show_online_status'], true ) ? $value : 'everyone';
	}

	/**
	 * Sanitize chat-panel visibility. Whitelist must stay in lockstep with
	 * the dropdown choices in SettingsRegistrar::register_messaging_settings()
	 * AND the consumer in Plugin::render_chat_panel().
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized visibility setting.
	 */
	public static function sanitize_chat_panel_visibility( $value ): string {
		$value = is_string( $value ) ? $value : '';
		return in_array( $value, self::WHITELISTS['mvs_chat_panel_visibility'], true ) ? $value : 'everywhere';
	}

	/**
	 * Sanitize filename strategy. Whitelist must stay in lockstep with the
	 * dropdown choices in SettingsRegistrar::register_uploads_settings()
	 * AND the consumer in Services\FilenameStrategy::is_valid_strategy().
	 *
	 * Default fallback matches FilenameStrategy::DEFAULT_UPGRADE so existing
	 * sites preserve prior behaviour when garbage gets posted.
	 *
	 * @since 1.2.1
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized strategy slug.
	 */
	public static function sanitize_filename_strategy( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return in_array( $value, self::WHITELISTS['mvs_filename_strategy'], true ) ? $value : 'original_sanitized';
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
