<?php
/**
 * Our translation template contains OUR strings, and nobody else's.
 *
 * This pollution has happened twice, the same way both times: the makepot
 * exclude list named a directory, third-party code later lived somewhere else,
 * and nothing noticed.
 *
 *   1. `vendor/` was unexcluded — 217 Action Scheduler and EDD SDK strings in
 *      the POT, cleaned up by adding it to the list.
 *   2. The runtime dependencies then MOVED to `libs/`, which the list did not
 *      name — and 256 of the same strings walked straight back in, days later.
 *
 * A translator opening this file should see the plugin's own words. Shipping
 * "Activate", "Status", "Pending" from Action Scheduler asks people to
 * translate a library they did not install and cannot see, and inflates every
 * translation of this plugin permanently.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class TranslationTemplateTest extends WP_UnitTestCase {

	/**
	 * The committed POT.
	 *
	 * @return string
	 */
	private function pot(): string {
		$path = dirname( __DIR__, 2 ) . '/languages/wpmediaverse.pot';

		if ( ! is_readable( $path ) ) {
			$this->markTestSkipped( 'No POT committed yet.' );
		}

		return (string) file_get_contents( $path );
	}

	/**
	 * No string in the template comes from bundled third-party code.
	 *
	 * Checked by SOURCE REFERENCE rather than by looking for known library
	 * words: makepot writes a `#: path:line` comment above every entry, so the
	 * origin is stated and does not have to be guessed from the text. A future
	 * bundled library nobody has thought of yet is caught by the same rule.
	 */
	public function test_no_strings_from_bundled_or_vendor_code(): void {
		$offenders = array();

		foreach ( array( 'libs/', 'vendor/', 'node_modules/' ) as $dir ) {
			$count = preg_match_all( '/^#: ' . preg_quote( $dir, '/' ) . '/m', $this->pot() );

			if ( $count > 0 ) {
				$offenders[] = "{$dir} ({$count} references)";
			}
		}

		$this->assertSame(
			array(),
			$offenders,
			"Third-party strings are in this plugin's translation template: \n  "
				. implode( "\n  ", $offenders )
				. "\n\nAdd the directory to the makepot `exclude` list in Gruntfile.js and regenerate."
		);
	}

	/**
	 * And the template does contain the plugin's own strings.
	 *
	 * The inverse guard: an exclude list that accidentally swallowed
	 * `includes/` would satisfy the test above perfectly, by shipping a POT with
	 * nothing in it.
	 */
	public function test_the_template_still_covers_our_own_code(): void {
		$this->assertGreaterThan(
			200,
			preg_match_all( '/^#: includes\//m', $this->pot() ),
			'The template should reference this plugin\'s own source many times over.'
		);
	}
}
