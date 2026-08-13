module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		// RTL CSS generation

		// Generate .pot file
		makepot: {
			target: {
				options: {
					domainPath: 'languages/',
					potFilename: 'wpmediaverse.pot',
					type: 'wp-plugin',
					updateTimestamp: false,
					// NEVER SCAN A COPY OF OURSELVES. `dist/wpmediaverse/` is a
					// staging copy of this plugin, so every string in it is a
					// duplicate of a string in source — makepot recorded both and
					// the POT grew a `#: dist/...` reference per string, ~2,600 of
					// them, more with each rebuild.
					//
					// `dist` was taught to clean before building, which fixed
					// `grunt dist`. It did not fix `grunt build`, which runs
					// makepot with no clean at all — so anyone building after a
					// dist still polluted the POT, and did until this line. The
					// exclusion is the durable half: it holds whichever task runs
					// and whatever is lying in the tree.
					exclude: [ 'dist/.*', 'node_modules/.*', 'vendor/.*', 'tests/.*' ],
					potHeaders: {
						poedit: true,
						'x-poedit-keywordslist': true,
					},
				},
			},
		},

		// CSS minification
		cssmin: {
			dist: {
				files: [
					{
						expand: true,
						cwd: 'assets/css/',
						src: [ '*.css', '!*.min.css' ],
						dest: 'assets/css/',
						ext: '.min.css',
					},
				],
			},
		},

		// JS minification
		uglify: {
			dist: {
				options: {
					mangle: {
						reserved: [ 'jQuery' ],
					},
				},
				files: [
					{
						expand: true,
						cwd: 'assets/js/',
						src: [ '*.js', '!*.min.js' ],
						dest: 'assets/js/',
						ext: '.min.js',
					},
				],
			},
		},

		// Clean dist folder
		clean: {
			dist: [ 'dist/' ],
		},

		// Copy files to dist
		copy: {
			dist: {
				files: [
					{
						expand: true,
						src: [
							'**',

							// ── Git / CI ──
							'!.git/**',
							'!.gitignore',
							'!.distignore',
							'!.github/**',

							// ── Dev tooling ──
							'!node_modules/**',
							'!tests/**',
							'!bin/**',
							'!tools/**',
							'!dist/**',
							'!.claude/**',
							'!.phpunit.result.cache',
							'!playwright.config.ts',
							'!test-results/**',
							// src/ included — block CSS loaded from src/blocks/ at runtime.

							// ── Docs, plans, audit, QA, marketing (internal / GitHub-only) ──
							// Free is shipped publicly — none of this goes to customers.
							'!docs/**',
							'!plan/**',
							'!plans/**',
							'!audit/**',
							'!qa/**',
							'!marketing/**',

							// ── Dev config files ──
							'!phpunit.xml',
							'!phpunit.xml.dist',
							'!phpstan.neon',
							'!phpstan.neon.dist',
							'!phpstan-baseline.neon',
							'!phpcs.xml',
							'!.phpcs.xml.dist',
							'!package.json',
							'!package-lock.json',
							'!composer.json',
							'!composer.lock',
							'!Gruntfile.js',
							'!webpack.config.js',
							'!.editorconfig',
							'!.eslintrc*',
							'!.babelrc',
							'!.browserslistrc',
							'!CLAUDE.md',
							'!ARCHITECTURE.md',
							'!.playwright-mcp/**',
							'!.phpunit*',

							// ── Dev-only PHP (keep seed + cleanup for customer demo import) ──
							'!populate-showcase.php',

							// ── Markdown files (keep readme.txt only) ──
							'!**/*.md',
							'!readme.txt.bak',

							// ── OS / placeholder files (plugin-check rejects hidden files) ──
							'!**/.DS_Store',
							'!**/.gitkeep',
							'!**/Thumbs.db',

							// ── Vendor: strip ALL dev deps ──
							// Keep: easy-digital-downloads (license SDK)
							// Keep: woocommerce (Action Scheduler)
							// Keep: composer (autoloader)
							'!vendor/bin/**',
							'!vendor/phpunit/**',
							'!vendor/squizlabs/**',
							'!vendor/wp-coding-standards/**',
							'!vendor/phpcompatibility/**',
							'!vendor/phpcsstandards/**',
							'!vendor/szepeviktor/**',
							'!vendor/phpstan/**',
							'!vendor/php-stubs/**',
							'!vendor/nikic/**',
							'!vendor/dealerdirect/**',
							'!vendor/doctrine/**',
							'!vendor/myclabs/**',
							'!vendor/phar-io/**',
							'!vendor/theseer/**',
							'!vendor/yoast/**',
							'!vendor/sebastian/**',
							'!vendor/symfony/**',
						],
						dest: 'dist/wpmediaverse/',
					},
				],
			},
		},

		// Create zip
		compress: {
			dist: {
				options: {
					archive: 'dist/wpmediaverse-<%= pkg.version %>.zip',
					mode: 'zip',
				},
				files: [
					{
						expand: true,
						cwd: 'dist/',
						src: [ 'wpmediaverse/**' ],
					},
				],
			},
		},
	} );

	// Load plugins
	grunt.loadNpmTasks( 'grunt-wp-i18n' );
	grunt.loadNpmTasks( 'grunt-contrib-cssmin' );
	grunt.loadNpmTasks( 'grunt-contrib-uglify' );
	grunt.loadNpmTasks( 'grunt-contrib-clean' );
	grunt.loadNpmTasks( 'grunt-contrib-copy' );
	grunt.loadNpmTasks( 'grunt-contrib-compress' );

	// CI gate
	grunt.registerTask( 'ci-check', 'Verify GitHub Actions CI is green before release.', function() {
		var done = this.async();
		var execFile = require( 'child_process' ).execFile;

		grunt.log.writeln( 'Checking GitHub Actions status...' );

		execFile( 'gh', [ 'run', 'list', '--branch', 'main', '--limit', '1', '--json', 'status,conclusion,name', '--jq', '.[0]' ], function( err, stdout ) {
			if ( err ) {
				grunt.log.error( 'Could not check CI. Is `gh` CLI installed and authenticated?' );
				done( false );
				return;
			}

			var run;
			try {
				run = JSON.parse( stdout.trim() );
			} catch ( e ) {
				grunt.log.error( 'No CI runs found.' );
				done( false );
				return;
			}

			if ( run.status === 'in_progress' || run.status === 'queued' ) {
				grunt.log.error( 'CI is still running (' + run.name + '). Wait for it to finish.' );
				done( false );
				return;
			}

			if ( run.conclusion !== 'success' ) {
				grunt.log.error( 'CI failed (' + run.name + ' → ' + run.conclusion + '). Fix before releasing.' );
				done( false );
				return;
			}

			grunt.log.ok( 'CI passed (' + run.name + ' → ' + run.conclusion + ')' );
			done();
		} );
	} );

	// Rewrite legacy script handle names in generated view.asset.php files.
	// @wordpress/scripts <30 still emits the legacy 'wp-interactivity' handle
	// as a Script Modules dependency, but WP 6.9.1+ expects the module ID
	// '@wordpress/interactivity'. Patch the generated files in place so block
	// registration via view.asset.php resolves the dependency correctly.
	grunt.registerTask( 'fix-script-module-deps', 'Rewrite legacy script handle names in view.asset.php files.', function() {
		var fs = require( 'fs' );
		var path = require( 'path' );
		var blocksDir = path.join( __dirname, 'build/blocks' );

		if ( ! fs.existsSync( blocksDir ) ) {
			grunt.log.writeln( 'No build/blocks directory — skipping.' );
			return;
		}

		var patched = 0;
		fs.readdirSync( blocksDir ).forEach( function( entry ) {
			var assetFile = path.join( blocksDir, entry, 'view.asset.php' );
			if ( ! fs.existsSync( assetFile ) ) {
				return;
			}
			var contents = fs.readFileSync( assetFile, 'utf8' );
			var rewritten = contents.replace( /'wp-interactivity'/g, "'@wordpress/interactivity'" );
			if ( rewritten !== contents ) {
				fs.writeFileSync( assetFile, rewritten );
				patched++;
			}
		} );
		grunt.log.ok( 'Patched ' + patched + ' view.asset.php file(s) for script module compat.' );
	} );

	// Build Gutenberg blocks.
	//
	// Delegates to the `build` script in package.json so the script-module flags
	// (--experimental-modules, --webpack-src-dir, --output-path) stay in ONE place.
	// Invoking `npx wp-scripts build` directly here would lose those flags and
	// produce IIFE output that breaks viewScriptModule blocks at runtime with:
	//   Cannot read properties of undefined (reading 'store')
	// (Symptom: window.wp.interactivity is undefined because script modules
	//  don't share the wp.* globals that a regular wp-scripts IIFE expects.)
	grunt.registerTask( 'blocks', 'Build Gutenberg blocks via npm run build.', function() {
		var done = this.async();
		var execFile = require( 'child_process' ).execFile;
		grunt.log.writeln( 'Building blocks (npm run build)...' );
		execFile( 'npm', [ 'run', 'build' ], function( err, stdout, stderr ) {
			if ( err ) {
				grunt.log.error( stderr || stdout );
				done( false );
				return;
			}
			grunt.log.ok( 'Blocks built successfully.' );
			grunt.task.run( 'fix-script-module-deps' );
			done();
		} );
	} );

	// Print dist summary
	grunt.registerTask( 'dist-summary', 'Show dist ZIP info.', function() {
		var pkg = grunt.config( 'pkg' );
		var zipPath = 'dist/wpmediaverse-' + pkg.version + '.zip';
		if ( grunt.file.exists( zipPath ) ) {
			var fs = require( 'fs' );
			var stats = fs.statSync( zipPath );
			var sizeMB = ( stats.size / 1024 / 1024 ).toFixed( 1 );
			grunt.log.writeln( '' );
			grunt.log.ok( 'ZIP: ' + zipPath + ' (' + sizeMB + ' MB)' );
			grunt.log.ok( 'Version: ' + pkg.version );
			grunt.log.writeln( '' );
			grunt.log.writeln( 'Vendor includes: EDD license SDK, Action Scheduler, Composer autoloader' );
			grunt.log.writeln( 'Import in WordPress: Plugins → Add New → Upload → ' + zipPath );
		}
	} );

	// Composer: strip dev deps so autoloader only references production packages.
	grunt.registerTask( 'composer-prod', 'Run composer install --no-dev to clean autoloader.', function() {
		var done = this.async();
		var execFile = require( 'child_process' ).execFile;
		grunt.log.writeln( 'Running composer install --no-dev...' );
		execFile( 'composer', [ 'install', '--no-dev', '--optimize-autoloader', '--no-interaction' ], function( err, stdout, stderr ) {
			if ( err ) {
				grunt.log.error( stderr || stdout );
				done( false );
				return;
			}
			grunt.log.ok( 'Composer: dev dependencies removed, autoloader optimized.' );
			done();
		} );
	} );

	// Composer: restore dev deps after dist build.
	grunt.registerTask( 'composer-restore', 'Restore dev dependencies after dist.', function() {
		var done = this.async();
		var execFile = require( 'child_process' ).execFile;
		grunt.log.writeln( 'Restoring composer dev dependencies...' );
		execFile( 'composer', [ 'install', '--no-interaction' ], function( err, stdout, stderr ) {
			if ( err ) {
				grunt.log.error( stderr || stdout );
				done( false );
				return;
			}
			grunt.log.ok( 'Composer: dev dependencies restored.' );
			done();
		} );
	} );

	// Build: blocks + RTL + minify + pot
	// NOTE: 'rtlcss' was removed from this pipeline in 2.3.0. It generated
	// assets/css/*-rtl.css, which nothing ever enqueued — there is no
	// wp_style_add_data( $handle, 'rtl', 'replace' ) anywhere in Free or Pro.
	//
	// Do not add it back, and do not "fix" the missing registration. RTL is
	// already correct without it: the browser mirrors flex and grid from
	// dir="rtl", so serving an rtlcss-flipped sheet DOUBLE-flips the layout —
	// verified on an Arabic install, where registering the files moved the
	// Explore heading and tag chips back to left-aligned on an RTL page.
	grunt.registerTask( 'build', [ 'blocks', 'cssmin', 'uglify', 'makepot' ] );

	// Dist: full build + strip dev deps + package zip + restore dev deps
	// `clean:dist` runs FIRST, before `build`. It used to sit after, which meant
	// `makepot` (inside `build`) scanned the PREVIOUS run's `dist/wpmediaverse/`
	// staging copy as if it were source: every string picked up a duplicate
	// `#: dist/...` reference, ~2,600 of them, growing with each rebuild. The
	// first build on a clean tree looked fine, so it only showed up on a rebuild.
	grunt.registerTask( 'dist', [ 'clean:dist', 'build', 'composer-prod', 'copy:dist', 'compress:dist', 'composer-restore', 'dist-summary' ] );

	// Release: CI check + dist (for production releases)
	grunt.registerTask( 'release', [ 'ci-check', 'dist' ] );

	// Default
	grunt.registerTask( 'default', [ 'build' ] );
};
