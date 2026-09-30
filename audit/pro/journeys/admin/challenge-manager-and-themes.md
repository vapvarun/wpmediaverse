---
journey: challenge-manager-and-themes
plugin: wpmediaverse-pro
priority: high
roles: [administrator]
covers: [MV-CHL-010, MV-CHL-011, MV-CHL-012, autopilot, theme-library, challenge-manager-admin]
prerequisites:
  - "Pro active; mvs_challenges_enabled = 1; manage_mvs_settings capability"
estimated_runtime_minutes: 8
---

# Autopilot picks themes correctly, Theme Library enforces built-in-vs-custom rules, and Challenge Manager's lifecycle actions all confirm visibly

## Setup

- Admin `?autologin=admin`; `admin.php?page=mvs-theme-library`; `admin.php?page=mvs-challenges`

## Steps

### 1. Autopilot picks the next enabled+unused theme
- **Action**: enable Autopilot (`mvs_autopilot_enabled = 1`) with a day/hour; force the scheduled action (or wait); confirm a new challenge appears using the picked theme's name/description/slug.
- **Expect**: `pick_next_theme()` selects the next enabled+unused theme (shuffled by category); the theme is marked used; if every enabled theme has been used, the pool resets (`used=false` for all) and reshuffles before the next pick.
- **On fail**: `includes/Challenges/AutopilotService.php::pick_next_theme()`.

### 2. No eligible theme — logs, doesn't error, no listener expected
- **Action**: disable every theme in the Theme Library, then force the Autopilot run.
- **Expect**: no challenge created; `mvs_autopilot_no_theme_available` fires with no listener anywhere (confirmed log-only, no admin notice — this is documented as not a defect).

### 3. Toggling the feature reschedules Autopilot correctly
- **Action**: toggle `mvs_challenges_enabled` off then back on mid-week, with Autopilot enabled throughout.
- **Expect**: the recurring Action Scheduler job unschedules immediately on toggle-off and reschedules on the next `init` after toggle-on.
- **On fail**: `includes/Challenges/AutopilotService.php` `update_option_*`/`add_option_*` hooks.

### 4. Changing day/hour doesn't double-run
- **Action**: change the Autopilot day/hour setting after a job is already scheduled.
- **Expect**: reschedules cleanly, does not run the job twice for the same cycle.

### 5. Settings screen shows the real next-run state
- **Action**: view the Autopilot section of Settings -> Competitions.
- **Expect**: shows which theme will run next and the computed next-run timestamp (`get_status()`'s `next_theme`/`schedule`) — never leaves the admin guessing.

### 6. Theme Library: toggle, add custom, delete rules
- **Action**: toggle a built-in theme's enabled state; add a custom theme (name + description); try to delete a built-in theme; delete the custom theme.
- **Expect**: toggle flips `enabled` for that slug. Adding a theme auto-slugs via `sanitize_title()`, marks `custom => true`, rejects a slug collision. Deleting a BUILT-IN theme fails (`delete_theme()` returns false — built-ins can only be disabled); the custom theme deletes successfully.
- **On fail**: `includes/Admin/ThemeLibrary.php::handle_delete()`.

### 7. Delete control should not even appear on built-ins
- **Action**: inspect the theme grid's built-in rows.
- **Expect**: only a toggle control on built-ins, no visible Delete button — a visible-but-silently-refused Delete is a UX defect even though the data is safe.

### 8. Challenge Manager create/edit validation
- **Action**: create a challenge with title, theme, dates (start < end < voting_end), XP, cover; try creating one with `end` before `start`.
- **Expect**: valid creation succeeds with a visible success notice; invalid date ordering is rejected with a clear error — same validation the REST create path enforces.
- **On fail**: `includes/Admin/ChallengeManager.php::handle_save()`.

### 9. Every lifecycle action confirms visibly, no silent completion
- **Action**: on a scheduled-eligible challenge, click "Start Now"; on an active one, "End Entries"; on a voting one, "Finalize"; on any non-finalized one, "Cancel". Then attempt each transition on a challenge in the WRONG status (e.g. Finalize on a scheduled challenge).
- **Expect**: each correct-state transition redirects with a visible admin notice (`?started=1`, `?updated=1`, etc. -> `render_action_notice()`) — this exists because these used to complete silently with no confirmation. Wrong-state attempts return a clear `WP_Error`, not a silent no-op.
- **On fail**: `includes/Admin/ChallengeManager.php` — missing `render_action_notice()` call for a given action.

### 10. Cancel requires confirmation
- **Action**: click Cancel on an active challenge.
- **Expect**: a confirm dialog fires before the cancellation executes — not an immediate action on bare click.

### 11. Cancel refuses on an already-finalized challenge
- **Action**: click Cancel on a finalized challenge.
- **Expect**: refused with a clear message, not a silent no-op or state corruption.

### 12. List/tab counts match the frontend hub exactly
- **Action**: compare `compute_status_counts()` on the admin list against the counts shown on the Compete hub's Active Challenge card and `/media/challenges/` tabs.
- **Expect**: identical numbers everywhere.

### 13. Pagination at scale
- **Action**: verify (via code or a seeded 2000+ dataset) that the Challenge Manager list paginates with real LIMIT/OFFSET.
- **Expect**: yes — large-site checklist requirement.

### 14. 390px Theme Library grid
- **Action**: view the Theme Library grid at 390px.
- **Expect**: usable grid layout, no horizontal overflow.

## Pass criteria

1. Autopilot picks themes deterministically per the shuffle-and-reset rule, and no-eligible-theme never errors.
2. Toggling the feature or its schedule reschedules Autopilot cleanly, never double-runs.
3. Theme Library refuses to delete a built-in theme, and its UI never offers a Delete control that's silently refused.
4. Every Challenge Manager lifecycle action confirms visibly (success or explicit error), including Cancel's own confirm dialog.
5. Admin list/tab counts match the public hub's counts exactly; the list paginates at scale.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Autopilot re-creates a challenge with the same theme repeatedly | pool-reset/shuffle logic broken | `includes/Challenges/AutopilotService.php` |
| Built-in theme actually deletes | `delete_theme()` missing the `custom === true` guard | `includes/Admin/ThemeLibrary.php` |
| A lifecycle action completes with no visible notice | `render_action_notice()` not called for that action | `includes/Admin/ChallengeManager.php` |
| Admin counts disagree with the public hub | two separate count queries instead of one shared method | `includes/Admin/ChallengeManager.php::compute_status_counts()` vs `includes/REST/CompeteSummaryController.php` |
