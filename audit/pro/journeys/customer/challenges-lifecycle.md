---
journey: challenges-lifecycle
plugin: wpmediaverse-pro
priority: critical
roles: [member, anonymous]
covers: [MV-CHL-001, MV-CHL-002, MV-CHL-003, MV-CHL-005, MV-CHL-006, MV-CHL-007, MV-CHL-008, challenges-list, challenges-detail, challenges-voting, challenges-finalize]
prerequisites:
  - "Both plugins active; mvs_competitions_enabled = 1; mvs_challenges_enabled = 1"
  - "Auto-login mu-plugin available; at least 3 members who can upload/vote"
estimated_runtime_minutes: 10
---

# Challenges list/detail render correctly, deadlines are enforced honestly, voting is per-entry, and finalization ranks correctly

**Why this journey exists**: `customer/07-challenge-entry-happy-path.md` covers create + one member's happy-path entry + the entry-limit + the no-photos upload path. This journey covers the surfaces and rules `07` doesn't: the list/detail views themselves, the deadline-enforcement boundary, voting mechanics (including the per-entry — not per-challenge — vote uniqueness), and finalization ranking.

## Setup

- Site: `$SITE_URL`; members A (creator via admin or REST), B, C, D (entrants/voters)
- `mvs_competitions_enabled = 1`, `mvs_challenges_enabled = 1`

## Steps

### 1. List opens on the first non-empty tab
- **Action**: log out; visit `/media/challenges/` with challenges in more than one status.
- **Expect**: opens on the first tab (`TabPicker::first_with_items()`) that actually has challenges — never a hardcoded Active tab that could be empty. Status badges read "Coming Soon"/"Open for Submissions"/"Voting Open"/"Results"/"Cancelled". No login prompt appears for browsing.
- **On fail**: `includes/Challenges/Renderer.php` tab default.

### 2. Every empty tab shows its documented copy
- **Action**: switch through every status tab that currently has zero challenges.
- **Expect**: exact copy per status — "Submissions open soon. Check back when the challenge starts." (scheduled), "No entries yet. Be the first to submit!" (active), "Submissions are closed. No entries were submitted." (voting), "This challenge has finished. No entries were submitted." (finalized), "This challenge was cancelled." (cancelled) — never a blank panel.

### 3. Detail deep link resolves the right challenge
- **Action**: deep-link `/media/challenges/?mvs_challenge_id=<id>` for a real challenge, then for a nonexistent id, then for a tournament id (cross-type).
- **Expect**: real id opens that exact challenge (not the list); theme/description/rules, live entry count, and voting status shown. Nonexistent/cross-type id shows an empty/not-found state, never a PHP notice or a cross-rendered tournament.
- **On fail**: `templates/challenges-body.php` — `absint(get_query_var('mvs_challenge_id', 0))` resolution.

### 4. Submit after the deadline is blocked, both belt-and-suspenders paths
- **Action**: as B, try to submit to a challenge whose `end_date` has passed but status is still `active` (pre-tick); then try to submit to one already in `voting`.
- **Expect**: still-`active`-but-past-end_date -> `mvs_challenge_entries_closed` 400. Already-`voting`/`finalized`/`cancelled` -> `mvs_challenge_not_active` 400. The Submit control itself is disabled with a "Deadline passed"/"Voting in progress" message before the user even attempts the call.
- **On fail**: `includes/Challenges/ChallengeService.php::submit_entry()`.

### 5. Voting is per-entry, not per-challenge
- **Action**: as C (not an entrant), vote for B's entry, then vote for a DIFFERENT entry (D's) in the SAME challenge, then try to vote for B's entry a second time, then unvote B's entry, then try to unvote it again.
- **Expect**: voting for both B's and D's entries succeeds (per-entry uniqueness, `(votable_type='entry', votable_id, user_id)` — a member may legally cast one vote on multiple different entries in one challenge, this is NOT "one vote per challenge"). Second vote on the same entry -> "You have already voted for this entry." Unvote succeeds and decrements `vote_count` (floored at 0); a second unvote with no existing vote -> "You have not voted for this entry."
- **On fail**: `includes/Challenges/ChallengeController.php` vote route uniqueness key.

### 6. Self-vote is rejected
- **Action**: as B (an entrant), try to vote for your own entry.
- **Expect**: "You cannot vote for your own entry." (403); the button for the user's own entry is visibly disabled/greyed with an explanatory tooltip, not silently rejected on click.

### 7. Deadline countdown format and boundary
- **Action**: compare the displayed countdown for a challenge >1 day out, <24h out, <1h out, and already past its deadline.
- **Expect**: `%1$dd %2$dh left` / `%1$dh %2$dm left` / `%dm left` / "Deadline passed" respectively, computed from server-relative time (REST payload timestamps), counting down live client-side without a page refresh.
- **On fail**: the challenge card/store's countdown formatter — check it isn't using the visitor's local clock.

### 8. Finalization ranks by votes then submission order
- **Action**: with challenge entries from B (2 votes), C (2 votes, submitted after B), D (1 vote), let voting expire (or use the admin Finalize action).
- **Expect**: B and C are tied at 2 votes — B (earlier `created_at`) wins the tie and becomes `winner_1st`; C becomes `winner_2nd`; D becomes `winner_3rd`. Ranks persist onto each entry's `rank` column. Status becomes `finalized`; `get_results()` on a non-finalized challenge returns a `WP_Error`.
- **On fail**: `includes/Challenges/ChallengeService.php::finalize()` — `ORDER BY vote_count DESC, created_at ASC`.

### 9. Zero and near-zero entrant finalization doesn't error
- **Action**: finalize a challenge with zero entries, then one with exactly 1 entry.
- **Expect**: zero entries — all winner slots stay unset/0, no error, no winner emails go out (no entrants to email). One entry — 2nd/3rd stay unset, rendered results never show "won by user #0" or a broken avatar for the unfilled ranks.

## Pass criteria

1. List/detail render the documented copy and status badges exactly, with correct default-tab selection.
2. Deadline enforcement rejects a submit through BOTH the status-based and end_date-based guards, and the UI disables Submit before the call is even attempted.
3. Voting is scoped per-entry (multiple entries votable per challenge by one user), self-vote is rejected, unvote works and is idempotent-safe.
4. Countdown format matches server time exactly at each of the four documented thresholds.
5. Finalization ranks by `vote_count DESC, created_at ASC` and never renders a broken state for unfilled ranks.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| List opens on an empty Active tab | `TabPicker` not wired for challenges | `includes/Challenges/Renderer.php` |
| Member blocked from voting a second entry in the same challenge | uniqueness key scoped to challenge instead of entry | `includes/Challenges/ChallengeController.php` |
| Tie-break winner is the later submission | `ORDER BY` clause wrong/missing `created_at ASC` tiebreak | `includes/Challenges/ChallengeService.php::finalize()` |
| Countdown drifts from server time | client-clock-based countdown instead of server-relative | challenge card/store countdown formatter |
