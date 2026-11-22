#!/usr/bin/env bash
# Uninstall smoke: run uninstall.php the way WordPress does, with the plugin INACTIVE, remove-data OFF and then ON,
# and reinstall after each, on a real WordPress.
#
# The PHPUnit suite cannot test this: it loads the plugin, so the autoloader and every class are always there.
# Deleting Free fataled for four releases for exactly that reason (uninstall.php ran without the autoloader).
#
#   OFF  every Free table and a setting survive, an album survives, no PHP error; reactivation works.
#   ON   Free's uninstall, then Pro's (when Pro is installed): no mvs_ table, mvs_ option (bar the opt-in, which
#        stays while the other plugin is installed), mvs_ user meta or album is left, no PHP error.
#   Reinstall after ON: activation recreates every Migrator::tables() table and the mvs/v1 REST index answers.
#
# DESTRUCTIVE: the ON pass deletes every MediaVerse row on the site. Run it only on a throwaway site.
#
# Environment:
#   MVS_UNINSTALL_WP  Command that runs WP-CLI on a THROWAWAY site, e.g. "./wp.sh". Required, and deliberately not
#                     MVS_WP (the boot matrix runs on a dev site whose data must survive).
#   MVS_FREE_SLUG / MVS_PRO_SLUG  Plugin folders (default wpmediaverse / wpmediaverse-pro).
#
# Exit: 0 clean, 1 a failure, 2 usage or setup problem. Leaves both plugins active on a freshly installed MediaVerse.

set -u

FREE_SLUG="${MVS_FREE_SLUG:-wpmediaverse}"
PRO_SLUG="${MVS_PRO_SLUG:-wpmediaverse-pro}"
WP="${MVS_UNINSTALL_WP:-}"
if [ -z "$WP" ]; then
	echo "uninstall-smoke: set MVS_UNINSTALL_WP to the WP-CLI command of a THROWAWAY site (the ON pass wipes MediaVerse data)" >&2
	exit 2
fi

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; RESET=$'\033[0m'
FAILS=0

run_php() { printf '%s' "$1" | $WP eval-file - 2>&1; }
ok()   { echo "  ${GREEN}ok${RESET}   $1"; }
bad()  { echo "  ${RED}FAIL${RESET} $1"; FAILS=$((FAILS + 1)); }

# A check prints lines "OK <text>" or "FAIL <text>"; anything else in its output (a PHP error) is a failure too.
report() {
	local out="$1" line
	while IFS= read -r line; do
		case "$line" in
			'') ;;
			OK\ *)   ok "${line#OK }" ;;
			FAIL\ *) bad "${line#FAIL }" ;;
			*)       bad "unexpected output: $(printf '%s' "$line" | cut -c1-220)" ;;
		esac
	done <<EOF_REPORT
$out
EOF_REPORT
}

PLUGINS="$(run_php '<?php
foreach ( array_keys( get_plugins() ) as $f ) {
	if ( 0 === strpos( $f, "'"$FREE_SLUG"'/" ) ) { echo "FREE=$f\n"; }
	if ( 0 === strpos( $f, "'"$PRO_SLUG"'/" ) ) { echo "PRO=$f\n"; }
}')"
FREE_FILE="$(printf '%s\n' "$PLUGINS" | sed -n 's/^FREE=//p')"
PRO_FILE="$(printf '%s\n' "$PLUGINS" | sed -n 's/^PRO=//p')"
if [ -z "$FREE_FILE" ]; then
	echo "uninstall-smoke: $FREE_SLUG is not installed on the site" >&2
	exit 2
fi

activate()   { $WP plugin activate "$FREE_FILE" ${PRO_FILE:+"$PRO_FILE"} >/dev/null 2>&1; }
deactivate() { $WP plugin deactivate ${PRO_FILE:+"$PRO_FILE"} "$FREE_FILE" >/dev/null 2>&1; }

# Include a plugin's uninstall.php as WordPress does: plugin inactive, WP_UNINSTALL_PLUGIN defined. Any PHP notice,
# warning or exception is printed as a FAIL line instead of passing silently.
uninstall() { # plugin-file
	run_php '<?php
set_error_handler( static function ( $no, $str, $file, $line ) { echo "FAIL PHP error in uninstall: $str at $file:$line\n"; return true; } );
if ( is_plugin_active( "'"$1"'" ) ) { echo "FAIL '"$1"' is still active; the smoke must run uninstall on an inactive plugin\n"; return; }
define( "WP_UNINSTALL_PLUGIN", "'"$1"'" );
try {
	include WP_PLUGIN_DIR . "/" . dirname( "'"$1"'" ) . "/uninstall.php";
	echo "OK uninstall.php of '"$1"' ran to the end\n";
} catch ( \Throwable $e ) {
	echo "FAIL uninstall.php of '"$1"' threw " . get_class( $e ) . ": " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine() . "\n";
}'
}

