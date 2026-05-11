---
journey: feature-toggle-gates-battles
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [feature-toggle, mvs_battles_enabled]
prerequisites:
  - "Both plugins active"
  - "Auto-login mu-plugin available"
estimated_runtime_minutes: 3
---

# Disabling mvs_battles_enabled removes Battles admin page + REST routes

## Steps

### 1. Toggle ON, verify page exists
- **Action**: `wp option update mvs_battles_enabled 1` then `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse-battles`
- **Expect**: page renders, no "permission denied".

### 2. Verify REST route registered
- **Action**: `curl $SITE_URL/wp-json/mvs/v1/battles`
- **Expect**: HTTP 200 (empty array OK).

### 3. Toggle OFF
- **Action**: `wp option update mvs_battles_enabled 0`

### 4. Verify admin page gone
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse-battles`
- **Expect**: WordPress "you do not have sufficient permissions" or 404 (page deregistered).

### 5. Verify REST route gone
- **Action**: `curl -i $SITE_URL/wp-json/mvs/v1/battles`
- **Expect**: HTTP 404 with `rest_no_route`.

### 6. Restore
- **Action**: `wp option update mvs_battles_enabled 1`

## Pass criteria

Disabling the toggle removes both the admin page AND the REST route in the same request cycle.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| REST route still 200 after toggle off | `register_rest_route` runs unconditionally | `includes/Battles/BattleController.php` constructor wiring in `Plugin::init()` |
| Admin page still visible | `add_menu_page` not gated | `includes/Core/Plugin.php` (battles bootstrap block) |
