---
journey: streak-daily-check-bounded
plugin: wpmediaverse-pro
priority: high
roles: [system]
covers: [streaks, daily_check, bounded-cron, big-site-readiness, 9966423880]
prerequisites:
  - "Both plugins active; mvs_streaks_enabled = 1; mvs_streak_freezes_enabled = 1"
  - "WP-CLI available for seeding + invoking the cron handler"
  - "Action Scheduler available (as_enqueue_async_action)"
estimated_runtime_minutes: 8
---

# `StreakService::daily_check()` sweeps users in bounded batches and drains via async continuation

**Why this journey exists**: The daily streak sweep previously ran an unbounded `get_col()` over every user with `_mvs_last_upload_date` matching the cutoff, loading the entire matching users table into PHP in one cron tick and running a `get_user_meta` + `update_user_meta` per user inside the loop. On a large community (10k+ active streaks) one tick would exhaust memory or time out, and — worse — the reset branch leaves `_mvs_last_upload_date` unchanged, so a plain `LIMIT` would strand the same rows forever. The 1.7.0 fix (`StreakService.php:176-243`) uses **keyset pagination** by ascending `user_id`: `DAILY_BATCH_SIZE = 100` rows per page, capped at `DAILY_MAX_PER_RUN = 2000` users per tick, and hands any remainder to an async continuation (`as_enqueue_async_action`) carrying the cursor — so one cron run can never load the whole users table, and the moving cursor guarantees every stranded reset row is eventually processed. This journey seeds >2000 eligible users and proves the sweep is bounded per tick and fully drains across continuations. The journey IS the regression test for the unbounded-cron fix. (Basecamp 9966423880; `FLOW-DATA-AUDIT-pro-2026-06-15.md` TC-1.7.0-C + Risk 5.)

## Setup

- Site: `$SITE_URL`
- Cutoff date: two days ago (`_mvs_last_upload_date` = `wp_date('Y-m-d', strtotime('-2 days'))`).
- Constants under test: `StreakService::DAILY_BATCH_SIZE = 100`, `StreakService::DAILY_MAX_PER_RUN = 2000`.
- Hook: `StreakService::AS_DAILY_HOOK` (the Action Scheduler hook bound to `daily_check`).

## Steps

### 1. Code-level: confirm the bounded constants exist
- **Action**: `grep -n "DAILY_BATCH_SIZE\|DAILY_MAX_PER_RUN" includes/Streaks/StreakService.php`
- **Expect**: `const DAILY_BATCH_SIZE = 100;` and `const DAILY_MAX_PER_RUN = 2000;` present. The query inside `daily_check()` uses `ORDER BY user_id ASC` + `user_id > %d` (keyset cursor) + `LIMIT %d` — NOT an unbounded `get_col()`.
- **On fail**: `includes/Streaks/StreakService.php:36,46,176-243` — the unbounded scan is back.

### 2. Seed >2000 eligible users with the two-days-ago cutoff
- **Action**:
  ```bash
  CUTOFF="$(date -v-2d +%Y-%m-%d 2>/dev/null || date -d '2 days ago' +%Y-%m-%d)"
  wp eval '
  $cutoff = $argv[0];
  for ($i=0; $i<2200; $i++) {
    $uid = wp_insert_user(["user_login"=>"streaktest_$i","user_pass"=>wp_generate_password()]);
    if (is_wp_error($uid)) continue;
    update_user_meta($uid, "_mvs_last_upload_date", $cutoff);
    update_user_meta($uid, "_mvs_current_streak", 5);
    update_user_meta($uid, "_mvs_streak_freezes", 0); // no freeze → reset branch (worst case: leaves cutoff unchanged)
  }
  ' "$CUTOFF"
  ```
- **Expect**: ~2200 users now match the cutoff. `mysql_query "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='_mvs_last_upload_date' AND meta_value='$CUTOFF'"` ≈ 2200.
- **Note**: freezes=0 deliberately exercises the reset branch, which does NOT advance `_mvs_last_upload_date` — the exact condition a `LIMIT`-only fix would strand.

### 3. Run ONE tick — assert it processes at most DAILY_MAX_PER_RUN and does not time out
- **Action**: time a single full-sweep tick: `wp eval '$t=microtime(true); \WPMediaVersePro\Core\Plugin::container_or_service("streaks")->daily_check(0); echo round((microtime(true)-$t)*1000)."ms";'` (no cursor = recurring-cron entry).
- **Expect**: completes without fatal/timeout/memory-exhaustion. Because freezes=0, each processed user's `_mvs_current_streak` is set to 0 while `_mvs_last_upload_date` stays at the cutoff. At most **2000** users are processed this tick (the `$processed >= DAILY_MAX_PER_RUN` cap), so ~200 still have `_mvs_current_streak = 5`.
- **On fail**: `includes/Streaks/StreakService.php:231` — the `DAILY_MAX_PER_RUN` cap / async hand-off is missing.

