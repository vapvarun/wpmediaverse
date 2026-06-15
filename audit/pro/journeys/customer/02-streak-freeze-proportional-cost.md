---
journey: streak-freeze-proportional-cost
plugin: wpmediaverse-pro
priority: high
roles: [member]
covers: [streaks, freeze-cost, atomic-debit, buy-freeze, 9966423677]
prerequisites:
  - "Both plugins active; mvs_streaks_enabled = 1; mvs_streak_freezes_enabled = 1"
  - "WB Gamification points engine available"
  - "Auto-login mu-plugin available"
estimated_runtime_minutes: 5
---

# Streak freeze costs one freeze per missed day, and freeze purchase debits atomically

**Why this journey exists**: Before 1.7.0, a single freeze bridged any gap — a 5-day absence cost the same one freeze as a 1-day absence, so streaks were trivially un-losable for anyone holding a single freeze. The fix (`StreakService.php:142`) computes `missed_days = max(1, gap_days - 1)` and requires `freezes >= missed_days` to preserve the streak, consuming one freeze per missed day. If the member doesn't hold enough freezes, the streak resets. Separately, the freeze *purchase* must be atomic: insufficient points returns HTTP 400 with no freeze granted (no partial state where a freeze is added but points weren't debited). This journey locks both: proportional consumption AND atomic purchase. The journey IS the regression test. (Basecamp 9966423677; `FLOW-DATA-AUDIT-pro-2026-06-15.md` TC-1.7.0-B + TC-1.6.0-B.)

## Setup

- Site: `$SITE_URL`
- Member: `<member>` (autologin `?autologin=<member>`); capture `UID`.
- Usermeta keys: `_mvs_current_streak`, `_mvs_streak_freezes`, `_mvs_last_upload_date`.
- Option: `mvs_pro_streak_freeze_cost` (points per freeze).

## Steps

### 1. Seed: 1 freeze, last upload yesterday, then a 6-day gap (5 missed days)
- **Action**:
  ```bash
  wp user meta update $UID _mvs_current_streak 7
  wp user meta update $UID _mvs_streak_freezes 1
  wp user meta update $UID _mvs_last_upload_date "$(date -v-6d +%Y-%m-%d 2>/dev/null || date -d '6 days ago' +%Y-%m-%d)"
  ```
- **Expect**: meta set. `gap_days = 6`, so `missed_days = max(1, 6-1) = 5`.
- **On fail**: env date math — adjust the gap date.

### 2. Upload today — insufficient freezes (1 < 5) → streak resets
- **Action**: upload one media item as the member (`POST /mvs/v1/media`), firing `mvs_media_uploaded` → `StreakService::on_upload()`.
- **Expect**:
  - `wp user meta get $UID _mvs_current_streak` → **1** (reset to a fresh single-day streak — NOT preserved at 8).
  - `wp user meta get $UID _mvs_streak_freezes` → **1** (unchanged — insufficient, so no deduction; freezes are NOT partially consumed).
- **On fail**: `includes/Streaks/StreakService.php::on_upload()` — the `freezes >= missed_days` guard is missing or the old "1 freeze bridges any gap" path is back.

### 3. Seed: 3 freezes, last upload yesterday, then a 4-day gap (3 missed days)
- **Action**:
  ```bash
  wp user meta update $UID _mvs_current_streak 10
  wp user meta update $UID _mvs_streak_freezes 3
  wp user meta update $UID _mvs_last_upload_date "$(date -v-4d +%Y-%m-%d 2>/dev/null || date -d '4 days ago' +%Y-%m-%d)"
  ```
- **Expect**: `gap_days = 4`, `missed_days = max(1, 4-1) = 3`, `freezes (3) >= missed_days (3)`.

### 4. Upload today — enough freezes → streak preserved, 3 freezes consumed
- **Action**: upload one media item as the member.
- **Expect**:
  - `_mvs_current_streak` → incremented/preserved (NOT reset to 1).
  - `_mvs_streak_freezes` → **0** (exactly 3 consumed — one per missed day, not just 1).
