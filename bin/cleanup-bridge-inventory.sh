#!/usr/bin/env bash
# bin/cleanup-bridge-inventory.sh
#
# Builds audit/cleanup/bridges.json — the canonical "what cannot be silently
# removed" list for this Free+Pro pair. Every cleanup plan MUST check
# candidate symbols against this file before deletion.
#
# Source of truth: the manifest. Phase 2 enumeration already collects REST
# routes, capabilities, services, settings, hooks, WP-CLI, etc. — re-greping
# the codebase for those categories duplicates work and is brittle (multi-
# line call sites, escape quirks). This script reads audit/manifests/*.json
# and the cross-plugin coupling map. If the manifest is stale, refresh it
# via /wp-plugin-onboard --refresh first.
#
# A symbol is a BRIDGE when it appears in ANY of:
#   1. cross-plugin coupling map (Pro consumes Free's hook)
#   2. REST endpoint enumeration (external API)
#   3. capability registry (role plugins read these)
#   4. settings registry (devops scripts / migrations set these)
#   5. hooks_fired with consumers (@since-documented in manifest)
#   6. WP-CLI command list (automation scripts depend)
#   7. service container key list (Pro + mu-plugins resolve via container)
#   8. templates/ files (theme can override via locate_template)
#
# Output: audit/cleanup/bridges.json

set -euo pipefail

FREE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$FREE_DIR/audit/cleanup"
OUT_FILE="$OUT_DIR/bridges.json"

mkdir -p "$OUT_DIR"

JQ="${JQ:-jq}"
command -v "$JQ" > /dev/null || { echo "jq required"; exit 1; }

MANIFEST="$FREE_DIR/audit/manifests/manifest.json"
HOOKS_FILE="$FREE_DIR/audit/manifests/manifest.hooks.json"
REST_FILE="$FREE_DIR/audit/manifests/manifest.rest.json"
CROSS_FILE="$FREE_DIR/audit/derived/cross-plugin-coupling.json"

for f in "$MANIFEST" "$HOOKS_FILE" "$REST_FILE"; do
    [ -f "$f" ] || { echo "Missing $f — run /wp-plugin-onboard --refresh first"; exit 1; }
done

TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT

# ---------------------------------------------------------------------------
# 1. Cross-plugin bridges (Free hooks consumed by Pro)
#    Shape: .result.free_hooks_consumed_by_pro is an array of
#    { hook, plugin, where, kind }. Group by hook so each bridge entry
#    lists every Pro listener for that hook.
# ---------------------------------------------------------------------------
if [ -f "$CROSS_FILE" ]; then
    $JQ '[(.result.free_hooks_consumed_by_pro // []) | group_by(.hook) | .[] |
          { type: "cross_plugin_hook", symbol: .[0].hook,
            provenance: "Pro listens via add_filter/add_action",
            consumed_by: [.[] | "\(.plugin):\(.where) (\(.kind))"] }]' \
        "$CROSS_FILE" > "$TMP/cross.json" 2>/dev/null \
       || echo "[]" > "$TMP/cross.json"
else
    echo "[]" > "$TMP/cross.json"
fi

# ---------------------------------------------------------------------------
# 2. Documented hooks (manifest hooks_fired)
# ---------------------------------------------------------------------------
$JQ '[.hooks_fired[]? | { type: "documented_hook", symbol: .name,
       provenance: ("manifest hooks_fired (\(.type)) — " + (.where // "unknown")),
       args_signature: (.args_signature // []) }]' \
    "$HOOKS_FILE" > "$TMP/hooks.json" 2>/dev/null || echo "[]" > "$TMP/hooks.json"

# ---------------------------------------------------------------------------
# 3. Overridable templates (everything in templates/ is theme-overridable
#    via locate_template by convention in WPMediaVerse)
# ---------------------------------------------------------------------------
find "$FREE_DIR/templates" -name "*.php" -not -path "*/dist/*" 2>/dev/null \
    | sed "s|$FREE_DIR/||" | sort \
    | while read -r tpl; do
        [ -z "$tpl" ] && continue
        echo "{\"type\":\"overridable_template\",\"symbol\":\"$tpl\",\"provenance\":\"templates/ directory — themes override via locate_template by convention\"}"
    done | $JQ -s '.' > "$TMP/templates.json" 2>/dev/null || echo "[]" > "$TMP/templates.json"

# ---------------------------------------------------------------------------
# 4. REST endpoints
# ---------------------------------------------------------------------------
$JQ '[.rest.endpoints[]? | { type: "rest_route", symbol: .route,
       provenance: ("REST endpoint — " + (.methods // [] | join("|")) + " — external API"),
       methods: (.methods // []) }]' \
    "$REST_FILE" > "$TMP/rest.json" 2>/dev/null || echo "[]" > "$TMP/rest.json"

# ---------------------------------------------------------------------------
# 5. Capabilities
# ---------------------------------------------------------------------------
$JQ '[.capabilities[]? | { type: "capability", symbol: .cap,
       provenance: "manifest capabilities — role plugins read these",
       default_roles: (.default_roles // []) }]' \
    "$MANIFEST" > "$TMP/caps.json" 2>/dev/null || echo "[]" > "$TMP/caps.json"

# ---------------------------------------------------------------------------
# 6. Options (settings)
# ---------------------------------------------------------------------------
$JQ '[.settings[]? | { type: "option", symbol: .key,
       provenance: ("register_setting — group=" + (.group // "?") + " — devops/migration scripts set this"),
       default: .default }]' \
    "$MANIFEST" > "$TMP/options.json" 2>/dev/null || echo "[]" > "$TMP/options.json"

# ---------------------------------------------------------------------------
# 7. WP-CLI commands
# ---------------------------------------------------------------------------
$JQ '[.wp_cli[]? | { type: "wp_cli", symbol: .command,
       provenance: ("@subcommand — " + (.purpose // "")) }]' \
    "$MANIFEST" > "$TMP/cli.json" 2>/dev/null || echo "[]" > "$TMP/cli.json"

# ---------------------------------------------------------------------------
# 8. Service container keys
# ---------------------------------------------------------------------------
$JQ '[.services[]? | { type: "service_key", symbol: .key,
       provenance: ("container->register('" + .key + "') — Pro+mu-plugins resolve via container->get") }]' \
    "$MANIFEST" > "$TMP/services.json" 2>/dev/null || echo "[]" > "$TMP/services.json"

# ---------------------------------------------------------------------------
# Merge
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
  source: "audit/manifests/*.json + audit/derived/cross-plugin-coupling.json",
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
