---
journey: compete-results-departed-members
plugin: wpmediaverse-pro
priority: high
roles: [subscriber, anonymous]
covers: [compete-recent-results, departed-member-labelling]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Pro active with mvs_battles_enabled = 1"
  - "At least one completed battle in wp_mvs_competitions (type=battle, status=completed)"
estimated_runtime_minutes: 4
---

# Competition results never show members a placeholder name

**Why this journey exists**: members leave. Accounts get deleted, and the GDPR
retain policy in `Privacy\ProMemberData` deliberately keeps competition entries
while anonymising `user_id` to `0`, so the surviving opponent's battle still
resolves. Both cases reach the same renderer, and until 2.4.1 both printed the bare
English literal `Unknown` — so `/compete/` showed members
"**Unknown VS Unknown**", won by "**Unknown**". Three placeholders and no
information, in a section headed "Recent Results".

The same literal also covered a third case it should not have: a tournament **bye**,
where there genuinely is no second player.

## Setup

- Compete hub: `$SITE_URL/compete/`
- The summary is cached in the `mvs_pro_compete_summary` transient. **Flush it after
  any direct DB edit** or the page will keep serving the pre-edit result:
  `wp eval 'delete_transient("mvs_pro_compete_summary");'`
- Record the original `user_id` of any entry you modify, and restore it at the end.

## Steps

### 1. Baseline — a battle between two live members renders their names
- **Action**: load `$SITE_URL/compete/`; read `.mvs-battle-matchup__name` nodes.
- **Expect**: real display names; no occurrence of the string `Unknown` anywhere in
  the page text.
- **On fail**: `includes/REST/CompeteSummaryController.php::get_display_name`.

### 2. One participant is gone — the result still stands, the person is named honestly
- **Action**: point one side of a completed battle at a non-existent user:
  `UPDATE wp_mvs_competition_entries SET user_id = 999999 WHERE id = <entry>`, flush
  the transient, reload.
- **Expect**: the matchup still renders. The departed side reads
  **"Deleted member"** — translated via `__( 'Deleted member', 'wpmediaverse-pro' )`,
  matching the wording Free already uses in `Admin\ReportsPage`. The surviving
  member's name is unchanged; their result is theirs and must not disappear.
- **Expect**: the literal `Unknown` appears nowhere on the page.
- **On fail**: `get_display_name()` is returning a bare literal again, or a different
  wording from Free's.

### 3. Both participants gone — the row is omitted, not filled with placeholders
- **Action**: point BOTH sides at non-existent users, flush, reload.
- **Expect**: that matchup is **absent** from Recent Results. If it was the only
  completed battle, the Recent Results section itself does not render. Nothing on the
  page reads "Deleted member VS Deleted member".
- **Rationale**: a result naming neither competitor carries no information; showing
  it is worse than showing nothing.
- **On fail**: the `user_exists()` guard in the battles loop of
  `CompeteSummaryController` is missing or inverted.

### 4. Anonymised entries (`user_id = 0`) behave the same as deleted ones
- **Action**: set one side's `user_id` to `0` — the exact state the GDPR retain
  policy creates — flush, reload.
- **Expect**: identical to step 2: "Deleted member", matchup still shown.
- **On fail**: the `!$user_id` branch is diverging from the missing-user branch.

### 5. Translation-readiness
- **Action**: grep `includes/REST/CompeteSummaryController.php` for user-facing
  literals.
- **Expect**: no bare `'Unknown'`; every member-visible string wrapped with the
  `wpmediaverse-pro` domain (Coding Rule 9).
- **On fail**: the literal is back.

### 6. Restore
- **Action**: restore every `user_id` you changed; flush the transient; reload and
  confirm step 1's baseline again.

## Pass criteria

1. A battle between live members shows their real names.
2. A battle with one departed side shows "Deleted member" and still renders.
3. A battle with both sides departed is omitted entirely.
4. `user_id = 0` (GDPR-anonymised) behaves identically to a deleted account.
5. The literal `Unknown` never appears in `/compete/` page text.
6. No unwrapped user-facing string remains in the controller.
7. All fixtures restored.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| "Unknown" back on the page | bare literal returned again | `includes/REST/CompeteSummaryController.php::get_display_name` |
| "Deleted member VS Deleted member" shown | both-sides-gone guard missing | same file, the battles loop `continue` |
| Change not visible after a DB edit | summary transient not flushed | `CompeteSummaryController::CACHE_KEY` |
| Wording differs from Free | two vocabularies for one state | Free `includes/Admin/ReportsPage.php:248` is the canonical wording |
| A tournament bye reads "Deleted member" | bye and departure conflated | distinguish `player_b_entry_id === 0` (no entry) from an entry whose user is gone |
