<?php
// phpcs:ignoreFile -- dev CLI gate shared across the plugin family; never loaded by a plugin at runtime.
/**
 * Extension guard gate: every call into an optional PHP extension in shipped code is guarded just before it.
 *
 * CANONICAL COPY: ~/.claude/skills/wp-plugin-development/scripts/extension-guard-check.php (with its
 * extension-guard-check.test.php). Plugins keep an identical copy (MediaVerse: bin/lib/); change the canonical
 * file, then copy it, never edit a plugin copy alone. `diff` the two to check.
 *
 * Usage: php extension-guard-check.php <plugin-dir> [dir ...]
 *   Scans <plugin-dir>/*.php plus the listed sub-directories (default: includes templates src).
 *   Prints one line per finding and exits 1 when there is any; prints nothing and exits 0 otherwise.
 *
 * What counts as a use: a call to an extension function (mb_* except WordPress's polyfilled mb_strlen, mb_substr and
 * mb_str_split; ctype_*; finfo_*; mime_content_type; iconv*; simplexml_*; libxml_*; xml_*; dom_import_simplexml;
 * zip_*), `new X` or `X::` (not `X::class`) for an extension class (DOMDocument, DOMXPath, DOMElement, DOMNode,
 * SimpleXMLElement, XMLReader, XMLWriter, ZipArchive, finfo). Leading backslashes are ignored.
 *
 * What counts as a guard, read from PHP tokens, so comments and string literals never count:
 *   - function_exists( 'f' ) / class_exists( 'C' ) where f or C belongs to the SAME extension, or
 *     extension_loaded( 'name' ) naming that extension. Either quote style.
 *   - it sits before the use and no more than WINDOW lines above it.
 *   - an extension that needs another proves it: simplexml, dom, xmlreader and xmlwriter guards cover libxml_*.
 * finfo handles: every finfo_* call other than finfo_open() must have its first argument, a variable, checked for
 * false in the window first: `$h ?`, `$h &&`, `! $h`, `false === $h` / `!== $h`, `$h instanceof`, or `if ( $h`.
 * finfo_open() can return false even with fileinfo loaded, and finfo_file( false ) is a TypeError on PHP 8. The
 * handle check also proves the extension, so no function_exists() is needed there (finfo_open() itself needs one).
 *
 * Not caught (a token gate cannot know the name): calls through a variable or a string (`$f( ... )`,
 * call_user_func( 'mb_strtolower', ... )), and methods on an object whose class came from elsewhere.
 *
 * Not scanned, on purpose: libs/ and vendor/ (third-party code, fixed upstream and re-bundled, never edited here),
 * bin/, tools/ and tests/ (developer tooling run on a full PHP build, never shipped or never run on a customer
 * site), build/, dist/ and node_modules/ (generated copies of src/, which is scanned).
 */

namespace Wbcom\ExtensionGuard;

const WINDOW = 12;

const FUNCTION_FAMILIES = array(
	'mb_'                  => 'mbstring',
	'ctype_'               => 'ctype',
	'finfo_'               => 'fileinfo',
	'mime_content_type'    => 'fileinfo',
	'iconv'                => 'iconv',
	'simplexml_'           => 'simplexml',
	'libxml_'              => 'libxml',
	'xml_'                 => 'xml',
	'dom_import_simplexml' => 'dom',
	'zip_'                 => 'zip',
);
const POLYFILLED       = array( 'mb_strlen', 'mb_substr', 'mb_str_split' );
const CLASS_FAMILIES   = array(
	'domdocument'      => 'dom',
	'domxpath'         => 'dom',
	'domelement'       => 'dom',
	'domnode'          => 'dom',
	'simplexmlelement' => 'simplexml',
	'xmlreader'        => 'xmlreader',
	'xmlwriter'        => 'xmlwriter',
	'ziparchive'       => 'zip',
	'finfo'            => 'fileinfo',
);
const IMPLIES          = array(
	'simplexml' => array( 'libxml' ),
	'dom'       => array( 'libxml' ),
	'xmlreader' => array( 'libxml' ),
	'xmlwriter' => array( 'libxml' ),
);
const SKIP             = array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT );

