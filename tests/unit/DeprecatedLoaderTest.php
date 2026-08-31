<?php
/**
 * `Core\Loader` is deprecated and says so.
 *
 * The class is the WordPress Plugin Boilerplate's hook collector, dead since
 * the plugin was scaffolded — nothing has ever instantiated one. It is
 * deprecated rather than deleted because Production Rule 1 gives a public
 * symbol two major versions between deprecation and removal, with no exception
 * for one that happens to be unused.
 *
 * This test is what makes the removal safe to do later: it pins the version the
 * clock started from, so whoever deletes the class in 4.0.0 can see the
 * deprecation actually shipped in 2.4.0 rather than taking a comment's word.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;
use WPMediaVerse\Core\Loader;

class DeprecatedLoaderTest extends WP_UnitTestCase {

	/**
	 * Constructing one raises a deprecation naming the replacement.
	 */
	public function test_constructing_the_loader_is_deprecated(): void {
		$this->setExpectedDeprecated( 'WPMediaVerse\Core\Loader' );

		new Loader();
	}

	/**
	 * Nothing in the plugin constructs one.
	 *
	 * The reason this can be deprecated without a migration path: there is no
	 * caller to migrate. If someone wires it up, this fails and they are told to
	 * use `add_action()` instead — which is all `run()` ever did.
	 */
	public function test_no_plugin_code_instantiates_the_loader(): void {
		$root  = dirname( __DIR__, 2 );
		$hits  = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/includes' ) );

		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() || 'Loader.php' === $file->getFilename() ) {
				continue;
			}

			if ( preg_match( '/new\s+\\\\?(?:WPMediaVerse\\\\Core\\\\)?Loader\s*\(/', (string) file_get_contents( $file->getPathname() ) ) ) {
				$hits[] = str_replace( $root . '/', '', $file->getPathname() );
			}
		}

		$this->assertSame(
			array(),
			$hits,
			"Core\\Loader is deprecated for removal in 4.0.0 and must not gain callers: \n  " . implode( "\n  ", $hits )
		);
	}
}
