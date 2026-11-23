# Extension guard gate: next version

Gaps in `bin/lib/extension-guard-check.php` (the family gate; Jetonomy keeps a byte-identical copy, so change both together, never one alone). Each item is a case the gate passes today but should not.

## Guards it accepts too easily

- A guard inside a nested `if` block that closed before the use, in the same function and within 12 lines: `if ( $a ) { function_exists( 'mb_strtolower' ); } mb_strtolower( $s );`.

## Uses it cannot see

- Group-use aliases: `use function X\{ mb_strtolower as lower };`.
- Calls through a variable, a string or a callable: `$f( $s )`, `call_user_func( 'mb_strtolower', $s )`, `array_map( 'mb_strtolower', $l )`.
- `new $cls` where `$cls` holds an extension class name.
- A class that extends an extension class (`class X extends DOMDocument`), including anonymous classes (`new class extends ZipArchive {}`).

## Names missing from its lists

- DOM classes: `DOMException`, `DOMText`, `DOMAttr`, `DOMImplementation`, and the PHP 8.4 `Dom\HTMLDocument` (with the rest of the `Dom\` namespace).
- `xmlwriter_*` functions (the procedural xmlwriter API).

Each fix adds a self-test case that fails on the current checker.
