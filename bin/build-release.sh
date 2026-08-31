#!/usr/bin/env bash
#
# build-release.sh — produce combo release zips for WPMediaVerse + WPMediaVerse Pro.
#
# Free and Pro ship as a paired release every time. This script enforces
# matching versions across both, boots both in a minimal WP stub, demands a
# fresh agent-walked smoke report, and only then produces both zips. No
# release ever leaves this script with a broken Free or a Pro built against
# a Free version it can't satisfy.
#
# Usage:
#   bin/build-release.sh                  # build current HEAD of both plugins
#   bin/build-release.sh --allow-dirty    # skip the clean-tree check (dev)
#   bin/build-release.sh --output ~/Desktop  # also copy zips to DIR
#   bin/build-release.sh --skip-browser-smoke  # emergency only — logs a warning
#   bin/build-release.sh --free-only      # only stage + zip Free (still version-pairs with Pro)
#
# Exit codes:
#   0   success — both zips at dist/ are ready to ship
#   2   bad invocation
#   10  one of the trees is dirty and --allow-dirty not set
#   11  version mismatch within a plugin or across Free <-> Pro
#   12  Pro plugin not found at ../wpmediaverse-pro
#   20  composer install in a staged tree failed
#   21  grunt build / npm install failed
#   30  boot smoke or browser smoke gate failed
#   40  required file missing from a staged tree, or cruft leaked in
#
# Run from anywhere — the script resolves itself to the Free plugin root.
#
# Stay thorough. Shipping a broken zip is a worse problem than a slow build.

set -euo pipefail

FREE_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRO_ROOT="$(cd "$FREE_ROOT/../wpmediaverse-pro" 2>/dev/null && pwd || echo "")"
FREE_SLUG="wpmediaverse"
PRO_SLUG="wpmediaverse-pro"
FREE_MAIN="${FREE_SLUG}.php"
PRO_MAIN="${PRO_SLUG}.php"

ALLOW_DIRTY=0
OUTPUT_DIR=""
SKIP_BROWSER_SMOKE=0
FREE_ONLY=0
while [ $# -gt 0 ]; do
	case "$1" in
		--allow-dirty)         ALLOW_DIRTY=1; shift ;;
		--output)              OUTPUT_DIR="$2"; shift 2 ;;
		--skip-browser-smoke)  SKIP_BROWSER_SMOKE=1; shift ;;
		--free-only)           FREE_ONLY=1; shift ;;
		*) echo "unknown flag: $1" >&2; exit 2 ;;
	esac
done

GREEN=$(printf '\033[0;32m')
RED=$(printf '\033[0;31m')
YELLOW=$(printf '\033[0;33m')
BOLD=$(printf '\033[1m')
RESET=$(printf '\033[0m')
ok()    { printf '%s==>%s %s\n' "$GREEN" "$RESET" "$1"; }
fail()  { printf '%s==>%s %s\n' "$RED"   "$RESET" "$1" >&2; }
warn()  { printf '%s==>%s %s\n' "$YELLOW" "$RESET" "$1"; }
step()  { printf '\n%s== %s ==%s\n' "$BOLD" "$1" "$RESET"; }

step "build-release.sh — combo (Free + Pro)"

if [ "$FREE_ONLY" -eq 0 ] && [ -z "$PRO_ROOT" ]; then
	fail "Pro plugin not found at \$FREE/../wpmediaverse-pro. Either clone Pro alongside Free or pass --free-only."
	exit 12
fi

# Helper: read a Version: header / Stable tag / package.json / define() value
# in a single grep, return only the X.Y.Z. Used everywhere we triangulate
# versions; centralising it keeps the failure messages consistent.
read_header_version() {
	grep -E '^\s*\*\s*Version:' "$1" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true
}
read_define_version() {
	grep -E "define\(\s*'$2'" "$1" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true
}
read_readme_version() {
	grep -E '^Stable tag:' "$1" | head -1 | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true
}
read_package_version() {
	grep -m1 '"version"' "$1" 2>/dev/null | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true
}

