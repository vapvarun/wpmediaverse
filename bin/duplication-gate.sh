#!/usr/bin/env bash
# bin/duplication-gate.sh — fail when a NEW near-duplicate code cluster lands.
#
# Wraps bin/cleanup-duplicate-detect.php (normalized method-body hashing across
# Free + Pro) with a frozen baseline, phpstan-baseline style: the duplicate
# clusters that exist today are accepted debt; any cluster hash NOT in the
# baseline fails the build. This is what stops "reinventing the same section
# again" from landing — you either reuse the shared helper/partial, or the gate
# goes red and points at the new copy-paste.
#
# Baseline: audit/cleanup/duplicates-baseline.txt (one cluster hash per line).
# After an intentional change (a real consolidation that removes clusters, or a
# new duplicate you accept), re-bless it:  composer dup-gate:bless
#
# Exit 0 = no new duplicates; 1 = new duplicate(s); 2 = setup error.

set -uo pipefail
cd "$(dirname "$0")/.."

BASELINE="audit/cleanup/duplicates-baseline.txt"
JSON="audit/cleanup/duplicates.json"

php bin/cleanup-duplicate-detect.php >/dev/null 2>&1 || { echo "duplication-gate: detector failed to run"; exit 2; }
[ -f "$JSON" ] || { echo "duplication-gate: $JSON not produced"; exit 2; }

if [ "${1:-}" = "--bless" ]; then
  python3 - "$JSON" "$BASELINE" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
hashes = sorted({c['hash'] for k in ('cross_plugin_duplicates', 'within_plugin_duplicates') for c in d.get(k, [])})
open(sys.argv[2], 'w').write('\n'.join(hashes) + '\n')
print(f"duplication-gate: blessed {len(hashes)} clusters into {sys.argv[2]}")
PY
  exit 0
fi

[ -f "$BASELINE" ] || { echo "duplication-gate: no baseline at $BASELINE — run 'composer dup-gate:bless'"; exit 2; }

python3 - "$JSON" "$BASELINE" <<'PY'
import json, sys
d = json.load(open(sys.argv[1]))
baseline = {h.strip() for h in open(sys.argv[2]) if h.strip()}
clusters = [c for k in ('cross_plugin_duplicates', 'within_plugin_duplicates') for c in d.get(k, [])]
new = [c for c in clusters if c['hash'] not in baseline]
if not new:
    print(f"\033[0;32m✓\033[0m duplication-gate: no new duplicate clusters ({len(clusters)} baselined).")
    sys.exit(0)
print(f"\033[0;31m✗ duplication-gate: {len(new)} NEW duplicate cluster(s)\033[0m — reuse a shared helper/partial instead of copy-pasting, or bless if intentional:")
for c in new:
    sites = "; ".join(f"{s.get('plugin','free')}:{s.get('file','?')}:{s.get('lines','?')} ({s.get('method','')})" for s in c.get('sites', []))
    print(f"    [{c.get('token_count','?')} tokens x{c.get('instances','?')}] {sites}")
sys.exit(1)
PY
