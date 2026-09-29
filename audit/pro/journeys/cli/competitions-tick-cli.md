---
journey: competitions-tick-cli
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-CHL-017, MV-TRN-019, wp-mvs-competitions-tick, wp-mvs-competitions-recompute]
prerequisites:
  - "Pro active; wp-cli available"
  - "At least one challenge past its start/end/voting-end date still in scheduled/active/voting status, and one tournament past its registration_end still in registration, and a match past its submit/vote deadline"
estimated_runtime_minutes: 5
---

# `wp mvs competitions tick`/`recompute` run all six transition hooks manually, truthfully, and idempotently

## Setup

- Fixtures with deadlines already in the past to force a visible transition on the next tick:
  - A challenge in `scheduled` past `start_date`, one `active` past `end_date`, one `voting` past `voting_end_date`
  - A tournament in `registration` past `registration_end` with 2+ registrants
  - A match past `submit_deadline` or `vote_deadline`

## Steps

### 1. `tick` fires all six transition hooks in one pass
- **Action**: `wp mvs competitions tick`.
- **Expect**: the scheduled challenge -> `active`; the active-past-end_date challenge -> `voting`; the voting-past-voting_end_date challenge -> `finalize()`d. The tournament past `registration_end` generates its bracket (or auto-cancels with `insufficient_participants` if under 2 registrants). The expired match resolves/advances. Output: "Competitions tick executed." Each of the six hooks (`mvs_resolve_expired_battles`, `mvs_activate_scheduled_challenges`, `mvs_close_challenge_entries`, `mvs_finalize_expired_challenges`, `mvs_start_registered_tournaments`, `mvs_resolve_expired_matches`) is wrapped in try/catch — one hook's failure must not block the others.
- **On fail**: `includes/Competitions/CompetitionsScheduler.php::tick()`.

### 2. Zero-eligible-row run still reports truthfully
- **Action**: run `wp mvs competitions tick` again immediately (nothing left eligible).
- **Expect**: still prints "Competitions tick executed." — no false claim of work done, but also no silent no-op with no output.

### 3. `recompute` is not a scoring recalculation
- **Action**: `wp mvs competitions recompute`.
- **Expect**: deletes the one-shot migration flag option, re-runs `run_migration()`, unschedules any legacy duplicate per-hook Action Scheduler recurring actions left from pre-2.3.0 installs, fires each of the six transition hooks once, and prints "Recomputed N competition(s)." where N is a `COUNT(*)` of competitions still open (not finalized/cancelled) — this does NOT recalculate scores or XP. Verify the printed N matches an independent count of open competitions.
- **On fail**: `includes/Competitions/CompetitionsScheduler.php::cli_recompute()`.

### 4. `recompute` is idempotent
- **Action**: run `wp mvs competitions recompute` a second time immediately.
- **Expect**: no error, no double-migration, N reflects the current (possibly now-lower) open count.

### 5. Running with the feature toggled off doesn't fatal
- **Action**: `wp option update mvs_tournaments_enabled 0`; run `wp mvs competitions tick`.
- **Expect**: the tournament-owned hooks fire but no tournament listener acts on disabled-feature data — no fatal, no phantom transition on tournament rows while the feature is off.
- **On fail**: `includes/Tournaments/TournamentService.php` hook registration not gated on the toggle.

## Pass criteria

1. A single `tick` run correctly transitions challenges, tournaments, and matches all six ways in one pass.
2. A per-hook failure never blocks the other five hooks in the same tick.
3. `recompute`'s printed count is "still open," never described or misread as a score recalculation.
4. Both commands are idempotent on a re-run with nothing eligible.
5. Running either command with a feature toggled off causes no fatal and no phantom transition on that feature's disabled data.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| One hook's exception aborts the whole tick | missing per-hook try/catch | `includes/Competitions/CompetitionsScheduler.php::tick()` |
| `recompute`'s N doesn't match an independent open-count | wrong status list in the COUNT query | `includes/Competitions/CompetitionsScheduler.php::cli_recompute()` |
| Tournament data changes while `mvs_tournaments_enabled = 0` | tournament listener not toggle-gated | `includes/Tournaments/TournamentService.php` |
