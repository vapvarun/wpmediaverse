<?php
/**
 * bin/cleanup-duplicate-detect.php
 *
 * Detects near-duplicate method bodies across Free + Pro by tokenizing each
 * method, normalizing identifiers + whitespace, and hashing the normalized
 * token stream. Methods with the same normalized hash AND body size ≥ N
 * tokens are flagged as candidates for consolidation.
 *
 * Output: audit/cleanup/duplicates.json
 *
 * Hard rule on results: a flagged candidate is NOT automatically a bug.
 * Some duplication is intentional (bundled POMO/getID3, constructor
 * stubs, getter shapes). Every candidate needs a human review before
 * acting on the cleanup plan.
 *
 * Usage:
 *   php bin/cleanup-duplicate-detect.php
 *
 * Heuristics:
 *   - Min token count per method to consider: 30 (skips trivial getters)
 *   - Identifier normalization: $vars → $V, function names → preserved
 *     (so two methods named differently with same body still match)
 *   - Comments + whitespace stripped
 *   - String literals replaced with "S" (so "user_id" vs "post_id" tokens
 *     don't break the match; you can flag literal drift separately)
 *
 * @package WPMediaVerse
 */

if ( PHP_VERSION_ID < 70400 ) {
	fwrite( STDERR, "PHP 7.4+ required\n" );
	exit( 1 );
}

$free_dir = realpath( __DIR__ . '/..' );
$pro_dir  = realpath( __DIR__ . '/../../wpmediaverse-pro' );
$out_dir  = $free_dir . '/audit/cleanup';
$out_file = $out_dir . '/duplicates.json';

if ( ! is_dir( $out_dir ) ) {
	mkdir( $out_dir, 0755, true );
}

const MIN_TOKENS = 30;
const EXCLUDED_DIRS = array( 'vendor', 'node_modules', 'dist', 'build', 'audit', 'tests' );

/**
 * Recursively find PHP files under $dir, excluding library trees.
 */
function find_php_files( string $dir ): array {
	if ( ! is_dir( $dir ) ) {
		return array();
	}
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( ! $f->isFile() || $f->getExtension() !== 'php' ) {
			continue;
		}
		$path = $f->getPathname();
		foreach ( EXCLUDED_DIRS as $skip ) {
			if ( strpos( $path, "/$skip/" ) !== false ) {
				continue 2;
			}
		}
		// Also skip bundled POMO / getID3 / phpmailer
		if ( preg_match( '#/(pomo|getid3|phpmailer|certificates|requests|sodium|simplepie|class-pop3\.php|class-snoopy\.php|class-IXR|class-wp-locale)/?#i', $path ) ) {
			continue;
		}
		$out[] = $path;
	}
	return $out;
}

/**
 * Extract all methods from a PHP file. Returns array of [class, method, start_line, end_line, normalized_hash, token_count].
 */
function extract_methods( string $file ): array {
	$source = file_get_contents( $file );
	if ( $source === false ) {
		return array();
	}
	$tokens = token_get_all( $source );

	$out          = array();
	$current_cls  = '';
	$brace_depth  = 0;
	$cls_depth    = -1;
	$i            = 0;
	$n            = count( $tokens );

	while ( $i < $n ) {
		$t = $tokens[ $i ];
		if ( is_array( $t ) ) {
			// Class boundary tracking
			if ( $t[0] === T_CLASS && $i + 2 < $n && is_array( $tokens[ $i + 2 ] ) && $tokens[ $i + 2 ][0] === T_STRING ) {
				$current_cls = $tokens[ $i + 2 ][1];
				$cls_depth   = $brace_depth;
			}
			// Function detection
			if ( $t[0] === T_FUNCTION ) {
				// Look ahead for function name
				$j = $i + 1;
				while ( $j < $n && ( is_array( $tokens[ $j ] ) && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) ) {
					$j++;
				}
				if ( $j < $n && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_STRING ) {
					$method_name = $tokens[ $j ][1];
					$start_line  = $t[2];
					// Walk forward to the opening { of the method body
					$k = $j;
					while ( $k < $n && $tokens[ $k ] !== '{' ) {
						$k++;
					}
					if ( $k >= $n ) {
						$i++;
						continue;
					}
					// Capture body until matching }
					$depth   = 1;
					$body    = array();
					$k++;
					while ( $k < $n && $depth > 0 ) {
						$bt = $tokens[ $k ];
						if ( $bt === '{' ) {
							$depth++;
						} elseif ( $bt === '}' ) {
							$depth--;
							if ( $depth === 0 ) {
								break;
							}
						}
						$body[] = $bt;
						$k++;
					}
					$end_line = is_array( $tokens[ $k ] ) ? $tokens[ $k ][2] : $start_line;

					$normalized = normalize_tokens( $body );
					if ( count( $normalized ) >= MIN_TOKENS ) {
						$hash = sha1( implode( ' ', $normalized ) );
						$out[] = array(
							'class'       => $current_cls,
							'method'      => $method_name,
							'file'        => $file,
							'start_line'  => $start_line,
							'end_line'    => $end_line,
							'token_count' => count( $normalized ),
							'hash'        => $hash,
						);
					}
					$i = $k + 1;
					continue;
				}
			}
		}
		if ( $t === '{' ) {
			$brace_depth++;
		} elseif ( $t === '}' ) {
			$brace_depth--;
			if ( $brace_depth === $cls_depth ) {
				$current_cls = '';
				$cls_depth   = -1;
			}
		}
		$i++;
	}
	return $out;
}

