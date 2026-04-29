#!/usr/bin/env bash
# WPMediaVerse — local CI gate
# Runs the same quality checks GitHub CI runs, before you push.
#
# Usage:
#   bash bin/ci-local.sh           # full check
#   bash bin/ci-local.sh --quick   # skip PHP version matrix
#   bash bin/ci-local.sh --staged  # run only against staged files
#
# Exits non-zero if any gate fails. Pre-push hook should call this.
# ─────────────────────────────────────────────────────────────────────────────

set -e

PLUGIN_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PLUGIN_DIR"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

QUICK=0
STAGED=0
for arg in "$@"; do
	case "$arg" in
		--quick) QUICK=1 ;;
		--staged) STAGED=1 ;;
	esac
done

FAIL=0
section() { echo -e "\n${BOLD}${BLUE}━━━ $1 ━━━${NC}"; }
pass() { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; FAIL=1; }
warn() { echo -e "  ${YELLOW}⚠${NC} $1"; }

# ─── 1. PHP syntax check (multi-version) ─────────────────────────────────────

section "1. PHP syntax check"

PHP_BINARIES=()
# Default PHP (whatever `php` resolves to)
PHP_BINARIES+=("php")
# Homebrew alternates — test the highest version we can find, since 8.3+
# catches duplicate-method-redeclare as fatal that 8.1/8.2 silently allow.
for v in 8.5 8.4 8.3; do
	if command -v "/opt/homebrew/opt/php@${v}/bin/php" >/dev/null 2>&1; then
		PHP_BINARIES+=("/opt/homebrew/opt/php@${v}/bin/php")
	elif command -v "/opt/homebrew/bin/php" >/dev/null 2>&1 && [ "$(/opt/homebrew/bin/php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" = "${v}" ]; then
		PHP_BINARIES+=("/opt/homebrew/bin/php")
	fi
done

if [ "$QUICK" = "1" ]; then
	PHP_BINARIES=("${PHP_BINARIES[0]}")
	warn "Quick mode: only default PHP ($(${PHP_BINARIES[0]} -v | head -1 | awk '{print $2}'))"
fi

# Files to lint
if [ "$STAGED" = "1" ]; then
	FILES=$(git diff --cached --name-only --diff-filter=ACM | grep '\.php$' || true)
	if [ -z "$FILES" ]; then
		warn "No staged PHP files; falling back to full project"
		STAGED=0
	fi
fi

if [ "$STAGED" = "0" ]; then
	# Mirror CI: lint every PHP file outside vendor/node_modules/dist
	FILES=$(find . -name "*.php" \
		-not -path "./vendor/*" \
		-not -path "./node_modules/*" \
		-not -path "./dist/*" \
		2>/dev/null)
fi

for php_bin in "${PHP_BINARIES[@]}"; do
	php_ver=$("$php_bin" -v | head -1 | awk '{print $2}')
	echo "  Linting with PHP $php_ver ($php_bin)"
	errors=$(echo "$FILES" | xargs -I{} "$php_bin" -l {} 2>&1 | grep -E "^(Parse error|Errors parsing|PHP Fatal)" || true)
	if [ -n "$errors" ]; then
		fail "PHP $php_ver lint errors:"
		echo "$errors" | head -10 | sed 's/^/    /'
	else
		pass "PHP $php_ver — no syntax errors"
	fi
done

# ─── 2. PHPCS (WordPress Coding Standards) ───────────────────────────────────

section "2. PHPCS"

if [ -x vendor/bin/phpcs ]; then
	if vendor/bin/phpcs --standard=phpcs.xml --report=summary "${FILES[@]:-.}" 2>&1 | tail -5 | grep -q "FOUND 0 ERRORS"; then
		pass "PHPCS clean"
	else
		# Show errors only (warnings are informational)
		err_count=$(vendor/bin/phpcs --standard=phpcs.xml --report=summary "${FILES[@]:-.}" 2>&1 | grep -E "^A TOTAL OF [0-9]+ ERROR" | awk '{print $4}' || echo 0)
		if [ "${err_count:-0}" = "0" ]; then
			pass "PHPCS clean (warnings only)"
		else
			fail "PHPCS errors found"
			vendor/bin/phpcs --standard=phpcs.xml -n "${FILES[@]:-.}" 2>&1 | tail -20
		fi
	fi
else
	warn "vendor/bin/phpcs not found — run composer install"
fi

# ─── 3. PHPStan ──────────────────────────────────────────────────────────────

section "3. PHPStan"

if [ -x vendor/bin/phpstan ]; then
	if vendor/bin/phpstan analyse -c phpstan.neon.dist --memory-limit=2G --no-progress > /tmp/phpstan-local.log 2>&1; then
		pass "PHPStan level 5 clean"
	else
		fail "PHPStan errors:"
		grep -v "^$\|^Note:\|PHPStan 2.x\|Upgrading\|Release notes\|Blog article" /tmp/phpstan-local.log | tail -15 | sed 's/^/    /'
	fi
