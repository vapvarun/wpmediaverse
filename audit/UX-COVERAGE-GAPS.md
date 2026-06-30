# REST → UX Coverage Gaps (Free + Pro)

_Generated 2026-06-30. Lens: the "Three entry points per data store" rule — every member-facing
data feature must be reachable through (1) member frontend UX, (2) backend/admin UX, (3) REST API.
An endpoint with no UX to drive it is dead weight; admins/members feel lost._

Method: two parallel coverage audits over `mvs/v1` (Free, 87 routes) and `mvs-pro/v1` (Pro, ~39 routes)
against `src/blocks/`, `templates/`, `includes/Admin/`, and `assets/js/`. Stories (create tile + IG
viewer) and the simplified upload modal were verified COVERED as of this date.

## Tier 1 — truly unreachable (no member AND/OR no admin UX; dead-weight endpoints)

| # | Feature | Endpoints | State | Fix |
|---|---------|-----------|-------|-----|
| 1 | **Per-media access rules & grants** (Free) | `GET/POST /media/{id}/rules`, `DELETE …/rules/{id}`, `POST /media/{id}/grant`, `DELETE …/grant/{user_id}`, `GET /me/grants` | No member UI, no admin UI. Only *collection* rules have UI. Owner can't say "who can see this" item; member can't grant a person access. | Add a "Who can see this" panel to the media edit modal + a grants list. |
| 2 | **Streaks member widget orphaned** (Pro) | `/streaks/buy-freeze` + `templates/partials/streak-widget.php` | The widget file is complete but **nothing renders it** (no block/shortcode/layout includes it); `assets/js/streaks-store.js` enqueued by nothing. REST + admin toggle live, member surface unreachable. | Wire the widget into compete-hub / dashboard, or delete it as dead weight. |

## Tier 2 — member website flows half-wired (report/manage)

| # | Feature | Endpoints | State | Fix |
|---|---------|-----------|-------|-----|
| 3 | **Report-a-member** (Free) | `POST /users/{id}/report` | `profile-actions.php` renders Follow / Message / Block only — no Report button. Only *media* reporting exists. Member-side report pipeline half-wired. | Add a Report action to the profile overflow menu. |
| 4 | **Blocked-users list** (Free) | `GET /me/blocked` | Zero consumers. Unblock only by revisiting the person's profile. No "Blocked members" screen. | Add a "Blocked members" list (dashboard settings) with unblock. |
| 5 | **Follower / following lists** (Free) | `/users/{id}/followers`, `/following`, `/me/followers` | Counts shown (`member-photos/render.php:197`) but no browsable list — "120 followers" opens nothing. | Make the counts open a followers/following list modal. |

## Tier 3 — website member UX missing, app-covered (Pro media features REST-only on web)

| # | Feature | Endpoints | State | Fix |
|---|---------|-----------|-------|-----|
| 6 | **Quota balance** (Pro) | `/me/quota` | App-only. Website member can't see own storage/upload quota; admin assigns packages (`QuotaPage.php:45`). | Surface quota in the upload modal / a dashboard widget. |
| 7 | **Video captions** (Pro) | `/captions/{media_id}` | Whisper VTT injected into REST only; no `<track kind="captions">` in any web player (a11y miss). | Render the VTT track in single-media/lightbox player. |
| 8 | **Video chapters + resume** (Pro) | `/videos/{id}/chapters`, `/resume` | Appended to REST only; no chapter markers / resume-on-load in web player. | Add chapter markers + resume to the web player. |
| 9 | **Advanced privacy** (Pro, minor) | `/privacy/settings` | Presets + custom-user lists + album inheritance are REST-only; web member uses Free basic dropdown only. | Add a presets/custom-user web UI (or accept app-only). |

## Tier 4 — app feeders / by-design (NOT defects)

- `/media/bulk` (Free) — no web multi-select; admin All Media uses its own server-side bulk. App/API only. (Could become a member power feature later.)
- `/app/config`, `/app/interests`, `/me/interests`, `/users/suggested`, `/me/onboarding/complete` (Free) — mobile-app feeders, no web UX by design (Instagram-per-site app scope).
- Write receipts / serve / signed-url / transcode pipeline / push device-register — INTERNAL, no standalone UX expected.

## Manifest staleness

The Pro manifest (`audit/pro/manifests/manifest.rest.json`) omits two registered route groups found in code:
**streaks** (`wpmediaverse-pro/includes/Streaks/StreakController.php` → `/streaks/buy-freeze`) and
**push** (`/push/register-device`). Reconcile on the next `/wp-plugin-onboard --refresh` (run from the Free plugin).

## Summary

- **Fully covered (member + admin):** Free media/albums/collections/feed/comments/favorites/reactions/
  moderation/AI-usage/notifications/profile/stats/tags/users/messages; Pro stories/connectors+feeds/
  battles/challenges/tournaments/compete-hub/leaderboard/boosts/collections/video-analytics(admin).
- **Real gaps:** 9 (2 Tier-1 unreachable, 3 Tier-2 half-wired member flows, 4 Tier-3 web-UX-missing/app-covered).
- **By design:** app feeders + internal receipts — not gaps.
