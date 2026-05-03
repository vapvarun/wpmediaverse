<?php
/**
 * Standard attributes injected into every Free Gutenberg block.
 *
 * Mirrors the canonical Wbcom block-standard schema implemented in
 * wbcom-essential v4.5.0 `plugins/gutenberg/src/shared/utils/attributes.js`
 * and documented at `~/.claude/skills/wp-block-development/references/block-quality-standard.md`
 * Section 15. The PHP shape declared here matches the JS shape exactly so a
 * block can declare schema once (in JS, via the shared `src/shared/utils/`
 * helpers) and consume it both editor-side AND server-side without drift.
 *
 * Wired to the `block_type_metadata` filter by `BlockRegistrar::init()` —
 * any block whose name starts with `mvs/` (and is NOT a Pro `mvs/pro-`
 * block, which Pro's own filter handles) automatically inherits this
 * schema. Block-specific attributes win on collision so a block can
 * override a default by declaring it in its own `block.json`.
 *
 * `align` is intentionally not in this list — it belongs in `supports`
 * (`{"align": ["wide", "full"]}`) where WordPress core consumes it.
 *
 * @package WPMediaVerse
 * @since   1.2.0
 */

namespace WPMediaVerse\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Canonical Wbcom block-standard attribute schema (PHP side).
 */
class StandardAttributes {

	/**
	 * Spacing schema — per-side padding/margin objects with responsive
	 * tablet/mobile variants and unit switching. Tablet/mobile defaults
	 * are absent so they inherit desktop unless explicitly set.
	 *
	 * `type: 'object'` lets WP REST validate the shape without rejecting
	 * the bracketed-form GET-encoding that `<ServerSideRender>` uses for
	 * editor previews — see
	 * `~/.claude/skills/wp-block-development/references/editor-preview-and-ssr.md`
	 * Rule 1 for the why.
	 */
	public const SPACING = array(
		'padding'       => array(
			'type'    => 'object',
			'default' => array(
				'top'    => 24,
				'right'  => 24,
				'bottom' => 24,
				'left'   => 24,
			),
		),
		'paddingTablet' => array( 'type' => 'object' ),
		'paddingMobile' => array( 'type' => 'object' ),
		'paddingUnit'   => array(
			'type'    => 'string',
			'default' => 'px',
		),
		'margin'        => array(
			'type'    => 'object',
			'default' => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
		),
		'marginTablet'  => array( 'type' => 'object' ),
		'marginMobile'  => array( 'type' => 'object' ),
		'marginUnit'    => array(
			'type'    => 'string',
			'default' => 'px',
		),
	);

	/**
	 * Typography schema. Numeric attributes have NO default (omitted
	 * `default` key) so the editor's "0 means use the theme default"
	 * convention from
	 * `~/.claude/skills/wp-block-development/references/editor-preview-and-ssr.md`
	 * Rule 1 still holds for blocks that don't carry typography
	 * configuration.
	 */
	public const TYPOGRAPHY = array(
		'fontFamily'     => array(
			'type'    => 'string',
			'default' => '',
		),
		'fontSize'       => array( 'type' => 'number' ),
		'fontSizeTablet' => array( 'type' => 'number' ),
		'fontSizeMobile' => array( 'type' => 'number' ),
		'fontSizeUnit'   => array(
			'type'    => 'string',
			'default' => 'px',
		),
		'fontWeight'     => array(
			'type'    => 'string',
			'default' => '',
		),
		'lineHeight'     => array( 'type' => 'number' ),
		'lineHeightUnit' => array(
			'type'    => 'string',
			'default' => '',
		),
		'letterSpacing'  => array( 'type' => 'number' ),
		'textTransform'  => array(
			'type'    => 'string',
			'default' => '',
		),
	);

