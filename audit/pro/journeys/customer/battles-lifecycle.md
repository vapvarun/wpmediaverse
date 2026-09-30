---
journey: battles-lifecycle
plugin: wpmediaverse-pro
priority: critical
roles: [member]
covers: [MV-BAT-001, MV-BAT-002, MV-BAT-003, MV-BAT-004, MV-BAT-007, battles-create-accept-submit-vote]
prerequisites:
  - "Both plugins active; mvs_competitions_enabled = 1; mvs_battles_enabled = 1"
  - "Auto-login mu-plugin available; two members A and B, each owning at least one published photo"
estimated_runtime_minutes: 8
---

# A battle goes create -> accept -> submit -> vote end to end, with every negative guard holding

**Why this journey exists**: `customer/03-battle-win-xp-configured.md` already proves resolution + configured-XP snapshot. This journey proves the front half of the lifecycle it assumes as setup — create, accept/decline, submit, and cast a vote — plus the list/deep-link surface, none of which `03` asserts directly.

## Setup

- Site: `$SITE_URL`; member A `?autologin=<A>`; member B `?autologin=<B>`; third member C `?autologin=<C>` for voting
- `mvs_competitions_enabled = 1`, `mvs_battles_enabled = 1`

## Steps

### 1. A challenges B by username
- **Action**: as A, open `/media/battles/`, click "Challenge Someone", search B by username, optionally set a theme, submit.
- **Expect**: toast "Challenge sent to %s!"; `POST /mvs-pro/v1/battles` returns success; battle created `pending`; one challenger row, one opponent row, one match row created atomically.
- **On fail**: `includes/Battles/BattleController.php` create route; `includes/Battles/BattleService.php::create()`.

### 2. Negative guards on creation
- **Action**: A tries to challenge themselves; A tries to challenge a nonexistent user id; A tries to challenge B again while the first battle is still pending.
- **Expect**: self-challenge -> `mvs_battle_self` "You cannot challenge yourself."; nonexistent opponent -> `mvs_battle_invalid_opponent` 404; duplicate active battle with the same opponent -> `mvs_battle_exists` 409 "An active battle already exists with this user."

### 3. B accepts
- **Action**: as B, open the Pending tab, click Accept.
- **Expect**: toast "Challenge accepted! Submit your photo now."; battle status moves to `accepted`/`active`.
- **On fail**: `includes/Battles/BattleController.php` accept route.

### 4. Stale double-action is clean
- **Action**: reload the same pending-tab page as B (now stale) and click Accept again.
- **Expect**: clean "already handled" state (`mvs_battle_not_pending`, 400), not a raw error or a duplicate action.

### 5. A non-opponent cannot accept/decline
- **Action**: as C, `POST /mvs-pro/v1/battles/{id}/accept`.
- **Expect**: 403 (`mvs_battle_not_opponent`).

### 6. Both participants submit
- **Action**: A submits an existing photo; B submits an existing photo (or uploads new via the sibling upload button — verify it's a visible sibling control, not nested, so a member with an empty library still has a path).
- **Expect**: each submit toasts "Photo submitted! Good luck!"; the submitting player sees feedback that they're waiting on their opponent; once BOTH have submitted, status auto-advances to `voting`.
- **On fail**: `includes/Battles/BattleService.php::submit()`.

### 7. Submission negative guards
- **Action**: a non-participant (C) tries to submit; A tries to submit media they don't own.
- **Expect**: non-participant -> 403; media not owned -> `mvs_battle_invalid_media` 400.

### 8. Voting phase — third party votes, participants cannot
- **Action**: as C, open the Voting tab, cast a vote for A's photo. As A or B, try to vote in your own battle.
- **Expect**: C's vote succeeds — toast "Vote recorded!", vote button hides and is replaced by a "Voted" badge. A/B's self-vote attempt is rejected 403 (`mvs_match_self_vote`-equivalent for battles) and the UI shows voting disabled/hidden for participants, not a clickable-then-403 control.
- **On fail**: `includes/Battles/BattleController.php` vote route.

### 9. Anonymous sees no vote button
- **Action**: log out; view the same voting battle.
- **Expect**: a "Log in to vote" link to the login page — never a clickable control that then 401s.

### 10. List opens on a non-empty tab; deep link works
- **Action**: visit `/media/battles/` — note which tab is open by default. Deep-link to the battle created above via its `mvs_battle_id` query var.
- **Expect**: list opens on the first tab that actually has battles (not a hardcoded empty "Voting" tab). Deep link shows an "All battles" back-button (hidden otherwise) and returns to the tab this battle belongs in.

### 11. Deep link to a nonexistent battle id
- **Action**: deep-link to a battle id that doesn't exist.
- **Expect**: "This battle is not available." — never a blank page.

## Pass criteria

1. Create -> accept -> submit -> voting transitions all happen exactly as documented, with correct toasts.
2. Self-challenge, invalid-opponent, and duplicate-active-battle are all rejected with the documented codes.
3. A stale accept/decline shows a clean "already handled" state, not a raw error.
4. Only a non-participant can vote; a participant sees voting UI hidden, not a 403-on-click control.
5. Anonymous visitors get a login prompt, never a dead vote button.
6. The list opens on a populated tab; deep links work and 404 cleanly for a missing id.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Duplicate battle created with the same opponent | `mvs_battle_exists` check missing/scoped wrong | `includes/Battles/BattleService.php::create()` |
| Stale Accept click raises a raw error | `mvs_battle_not_pending` guard missing | `includes/Battles/BattleController.php` |
| Participant can click Vote and it 403s | voting UI not hidden for participants | Battles page template / Interactivity store |
| List always opens on an empty tab | `TabPicker::first_with_items()` not wired for battles | `includes/Competitions/TabPicker.php` usage in `Battles\Renderer` |
