<?php
/**
 * Settings API contract test.
 *
 * Catches the bug class that shipped as commit d986525 (FREE 1.2.0):
 * two `register_setting()` calls for the same option, with conflicting
 * sanitize callbacks AND a whitelist that didn't match the dropdown
 * choices. Browser testing missed it. wppqa missed it. arch-checks
 * missed it. None of the existing automated gates inspect the
 * register_setting() / add_settings_field() contract.
 *
 * R1. No option name appears in more than one register_setting() call.
 * R2. For every add_settings_field() that uses render_select_field with
 *     a 'choices' array, the corresponding option's whitelist (exposed
 *     via Sanitizers::get_whitelist) MUST equal the choices array keys.
 * R3. Select fields MUST NOT use sanitize_text_field / absint / similar
 *     "no whitelist" callbacks. A fixed-choice dropdown demands a named
 *     whitelist sanitizer registered in Sanitizers::WHITELISTS.
 * R4. Type/default sanity: integer-keyed choices => option type 'integer';
 *     string-keyed choices => option type 'string'. Default value MUST be
 *     a member of the choices array.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Admin\Settings\SettingsRegistrar;
use WPMediaVerse\Admin\Settings\Sanitizers;

class SettingsContractTest extends WP_UnitTestCase {

	/**
	 * Snapshot of $wp_registered_settings keyed by option name.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $registered_settings;

	/**
	 * Map of select-field args by option name (only fields that use
	 * render_select_field and pass a 'choices' array — pro-upsell,
	 * page-dropdown, file-types-checkbox renderers are excluded).
	 *
	 * @var array<string, array{choices: array<int|string, mixed>, page_slug: string, section: string}>
	 */
	private array $select_fields;

	protected function setUp(): void {
		parent::setUp();

		// Reset Settings API globals so the test sees only what register_all() registers.
		global $wp_registered_settings, $wp_settings_fields;
		$wp_registered_settings = array();
		$wp_settings_fields     = array();

		// Force admin context so any Pro-conditional code in register_all() takes
		// the same path it would when an admin saves the form.
		set_current_screen( 'options-general' );

		( new SettingsRegistrar() )->register_all();

		$this->registered_settings = $this->index_registered_settings();
		$this->select_fields       = $this->index_select_fields();
	}

	/**
	 * R1 — No option appears in two register_setting() calls.
	 */
	public function test_no_duplicate_register_setting_calls(): void {
		$dups = array();
		foreach ( $this->registered_settings as $option => $entries ) {
			if ( count( $entries ) > 1 ) {
				$dups[ $option ] = count( $entries );
			}
		}
		$this->assertSame(
			array(),
			$dups,
			'Duplicate register_setting() calls — second call silently overwrites the first sanitize_callback. Same bug class as d986525 (dm_access). Offenders: ' . wp_json_encode( $dups )
		);
	}

	/**
	 * R3 — Select-rendered fields MUST use a named whitelist sanitizer.
	 *
	 * sanitize_text_field / absint / sanitize_key accept any value of the
	 * right shape — they cannot reject "garbage" that happens to be a
	 * string or a positive integer. The dm_access bug shipped because the
	 * second register_setting overwrote the enum sanitizer with one of
	 * these "no whitelist" callbacks.
	 */
	public function test_select_fields_use_named_whitelist_sanitizer(): void {
		$violations = array();
		foreach ( $this->select_fields as $option => $field ) {
			$entry = $this->registered_settings[ $option ][0] ?? null;
			if ( null === $entry ) {
				$violations[ $option ] = 'no register_setting() entry';
				continue;
			}
			$callback = $entry['sanitize_callback'];

			if ( null === Sanitizers::get_whitelist( $option ) ) {
				$violations[ $option ] = 'no entry in Sanitizers::WHITELISTS — add one';
				continue;
			}

			if ( ! $this->is_named_whitelist_sanitizer( $callback ) ) {
				$violations[ $option ] = 'sanitize_callback is not a Sanitizers::sanitize_* whitelist method (got ' . $this->describe_callback( $callback ) . ')';
			}
		}
		$this->assertSame(
			array(),
			$violations,
			"Select fields without whitelist sanitizers — admins can save arbitrary values that bypass the dropdown:\n" . $this->format_violations( $violations )
		);
	}

	/**
	 * R2 — Whitelist values MUST equal the dropdown choice keys.
	 *
	 * If the choices say {everyone, followers, mutual, nobody} but the
	 * sanitizer whitelist is {true, false}, every "mutual"/"nobody"
	 * submit gets rewritten to a fallback. That's exactly what dm_access
	 * shipped with.
	 */
	public function test_select_field_choices_match_sanitizer_whitelist(): void {
		$violations = array();
		foreach ( $this->select_fields as $option => $field ) {
			$whitelist = Sanitizers::get_whitelist( $option );
			if ( null === $whitelist ) {
				continue; // Already caught by R3.
			}

			$choice_keys = array_keys( $field['choices'] );
			sort( $choice_keys );
			$wl = $whitelist;
			sort( $wl );

			if ( $choice_keys !== $wl ) {
				$violations[ $option ] = sprintf(
					'choices=%s vs whitelist=%s',
					wp_json_encode( $choice_keys ),
					wp_json_encode( $wl )
				);
			}
		}
		$this->assertSame(
			array(),
			$violations,
			"Dropdown choices do not match sanitizer whitelist — submitted values get silently rewritten:\n" . $this->format_violations( $violations )
		);
	}

	/**
	 * R4 — Type / default sanity for select-driven options.
	 */
	public function test_select_field_type_and_default_sanity(): void {
		$violations = array();
		foreach ( $this->select_fields as $option => $field ) {
			$entry = $this->registered_settings[ $option ][0] ?? null;
			if ( null === $entry ) {
				continue;
			}

			$choice_keys = array_keys( $field['choices'] );
			$declared    = (string) ( $entry['type'] ?? '' );
			$default     = $entry['default'] ?? null;

			$all_int    = array_reduce( $choice_keys, static fn( $c, $k ) => $c && is_int( $k ), true );
			$all_string = array_reduce( $choice_keys, static fn( $c, $k ) => $c && is_string( $k ), true );

			if ( $all_int && 'integer' !== $declared ) {
				$violations[ $option . '@type' ] = "integer choices but type='{$declared}'";
			}
			if ( $all_string && 'string' !== $declared ) {
				$violations[ $option . '@type' ] = "string choices but type='{$declared}'";
			}

			// Default must be a member of the choices array.
			if ( $all_int ) {
				$default_int = (int) $default;
				if ( ! in_array( $default_int, $choice_keys, true ) ) {
					$violations[ $option . '@default' ] = "default={$default} not in choices " . wp_json_encode( $choice_keys );
				}
			} elseif ( $all_string ) {
				if ( ! in_array( (string) $default, $choice_keys, true ) ) {
					$violations[ $option . '@default' ] = "default='{$default}' not in choices " . wp_json_encode( $choice_keys );
				}
			}
		}
		$this->assertSame(
			array(),
			$violations,
			"Select field type/default mismatches:\n" . $this->format_violations( $violations )
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers (introspection)
	// -------------------------------------------------------------------------

	/**
	 * Walk $wp_registered_settings and group entries by option name.
	 *
	 * Each entry is a snapshot of the args array passed to register_setting().
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function index_registered_settings(): array {
		global $wp_registered_settings;

		$indexed = array();
		if ( ! is_array( $wp_registered_settings ) ) {
			return $indexed;
		}

		// Plus a "shadow" tracker that counts how many times register_setting()
		// was called for each option. WP only stores the last call's args, so
		// we hook 'sanitize_option_*' filter additions to count ourselves —
		// but those aren't exposed cleanly. Instead, we count by replaying:
		// re-bootstrap register_all into a counting wrapper.
		foreach ( $wp_registered_settings as $option => $args ) {
			$indexed[ $option ] = array( $args );
		}

		// Detect duplicate register_setting() calls deterministically by
		// re-running register_all() with a register_setting filter.
		$call_counts = $this->count_register_setting_calls();
		foreach ( $call_counts as $option => $count ) {
			if ( $count > 1 && isset( $indexed[ $option ] ) ) {
				// Fake-pad to reflect the duplication; first entry is the args
				// from the last call (WP's behaviour), and the duplicate
				// markers are placeholders consumed only by the count check.
				$indexed[ $option ] = array_pad(
					$indexed[ $option ],
					$count,
					array( '_duplicate_marker' => true )
				);
			}
		}

		return $indexed;
	}

	/**
	 * Count register_setting() invocations per option by re-running
	 * register_all() with a wrapper.
	 *
	 * @return array<string, int>
	 */
	private function count_register_setting_calls(): array {
		// 'register_setting_args' filter signature is
		//   apply_filters( 'register_setting_args', $args, $defaults, $option_group, $option_name )
		// (see /wp-includes/option.php). We use it as a no-op pass-through that
		// also counts how many times each option was registered.
		$counts = array();

		$filter = static function ( $args, $defaults, $option_group, $option_name ) use ( &$counts ) {
			$counts[ $option_name ] = ( $counts[ $option_name ] ?? 0 ) + 1;
			return $args;
		};

		add_filter( 'register_setting_args', $filter, 99, 4 );
		( new SettingsRegistrar() )->register_all();
		remove_filter( 'register_setting_args', $filter, 99 );

		return $counts;
	}

	/**
	 * Walk $wp_settings_fields and pick out the ones bound to render_select_field.
	 *
	 * Excludes render_pro_select_field (upsell, no real choice array under
	 * 'choices'), render_page_dropdown_field (uses wp_dropdown_pages), and
	 * checkbox/text/number/password renderers.
	 *
	 * @return array<string, array{choices: array<int|string, mixed>, page_slug: string, section: string}>
	 */
	private function index_select_fields(): array {
		global $wp_settings_fields;

		$found = array();
		if ( ! is_array( $wp_settings_fields ) ) {
			return $found;
		}

		foreach ( $wp_settings_fields as $page_slug => $sections ) {
			foreach ( $sections as $section_id => $fields ) {
				foreach ( $fields as $field_id => $field ) {
					$callback = $field['callback'] ?? null;
					if ( ! is_array( $callback ) || count( $callback ) !== 2 ) {
						continue;
					}
					if ( 'render_select_field' !== $callback[1] ) {
						continue;
					}

					$args = $field['args'] ?? array();
					if ( empty( $args['choices'] ) || ! is_array( $args['choices'] ) ) {
						continue;
					}
					$option = $args['option'] ?? $field_id;

					$found[ $option ] = array(
						'choices'   => $args['choices'],
						'page_slug' => $page_slug,
						'section'   => $section_id,
					);
				}
			}
		}

		return $found;
	}

	/**
	 * Is this callback a Sanitizers::sanitize_* method that registers a whitelist?
	 *
	 * @param mixed $callback Sanitize callback.
	 */
	private function is_named_whitelist_sanitizer( $callback ): bool {
		if ( ! is_array( $callback ) || count( $callback ) !== 2 ) {
			return false;
		}
		[ $class, $method ] = $callback;
		if ( Sanitizers::class !== $class ) {
			return false;
		}
		// Method must exist on Sanitizers (defensive).
		return is_string( $method ) && method_exists( Sanitizers::class, $method );
	}

	/**
	 * Human-readable callback description for failure messages.
	 *
	 * @param mixed $callback Sanitize callback.
	 */
	private function describe_callback( $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}
		if ( is_array( $callback ) && count( $callback ) === 2 ) {
			[ $class, $method ] = $callback;
			$class              = is_object( $class ) ? get_class( $class ) : (string) $class;
			return $class . '::' . $method;
		}
		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}
		return gettype( $callback );
	}

	/**
	 * Pretty-print a violations map for assertion messages.
	 *
	 * @param array<string, string> $violations Map of option => reason.
	 */
	private function format_violations( array $violations ): string {
		$lines = array();
		foreach ( $violations as $option => $reason ) {
			$lines[] = '  - ' . $option . ': ' . $reason;
		}
		return implode( "\n", $lines );
	}
}