	/**
	 * Box-shadow schema. `boxShadow` boolean is the on/off toggle; the
	 * editor only emits the other shadow declarations when it's `true`.
	 */
	public const SHADOW = array(
		'boxShadow'        => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'shadowHorizontal' => array(
			'type'    => 'number',
			'default' => 0,
		),
		'shadowVertical'   => array(
			'type'    => 'number',
			'default' => 4,
		),
		'shadowBlur'       => array(
			'type'    => 'number',
			'default' => 8,
		),
		'shadowSpread'     => array(
			'type'    => 'number',
			'default' => 0,
		),
		'shadowColor'      => array(
			'type'    => 'string',
			'default' => 'rgba(0, 0, 0, 0.12)',
		),
	);

	/**
	 * Border schema. `borderRadius` is per-corner like padding/margin.
	 */
	public const BORDER = array(
		'borderRadius'     => array(
			'type'    => 'object',
			'default' => array(
				'top'    => 0,
				'right'  => 0,
				'bottom' => 0,
				'left'   => 0,
			),
		),
		'borderRadiusUnit' => array(
			'type'    => 'string',
			'default' => 'px',
		),
	);

	/**
	 * Per-device visibility schema.
	 */
	public const VISIBILITY = array(
		'hideOnDesktop' => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'hideOnTablet'  => array(
			'type'    => 'boolean',
			'default' => false,
		),
		'hideOnMobile'  => array(
			'type'    => 'boolean',
			'default' => false,
		),
	);

	/**
	 * Per-instance scoping ID. Populated client-side by
	 * `useUniqueId()` from `src/shared/hooks/useUniqueId.js` on insert;
	 * server-side `MVS_CSS::generate()` keys all per-instance CSS off it.
	 */
	public const UNIQUE_ID = array(
		'uniqueId' => array(
			'type'    => 'string',
			'default' => '',
		),
	);

	/**
	 * Returns the union of every standard attribute group, merged left
	 * to right so later groups can't shadow earlier ones (none currently
	 * collide; this is defence-in-depth against future additions).
	 *
	 * @return array<string, array{type:string, default?:mixed}>
	 */
	public static function schema(): array {
		return array_merge(
			self::UNIQUE_ID,
			self::SPACING,
			self::TYPOGRAPHY,
			self::SHADOW,
			self::BORDER,
			self::VISIBILITY
		);
	}

	/**
	 * Filter callback for `block_type_metadata`.
	 *
	 * Merges the standard schema into any block whose `name` starts with
	 * `mvs/` BUT NOT `mvs/pro-` (Pro's own filter handles those — keeps
	 * each plugin's scope clean even though both filters would safely
	 * idempotent-merge if both ran). Block-specific attributes win on
	 * collision — a block can override a default (e.g. set `borderRadius`
	 * to `{ top: 8, right: 8, bottom: 8, left: 8 }`) by declaring it in
	 * its own `block.json`.
	 *
	 * @param array<string, mixed> $metadata Raw metadata from `block.json`.
	 * @return array<string, mixed>
	 */
	public static function inject( array $metadata ): array {
		$name = isset( $metadata['name'] ) ? (string) $metadata['name'] : '';
		if ( '' === $name || ! str_starts_with( $name, 'mvs/' ) || str_starts_with( $name, 'mvs/pro-' ) ) {
			return $metadata;
		}
		$existing               = isset( $metadata['attributes'] ) && is_array( $metadata['attributes'] )
			? $metadata['attributes']
			: array();
		$metadata['attributes'] = array_merge( self::schema(), $existing );
		return $metadata;
	}

	/**
	 * Visibility class names for a block wrapper.
	 *
	 * Each `hideOn*` boolean translates to a CSS-only utility class the
	 * shared `src/shared/base.css` keys off. Returns a space-separated
	 * class list (may be empty).
	 *
	 * @param array<string, mixed> $attributes Block attributes.
	 * @return string
	 */
	public static function visibility_classes( array $attributes ): string {
		$classes = array();
		if ( ! empty( $attributes['hideOnDesktop'] ) ) {
			$classes[] = 'mvs-hide-desktop';
		}
		if ( ! empty( $attributes['hideOnTablet'] ) ) {
			$classes[] = 'mvs-hide-tablet';
		}
		if ( ! empty( $attributes['hideOnMobile'] ) ) {
			$classes[] = 'mvs-hide-mobile';
		}
		return implode( ' ', $classes );
	}
}