# --- 1. clean-tree gate (Free + Pro) --------------------------------------
step "1. clean-tree gate"
check_clean_tree() {
	local label="$1" root="$2"
	if [ "$ALLOW_DIRTY" -eq 1 ]; then
		warn "$label: clean-tree check skipped (--allow-dirty)"
		return 0
	fi
	local dirty
	dirty="$(git -C "$root" status --porcelain \
		':(exclude)assets/css/*.min.css' \
		':(exclude)assets/css/*-rtl.css' \
		':(exclude)assets/css/*-rtl.min.css' \
		':(exclude)assets/js/*.min.js' \
		':(exclude)languages/*.pot' \
		':(exclude)dist/' \
		':(exclude)build/' || true)"
	if [ -n "$dirty" ]; then
		fail "$label: working tree has uncommitted changes (excluding build artefacts)."
		fail "$dirty"
		exit 10
	fi
	ok "$label: clean"
}
check_clean_tree "Free" "$FREE_ROOT"
[ "$FREE_ONLY" -eq 0 ] && check_clean_tree "Pro" "$PRO_ROOT"

FREE_HEAD="$(git -C "$FREE_ROOT" rev-parse --short HEAD)"
PRO_HEAD="(skipped)"
[ "$FREE_ONLY" -eq 0 ] && PRO_HEAD="$(git -C "$PRO_ROOT" rev-parse --short HEAD)"
ok "Free HEAD: $FREE_HEAD    Pro HEAD: $PRO_HEAD"

# --- 2. version coherence -------------------------------------------------
step "2. version coherence (Free + Pro must match)"

FREE_HEADER_V="$(read_header_version "$FREE_ROOT/$FREE_MAIN")"
FREE_CONST_V="$(read_define_version  "$FREE_ROOT/$FREE_MAIN" 'MVS_VERSION')"
FREE_README_V="$(read_readme_version "$FREE_ROOT/readme.txt")"
FREE_PKG_V="$(read_package_version  "$FREE_ROOT/package.json")"

if [ -z "$FREE_HEADER_V" ] || [ -z "$FREE_CONST_V" ] || [ -z "$FREE_README_V" ]; then
	fail "Free: missing one of header/const/readme version"
	fail "  header=$FREE_HEADER_V const=$FREE_CONST_V readme=$FREE_README_V"
	exit 11
fi
if [ "$FREE_HEADER_V" != "$FREE_CONST_V" ] || [ "$FREE_HEADER_V" != "$FREE_README_V" ]; then
	fail "Free: version mismatch in plugin metadata"
	fail "  header=$FREE_HEADER_V const=$FREE_CONST_V readme=$FREE_README_V"
	exit 11
fi
if [ -n "$FREE_PKG_V" ] && [ "$FREE_PKG_V" != "$FREE_HEADER_V" ]; then
	fail "Free: package.json version ($FREE_PKG_V) doesn't match plugin header ($FREE_HEADER_V)"
	exit 11
fi
ok "Free version: $FREE_HEADER_V"

