<?php
/**
 * What the member dashboard is made of.
 *
 * Eight sections built eight ways is eight things to keep in step. Each one
 * declares the same shape and the rail renders from the declaration, so
 * "available", "enabled" and "permitted" are decided in ONE place rather than
 * re-argued per tab — which is how Documents ended up gated correctly and the
 * competition tabs ended up switching by hash.
 *
 * A REGISTRY, not an action that echoes markup. `mvs_dashboard_tabs` is an
 * action with subscribers that print their own buttons, and that is exactly why
 * three of them shipped as hash-switching buttons after the others became
 * links: nothing could impose a shape on markup somebody else wrote. A
 * declaration can be rendered any way the page later needs.
 *
 * THREE ABSENCES, AND THEY ARE NOT THE SAME THING:
 *
 *   available — the component providing it is not installed (Documents, no Pro)
 *   enabled   — the owner switched the feature off
 *   capability — this member may not use it
 *
 * All three remove the section from the rail. None of them is "empty": a
 * section with nothing in it still belongs on the rail, showing zero, because
 * "you have no albums" is information and a missing Albums item is confusion.
 *
 * HIDDEN IS NOT GUARDED. Removing a section from the rail hides it from someone
 * browsing; the URL still exists. Every panel and every endpoint behind it
 * checks its own capability. This class decides what to DRAW.
 *
 * @package WPMediaVerse
 * @since   2.4.0
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * The dashboard's section registry.
 *
 * @since 2.4.0
 */
final class DashboardSections {

	/**
	 * Groups, in rail order.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	public const GROUPS = array( 'library', 'compete', 'account' );

	/**
	 * Resolved sections for this request.
	 *
	 * @since 2.4.0
	 * @var array<string, array>|null
	 */
	private static $resolved = null;

	/**
	 * Every section this member can see, in rail order.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string, array> Slug => section.
	 */
	public static function all(): array {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		/**
		 * Declare a dashboard section.
		 *
		 * Each entry is:
		 *
		 *     'documents' => array(
		 *         'label'      => __( 'Documents', 'text-domain' ),
		 *         'group'      => 'library',
		 *         'order'      => 40,
		 *         'available'  => true,          // is the component present
		 *         'enabled'    => true,          // has the owner switched it on
		 *         'capability' => 'read',        // what the member needs
		 *         'count'      => fn() => 31,    // or null for no number
		 *         'url'        => '',            // defaults to /<page>/<slug>/
		 *         'endpoints'  => 'mvs-pro/v1/documents',
		 *     )
		 *
		 * @since 2.4.0
		 *
		 * @param array $sections Declared sections, keyed by slug.
		 */
		$declared = (array) apply_filters( 'mvs_dashboard_sections', self::core_sections() );

		$sections = array();

		foreach ( $declared as $slug => $section ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' === $slug || empty( $section['label'] ) ) {
				// A section with no name cannot be rendered and cannot be
				// reported — dropping it beats drawing a blank rail item.
				continue;
			}

			$section = self::normalise( $slug, (array) $section );

			if ( ! $section['available'] || ! $section['enabled'] ) {
				continue;
			}

			if ( '' !== $section['capability'] && ! current_user_can( $section['capability'] ) ) {
				continue;
			}

			$sections[ $slug ] = $section;
		}

		uasort(
			$sections,
			static function ( array $a, array $b ): int {
				$group = array_search( $a['group'], self::GROUPS, true ) <=> array_search( $b['group'], self::GROUPS, true );

				return $group ?: ( $a['order'] <=> $b['order'] );
			}
		);

		self::$resolved = $sections;