# Free's table list, read from its own Migrator file (the plugin is not loaded while it is inactive).
TABLES_PHP='require_once WP_PLUGIN_DIR . "/'"$FREE_SLUG"'/includes/Core/Migrator.php"; global $wpdb; $tables = \WPMediaVerse\Core\Migrator::tables(); $have = (array) $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "mvs_" ) . "%" ) ); $missing = array_diff( array_map( static fn( $t ) => $wpdb->prefix . $t, $tables ), $have );'

echo "Uninstall smoke: free=$FREE_FILE pro=${PRO_FILE:-none}"

# --- seed --------------------------------------------------------------------------------------------------------
activate
SEED="$(run_php '<?php
update_option( "mvs_grid_columns", 4 );
delete_option( "mvs_delete_data_on_uninstall" );
$a = wp_insert_post( array( "post_type" => "mvs_album", "post_status" => "publish", "post_title" => "ZZ uninstall smoke" ) );
update_user_meta( 1, "mvs_uninstall_smoke", 1 );
echo "OK seeded album $a, a setting and a member meta\n";')"
report "$SEED"
ALBUM="$(printf '%s' "$SEED" | sed -n 's/.*album \([0-9]*\).*/\1/p')"

# --- remove data OFF ---------------------------------------------------------------------------------------------
echo "== remove data OFF"
deactivate
[ -n "$PRO_FILE" ] && report "$(uninstall "$PRO_FILE")"
report "$(uninstall "$FREE_FILE")"
report "$(run_php '<?php '"$TABLES_PHP"'
echo $missing ? "FAIL data OFF dropped tables: " . implode( ", ", $missing ) . "\n" : "OK data OFF kept all " . count( $tables ) . " Free tables\n";
echo 4 === (int) get_option( "mvs_grid_columns" ) ? "OK data OFF kept the settings\n" : "FAIL data OFF deleted the settings\n";
echo get_post( '"${ALBUM:-0}"' ) ? "OK data OFF kept the album\n" : "FAIL data OFF deleted the album\n";')"
activate
report "$(run_php '<?php echo is_plugin_active( "'"$FREE_FILE"'" ) ? "OK reactivated after a data OFF uninstall\n" : "FAIL could not reactivate after a data OFF uninstall\n";')"

# --- remove data ON ----------------------------------------------------------------------------------------------
echo "== remove data ON"
run_php '<?php update_option( "mvs_delete_data_on_uninstall", "1" );' >/dev/null
deactivate
report "$(uninstall "$FREE_FILE")"
[ -n "$PRO_FILE" ] && report "$(uninstall "$PRO_FILE")"
report "$(run_php '<?php global $wpdb;
$left = (array) $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $wpdb->prefix . "mvs_" ) . "%" ) );
echo $left ? "FAIL data ON left tables: " . implode( ", ", $left ) . "\n" : "OK data ON dropped every mvs_ table (Free and Pro)\n";
$opts = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name <> %s", $wpdb->esc_like( "mvs_" ) . "%", "mvs_delete_data_on_uninstall" ) );
echo $opts ? "FAIL data ON left options: " . implode( ", ", array_slice( $opts, 0, 8 ) ) . "\n" : "OK data ON removed every mvs_ option\n";
echo "1" === (string) get_option( "mvs_delete_data_on_uninstall" ) ? "OK the opt-in stays while the other plugin is installed\n" : "FAIL the opt-in was removed while the other plugin is still installed\n";
$meta = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( "mvs_" ) . "%" ) );
echo $meta ? "FAIL data ON left $meta mvs_ user meta rows\n" : "OK data ON removed the member meta\n";
$albums = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( \"mvs_album\", \"mvs_collection\" )" );
echo $albums ? "FAIL data ON left $albums albums/collections\n" : "OK data ON removed albums and collections\n";')"

# --- reinstall ---------------------------------------------------------------------------------------------------
echo "== reinstall"
run_php '<?php delete_option( "mvs_delete_data_on_uninstall" );' >/dev/null
activate
report "$(run_php '<?php '"$TABLES_PHP"'
echo $missing ? "FAIL reinstall did not recreate: " . implode( ", ", $missing ) . "\n" : "OK reinstall recreated all " . count( $tables ) . " Free tables\n";
$r = rest_do_request( new WP_REST_Request( "GET", "/mvs/v1" ) );
echo 200 === $r->get_status() ? "OK REST mvs/v1 answers after reinstall\n" : "FAIL REST mvs/v1 answered " . $r->get_status() . "\n";')"

echo
if [ "$FAILS" -gt 0 ]; then
	echo "${RED}Uninstall smoke FAILED: $FAILS problem(s).${RESET}"
	exit 1
fi
echo "${GREEN}Uninstall smoke passed: data OFF keeps, data ON removes, reinstall is clean.${RESET}"
exit 0
