#!/usr/bin/env bash
# bin/cleanup-bridge-inventory.sh
#
# Builds audit/cleanup/bridges.json — the canonical "what cannot be silently
# removed" list for this Free+Pro pair. Every cleanup plan MUST check
# candidate symbols against this file before deletion.
#
# A symbol is a BRIDGE when at least one of these is true:
#   1. Pro consumes a Free hook (cross-plugin coupling)
#   2. Public filter / action documented with @since (third-party hookable)
#   3. Template file the theme can override via locate_template()
#   4. REST endpoint (external API)
#   5. Capability (role-management plugins read these)
#   6. WP option (devops scripts / staging migration set these)
#   7. WP-CLI command (automation scripts depend)
#   8. Service container key (Pro + mu-plugins resolve these)
#   9. CSS class used by Pro / theme / customer overrides
#
# Output: audit/cleanup/bridges.json — flat list of all bridge symbols
# with provenance.

set -euo pipefail

FREE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PRO_DIR="$FREE_DIR/../wpmediaverse-pro"
OUT_DIR="$FREE_DIR/audit/cleanup"
OUT_FILE="$OUT_DIR/bridges.json"

mkdir -p "$OUT_DIR"

JQ="${JQ:-jq}"
command -v "$JQ" > /dev/null || { echo "jq required"; exit 1; }

# Build scan file lists (PHP only; excludes vendor/dist/node_modules/audit/tests).
build_scan_list() {
    local dir="$1"
    local out="$2"
    find "$dir" -name "*.php" \
        -not -path "*/vendor/*" -not -path "*/node_modules/*" \
        -not -path "*/dist/*" -not -path "*/build/*" \
        -not -path "*/audit/*" -not -path "*/tests/*" \
        -print0 > "$out"
}

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

build_scan_list "$FREE_DIR" "$TMP/free-scan.bin"
[ -d "$PRO_DIR" ] && build_scan_list "$PRO_DIR" "$TMP/pro-scan.bin" || : > "$TMP/pro-scan.bin"

# ---------------------------------------------------------------------------
# 1. Cross-plugin bridges (Free hooks consumed by Pro)
# ---------------------------------------------------------------------------
CROSS_FILE="$FREE_DIR/audit/derived/cross-plugin-coupling.json"
if [ -f "$CROSS_FILE" ]; then
    $JQ '[.[] | select(.consumed_by != null and (.consumed_by | length > 0)) |
          { type: "cross_plugin_hook", symbol: .name, provenance: "Pro listens via add_filter/add_action",
            consumed_by: [.consumed_by[].where] }]' "$CROSS_FILE" > "$TMP/cross.json" 2>/dev/null \
       || echo "[]" > "$TMP/cross.json"
else
    echo "[]" > "$TMP/cross.json"
fi

# ---------------------------------------------------------------------------
# 2. Public hooks (@since annotated)
# ---------------------------------------------------------------------------
xargs -0 grep -nE "(do_action|apply_filters)\s*\(\s*['\"]" < "$TMP/free-scan.bin" 2>/dev/null \
    | awk -F: '{file=$1; line=$2; rest=$0; sub("^"file":"line":","",rest); print file"\t"line"\t"rest}' \
    | while IFS=$'\t' read -r f l rest; do
        # Get the 30 lines BEFORE this hook to find @since
        start=$((l - 30))
        [ "$start" -lt 1 ] && start=1
        if sed -n "${start},${l}p" "$f" 2>/dev/null | grep -q '@since' ; then
            name=$(echo "$rest" | grep -oE "['\"]([a-z_][a-z0-9_]+)['\"]" | head -1 | tr -d "'\"")
            [ -n "$name" ] && echo "{\"type\":\"documented_hook\",\"symbol\":\"$name\",\"provenance\":\"@since-annotated in $f:$l\"}"
        fi
    done | $JQ -s '.' > "$TMP/hooks.json" 2>/dev/null || echo "[]" > "$TMP/hooks.json"

# ---------------------------------------------------------------------------
# 3. Overridable templates (locate_template / load_template)
# ---------------------------------------------------------------------------
xargs -0 grep -hE "locate_template\s*\(|load_template\s*\(" < "$TMP/free-scan.bin" 2>/dev/null \
    | grep -oE "['\"][a-zA-Z0-9_/.\-]+\.php['\"]" \
    | tr -d "'\"" | sort -u \
    | while read -r tpl; do
        echo "{\"type\":\"overridable_template\",\"symbol\":\"$tpl\",\"provenance\":\"locate_template/load_template — theme can override\"}"
    done | $JQ -s '.' > "$TMP/templates.json" 2>/dev/null || echo "[]" > "$TMP/templates.json"

