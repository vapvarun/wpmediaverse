---
journey: battle-win-xp-configured
plugin: wpmediaverse-pro
roles: [administrator, member]
priority: high
covers: [battles, gamification, mvs_battle_win, xp_for_win, CompetePointsBridge, configured-xp]
prerequisites:
  - "Both plugins active; mvs_battles_enabled = 1"
  - "WB Gamification active (CompetePointsBridge wired)"
  - "Auto-login mu-plugin available; two members to battle"
estimated_runtime_minutes: 6
---

# A resolved battle awards the OWNER-CONFIGURED win XP, not the flat gamification default

**Why this journey exists**: `CompetePointsBridge::resolve_points()` handled challenge and tournament XP from configured settings, but had NO case for battle wins — so battle winners always received WB Gamification's flat default points for the action, ignoring whatever the site owner set. The 1.7.0 fix adds (a) a `mvs_pro_battle_win_xp` setting in Gamification settings (`GamificationSettings.php:141-154`, default 100), (b) a snapshot of that value into the battle's `settings` JSON as `xp_win` at creation time (`BattleService.php:105`) so changing the global setting later never retro-rewrites an in-flight battle's reward, (c) a `BattleService::xp_for_win()` resolver (`BattleService.php:679-685`) that reads the snapshot (falling back to the live option), and (d) a `mvs_battle_win` case in `CompetePointsBridge` (`CompetePointsBridge.php:151-164`) that routes through it. This journey asserts the winner's awarded points equal the configured N, the snapshot is taken at creation, and the bridge resolves via `xp_for_win()`. The journey IS the regression test. (`FLOW-DATA-AUDIT-pro-2026-06-15.md` Risk 1 + TC-1.6.0-A.)

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=admin`
- Battlers: `<member-a>` (challenger), `<member-b>` (opponent); capture `UID_A`, `UID_B`.
- Option: `mvs_pro_battle_win_xp`.
- Tables: `wp_mvs_competitions` (type=battle), `wp_mvs_competition_entries`, `wp_mvs_competition_matches`, `wp_mvs_competition_votes`.

## Steps

### 1. Owner sets the configured battle-win XP to a distinctive value
- **Action**: in Gamification settings (`?page=mvs-gamification`) set "Battle win XP" to **777** (a value that cannot collide with the engine's flat default). Or `wp option update mvs_pro_battle_win_xp 777`.
- **Expect**: `wp option get mvs_pro_battle_win_xp` → `777`.
- **On fail**: `includes/Admin/GamificationSettings.php:141-154` — the setting isn't registered/saved.

### 2. Create a battle — assert the setting is snapshotted into settings JSON
- **Action**: as member A, `curl -X POST -H 'X-WP-Nonce: $NONCE' -d '{"opponent_id":'"$UID_B"',"media_id":<a-media>}' $SITE_URL/wp-json/mvs-pro/v1/battles`; capture `BID`.
- **Action**: `mysql_query "SELECT settings FROM wp_mvs_competitions WHERE id = $BID"`.
- **Expect**: HTTP 200/201; the `settings` JSON contains `"xp_win": 777` — snapshotted at creation from `get_option('mvs_pro_battle_win_xp', 100)`.
- **On fail**: `includes/Battles/BattleService.php:105` — `'xp_win' => (int) get_option('mvs_pro_battle_win_xp', 100)` not written into create args.

### 3. Snapshot is immune to a later setting change
- **Action**: change the global option AFTER creation: `wp option update mvs_pro_battle_win_xp 50`. Re-read the battle's settings JSON.
- **Expect**: the in-flight battle's `xp_win` is still **777** (the snapshot), not 50 — changing the global doesn't retro-rewrite an active battle.
- **On fail**: `xp_for_win()` is reading the live option instead of preferring the snapshot.

### 4. Run the battle: accept → both submit → vote → resolve
- **Action**: member B `POST /battles/$BID/accept`; both players `POST /battles/$BID/submit`; cast votes via `POST /battles/$BID/vote` so member A wins; then resolve (let the vote window expire and fire `mvs_resolve_expired_matches`, or trigger `CompetitionsScheduler::tick()`).
- **Expect**: battle status `resolved`; `winner` = member A.
- **On fail**: `includes/Battles/BattleService.php` (accept/submit/vote/resolve), `CompetitionsScheduler`.

### 5. Bridge resolves `mvs_battle_win` via `xp_for_win()` — assert awarded XP equals the snapshot
- **Action**: capture the gamification award. Either spy the filter: `wp eval 'add_filter("wb_gam_points_for_action", function($pts,$action,$meta){ if($action==="mvs_battle_win"){ error_log("battle_xp=".$pts); } return $pts; },99,3);'` before resolve, OR read the member's points delta around resolution.
- **Expect**: the `mvs_battle_win` action resolves to **777** (the snapshot) for member A — exactly the configured amount, NOT the WB Gamification flat default. `CompetePointsBridge::resolve_points('mvs_battle_win', $meta)` calls `$this->battles->xp_for_win($battle_id)` and returns 777.
- **On fail**: `includes/Gamification/CompetePointsBridge.php:151-164` — the `mvs_battle_win` case is missing (falls through to flat default), or `battle_id` not resolved from `$meta['battle_id'] ?? $meta['competition_id']`.

### 6. Loser is notified, winner credited (no dropped notifications)
- **Action**: `mysql_query "SELECT user_id, type FROM wp_mvs_notifications WHERE user_id IN ($UID_A,$UID_B) ORDER BY id DESC LIMIT 4"`.
- **Expect**: both winner and loser received battle-resolution notifications via `BattleNotificationListener::notify_players()`.
- **On fail**: `includes/Battles/BattleNotificationListener.php` — notification type not whitelisted via `mvs_notification_types` (Free's `NotificationService::create()` silently drops unknown types).

### 7. Fallback default when no snapshot is present (legacy battle)
- **Action**: simulate a battle row whose `settings` JSON lacks `xp_win` (a pre-fix row); call `wp eval 'echo \WPMediaVersePro\Core\Plugin::container_or_service("battles")->xp_for_win(<legacy_bid>);'`.
- **Expect**: returns the live `get_option('mvs_pro_battle_win_xp', 100)` value — graceful fallback, never a fatal or 0.
- **On fail**: `includes/Battles/BattleService.php:685` — `$settings['xp_win'] ?? get_option(...)` fallback missing.

### 8. Responsive check — battle UI (desktop AND mobile)
- **Action**: render `battles.php` (active battle card) and the `GamificationSettings` admin page; `playwright_resize 1280 800` screenshot, then `playwright_resize 390 844` screenshot.
- **Expect (390px)**: no horizontal scroll; vote/submit buttons tappable (>=40px); the XP setting field usable on the admin page.
- **On fail**: Pro battle / gamification-settings CSS missing a `@media` breakpoint.

### 9. Translation-readiness check
- **Action**: grep `includes/Battles/`, `includes/Admin/GamificationSettings.php`, `templates/battles.php` for visible strings.
- **Expect**: all wrapped via `__()/esc_html__()` with text domain `wpmediaverse-pro`; JS strings localized.
- **On fail**: the template/controller emitting the unwrapped string.

## Pass criteria

ALL of the following hold:
1. `mvs_pro_battle_win_xp` is an owner-editable Gamification setting.
2. The configured value is snapshotted into the battle's `settings` JSON as `xp_win` at creation.
3. Changing the global option later does NOT alter an in-flight battle's snapshot.
4. On resolve, the winner's awarded points equal the configured/snapshotted amount (e.g. 777) via `CompetePointsBridge`'s `mvs_battle_win` case calling `BattleService::xp_for_win()` — NOT the flat default.
5. Both winner and loser receive notifications.
6. A snapshot-less legacy battle falls back to the live option without error.
7. Battle UI + settings render at 1280x800 AND 390x844.
8. All visible strings are translation-ready under `wpmediaverse-pro`.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Winner gets flat default XP, not configured | `mvs_battle_win` case missing in bridge | `includes/Gamification/CompetePointsBridge.php:151-164` |
| `xp_win` absent from settings JSON | snapshot not written at creation | `includes/Battles/BattleService.php:105` |
| Changing the option rewrites active battle XP | resolver reads live option, not snapshot | `includes/Battles/BattleService.php:679-685` |
| Setting field absent/not saving | setting not registered | `includes/Admin/GamificationSettings.php:141-154` |
| Loser notification dropped | type not in `mvs_notification_types` whitelist | `includes/Battles/BattleNotificationListener.php` |
