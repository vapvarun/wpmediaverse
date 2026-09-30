---
journey: battles-edge-cases
plugin: wpmediaverse-pro
priority: high
roles: [member]
covers: [MV-BAT-010, MV-BAT-011, MV-BAT-015, battles-deadline-parity, battles-license-free, battles-concurrency]
prerequisites:
  - "Pro active; mvs_battles_enabled = 1"
  - "Pro license deliberately left inactive/expired for the license check"
  - "Two members able to open the same battle in two browser tabs"
estimated_runtime_minutes: 6
---

# Deadline display matches server enforcement, battles work fully unlicensed, and votes race safely

## Setup

- Site: `$SITE_URL`; member `<A>`, `<B>` for a battle nearing its deadline; member `<C>` for the concurrency vote race

## Steps

### 1. Countdown vs server deadline agree
- **Action**: watch a battle's countdown approach zero (submit or vote deadline); at/just after the deadline, attempt the action via the UI.
- **Expect**: the submit/vote button disables or hides at or before the moment the server would reject the action — never a live-looking button that 400s on click.
- **On fail**: the countdown component's deadline source vs `includes/Battles/BattleService.php` deadline check — compare timestamps.

### 2. Clock-skew error is explicit
- **Action**: simulate a client that perceives "time left" while the server has already expired the deadline (backdate the server deadline slightly and act at the boundary).
- **Expect**: the resulting error toast clearly says the deadline passed — not a generic "Vote failed."/"Submission failed."

### 3. Full lifecycle with an inactive/expired Pro license
- **Action**: confirm the Pro license is inactive/expired (`wp mvs-pro license status` or the License admin screen); run the full battle lifecycle: create, accept, submit, vote, resolve.
- **Expect**: every step succeeds with zero restriction — no "upgrade to unlock" interstitial anywhere. Battles are explicitly not the one license-gated feature (only Documents writes are).
- **On fail**: any license check accidentally added to `includes/Battles/BattleController.php` or `BattleService.php` — battles must have none.

### 4. No stray license nag in the Battles UI
- **Action**: browse `/media/battles/` end to end while unlicensed.
- **Expect**: no license nag banner appears anywhere in this flow.

### 5. Concurrent votes from the same user, two tabs
- **Action**: as `<C>`, open the same voting battle in two tabs. Vote for side A in tab 1. Immediately vote for side B in tab 2 before tab 1's response returns.
- **Expect**: the server is the single source of truth — determine and document whether the second vote changes the recorded vote, is ignored, or errors; the UI in BOTH tabs must reconcile to whatever the server actually recorded, not trust tab 2's optimistic UI state.
- **On fail**: `includes/Battles/BattleService.php::vote()` — missing a deterministic single-vote-per-user rule, or the frontend not re-syncing from the server response.

### 6. A vote rejected because the battle resolved mid-flight
- **Action**: have an admin resolve the battle (Battle Monitor) at the same moment `<C>`'s vote request is in flight.
- **Expect**: the vote request that lands after resolution gets a clean "this battle is over" message; the displayed vote count is not corrupted (no double-increment, no stale count).
- **On fail**: `includes/Battles/BattleController.php` vote route — missing a status check that runs AFTER acquiring any lock, not just before.

## Pass criteria

1. UI countdown and server deadline enforcement agree; no live-looking control that then fails.
2. A deadline-related failure names the deadline explicitly in its error message.
3. The entire battle lifecycle works with zero restriction while unlicensed; no nag banner anywhere.
4. Racing votes from one user resolve deterministically and the UI reconciles to the server's truth.
5. A vote landing after resolution shows a clean "battle is over" message with no vote-count corruption.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Vote button live past the actual deadline | countdown uses a stale/local timestamp | Battles card countdown component |
| Any "upgrade"/license nag in Battles | a license check leaked into a battles code path | `includes/Battles/BattleController.php`, `BattleService.php` |
| Vote count double-increments under a race | no unique-constraint / no post-lock status re-check | `includes/Battles/BattleService.php::vote()` |
