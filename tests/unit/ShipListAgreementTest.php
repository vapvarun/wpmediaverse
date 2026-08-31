<?php
/**
 * The three lists that decide what a customer receives must agree.
 *
 * THIS IS THE TEST FOR THE BUG THAT SHIPPED A RELEASE WITH NO AUTOLOADER.
 *
 * Three separate, hand-maintained lists describe "what goes in the zip":
 *
 *   1. `Gruntfile.js`  copy.dist negative `src:` globs   — what `grunt dist` copies
 *   2. `.distignore`                                     — what wp-plugin-qa,
 *                                                          WordPress.org SVN tagging
 *                                                          and third-party packagers
 *                                                          read
 *   3. `.github/workflows/tests.yml`  rsync --exclude     — what Plugin Check inspects
 *                                                          as "what customers receive"
 *
 * Each already carries a comment telling the reader to keep it in step with
 * another. `.distignore`'s header says it "Mirrors the negative `src:` patterns
 * in Gruntfile.js"; the workflow's says the list "must mirror Gruntfile.js
 * copy:dist task". They did not mirror each other, and nothing checked: the
 * Gruntfile copied `vendor/` minus its dev packages while `.distignore` excluded
 * `/vendor` wholesale, so a zip built the second way had no Composer autoloader.
 * HTTP 500 on the front page, on wp-admin/plugins.php, and WP-CLI refusing to
 * start — every recovery route through WordPress closed, FTP the only way back.
 *
 * A comment asking humans to keep three lists in step is not a mechanism. This
 * is the mechanism.
 *
 * WHAT IT COMPARES, AND WHY NOT MORE. Only top-level directories, because that
 * is the granularity where the lists genuinely mean the same thing and where
 * the failure happened. Their syntaxes differ (`'!docs/**'` vs `/docs` vs
 * `--exclude='docs/'`), and file-level entries diverge legitimately — the
 * Gruntfile carries build-artifact patterns the other two have no reason to
 * name. Comparing everything would produce a test that fails constantly for
 * reasons nobody should care about, and a test people learn to ignore is worse
 * than no test.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Tests\Unit;

use WP_UnitTestCase;

class ShipListAgreementTest extends WP_UnitTestCase {

	/**
	 * Directories whose presence in the zip nobody should have to guess.
	 *
	 * Deliberately explicit rather than "every directory in the repo": this asks
	 * a question about the ones that matter, and a new directory should have to
	 * be added here consciously.
	 *
	 * @var string[]
	 */
	private const WATCHED = array(
		'vendor',
		'node_modules',
		'tests',
		'bin',
		'tools',
		'dist',
		'docs',
		'audit',
		'qa',
		'plan',
		'marketing',
		'.git',
		'.github',
	);

	/**
	 * Plugin root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Top-level directories excluded by the Gruntfile's copy.dist task.
	 *
	 * @return string[]
	 */
	private function grunt_excludes(): array {
		$source = (string) file_get_contents( $this->root() . '/Gruntfile.js' );

		preg_match_all( "/'!([^']+)'/", $source, $matches );

		return $this->normalise( $matches[1] ?? array() );
	}

	/**
	 * Top-level directories excluded by .distignore.
	 *
	 * @return string[]
	 */
	private function distignore_excludes(): array {
		$lines = file( $this->root() . '/.distignore', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$lines = array_filter(
			(array) $lines,
			static function ( $line ) {
				$line = trim( $line );

				return '' !== $line && 0 !== strpos( $line, '#' ) && 0 !== strpos( $line, '!' );
			}
		);

		return $this->normalise( $lines );
	}

	/**
	 * Top-level directories excluded by the Plugin Check rsync in tests.yml.
	 *
	 * @return string[]|null Null when the workflow has no such step.
	 */
	private function workflow_excludes(): ?array {
		$path = $this->root() . '/.github/workflows/tests.yml';

		if ( ! is_readable( $path ) ) {
			return null;
		}

		$source = (string) file_get_contents( $path );

		preg_match_all( "/--exclude='([^']+)'/", $source, $matches );

		if ( empty( $matches[1] ) ) {
			return null;
		}

		return $this->normalise( $matches[1] );
	}

	/**
	 * Reduce a pattern in any of the three syntaxes to a top-level name.
	 *
	 * `docs/**`, `/docs`, `docs/` and `docs` all mean the same directory. Entries
	 * that are not a plain top-level name — globs like `*.min.js`, nested paths
	 * like `build/.phpcs-cache` — are dropped, because those are the ones that
	 * legitimately differ between the three files.
	 *
	 * @param array $patterns Raw patterns.
	 * @return string[] Sorted, unique top-level names.
	 */
	private function normalise( array $patterns ): array {
		$names = array();

		foreach ( $patterns as $pattern ) {
			$name = trim( (string) $pattern );
			$name = ltrim( $name, '/' );
			$name = preg_replace( '#/\*\*$#', '', $name );
			$name = rtrim( (string) $name, '/' );

			if ( '' === $name || false !== strpos( $name, '/' ) ) {
				continue;
			}

			// A TRAILING-STAR PREFIX IS STILL A STATEMENT ABOUT THE DIRECTORY.
			// `tests.yml` writes `--exclude='.git*'`, which covers `.git`,
			// `.gitignore` and `.gitattributes` in one. Dropping it for
			// containing a star made this test report `.git` as a disagreement
			// on its very first run — a false positive from the comparison, not
			// a real difference between the files. Kept as the prefix so
			// `covers()` can match it.
			if ( false !== strpos( rtrim( $name, '*' ), '*' ) ) {
				continue;
			}

			$names[ $name ] = true;
		}

		$names = array_keys( $names );
		sort( $names );

		return $names;
	}

	/**
	 * Does this list exclude the given directory, however it spells it?
	 *
	 * Exact name, or a trailing-star prefix that covers it.
	 *
	 * @param string[] $excludes Normalised entries.
	 * @param string   $dir      Directory name.
	 * @return bool
	 */
	private function covers( array $excludes, string $dir ): bool {
		foreach ( $excludes as $entry ) {
			if ( $entry === $dir ) {
				return true;
			}

			if ( '*' === substr( $entry, -1 ) && 0 === strpos( $dir, rtrim( $entry, '*' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every watched directory is treated the same by the Gruntfile and .distignore.
	 *
	 * THE EXACT DISAGREEMENT THAT SHIPPED A BROKEN RELEASE. `vendor` was in one
	 * and effectively not the other, and the two files each told the reader they
	 * mirrored one another.
	 */
	public function test_gruntfile_and_distignore_agree_on_watched_directories(): void {
		$grunt = $this->grunt_excludes();
		$dist  = $this->distignore_excludes();

		$disagreements = array();

		foreach ( self::WATCHED as $dir ) {
			$in_grunt = $this->covers( $grunt, $dir );
			$in_dist  = $this->covers( $dist, $dir );

			if ( $in_grunt !== $in_dist ) {
				$disagreements[] = sprintf(
					'%s: Gruntfile %s, .distignore %s',
					$dir,
					$in_grunt ? 'excludes' : 'SHIPS',
					$in_dist ? 'excludes' : 'SHIPS'
				);
			}
		}

		$this->assertSame(
			array(),
			$disagreements,
			"These two files disagree about what reaches a customer, and both claim to mirror the other:\n  "
				. implode( "\n  ", $disagreements )
				. "\n\nA zip built one way will differ from a zip built the other. That is how a release shipped with no autoloader."
		);
	}

	/**
	 * And the Plugin Check copy agrees with them, where it exists.
	 *
	 * It is the list that decides what Plugin Check believes customers receive.
	 * If it disagrees, the check is inspecting a fiction — passing on files
	 * nobody gets, or missing files everybody does.
	 */
	public function test_plugin_check_copy_agrees_where_it_exists(): void {
		$workflow = $this->workflow_excludes();

		if ( null === $workflow ) {
			$this->markTestSkipped( 'No Plugin Check rsync step in this plugin.' );
		}

		$dist          = $this->distignore_excludes();
		$disagreements = array();

		foreach ( self::WATCHED as $dir ) {
			$in_workflow = $this->covers( $workflow, $dir );
			$in_dist     = $this->covers( $dist, $dir );

			if ( $in_workflow !== $in_dist ) {
				$disagreements[] = sprintf(
					'%s: tests.yml %s, .distignore %s',
					$dir,
					$in_workflow ? 'excludes' : 'SHIPS',
					$in_dist ? 'excludes' : 'SHIPS'
				);
			}
		}

		$this->assertSame(
			array(),
			$disagreements,
			"Plugin Check is inspecting a different tree from the one that ships:\n  " . implode( "\n  ", $disagreements )
		);
	}

	/**
	 * vendor/ is excluded everywhere, and this one is asserted on its own.
	 *
	 * Not covered by the agreement tests above: three lists can agree perfectly
	 * and all be wrong together. vendor/ is dev and build tooling, the runtime
	 * dependencies live in libs/, and shipping vendor/ would put phpunit and
	 * phpcs in a customer's plugin directory.
	 */
	public function test_vendor_is_excluded_from_every_list(): void {
		$this->assertTrue( $this->covers( $this->grunt_excludes(), 'vendor' ), 'grunt dist must not copy vendor/.' );
		$this->assertTrue( $this->covers( $this->distignore_excludes(), 'vendor' ), '.distignore must exclude vendor/.' );

		$workflow = $this->workflow_excludes();

		if ( null !== $workflow ) {
			$this->assertTrue( $this->covers( $workflow, 'vendor' ), 'Plugin Check must not inspect vendor/.' );
		}
	}

	/**
	 * libs/ ships in every list, because the plugin does not run without it.
	 *
	 * The mirror image of the vendor rule and the more dangerous direction: it
	 * holds Action Scheduler and the EDD SDK, and the entry file requires them
	 * directly. Excluding it anywhere produces the failure vendor/ used to —
	 * a zip that installs and then cannot work.
	 */
	public function test_libs_is_never_excluded(): void {
		$this->assertFalse( $this->covers( $this->grunt_excludes(), 'libs' ), 'libs/ MUST ship — the plugin loads it at runtime.' );
		$this->assertFalse( $this->covers( $this->distignore_excludes(), 'libs' ), 'libs/ MUST ship — the plugin loads it at runtime.' );

		$workflow = $this->workflow_excludes();

		if ( null !== $workflow ) {
			$this->assertFalse( $this->covers( $workflow, 'libs' ), 'Plugin Check should inspect libs/ — customers receive it.' );
		}
	}
}
