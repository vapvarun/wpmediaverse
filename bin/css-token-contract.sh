#!/usr/bin/env bash
# bin/css-token-contract.sh — CSS token-contract gate.
#
# Catches the dark-mode / CSS bug class that functional journeys, WPCS, and
# PHPStan are all blind to (HTTP 200 + present DOM node != readable pixels):
#
#   (A) PHANTOM tokens — `var(--mvs-*)` used somewhere but never defined in any
#       :root / dark block. It silently freezes at the inline fallback and never
#       flips in dark mode (this is exactly the --mvs-chat-muted / --mvs-card-bg
#       / --mvs-bg-subtle bug class found 2026-06).
#
#   (B) DEAD dark-mode selectors — a stylesheet that targets [data-theme="dark"]
#       but NOT [data-bx-mode="dark"]. Reign + BuddyX + BuddyX Pro emit
#       data-bx-mode / .dark-mode, so a data-theme-only block never fires on any
#       Wbcom theme and dark mode silently doesn't apply (the IG / Pinterest /
#       Flickr layout bug found 2026-06).
#
# Runs from either plugin. When run from the Pro plugin it also reads the Free
# plugin's frontend.css for the canonical token definitions Pro consumes, so
# Free-owned tokens (--mvs-text etc.) are not reported as phantom.
#
# Exit 0 = clean; non-zero = at least one BLOCK violation (printed).

set -uo pipefail
cd "$(dirname "$0")/.."

GREEN="\033[0;32m"; RED="\033[0;31m"; RESET="\033[0m"

# Source CSS in scope — exclude minified + generated RTL (they mirror source).
# Portable read loop (no `mapfile` — macOS ships bash 3.2).
css_files=()
while IFS= read -r line; do
  [ -n "$line" ] && css_files+=("$line")
done < <(
  find assets/css src templates -type f -name '*.css' 2>/dev/null \
    | grep -vE '\.min\.css$|-rtl\.css$' \
    | sort
)
if [ "${#css_files[@]}" -eq 0 ]; then
  echo "css-token-contract: no source CSS found — nothing to check."
  exit 0
fi

# Files scanned for token DEFINITIONS = source CSS + Free's canonical token
# files. From the Free plugin the sibling path resolves to itself (deduped);
# from Pro it resolves to the Free plugin, so Free-owned tokens (--mvs-text …)
# that Pro consumes are not reported as phantom.
def_files=("${css_files[@]}")
for p in ../wpmediaverse/assets/css/frontend.css ../wpmediaverse/assets/css/messaging.css; do
  [ -f "$p" ] && def_files+=("$p")
done

# Intentional override-knob tokens: legitimately undefined in CSS because they
# are set inline by the block editor / JS (they always carry a fallback). Any
# OTHER used-but-undefined token is a bug.
ALLOW='^--mvs-(grid-gap|blur|overlay-opacity|container-width|pro-(padding|margin)-(tablet|mobile))$'

fail=0

# ── (A) Phantom tokens ───────────────────────────────────────────────────────
defined="$(
  grep -hoE -- '--mvs-[a-z0-9-]+[[:space:]]*:' "${def_files[@]}" 2>/dev/null \
    | grep -oE -- '--mvs-[a-z0-9-]+' | sort -u
)"
used="$(
  grep -hoE -- 'var\([[:space:]]*--mvs-[a-z0-9-]+' "${css_files[@]}" 2>/dev/null \
    | grep -oE -- '--mvs-[a-z0-9-]+' | sort -u
)"
phantom="$(comm -23 <(printf '%s\n' "$used") <(printf '%s\n' "$defined") | grep -vE "$ALLOW" || true)"

if [ -n "$phantom" ]; then
  while IFS= read -r tok; do
    [ -z "$tok" ] && continue
    printf "${RED}✗ PHANTOM token${RESET} %s — used via var() but never defined; frozen at fallback, never flips in dark mode.\n" "$tok"
    grep -rnE -- "var\([[:space:]]*$tok" "${css_files[@]}" 2>/dev/null | head -3 | sed 's/^/      /'
    fail=1
  done <<< "$phantom"
fi

# ── (B) Dead dark-mode selectors ─────────────────────────────────────────────
for f in "${css_files[@]}"; do
  if grep -qE '\[data-theme="dark"\]' "$f" && ! grep -qE '\[data-bx-mode="dark"\]' "$f"; then
    printf "${RED}✗ DEAD dark selector${RESET} %s — targets [data-theme=\"dark\"] but not [data-bx-mode=\"dark\"]; dead on BuddyX/Reign.\n" "$f"
    grep -nE '\[data-theme="dark"\]' "$f" | head -2 | sed 's/^/      /'
    fail=1
  fi
done

if [ "$fail" -eq 0 ]; then
  printf "${GREEN}✓${RESET} CSS token-contract clean — no phantom tokens, no dead dark-mode selectors (%d files).\n" "${#css_files[@]}"
fi
exit "$fail"
