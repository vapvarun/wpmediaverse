---
journey: challenges-notifications-and-mobile
plugin: wpmediaverse-pro
priority: high
roles: [member]
covers: [MV-CHL-009, MV-CHL-016, challenges-emails, mobile-390]
prerequisites:
  - "Pro active; mvs_challenges_enabled = 1"
  - "WP mail sending observable (mail-catcher or log plugin)"
  - "A challenge with a human creator, at least one entrant, and finalization with a clear winner + at least one non-winner"
estimated_runtime_minutes: 6
---

# All four challenge email events fire exactly once each, and the flow is usable at 390px

## Setup

- Creator A (human, via admin create — not Autopilot); entrants B, C, D
- Mail-catcher/log active

## Steps

### 1. Creator confirmation email
- **Action**: as admin, create a challenge with A as its human creator (A has a valid email).
- **Expect**: A receives "[Sitename] Your challenge is set up." In-app: no counterpart exists for this event (confirmed — three notification types exist, not four; this email has none).
- **On fail**: `includes/Challenges/ChallengeNotificationListener.php` hooked to `mvs_challenge_created`.

### 2. Entrant "entry received" email
- **Action**: as B, submit an entry.
- **Expect**: B receives "Entry received: {title}"; an in-app "entry received" notification also fires.

### 3. Winner placement emails on finalize
- **Action**: finalize with B, C, D entrants where B wins 1st, C 2nd, D 3rd.
- **Expect**: each of B/C/D receives "{1st/2nd/3rd} place in {title}!" with rank-aware subject/body; in-app "won" notification also fires for each.

### 4. Consolation email to non-winners
- **Action**: finalize a challenge with 4+ entrants (top 3 + at least one 4th).
- **Expect**: the 4th+ entrant receives 'The "{title}" challenge has ended' (not a placement email); in-app "participated" notification also fires. No placement email is sent to a non-winner.

### 5. No email ever sent for won/lost outside these four templates
- **Action**: review all mail sent across steps 1-4.
- **Expect**: exactly four distinct templates fired, none outside them; each is filterable (`mvs_challenge_email_created_*`, `_entry_*`, `_winner_*`, `_participant_*`).

### 6. Missing/invalid email fails silently
- **Action**: set one entrant's email to an invalid string (or empty), finalize.
- **Expect**: no fatal; that recipient's email is skipped (`is_email()` check), everyone else's still sends.
- **On fail**: `includes/Challenges/ChallengeNotificationListener.php` — missing an `is_email()` guard before `wp_mail()`.

### 7. Autopilot-created challenge skips the creator email without erroring
- **Action**: let (or force) an Autopilot-created challenge run through submission/finalization.
- **Expect**: no creator "set up" email attempted (no human creator) and no fatal from the missing-creator case.

### 8. Zero-entrant finalization sends nothing
- **Action**: finalize a challenge with zero entries.
- **Expect**: no winner/participant emails sent (entrant loop finds nobody) — no error.

### 9. Mobile 390px — list, detail, submit, vote
- **Action**: at 390px, load the challenges list, open detail, submit an entry, and cast a vote.
- **Expect**: cards stack full-width, no horizontal overflow; tap targets (submit, vote, tab switches) meet the 40px minimum; the upload/media-picker modal is usable without zoom.
- **On fail**: Pro challenges stylesheet missing a `@media` breakpoint.

### 10. Long text doesn't overflow at 390px
- **Action**: seed a long theme title/description; view it at 390px.
- **Expect**: wraps correctly; countdown text doesn't truncate awkwardly at narrow width.

## Pass criteria

1. Exactly four distinct email templates fire, each to the correct recipient(s), never mixed up.
2. A missing/invalid recipient email is skipped silently, other recipients still receive theirs.
3. Autopilot-created and zero-entrant challenges never error on the missing-creator/missing-entrant paths.
4. The full flow is usable at 390px with no horizontal scroll and >=40px tap targets.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Winner also gets the consolation email | rank-vs-non-winner branching wrong | `includes/Challenges/ChallengeNotificationListener.php` |
| Fatal on a recipient with no email | missing `is_email()` guard | same file |
| Autopilot challenge fatals on finalize (no creator) | creator-email path not null-safe | same file |
| Horizontal scroll on challenge cards at 390px | missing mobile breakpoint | Pro challenges stylesheet |
