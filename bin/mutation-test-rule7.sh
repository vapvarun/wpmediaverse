#!/usr/bin/env bash
# bin/mutation-test-rule7.sh — proves Rule 7 (bin/coding-rules-check.sh) actually
# catches a direct mvs_media_index query, and actually respects its allowlist.
#
# Run via: bash bin/mutation-test-rule7.sh
#
# Why this exists: a rule that has never seen a failing case is unverified, not
# passing — it could be silently vacuous (a typo'd regex, an allowlist that
# accidentally matches everything) and a green run would look identical either
# way. This builds a synthetic plugin tree, plants an intentional violation in
# it, and asserts the shared detector (bin/lib/detect-media-index-leaks.py)
# finds it — then plants the same violation inside an allowlisted filename and
# asserts it does NOT get flagged. Both directions matter: a rule that flags
# everything is as useless as one that flags nothing.
#
# This is a build-time self-test of the tool, not a per-commit gate — it does
# not need to run on every push, only when Rule 7 or its detector changes.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DETECTOR="$SCRIPT_DIR/lib/detect-media-index-leaks.py"

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

FAILURES=0

fail() {
    echo "✗ $*"
    FAILURES=$((FAILURES + 1))
}
pass() {
    echo "✓ $*"
}

VIOLATING_SNIPPET='<?php
class Fixture {
	public function leak() {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}mvs_media_index WHERE status = %s",
				"publish"
			)
		);
	}
}
'

# --- Case 1: a violation in a normal (non-allowlisted) file MUST be caught ---
mkdir -p "$TMP_DIR/case1/includes/Services"
printf '%s' "$VIOLATING_SNIPPET" > "$TMP_DIR/case1/includes/Services/LeakyService.php"

hits_case1="$(python3 "$DETECTOR" "$TMP_DIR/case1" 2>/dev/null || true)"
if echo "$hits_case1" | grep -q "LeakyService.php"; then
    pass "Case 1 — a direct mvs_media_index query in a non-allowlisted file is caught"
else
    fail "Case 1 — planted violation in LeakyService.php was NOT caught (detector is vacuous):"
    echo "    detector output: ${hits_case1:-<empty>}"
fi

# --- Case 2: the identical violation inside an allowlisted path MUST be silent ---
mkdir -p "$TMP_DIR/case2/includes/Repository"
printf '%s' "$VIOLATING_SNIPPET" > "$TMP_DIR/case2/includes/Repository/MediaRepository.php"

hits_case2="$(python3 "$DETECTOR" "$TMP_DIR/case2" 2>/dev/null || true)"
if [ -z "$hits_case2" ]; then
    pass "Case 2 — the same query inside the allowlisted MediaRepository.php is not flagged"
else
    fail "Case 2 — allowlisted MediaRepository.php was flagged (allowlist is broken):"
    echo "    detector output: $hits_case2"
fi

# --- Case 3: a file that merely mentions the table name in a comment/docblock
#     must NOT be flagged — the detector matches real $wpdb calls, not the
#     bare string, or it would be too noisy to trust (see plan/document-library.md
#     §24.1: the naive substring count was 50 files, the real number was 12).
mkdir -p "$TMP_DIR/case3/includes/Services"
cat > "$TMP_DIR/case3/includes/Services/CommentOnly.php" <<'PHP'
<?php
/**
 * Purges mvs_media_index rows via the repository cascade.
 *
 * @see MediaRepository::delete_cascade() — this class never queries
 *      mvs_media_index directly.
 */
class CommentOnly {
	public function noop() {
		return true;
	}
}
PHP

hits_case3="$(python3 "$DETECTOR" "$TMP_DIR/case3" 2>/dev/null || true)"
if [ -z "$hits_case3" ]; then
    pass "Case 3 — a bare comment mentioning the table name is not a false positive"
else
    fail "Case 3 — comment-only mention was flagged (detector is too broad):"
    echo "    detector output: $hits_case3"
fi

echo ""
if [ "$FAILURES" -eq 0 ]; then
    echo "All Rule 7 mutation-test cases pass — the detector both catches real leaks"
    echo "and respects its allowlist and comment-only mentions."
    exit 0
else
    echo "$FAILURES mutation-test case(s) failed. Rule 7 cannot be trusted until this is green."
    exit 1
fi
