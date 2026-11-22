<?php
/**
 * String helpers that work without the optional mbstring and ctype extensions.
 *
 * WordPress polyfills only mb_strlen(), mb_substr() and mb_str_split(). A host without mbstring or ctype is rare
 * but real, and calling any other mb_* or ctype_* function there is a fatal on a member's request (a search, an
 * upload, a media page). Code that needs case folding or a digits check goes through here.
 *
 * @package WPMediaVerse
 * @since   2.6.5
 */

namespace WPMediaVerse\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Extension-safe string helpers.
 */
final class Text {

	/**
	 * Lower-case a UTF-8 string (ASCII-only folding without mbstring).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
	}

	/**
	 * Upper-case a UTF-8 string (ASCII-only folding without mbstring).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function upper( string $text ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $text, 'UTF-8' ) : strtoupper( $text );
	}

	/**
	 * Whether a string is one or more ASCII digits, as ctype_digit() answers for a string.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function is_digits( string $text ): bool {
		return 1 === preg_match( '/^[0-9]+$/D', $text );
	}
}
