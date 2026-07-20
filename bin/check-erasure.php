#!/usr/bin/env php
<?php
/**
 * Erasure coverage gate.
 *
 * Data Lifecycle Standard §9a: every user-keyed table must appear on exactly one
 * of two lists — ERASE or RETAIN. This script parses the schema out of the
 * Migrator and the two lists out of MemberDataMap (plus Pro's contribution), and
 * FAILS THE BUILD on any user-keyed table that is on neither.
 *
 * The rule already existed in prose. It did not hold: nine member-bearing tables
 * sat in no exporter and no eraser — the usage ledger, the error log, and every
 * Pro table including push devices. Nobody disagreed with the rule. A table was
 * added, and nothing forced the question.
 *
 * So it is mechanical now. Deciding to RETAIN a table is a fine answer; never
 * deciding is not. What this gate rejects is silence — because silence is
 * indistinguishable from an oversight, and on a compliance path telling a
 * deliberate retention apart from a bug is the entire game.
 *
 * Usage:  php bin/check-erasure.php
 * Exit:   0 = every user-keyed table is classified. 1 = at least one is not.
 *
 * @package WPMediaVerse
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

$root = dirname( __DIR__ );

/**
 * Columns that mean "this row belongs to, or is about, a person".
 */
$user_columns = array(
	'user_id',
	'post_author',
	'actor_id',
	'target_id',
	'sender_id',
	'recipient_id',
	'follower_id',
	'following_id',
	'blocker_id',
	'blocked_id',
	'reporter_id',
	'mentioned_user_id',
	'creator_id',
	'created_by',
	'winner_id',
	'owner_id',
	'voter_id',
	'granted_by',
);

/**
 * Find every CREATE TABLE and the user-bearing columns in it.
 *
 * @param string $file Schema source file.
 * @return array<string, string[]> table => user columns
 */
function mvs_parse_schema( string $file, array $user_columns ): array {
	if ( ! is_readable( $file ) ) {
		return array();
	}

	$sql    = (string) file_get_contents( $file );
	$tables = array();

	// Each `{$wpdb->prefix}mvs_foo (` opens a table body; take everything up to
	// the next CREATE TABLE (or EOF) as its column list.
	if ( ! preg_match_all( '/CREATE TABLE[^`\'"]*?(mvs_[a-z0-9_]+)\s*\((.*?)(?=CREATE TABLE|\z)/is', $sql, $m, PREG_SET_ORDER ) ) {
		return array();
	}

	foreach ( $m as $match ) {
		$table = $match[1];
		$body  = $match[2];

		$found = array();
		foreach ( $user_columns as $col ) {
			if ( preg_match( '/(^|\s|`)' . preg_quote( $col, '/' ) . '(`|\s)/i', $body ) ) {
				$found[] = $col;
			}
		}

		if ( ! empty( $found ) ) {
			$tables[ $table ] = array_values( array_unique( $found ) );
		}
	}

	return $tables;
}

/**
 * Table names classified in a privacy map file.
 *
 * @param string $file Map source.
 * @return string[]
 */
function mvs_parse_map( string $file ): array {
	if ( ! is_readable( $file ) ) {
		return array();
	}

	$src = (string) file_get_contents( $file );

	// Two forms, because Free declares the map as a literal and Pro appends to it
	// through the filter:
	//   'mvs_foo' => array( ... )        (Free)
	//   $map['mvs_foo'] = array( ... )   (Pro)
	// Matching only the first is how this gate produced a false positive on every
	// Pro table it was written to catch — and a gate that cries wolf gets ignored,
	// which is worse than no gate.
	preg_match_all( "/'(mvs_[a-z0-9_]+)'\s*(?:=>|\]\s*=)\s*array\s*\(/i", $src, $m );

	return array_values( array_unique( $m[1] ?? array() ) );
}

// The schema lives in the Migrator (Free) and Pro's own migrator, if present.
$schema = mvs_parse_schema( $root . '/includes/Core/Migrator.php', $user_columns );

$pro_root = dirname( $root ) . '/wpmediaverse-pro';
foreach ( glob( $pro_root . '/includes/**/*Migrator*.php' ) ?: array() as $pro_file ) {
	$schema += mvs_parse_schema( $pro_file, $user_columns );
}

// The two lists.
$classified = array_merge(
	mvs_parse_map( $root . '/includes/Privacy/MemberDataMap.php' ),
	mvs_parse_map( $pro_root . '/includes/Privacy/ProMemberData.php' )
);

$green = "\033[0;32m";
$red   = "\033[0;31m";
$reset = "\033[0m";

if ( empty( $schema ) ) {
	echo "check-erasure: could not parse any schema — refusing to pass vacuously.\n";
	exit( 1 );
}

$missing = array();

foreach ( $schema as $table => $cols ) {
	if ( ! in_array( $table, $classified, true ) ) {
		$missing[ $table ] = $cols;
	}
}

if ( ! empty( $missing ) ) {
	foreach ( $missing as $table => $cols ) {
		printf(
			"%s✗ check-erasure: `%s` holds a person (%s) but is on NEITHER the ERASE nor the RETAIN list.%s\n",
			$red,
			$table,
			implode( ', ', $cols ),
			$reset
		);
	}

	echo "\n";
	echo "Add it to WPMediaVerse\\Privacy\\MemberDataMap (or, for a Pro table,\n";
	echo "WPMediaVersePro\\Privacy\\ProMemberData). Choosing to RETAIN it is a fine\n";
	echo "answer — but say so, with the reason and the legal basis. A table that is\n";
	echo "invisible to an erasure request is a compliance defect, not a backlog item.\n";

	exit( 1 );
}

printf(
	"%s✓ check-erasure: all %d user-keyed table(s) are classified (ERASE or RETAIN).%s\n",
	$green,
	count( $schema ),
	$reset
);

exit( 0 );
