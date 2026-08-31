<?php
/**
 * The plugin fails to boot without taking the site down.
 *
 * The autoloader used to be wrapped in a bare `file_exists()` that skipped the
 * require and let execution continue, even though every line after it names a
 * class the autoloader provides. Replicated 2026-08-13 by moving `vendor/`
 * aside: HTTP 500 on the front page, HTTP 500 on `wp-admin/plugins.php` — the
 * screen an owner would use to switch the plugin off — and WP-CLI refusing to
 * start at all ("Callable WPMediaVerse\CLI\Commands does not exist"). Every
 * recovery route through WordPress was closed; the only way back was FTP.
 *
 * These tests cover the notice a customer actually sees, because that is the
 * part that has to be right when someone is looking at a broken site. The
 * guard's placement is covered by a shape assertion, and that limitation is
 * stated where it applies.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class BootGuardTest extends WP_UnitTestCase {

	/**
	 * Render the admin notices and return the markup.
	 *
	 * @return string
	 */
	private function notices(): string {
		ob_start();
		do_action( 'admin_notices' );

		return (string) ob_get_clean();
	}

	/**
	 * The plugin's own entry file, as text.
	 *
	 * @return string
	 */
	private function entry_file(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/wpmediaverse.php' );
	}

	/**
	 * The `Requires PHP` header and `MVS_MIN_PHP` say the same thing.
	 *
	 * They are two different enforcement points — WordPress reads the header at
	 * activation and update time, the constant drives the runtime guard — and a
	 * site can hit either one. If they drift, the plugin refuses to boot at a
	 * version WordPress was happy to activate, or the reverse.
	 */
	public function test_php_requirement_is_declared_once(): void {
		preg_match( '/^\s*\*\s*Requires PHP:\s*(\S+)/m', $this->entry_file(), $header );

		$this->assertSame(
			MVS_MIN_PHP,
			$header[1] ?? '',
			'The Requires PHP header and MVS_MIN_PHP must agree — WordPress enforces the header, the runtime guard enforces the constant.'
		);
	}

	/**
	 * The incomplete-package notice names the problem and reassures about data.
	 *
	 * Someone reading this is looking at a site that just broke. The two things
	 * they need are what went wrong and whether they have lost anything.
	 */
	public function test_incomplete_package_notice_explains_the_failure(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		mvs_boot_failure( 'incomplete' );
		$notices = $this->notices();

		$this->assertStringContainsString( 'part of the plugin is missing', $notices, 'The notice must name the actual cause.' );
		$this->assertStringContainsString( 'Nothing has been deleted', $notices, 'The notice must answer the question the owner will actually have.' );
		$this->assertStringContainsString( 'notice-error', $notices );
	}

	/**
	 * The PHP-version notice reports both the requirement and what is installed.
	 *
	 * A notice that says only "PHP is too old" sends the owner to their host
	 * without the two numbers that conversation needs.
	 */
	public function test_php_version_notice_names_both_versions(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		mvs_boot_failure( 'php' );
		$notices = $this->notices();

		$this->assertStringContainsString( MVS_MIN_PHP, $notices, 'The notice must state the version required.' );
		$this->assertStringContainsString( PHP_VERSION, $notices, 'The notice must state the version in use.' );
	}

	/**
	 * A member who cannot act on it is not shown it.
	 *
	 * A subscriber can neither re-upload the plugin nor change the site's PHP
	 * version, so for them this is noise on every admin page they can reach.
	 */
	public function test_notice_is_hidden_from_users_who_cannot_act_on_it(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		mvs_boot_failure( 'incomplete' );

		$this->assertStringNotContainsString( 'part of the plugin is missing', $this->notices() );
	}

	/**
	 * The guard stops the file instead of merely noting the problem.
	 *
	 * This is a shape assertion, and a deliberate one: proving the behaviour
	 * would mean loading the entry file a second time inside a suite that has
	 * already loaded it. What it pins is the exact thing that was wrong — the
	 * check observed a missing piece and let execution carry on into code that
	 * needs it.
	 */
	public function test_incomplete_package_stops_the_entry_file(): void {
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*!\s*is_readable\(\s*MVS_PLUGIN_DIR\s*\.\s*\'includes\/Core\/Plugin\.php\'\s*\)\s*\)\s*\{[^}]*return;/s',
			$this->entry_file(),
			'An incomplete package must return out of the entry file; continuing past it is what took the whole site down.'
		);
	}

	/**
	 * Runtime does not depend on Composer.
	 *
	 * `vendor/` is dev tooling, gitignored, and excluded from the release zip.
	 * Requiring its autoloader at runtime is what made a mis-built zip fatal on
	 * every page — and would now fail on EVERY install, since the directory is
	 * not there at all.
	 */
	public function test_runtime_does_not_load_the_composer_autoloader(): void {
		$this->assertStringNotContainsString(
			'vendor/autoload.php',
			$this->entry_file(),
			'The entry file must not require Composer at runtime — vendor/ does not ship.'
		);

		$this->assertStringContainsString(
			'spl_autoload_register',
			$this->entry_file(),
			'The plugin loads its own classes through a hand-written autoloader.'
		);
	}

	/**
	 * The hand-written autoloader actually resolves this plugin's classes.
	 *
	 * Behavioural, unlike the assertions above: it asks for a class by name and
	 * checks the file behind it was loaded. A typo in the prefix, the directory
	 * or the separator replacement would leave every class unresolvable, which
	 * is the same white screen by a different route.
	 */
	public function test_hand_written_autoloader_resolves_plugin_classes(): void {
		$this->assertTrue(
			class_exists( '\WPMediaVerse\Core\Migrator' ),
			'A namespaced class must resolve to includes/Core/Migrator.php through the registered autoloader.'
		);

		$this->assertTrue(
			class_exists( '\WPMediaVerse\Repository\MediaRepository' ),
			'Deeper namespaces must resolve too — the separator replacement is what breaks here.'
		);
	}

	/**
	 * Runtime dependencies are bundled, not declared in composer.json.
	 *
	 * Both directions matter. A package listed in `require` but absent from
	 * `libs/` is the Action Scheduler bug — declared, shipped, never loaded. A
	 * package in `libs/` that is ALSO a Composer requirement invites the two
	 * build paths to start disagreeing about it again.
	 */
	public function test_runtime_dependencies_are_bundled_not_required(): void {
		$composer = json_decode( (string) file_get_contents( dirname( __DIR__, 2 ) . '/composer.json' ), true );
		$require  = array_keys( $composer['require'] ?? array() );

		$this->assertSame(
			array( 'php' ),
			$require,
			'composer.json must require nothing but PHP — runtime dependencies live in libs/.'
		);

		foreach ( array( 'action-scheduler/action-scheduler.php', 'edd-sl-sdk/edd-sl-sdk.php' ) as $bundled ) {
			$this->assertFileExists(
				dirname( __DIR__, 2 ) . '/libs/' . $bundled,
				"libs/{$bundled} must ship — nothing else loads it."
			);
		}
	}

	/**
	 * Action Scheduler is loaded, not merely shipped.
	 *
	 * THE BUG THIS FILE EXISTS FOR, in its second form. The package was a
	 * declared dependency and present in vendor/, but no line of the plugin ever
	 * required it — so `WebhookService`, `StorageCleanupService` and
	 * `StorageRepairService` all sat behind `function_exists( 'as_*' )` guards
	 * that were false on any site without Pro or WooCommerce. Webhooks fell back
	 * to sending inside the member's request; repair sweeps stopped mid-run.
	 */
	public function test_action_scheduler_is_actually_loaded(): void {
		$this->assertTrue(
			function_exists( 'as_enqueue_async_action' ),
			'The three services that schedule background work check for this function and silently degrade without it.'
		);

		$this->assertTrue( function_exists( 'as_schedule_single_action' ) );
		$this->assertTrue( function_exists( 'as_has_scheduled_action' ) );
	}
}
