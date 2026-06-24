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
	 * The full universe of MIME types the Allowed File Types picker renders.
	 *
	 * Single source of truth shared by FieldRenderer (draws the checkbox grid)
	 * and sanitize_file_types() (reconciles a submission). Reconcile only touches
	 * types in THIS universe — MIME types added in code via the
	 * `mvs_allowed_file_types` filter are outside the picker and are preserved
	 * across a save, never dropped when a box is unchecked. Keep in lockstep with
	 * the grid in FieldRenderer::render_file_types_field().
	 *
	 * @var array<int, string>
	 */
	public const KNOWN_FILE_TYPES = array(
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',
		'video/mp4',
		'video/webm',
		'audio/mpeg',
		'audio/ogg',
	);

	/**
	 * Hidden sentinel field name proving the Allowed File Types control was on
	 * the submitted page, so sanitize_file_types() can tell "present but empty =
	 * remove all" apart from "field absent = preserve current value". FieldRenderer
	 * always prints this hidden input next to the grid.
	 *
	 * @var string
	 */
	public const FILE_TYPES_PRESENT_FIELD = 'mvs_allowed_file_types_present';

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
		'mvs_ai_provider'            => array( 'openai', 'google_vision', 'rekognition', 'anthropic' ),
		'mvs_openai_model'           => array( 'gpt-4o-mini', 'gpt-4o' ),
		'mvs_moderation_auto_action' => array( 'flag', 'hide', 'reject', 'delete' ),
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
	 * Sanitize the Allowed File Types submission.
	 *
	 * Unchecked checkboxes send nothing in POST, so a "zero boxes checked"
	 * submission arrives here as an empty/absent value that looks identical to
	 * "this field was never on the page". WP's options.php walks EVERY option in
	 * the group, so this callback runs even for absent fields. We disambiguate
	 * with a hidden sentinel (FieldRenderer always prints it next to the grid):
	 *
	 *   - Sentinel ABSENT  -> the picker was not on the submitted page. Preserve
	 *     the current stored value (no change). This also guards against future
	 *     callers that save the group without the file-types UI present.
	 *
	 *   - Sentinel PRESENT -> the picker WAS submitted. The submitted checkboxes
	 *     are the authoritative set of KNOWN types the user wants. We reconcile
	 *     against self::KNOWN_FILE_TYPES: every known type that was
	 *     NOT checked is removed, even on the first uncheck. MIME types added in
	 *     code via the `mvs_allowed_file_types` filter live outside the picker
	 *     universe, so any currently-stored custom types are preserved.
	 *
	 *   - Sentinel PRESENT but ZERO boxes checked -> "remove all" — the value is
	 *     persisted as an empty string (minus any preserved custom types).
	 *     Uploads then reject every type until the admin re-enables one.
	 *
	 * @param mixed $value Input (array from checkboxes, or empty when none checked).
	 * @return string Comma-separated MIME types.
	 */
	public static function sanitize_file_types( $value ): string {
		$stored = (string) get_option( 'mvs_allowed_file_types', SettingsRegistrar::DEFAULT_ALLOWED_FILE_TYPES );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by options.php / the Settings API before this sanitize_callback runs.
		$field_present = isset( $_POST[ self::FILE_TYPES_PRESENT_FIELD ] );

		// Field not on the submitted page: leave the stored value untouched.
		if ( ! $field_present ) {
			return $stored;
		}

		// Submitted (checked) known types. Normally an array of checkbox values.
		// But the persistence path (pre_update_option deletes the option so the
		// new value is INSERTed past WP's update_option->add_option no-op) makes
		// add_option() re-run this callback on the already-sanitized CSV *string*.
		// Treat that string as the submitted set so the second pass is idempotent
		// instead of seeing "not an array" and collapsing a real selection to ''
		// (which would silently disable every upload type).
		$submitted = array();
		if ( is_array( $value ) ) {
			$submitted = array_map( 'sanitize_mime_type', $value );
		} elseif ( is_string( $value ) && '' !== $value ) {
			$submitted = array_map( 'sanitize_mime_type', array_map( 'trim', explode( ',', $value ) ) );
		}
		$submitted = array_filter( $submitted );

		$known = self::KNOWN_FILE_TYPES;

		// Keep only submitted types that belong to the picker universe — a
		// checked known type stays, everything else from the form is ignored.
		$kept_known = array_values( array_intersect( $known, $submitted ) );

		// Preserve currently-stored types that are OUTSIDE the picker universe
		// (added in code via the mvs_allowed_file_types filter). The picker can
		// never represent them, so unchecking boxes must not wipe them.
		$stored_types  = array_filter( array_map( 'trim', explode( ',', $stored ) ) );
		$custom_stored = array_values( array_diff( $stored_types, $known ) );

		$result = array_values( array_unique( array_merge( $custom_stored, $kept_known ) ) );

		// Present-but-empty (and no custom types) = remove all -> ''.
		return implode( ',', $result );
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
		return in_array( $value, self::WHITELISTS['mvs_thumbnail_size'], true ) ? $value : self::WHITELISTS['mvs_thumbnail_size'][0];
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
	 * Sanitize the AI moderation flag-criteria rule.
	 *
	 * Keeps only known categories and never returns an empty set — an empty rule
	 * would silently disable all AI flagging, so unchecking everything falls
	 * back to every category (matching the register_setting default).
	 *
	 * @param mixed $value Submitted value (array of category keys).
	 * @return string[] Sanitized category keys.
	 */
	public static function sanitize_ai_moderation_categories( $value ): array {
		$allowed = \WPMediaVerse\Services\AIService::MODERATION_CATEGORIES;
		$value   = is_array( $value ) ? $value : array();
		$clean   = array_values( array_intersect( array_map( 'sanitize_text_field', $value ), $allowed ) );
		return empty( $clean ) ? $allowed : $clean;
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
	 * Default fallback matches FilenameStrategy::effective_default() (hashed
	 * since 1.6.0, filterable) so garbage input lands on the same default the
	 * runtime resolver uses (audit 2026-06-04, #9962530792).
	 *
	 * @since 1.2.1
	 *
	 * @param mixed $value Raw input.
	 * @return string Sanitized strategy slug.
	 */
	public static function sanitize_filename_strategy( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return in_array( $value, self::WHITELISTS['mvs_filename_strategy'], true )
			? $value
			: \WPMediaVerse\Services\FilenameStrategy::effective_default();
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
