<?php
/**
 * Code standard checks with a baseline: existing code passes until it is
 * touched, new code fails at once.
 *
 *   Rule 9   no ticket or card id in a comment (the id belongs in the commit).
 *   Rule 10  no method over 150 lines; a baselined long method may not grow.
 *   Rule 11  no new $wpdb query outside the repository layer and the migrator.
 *
 * Usage: php bin/lib/code-standard-check.php <plugin-dir> [--bless]
 * Prints one line per violation and exits 1 when there is any. --bless rewrites
 * audit/cleanup/code-standard-baseline.txt from the current tree; do that only
 * when the debt itself shrank, never to make a new violation pass.
 *
 * @package WPMediaVerse
 */

$dir   = rtrim( $argv[1] ?? '.', '/' );
$bless = in_array( '--bless', $argv, true );

const MAX_METHOD_LINES = 150;
const ID_PATTERN       = '/Basecamp|\b[Cc]ards? #?\d{8,}|#\d{8,}|\b(?:9\d{9}|10\d{9})\b/';
const WPDB_METHODS     = array( 'query', 'get_results', 'get_var', 'get_row', 'get_col', 'insert', 'update', 'delete', 'replace' );

// Data access belongs in a repository; the migrator owns schema changes.
$wpdb_allowed = '#(^|/)(Repository/|Core/Migrator\.php$|Core/Installer\.php$)#';

$baseline_file = $dir . '/audit/cleanup/code-standard-baseline.txt';

/**
 * Files the checks read: shipped PHP and JS, never generated or vendored code.
 */
function standard_files( string $dir ): array {
	$out = array();
	foreach ( array( 'includes', 'src', 'templates', 'assets/js' ) as $root ) {
		if ( ! is_dir( "$dir/$root" ) ) {
			continue;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$dir/$root", FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			$path = substr( $file->getPathname(), strlen( $dir ) + 1 );
			if ( preg_match( '#(\.min\.js$|/vendor/|/build/)#', $path ) || ! preg_match( '/\.(php|js)$/', $path ) ) {
				continue;
			}
			$out[] = $path;
		}
	}
	sort( $out );
	return $out;
}

/**
 * Comment texts in a JS file: whole-line // comments and /* blocks.
 */
function js_comments( string $src ): array {
	preg_match_all( '#^\s*//.*$|/\*.*?\*/#ms', $src, $m );
	return $m[0];
}

/**
 * Comments, method lengths and $wpdb query calls in one PHP file.
 */
function php_facts( string $src ): array {
	$tokens   = token_get_all( $src );
	$comments = array();
	$methods  = array();
	$wpdb     = 0;
	$count    = count( $tokens );
	for ( $i = 0; $i < $count; $i++ ) {
		$t = $tokens[ $i ];
		if ( ! is_array( $t ) ) {
			continue;
		}
		if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) {
			$comments[] = $t[1];
		} elseif ( T_VARIABLE === $t[0] && '$wpdb' === $t[1] ) {
			$j = $i + 1;
			if ( isset( $tokens[ $j ], $tokens[ $j + 1 ] ) && is_array( $tokens[ $j ] ) && T_OBJECT_OPERATOR === $tokens[ $j ][0]
				&& is_array( $tokens[ $j + 1 ] ) && in_array( $tokens[ $j + 1 ][1], WPDB_METHODS, true ) ) {
				++$wpdb;
			}
		} elseif ( T_FUNCTION === $t[0] ) {
			$name = null;
			for ( $j = $i + 1; $j < $count; $j++ ) {
				if ( is_array( $tokens[ $j ] ) && T_STRING === $tokens[ $j ][0] ) {
					$name = $tokens[ $j ][1];
					break;
				}
				if ( '(' === $tokens[ $j ] ) {
					break; // A closure.
				}
			}
			if ( null === $name ) {
				continue;
			}
			// Find the body: the first '{' before a ';' (abstract and interface methods have none).
			for ( ; $j < $count && '{' !== $tokens[ $j ] && ';' !== $tokens[ $j ]; $j++ );
			if ( $j >= $count || ';' === $tokens[ $j ] ) {
				continue;
			}
			$depth = 0;
			for ( $k = $j; $k < $count; $k++ ) {
				$tk = $tokens[ $k ];
				if ( '{' === $tk || ( is_array( $tk ) && in_array( $tk[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) ) {
					++$depth;
				} elseif ( '}' === $tk && 0 === --$depth ) {
					break;
				}
			}
			$end_line = 0;
			for ( $b = $k; $b >= 0 && ! $end_line; $b-- ) {
				$end_line = is_array( $tokens[ $b ] ) ? $tokens[ $b ][2] + substr_count( $tokens[ $b ][1], "\n" ) : 0;
			}
			$methods[ $name ] = max( $methods[ $name ] ?? 0, $end_line - $t[2] + 1 );
		}
	}
	return array( $comments, $methods, $wpdb );
}

$current = array();
foreach ( standard_files( $dir ) as $path ) {
	$src = (string) file_get_contents( "$dir/$path" );
	if ( str_ends_with( $path, '.js' ) ) {
		$comments = js_comments( $src );
		$methods  = array();
		$wpdb     = 0;
	} else {
		list( $comments, $methods, $wpdb ) = php_facts( $src );
	}
	foreach ( $comments as $c ) {
		foreach ( explode( "\n", $c ) as $line ) {
			if ( preg_match( ID_PATTERN, $line ) ) {
				$current[] = "id\t$path\t" . sha1( trim( $line ) );
			}
		}
	}
	foreach ( $methods as $name => $lines ) {
		if ( $lines > MAX_METHOD_LINES ) {
			$current[] = "method\t$path::$name\t$lines";
		}
	}
	if ( $wpdb && ! preg_match( $wpdb_allowed, $path ) ) {
		$current[] = "wpdb\t$path\t$wpdb";
	}
}

if ( $bless ) {
	file_put_contents( $baseline_file, "# Code standard baseline: existing debt that passes until touched. Rewrite with --bless only when it shrinks.\n" . implode( "\n", $current ) . "\n" );
	echo 'Blessed ' . count( $current ) . " entries.\n";
	exit( 0 );
}

$base = array();
if ( is_readable( $baseline_file ) ) {
	foreach ( file( $baseline_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
		if ( '#' !== $line[0] ) {
			$parts                           = explode( "\t", $line );
			$base[ $parts[0] . "\t" . $parts[1] ][] = $parts[2] ?? '';
		}
	}
}

$fail = 0;
foreach ( $current as $row ) {
	list( $kind, $where, $value ) = explode( "\t", $row );
	$known                        = $base[ "$kind\t$where" ] ?? array();
	if ( 'id' === $kind && ! in_array( $value, $known, true ) ) {
		echo "Rule 9  $where: a comment names a ticket or card id; put the reason in the comment and the id in the commit\n";
		++$fail;
	} elseif ( 'method' === $kind && ( ! $known || (int) $value > (int) $known[0] ) ) {
		echo "Rule 10 $where: $value lines" . ( $known ? " (baseline {$known[0]})" : '' ) . ': split it by job, nothing new over ' . MAX_METHOD_LINES . "\n";
		++$fail;
	} elseif ( 'wpdb' === $kind && ( ! $known || (int) $value > (int) $known[0] ) ) {
		echo "Rule 11 $where: $value \$wpdb queries" . ( $known ? " (baseline {$known[0]})" : '' ) . ": new queries go in a repository class\n";
		++$fail;
	}
}
exit( $fail ? 1 : 0 );
