#!/usr/bin/env bash
# Boot matrix smoke: MediaVerse Free only, Pro only, and both, on a REAL WordPress.
#
# Thousands of sites run Free alone, or update one plugin before the other. A Pro
# hook or block left behind must never white-screen a Free-only site, and Pro must
# say what is missing when Free is absent. tools/smoke-test.php boots each plugin in
# a stub; this boots them in WordPress and looks at what a visitor and an admin get.
#
# For each of the three modes it:
#   1. sets the active plugins,
#   2. publishes one probe page holding EVERY MediaVerse block (Free and Pro) and
#      every mvs_* shortcode, so a block whose plugin is absent is exercised too,
#   3. requests the probe page, the home page, the REST indexes and the MediaVerse
#      wp-admin screens,
#   4. FAILS on a 5xx, a fatal, an uncaught error, a PHP warning, notice or
#      deprecation in the output, or any new PHP error line in debug.log.
#
# It restores the original active plugins when it finishes, however it ends.
#
# Environment (one of the first two is required):
#   MVS_WP          Command that runs WP-CLI on the test site, e.g. "./wp.sh" or
#                   "wp --path=/path/to/site". Needs to read PHP from stdin
#                   (wp eval-file -).
#   MVS_WP_PATH     Shorthand for MVS_WP="wp --path=$MVS_WP_PATH".
#   MVS_SITE_URL    Site URL (default: the siteurl option).
#   MVS_LOGIN_PARAM Query string that logs an admin in on a dev site, e.g.
#                   "autologin=1". Without it the wp-admin checks are skipped and
#                   reported as skipped, never as passed.
#   MVS_FREE_SLUG / MVS_PRO_SLUG  Plugin folders (default wpmediaverse / wpmediaverse-pro).
#
# Usage:   bash bin/boot-matrix-smoke.sh
# Exit:    0 all modes clean, 1 a failure, 2 usage or setup problem.
#
# Needs WP_DEBUG on with WP_DEBUG_LOG: it refuses to run otherwise, since a smoke
# that cannot see a warning proves nothing. With WP_DEBUG_DISPLAY on as well, a
# warning is also caught in the page output; with it off, only debug.log is
# checked and the script says so.

set -u

FREE_SLUG="${MVS_FREE_SLUG:-wpmediaverse}"
PRO_SLUG="${MVS_PRO_SLUG:-wpmediaverse-pro}"
WP="${MVS_WP:-}"
if [ -z "$WP" ]; then
	if [ -n "${MVS_WP_PATH:-}" ]; then
		WP="wp --path=$MVS_WP_PATH"
	else
		echo "boot-matrix-smoke: set MVS_WP (e.g. ./wp.sh) or MVS_WP_PATH" >&2
		exit 2
	fi
fi
LOGIN="${MVS_LOGIN_PARAM:-}"

RED=$'\033[0;31m'; GREEN=$'\033[0;32m'; YELLOW=$'\033[0;33m'; DIM=$'\033[2m'; RESET=$'\033[0m'
FAILS=0
SKIPS=0

# Run PHP on the site through WP-CLI. The code is read from stdin.
run_php() { printf '%s' "$1" | $WP eval-file - 2>&1; }

SITE="${MVS_SITE_URL:-$($WP option get siteurl 2>/dev/null | tail -1)}"
if [ -z "$SITE" ]; then
	echo "boot-matrix-smoke: cannot read the site URL (MVS_SITE_URL)" >&2
	exit 2
fi
SITE="${SITE%/}"

TMP="$(mktemp -d)"
JAR="$TMP/cookies.txt"
BODY="$TMP/body.html"

ORIG_ACTIVE="$($WP --skip-plugins --skip-themes option get active_plugins --format=json 2>/dev/null | tail -1)"
restore() {
	if [ -n "${ORIG_ACTIVE:-}" ]; then
		$WP --skip-plugins --skip-themes option update active_plugins "$ORIG_ACTIVE" --format=json >/dev/null 2>&1
	fi
	rm -rf "$TMP"
}
trap restore EXIT

