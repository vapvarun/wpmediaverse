<?php
/**
 * Extension guard check: every call into an optional PHP extension in shipped code has a guard just before it.
 *
 * Hosts ship without mbstring, ctype, fileinfo, SimpleXML, dom, iconv or zip often enough that one unguarded call is
 * a fatal on someone's site. A call counts as guarded when one of the GUARD_WINDOW lines before it (or its own line)
 * holds function_exists() or class_exists() for a symbol of the same extension (a finfo_open() guard covers the
 * finfo_file() after it), or extension_loaded(). WordPress polyfills mb_strlen,
 * mb_substr and mb_str_split, so those are allowed bare.
 *
 * Usage: php bin/lib/extension-guard-check.php <plugin-dir>
 * Prints one line per unguarded call and exits 1 when there is any; prints nothing and exits 0 otherwise.
 *
 * @package WPMediaVerse
 */

namespace WPMediaVerse\Bin\ExtensionGuard;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput -- CLI tool, not plugin runtime.

const GUARD_WINDOW = 12;

// Function prefixes and names that belong to an optional extension.
const EXT_FUNCTION_PATTERN = '/^(mb_|ctype_|finfo_|simplexml_|iconv|mime_content_type$|dom_import_simplexml$|libxml_)/i';
const POLYFILLED           = array( 'mb_strlen', 'mb_substr', 'mb_str_split' );
const EXT_CLASSES          = array( 'domdocument', 'domxpath', 'simplexmlelement', 'xmlreader', 'xmlwriter', 'ziparchive', 'finfo' );

$plugin_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
	fwrite( STDERR, "usage: php extension-guard-check.php <plugin-dir>\n" );
	exit( 2 );
}

$files = array();
foreach ( glob( $plugin_dir . '/*.php' ) as $top ) {
	$files[] = $top;
}
foreach ( array( 'includes', 'templates', 'src' ) as $dir ) {
	if ( ! is_dir( "$plugin_dir/$dir" ) ) {
		continue;
	}
	$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( "$plugin_dir/$dir", \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( 'php' === $file->getExtension() ) {
			$files[] = $file->getPathname();
		}
	}
}

$found = 0;
foreach ( $files as $file ) {
	$code   = (string) file_get_contents( $file );
	$lines  = explode( "\n", $code );
	$tokens = token_get_all( $code );
	$count  = count( $tokens );

	for ( $i = 0; $i < $count; $i++ ) {
		$name = null;
		if ( is_array( $tokens[ $i ] ) && T_NEW === $tokens[ $i ][0] ) {
			$j = next_code( $tokens, $i + 1 );
			if ( null !== $j && is_array( $tokens[ $j ] ) ) {
				$class = strtolower( ltrim( $tokens[ $j ][1], '\\' ) );
				if ( in_array( $class, EXT_CLASSES, true ) ) {
					$name = ltrim( $tokens[ $j ][1], '\\' );
				}
			}
		} elseif ( is_array( $tokens[ $i ] ) && in_array( $tokens[ $i ][0], name_tokens(), true ) ) {
			$fn      = ltrim( $tokens[ $i ][1], '\\' );
			$next    = next_code( $tokens, $i + 1 );
			$prev    = prev_code( $tokens, $i - 1 );
			$is_call = null !== $next && '(' === $tokens[ $next ];
			$is_decl = null !== $prev && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW ), true );
			if ( $is_call && ! $is_decl && preg_match( EXT_FUNCTION_PATTERN, $fn ) && ! in_array( strtolower( $fn ), POLYFILLED, true ) ) {
				$name = $fn;
			}
		}
		if ( null === $name ) {
			continue;
		}

		$line   = $tokens[ $i ][2];
		$window = implode( "\n", array_slice( $lines, max( 0, $line - 1 - GUARD_WINDOW ), min( $line, GUARD_WINDOW + 1 ) ) );
		if ( guarded( $name, $window ) ) {
			continue;
		}
		echo 'Extension guard — ' . substr( $file, strlen( $plugin_dir ) + 1 ) . ":$line calls $name() with no function_exists/class_exists guard in the " . GUARD_WINDOW . " lines before it\n";
		++$found;
	}
}
exit( $found ? 1 : 0 );

/**
 * The extension a function or class belongs to, so a guard on one of its symbols covers the others.
 *
 * @param string $name Function or class name.
 */
function family( string $name ): string {
	$name = strtolower( ltrim( $name, '\\' ) );
	foreach ( array(
		'mb_'               => 'mbstring',
		'ctype_'            => 'ctype',
		'finfo'             => 'fileinfo',
		'mime_content_type' => 'fileinfo',
		'iconv'             => 'iconv',
		'simplexml'         => 'xml',
		'libxml_'           => 'xml',
		'dom'               => 'xml',
		'xmlreader'         => 'xml',
		'xmlwriter'         => 'xml',
		'ziparchive'        => 'zip',
	) as $prefix => $family ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return $family;
		}
	}
	return $name;
}

/**
 * Whether the window holds a guard for the extension $name belongs to.
 *
 * @param string $name   Function or class called.
 * @param string $window Source lines before the call, and the call's own line.
 */
function guarded( string $name, string $window ): bool {
	if ( false !== stripos( $window, 'extension_loaded(' ) ) {
		return true;
	}
	preg_match_all( "/(?:function_exists|class_exists)\\(\\s*'([^']+)'/i", $window, $m );
	foreach ( $m[1] as $symbol ) {
		if ( family( $symbol ) === family( $name ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Token types that can carry a function or class name.
 *
 * @return int[]
 */
function name_tokens(): array {
	return array_values( array_filter( array( T_STRING, defined( 'T_NAME_FULLY_QUALIFIED' ) ? T_NAME_FULLY_QUALIFIED : null ) ) );
}

/**
 * Index of the next token that is not whitespace or a comment, or null.
 *
 * @param array $tokens Tokens.
 * @param int   $i      Start index.
 */
function next_code( array $tokens, int $i ): ?int {
	for ( $n = count( $tokens ); $i < $n; $i++ ) {
		if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			return $i;
		}
	}
	return null;
}

/**
 * Index of the previous token that is not whitespace or a comment, or null.
 *
 * @param array $tokens Tokens.
 * @param int   $i      Start index.
 */
function prev_code( array $tokens, int $i ): ?int {
	for ( ; $i >= 0; $i-- ) {
		if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			return $i;
		}
	}
	return null;
}
