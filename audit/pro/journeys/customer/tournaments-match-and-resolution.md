---
journey: tournaments-match-and-resolution
plugin: wpmediaverse-pro
priority: critical
roles: [member, administrator]
covers: [MV-TRN-005, MV-TRN-006, MV-TRN-007, MV-TRN-008, MV-TRN-009, MV-TRN-010, MV-TRN-011, MV-TRN-012, MV-TRN-013, MV-TRN-018, tournaments-match-lifecycle, tournaments-round-advancement, tournaments-champion]
prerequisites:
  - "Both plugins active; mvs_competitions_enabled = 1; mvs_tournaments_enabled = 1"
  - "Auto-login mu-plugin available; at least 4 members able to register and vote"
  - "wp-cli available to force ticks"
estimated_runtime_minutes: 12
---

# A tournament match goes submit -> vote -> resolve -> advance -> champion, with the tie rule and concurrency guards holding

**Why this journey exists**: `customer/01-tournament-sparse-bracket-safety.md` proves bracket generation is fatal-safe on sparse entries and covers the admin manual per-match resolve path. This journey covers everything downstream of a generated bracket that `01` does not: submitting media for a match, the stale-deadline default-loss path, per-matchup voting (including the self-vote guard), the AUTOMATIC tie-resolution rule, round advancement across a full multi-round bracket, final champion display, the champion's in-app notification, and two concurrency races.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; members A, B, C, D `?autologin=<X>`
- An 8-slot tournament with A, B, C, D registered (real 2-player round-1 matches, no byes) — use this for the bracket-progression steps

## Steps

### 1. Bracket view shows correct per-match status labels
- **Action**: view `/media/tournaments/` detail for the active tournament, then the admin `render_detail()` view.
- **Expect**: each match shows a status label — "Awaiting Start" / "Submissions Open" / "Voting Open" / "Match Complete" / "Automatic Advance" (bye). The admin view additionally lists every participant with seed number and elimination round ("Eliminated R{n}" or "Active"). No round renders as a phantom "TBD vs TBD" for a round not yet reached — those matches simply don't exist yet.
- **On fail**: `includes/Tournaments/TournamentController.php` bracket route; `includes/Admin/TournamentManager.php::render_detail()`.

### 2. Submit media for a match
- **Action**: as A (player in a round-1 match), submit an existing photo. As a non-participant, try to submit to that same match. As B (the opponent), submit — confirm status flips to `voting` only once BOTH have submitted.
- **Expect**: non-participant gets `mvs_match_not_participant` 403. Media not passing `CompetitionMedia::is_entrable()` (not owned, or a document) is rejected. Match stays `active` until both `player_a_media_id` and `player_b_media_id` are set.
- **On fail**: `includes/Tournaments/TournamentController.php` submit route.

### 3. Re-submitting your own media before the opponent submits
- **Action**: as A, submit media, then submit a DIFFERENT media item before B has submitted.
- **Expect**: the overwrite succeeds — no "already submitted" guard exists until both sides have submitted (confirmed by code). Attempting a further submit once the match has flipped to `voting` is rejected.

### 4. Stale submit deadline — default-loss resolution
- **Action**: on the C-vs-D match, have only C submit; let (or backdate) `submit_deadline` pass; force the tick.
- **Expect**: C wins by default (submitted); D is marked eliminated for this round; match status becomes `completed`; `mvs_tournament_match_resolved` fires — same downstream elimination notification as a normal voting loss.
- **On fail**: `includes/Tournaments/TournamentService.php::resolve_expired_matches()`.

### 5. Neither player submits — higher seed wins by default
- **Action**: seed a match where NEITHER player submits; let the deadline pass; force the tick.
- **Expect**: player A (the higher seed) wins by default; the bracket UI does not attempt to render two empty media tiles as though a real match happened.

### 6. Voting: self-vote and non-participant votes rejected, third party works
- **Action**: on the A-vs-B voting match: as A or B, try to vote in your own match; as a third member not in this match, vote for A; try to vote again; try to vote for a user id not in this match.
- **Expect**: self-vote -> `mvs_match_self_vote` 403; vote for a non-participant id -> `mvs_match_invalid_vote` 400; a real third-party vote increments `player_a_votes`; a second vote from the same voter is ignored ("Already voted."). Participants see voting UI disabled/hidden, not a clickable 403 control.
- **On fail**: `includes/Tournaments/TournamentController.php` vote route.

### 7. Vote deadline boundary
- **Action**: attempt to vote exactly as `vote_deadline` passes.
- **Expect**: rejected once past, "Voting deadline passed."

