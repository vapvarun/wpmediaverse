---
journey: tournament-sparse-bracket-safety
plugin: wpmediaverse-pro
priority: critical
roles: [administrator, member]
covers: [tournaments, generate_bracket, sparse-bracket, both-null-skip, 9966421635]
prerequisites:
  - "Both plugins active; mvs_tournaments_enabled = 1"
  - "Auto-login mu-plugin available"
  - "At least 2-3 members who can register media"
estimated_runtime_minutes: 6
---

# A sparse tournament bracket starts without a fatal and exposes a clean bracket

**Why this journey exists**: When a tournament's `bracket_size` far exceeds its actual entry count (e.g. `bracket_size=16` with only 3 registrations), `generate_bracket()` pads the slot array with nulls. Before 1.7.0, a round-1 match position where BOTH slots were null hit the bye branch, which dereferenced `$winner->id` on a null and produced a PHP fatal — the tournament never started and the bracket endpoint 500'd. The fix (`TournamentService.php:245-251`) skips both-null positions entirely (creates no match row) while still creating single-player bye matches for half-filled positions. This journey reproduces the exact sparse condition and asserts: no fatal, both-null slots skipped, single-byes created, status flips to active, and `GET /tournaments/{id}/bracket` returns 200. It also covers the admin manual per-match resolve advancing the bracket and notifying the loser. The journey IS the regression test. (Basecamp 9966421635.)

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=admin`
- Members: `<member-a>`, `<member-b>` (real registrants), to keep one real 2-player match.
- Tables: `wp_mvs_competitions`, `wp_mvs_competition_entries`, `wp_mvs_competition_matches`.

## Steps

### 1. Create a tournament with an oversized bracket
- **Action**: `curl -X POST -H 'Content-Type: application/json' -H 'X-WP-Nonce: $NONCE' -d '{"title":"Sparse Test","bracket_size":16,"round_duration_hours":48}' $SITE_URL/wp-json/mvs-pro/v1/tournaments` (as admin).
- **Expect**: HTTP 200/201; capture id.
- **Capture**: `TID` ← `.id`.
- **On fail**: `includes/Tournaments/TournamentController.php` (create route), `includes/Core/Plugin.php` (tournaments bootstrap gate).

### 2. Register only 3 participants (13 byes — sparse)
- **Action**: register member A, member B, and one more entry via `POST /tournaments/$TID/register` (each with their own auth) so `wp_mvs_competition_entries` has exactly 3 rows for `competition_id = $TID`.
- **Expect**: 3 entries. `mysql_query "SELECT COUNT(*) FROM wp_mvs_competition_entries WHERE competition_id = $TID"` returns 3.
- **On fail**: `includes/Tournaments/TournamentController.php` (register route).

### 3. Start the tournament — must NOT fatal
- **Action**: trigger bracket generation — either run the scheduler hook `wp eval '\WPMediaVersePro\Core\Plugin::container_or_service("tournaments")->generate_bracket('"$TID"');'` or fire `do_action('mvs_start_registered_tournaments')` via WP-CLI. Watch `wp-content/debug.log`.
- **Expect**: returns `true` (or no `WP_Error`); **no PHP fatal**, no `Trying to get property 'id' of non-object`, no entry in debug.log for `generate_bracket`.
- **On fail**: `includes/Tournaments/TournamentService.php::generate_bracket()` — the both-null skip at line ~245 was removed; the bye branch is dereferencing a null winner.

### 4. Assert both-null slots produced NO match row
- **Action**: `mysql_query "SELECT match_position, player_a_entry_id, player_b_entry_id, status FROM wp_mvs_competition_matches WHERE competition_id = $TID AND round_number = 1 ORDER BY match_position"`
- **Expect**: with 3 entries in a 16-slot bracket (8 round-1 positions), exactly **1 real 2-player match** (`status='active'`, both player ids non-null) + **1 single-bye match** (`status='bye'`, one player id, `winner_entry_id` set) for the odd 3rd entry. The remaining ~6 both-null positions produce **NO rows** — no match where both `player_a_entry_id` AND `player_b_entry_id` are NULL.
- **On fail**: `includes/Tournaments/TournamentService.php` — both-null `continue;` skip missing.

### 5. Status transitions to active
- **Action**: `mysql_query "SELECT status FROM wp_mvs_competitions WHERE id = $TID"`
- **Expect**: `active` (was `registration`).
- **On fail**: `generate_bracket()` early-returned a `WP_Error` before the status update.

### 6. Bracket endpoint returns a clean 200
- **Action**: `curl -i $SITE_URL/wp-json/mvs-pro/v1/tournaments/$TID/bracket`
- **Expect**: HTTP **200** with a JSON bracket; round 1 contains the 1 real match + the single-bye match; **no match object has a null winner unless its `status` is `bye`**; no `null`-on-`null` artifacts.
- **On fail**: `includes/Tournaments/TournamentController.php` (bracket route) / `TournamentService` bracket serializer.

### 7. Maximum sparseness regression (2 entries in a 64-slot bracket)
- **Action**: repeat steps 1-6 with `bracket_size=64` and only 2 registrants.
- **Expect**: no fatal; exactly 1 real match row; the other 31 positions are absent (both-null skipped); `GET /bracket` returns 200.
- **On fail**: same as step 3/4.

### 8. Admin manual per-match resolve advances the bracket + notifies the loser
- **Action**: in `TournamentManager` admin page (`?page=mvs-tournaments`), on the real 2-player match (status `voting`/`active`), click **Resolve** (or `POST /tournaments/$TID/matches/{match_id}/vote` then resolve).
- **Expect**: the match `winner_entry_id` is set; the loser's `eliminated_in_round` is updated in `wp_mvs_competition_entries`; a next-round match is created with the winner as a participant; the eliminated player receives a `tournament_eliminated` notification (`mysql_query "SELECT COUNT(*) FROM wp_mvs_notifications WHERE type='tournament_eliminated' AND user_id=<loser-id>"` > 0).
- **On fail**: `includes/Admin/TournamentManager.php` (resolve handler), `includes/Tournaments/TournamentService.php::resolve_expired_matches()`, `TournamentNotificationListener::notify_loser()` (notification type not whitelisted via `mvs_notification_types` → silently dropped).

### 9. Responsive check — desktop AND mobile (bracket visualizer + admin page)
- **Action**: at `tournaments.php` bracket visualizer and the `TournamentManager` admin page: `playwright_resize 1280 800` screenshot, then `playwright_resize 390 844` screenshot.
- **Expect (390px)**: `document.documentElement.scrollWidth - window.innerWidth <= 1`; Resolve button tappable (>=40px); bracket columns scroll within their container, not the page; no clipped match cards.
- **On fail**: responsive CSS for the bracket — `templates/tournaments.php` / Pro tournament styles missing a `@media` breakpoint.

### 10. Translation-readiness check
- **Action**: grep `includes/Tournaments/` + `includes/Admin/TournamentManager.php` + `templates/tournaments.php` for visible strings.
- **Expect**: all wrapped with `__()/esc_html__()` and text domain `wpmediaverse-pro`; no hardcoded literals; JS strings localized.
- **On fail**: the template/controller emitting the unwrapped string.

## Pass criteria

ALL of the following hold:
1. `generate_bracket()` on a sparse bracket produces **no PHP fatal**.
2. Both-null slot positions create **no** match row; single-player positions create a `status='bye'` match with a winner.
3. Tournament status transitions to `active`.
4. `GET /tournaments/{id}/bracket` returns **200** with no null-winner non-bye matches.
5. Maximum-sparseness case (2-in-64) behaves identically.
6. Admin manual resolve advances the bracket and notifies the loser.
7. Bracket renders at 1280x800 AND 390x844 (no page-level horizontal scroll).
8. All visible strings are translation-ready under `wpmediaverse-pro`.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| PHP fatal on start | both-null skip removed; bye branch dereferences null winner | `includes/Tournaments/TournamentService.php:245-251` |
| Rows with both player ids NULL | `continue;` for both-null positions missing | `includes/Tournaments/TournamentService.php` `generate_bracket()` |
| `/bracket` returns 500 | bracket serializer chokes on the malformed both-null row | `includes/Tournaments/TournamentController.php` bracket route |
| Status stuck at `registration` | early `WP_Error` return before status update | `generate_bracket()` guard clauses |
| Loser gets no notification | `tournament_eliminated` not in `mvs_notification_types` whitelist | `TournamentNotificationListener::register_notification_types()` |
| Next-round match not created on manual resolve | advance logic not wired to the admin path | `includes/Admin/TournamentManager.php`, `TournamentService::resolve_expired_matches()` |
