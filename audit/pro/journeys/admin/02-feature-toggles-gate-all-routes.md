---
journey: feature-toggles-gate-all-routes
plugin: wpmediaverse-pro
priority: critical
roles: [administrator]
covers: [feature-toggle, mvs_battles_enabled, battles-toggle, challenges-toggle, tournaments-toggle, streaks-toggle, boosts-toggle, registration-gating]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "WPMediaVerse 2.0.0+ (free) and WPMediaVerse Pro 2.0.0+ active"
estimated_runtime_minutes: 6
---

# Pro gamification feature toggles gate their admin page and REST route

**Why this journey exists**: `mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_streaks_enabled`, and `mvs_boosts_enabled` are REGISTRATION-gated in `includes/Core/Plugin.php` — `register_rest_route()` and `add_menu_page()` only run when the option is `'1'`. Because the route does not exist while the feature is off, the cert contract (flip-and-dispatch) cannot prove them: flipping the option at cert time cannot re-register a route within the same request. This journey is their enforcement proof. For each toggle it asserts that OFF removes BOTH the admin page and the `mvs-pro/v1` route in the same request cycle, and ON restores them. (The cert's boot smoke covers them transitively: when ON, the routes are live-discovered and dispatched.)

This is the single, comprehensive toggle-gating journey for all five gamification features; it supersedes the earlier battles-only journey (it still `covers:` the `feature-toggle` / `mvs_battles_enabled` tags the battles-only journey carried).

## Setup

- Site: `$SITE_URL`
- User: `admin` (autologin via `?autologin=1`)
- Settings screen: `admin.php?page=mvs-gamification`
- Toggle -> route map:
  - `mvs_battles_enabled` -> `GET /wp-json/mvs-pro/v1/battles`
  - `mvs_challenges_enabled` -> `GET /wp-json/mvs-pro/v1/challenges`
  - `mvs_tournaments_enabled` -> `GET /wp-json/mvs-pro/v1/tournaments`
  - `mvs_streaks_enabled` -> `GET /wp-json/mvs-pro/v1/streaks/me`
  - `mvs_boosts_enabled` -> `GET /wp-json/mvs-pro/v1/boosts`

## Steps (repeat per toggle)

### 1. Auto-login as admin
- **Action**: `playwright_navigate $SITE_URL/wp-admin/?autologin=1`
- **Expect**: wp-admin dashboard.

### 2. Enable the toggle and confirm route + page exist
- **Action**: open `admin.php?page=mvs-gamification`, check the toggle, submit; then `GET /wp-json/mvs-pro/v1/<route>`.
- **Expect**: 302 -> "Settings saved."; DB option == `1`; the route responds with a 2xx/4xx (NOT `rest_no_route`); the feature's admin submenu is present.

### 3. Disable the toggle and confirm route + page are gone
- **Action**: uncheck the toggle; submit; reload; then `GET /wp-json/mvs-pro/v1/<route>` in a fresh request.
- **Expect**: DB option == `0`; the route returns 404 `rest_no_route` (it is not registered); the feature's admin submenu is absent.

### 4. Confirm persistence
- **Action**: `mysql_query "SELECT option_value FROM wp_options WHERE option_name='<toggle>'"`.
- **Expect**: matches the last saved state byte-for-byte.

## Pass criteria

For EACH of the five toggles, ALL hold:
1. The option persists to `wp_options` across save + reload.
2. With the toggle ON, the mapped `mvs-pro/v1` route is registered (not `rest_no_route`) and the admin submenu shows.
3. With the toggle OFF, the mapped route returns `rest_no_route` and the admin submenu is hidden — in the SAME request cycle.
4. No 500 on any save or dispatch.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Route still answers while toggle OFF | `register_rest_route` not guarded by the option | `includes/Core/Plugin.php` (search the option name) |
| Admin submenu shows while toggle OFF | `add_menu_page`/`add_submenu_page` not guarded | `includes/Admin/GamificationSettings.php` |
| Toggle reverts after reload | Sanitizer overwritten / duplicate `register_setting()` | `includes/Admin/GamificationSettings.php` |
| Route 500 while ON | Controller fataled on boot | the feature's REST controller under `includes/` |
