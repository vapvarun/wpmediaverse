#!/usr/bin/env bash
# bin/template-style-check.sh — no STATIC inline styles / hardcoded hex in markup.
#
# The CSS gates (css-token-contract) scan .css files; they are blind to inline
# style="…" attributes and hardcoded hex inside PHP templates / block render.php /
# markup-echoing classes — the class of "unprofessional UX" issue (inline
# margin/color/font, #666 footers) that slips past WPCS + the CSS gate and only
# gets caught by eye. This gate closes that hole.
#
# A style="…" attribute is a VIOLATION when it is STATIC (no <?php interpolation)
# and sets visual CSS (color/background/margin/padding/font/display/border/
# text-align/box-shadow/width:<literal>). DYNAMIC styles (style="width:<?php …%>"),
# and attributes that only set --mvs-* custom properties (block editor responsive
# controls), are allowed. Hardcoded hex inside any inline style is always flagged.
#
# Exit 0 = clean; 1 = violations (printed file:line); 2 = setup error.

set -uo pipefail
cd "$(dirname "$0")/.."

# Scan markup: templates, block render.php, and includes that echo HTML.
roots=( templates src/blocks includes )
files=()
while IFS= read -r f; do
  [ -n "$f" ] && files+=( "$f" )
done < <(
  find "${roots[@]}" -type f -name '*.php' 2>/dev/null \
    | grep -vE '/(vendor|node_modules|tests)/' | sort
)
[ "${#files[@]}" -eq 0 ] && { echo "template-style-check: no PHP markup found."; exit 0; }

python3 - "${files[@]}" <<'PY'
import re, sys

GREEN = "\033[0;32m"; RED = "\033[0;31m"; RESET = "\033[0m"

# style="..." capturing the value (no embedded double-quote).
ATTR = re.compile(r'style="([^"]*)"')
# Static visual properties that belong in a stylesheet. Each base word also
# matches its CSS longhands (margin-top, padding-left, border-bottom,
# font-size, font-weight, background-color, ...) via the optional -suffix —
# `\bmargin\b\s*:` alone would miss `margin-top:` and let it slip through.
# NOTE: pure display:(none|block|...) toggles are allowed earlier (TOGGLE_ONLY),
# so listing `display` here only catches display mixed with other declarations.
VISUAL = re.compile(
    r'\b(color|background|margin|padding|font|border|text-align|box-shadow|line-height|letter-spacing|gap|vertical-align|display|flex|align-items|justify-content|object-fit|text-decoration)'
    r'(-[a-z]+)*\s*:',
    re.I,
)
WIDTH_LITERAL = re.compile(r'\b(width|height)\s*:\s*\d')   # literal px/%, not a var/computed
HEX = re.compile(r'#[0-9a-fA-F]{3,8}\b')
# A hex inside a var() fallback — var(--mvs-x, #e5e5e5) — IS the tokenized
# pattern (token first, literal only as last-resort fallback), so it is allowed.
# Only a bare hex outside any var() is a hardcoded color.
VAR_CALL = re.compile(r'var\([^)]*\)')

def has_bare_hex(val):
    return bool(HEX.search(VAR_CALL.sub('', val)))
# A pure visibility toggle is BEHAVIORAL, not cosmetic: a JS / Interactivity-API
# initial hidden|shown state (data-wp-bind--hidden, classList toggles). When the
# style sets ONLY display:(none|block|flex|...) and nothing else, it is allowed —
# a hidden element has no appearance, so it is not "unprofessional inline CSS".
# Mixed layout (display:flex;gap:12px;padding:..) is NOT a toggle and stays flagged.
TOGGLE_ONLY = re.compile(r'^\s*display\s*:\s*(none|block|flex|inline|inline-block|inline-flex|grid)\s*;?\s*$', re.I)

violations = []
for path in sys.argv[1:]:
    try:
        lines = open(path, encoding='utf-8', errors='replace').read().splitlines()
    except OSError:
        continue
    for n, line in enumerate(lines, 1):
        for m in ATTR.finditer(line):
            val = m.group(1)
            if not val.strip():
                continue
            # Dynamic: PHP-computed value -> the computed parts are allowed
            # (progress bars, author-set sizes), BUT a LITERAL hex baked into the
            # source is never OK even in a dynamic style — flag it and move on.
            if '<?php' in val or '<?=' in val:
                if has_bare_hex(val):
                    violations.append((path, n, 'hardcoded hex', val.strip()[:80]))
                continue
            # Custom-property-only (editor responsive controls) -> allowed.
            stripped = re.sub(r'--mvs-[a-z0-9-]+\s*:[^;]*;?', '', val).strip()
            if stripped == '':
                continue
            # Pure visibility toggle (no hex, no other props) -> behavioral, allowed.
            if not has_bare_hex(val) and TOGGLE_ONLY.match(stripped):
                continue
            has_hex = has_bare_hex(val)
            if has_hex or VISUAL.search(val) or WIDTH_LITERAL.search(val):
                kind = 'hardcoded hex' if has_hex else 'static inline style'
                violations.append((path, n, kind, val.strip()[:80]))

if not violations:
    print(f"{GREEN}✓{RESET} template-style-check: no static inline styles or hardcoded hex in markup.")
    sys.exit(0)

print(f"{RED}✗ template-style-check: {len(violations)} static inline style(s) / hex in markup{RESET} — move to a tokenized CSS class:")
for path, n, kind, snippet in violations:
    print(f"    {path}:{n}  [{kind}]  style=\"{snippet}\"")
sys.exit(1)
PY
