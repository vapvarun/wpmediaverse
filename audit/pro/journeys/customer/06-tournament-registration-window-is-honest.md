---
journey: tournament-registration-window-is-honest
plugin: wpmediaverse-pro
priority: high
roles: [member]
covers: [tournaments, compete-hub, registration, dead-cta]
prerequisites:
  - "mvs_tournaments_enabled = 1"
  - "One tournament whose registration window has CLOSED but status is still active"
  - "One tournament whose registration window is OPEN"
estimated_runtime_minutes: 5
---

# The hub only offers Register while registration is really open

**Why this journey exists**: `CompeteSummaryController::get_open_tournaments()` selects tournaments with `status IN ('registration','active')` and never compares `registration_end` to now. `register_url` is the tournament's detail URL — a link, not the register action — and `spots_remaining` is bracket size minus participants. So a tournament that started in July, with registration long closed, was still listed on the Compete hub as "11 spots left" with a **Register** button; a member who clicked it landed on the tournament page, which offers no register control and no explanation, while `POST /tournaments/{id}/register` answers 400 "Registration has closed." A member cannot tell whether they failed or the site did.

## Setup

- Site: `$SITE_URL`; member: `?autologin=<member>`
- Fixture A (closed): tournament `status=active`, `settings.registration_end` in the past, participants < bracket_size.
- Fixture B (open): tournament `status=registration`, `registration_end` in the future.

## Steps

### 1. Hub with a closed-registration tournament
- **Action**: visit `/compete/` as a member.
- **Expect**: fixture A appears (it is a live event worth seeing) but is labelled In Progress with a **View Bracket** action. It must NOT show "Register" and must NOT advertise spots left.
- **On fail**: `includes/REST/CompeteSummaryController.php::get_open_tournaments()` (~260) — no `registration_end` check; `templates/compete-hub-body.php:333-341`.

### 2. Hub with an open tournament
- **Action**: same page, fixture B.
- **Expect**: shows "N spots left" and a **Register** control that performs registration (or leads to a page whose primary action is Register).

### 3. Registering actually works
- **Action**: use the Register control for fixture B.
- **Expect**: `POST /wp-json/mvs-pro/v1/tournaments/{B}/register` returns 200/201; the member's state flips to registered (button becomes Registered / Leave); a reload keeps it.

### 4. The closed tournament explains itself
- **Action**: open fixture A's page `/media/tournaments/{A}/`.
- **Expect**: the page states registration is closed (or shows the bracket/round state). A member is never left on a page with no action and no reason.
- **On fail**: `templates/tournaments-body.php` (registration CTA block, ~128).

### 5. The API agrees with the UI
- **Action**: `POST /tournaments/{A}/register`.
- **Expect**: 400 `mvs_tournament_reg_closed` — and no UI anywhere offered that action.

## Pass criteria

1. No Register control is shown for a tournament whose registration window has closed or whose bracket is full.
2. "Spots left" appears only while registration is open.
3. Register works end to end for an open tournament and survives a reload.
4. A closed tournament's page says why registration is unavailable.
5. Every hub CTA leads to a page whose primary action matches the CTA's promise.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| "Register" on an in-progress tournament | `status IN ('registration','active')` with no window check | `includes/REST/CompeteSummaryController.php:272` |
| Register button navigates instead of registering | `register_url` = detail URL | `includes/REST/CompeteSummaryController.php:302` |
| Detail page offers nothing | registration CTA hidden with no closed-state message | `templates/tournaments-body.php:128` |