if [ "$FREE_ONLY" -eq 0 ]; then
	PRO_HEADER_V="$(read_header_version "$PRO_ROOT/$PRO_MAIN")"
	PRO_CONST_V="$(read_define_version  "$PRO_ROOT/$PRO_MAIN" 'MVS_PRO_VERSION')"
	PRO_README_V="$(read_readme_version "$PRO_ROOT/readme.txt")"
	PRO_PKG_V="$(read_package_version  "$PRO_ROOT/package.json")"

	if [ -z "$PRO_HEADER_V" ] || [ -z "$PRO_CONST_V" ] || [ -z "$PRO_README_V" ]; then
		fail "Pro: missing one of header/const/readme version"
		fail "  header=$PRO_HEADER_V const=$PRO_CONST_V readme=$PRO_README_V"
		exit 11
	fi
	if [ "$PRO_HEADER_V" != "$PRO_CONST_V" ] || [ "$PRO_HEADER_V" != "$PRO_README_V" ]; then
		fail "Pro: version mismatch in plugin metadata"
		fail "  header=$PRO_HEADER_V const=$PRO_CONST_V readme=$PRO_README_V"
		exit 11
	fi
	if [ -n "$PRO_PKG_V" ] && [ "$PRO_PKG_V" != "$PRO_HEADER_V" ]; then
		fail "Pro: package.json version ($PRO_PKG_V) doesn't match plugin header ($PRO_HEADER_V)"
		exit 11
	fi
	ok "Pro version:  $PRO_HEADER_V"

	# THE rule: Free + Pro release versions must match.
	if [ "$FREE_HEADER_V" != "$PRO_HEADER_V" ]; then
		fail "COMBO RULE BROKEN: Free is $FREE_HEADER_V but Pro is $PRO_HEADER_V."
		fail "Free and Pro ship as a paired release every time. Bump them together or run --free-only."
		exit 11
	fi
fi

VERSION="$FREE_HEADER_V"
ok "combo release version: $VERSION"

# --- 3. boot smoke on the working trees -----------------------------------
step "3. boot smoke (working tree)"
if [ ! -f "$FREE_ROOT/tools/smoke-test.php" ]; then
	fail "Free tools/smoke-test.php missing"
	exit 30
fi
if ! php "$FREE_ROOT/tools/smoke-test.php" "$FREE_ROOT/$FREE_MAIN"; then
	fail "Free boot smoke failed against working tree"
	exit 30
fi
ok "Free boot smoke OK"

if [ "$FREE_ONLY" -eq 0 ]; then
	if [ ! -f "$PRO_ROOT/tools/smoke-test.php" ]; then
		fail "Pro tools/smoke-test.php missing"
		exit 30
	fi
	if ! php "$PRO_ROOT/tools/smoke-test.php" "$PRO_ROOT/$PRO_MAIN" "$FREE_ROOT/$FREE_MAIN"; then
		fail "Pro boot smoke failed against working tree (Free + Pro pair)"
		exit 30
	fi
	ok "Pro  boot smoke OK"
fi

# --- 4. asset regen via grunt --------------------------------------------
# Refresh every *.min.css, *.min.js, RTL CSS, and the .pot translation file
# from the committed source. Closes the "stale .min" gap that ships zips
# without minified versions of newly added assets.
step "4. grunt build (Free)"
( cd "$FREE_ROOT" && {
	if [ ! -x "node_modules/.bin/grunt" ]; then
		warn "Free node_modules missing — running npm install"
		npm install --silent || { fail "Free npm install failed"; exit 21; }
	fi
	./node_modules/.bin/grunt build > /dev/null 2>&1 || { fail "Free grunt build failed"; exit 21; }
} )
ok "Free grunt build OK"

if [ "$FREE_ONLY" -eq 0 ]; then
	step "4b. grunt build (Pro)"
	( cd "$PRO_ROOT" && {
		if [ ! -x "node_modules/.bin/grunt" ]; then
			warn "Pro node_modules missing — running npm install"
			npm install --silent || { fail "Pro npm install failed"; exit 21; }
		fi
		./node_modules/.bin/grunt build > /dev/null 2>&1 || { fail "Pro grunt build failed"; exit 21; }
	} )
	ok "Pro grunt build OK"
fi

