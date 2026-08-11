# Plan: Pro competitions test-suite triage (Boost / Challenge / Battle)

**Date:** 2026-08-11
**Type:** Test-suite health, Pro plugin only
**Basecamp:** [Pro unit suite is 83 red at HEAD](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10184313297) and its duplicate [[Dev] Pro unit suite is 40% red](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10181028130) — both in Bugs, both describe the same underlying gap.

## Why this is a plan, not a quick fix

The Basecamp card that opened this work was explicit, and worth repeating verbatim because it is the whole reason this file exists instead of a string of ad-hoc test edits:

> Whether the tests or the code are wrong is the actual question, and it must
> be answered per test rather than assumed: a test asserting the old contract
> is a stale test, but a test catching a real contract break is a shipped
> bug. Do not mass-update assertions to make the suite green — that converts
> a signal into silence.

A site owner never sees a PHPUnit run. What they see is whether Challenges and
Battles actually work. A red test suite that gets "fixed" by loosening
assertions is a **more** confusing outcome than a red test suite left honestly
red — it looks stable while hiding exactly the kind of contract break the
suite exists to catch. This plan exists so the fix is real, not cosmetic.

## Current, honestly-measured state (2026-08-11)

Run through Local's actual PHP binary (the system `phpunit` was picking up a
mismatched Xdebug/opcache build and never gave a trustworthy number before
today — see `plan/document-library.md` §24.1 for that specific correction).

**Full suite: 530 tests, 12 errors, 27 failures = 39 problems.** Down from the
83 the Basecamp card recorded on 2026-08-09, but the drop happened as a side
effect of unrelated documents work, not because this area was addressed:

| Suite | Problems | Status |
|---|---:|---|
| `BoostServiceTest` | 15 (was 16) | **Root-caused and fixed** — see below |
| `ChallengeServiceTest` | 13 | **Investigated, NOT the same root cause** — see below |
| `BattleServiceTest` | 8 | **Not yet investigated** |
| `DeliveryControllerTest` | 3 | **Not yet investigated** — pre-existing, was never actually about cross-suite pollution (see §24.2 item 4 in `document-library.md`) |

### What actually happened with BoostServiceTest — the confirmed root cause

The original card's diagnosis ("cross-test pollution with `DeliveryControllerTest`,
fix with a `tearDown()` cleanup") was **wrong**. Disproved directly:
`BoostServiceTest` failed identically whether run alone or combined with
`DeliveryControllerTest`; `DeliveryControllerTest` showed zero effect from
`BoostServiceTest` in either direction. There was no pollution between these
two suites to find.

The real cause: `Plugin::points_backend_available()` correctly reports
`false` because the separate `wb-gamification` plugin isn't installed in this
test environment, so `BoostService::create()` correctly refuses every boost
with `503 mvs_boost_gamification_unavailable` — **production-correct
behaviour for a site without wb-gamification**, not a bug. Nothing in the
test suite had ever stubbed the points backend, so every boost-creation test
hit the real refusal path instead of the logic it meant to test.

Fixed: `tests/PointsBackendStub.php` (in-memory `wb_gam_get_user_points()` +
`\WBGam\Engine\PointsEngine::debit()`), wired once from `tests/bootstrap.php`.
Building it surfaced a real PHP gotcha worth remembering for whoever touches
this next: **a bare `function foo() {}` declared inside a method body, in a
file under a namespace, silently binds to `<namespace>\foo`, not the global
function the product code checks with `function_exists()`.** Confirmed by
instrumenting the exact line — it "declared" with no error, and
`function_exists()` still returned false. Fixed via `eval()` with an explicit
`namespace { ... }` block, the only way one file can declare something in a
*different* namespace than the one it's written in.

Result: `BoostServiceTest` 16 problems → 14. **Not fully clean** — a separate,
unrelated bug remains (see Open threads below).

### What ChallengeServiceTest actually shows — genuinely different, not yet triaged

Investigated on 2026-08-11 far enough to know it is **not** the same
missing-stub class of failure. Four representative failures:

1. `test_submit_duplicate_media_returns_error` expects error code
   `mvs_challenge_duplicate_media`; the code returns `mvs_challenge_invalid_media`.
   Could be a renamed error code the test never got updated for (stale test),
   or the duplicate-media check genuinely stopped distinguishing "duplicate"
   from "invalid" and both now fall through to the generic path (real bug).
2. `test_multiple_entries_allowed_when_limit_permits` — submitting a normal,
   valid entry is refused with `mvs_challenge_invalid_media` when the test
   expects success. Reads like a real validation regression, not a stale
   assertion — a test expecting SUCCESS getting a hard refusal is the
   opposite direction from "test expects an old error code".
3. `test_max_entries_per_user_enforced` — got an int (success) where a
   `WP_Error` (refusal) was expected. The per-user entry limit may not be
   enforced at all, or the test's setup no longer produces the state that
   should trigger it.
4. `test_list_entries_returns_submitted_entries` and a `vote()` `TypeError`
   are downstream cascades of #2/#3's setup failing to produce valid entries
   — not independent findings.

**`BattleServiceTest` (8 problems) has not been looked at yet at all.** No
claim is made about its root cause either way.

## Phased plan

