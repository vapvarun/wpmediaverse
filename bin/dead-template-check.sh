#!/usr/bin/env bash
#
# Dead-template detector — flags template files that nothing references by name.
#
# A plugin template is "live" when its basename appears (as a string / include
# path / layout-map value) somewhere in includes/, templates/, or src/. Files
# loaded purely by name through TemplateLoader::locate(), include/require, or a
# Pro layout map are all caught because the basename is a literal in the call.
#
# Catches the bug class cleaned in 1.8.1: orphaned legacy templates that drift
# (miss fixes) because no code path reaches them. Scoped to templates/ — block
# render.php files are WP-registered via block.json and are out of scope.
#
# Exit 1 if any orphan is found (allowlist below for convention-loaded files).
set -uo pipefail
PLUGIN_DIR="${1:-$(pwd)}"
cd "$PLUGIN_DIR"
[ -d templates ] || { echo "no templates/ dir — skip"; exit 0; }

# Convention-loaded by the WP template hierarchy / theme, not by an in-code name.
ALLOWLIST="index.php"

orphans=0
while IFS= read -r f; do
  base="$(basename "$f")"
  case " $ALLOWLIST " in *" $base "*) continue;; esac
  hits="$(grep -rlF --include='*.php' --include='*.js' "$base" includes templates src 2>/dev/null | grep -vxF "$f" | wc -l | tr -d ' ')"
  if [ "$hits" -eq 0 ]; then
    echo "  ORPHAN: $f"
    orphans=$((orphans+1))
  fi
done < <(find templates -type f -name '*.php' | sort)

if [ "$orphans" -gt 0 ]; then
  echo "✗ dead-template-check: $orphans orphan template(s) — referenced by no include/locate/map. Remove them or wire an entry point."
  exit 1
fi
echo "✓ dead-template-check: no orphan templates"