- **On fail**: `includes/Streaks/StreakService.php:142-146` — `missed_days` consumption is flat (subtracting 1) instead of `freezes - missed_days`.

### 5. Freeze purchase with sufficient points — atomic debit succeeds
- **Action**: set `wp option update mvs_pro_streak_freeze_cost 100`; grant the member 150 points via the WB Gamification engine; `curl -X POST -H 'X-WP-Nonce: $NONCE' $SITE_URL/wp-json/mvs-pro/v1/streaks/buy-freeze`.
- **Expect**: HTTP **200**; `freezes` incremented by 1; points balance now **50** (debited). `PointsEngine::debit()` was called.
- **On fail**: `includes/Streaks/StreakController.php::buy_freeze()`.

### 6. Freeze purchase with insufficient points — 400, no freeze granted (atomic)
- **Action**: with the member now at 50 points and cost 100, `curl -i -X POST -H 'X-WP-Nonce: $NONCE' $SITE_URL/wp-json/mvs-pro/v1/streaks/buy-freeze`.
- **Expect**: HTTP **400** with error code `mvs_insufficient_points`; freeze count **unchanged**; points **unchanged at 50** — no partial state where a freeze was granted without a successful debit.
- **On fail**: `includes/Streaks/StreakController.php:64` — the balance check is missing or runs after the freeze grant (non-atomic).

### 7. Debit-failure path — 400, no freeze granted
- **Action**: simulate `PointsEngine::debit()` returning false (e.g. concurrent spend), then `POST /streaks/buy-freeze`.
- **Expect**: HTTP **400** with `mvs_deduction_failed`; no freeze granted.
- **On fail**: `includes/Streaks/StreakController.php:79` — the debit return value isn't checked before granting the freeze.

### 8. Responsive check — streak widget (desktop AND mobile)
- **Action**: render `streak-widget.php` (member dashboard); `playwright_resize 1280 800` screenshot, then `playwright_resize 390 844` screenshot.
- **Expect (390px)**: no horizontal scroll; freeze count + buy-freeze button tappable (>=40px); badge not clipped.
- **On fail**: Pro streak-widget CSS missing a `@media` breakpoint.

### 9. Translation-readiness check
- **Action**: grep `includes/Streaks/` + `templates/**/streak-widget.php` for visible strings.
- **Expect**: all wrapped via `__()/esc_html__()` with text domain `wpmediaverse-pro`; JS strings localized; the `mvs_insufficient_points` / `mvs_deduction_failed` messages are translatable.
- **On fail**: the controller/template emitting the unwrapped string.

## Pass criteria

ALL of the following hold:
1. A 5-missed-day gap requires 5 freezes; holding only 1 resets the streak to 1 and consumes **no** freezes.
2. A 3-missed-day gap with 3 freezes preserves the streak and consumes exactly 3 freezes (proportional, not flat).
3. Freeze purchase with enough points succeeds (200) and debits the configured cost.
4. Freeze purchase with insufficient points returns 400 `mvs_insufficient_points` and grants no freeze (atomic — points and freeze unchanged).
5. A failed debit returns 400 `mvs_deduction_failed` and grants no freeze.
6. Streak widget renders at 1280x800 AND 390x844.
7. All visible strings are translation-ready under `wpmediaverse-pro`.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| 5-day gap costs only 1 freeze | flat consumption; `missed_days` math reverted | `includes/Streaks/StreakService.php:142` |
| Streak preserved despite too few freezes | `freezes >= missed_days` guard missing | `includes/Streaks/StreakService.php:144` |
| Freeze granted but points not debited | non-atomic purchase; debit checked after grant | `includes/Streaks/StreakController.php:64,79` |
| Insufficient-points returns 200 | balance check missing before grant | `includes/Streaks/StreakController.php::buy_freeze()` |
| Widget overflows at 390px | missing mobile breakpoint | Pro streak-widget stylesheet |
