---
journey: competition-toggles-gate-their-own-section
plugin: wpmediaverse-pro
priority: high
roles: [administrator, member]
covers: [competitions, feature-toggles, compete-hub, optionality]
prerequisites:
  - "Both plugins active; Pro licence active"
  - "Auto-login mu-plugin available"
  - "At least one challenge with status=active and one tournament exist"
estimated_runtime_minutes: 6
---

# Switching one competition off removes it everywhere, while the others keep working

**Why this journey exists**: Competitions are optional per feature — an owner can run Challenges without Tournaments, or Battles alone. Each toggle must remove its own surface completely: admin screen, REST routes, front-end route, and its section of the Compete hub. The hub is the weak point: `CompeteHubRenderer::any_feature_on()` gates the WHOLE hub, but the hub's data comes from `CompeteSummaryController`, whose `get_active_challenge()` and `get_open_tournaments()` query the competitions table with no toggle check. So with Challenges off and Tournaments on, the hub still advertised "Active Challenge" and its CTA led to a 404 — the plugin offering a feature the owner switched off. The journey IS the regression test.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=admin`; member: `?autologin=<member>`
- Options: `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_battles_enabled`
- Restore every toggle to its starting value at the end.

## Steps

### 1. Baseline: all three on
- **Action**: `wp option update mvs_challenges_enabled 1` (same for tournaments, battles); visit `$SITE_URL/compete/` as a member.
- **Expect**: hub shows the Active Challenge card, Open Tournaments and Battle Arena.

### 2. Switch Challenges off
- **Action**: `wp option update mvs_challenges_enabled 0`.
- **Expect**: no error; other toggles untouched.

### 3. The admin surface is gone
- **Action**: load `/wp-admin/` and `/wp-admin/admin.php?page=mvs-challenges`.
- **Expect**: no "Photo Challenges" item in the MediaVerse → Competitions submenu; the direct URL returns 403. Tournaments and Photo Battles remain listed.
- **On fail**: `includes/Core/Plugin.php` (~938, manager construction gated by the service).

### 4. The REST surface is gone
- **Action**: `GET /wp-json/mvs-pro/v1/challenges`, `/challenges/{id}`, `/challenges/{id}/entries`; `POST /challenges/{id}/entries`.
- **Expect**: every one returns 404 `rest_no_route`. `/tournaments` and `/battles` still return 200.
- **On fail**: `includes/Core/Plugin.php` (~584, controller registration gate).

### 5. The front-end route is gone
- **Action**: visit `/media/challenges/` and `/media/challenges/{id}/` as a member.
- **Expect**: HTTP 404 for both.
- **On fail**: `includes/Frontend/GamificationTemplateLoader.php::load_template()`.

### 6. The hub no longer advertises it  ← the assertion that failed
- **Action**: visit `/compete/` as a member and as a logged-out visitor.
- **Expect**: NO "Active Challenge" card, no Enter Challenge CTA, no challenge counters. Tournaments and Battles sections still render. No link on the hub leads to a 404.
- **On fail**: `includes/REST/CompeteSummaryController.php::get_active_challenge()` (~181) and `get_open_tournaments()` (~260) do not check the feature toggle; `templates/compete-hub-body.php` renders whatever the summary returns.

### 7. Repeat for Tournaments and Battles
- **Action**: restore Challenges, switch Tournaments off, repeat steps 3-6 against `/media/tournaments/`, `/wp-json/mvs-pro/v1/tournaments`, `admin.php?page=mvs-tournaments`, and the hub's Open Tournaments section. Then the same for Battles.
- **Expect**: identical isolation each time.

### 8. All three off
- **Action**: switch all three off; visit `/compete/`.
- **Expect**: hub 404s for members; an admin sees the actionable "every competition feature is disabled" notice.

### 9. Restore
- **Action**: restore all toggles to their step-1 values.

## Pass criteria

ALL hold, for each feature in turn:
1. Its admin submenu disappears; its admin URL returns 403.
2. Its REST routes return 404 `rest_no_route`, including writes.
3. Its front-end routes return 404.
4. Its section vanishes from the Compete hub, for members and logged-out visitors.
5. The other features keep working throughout — pages, REST and hub sections.
6. No hub link ever points at a 404.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Hub still shows a disabled feature's card | summary query has no toggle check | `includes/REST/CompeteSummaryController.php:181,260` |
| Hub CTA 404s | same as above; route correctly gated, hub is not | `includes/Frontend/GamificationTemplateLoader.php` |
| Admin screen still listed | manager constructed outside the service gate | `includes/Core/Plugin.php:938` |
| REST still answers | controller registered outside the toggle gate | `includes/Core/Plugin.php:563,584,605` |