# --- 5. browser smoke gate (combo report required) -----------------------
# Gates the package behind a documented browser walk of customer-facing
# flows. Customer-first-hand-experience protection: no release ships unless
# a fresh agent run of qa/runbooks/AGENT_SMOKE_RUNBOOK.md (dispatched via the
# mediaverse-qa skill) reported zero from-origin failures and was tagged
# with the current release version.
#
# The combo report covers BOTH plugins in one walk (Sections C cover Free
# core flows, E covers Pro extensions). For a free-only build we accept the
# free-only artifact instead.
#
# Emergency bypass: --skip-browser-smoke (logs a warning).
step "5. browser smoke gate"
if [ "$FREE_ONLY" -eq 1 ]; then
	SMOKE_REPORT="$FREE_ROOT/qa/.last-smoke-pass-free.json"
	REQUIRED_MODE="free"
else
	SMOKE_REPORT="$FREE_ROOT/qa/.last-smoke-pass.json"
	REQUIRED_MODE="combo"
fi

if [ "$SKIP_BROWSER_SMOKE" -eq 1 ]; then
	warn "browser smoke gate SKIPPED (--skip-browser-smoke). Not for customer releases."
elif [ ! -f "$SMOKE_REPORT" ]; then
	fail "no browser smoke report at $SMOKE_REPORT"
	fail "Run the mediaverse-qa skill first:"
	fail "  Ask Claude Code: \"run mediaverse pre-release smoke in $REQUIRED_MODE mode\""
	fail "Or, in emergencies, rerun with --skip-browser-smoke."
	exit 30
else
	REPORT_JSON_CHECK="$(python3 -c "
import json, sys
try:
    d = json.load(open('$SMOKE_REPORT'))
except Exception as e:
    print('PARSE_FAIL ' + str(e))
    sys.exit(0)
release = d.get('release_version', '')
mode    = d.get('mode', '')
failures     = d.get('failures') or []
debug_issues = d.get('debug_log_issues') or []
ran_at = d.get('ran_at', '')
from_failures = [f for f in failures if (f.get('origin') or 'from') == 'from']
from_issues   = [i for i in debug_issues if (i.get('origin') or 'from') == 'from']
print('VERSION=' + release)
print('MODE=' + mode)
print('FROM_FAILURES=' + str(len(from_failures)))
print('FROM_ISSUES=' + str(len(from_issues)))
print('RAN_AT=' + ran_at)
" 2>&1)"
	if echo "$REPORT_JSON_CHECK" | grep -q '^PARSE_FAIL'; then
		fail "smoke report at $SMOKE_REPORT is not valid JSON."
		fail "$REPORT_JSON_CHECK"
		exit 30
	fi
	REPORT_VERSION="$(echo "$REPORT_JSON_CHECK" | grep -oE '^VERSION=.*'       | sed 's/^VERSION=//')"
	REPORT_MODE="$(   echo "$REPORT_JSON_CHECK" | grep -oE '^MODE=.*'          | sed 's/^MODE=//')"
	FROM_FAILURES="$( echo "$REPORT_JSON_CHECK" | grep -oE '^FROM_FAILURES=.*' | sed 's/^FROM_FAILURES=//')"
	FROM_ISSUES="$(   echo "$REPORT_JSON_CHECK" | grep -oE '^FROM_ISSUES=.*'   | sed 's/^FROM_ISSUES=//')"
	RAN_AT="$(        echo "$REPORT_JSON_CHECK" | grep -oE '^RAN_AT=.*'        | sed 's/^RAN_AT=//')"

	if [ "$REPORT_VERSION" != "$VERSION" ]; then
		fail "smoke report version ($REPORT_VERSION) doesn't match release version ($VERSION)"
		fail "Rerun the mediaverse-qa skill against HEAD before packaging."
		exit 30
	fi
	if [ -n "$REPORT_MODE" ] && [ "$REPORT_MODE" != "$REQUIRED_MODE" ]; then
		fail "smoke report mode ($REPORT_MODE) doesn't match required mode ($REQUIRED_MODE)"
		fail "Rerun the mediaverse-qa skill in $REQUIRED_MODE mode."
		exit 30
	fi
	if [ "$FROM_FAILURES" != "0" ]; then
		fail "smoke report has $FROM_FAILURES from-origin failures. Fix them before packaging."
		exit 30
	fi
	if [ "$FROM_ISSUES" != "0" ]; then
		fail "smoke report recorded $FROM_ISSUES from-origin debug.log entries during the walk. Fix them before packaging."
		exit 30
	fi
	# FRESHNESS. Version + mode + zero failures is what this gate checked until
	# 2026-08-19, and none of those notice a report that predates the code it
	# claims to have walked. The 2.4.0 cycle proved it: the combo report was
	# dated 2026-08-11 and still passed every check above while four later
	# changes — document previews, the licence gate, an index fix and a PHP
	# warning — had never been walked at all. A version match is not a
	# freshness check; the version had not moved.
	#
	# So: the report must be NEWER than the last commit that touched plugin
	# source. Compared against real commits rather than a fixed max age,
	# because a quiet week is not staleness and an hour of edits is.
	LAST_SRC_COMMIT=0
	for _root in "$FREE_ROOT" "$PRO_ROOT"; do
		[ -d "$_root/.git" ] || continue
		_ts="$( cd "$_root" && git log -1 --format=%ct -- includes templates assets src 2>/dev/null || echo 0 )"
		[ -n "$_ts" ] || _ts=0
		[ "$_ts" -gt "$LAST_SRC_COMMIT" ] && LAST_SRC_COMMIT="$_ts"
	done

	REPORT_TS="$( python3 -c "