### Phase 1 — cheap, isolates signal from noise (do this first)

Wire `PointsBackendStub::install()`'s effect into `ChallengeServiceTest` and
`BattleServiceTest` the same way `BoostServiceTest` now gets it (via
`tests/bootstrap.php`, already global — confirm both test classes' `set_up()`
call `PointsBackendStub::reset()` and their `tear_down()` calls it again, the
same pattern already in `BoostServiceTest`). Re-run both suites.

**This will NOT fix challenge/battle's failures** — the four failures
above are not gamification-refusal shaped (no `WP_Error` mentioning points or
gamification anywhere in the four samples). But it removes any doubt: if the
stub changes the numbers at all, that fraction of the 13+8 problems *was*
the same root cause as Boost and this phase fixes it for free. Whatever
remains after this phase is Phase 2's actual scope, measured honestly instead
of guessed.

### Phase 2 — per-test triage (the real work, do not skip or rush)

For every remaining failure in `ChallengeServiceTest` and `BattleServiceTest`,
answer ONE question and record the answer against the test, not just make it
pass:

- **Stale test**: the code's current contract is correct and intentional: a
  prior change legitimately renamed an error code, changed a return shape, or
  altered validation rules, and the test was never updated. Fix: update the
  assertion, and leave a one-line comment naming what changed and when (grep
  `git log` / `git blame` on the touched method for the real answer, don't
  guess).
- **Real bug**: the test is asserting behaviour the product still promises
  (duplicate-media detection, per-user entry caps, valid-entry acceptance) and
  the code has drifted away from it. Fix: fix the code, not the test. This is
  the class of failure that would surface to a site owner as "my Photo
  Challenge let someone submit the same photo twice" or "the entry limit
  doesn't work."

Given failure #2 above (a *valid* entry being refused) and #3 (a limit that
may not be enforced), there is a real chance at least one of `ChallengeServiceTest`'s
13 problems is case B, not case A. **Do not assume Boost's diagnosis
generalizes** — that was exactly the mistake in the original card's "cross-test
pollution" theory, and this plan exists to not repeat that shape of mistake
with a different guess.

Suggested order, cheapest signal first:
1. `test_submit_duplicate_media_returns_error` (one assertion, one error code — fast to resolve either way).
2. `test_multiple_entries_allowed_when_limit_permits` (real validation logic, worth understanding before the cascades below it make sense).
3. `test_max_entries_per_user_enforced` (paired with #2 — same submission path).
4. The two cascade failures, likely resolve for free once #2/#3 do.
5. Then repeat this whole process for `BattleServiceTest`, which has not been touched yet and may have its own, third kind of root cause.

### Phase 3 — stop the regression (the card's own request, still undone)

The original Basecamp card said explicitly: **"Add the Pro unit suite to
`bin/local-ci.sh` so it can never rot silently again. That is the change that
actually prevents recurrence."** This has not been done. Once Phase 1-2 bring
the suite to a real, understood, green (or intentionally-skipped-with-a-reason)
state, wire it into the local-CI pipeline (`wpmediaverse-pro/CLAUDE.md`'s own
"Local CI pipeline" table currently has no PHPUnit stage at all — Free's does
not either, worth checking both). Without this phase, the exact failure mode
that produced an 83-red suite nobody noticed can happen again on the next
untested change.

## Non-goals

- Fixing `DeliveryControllerTest`'s 3 problems as part of this plan — track
  separately, it showed zero interaction with `BoostServiceTest` either
  direction and its cause is unknown, not assumed related.
- Touching `TournamentServiceTest` — named in the original card as "the
  largest cluster" but not reproduced in today's 39-problem count, meaning
  something already fixed it between 2026-08-09 and today. Worth a note on
  the Basecamp card, not new investigation, unless a fresh run shows it red
  again.
- Any change to production challenge/battle code without Phase 2's per-test
  verdict recorded first. No mass-fixing.

## Acceptance criteria

- [ ] Phase 1 run: `ChallengeServiceTest` + `BattleServiceTest` re-measured with the stub wired in; delta recorded even if zero.
- [ ] Every remaining `ChallengeServiceTest` failure has a recorded stale-test-or-real-bug verdict with evidence, not a guess.
- [ ] Every `BattleServiceTest` failure investigated at least once (currently zero).
- [ ] Full suite count recorded again after Phase 2, compared honestly against today's 39 (both plugins' `CLAUDE.md` files updated, not just this plan file).
- [ ] `bin/local-ci.sh` (or `wpmediaverse-pro`'s equivalent) runs the Pro PHPUnit suite so this cannot silently rot to 39+ again.
- [ ] Basecamp cards #10184313297 and #10181028130 updated with the final honest number and merged/closed as duplicates of each other.

## Open threads this plan does not resolve (tracked elsewhere)

- `BoostService::create()`'s separate, unrelated `$wpdb->insert()`/`get_row()`
  bug found while fixing the gamification stub (`test_create_boost_sets_correct_points`
  and the `test_format_boost_*` tests) — `plan/document-library.md` §24.2 item 4.
- Admin IA's remaining live-browser test plan (8 rows, code-verified but not
  click-tested) — `plan/2026-08-09-admin-ia-reorganization-plan.md`.
