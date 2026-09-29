---
journey: streaks-flow
plugin: wpmediaverse-pro
priority: high
roles: [member, administrator]
covers: [MV-BST-005, MV-BST-008, MV-BST-009, MV-BST-010, streaks, streak-badge, streak-milestones]
prerequisites:
  - "Both plugins active"
  - "Auto-login mu-plugin available"
  - "WP-CLI available for date-boundary and toggle manipulation"
estimated_runtime_minutes: 8
---

# Upload streaks count day-to-day, badge honestly, and award fixed milestone XP

**Why this journey exists**: streak counting (`StreakService::on_upload()`), the
display-name badge (`mvs_user_display_name` filter in `Core/Plugin.php`), and the
four fixed milestone XP awards (`check_milestones()`) are three independently
wired surfaces that all read the same `_mvs_current_streak` user meta. This
journey proves the count is honest (no same-day inflation, correct day-1 seed),
the badge appears exactly where it's supposed to and nowhere it isn't
(`get_display_name()` vs `get_display_name_plain()`), and milestone XP fires at
the fixed constants only — and proves the toggle-off state genuinely stops
counting rather than merely hiding a counter.

## Setup

- Site: `$SITE_URL`
- Member: `?autologin=<member>`; capture `UID`.
- `mvs_streaks_enabled = 1`.
- Usermeta: `_mvs_current_streak`, `_mvs_longest_streak`, `_mvs_last_upload_date`.

## Steps

### 1. Day-1 upload seeds the streak at 1, not 0
- **Action**: clear `_mvs_last_upload_date` (`wp user meta delete $UID _mvs_last_upload_date`), upload one media item as the member.
- **Expect**: `wp user meta get $UID _mvs_current_streak` -> `1`.
- **On fail**: `includes/Streaks/StreakService.php::on_upload()` — first-upload seed path.

### 2. A second upload the same day does not inflate the streak
- **Action**: upload a second item the same day.
- **Expect**: `_mvs_current_streak` unchanged at `1` (the `$last_date === $today` early-return guard held).
- **On fail**: `StreakService::on_upload()` — same-day guard missing.

### 3. A consecutive-day upload increments
- **Action**: `wp user meta update $UID _mvs_last_upload_date "$(date -v-1d +%Y-%m-%d 2>/dev/null || date -d yesterday +%Y-%m-%d)"`, upload again.
- **Expect**: `_mvs_current_streak` -> `2`.

### 4. Streak badge renders on the author header and lightbox sidebar, never on compact grid cards
- **Action**: with `_mvs_current_streak > 0`, view (a) this member's single-media author header, (b) the lightbox sidebar, (c) the Explore grid card for the same member's media.
- **Expect**: (a) and (b) show a flame icon + "%d day streak" with both `title` and `aria-label` set (single render path, `mvs_user_display_name` via `get_display_name()`). (c) shows the plain name with NO badge — `get_display_name_plain()` strips it by design, not a bug.
- **On fail**: `Core/Plugin.php` `mvs_user_display_name` filter callback.

### 5. Badge survives inside a BuddyPress activity item + 390px name-wrap check
- **Action**: post an activity item as the streaked member (upload triggers one); view it in the activity stream at 1280x800 and 390x844.
- **Expect**: badge doesn't break BP's activity-item layout; at 390px the name does not wrap into an unreadable line because of the badge.

### 6. Milestone at 7 days posts the fixed-XP activity note
- **Action**: seed `_mvs_current_streak` to 6 with yesterday's date, upload once more to cross 7.
- **Expect**: an activity item posts "Reached a 7-day upload streak! +50 points"; with WB Gamification present, the member's balance increases by exactly 50 (via `CompetePointsBridge` reading `xp_bonus` metadata, not WB Gamification's own flat default).
- **On fail**: `StreakService::check_milestones()`; `Gamification/CompetePointsBridge.php`.

### 7. No setting exists to change the milestone amounts
- **Action**: search the Gamification settings screen and `wp option list --search='mvs_*milestone*'`.
- **Expect**: nothing — 50/250/1000/5000 at 7/30/100/365 days are fixed constants (unlike battle/challenge/tournament XP, which ARE configurable). Confirm this matches the customer docs, which list the same four fixed amounts.

### 8. Activity-post failure never costs the member their streak
- **Action**: simulate the activity service throwing (e.g. temporarily deactivate BuddyPress if the activity call depends on it, or stub the hook to throw) at the moment of a milestone; re-check streak meta afterward.
- **Expect**: `_mvs_current_streak`/`_mvs_longest_streak` are already saved and unaffected — only the celebratory post is best-effort and caught/logged.

### 9. Toggle OFF genuinely stops tracking, not just hides the widget
- **Action**: `wp option update mvs_streaks_enabled 0`; upload media as the member on several distinct days; check `/my-media/` dashboard, `GET /mvs-pro/v1/me/streak`, and the display-name badge anywhere.
- **Expect**: `_mvs_current_streak` does NOT accumulate during this window (`StreakService::init()` never hooks `on_upload` when off — genuinely not tracked). The widget and badge both independently short-circuit on the same option, so neither shows a trace. `GET /me/streak` still responds (not gated) with the last-known meta plus `enabled: false`.
- **On fail**: `StreakService::init()` — hook registration not gated by the option.

### 10. Turning streaks back on resumes from the stale date honestly
- **Action**: `wp option update mvs_streaks_enabled 1`; upload immediately.
- **Expect**: no silently-invented progress — the very next upload either extends or resets the streak strictly from the stale `_mvs_last_upload_date`, same gap-day math as any other gap.

## Pass criteria

1. First upload seeds streak at 1; a same-day repeat never inflates it; consecutive days increment.
2. The badge appears only via `get_display_name()` (author header, lightbox), never via `get_display_name_plain()` (grid/compact lists) — by design.
3. Badge survives inside a BP activity item and at 390px.
4. Milestone at 7/30/100/365 awards exactly 50/250/1000/5000 via the bridge, with no admin-configurable override.
5. An activity-post failure at milestone time never loses the saved streak data.
6. Toggle OFF stops real counting (not merely display); toggle ON resumes honestly from stale data.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Streak inflates on repeat same-day upload | same-day guard missing/broken | `includes/Streaks/StreakService.php::on_upload()` |
| Badge missing from author header | filter not registered or gated wrong | `includes/Core/Plugin.php` `mvs_user_display_name` |
| Badge appears on Explore grid cards | grid using `get_display_name()` instead of `_plain()` | wherever the grid card partial renders the author name |
| Milestone XP amount configurable/wrong | constants changed or bridge not reading `xp_bonus` | `StreakService::check_milestones()`; `CompetePointsBridge.php` |
| Streak keeps counting with toggle off | `init()` hooks `on_upload` unconditionally | `includes/Streaks/StreakService.php::init()` |