import datetime, sys
raw = '''$RAN_AT'''.strip().replace('Z', '+00:00')
try:
    print(int(datetime.datetime.fromisoformat(raw).timestamp()))
except Exception:
    print(0)
" 2>/dev/null || echo 0 )"

	if [ "$LAST_SRC_COMMIT" -gt 0 ] && [ "$REPORT_TS" -le "$LAST_SRC_COMMIT" ]; then
		fail "smoke report is STALE: dated $RAN_AT, but plugin source was committed after that."
		fail "It walked code that is no longer what you are packaging."
		fail "Rerun the smoke in $REQUIRED_MODE mode against HEAD."
		exit 30
	fi

	# COVERAGE. Zero failures is not evidence when nothing ran — a report whose
	# sections are entirely skipped satisfies every check above. Require the
	# core-flow section to have actually walked something.
	CORE_PASSES="$( python3 -c "
import json
d = json.load(open('$SMOKE_REPORT'))
print(int(((d.get('sections') or {}).get('C_core_flows') or {}).get('pass') or 0))
" 2>/dev/null || echo 0 )"

	if [ "$CORE_PASSES" -lt 10 ]; then
		fail "smoke report records only $CORE_PASSES passing core-flow checks."
		fail "Zero failures is not evidence when nothing was walked."
		exit 30
	fi

	ok "browser smoke report v$REPORT_VERSION ($REPORT_MODE) — green, dated $RAN_AT, $CORE_PASSES core-flow passes"
fi

# --- 6. produce the zips via grunt dist ----------------------------------
# Wipe every prior dist artifact for this plugin slug before re-building,
# so the dist/ directory only ever contains the CURRENT version's ZIP +
# extracted tree. Prevents stale 1.X.Y.zip files from accumulating
# alongside the new 1.A.B.zip (caught by Varun's review of an earlier
# build: a 1.2.2.zip lingered after the 1.4.0 release and git's rename
# detection then misreported the relationship between the two ZIPs).
step "6. grunt dist (Free)"
( cd "$FREE_ROOT" && rm -rf "dist/$FREE_SLUG" "dist/${FREE_SLUG}-"*.zip \
	&& ./node_modules/.bin/grunt dist > /dev/null 2>&1 ) || { fail "Free grunt dist failed"; exit 21; }
FREE_ZIP="$FREE_ROOT/dist/${FREE_SLUG}-${VERSION}.zip"
[ -f "$FREE_ZIP" ] || { fail "Free zip not produced at $FREE_ZIP"; exit 40; }
ok "Free zip: $FREE_ZIP"