### 4. Assert an async continuation was enqueued with the cursor
- **Action**: `wp action-scheduler list --hook=<AS_DAILY_HOOK> --status=pending` (or query `wp_actionscheduler_actions`).
- **Expect**: exactly one pending async action for the daily hook carrying a non-zero cursor arg (the `user_id` where the tick stopped, ~the 2000th user id). The recurring cron passes no cursor; only the continuation carries one.
- **On fail**: `includes/Streaks/StreakService.php:235-239` — `as_enqueue_async_action(self::AS_DAILY_HOOK, array($cursor), ...)` not firing (and no `wp_schedule_single_event` fallback).

### 5. Assert the cursor only advances forward (no re-scan of processed rows)
- **Action**: `mysql_query "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='_mvs_current_streak' AND meta_value='0'"` after the first tick.
- **Expect**: ≈ 2000 users reset to 0 — the first 2000 by ascending `user_id`. The keyset cursor means the continuation starts AFTER the last processed id, never re-processing.
- **On fail**: `includes/Streaks/StreakService.php:216` — `$cursor = $user_id;` not updated per row, so the `LIMIT` strands rows / re-scans.

### 6. Run the continuation — assert it drains the remainder
- **Action**: invoke the continuation with the captured cursor: `wp eval '\WPMediaVersePro\Core\Plugin::container_or_service("streaks")->daily_check(<CURSOR>);'` (or `wp action-scheduler run --hook=<AS_DAILY_HOOK>`).
- **Expect**: the remaining ~200 users now also have `_mvs_current_streak = 0`. `mysql_query "SELECT COUNT(*) FROM wp_usermeta WHERE meta_key='_mvs_current_streak' AND meta_value='5'"` → **0** (fully drained). No further continuation enqueued (remainder < DAILY_MAX_PER_RUN).
- **On fail**: keyset continuation not resuming from the cursor — `daily_check($after_user_id)` ignoring its argument.

### 7. Idempotency / no infinite loop
- **Action**: run `daily_check(0)` once more.
- **Expect**: 0 eligible rows remain at the cutoff for users whose streak already reset and whose `_mvs_last_upload_date` is unchanged — the sweep returns quickly (the `0 === $batch_count` early return) and enqueues no continuation. No runaway re-enqueue loop.
- **On fail**: `includes/Streaks/StreakService.php:210-212` — empty-batch early-return missing.

### 8. Cleanup
- **Action**: `wp eval 'foreach (get_users(["search"=>"streaktest_*","search_columns"=>["user_login"],"number"=>3000,"fields"=>"ID"]) as $id){ wp_delete_user($id); }'`
- **Expect**: seeded test users removed.

## Pass criteria

ALL of the following hold:
1. `DAILY_BATCH_SIZE = 100` and `DAILY_MAX_PER_RUN = 2000` exist; the query is keyset-paginated (`user_id > cursor ORDER BY user_id ASC LIMIT 100`), not unbounded.
2. A single tick over >2000 eligible users completes without timeout/memory exhaustion and processes at most 2000.
3. An async continuation is enqueued carrying the stopping cursor.
4. The cursor advances forward only — processed rows are never re-scanned, and reset-branch rows (cutoff unchanged) are NOT stranded.
5. The continuation drains the remainder; the full set is eventually processed.
6. Re-running on a drained set returns immediately with no continuation (no infinite loop).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| One tick loads all eligible users (slow / OOM) | unbounded `get_col()`; no per-tick cap | `includes/Streaks/StreakService.php:176-243` |
| Same reset rows processed every tick / never drains | plain `LIMIT` without a moving cursor strands reset rows | `includes/Streaks/StreakService.php:216` (`$cursor = $user_id`) |
| No follow-up after 2000 processed | async continuation not enqueued | `includes/Streaks/StreakService.php:231-241` |
| Continuation re-scans from 0 | `daily_check()` ignores `$after_user_id` | `includes/Streaks/StreakService.php:176,183` |
| Runaway re-enqueue | empty-batch early return missing | `includes/Streaks/StreakService.php:210-212` |
