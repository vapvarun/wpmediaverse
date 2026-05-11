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

# Pro REST routes share the mvs/v1 namespace but never collide with Free's routes

**Why this journey exists**: Architecture invariant 5 (rest namespace isolation) — both plugins MAY share `mvs/v1` namespace but no two routes may have colliding `methods × path` tuples. This journey calls one Pro-only route + one Free-only route, asserts both reach their expected handlers.

## Steps

### 1. Hit Free-only route /media
- **Action**: `curl -s $SITE_URL/wp-json/mvs/v1/media | python3 -c 'import json,sys; d=json.load(sys.stdin); print(type(d).__name__)'`
- **Expect**: list-shaped response (Free MediaController list).

### 2. Hit Pro-only route /battles (toggle ON first)
- **Action**: `wp option update mvs_battles_enabled 1` then `curl $SITE_URL/wp-json/mvs/v1/battles`
- **Expect**: 200 with battles list (Pro BattleController).

### 3. Enumerate all routes, look for collisions
- **Action**: `curl $SITE_URL/wp-json/mvs/v1 | python3 -c "import json,sys; d=json.load(sys.stdin); routes=d.get('routes',{}); print('total:',len(routes))"`
- **Expect**: total prints; no duplicate route keys.

## Pass criteria

Each route reaches the correct plugin's handler; no method×path overlap.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Pro route returns Free's handler response | Both registered the same route; later wins | `bin/architecture-checks.sh` (invariant 5 check) |
| /battles returns 404 | Pro toggle off OR battles route deregistered | `includes/Battles/BattleController.php` |