if [ "$FREE_ONLY" -eq 0 ]; then
	step "6b. grunt dist (Pro)"
	( cd "$PRO_ROOT" && rm -rf "dist/$PRO_SLUG" "dist/${PRO_SLUG}-"*.zip \
		&& ./node_modules/.bin/grunt dist > /dev/null 2>&1 ) || { fail "Pro grunt dist failed"; exit 21; }
	PRO_ZIP="$PRO_ROOT/dist/${PRO_SLUG}-${VERSION}.zip"
	[ -f "$PRO_ZIP" ] || { fail "Pro zip not produced at $PRO_ZIP"; exit 40; }
	ok "Pro  zip: $PRO_ZIP"
fi

# --- 7. extract + boot-smoke each zip back -------------------------------
# Catches any zip corruption / file dropped by the dist exclude list.
step "7. boot smoke against extracted zips"
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT

unzip -q "$FREE_ZIP" -d "$SCRATCH/free"
if [ ! -f "$SCRATCH/free/$FREE_SLUG/$FREE_MAIN" ]; then
	fail "Free zip missing expected entry: $FREE_SLUG/$FREE_MAIN"
	exit 40
fi
if ! php "$FREE_ROOT/tools/smoke-test.php" "$SCRATCH/free/$FREE_SLUG/$FREE_MAIN"; then
	fail "Free boot smoke failed against extracted zip"
	exit 30
fi
ok "Free zip boot smoke OK"

if [ "$FREE_ONLY" -eq 0 ]; then
	unzip -q "$PRO_ZIP" -d "$SCRATCH/pro"
	if [ ! -f "$SCRATCH/pro/$PRO_SLUG/$PRO_MAIN" ]; then
		fail "Pro zip missing expected entry: $PRO_SLUG/$PRO_MAIN"
		exit 40
	fi
	# Pro smoke needs a Free main file. Use the staged Free zip's main file
	# so the pair under test is the same code that's about to ship.
	if ! php "$PRO_ROOT/tools/smoke-test.php" "$SCRATCH/pro/$PRO_SLUG/$PRO_MAIN" "$SCRATCH/free/$FREE_SLUG/$FREE_MAIN"; then
		fail "Pro boot smoke failed against extracted zip pair"
		exit 30
	fi
	ok "Pro  zip boot smoke OK (paired with Free zip)"
fi

# --- 8. checksums + optional output copy ---------------------------------
step "8. summary"
FREE_SHA="$(shasum -a 256 "$FREE_ZIP" | awk '{print $1}')"
FREE_SIZE="$(du -h "$FREE_ZIP" | awk '{print $1}')"
echo "    Free: $FREE_ZIP  ($FREE_SIZE  sha256=$FREE_SHA)"

if [ "$FREE_ONLY" -eq 0 ]; then
	PRO_SHA="$(shasum -a 256 "$PRO_ZIP" | awk '{print $1}')"
	PRO_SIZE="$(du -h "$PRO_ZIP" | awk '{print $1}')"
	echo "    Pro:  $PRO_ZIP  ($PRO_SIZE  sha256=$PRO_SHA)"
fi

if [ -n "$OUTPUT_DIR" ]; then
	mkdir -p "$OUTPUT_DIR"
	cp "$FREE_ZIP" "$OUTPUT_DIR/"
	echo "    copied to $OUTPUT_DIR/$(basename "$FREE_ZIP")"
	if [ "$FREE_ONLY" -eq 0 ]; then
		cp "$PRO_ZIP" "$OUTPUT_DIR/"
		echo "    copied to $OUTPUT_DIR/$(basename "$PRO_ZIP")"
	fi
fi

echo
ok "OK — combo release $VERSION ready (Free $FREE_HEAD$([ "$FREE_ONLY" -eq 0 ] && echo " + Pro $PRO_HEAD"))"
