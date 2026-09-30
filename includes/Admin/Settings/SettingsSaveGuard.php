<?php
/**
 * Keep settings a form did not show from being wiped when it is saved.
 *
 * @package WPMediaVerse
 * @since   2.6.0
 */

namespace WPMediaVerse\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Saves only the options a settings form actually rendered.
 *
 * Core's options.php writes EVERY option registered to the submitted group,
 * and writes null for any the form did not post. So an option registered in a
 * group but not on screen was reset by any Save on that tab:
 * - the four competition types, whose rows are withheld while the master
 *   switch is off, were wiped to off;
 * - the storage driver and AI provider, shown as disabled (nameless) selects
 *   while Pro is inactive, fell back to local / OpenAI;
 * - mvs_comment_edit_window, registered with no field, reset to 15 minutes.
 *
 * One rule instead of a hidden input per field: each form posts the list of
 * names it rendered (read from its own markup, so a disabled select without
 * a name is correctly absent and an unticked checkbox is correctly present),
 * and the allowed_options filter drops every other option from the save.
 * Dropped options are neither updated nor reset.
 */
class SettingsSaveGuard {

	/**
	 * POST field carrying the rendered option names.
	 */
	public const FIELD = 'mvs_rendered_options';

	/**
	 * Register the save filter.
	 */
	public static function register(): void {
		// After core's option_update_filter (priority 10, added by
		// admin-filters.php, which loads AFTER plugins): at an equal priority
		// this ran first, saw no registered settings yet, and did nothing.
		add_filter( 'allowed_options', array( self::class, 'filter_allowed_options' ), 20 );
	}

	/**
	 * Render a form body and append the list of option names it contains.
	 *
	 * @param callable $render Callback that echoes the form's fields.
	 */
	public static function render_with_manifest( callable $render ): void {
		ob_start();
		$render();
		$html = (string) ob_get_clean();

		// Top-level option name of every enabled input: `mvs_pro_settings[x]`
		// is the option `mvs_pro_settings`. Either quote style: core's
		// wp_dropdown_pages() writes name='...' for the page pickers.
		preg_match_all( '/<(?:input|select|textarea)\b(?![^>]*\sdisabled(?:[\s=>]|\/>))[^>]*\bname=["\']([A-Za-z0-9_\-]+)/', $html, $matches );
		$names = array_values( array_unique( $matches[1] ) );

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup produced by the field renderers, each of which escapes its own output.
		printf(
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( self::FIELD ),
			esc_attr( implode( ',', $names ) )
		);
	}

	/**
	 * Drop options the submitted form did not render from this save.
	 *
	 * Runs inside options.php just before its nonce check. It only ever
	 * removes names from the list, so a forged request still fails the check
	 * and writes nothing.
	 *
	 * @param array $allowed Allowed options, keyed by option group.
	 * @return array
	 */
	public static function filter_allowed_options( $allowed ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- narrowing only; options.php verifies the nonce before any write.
		if ( ! is_array( $allowed ) || ! isset( $_POST[ self::FIELD ], $_POST['option_page'] ) ) {
			return $allowed;
		}

		$group = sanitize_key( wp_unslash( $_POST['option_page'] ) );
		if ( 0 !== strpos( $group, SettingsPage::OPTION_GROUP ) || empty( $allowed[ $group ] ) ) {
			return $allowed;
		}

		$rendered = array_filter( array_map( 'sanitize_key', explode( ',', (string) wp_unslash( $_POST[ self::FIELD ] ) ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$allowed[ $group ] = array_values( array_intersect( (array) $allowed[ $group ], $rendered ) );

		return $allowed;
	}
}
