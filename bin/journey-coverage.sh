#!/usr/bin/env bash
# bin/journey-coverage.sh — every REQUIRED feature must have a journey.
#
# Reads audit/journeys/REQUIRED-COVERS.txt (the critical features a release must
# never ship without an executable journey) and asserts each `covers:` tag is
# claimed by at least one journey's frontmatter, across Free (audit/journeys)
# and Pro (audit/pro/journeys).
#
# The coverage half of the regression defense:
#   1.4 css-token-contract  — no phantom tokens / dead dark selectors
#   1.5 duplication gate     — no new copy-paste
#   1.6 journey coverage     — no critical feature without a journey  (this file)
#   4.1 run-journeys         — the journeys actually pass end-to-end
#
# A dropped feature (the boost-drop class) makes its journey's assertions fail at
# 4.1; a *deleted or never-written* journey is caught here at 1.6.
#
# Exit 0 = every required feature has a journey; 1 = one missing; 2 = setup error.

set -uo pipefail
cd "$(dirname "$0")/.."

REQUIRED="audit/journeys/REQUIRED-COVERS.txt"
GREEN="\033[0;32m"; RED="\033[0;31m"; RESET="\033[0m"

[ -f "$REQUIRED" ] || { echo "journey-coverage: no $REQUIRED — nothing required."; exit 0; }

# Every `covers:` tag claimed by any journey (Free + Pro).
claimed="$(
  grep -rhE '^covers:' audit/journeys audit/pro/journeys 2>/dev/null \
    | sed -E 's/^covers:[[:space:]]*\[?//; s/\].*$//; s/,/ /g' \
    | tr ' ' '\n' | grep -v '^$' | sort -u
)"

missing=0
while IFS= read -r raw; do
  tag="$(printf '%s' "$raw" | sed 's/#.*//' | tr -d '[:space:]')"
  [ -z "$tag" ] && continue
  if ! printf '%s\n' "$claimed" | grep -qx "$tag"; then
    printf "${RED}✗ journey-coverage: required feature '%s' has NO journey${RESET} — add one covering it under audit/journeys/ or audit/pro/journeys/.\n" "$tag"
    missing=1
  fi
done < "$REQUIRED"

if [ "$missing" -eq 0 ]; then
  req_count="$(grep -vcE '^[[:space:]]*(#|$)' "$REQUIRED")"
  printf "${GREEN}✓${RESET} journey-coverage: all %s required features have a journey.\n" "$req_count"
fi
exit "$missing"
