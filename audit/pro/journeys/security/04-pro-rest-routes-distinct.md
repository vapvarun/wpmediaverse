---
journey: pro-rest-routes-distinct
plugin: wpmediaverse-pro
priority: high
roles: [anonymous]
covers: [arch-invariant-5, rest-namespace-isolation]
prerequisites:
  - "Both plugins active"
estimated_runtime_minutes: 1
---

# Pro REST routes live in their own mvs-pro/v1 namespace, fully separate from Free's mvs/v1

**Why this journey exists**: Architecture invariant 5 (rest namespace isolation) — Free registers under `mvs/v1` and Pro registers under the separate `mvs-pro/v1` namespace; the two namespaces share zero route keys, so there can be no collision. This journey calls one Pro-only route (`mvs-pro/v1`) + one Free-only route (`mvs/v1`), asserts each reaches its expected handler, and confirms the two route-key sets are disjoint.

## Steps

### 1. Hit Free-only route /media
- **Action**: `curl -s $SITE_URL/wp-json/mvs/v1/media | python3 -c 'import json,sys; d=json.load(sys.stdin); print(type(d).__name__)'`
- **Expect**: list-shaped response (Free MediaController list).

### 2. Hit Pro-only route /battles (toggle ON first)
- **Action**: `wp option update mvs_battles_enabled 1` then `curl $SITE_URL/wp-json/mvs-pro/v1/battles`
- **Expect**: 200 with battles list (Pro BattleController).

### 3. Enumerate both namespaces, confirm route keys are disjoint
- **Action**: `curl -s $SITE_URL/wp-json/mvs/v1 | python3 -c "import json,sys; print('\n'.join(json.load(sys.stdin).get('routes',{}).keys()))" > /tmp/free.txt; curl -s $SITE_URL/wp-json/mvs-pro/v1 | python3 -c "import json,sys; print('\n'.join(json.load(sys.stdin).get('routes',{}).keys()))" > /tmp/pro.txt; comm -12 <(sort /tmp/free.txt) <(sort /tmp/pro.txt)`
- **Expect**: `comm -12` prints nothing — zero route keys shared between `mvs/v1` and `mvs-pro/v1`.

## Pass criteria

Each route reaches the correct plugin's handler, and the `mvs/v1` (Free) and `mvs-pro/v1` (Pro) route-key sets are disjoint (zero collisions).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A route key appears in both namespaces | A controller registered into the wrong namespace | `bin/architecture-checks.sh` (invariant 5 check) |
| /battles returns 404 under mvs-pro/v1 | Pro toggle off OR battles route deregistered | `includes/Battles/BattleController.php` |
