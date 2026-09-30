---
journey: gamification-settings
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-BST-004, MV-BST-013, MV-BST-014, gamification-settings, show_when, points-backend-dependency]
prerequisites:
  - "Both plugins active"
  - "Auto-login mu-plugin available"
  - "WP-CLI available"
estimated_runtime_minutes: 8
---

# The Competitions settings page's show_when chain hides/shows the right rows, and the points bridge dispatches correctly with or without WB Gamification

**Why this journey exists**: `admin/02-feature-toggles-gate-all-routes.md` already
proves the five gamification toggles gate their own ADMIN PAGE and REST ROUTE.
This journey proves the *settings-row visibility* half those routes don't
touch — the `show_when` dependency chain in `GamificationSettings.php` (master
-> sub-toggle -> conditional numeric fields), the third independent
`points_backend_available()` gate specific to Boosts/streak-freezes, and that
hidden rows never lose their stored value. It also proves `CompetePointsBridge`
dispatches by action id correctly whether or not WB Gamification is installed —
the general contract `customer/03-battle-win-xp-configured.md` only proves for
the single `mvs_battle_win` case.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.
- Settings screen: `admin.php?page=mvs-settings#gamification`.
- Options: `mvs_competitions_enabled`, `mvs_boosts_enabled`, `mvs_battles_enabled`, `mvs_streaks_enabled`, `mvs_streak_freezes_enabled`.

## Steps

### 1. Master OFF hides every sub-row, not just disables it
- **Action**: `wp option update mvs_competitions_enabled 0`; load the settings screen.
- **Expect**: only the "Competitions" master checkbox row renders — Photo Battles / Photo Challenges / Tournaments / Media Boosts rows are absent from the DOM entirely (`show_when: mvs_competitions_enabled`), not merely greyed out.
- **On fail**: `includes/Admin/GamificationSettings.php` line ~178.

### 2. Hidden sub-toggle values survive a master-off/on cycle
- **Action**: with master ON, tick Media Boosts and Photo Battles, save. Turn master OFF, save, reload, turn master back ON, save, reload.
- **Expect**: Boosts and Battles checkboxes show their PRE-existing checked state, not reset to unchecked — stored values survive being withheld from the form.
- **On fail**: sanitizer clearing sub-option values when the master is off.

### 3. Boosts requires BOTH the master AND its own sub-toggle
- **Action**: `wp eval` sequence: (a) master off, boosts on via direct option update -> check `competition_feature_on('boosts')`; (b) master on, boosts off -> check again; (c) both on -> check again.
- **Expect**: (a) false, (b) false, (c) true only.
- **On fail**: `Plugin::competition_feature_on()`.

### 4. `points_backend_available()` is a third, independent gate
- **Action**: with both switches ON, deactivate/rename WB Gamification.
- **Expect**: the Boost button disappears from the feed even though both switches remain ON.
- **On fail**: wherever the boost button template checks `points_backend_available()`.

### 5. Boost Pricing section only shows when Boosts is ON
- **Action**: toggle Media Boosts off, save, reload; observe the Boost Pricing card (Points per 100 Impressions / Max Impressions per Boost / Boost Expiry Days).
- **Expect**: card absent when Boosts is off; present with sane non-zero defaults (50/5000/7) when on — never $0 cost or unlimited impressions on first enable.

### 6. Upload Streaks section is independent of the Competitions master
- **Action**: with `mvs_competitions_enabled = 0`, enable `mvs_streaks_enabled = 1`.
- **Expect**: Upload Streaks fields render and save regardless of the competitions master's state; the Competitions Dashboard admin screen shows an "Active Streaks" panel even with zero competition features on.
- **On fail**: `GamificationSettings.php` — Streaks section wrongly nested under the competitions `show_when`.

### 7. Streak Freezes chain: Enable Streaks -> Allow Freezes -> Freeze Cost
- **Action**: with Streaks off, confirm Allow Streak Freezes row is absent. Turn Streaks on, confirm Allow Freezes appears (default off). Turn Freezes on, confirm Freeze Cost appears (default 100).
- **Expect**: exact three-level `show_when` chain holds; defaults are sane non-zero (100).

### 8. 390px settings usability
- **Action**: `playwright_resize 390 844`; open the Competitions tab with several conditional rows visible.
- **Expect**: form fields and the conditional show/hide JS work without breaking layout; no horizontal scroll.

### 9. Points bridge dispatches only MediaVerse's own action ids
- **Action**: with WB Gamification active, `wp eval 'add_filter("wb_gam_points_for_action", function($pts,$action,$meta){ error_log("bridge:$action=$pts"); return $pts; }, 99, 3);'`; trigger a streak milestone (any of `mvs_challenge_winner`, `mvs_challenge_participate`, `mvs_tournament_round_win`, `mvs_tournament_win`, `mvs_battle_win`, `mvs_streak_milestone`) and a WHOLLY UNRELATED WB Gamification action (e.g. a forum post, if available).
- **Expect**: the MediaVerse action id resolves to the owner-configured/snapshotted amount via `resolve_points()`; the unrelated action's points pass through completely untouched (bridge does not intercept it).
- **On fail**: `includes/Gamification/CompetePointsBridge.php::resolve_points()`.

### 10. Bridge is a no-op with WB Gamification absent — nothing errors
- **Action**: deactivate/rename WB Gamification entirely; trigger a streak milestone and a battle win.
- **Expect**: no PHP error anywhere; the point-spending UI (Boost button, Buy Freeze) simply hides; a forced call to the underlying REST route 503s cleanly rather than fataling.

### 11. Malformed metadata falls through safely
- **Action**: `wp eval` fire `mvs_battle_win` (or another mapped action) with missing/malformed `$meta` directly against `CompetePointsBridge::resolve_points()`.
- **Expect**: returns the original flat `$points` unchanged — never zero, never a fatal.
- **On fail**: `CompetePointsBridge.php` — missing a defensive fallback.

## Pass criteria

ALL of the following hold:
1. The `show_when` chain hides/shows rows exactly per the master -> sub-toggle -> conditional-field hierarchy, at every level (Competitions, Streaks independently).
2. Hidden sub-toggle values are preserved, never reset, across a master-off/on cycle.
3. Boosts requires both `mvs_competitions_enabled` AND `mvs_boosts_enabled`; `points_backend_available()` is a genuinely independent third gate.
4. Settings form works at 390px.
5. `CompetePointsBridge` dispatches only its six named action ids and passes every other action through unchanged, with or without WB Gamification present, and never errors on malformed metadata.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Sub-rows show while master is off | `show_when` not applied to that row's field config | `includes/Admin/GamificationSettings.php` |
| Sub-toggle resets to unchecked after a master off/on cycle | sanitizer clearing the value when master is off | `GamificationSettings.php` (save handler) |
| Boost button live with WB Gamification absent | `points_backend_available()` not checked at render time | wherever the boost button template renders |
| Streaks fields gated behind the competitions master | wrong `show_when` key on the Streaks section | `GamificationSettings.php` |
| An unrelated WB Gamification action gets altered | bridge matching too broadly (no action-id whitelist) | `includes/Gamification/CompetePointsBridge.php` |