# ---------------------------------------------------------------------------
# 4. REST endpoints
# ---------------------------------------------------------------------------
xargs -0 grep -hE "register_rest_route" < "$TMP/free-scan.bin" 2>/dev/null \
    | grep -oE "['\"][a-zA-Z0-9_/\-]+['\"]" | head -200 \
    | tr -d "'\"" | sort -u \
    | grep -E "^/?[a-z]+/v[0-9]+/" \
    | while read -r route; do
        echo "{\"type\":\"rest_route\",\"symbol\":\"$route\",\"provenance\":\"external API endpoint\"}"
    done | $JQ -s '.' > "$TMP/rest.json" 2>/dev/null || echo "[]" > "$TMP/rest.json"

# ---------------------------------------------------------------------------
# 5. Capabilities (role plugins read these)
# ---------------------------------------------------------------------------
xargs -0 grep -hE "(add_cap|->add_cap)\s*\(\s*['\"]" < "$TMP/free-scan.bin" 2>/dev/null \
    | grep -oE "['\"][a-z_][a-z0-9_]+['\"]" | head -200 \
    | tr -d "'\"" | sort -u \
    | grep -vE "^(administrator|editor|author|contributor|subscriber)$" \
    | while read -r cap; do
        echo "{\"type\":\"capability\",\"symbol\":\"$cap\",\"provenance\":\"granted via add_cap — role plugins read this\"}"
    done | $JQ -s '.' > "$TMP/caps.json" 2>/dev/null || echo "[]" > "$TMP/caps.json"

# ---------------------------------------------------------------------------
# 6. Options (register_setting + get_option for mvs_* options)
# ---------------------------------------------------------------------------
xargs -0 grep -hE "register_setting\s*\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]mvs_" < "$TMP/free-scan.bin" 2>/dev/null \
    | grep -oE "['\"]mvs_[a-z_0-9]+['\"]" | tr -d "'\"" | sort -u \
    | while read -r opt; do
        echo "{\"type\":\"option\",\"symbol\":\"$opt\",\"provenance\":\"register_setting — devops/migration may set this\"}"
    done | $JQ -s '.' > "$TMP/options.json" 2>/dev/null || echo "[]" > "$TMP/options.json"

# ---------------------------------------------------------------------------
# 7. WP-CLI commands (@subcommand annotation)
# ---------------------------------------------------------------------------
xargs -0 grep -nA 1 "@subcommand" < "$TMP/free-scan.bin" 2>/dev/null \
    | grep -E "@subcommand\s+[a-z\-]+" \
    | grep -oE "@subcommand\s+[a-z\-]+" | awk '{print $2}' | sort -u \
    | while read -r cmd; do
        echo "{\"type\":\"wp_cli\",\"symbol\":\"wp mvs $cmd\",\"provenance\":\"@subcommand — automation scripts depend\"}"
    done | $JQ -s '.' > "$TMP/cli.json" 2>/dev/null || echo "[]" > "$TMP/cli.json"

# ---------------------------------------------------------------------------
# 8. Service container keys
# ---------------------------------------------------------------------------
xargs -0 grep -hE "self::\\\$container->register\s*\(\s*['\"]" < "$TMP/free-scan.bin" 2>/dev/null \
    | grep -oE "['\"][a-z_][a-z0-9_.]+['\"]" | head -100 \
    | tr -d "'\"" | sort -u \
    | while read -r key; do
        echo "{\"type\":\"service_key\",\"symbol\":\"$key\",\"provenance\":\"container register — Pro+mu-plugins resolve via ->get('$key')\"}"
    done | $JQ -s '.' > "$TMP/services.json" 2>/dev/null || echo "[]" > "$TMP/services.json"

# ---------------------------------------------------------------------------
# Merge everything into the final bridges.json
# ---------------------------------------------------------------------------
$JQ -n --slurpfile c "$TMP/cross.json" \
      --slurpfile h "$TMP/hooks.json" \
      --slurpfile t "$TMP/templates.json" \
      --slurpfile r "$TMP/rest.json" \
      --slurpfile p "$TMP/caps.json" \
      --slurpfile o "$TMP/options.json" \
      --slurpfile l "$TMP/cli.json" \
      --slurpfile s "$TMP/services.json" \
'{
  generated_at: (now | strftime("%Y-%m-%dT%H:%M:%SZ")),
  generator: "bin/cleanup-bridge-inventory.sh",
  bridges: ($c[0] + $h[0] + $t[0] + $r[0] + $p[0] + $o[0] + $l[0] + $s[0]),
  counts: {
    cross_plugin_hooks: ($c[0] | length),
    documented_hooks: ($h[0] | length),
    overridable_templates: ($t[0] | length),
    rest_routes: ($r[0] | length),
    capabilities: ($p[0] | length),
    options: ($o[0] | length),
    wp_cli: ($l[0] | length),
    service_keys: ($s[0] | length),
    total: ($c[0] + $h[0] + $t[0] + $r[0] + $p[0] + $o[0] + $l[0] + $s[0] | length)
  }
}' > "$OUT_FILE"

echo "Wrote $OUT_FILE"
$JQ '.counts' "$OUT_FILE"
