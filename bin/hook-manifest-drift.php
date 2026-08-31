<?php
/**
 * Hook-manifest drift gate.
 *
 * Stage 3.1 only checks that the manifest is valid JSON and not too old. Age is
 * not correctness: a `touch` satisfies it, and a manifest can be freshly dated
 * and still describe hooks that no longer exist. That is not hypothetical - on
 * 2026-09-01 this plugin's manifest was missing 20 hooks that ship and fire
 * (including `mvs_document_drive_access` and `mvs_user_can_use_documents`,
 * which readme.txt already advertises to developers), and it was still
 * advertising `mvs_ffmpeg_binary`, removed with video transcoding in 2.4.0.
 *
 * So this compares the manifests against the code in BOTH directions:
 *   - fired in code, absent from every manifest -> undocumented extension point
 *   - present in a manifest, fired nowhere       -> we are advertising a lie
 *
 * Usage: php bin/hook-manifest-drift.php [--json]
 * Exit:  0 clean, 1 drift.
 *
 * @package WPMediaVerse
 */

declare( strict_types = 1 );

$free_dir = dirname( __DIR__ );
$pro_dir  = dirname( $free_dir ) . '/wpmediaverse-pro';

/** Hook name prefixes this plugin family owns. */
const OWNED_PREFIXES = array( 'mvs_', 'wpmediaverse_' );

/**
 * Hooks that legitimately have no literal do_action()/apply_filters() call site.
 * Each needs a reason: an unexplained entry here is how a gate stops gating.
 */
const EXEMPT = array(
	// Scheduled in Pro Core/Plugin.php; Action Scheduler fires them, so the
	// call site lives in the library, not in our source.
	'mvs_activate_scheduled_challenges' => 'Action Scheduler action',
	'mvs_close_challenge_entries'       => 'Action Scheduler action',
	'mvs_finalize_expired_challenges'   => 'Action Scheduler action',
	'mvs_resolve_expired_matches'       => 'Action Scheduler action',
	'mvs_start_registered_tournaments'  => 'Action Scheduler action',
	// Dynamic hook, documented under its braced form.
	'mvs_settings_render_{renderer}'    => 'dynamic hook name',
	'mvs_settings_render_'              => 'dynamic hook prefix',
);

/**
 * Collect every hook fired in a tree.
 *
 * @param string $dir Plugin directory.
 * @return array<string,string> hook name => first call site.
 */
function mvs_hooks_in_code( string $dir ): array {
	$found = array();
	if ( ! is_dir( $dir ) ) {
		return $found;
	}

	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		$path = $file->getPathname();
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		// Vendored, built and test code is not the shipped surface.
		if ( preg_match( '#/(vendor|node_modules|dist|libs|build|tests|\.git)/#', $path ) ) {
			continue;
		}
		$src = (string) file_get_contents( $path );
		if ( ! preg_match_all( '#(?:apply_filters|do_action)(?:_ref_array|_deprecated)?\(\s*[\'"]([a-z0-9_]+)[\'"]#', $src, $m, PREG_OFFSET_CAPTURE ) ) {
			continue;
		}
		foreach ( $m[1] as $hit ) {
			$name = $hit[0];
			if ( ! mvs_is_owned( $name ) || isset( $found[ $name ] ) ) {
				continue;
			}
			$line           = substr_count( substr( $src, 0, $hit[1] ), "\n" ) + 1;
			$found[ $name ] = str_replace( dirname( $dir ) . '/', '', $path ) . ':' . $line;
		}
	}
	return $found;
}

/**
 * Whether a hook belongs to this plugin family.
 *
 * @param string $name Hook name.
 * @return bool
 */
function mvs_is_owned( string $name ): bool {
	foreach ( OWNED_PREFIXES as $prefix ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Read hook names out of a manifest file.
 *
 * @param string $path Manifest path.
 * @return array<int,string>
 */
function mvs_hooks_in_manifest( string $path ): array {
	if ( ! is_file( $path ) ) {
		return array();
	}
	$data = json_decode( (string) file_get_contents( $path ), true );
	if ( ! is_array( $data ) || ! isset( $data['hooks_fired'] ) ) {
		return array();
	}
	return array_map(
		static function ( $h ) {
			return is_array( $h ) ? (string) ( $h['name'] ?? '' ) : (string) $h;
		},
		(array) $data['hooks_fired']
	);
}

$code = mvs_hooks_in_code( $free_dir ) + mvs_hooks_in_code( $pro_dir );

$manifest = array();
foreach ( array( '/audit/manifests/manifest.hooks.json', '/audit/pro/manifests/manifest.hooks.json' ) as $rel ) {
	$manifest = array_merge( $manifest, mvs_hooks_in_manifest( $free_dir . $rel ) );
}
$manifest = array_unique( array_filter( $manifest ) );

$undocumented = array_diff( array_keys( $code ), $manifest, array_keys( EXEMPT ) );
$phantom      = array_diff( $manifest, array_keys( $code ), array_keys( EXEMPT ) );

sort( $undocumented );
sort( $phantom );

if ( in_array( '--json', $argv, true ) ) {
	echo wp_json_encode_fallback(
		array(
			'undocumented' => array_values( $undocumented ),
			'phantom'      => array_values( $phantom ),
			'code_total'   => count( $code ),
			'manifest_total' => count( $manifest ),
		)
	), "\n";
} else {
	printf( "hooks fired in code: %d   ·   hooks in manifests: %d\n", count( $code ), count( $manifest ) );
	foreach ( $undocumented as $name ) {
		printf( "  UNDOCUMENTED  %-42s fired at %s\n", $name, $code[ $name ] );
	}
	foreach ( $phantom as $name ) {
		printf( "  PHANTOM       %-42s in a manifest, fired nowhere\n", $name );
	}
	if ( ! $undocumented && ! $phantom ) {
		echo "hook manifests match the code in both directions\n";
	}
}

/**
 * json_encode with pretty print, isolated so the script stays WP-free.
 *
 * @param mixed $data Data.
 * @return string
 */
function wp_json_encode_fallback( $data ): string {
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

exit( ( $undocumented || $phantom ) ? 1 : 0 );
