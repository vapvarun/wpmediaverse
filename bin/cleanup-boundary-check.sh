#!/usr/bin/env bash
# bin/cleanup-boundary-check.sh
#
# Detects Pro→Free boundary violations per Coding Rule #10
# ("Pro: never import Free classes directly; hook into mvs_loaded and use
#  ServiceContainer"). Each violation is a candidate for refactor — Pro
# should call Free's service via ->get('foo') instead of `use` + new.
#
# Also flags Pro code doing direct $wpdb queries against mvs_* tables —
# should route through Free's MediaRepository.
#
# Output: audit/cleanup/boundary-violations.json

set -euo pipefail

FREE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRO_DIR="$FREE_DIR/../wpmediaverse-pro"
OUT_DIR="$FREE_DIR/audit/cleanup"
OUT_FILE="$OUT_DIR/boundary-violations.json"

mkdir -p "$OUT_DIR"

if [ ! -d "$PRO_DIR" ]; then
    echo "{\"generated_at\":\"$(date -u +%Y-%m-%dT%H:%M:%SZ)\",\"violations\":[],\"note\":\"Pro plugin directory not found at $PRO_DIR\"}" > "$OUT_FILE"
    echo "No Pro directory — wrote empty $OUT_FILE"
    exit 0
fi

JQ="${JQ:-jq}"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

find "$PRO_DIR" -name "*.php" \
    -not -path "*/vendor/*" -not -path "*/node_modules/*" \
    -not -path "*/dist/*" -not -path "*/build/*" \
    -not -path "*/audit/*" -not -path "*/tests/*" \
    -print0 > "$TMP/pro-scan.bin"

# ---------------------------------------------------------------------------
# Violation A: `use WPMediaVerse\…` direct imports in Pro classes
# ---------------------------------------------------------------------------
xargs -0 grep -nHE "^\s*use\s+WPMediaVerse\\\\" < "$TMP/pro-scan.bin" 2>/dev/null \
    | grep -v "WPMediaVerse\\\\Pro\\\\" \
    | awk -F: '{file=$1; line=$2; rest=substr($0, length(file)+length(line)+3); print file"\t"line"\t"rest}' \
    | while IFS=$'\t' read -r f l rest; do
        rel="${f#$PRO_DIR/}"
        symbol=$(echo "$rest" | sed -E 's/^\s*use\s+([^;]+);.*/\1/' | tr -d ' ')
        echo "{\"violation\":\"direct_free_import\",\"file\":\"$rel\",\"line\":$l,\"imports\":\"$symbol\",\"rule\":\"Coding Rule #10\",\"fix\":\"Use Plugin::container()->get(<service_key>) — never import Free classes directly\"}"
    done | $JQ -s '.' > "$TMP/imports.json" 2>/dev/null || echo "[]" > "$TMP/imports.json"

# ---------------------------------------------------------------------------
# Violation B: Direct `$wpdb` queries against mvs_* tables in Pro
# ---------------------------------------------------------------------------
# Free-owned tables (21 total — anything not in this list is Pro-owned and
# legitimately queryable by Pro services). Update this list when Free's
# Migrator adds a new table.
FREE_TABLES_RE='mvs_(media_index|media_meta|media_views|media_stats|reactions|favorites|follows|comments|mentions|activity|notifications|reports|blocks|access_rules|access_grants|album_items|error_log|conversations|conversation_participants|messages|message_reactions|transactions)'

# Match the Free-table name only when it appears on a line ALSO containing
# an SQL access keyword. This excludes false positives where a docblock
# comment mentions a Free-table FK above an unrelated query (e.g.,
# Pro's ChallengeService comments "cover_media_id is a FK to mvs_media_index"
# right above a SELECT on mvs_competition_entries — the docblock would
# previously trip the 10-line window scan).
SQL_KEYWORDS_RE='(FROM[[:space:]]|UPDATE[[:space:]]|INTO[[:space:]]|JOIN[[:space:]]|DELETE[[:space:]]+FROM|SHOW[[:space:]]+TABLES)'

xargs -0 grep -nHE "\\\$wpdb->(query|get_var|get_row|get_results|get_col|insert|update|delete|prepare)\s*\(" < "$TMP/pro-scan.bin" 2>/dev/null \
    | while IFS=$'\n' read -r match; do
        file=$(echo "$match" | awk -F: '{print $1}')
        line=$(echo "$match" | awk -F: '{print $2}')
        # Narrower context window (line-1 .. line+5) AND require an SQL
        # keyword on the same line as the Free-table reference.
        if awk -v l="$line" 'NR>=l-1 && NR<=l+5' "$file" 2>/dev/null \
            | grep -E "$SQL_KEYWORDS_RE" \
            | grep -qE "$FREE_TABLES_RE" ; then
            rel="${file#$PRO_DIR/}"
            echo "{\"violation\":\"direct_wpdb_on_mvs_table\",\"file\":\"$rel\",\"line\":$line,\"rule\":\"Coding Rule #16 + boundary\",\"fix\":\"Route through Plugin::container()->get('media_repository') or the appropriate service. Direct wpdb against Free tables bypasses caching, privacy, request-cache invariants.\"}"
        fi
    done | $JQ -s '.' > "$TMP/wpdb.json" 2>/dev/null || echo "[]" > "$TMP/wpdb.json"

# ---------------------------------------------------------------------------
# Violation C: Pro using `new WPMediaVerse\…` (fully-qualified instantiation)
# ---------------------------------------------------------------------------
xargs -0 grep -nHE "new\s+\\\\?WPMediaVerse\\\\" < "$TMP/pro-scan.bin" 2>/dev/null \
    | grep -v "WPMediaVerse\\\\Pro\\\\" \
    | grep -v "throw new" \
    | awk -F: '{file=$1; line=$2; print file"\t"line}' \
    | while IFS=$'\t' read -r f l; do
        rel="${f#$PRO_DIR/}"
        echo "{\"violation\":\"new_free_class\",\"file\":\"$rel\",\"line\":$l,\"rule\":\"Coding Rule #10\",\"fix\":\"Free classes should be resolved via the service container, not instantiated by Pro\"}"
    done | $JQ -s '.' > "$TMP/new.json" 2>/dev/null || echo "[]" > "$TMP/new.json"

# ---------------------------------------------------------------------------
# Merge
# ---------------------------------------------------------------------------
$JQ -n --slurpfile i "$TMP/imports.json" \
      --slurpfile w "$TMP/wpdb.json" \
      --slurpfile n "$TMP/new.json" \
'{
  generated_at: (now | strftime("%Y-%m-%dT%H:%M:%SZ")),
  generator: "bin/cleanup-boundary-check.sh",
  violations: ($i[0] + $w[0] + $n[0]),
  counts: {
    direct_free_imports: ($i[0] | length),
    direct_wpdb_on_mvs_tables: ($w[0] | length),
    new_free_class: ($n[0] | length),
    total: ($i[0] + $w[0] + $n[0] | length)
  }
}' > "$OUT_FILE"

echo "Wrote $OUT_FILE"
$JQ '.counts' "$OUT_FILE"