# --- preconditions --------------------------------------------------------------
PRE="$(run_php '<?php
$ok = defined("WP_DEBUG") && WP_DEBUG && defined("WP_DEBUG_LOG") && WP_DEBUG_LOG;
$display = ! defined("WP_DEBUG_DISPLAY") || WP_DEBUG_DISPLAY;
$free = array_filter( array_keys( get_plugins() ), static fn( $f ) => 0 === strpos( $f, "'"$FREE_SLUG"'/" ) );
$pro  = array_filter( array_keys( get_plugins() ), static fn( $f ) => 0 === strpos( $f, "'"$PRO_SLUG"'/" ) );
echo json_encode( array( "debug" => $ok, "display" => $display, "free" => array_values( $free )[0] ?? "", "pro" => array_values( $pro )[0] ?? "" ) );
' | tail -1)"
FREE_FILE="$(printf '%s' "$PRE" | sed -n 's/.*"free":"\([^"]*\)".*/\1/p' | sed 's#\\/#/#g')"
PRO_FILE="$(printf '%s' "$PRE" | sed -n 's/.*"pro":"\([^"]*\)".*/\1/p' | sed 's#\\/#/#g')"
if ! printf '%s' "$PRE" | grep -q '"debug":true'; then
	echo "boot-matrix-smoke: WP_DEBUG and WP_DEBUG_LOG must be on for this site; a smoke that cannot see a warning proves nothing." >&2
	exit 2
fi
DISPLAY_ON=1
if ! printf '%s' "$PRE" | grep -q '"display":true'; then
	DISPLAY_ON=0
	echo "${YELLOW}note${RESET}: WP_DEBUG_DISPLAY is off on this site, so a warning does not reach the page; only debug.log is checked. Turn display on for the full check."
fi
if [ -z "$FREE_FILE" ] || [ -z "$PRO_FILE" ]; then
	echo "boot-matrix-smoke: both plugins must be installed on the test site (free='$FREE_FILE' pro='$PRO_FILE')." >&2
	exit 2
fi

set_mode() { # free | pro | both
	local list
	case "$1" in
		free) list="[\"$FREE_FILE\"]" ;;
		pro)  list="[\"$PRO_FILE\"]" ;;
		both) list="[\"$FREE_FILE\",\"$PRO_FILE\"]" ;;
	esac
	# --skip-plugins: switching modes must still work while the plugin being tested fatals.
	$WP --skip-plugins --skip-themes option update active_plugins "$list" --format=json >/dev/null 2>&1
}

# --- discover what to request (both plugins active) ------------------------------
set_mode both
DISCOVERY="$(run_php '<?php
global $shortcode_tags;
$blocks = array_values( array_filter( array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() ), static fn( $n ) => 0 === strpos( $n, "mvs/" ) ) );
$codes  = array_values( array_filter( array_keys( $shortcode_tags ), static fn( $n ) => 0 === strpos( $n, "mvs_" ) ) );
$pro_blocks = array_values( array_filter( $blocks, static fn( $n ) => 0 === strpos( $n, "mvs/pro-" ) ) );
$pro_codes  = array_values( array_filter( $codes, static fn( $n ) => 0 === strpos( $n, "mvs_pro_" ) ) );
echo json_encode( array( "blocks" => $blocks, "codes" => $codes, "pro_blocks" => $pro_blocks, "pro_codes" => $pro_codes ) );
' | tail -1)"
N_BLOCKS="$(printf '%s' "$DISCOVERY" | sed -n 's/.*"blocks":\[\([^]]*\)\],"codes".*/\1/p' | tr ',' '\n' | grep -c .)"
if [ "${N_BLOCKS:-0}" -lt 5 ]; then
	echo "boot-matrix-smoke: found only $N_BLOCKS mvs/ blocks with both plugins active; the site is not set up (discovery: $DISCOVERY)" >&2
	exit 2
fi

# The probe page: every block and shortcode in one post, so one request exercises all.
PAGE_ID="$(run_php '<?php
$d = json_decode( '"'"''"$DISCOVERY"''"'"', true );
$c = "";
foreach ( $d["blocks"] as $b ) { $c .= "<!-- wp:" . $b . " /-->\n\n"; }
foreach ( $d["codes"] as $s ) { $c .= "[" . $s . "]\n\n"; }
$old = get_page_by_path( "zz-boot-matrix-smoke" );
if ( $old ) { wp_delete_post( $old->ID, true ); }
echo wp_insert_post( array( "post_type" => "page", "post_status" => "publish", "post_title" => "ZZ boot matrix smoke", "post_name" => "zz-boot-matrix-smoke", "post_content" => $c ) );
' | tail -1)"
case "$PAGE_ID" in ''|*[!0-9]*) echo "boot-matrix-smoke: could not create the probe page: $PAGE_ID" >&2; exit 2 ;; esac
trap 'run_php "<?php wp_delete_post( ${PAGE_ID}, true );" >/dev/null 2>&1; restore' EXIT