		return $sections;
	}

	/**
	 * Drop the resolved-sections memo.
	 *
	 * The registry memoises per request, so the FIRST caller of all() fixes what
	 * every later one sees — anything that declares a section after that point
	 * is silently ignored, and in tests the first case to touch it decides the
	 * registry for the rest. Both are the same bug; this is the escape hatch.
	 *
	 * @since 2.4.0
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$resolved = null;
	}

	/**
	 * Sections grouped for rendering, empty groups omitted.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string, array<string, array>>
	 */
	public static function grouped(): array {
		$grouped = array();

		foreach ( self::all() as $slug => $section ) {
			$grouped[ $section['group'] ][ $slug ] = $section;
		}

		return $grouped;
	}

	/**
	 * Whether a slug is a section this member can see.
	 *
	 * @since 2.4.0
	 *
	 * @param string $slug Section slug.
	 * @return bool
	 */
	public static function exists( string $slug ): bool {
		return isset( self::all()[ $slug ] );
	}

	/**
	 * Every DECLARED section slug, before any per-member filtering.
	 *
	 * Deliberately not `all()`. `all()` answers "what may this member see",
	 * which depends on who is asking and runs the count closures; a rewrite rule
	 * is registered once, on `init`, for everybody. Asking `all()` there would
	 * build the URL vocabulary out of one visitor's permissions — the first
	 * person to hit an empty cache would decide which sections have URLs for the
	 * whole site.
	 *
	 * This is the vocabulary: the keys, no capability checks, no counts.
	 *
	 * @since 2.4.0
	 *
	 * @return string[]
	 */
	public static function slugs(): array {
		$declared = (array) apply_filters( 'mvs_dashboard_sections', self::core_sections() );

		$slugs = array();

		foreach ( array_keys( $declared ) as $slug ) {
			$slug = sanitize_key( (string) $slug );

			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * The section a request is asking for, falling back to the first.
	 *
	 * Falls back rather than 404s: a member who followed a link to a section
	 * that has since been switched off should land on their dashboard, not on
	 * an error.
	 *
	 * @since 2.4.0
	 *
	 * @param string $requested Requested slug.
	 * @return string
	 */
	public static function resolve( string $requested ): string {
		if ( self::exists( $requested ) ) {
			return $requested;
		}

		$sections = self::all();

		return $sections ? (string) array_key_first( $sections ) : 'media';
	}

	/**
	 * A section's URL.
	 *
	 * @since 2.4.0
	 *
	 * @param string $slug Section slug.
	 * @return string
	 */
	public static function url( string $slug ): string {
		$section = self::all()[ $slug ] ?? null;

		if ( $section && '' !== $section['url'] ) {
			return $section['url'];
		}

		$page = (int) get_option( 'mvs_page_dashboard', 0 );
		$base = $page ? (string) get_permalink( $page ) : home_url( '/' );

		if ( ! get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'mvs_section', $slug, $base );
		}

		return trailingslashit( $base ) . $slug . '/';
	}

	/**
	 * A section's item count, or null when it does not report one.
	 *
	 * Null and zero are different answers: null is "this section does not count
	 * itself", zero is "it counted, and there is nothing".
	 *
	 * @since 2.4.0
	 *
	 * @param string $slug Section slug.
	 * @return int|null
	 */
	public static function count( string $slug ) {
		$section = self::all()[ $slug ] ?? null;

		if ( ! $section || ! is_callable( $section['count'] ) ) {
			return null;
		}

		$count = call_user_func( $section['count'] );

		return is_numeric( $count ) ? (int) $count : null;
	}

	/**
	 * Fill in everything a declaration left out.
	 *
	 * @since 2.4.0
	 *
	 * @param string $slug    Slug.
	 * @param array  $section Declaration.
	 * @return array
	 */
	private static function normalise( string $slug, array $section ): array {
		return array(
			'slug'       => $slug,
			'label'      => (string) $section['label'],
			'group'      => in_array( $section['group'] ?? '', self::GROUPS, true ) ? (string) $section['group'] : 'library',
			'order'      => isset( $section['order'] ) ? (int) $section['order'] : 50,
			// Default TRUE for both, so a declaration that says nothing about
			// availability is taken at its word — a section nobody declares does
			// not appear at all, which is the real gate.
			'available'  => ! isset( $section['available'] ) || (bool) $section['available'],
			'enabled'    => ! isset( $section['enabled'] ) || (bool) $section['enabled'],
			'capability' => isset( $section['capability'] ) ? (string) $section['capability'] : '',
			'count'      => $section['count'] ?? null,
			'url'        => isset( $section['url'] ) ? (string) $section['url'] : '',
			'endpoints'  => isset( $section['endpoints'] ) ? (string) $section['endpoints'] : '',
		);
	}

	/**
	 * The sections Free provides.
	 *
	 * @since 2.4.0
	 *
	 * @return array<string, array>
	 */
	private static function core_sections(): array {
		$repo = Plugin::container()->get( 'media_repository' );
		$user = get_current_user_id();

		return array(
			'media'       => array(
				'label'     => __( 'Media', 'wpmediaverse' ),
				'group'     => 'library',
				'order'     => 10,
				'count'     => static fn() => $repo->count_by_author( $user ),
				'endpoints' => 'mvs/v1/media',
			),
			'albums'      => array(
				'label'     => __( 'Albums', 'wpmediaverse' ),
				'group'     => 'library',
				'order'     => 20,
				'endpoints' => 'mvs/v1/albums',
			),
			'collections' => array(
				'label'     => __( 'Collections', 'wpmediaverse' ),
				'group'     => 'library',
				'order'     => 30,
				'endpoints' => 'mvs/v1/collections',
			),
			'favorites'   => array(
				'label'     => __( 'Favorites', 'wpmediaverse' ),
				'group'     => 'library',
				'order'     => 60,
				'endpoints' => 'mvs/v1/favorites',
			),
			// Editing your profile was a button on a card above the rail, which
			// cost every member ~110px of vertical space on every visit to say
			// what the rail head now says in a line. It is a PANEL like the rest
			// — it always was, toggled by `editingProfile` — so it is declared
			// like the rest and rendered where the rest render.
			//
			// Viewing the profile is NOT here: it navigates away to the
			// community profile, and a rail whose items all switch a panel
			// except one that silently leaves the page is a rail you cannot
			// trust. That one is a link in the rail head, beside the name.
			'profile'     => array(
				'label'     => __( 'Edit profile', 'wpmediaverse' ),
				'group'     => 'account',
				'order'     => 10,
				'endpoints' => 'mvs/v1/profile',
			),
		);
	}
}
