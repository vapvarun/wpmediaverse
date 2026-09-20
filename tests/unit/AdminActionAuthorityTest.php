<?php
/**
 * Every Free wp_ajax_* / admin_post_* handler declares who may fire it, and the
 * declaration is checked against the callback's own source.
 *
 * Pro carries the same test over its own namespace and its own manifest.
 *
 * WHY THIS SURFACE. A REST route has a permission_callback: one named thing, in
 * one place, that RouteAuthorityTest can read off the registry. An admin action
 * has nothing. `add_action( 'wp_ajax_mvs_import_demo_data', ... )` publishes a
 * state-changing endpoint at /wp-admin/admin-ajax.php reachable by ANY logged-in
 * user who knows the action name, and the only thing between a subscriber and
 * the mutation is whatever the callback body happens to check. Nothing in this
 * pipeline asks whether it checks anything: WPCS accepts a handler with no
 * capability check, PHPStan accepts it, the contract audit accepts it, the
 * browser smoke only ever drives these screens as an administrator.
 *
 * So the claim moves into audit/admin-action-authority.json, where it is checked:
 *
 *   1. a registered handler missing from the manifest fails - a new admin action
 *      cannot ship without someone stating who may fire it;
 *   2. a manifest entry with no live handler fails, unless it is declared
 *      conditional with conditional_on naming what mounts it (same escape hatch
 *      Pro's RouteAuthorityTest uses for feature toggles);
 *   3. every state-changing handler must resolve to BOTH a nonce check and a
 *      capability check. This is read out of the callback's SOURCE via
 *      ReflectionMethod::getFileName() + getStartLine()/getEndLine(), so it is
 *      the shipped code being checked and not a comment about it. A handler that
 *      delegates to a named guard declares that guard in `delegates_to` and the
 *      guard's source is read instead - the delegation is a stated fact, not an
 *      inference the test makes from a method name;
 *   4. a handler whose authority genuinely IS "logged in" declares audience
 *      member with a reason, exactly like a public REST route;
 *   5. any *_nopriv_ handler declares a reason. Free registers none today; the
 *      rule exists so the first one cannot land silently.
 *
 * WHY THE LIVE WALK IS A UNION. Free's admin actions register inside
 * `if ( is_admin() )` in Plugin::init(), which runs at bootstrap - long before a
 * test can flip WP_ADMIN. A pure $GLOBALS['wp_filter'] walk in a PHPUnit process
 * therefore finds ZERO handlers and every check above passes on an empty set,
 * which is the exact vacuous-pass this test exists to prevent. So live_handlers()
 * is the union of the runtime registry walk (catches anything registered
 * dynamically, including from another plugin's context) and a source sweep of
 * this plugin's own includes/ for the four hook prefixes (catches everything
 * hidden behind is_admin()). The two agree exactly when the sweep is run in an
 * admin request - verified 2026-09-20: the runtime walk with WP_ADMIN defined
 * returned the same 4 live handlers the sweep finds, plus the sweep's fifth,
 * admin_post_mvs_report_status, which Pro's presence switches off.
 * test_the_live_walk_is_not_empty() is the tripwire on the union itself.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use ReflectionMethod;
use ReflectionFunction;

class AdminActionAuthorityTest extends WP_UnitTestCase {

	private const MANIFEST = __DIR__ . '/../../audit/admin-action-authority.json';

	/** Source root swept for registrations hidden behind is_admin(). */
	private const SOURCE_ROOT = __DIR__ . '/../../includes';

	/**
	 * Only handlers whose callback lives in this namespace are ours.
	 *
	 * The TRAILING BACKSLASH is the whole separation between Free and Pro.
	 * Pro's ConnectionTester registers four wp_ajax_mvs_pro_test_* handlers
	 * OUTSIDE is_admin(), so they really are in $GLOBALS['wp_filter'] during
	 * this test - Free's bootstrap loads Pro. WPMediaVersePro\\Core\\ConnectionTester
	 * does not start with "WPMediaVerse\\", so they fall out here and Free's
	 * manifest is never asked to declare them. Drop the backslash and this
	 * test demands all four - which is the mutation that proves the walk is
	 * live rather than empty.
	 */
	private const NAMESPACE_PREFIX = 'WPMediaVerse\\';

	private const PREFIXES = array( 'wp_ajax_nopriv_', 'admin_post_nopriv_', 'wp_ajax_', 'admin_post_' );

	private const NONCE_FUNCTIONS = array( 'check_admin_referer', 'check_ajax_referer', 'wp_verify_nonce' );

	private const CAPABILITY_FUNCTIONS = array( 'current_user_can', 'user_can' );

	/** @var array<string, array<string, mixed>> */
	private array $manifest = array();

	public function set_up(): void {
		parent::set_up();

		$this->manifest = (array) ( json_decode( (string) file_get_contents( self::MANIFEST ), true )['handlers'] ?? array() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// The live surface
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Every admin-action handler this plugin registers, hook => Class::method.
	 *
	 * @return array<string, string>
	 */
	private function live_handlers(): array {
		$out = $this->registry_walk() + $this->source_sweep();
		ksort( $out );

		return $out;
	}

	/**
	 * Runtime half: $GLOBALS['wp_filter'].
	 *
	 * @return array<string, string>
	 */
	private function registry_walk(): array {
		$out = array();

		foreach ( $GLOBALS['wp_filter'] as $hook => $object ) {
			if ( ! $this->is_admin_action_hook( (string) $hook ) ) {
				continue;
			}

			foreach ( $object->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$name = $this->callback_name( $callback['function'] );

					if ( $this->is_ours( $name ) ) {
						$out[ $hook ] = $name;
					}
				}
			}
		}

		return $out;
	}

	/**
	 * Static half: add_action() registrations in this plugin's own source.
	 *
	 * Needed because is_admin() is false in a test process, so the classes that
	 * register these hooks are never constructed. See the class docblock.
	 *
	 * @return array<string, string>
	 */
	private function source_sweep(): array {
		$out = array();

		$files = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( self::SOURCE_ROOT, \FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $files as $file ) {
			if ( 'php' !== strtolower( $file->getExtension() ) ) {
				continue;
			}

			$src = (string) file_get_contents( $file->getPathname() );
			if ( false === strpos( $src, 'add_action' ) ) {
				continue;
			}

			// Comments out, or a registration someone commented out still reads
			// as live and the rot check can never fail. Proved: commenting out
			// admin_post_mvs_pro_dismiss_report left this test green until the
			// stripper landed.
			$src = $this->without_comments( $src );

			preg_match( '/^namespace\s+([^;]+);/m', $src, $ns );
			preg_match( '/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cn );
			$class = ( $ns && $cn ) ? trim( $ns[1] ) . '\\' . $cn[1] : '';

			// Two registration shapes: a literal hook name, and a prefix
			// concatenated with a class constant (CloudOpsManager does this).
			// The action segment is [A-Za-z0-9_-], not [a-z0-9_-]: wp_ajax_{$action}
			// interpolates $_REQUEST['action'] verbatim and hook names are
			// case-sensitive, so an upper-case action is a real, registrable
			// endpoint. Proved: an admin_post_mvs_pro_MUTANT registration slipped
			// past a lower-case-only pattern and this test stayed green.
			$pattern = '/add_action\(\s*(?:'
				. '\'(wp_ajax_nopriv_|admin_post_nopriv_|wp_ajax_|admin_post_)([A-Za-z0-9_\-]*)\''
				. '|\'(wp_ajax_nopriv_|admin_post_nopriv_|wp_ajax_|admin_post_)\'\s*\.\s*(?:self|static)::([A-Z_0-9]+)'
				. ')\s*,\s*(.{0,140}?)\)/s';

			preg_match_all( $pattern, $src, $matches, PREG_SET_ORDER );

			foreach ( $matches as $hit ) {
				if ( '' !== $hit[1] ) {
					$hook = $hit[1] . $hit[2];
				} elseif ( $class && defined( $class . '::' . $hit[4] ) ) {
					$hook = $hit[3] . (string) constant( $class . '::' . $hit[4] );
				} else {
					continue;
				}

				$name = $this->sweep_callback_name( (string) $hit[5], $class );

				if ( $this->is_ours( $name ) ) {
					$out[ $hook ] = $name;
				}
			}
		}

		return $out;
	}

	/**
	 * Resolve the callback argument of an add_action() call found in source.
	 *
	 * @param string $argument Raw source of the callback argument.
	 * @param string $class    Class the call sits inside.
	 * @return string
	 */
	private function sweep_callback_name( string $argument, string $class ): string {
		if ( preg_match( '/array\(\s*\$this\s*,\s*\'(\w+)\'/', $argument, $m ) ) {
			return $class . '::' . $m[1];
		}
		if ( preg_match( '/array\(\s*(?:self::class|static::class|__CLASS__)\s*,\s*\'(\w+)\'/', $argument, $m ) ) {
			return $class . '::' . $m[1];
		}
		if ( preg_match( '/array\(\s*([\\\\\w]+)::class\s*,\s*\'(\w+)\'/', $argument, $m ) ) {
			return ltrim( $m[1], '\\' ) . '::' . $m[2];
		}
		if ( preg_match( '/^\s*\'([\\\\\w]+)::(\w+)\'/', $argument, $m ) ) {
			return ltrim( $m[1], '\\' ) . '::' . $m[2];
		}

		// A closure or a shape this sweep cannot read. Still ours if it is in
		// one of our files, and still has to be declared - so report it under a
		// name the failure message can carry.
		return $class . '::CLOSURE_OR_UNRESOLVED';
	}

	/**
	 * PHP source with every comment removed.
	 *
	 * @param string $src Source.
	 * @return string
	 */
	private function without_comments( string $src ): string {
		$out = '';

		foreach ( token_get_all( $src ) as $token ) {
			if ( is_array( $token ) ) {
				if ( T_COMMENT === $token[0] || T_DOC_COMMENT === $token[0] ) {
					continue;
				}

				$out .= $token[1];
				continue;
			}

			$out .= $token;
		}

		return $out;
	}

	private function is_admin_action_hook( string $hook ): bool {
		foreach ( self::PREFIXES as $prefix ) {
			if ( 0 === strpos( $hook, $prefix ) && strlen( $hook ) > strlen( $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param string $name Class::method.
	 * @return bool
	 */
	private function is_ours( string $name ): bool {
		return 0 === strpos( $name, self::NAMESPACE_PREFIX );
	}

	/**
	 * @param mixed $function Registered callable.
	 * @return string
	 */
	private function callback_name( $function ): string {
		if ( is_array( $function ) && isset( $function[0], $function[1] ) ) {
			return ( is_object( $function[0] ) ? get_class( $function[0] ) : (string) $function[0] ) . '::' . $function[1];
		}
		if ( is_string( $function ) ) {
			return $function;
		}
		if ( is_object( $function ) && ! $function instanceof \Closure ) {
			return get_class( $function ) . '::__invoke';
		}

		return 'Closure';
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 0. The tripwire
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * If the walk finds nothing, every other check below passes on an empty set
	 * and this whole file is decoration. Assert the floor first.
	 */
	public function test_the_live_walk_is_not_empty(): void {
		$live = $this->live_handlers();

		$this->assertNotEmpty(
			$live,
			'The admin-action walk found no handlers at all. Every other check in this file '
			. 'is then vacuous. Fix the walk before trusting a green run.'
		);

		$this->assertGreaterThanOrEqual(
			count( $this->manifest ),
			count( $live ),
			'The walk found fewer handlers than the manifest declares, so it is not seeing the '
			. 'whole surface: ' . implode( ', ', array_keys( array_diff_key( $this->manifest, $live ) ) )
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. Declared
	// ─────────────────────────────────────────────────────────────────────────

	public function test_every_registered_handler_is_declared(): void {
		$undeclared = array_diff_key( $this->live_handlers(), $this->manifest );

		$this->assertSame(
			array(),
			array_keys( $undeclared ),
			"These admin actions are registered but not declared in audit/admin-action-authority.json.\n"
			. "Add each one with its audience, capability, nonce_action and nonce_field.\n"
			. 'An admin action nobody declared is a state change nobody reviewed.'
		);
	}

	/**
	 * The declaration must name the callback that is actually on the hook. A
	 * manifest that says "admin only" about a method the hook no longer points
	 * at is worse than no manifest - it reads as a reviewed fact.
	 */
	public function test_the_declared_callback_is_the_one_on_the_hook(): void {
		$drift = array();

		foreach ( $this->live_handlers() as $hook => $callback ) {
			$declared = (string) ( $this->manifest[ $hook ]['callback'] ?? '' );

			if ( '' !== $declared && $declared !== $callback ) {
				$drift[] = $hook . ' — declared ' . $declared . ', registered ' . $callback;
			}
		}

		$this->assertSame( array(), $drift, implode( "\n", $drift ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. No rot
	// ─────────────────────────────────────────────────────────────────────────

	public function test_the_manifest_has_no_handlers_that_no_longer_exist(): void {
		$stale = array();

		foreach ( array_diff_key( $this->manifest, $this->live_handlers() ) as $hook => $declared ) {
			if ( empty( $declared['conditional'] ) ) {
				$stale[] = $hook . ' — declared, not registered, and not marked conditional';
			}
		}

		$this->assertSame(
			array(),
			$stale,
			"These admin actions are declared but no longer registered. Remove them so the manifest keeps meaning something.\n"
			. implode( "\n", $stale )
		);
	}

	/**
	 * `conditional` exempts an entry from the rot check, so it has to say what
	 * mounts it. Without this, marking an entry conditional would be a way to
	 * keep a deleted handler in the file forever.
	 */
	public function test_conditional_handlers_name_what_mounts_them(): void {
		$unexplained = array();

		foreach ( $this->manifest as $hook => $declared ) {
			if ( ! empty( $declared['conditional'] ) && '' === trim( (string) ( $declared['conditional_on'] ?? '' ) ) ) {
				$unexplained[] = $hook . ' — conditional with no conditional_on';
			}
		}

		$this->assertSame( array(), $unexplained, implode( "\n", $unexplained ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. The teeth: nonce AND capability, read out of the shipped source
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Every state-changing handler resolves to a nonce check AND a capability
	 * check - in its own body, or in the guard it declares it delegates to.
	 */
	public function test_every_state_changing_handler_checks_a_nonce_and_a_capability(): void {
		$problems = array();

		foreach ( $this->manifest as $hook => $declared ) {
			if ( empty( $declared['writes'] ) ) {
				continue;
			}

			$callback = (string) ( $declared['callback'] ?? '' );
			$source   = $this->source_of( $callback );

			if ( null === $source ) {
				$problems[] = $hook . ' — cannot read the source of ' . $callback . ', so nothing about it is proven';
				continue;
			}

			$guard = trim( (string) ( $declared['delegates_to'] ?? '' ) );
			if ( '' !== $guard ) {
				$guard_source = $this->source_of( $guard );

				if ( null === $guard_source ) {
					$problems[] = $hook . ' — declares delegates_to ' . $guard . ' but that method does not exist';
					continue;
				}

				// The delegation has to be real: the handler must actually call
				// the guard it names. Otherwise `delegates_to` is a way to
				// declare a check into existence.
				$method = substr( $guard, (int) strrpos( $guard, ':' ) + 1 );
				if ( ! preg_match( '/(?:\$this->|self::|static::)' . preg_quote( $method, '/' ) . '\s*\(/', $source ) ) {
					$problems[] = $hook . ' — declares delegates_to ' . $guard . ' but never calls it';
					continue;
				}

				$source .= "\n" . $guard_source;
			}

			if ( ! $this->calls_one_of( $source, self::NONCE_FUNCTIONS ) ) {
				$problems[] = $hook . ' — no nonce check in ' . $callback
					. ( '' !== $guard ? ' or in ' . $guard : '' )
					. '. Anyone who knows the action name can fire it from any page.';
			}

			if ( ! $this->calls_one_of( $source, self::CAPABILITY_FUNCTIONS ) ) {
				// "Logged in IS the authority" is a legitimate answer for a
				// self-scoped preference write - but it has to be declared and
				// justified, exactly like a public REST route.
				if ( 'member' === ( $declared['audience'] ?? '' ) && '' !== trim( (string) ( $declared['reason'] ?? '' ) ) ) {
					continue;
				}

				$problems[] = $hook . ' — no capability check in ' . $callback
					. ( '' !== $guard ? ' or in ' . $guard : '' )
					. '. Any logged-in member who knows the action name can fire it. '
					. 'If that is intended, declare audience: member with a reason.';
			}
		}

		$this->assertSame( array(), $problems, "\n" . implode( "\n", $problems ) );
	}

	/**
	 * A declared capability must be one the callback actually checks. Otherwise
	 * the manifest can claim manage_options over a handler that checks
	 * read - the thing this file exists to stop.
	 */
	public function test_the_declared_capability_appears_in_the_source(): void {
		$problems = array();

		foreach ( $this->manifest as $hook => $declared ) {
			$capability = (string) ( $declared['capability'] ?? '' );

			if ( '' === $capability || 'is_user_logged_in' === $capability ) {
				continue;
			}

			$callback = (string) ( $declared['callback'] ?? '' );
			$source   = $this->guarding_source( $callback );
			$guard    = trim( (string) ( $declared['delegates_to'] ?? '' ) );
			if ( '' !== $guard ) {
				$source .= "\n" . $this->guarding_source( $guard );
			}

			foreach ( explode( '|', $capability ) as $cap ) {
				if ( false === strpos( $source, "'" . $cap . "'" ) ) {
					$problems[] = $hook . ' — declares capability "' . $cap . '" which appears nowhere in the code that guards it';
				}
			}
		}

		$this->assertSame( array(), $problems, "\n" . implode( "\n", $problems ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 4/5. Declared exemptions have to say why
	// ─────────────────────────────────────────────────────────────────────────

	public function test_member_and_public_handlers_say_why(): void {
		$problems = array();

		foreach ( $this->manifest as $hook => $declared ) {
			$audience = (string) ( $declared['audience'] ?? '' );

			if ( ! in_array( $audience, array( 'member', 'public' ), true ) ) {
				continue;
			}

			$reason = trim( (string) ( $declared['reason'] ?? '' ) );

			if ( '' === $reason || 0 === strpos( $reason, 'TODO' ) ) {
				$problems[] = $hook . ' — declared ' . $audience . ' with no reason. Fire it as a subscriber and say what they get.';
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	/**
	 * A *_nopriv_ handler is reachable signed out. Free registers none today;
	 * the rule exists so the first one cannot land without a stated reason.
	 */
	public function test_nopriv_handlers_are_declared_with_a_reason(): void {
		$problems = array();

		foreach ( $this->live_handlers() as $hook => $callback ) {
			if ( false === strpos( $hook, '_nopriv_' ) ) {
				continue;
			}

			$declared = $this->manifest[ $hook ] ?? null;
			$reason   = trim( (string) ( $declared['reason'] ?? '' ) );

			if ( 'public' !== ( $declared['audience'] ?? '' ) || '' === $reason ) {
				$problems[] = $hook . ' — reachable signed out (' . $callback . '), and not declared audience: public with a reason';
			}
		}

		$this->assertSame( array(), $problems, implode( "\n", $problems ) );
	}

	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * A callable's source with its class constants resolved inline.
	 *
	 * DocumentOrphansPanel::guard() checks `current_user_can( self::CAPABILITY )`.
	 * A literal search over the method body alone finds no capability string and
	 * reports the declaration as unproven - which is how the first draft of this
	 * manifest got caught claiming manage_options over a handler that actually
	 * checks manage_mvs_settings. Appending the resolved constant values keeps
	 * the check strict (the string must really be there) without punishing a
	 * class that names its capability once instead of repeating it.
	 *
	 * @param string $callable Class::method.
	 * @return string
	 */
	private function guarding_source( string $callable ): string {
		$source = (string) $this->source_of( $callable );
		$class  = false !== strpos( $callable, '::' ) ? strstr( $callable, '::', true ) : '';

		if ( '' === $class || '' === $source ) {
			return $source;
		}

		preg_match_all( '/(?:self|static|' . preg_quote( $class, '/' ) . ')::([A-Z_0-9]+)/', $source, $matches );

		foreach ( array_unique( $matches[1] ) as $name ) {
			if ( defined( $class . '::' . $name ) ) {
				$value = constant( $class . '::' . $name );

				if ( is_string( $value ) ) {
					$source .= "\n// " . $class . '::' . $name . " = '" . $value . "'";
				}
			}
		}

		return $source;
	}

	/**
	 * The shipped source of a callable, read off disk via Reflection.
	 *
	 * @param string $callable Class::method or function name.
	 * @return string|null
	 */
	private function source_of( string $callable ): ?string {
		try {
			$reflection = false !== strpos( $callable, '::' )
				? new ReflectionMethod( ...explode( '::', $callable, 2 ) )
				: new ReflectionFunction( $callable );
		} catch ( \Throwable $e ) {
			return null;
		}

		$file = (string) $reflection->getFileName();
		if ( '' === $file || ! is_readable( $file ) ) {
			return null;
		}

		$lines = (array) file( $file );
		$start = (int) $reflection->getStartLine();
		$end   = (int) $reflection->getEndLine();

		return implode( '', array_slice( $lines, $start - 1, $end - $start + 1 ) );
	}

	/**
	 * Does this source call any of these functions?
	 *
	 * The word boundary matters in both directions. Without a leading guard,
	 * `$this->current_user_can_save()` would satisfy a search for
	 * `current_user_can` - which is the delegating case this test deliberately
	 * makes you declare. Without a trailing `\s*\(`, a mention in a comment
	 * would count.
	 *
	 * @param string   $source    Source text.
	 * @param string[] $functions Function names.
	 * @return bool
	 */
	private function calls_one_of( string $source, array $functions ): bool {
		foreach ( $functions as $function ) {
			if ( preg_match( '/(?<![\w>:$])' . preg_quote( $function, '/' ) . '\s*\(/', $source ) ) {
				return true;
			}
		}

		return false;
	}
}
