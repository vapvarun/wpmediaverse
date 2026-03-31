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
							// Git / CI
							'!.git/**',
							'!.gitignore',
							'!.distignore',
							'!.github/**',
							// Dev tooling
							'!node_modules/**',
							'!tests/**',
							'!bin/**',
							'!dist/**',
							'!src/**',
							// Docs & plans (stay on GitHub only)
							'!docs/**',
							'!plan/**',
							'!plans/**',
							// Dev config
							'!phpunit.xml.dist',
							'!phpunit.xml',
							'!phpstan.neon.dist',
							'!phpstan-baseline.neon',
							'!phpcs.xml',
							'!package.json',
							'!package-lock.json',
							'!composer.json',
							'!composer.lock',
							'!Gruntfile.js',
							'!CLAUDE.md',
							'!.playwright-mcp/**',
							// Seed data (not for production)
							'!seed-demo-data.php',
							'!populate-showcase.php',
							// Markdown files (changelogs kept)
							'!**/*.md',
							'!readme.txt.bak',
							// Vendor dev deps (keep EDD SDK)
							'!vendor/bin/**',
							'!vendor/phpunit/**',
							'!vendor/squizlabs/**',
							'!vendor/wp-coding-standards/**',
							'!vendor/phpcompatibility/**',
							'!vendor/szepeviktor/**',
							'!vendor/phpstan/**',
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

	// Build: RTL + minify + pot
	grunt.registerTask( 'build', [ 'rtlcss', 'cssmin', 'uglify', 'makepot' ] );

	// Dist: CI check + build + package zip
	grunt.registerTask( 'dist', [ 'ci-check', 'build', 'clean:dist', 'copy:dist', 'compress:dist' ] );

	// Default
	grunt.registerTask( 'default', [ 'build' ] );
};
