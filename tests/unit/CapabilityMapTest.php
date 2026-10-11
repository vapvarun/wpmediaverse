<?php
/**
 * Capability grants and what each capability is allowed to authorise.
 *
 * WordPress capability names do not say whether they mean "your own" or
 * "anyone's". Pro read `edit_mvs_media` as "may edit any media"; Free grants it
 * to every role including Subscriber and means "your own", so any member with
 * an account could rewrite any other member's video chapters. Nothing in the
 * pipeline noticed, because every layer was asking whether the code worked.
 *
 * Two things are pinned here:
 *
 *   1. the role -> capability map, so WIDENING a grant fails CI rather than
 *      silently turning every existing `current_user_can()` into a bigger
 *      permission than its author intended;
 *   2. own-scope capabilities are never used as a bare authority check, which
 *      is the exact shape of the chapters bug.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class CapabilityMapTest extends WP_UnitTestCase {

	private const MAP = __DIR__ . '/../../audit/capability-map.json';

	/** @var array */
	private array $map = array();

	public function set_up(): void {
		parent::set_up();

		$this->map = (array) json_decode( (string) file_get_contents( self::MAP ), true );
	}

	/**
	 * Every mvs_* capability a role actually holds, from the live roles.
	 *
	 * @return array<string, string[]>
	 */
	private function live_grants(): array {
		$out = array();

		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = array_keys( array_filter( (array) ( $role['capabilities'] ?? array() ) ) );
			$mvs  = array_values(
				array_filter(
					$caps,
					static function ( $cap ) {
						return 0 === strpos( (string) $cap, 'mvs_' ) || false !== strpos( (string) $cap, '_mvs_' );
					}
				)
			);

			sort( $mvs );

			if ( $mvs ) {
				$out[ $slug ] = $mvs;
			}
		}

		return $out;
	}

	public function test_role_grants_match_the_declared_map(): void {
		$declared = (array) ( $this->map['roles'] ?? array() );
		$live     = $this->live_grants();

		foreach ( $live as $role => $caps ) {
			$this->assertArrayHasKey( $role, $declared, "Role {$role} now holds mvs capabilities and is not declared in audit/capability-map.json." );

			$added = array_values( array_diff( $caps, (array) $declared[ $role ] ) );

			$this->assertSame(
				array(),
				$added,
				"Role {$role} gained capabilities that were never declared: " . implode( ', ', $added )
					. "\nWidening a grant silently widens every current_user_can() that names it. "
					. 'Declare it in audit/capability-map.json, with its scope, and re-read the callbacks that use it.'
			);
		}

		foreach ( $declared as $role => $caps ) {
			$removed = array_values( array_diff( (array) $caps, (array) ( $live[ $role ] ?? array() ) ) );

			$this->assertSame( array(), $removed, "Role {$role} no longer holds declared capabilities: " . implode( ', ', $removed ) );
		}
	}

	public function test_every_granted_capability_declares_a_scope(): void {
		$scope   = (array) ( $this->map['scope'] ?? array() );
		$missing = array();

		foreach ( $this->live_grants() as $caps ) {
			foreach ( $caps as $cap ) {
				if ( ! isset( $scope[ $cap ] ) ) {
					$missing[ $cap ] = true;
				}
			}
		}

		$this->assertSame(
			array(),
			array_keys( $missing ),
			'These capabilities are granted but their scope is undeclared. Say whether each means own, any, site or feature.'
		);
	}

	/**
	 * An own-scope capability is not authority over someone else's object.
	 */
	public function test_own_scope_capabilities_are_not_used_as_blanket_authority(): void {
		$scope     = (array) ( $this->map['scope'] ?? array() );
		$allowlist = (array) ( $this->map['bare_check_allowlist'] ?? array() );
		$own       = array_keys(
			array_filter(
				$scope,
				static function ( $value ) {
					return 'own' === $value;
				}
			)
		);

		$this->assertNotEmpty( $own, 'No own-scope capabilities declared; the map is not doing its job.' );

		$root      = dirname( __DIR__, 2 ) . '/includes';
		$offenders = array();

		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root ) );

		foreach ( $files as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$lines = (array) file( $file->getPathname() );

			foreach ( $lines as $number => $line ) {
				foreach ( $own as $cap ) {
					if ( false === strpos( (string) $line, "current_user_can( '" . $cap . "' )" ) ) {
						continue;
					}

					// Paired with an ownership comparison in the same condition is the
					// correct idiom - `get_author( $id ) === $user_id && current_user_can( ... )`.
					// The dangerous shape is the capability standing alone, which is how the
					// chapters bug read an own-scope capability as site-wide authority.
					if ( false !== strpos( (string) $line, 'get_author(' ) || false !== strpos( (string) $line, 'post_author' ) ) {
						continue;
					}

					// Keyed by the enclosing method, not the line: a line number moves
					// whenever anything above it changes.
					$key = str_replace( dirname( __DIR__, 2 ) . '/', '', $file->getPathname() ) . '::' . self::enclosing_function( $lines, $number );

					if ( isset( $allowlist[ $key ] ) && '' !== trim( (string) $allowlist[ $key ] ) ) {
						continue;
					}

					$offenders[] = $key . ' — current_user_can( \'' . $cap . '\' ) as authority. That capability means "your own"'
						. ' and every role holds it, so this grants it over anyone\'s object. Compare the owner, or use an any-scope capability.';
				}
			}
		}

		$this->assertSame( array(), $offenders, implode( "\n", $offenders ) );
	}

	/**
	 * Name of the function a line sits in, by the nearest declaration above it.
	 *
	 * @param array<int, string> $lines  File lines.
	 * @param int                $number Zero-based line index.
	 * @return string Function name, or 'file' outside any function.
	 */
	private static function enclosing_function( array $lines, int $number ): string {
		for ( $i = $number; $i >= 0; $i-- ) {
			if ( preg_match( '/\bfunction\s+(\w+)\s*\(/', (string) $lines[ $i ], $m ) ) {
				return $m[1];
			}
		}
		return 'file';
	}
}