echo "Boot matrix smoke on $SITE  (probe page $PAGE_ID: $N_BLOCKS blocks, $(printf '%s' "$DISCOVERY" | sed -n 's/.*"codes":\[\([^]]*\)\].*/\1/p' | tr ',' '\n' | grep -c .) shortcodes)"

# --- request + inspect -----------------------------------------------------------
BAD_OUTPUT='(Fatal error|Parse error|Warning|Notice|Deprecated|Strict Standards)(</b>)?:[^<]{0,200} on line (<b>)?[0-9]+|Uncaught |Stack trace:'

check() { # mode label url [expect-text]
	local mode="$1" label="$2" url="$3" want="${4:-}" code hit
	code="$(curl -sS -L -m 90 -b "$JAR" -c "$JAR" -o "$BODY" -w '%{http_code}' "$url" 2>"$TMP/curl.err")"
	if [ "$code" = "000" ] || [ "${code:0:1}" = "5" ]; then
		echo "  ${RED}FAIL${RESET} [$mode] $label  HTTP $code  ($(head -c 160 "$TMP/curl.err" | tr '\n' ' '))"
		FAILS=$((FAILS + 1)); return
	fi
	hit="$(grep -E -m2 -o "$BAD_OUTPUT" "$BODY" 2>"$TMP/grep.err" | head -2 | tr '\n' ' ')"
	if [ -s "$TMP/grep.err" ]; then
		# A scanner that errors would pass everything: treat it as a failure of the smoke itself.
		echo "  ${RED}FAIL${RESET} [$mode] $label  the output scanner failed: $(head -c 120 "$TMP/grep.err")"
		FAILS=$((FAILS + 1)); return
	fi
	if [ -n "$hit" ]; then
		echo "  ${RED}FAIL${RESET} [$mode] $label  HTTP $code  PHP output: $hit"
		FAILS=$((FAILS + 1)); return
	fi
	if [ -n "$want" ] && ! grep -q -F "$want" "$BODY"; then
		echo "  ${RED}FAIL${RESET} [$mode] $label  HTTP $code  expected text missing: $want"
		FAILS=$((FAILS + 1)); return
	fi
	echo "  ${GREEN}ok${RESET}   [$mode] $label  ${DIM}HTTP $code${RESET}"
}

for MODE in free pro both; do
	echo "== mode: $MODE"
	set_mode "$MODE"
	: > "$JAR"
	LOG_OFFSET="$(run_php '<?php $f = WP_CONTENT_DIR . "/debug.log"; echo file_exists( $f ) ? filesize( $f ) : 0;' | tail -1)"

	check "$MODE" "home page"        "$SITE/"
	check "$MODE" "probe page (all MediaVerse blocks and shortcodes)" "$SITE/?page_id=$PAGE_ID"
	check "$MODE" "REST index"       "$SITE/wp-json/"
	check "$MODE" "REST index mvs/v1"     "$SITE/wp-json/mvs/v1"
	check "$MODE" "REST index mvs-pro/v1" "$SITE/wp-json/mvs-pro/v1"

	if [ -n "$LOGIN" ]; then
		check "$MODE" "wp-admin"                 "$SITE/wp-admin/?$LOGIN"
		# The MediaVerse screens THIS mode actually shows in the admin menu: each plugin's own
		# screens are asked for only while it is active (core answers a CPT screen of an absent
		# plugin with its plain "Invalid post type" page, which is not a fault of ours).
		SCREENS="$(grep -o "href=['\"][^'\"]*['\"]" "$BODY" | sed "s/^href=['\"]//; s/['\"]$//; s/&#038;/\&/g; s/&amp;/\&/g" \
			| grep -E 'page=(mvs|wpmediaverse)|post_type=mvs_' | sed 's#^.*/wp-admin/##' | grep -v '^http' | sort -u | head -30)"
		if [ "$MODE" = "pro" ]; then
			check "$MODE" "plugins screen (Pro names the missing Free)" "$SITE/wp-admin/plugins.php" "requires the free MediaVerse plugin"
		else
			check "$MODE" "plugins screen"       "$SITE/wp-admin/plugins.php"
		fi
		N_SCREENS=0
		while IFS= read -r SCREEN; do
			[ -z "$SCREEN" ] && continue
			N_SCREENS=$((N_SCREENS + 1))
			check "$MODE" "wp-admin $SCREEN" "$SITE/wp-admin/$SCREEN"
		done <<EOF_SCREENS