/**
 * Normalize token stream to canonicalize identifiers + strings so similar
 * methods hash to the same value.
 */
function normalize_tokens( array $tokens ): array {
	$out = array();
	foreach ( $tokens as $t ) {
		if ( is_array( $t ) ) {
			$id   = $t[0];
			$text = $t[1];
			// Strip whitespace / comments
			if ( in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				continue;
			}
			// Variables → $V
			if ( $id === T_VARIABLE ) {
				$out[] = '$V';
				continue;
			}
			// String literals → S
			if ( in_array( $id, array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				$out[] = 'S';
				continue;
			}
			// Numbers → N
			if ( in_array( $id, array( T_LNUMBER, T_DNUMBER ), true ) ) {
				$out[] = 'N';
				continue;
			}
			// Keep keywords + identifiers verbatim — they carry semantics
			$out[] = $text;
		} else {
			$out[] = $t;
		}
	}
	return $out;
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

$free_files = find_php_files( $free_dir );
$pro_files  = is_dir( $pro_dir ) ? find_php_files( $pro_dir ) : array();

fprintf( STDERR, "Scanning Free: %d files, Pro: %d files...\n", count( $free_files ), count( $pro_files ) );

$by_hash = array();

foreach ( $free_files as $f ) {
	foreach ( extract_methods( $f ) as $m ) {
		$m['plugin'] = 'free';
		$m['rel']    = str_replace( $free_dir . '/', '', $m['file'] );
		$by_hash[ $m['hash'] ][] = $m;
	}
}
foreach ( $pro_files as $f ) {
	foreach ( extract_methods( $f ) as $m ) {
		$m['plugin'] = 'pro';
		$m['rel']    = str_replace( $pro_dir . '/', '', $m['file'] );
		$by_hash[ $m['hash'] ][] = $m;
	}
}

// A duplicate is a hash bucket with ≥ 2 entries spanning ≥ 1 plugin.
// We are most interested in CROSS-PLUGIN duplicates first (Free ↔ Pro).
// Then in-plugin duplicates (Free↔Free or Pro↔Pro) as secondary signal.

$cross  = array();
$within = array();

foreach ( $by_hash as $hash => $bucket ) {
	if ( count( $bucket ) < 2 ) {
		continue;
	}
	$plugins = array_unique( array_column( $bucket, 'plugin' ) );
	if ( count( $plugins ) > 1 ) {
		$cross[] = array(
			'hash'        => $hash,
			'instances'   => count( $bucket ),
			'token_count' => $bucket[0]['token_count'],
			'sites'       => array_map( function ( $m ) {
				return array(
					'plugin' => $m['plugin'],
					'file'   => $m['rel'],
					'lines'  => $m['start_line'] . '-' . $m['end_line'],
					'method' => ( $m['class'] ? $m['class'] . '::' : '' ) . $m['method'],
				);
			}, $bucket ),
		);
	} else {
		$within[] = array(
			'hash'        => $hash,
			'plugin'      => $plugins[0],
			'instances'   => count( $bucket ),
			'token_count' => $bucket[0]['token_count'],
			'sites'       => array_map( function ( $m ) {
				return array(
					'file'   => $m['rel'],
					'lines'  => $m['start_line'] . '-' . $m['end_line'],
					'method' => ( $m['class'] ? $m['class'] . '::' : '' ) . $m['method'],
				);
			}, $bucket ),
		);
	}
}

// Sort by token_count DESC so the chunkiest duplicates rise to the top
usort( $cross,  function ( $a, $b ) { return $b['token_count'] - $a['token_count']; } );
usort( $within, function ( $a, $b ) { return $b['token_count'] - $a['token_count']; } );

$result = array(
	'generated_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
	'generator'    => 'bin/cleanup-duplicate-detect.php',
	'config'       => array(
		'min_tokens'       => MIN_TOKENS,
		'normalization'    => 'whitespace+comments stripped; $vars→$V; strings→S; numbers→N; identifiers preserved',
		'excluded_libs'    => array( 'pomo', 'getid3', 'phpmailer', 'certificates', 'requests', 'sodium', 'simplepie' ),
	),
	'counts'       => array(
		'cross_plugin_duplicates' => count( $cross ),
		'within_plugin_duplicates' => count( $within ),
		'free_files_scanned'      => count( $free_files ),
		'pro_files_scanned'       => count( $pro_files ),
	),
	'cross_plugin_duplicates' => $cross,
	'within_plugin_duplicates' => $within,
);

file_put_contents( $out_file, json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
fprintf( STDERR, "Wrote %s\n", $out_file );
fprintf( STDERR, "cross_plugin_duplicates=%d  within_plugin_duplicates=%d\n", count( $cross ), count( $within ) );