else
	warn "vendor/bin/phpstan not found"
fi

# ─── 4. Specific gotchas catch (duplicate methods, raw URL leaks, etc.) ──────

section "4. Code-smell sniffs"

# Raw uploads URL leak guard.
# .htaccess in wp-content/uploads/wpmediaverse/ blocks direct access. Every
# media URL must flow through SignedUrlService (or MediaUrl). Specific
# leak patterns:
#   1. Direct MediaRepository::get(..., 'file_url') / 'thumb_*' fed to esc_url or REST emission
#   2. Stored varchar URL columns (cover_image_url) emitted raw via $row->...
#   3. Literal wp-content/uploads/wpmediaverse path-string concat
URL_LEAKS=$(
	grep -rnE \
		"esc_url\s*\(\s*MediaRepository::get\(\s*[^,]+,\s*'(file_url|thumb_large|thumb_medium|thumb_thumb)'|esc_url\s*\(\s*set_url_scheme\s*\(\s*MediaRepository::get\(\s*[^,]+,\s*'(file_url|thumb_)|'file_url'\s*=>\s*MediaRepository::get|'cover_image_url'\s*=>\s*\\\$row->cover_image_url|'thumbnail_url'\s*=>\s*MediaRepository::get|'(file_url|thumbnail_url|cover_url|cover_image_url)'\s*=>\s*\\\$row->|MediaRepository::get\(\s*[^,]+,\s*'file_url'\s*\)\s*[;\)]" \
		--include="*.php" \
		--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=tests --exclude-dir=docs \
		includes/ templates/ src/ 2>/dev/null \
	| grep -vE "MediaUrl|SignedUrlService|^[^:]+:[0-9]+:\s*\*|^[^:]+:[0-9]+:\s*//|id_from_url|file_url\s*=\s*%s|CI:\s*storage-internal" \
	|| true
)
# Also flag literal wp-content/uploads path concat in PHP (not test fixtures).
URL_PATH_CONCAT=$(
	grep -rnE \
		"['\"]\s*/?wp-content/uploads/wpmediaverse/['\"]" \
		--include="*.php" \
		--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=dist --exclude-dir=tests --exclude-dir=docs \
		includes/ templates/ src/ 2>/dev/null \
	| grep -vE "MediaUrl|SignedUrlService|^[^:]+:[0-9]+:\s*\*|^[^:]+:[0-9]+:\s*//|trailingslashit|CI:\s*storage-internal" \
	|| true
)

if [ -n "$URL_LEAKS" ] || [ -n "$URL_PATH_CONCAT" ]; then
	fail "Raw upload-URL emissions detected — must route through MediaUrl / SignedUrlService:"
	[ -n "$URL_LEAKS" ] && echo "$URL_LEAKS" | head -10 | sed 's/^/    /'
	[ -n "$URL_PATH_CONCAT" ] && echo "$URL_PATH_CONCAT" | head -5 | sed 's/^/    /'
else
	pass "No raw upload-URL leaks"
fi

# Duplicate method declarations (the gotcha that bit us 2026-04-29)
DUPES=$(grep -rEn "^\s*(public|private|protected)\s+(static\s+)?function\s+\w+" \
	--include="*.php" includes/ 2>/dev/null \
	| awk -F'function ' '{print $2}' \
	| awk -F'(' '{print $1}' \
	| sort | uniq -c | awk '$1 > 1 {print}' \
	| head -5 || true)

if [ -n "$DUPES" ]; then
	# Only flag dupes that are in the same file
	REAL_DUPES=""
	for fn in $(echo "$DUPES" | awk '{print $2}'); do
		files=$(grep -rln "function ${fn}\s*(" --include="*.php" includes/ 2>/dev/null)
		for f in $files; do
			count=$(grep -cE "^\s*(public|private|protected)\s+(static\s+)?function\s+${fn}\s*\(" "$f")
			if [ "$count" -gt 1 ]; then
				REAL_DUPES+="$f: $fn (declared $count times)\n"
			fi
		done
	done
	if [ -n "$REAL_DUPES" ]; then
		fail "Duplicate method declarations found:"
		echo -e "$REAL_DUPES" | sed 's/^/    /'
	else
		pass "No same-file duplicate methods"
	fi
else
	pass "No duplicate methods"
fi

# ─── Summary ─────────────────────────────────────────────────────────────────

section "Summary"

if [ "$FAIL" = "0" ]; then
	echo -e "  ${GREEN}${BOLD}✓ All gates passed — safe to push${NC}\n"
	exit 0
else
	echo -e "  ${RED}${BOLD}✗ One or more gates failed — fix before pushing${NC}\n"
	exit 1
fi