$SCREENS
EOF_SCREENS
		if [ "$MODE" != "pro" ] && [ "$N_SCREENS" -eq 0 ]; then
			echo "  ${RED}FAIL${RESET} [$MODE] no MediaVerse screen found in the admin menu (login not working?)"
			FAILS=$((FAILS + 1))
		fi
	else
		echo "  ${YELLOW}skip${RESET} [$MODE] wp-admin screens (set MVS_LOGIN_PARAM to a dev auto-login query, e.g. autologin=1)"
		SKIPS=$((SKIPS + 1))
	fi

	# Anything PHP logged while this mode ran.
	NEWLOG="$(run_php '<?php $f = WP_CONTENT_DIR . "/debug.log"; echo file_exists( $f ) ? (string) substr( file_get_contents( $f ), (int) '"${LOG_OFFSET:-0}"' ) : "";')"
	LOGHIT="$(printf '%s\n' "$NEWLOG" | grep -E -m3 'PHP (Fatal error|Parse error|Warning|Notice|Deprecated)|Uncaught ' | cut -c1-240)"
	if [ -n "$LOGHIT" ]; then
		echo "  ${RED}FAIL${RESET} [$MODE] debug.log has new PHP errors:"
		printf '%s\n' "$LOGHIT" | sed 's/^/         /'
		FAILS=$((FAILS + 1))
	else
		echo "  ${GREEN}ok${RESET}   [$MODE] debug.log: no new PHP error lines"
	fi

	if [ "$MODE" = "free" ]; then
		PRO_B="$(printf '%s' "$DISCOVERY" | sed -n 's/.*"pro_blocks":\[\([^]]*\)\].*/\1/p' | tr ',' '\n' | grep -c .)"
		PRO_S="$(printf '%s' "$DISCOVERY" | sed -n 's/.*"pro_codes":\[\([^]]*\)\].*/\1/p' | tr ',' '\n' | grep -c .)"
		GUEST="$(curl -sS -L -m 60 "$SITE/?page_id=$PAGE_ID")"
		LEFT="$(printf '%s' "$GUEST" | grep -c 'wp-block-mvs-pro-')"
		RAW="$(printf '%s' "$GUEST" | grep -E -o '\[(mvs_pro_[a-z_]+|mvs_document)\]' | sort -u | head -5 | tr '\n' ' ')"
		if [ -n "$RAW" ]; then
			echo "  ${RED}FAIL${RESET} [free] a visitor sees raw Pro shortcodes on the probe page: $RAW"
			FAILS=$((FAILS + 1))
		else
			echo "  ${GREEN}ok${RESET}   [free] a visitor sees no raw Pro shortcode ($PRO_S Pro shortcodes, $PRO_B Pro blocks; $LEFT Pro block wrappers)"
		fi
		if [ -n "$LOGIN" ] && [ "${PRO_S:-0}" -gt 0 ]; then
			check "$MODE" "probe page as admin (Pro shortcodes say they need Pro)" "$SITE/?page_id=$PAGE_ID" "This block needs MediaVerse Pro."
		fi
	fi
done

echo
if [ "$FAILS" -gt 0 ]; then
	echo "${RED}Boot matrix smoke FAILED: $FAILS problem(s).${RESET}"
	exit 1
fi
if [ "$SKIPS" -gt 0 ]; then
	echo "${YELLOW}Boot matrix smoke passed, with $SKIPS wp-admin check(s) skipped.${RESET}"
else
	echo "${GREEN}Boot matrix smoke passed: Free only, Pro only and both are clean.${RESET}"
fi
exit 0