/**
 * The extension a function belongs to, or null when it is not an optional-extension function.
 */
function function_family( string $name ): ?string {
	$name = strtolower( ltrim( $name, '\\' ) );
	if ( in_array( $name, POLYFILLED, true ) ) {
		return null;
	}
	foreach ( FUNCTION_FAMILIES as $prefix => $family ) {
		if ( 0 === strpos( $name, $prefix ) ) {
			return $family;
		}
	}
	return null;
}

/**
 * The extension a class belongs to, or null.
 */
function class_family( string $name ): ?string {
	return CLASS_FAMILIES[ strtolower( ltrim( $name, '\\' ) ) ] ?? null;
}

/**
 * Whether a token is a name (function or class), and its text.
 */
function name_of( $token ): ?string {
	if ( ! is_array( $token ) ) {
		return null;
	}
	$types = array( T_STRING );
	if ( defined( 'T_NAME_FULLY_QUALIFIED' ) ) {
		$types[] = T_NAME_FULLY_QUALIFIED;
	}
	return in_array( $token[0], $types, true ) ? ltrim( $token[1], '\\' ) : null;
}

/**
 * Index of the next (step 1) or previous (step -1) code token, skipping whitespace and comments.
 */
function step( array $t, int $i, int $dir ): ?int {
	for ( $i += $dir; $i >= 0 && $i < count( $t ); $i += $dir ) {
		if ( ! is_array( $t[ $i ] ) || ! in_array( $t[ $i ][0], SKIP, true ) ) {
			return $i;
		}
	}
	return null;
}

/**
 * Every guard in the code: [ token index, line, family it proves ].
 */
function guards( array $t ): array {
	$out = array();
	foreach ( $t as $i => $token ) {
		$fn = strtolower( (string) name_of( $token ) );
		if ( ! in_array( $fn, array( 'function_exists', 'class_exists', 'extension_loaded' ), true ) ) {
			continue;
		}
		$open = step( $t, $i, 1 );
		$arg  = null !== $open && '(' === $t[ $open ] ? step( $t, $open, 1 ) : null;
		if ( null === $arg || ! is_array( $t[ $arg ] ) || T_CONSTANT_ENCAPSED_STRING !== $t[ $arg ][0] ) {
			continue;
		}
		$value  = stripslashes( substr( $t[ $arg ][1], 1, -1 ) );
		$family = 'extension_loaded' === $fn ? strtolower( $value ) : ( 'class_exists' === $fn ? class_family( $value ) : function_family( $value ) );
		if ( null === $family ) {
			continue;
		}
		foreach ( array_merge( array( $family ), IMPLIES[ $family ] ?? array() ) as $proved ) {
			$out[] = array( $i, $token[2], $proved );
		}
	}
	return $out;
}

/**
 * Whether variable $var is checked for false/truthiness in tokens before $before, at or below line $from.
 */