### 8. Automatic tie resolution, including 0-0
- **Action**: let the A-vs-B match's vote deadline pass tied at equal votes (including 0-0, no votes cast).
- **Expect**: player A (higher seed) wins the tie — documented, intentional, not a bug. B is eliminated. `mvs_tournament_match_resolved` fires.
- **On fail**: `includes/Tournaments/TournamentService.php::resolve_match()`.

### 9. Admin manual force-resolve advances immediately
- **Action**: as admin, force-resolve a still-open match via Tournament Manager's detail page.
- **Expect**: calls the identical `resolve_match()` then explicitly `advance_rounds()` — the bracket advances without waiting for the next cron tick. A confirmation is required before the irreversible elimination fires.
- **On fail**: `includes/Admin/TournamentManager.php::handle_resolve_match()`.

### 10. Force-resolving an already-resolved match is a clean no-op
- **Action**: force-resolve the same match a second time (moments after it auto-resolved via cron).
- **Expect**: clean "not in voting phase" error, never a double-elimination.

### 11. Round advancement pairs winners in order
- **Action**: resolve every round-1 match (mix of real wins and byes if applicable); force/wait for the next tick.
- **Expect**: `try_advance()` only proceeds once every round-1 match is `completed` or `bye`; round-2 matches pair winners in `match_position` order; the same submit/vote deadline formula applies; `current_round` bumps.
- **On fail**: `includes/Tournaments/TournamentService.php::try_advance()`.

### 12. Champion crowned on the final round
- **Action**: run the bracket through to its final match; resolve it.
- **Expect**: `try_advance()` detects `next_round > total_rounds`, sets `status = finalized`, `winner_id` = champion, `resolved_at` = now, fires `mvs_tournament_finalized`. Admin detail page shows "Champion: {name}"; frontend badge reads "Completed". The champion is prominently displayed on the frontend, not only inferable by tracing the bracket.
- **On fail**: `includes/Tournaments/TournamentService.php::try_advance()` finalization branch; frontend champion display in `templates/tournaments-body.php`.

### 13. Champion + eliminated-player in-app notifications
- **Action**: check the champion's in-app notifications after finalization; check an eliminated player's notifications after their loss (including the stale-deadline default loss from step 4).
- **Expect**: "You won a photo tournament!" for the champion (self-scoped, `allow_self = true`); "You were eliminated from a tournament" for every eliminated player including default-loss eliminations — no email exists for either event by design (confirmed: zero `wp_mail()` calls in the tournament notification listener).

### 14. Concurrency — registration race against bracket generation
- **Action**: fire a registration `POST` at the same moment `generate_bracket()` runs for that tournament (or rapid back-to-back).
- **Expect**: `generate_bracket()` takes `SELECT ... FOR UPDATE` on the competition row before snapshotting entries — a registration committing after the lock is excluded cleanly, never partially included or corrupting the entry list.
- **On fail**: `includes/Tournaments/TournamentService.php::generate_bracket()` locking.

### 15. Concurrency — vote landing after auto-resolve
- **Action**: fire a vote on a match at the same moment `resolve_expired_matches()` resolves it.
- **Expect**: the vote is rejected `mvs_match_not_voting` (match already past `voting` status) rather than accepted into an already-resolved match.

## Pass criteria

1. Match status labels and admin detail view match the documented states, with no phantom future-round placeholders.
2. Submit/self-vote/non-participant guards all hold with the documented error codes.
3. Stale-deadline default-loss (one submitter, and neither submitter) resolves correctly and eliminates the right side.
4. The tie rule (including 0-0) always resolves to the higher seed, both automatically and via admin force-resolve.
5. Round advancement pairs winners correctly in `match_position` order and only proceeds once the round is fully resolved.
6. The champion is crowned, displayed prominently on the frontend, and notified in-app with no email.
7. Both concurrency races (registration-vs-bracket, vote-vs-auto-resolve) resolve without data corruption.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Match stuck `active` after both submit | the both-submitted check missing | `includes/Tournaments/TournamentController.php` submit route |
| Wrong side wins a tie | tie rule inverted or missing `>=` | `includes/Tournaments/TournamentService.php::resolve_match()` |
| Round 2 pairs winners out of order | `match_position` ordering lost in `try_advance()` | `includes/Tournaments/TournamentService.php::try_advance()` |
| No champion shown on frontend after final match | finalization branch not reached / frontend template not reading `winner_id` | `TournamentService::try_advance()` + `templates/tournaments-body.php` |
| Late registration slips into a bracket already generating | missing `SELECT ... FOR UPDATE` | `includes/Tournaments/TournamentService.php::generate_bracket()` |
