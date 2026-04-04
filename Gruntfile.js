module.exports = function( grunt ) {
	'use strict';

	grunt.initConfig( {
		pkg: grunt.file.readJSON( 'package.json' ),

		// RTL CSS generation
		rtlcss: {
			dist: {
				files: [
					{
						expand: true,
						cwd: 'assets/css/',
						src: [ '*.css', '!*-rtl.css', '!*.min.css' ],
						dest: 'assets/css/',
						ext: '-rtl.css',
					},
				],
			},
		},

		// Generate .pot file
		makepot: {
			target: {
				options: {
					domainPath: 'languages/',
					potFilename: 'wpmediaverse.pot',
					type: 'wp-plugin',
					updateTimestamp: false,
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
							'!dist/**',
							// src/ included — block CSS loaded from src/blocks/ at runtime.

							// ── Docs, plans, marketing (GitHub only) ──
							'!docs/**',
							'!plan/**',
							'!plans/**',
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
	grunt.loadNpmTasks( 'grunt-rtlcss' );
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

	// Run npm run build for Gutenberg blocks
	grunt.registerTask( 'blocks', 'Build Gutenberg blocks via webpack.', function() {
		var done = this.async();
		var execFile = require( 'child_process' ).execFile;
		grunt.log.writeln( 'Building blocks (npm run build)...' );
		execFile( 'npx', [ 'wp-scripts', 'build' ], function( err, stdout, stderr ) {
			if ( err ) {
				grunt.log.error( stderr || stdout );
				done( false );
				return;
			}
			grunt.log.ok( 'Blocks built successfully.' );
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
	grunt.registerTask( 'build', [ 'blocks', 'rtlcss', 'cssmin', 'uglify', 'makepot' ] );

	// Dist: full build + strip dev deps + package zip + restore dev deps
	grunt.registerTask( 'dist', [ 'build', 'composer-prod', 'clean:dist', 'copy:dist', 'compress:dist', 'composer-restore', 'dist-summary' ] );

	// Release: CI check + dist (for production releases)
	grunt.registerTask( 'release', [ 'ci-check', 'dist' ] );

	// Default
	grunt.registerTask( 'default', [ 'build' ] );
};