function handle_checked( array $t, string $var, int $before, int $from ): bool {
	for ( $i = 0; $i < $before; $i++ ) {
		if ( ! is_array( $t[ $i ] ) || T_VARIABLE !== $t[ $i ][0] || $var !== $t[ $i ][1] || $t[ $i ][2] < $from ) {
			continue;
		}
		$n  = step( $t, $i, 1 );
		$p  = step( $t, $i, -1 );
		$pp = null !== $p ? step( $t, $p, -1 ) : null;
		$next = null !== $n ? $t[ $n ] : null;
		$prev = null !== $p ? $t[ $p ] : null;
		if ( '?' === $next || ( is_array( $next ) && in_array( $next[0], array( T_BOOLEAN_AND, T_LOGICAL_AND, T_INSTANCEOF ), true ) ) ) {
			return true;
		}
		if ( '!' === $prev || ( is_array( $prev ) && in_array( $prev[0], array( T_IS_IDENTICAL, T_IS_NOT_IDENTICAL ), true ) ) ) {
			return true;
		}
		if ( '(' === $prev && null !== $pp && is_array( $t[ $pp ] ) && in_array( $t[ $pp ][0], array( T_IF, T_ELSEIF ), true ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Findings for one file's source: list of [ line, message ].
 */
function scan_code( string $code ): array {
	$t        = token_get_all( $code );
	$guards   = guards( $t );
	$findings = array();

	foreach ( $t as $i => $token ) {
		$name = name_of( $token );
		if ( null === $name && ! ( is_array( $token ) && T_NEW === $token[0] ) ) {
			continue;
		}
		$next = step( $t, $i, 1 );
		$prev = step( $t, $i, -1 );
		$family = null;
		$what   = '';

		if ( is_array( $token ) && T_NEW === $token[0] ) {
			$cls = null !== $next ? name_of( $t[ $next ] ) : null;
			if ( null !== $cls && null !== ( $family = class_family( $cls ) ) ) {
				$what = "new $cls";
			}
		} elseif ( null !== $next && is_array( $t[ $next ] ) && T_DOUBLE_COLON === $t[ $next ][0] ) {
			$after = step( $t, $next, 1 );
			$is_class_const = null !== $after && is_array( $t[ $after ] ) && T_CLASS === $t[ $after ][0];
			if ( ! $is_class_const && null !== ( $family = class_family( $name ) ) ) {
				$what = "$name::";
			}
		} elseif ( null !== $next && '(' === $t[ $next ] ) {
			$declared = null !== $prev && is_array( $t[ $prev ] ) && in_array( $t[ $prev ][0], array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST, defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? T_NULLSAFE_OBJECT_OPERATOR : T_OBJECT_OPERATOR ), true );
			if ( ! $declared && null !== ( $family = function_family( $name ) ) ) {
				$what = "$name()";
			}
		}
		if ( null === $family || '' === $what ) {
			continue;
		}

		$line = $token[2];
		$from = $line - WINDOW;

		// finfo_* on a handle: the handle must be checked for false first.
		$fn = strtolower( $name ?? '' );
		if ( 'fileinfo' === $family && 0 === strpos( $fn, 'finfo_' ) && 'finfo_open' !== $fn ) {
			$arg = step( $t, (int) $next, 1 );
			$var = null !== $arg && is_array( $t[ $arg ] ) && T_VARIABLE === $t[ $arg ][0] ? $t[ $arg ][1] : null;
			if ( null === $var || ! handle_checked( $t, $var, $i, $from ) ) {
				$findings[] = array( $line, "$what uses a fileinfo handle " . ( $var ?? '(not a variable)' ) . ' not checked for false in the ' . WINDOW . ' lines before it (finfo_open() can return false)' );
			}
			continue;
		}

		$ok = false;
		foreach ( $guards as $g ) {
			if ( $g[0] < $i && $g[1] >= $from && $g[2] === $family ) {
				$ok = true;
				break;
			}
		}
		if ( ! $ok ) {
			$findings[] = array( $line, "$what needs the $family extension: no function_exists/class_exists/extension_loaded guard for it in the " . WINDOW . ' lines before it' );
		}
	}
	return $findings;
}

/**
 * Findings for a plugin directory: list of "relative/path.php:line message".
 */
function scan_dir( string $plugin_dir, array $dirs ): array {
	$files = glob( $plugin_dir . '/*.php' ) ?: array();
	foreach ( $dirs as $dir ) {
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
	$out = array();
	foreach ( $files as $file ) {
		foreach ( scan_code( (string) file_get_contents( $file ) ) as $f ) {
			$out[] = substr( $file, strlen( $plugin_dir ) + 1 ) . ':' . $f[0] . ' ' . $f[1];
		}
	}
	return $out;
}

if ( isset( $argv[0] ) && realpath( $argv[0] ) === __FILE__ ) {
	$plugin_dir = rtrim( (string) ( $argv[1] ?? '' ), '/' );
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		fwrite( STDERR, "usage: php extension-guard-check.php <plugin-dir> [dir ...]\n" );
		exit( 2 );
	}
	$dirs     = count( $argv ) > 2 ? array_slice( $argv, 2 ) : array( 'includes', 'templates', 'src' );
	$findings = scan_dir( $plugin_dir, $dirs );
	foreach ( $findings as $line ) {
		echo "Extension guard - $line\n";
	}
	exit( $findings ? 1 : 0 );
}
