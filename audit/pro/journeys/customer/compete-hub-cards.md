---
journey: compete-hub-cards
plugin: wpmediaverse-pro
priority: high
roles: [administrator, anonymous, member]
covers: [MV-CMP-001, MV-CMP-003, MV-CMP-004, MV-CMP-005, MV-CMP-007, MV-CMP-011, compete-hub, first-run-view]
prerequisites:
  - "Both plugins active; mvs_competitions_enabled = 1"
  - "Auto-login mu-plugin available"
  - "Free WB Gamification plugin available to toggle on/off"
estimated_runtime_minutes: 8
---

# Each Compete hub card renders correctly when its feature is on, and the hub degrades gracefully when everything is quiet

**Why this journey exists**: `admin/06-competition-toggles-gate-their-own-section.md` already proves each toggle HIDES its card when off. This journey proves the opposite half — that when a feature IS on, its card's own content (empty state, populated state, edge cases) is correct — plus the points chip's graceful degradation and the first-run guided view, none of which the toggle journey exercises.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; member `?autologin=<member>`
- `mvs_competitions_enabled = 1`

## Steps

### 1. Active Challenge card — empty and populated
- **Action**: with `mvs_challenges_enabled = 1` and zero active challenges, load `/compete/`. Then create/activate one challenge with zero entries, reload.
- **Expect**: empty state shows a megaphone icon, "No active challenge right now. Check back soon!" with a "Browse Past Challenges" button to `/media/challenges/`. Populated: title, theme (hidden if it equals the title), entry count, countdown, progress bar with `role="progressbar"` + `aria-valuenow`/`aria-valuetext` bound reactively, up to N thumbnail previews — even with 0 entries this must not error or show a broken 0/0 state.
- **On fail**: `includes/Frontend/CompeteHubRenderer.php` Active Challenge section; `templates/compete-hub-body.php`.

### 2. Open Tournaments card — full and already-started tournaments
- **Action**: with `mvs_tournaments_enabled = 1`, seed one tournament with spots remaining, one that is full (`spots_remaining = 0`), and one already started (`can_register = false`). Reload the hub.
- **Expect**: the tournament with spots shows bracket size, spots-remaining count, and a Register button. The FULL tournament hides Register entirely (never disabled-and-clickable). The already-started tournament shows only "View Bracket" — no dangling Register button.
- **On fail**: `includes/REST/CompeteSummaryController.php` — `can_register`/`spots_remaining` computation.

### 3. Battle Arena card — zero completed battles, then a tie and a deleted-user battle
- **Action**: with `mvs_battles_enabled = 1` and zero completed battles, reload. Complete a tied battle (0-0). Point one side of a completed battle at a deleted user id, reload.
- **Expect**: with zero completed battles, "Recent Results" is hidden ENTIRELY, not an empty table. With completed battles, matchups show photos side by side; the winner gets a green checkmark badge and a `--winner` class. The tie resolves to the challenger (per the documented tie rule) and shows that winner clearly, not an ambiguous no-winner state. The deleted-user side shows an avatar/name fallback, not a broken image.
- **On fail**: `includes/Frontend/CompeteHubRenderer.php` Battle Arena section; `includes/REST/CompeteSummaryController.php` recent-battles query.

### 4. First-run guided view — only enabled modes appear
- **Action**: enable ONLY Battles (challenges/tournaments off), with zero battles anywhere; load `/compete/`.
- **Expect**: heading "Ready to compete?", lede "Put your photography up against the community. Here's how you can play." Only the Battle mode card is listed; single CTA "Start a photo battle" — no secondary "Browse challenges"/"View tournaments" buttons since those modes are off.
- **On fail**: `templates/compete-hub-body.php` first-run block — rendering a mode card for a disabled sub-feature.

### 5. First-run view with all three sub-toggles off (master still on)
- **Action**: turn all three sub-toggles off, master `mvs_competitions_enabled` stays on; reload.
- **Expect**: the firstrun view must not render zero mode-cards with a dangling "Here's how you can play" heading and nothing underneath — verify what actually renders in this state (an actionable "every competition feature is disabled" message, per the toggle journey's step 8, or an equivalent honest empty state).
- **On fail**: same template, missing an explicit all-off branch.

### 6. Points balance chip degrades gracefully without WB Gamification
- **Action**: as `<member>`, load `/compete/` with WB Gamification plugin INACTIVE. Then activate WB Gamification and reload.
- **Expect**: inactive — no points chip at all, never a broken "0 points" ghost chip. Active — chip shows the formatted balance, label `"<n> points"`, `aria-label` "<n> reward points. Earn them by competing and engaging; spend them on boosts. Select to open your rewards hub." linking to the configured `wb_gam_hub_page_id`, or a plain non-linked span with the same label if no hub page is configured.
- **On fail**: `includes/Frontend/CompeteHubRenderer.php` points-chip block — missing a plugin-active guard, or hardcoding a link when no hub page id exists.

### 7. Guest CTAs are the "create an account" message everywhere
- **Action**: log out; reload the hub with all three features on and populated.
- **Expect**: every section's CTA for a logged-out visitor reads "Create an account… to <verb>" — never a silent link that leads nowhere.

## Pass criteria

1. Each card's empty state matches the documented copy exactly; a populated state with 0 entries/spots/results never errors.
2. Full/already-started tournaments never show a clickable-but-broken Register.
3. Zero completed battles hides Recent Results entirely; a tie resolves visibly to the challenger; a deleted user shows a fallback avatar.
4. The first-run view lists only enabled modes, and the all-off state is explicit, never a dangling empty heading.
5. The points chip never renders without the gamification backend, and never dead-links when no hub page is configured.
6. Every guest CTA is the "create an account" message.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| "0/0" or error on a zero-entry challenge card | progress bar / thumbnail strip not guarding zero state | `templates/compete-hub-body.php` |
| Register button visible-but-disabled on a full tournament | button hidden condition wrong | `includes/REST/CompeteSummaryController.php` |
| "Recent Results" shows an empty table | missing hidden-when-zero guard | `includes/Frontend/CompeteHubRenderer.php` |
| Ghost "0 points" chip with no gamification plugin | missing `class_exists`/active check | points-chip render block |
