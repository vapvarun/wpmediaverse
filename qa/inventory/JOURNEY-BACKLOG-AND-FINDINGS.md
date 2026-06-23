# Journey backlog + findings — gap burn-down (2026-06)

Output of the functionality-journey burn-down. Each journey below closes a coverage gap from
`FUNCTIONALITY-JOURNEYS.md`. They are drafted-but-not-yet-promoted into `AGENT_SMOKE_RUNBOOK.md`
(promote incrementally). The FINDINGS section is the actionable bug backlog the journeys surfaced —
that is the real point: journeys exist to catch broken flows before customers do.

## Status legend
- DRAFTED = journey written here, ready to promote into the runbook's C/E sections.
- CONFIRMED = bug verified at code level; fix landed or pending.
- VERIFY = plausible defect flagged by the journey draft; must be reproduced before fixing
  (never fix blindly).

---

## Drafted journeys (condensed; full contracts promote into runbook C/E)

**Member**
- `C.member.dashboard-hub` (M19) — `/my-media/`: anon gate -> login round-trip -> 4 tabs each fetch
  on switch, populated + empty + loading states, active tab survives client-side nav; every action
  (upload/delete/unfavorite) reflects on its tab without reload.
- `C.member.edit-profile` (M81) — logged-out gate -> edit (name/bio/avatar/dm_access/online) with
  pending->success feedback -> avatar validation (type/size) -> changes reflected on public profile.
- `C.member.online-status-dot` (M82) — two-layer gate: admin `mvs_show_online_status` + per-user
  `_mvs_show_online`; dot shows/hides correctly across both; admin `nobody` overrides user.
- `C.member.block-user` (M35) — block -> blocked user's media hidden from feed, follow refused, DM
  refused with a readable message -> view blocked list -> unblock reverses all. (See FINDING F2.)
- `C.member.upload-popular-tag-pill` (M78) — popular tag pills load in the upload modal; click
  appends to tags (comma-joined, dedup, modal stays open); pill-added tags survive upload.
- `C.member.albums-lifecycle` (M37-M41) — PROMOTED to runbook already.
- `E.collections-lifecycle` (M42/M43) — PROMOTED to runbook already.

**Pro / media**
- `E.video-chapters` (M67) — define chapters (PUT chapters) -> appear in REST + player -> resume
  position writes/reads, clears at 95%. (See FINDING F3.)
- `E.video-play-analytics` (M68) — play events write to `mvs_play_events` -> owner reads own
  analytics, admin reads aggregate, Stats tab renders. (See FINDING F4.)
- `E.image-optimization` (M80) — WebP/AVIF siblings produced on upload (setting-gated) -> frontend
  serves `<picture>`/`srcset`; size-compare discard guard; toggle off stops new siblings.
  (See FINDING F5.)
- `E.flickr-connect` (M69) — OAuth connect -> connected state visible -> browse/import (dedup) ->
  feed renders -> disconnect clears all meta. (See FINDING F6.)

**Admin / privacy**
- `C.admin.online-status-wire` (O23/M82) — admin toggle visibly changes the dot. (FINDING F1.)
- `C.admin.duplicate-action-wire` (O25) — warn/skip/allow each produce a distinct, user-visible
  upload outcome. (FINDING F7.)
- `C.admin.strip-exif-wire` (O26) — strip ON removes GPS from the saved file; OFF retains it
  (verify by reading file EXIF, not just the DB row).
- `C.admin.all-media-optimization` (O40) — All Media optimization column reads real meta; Optimize /
  Repair-thumb row actions run and notify. (FINDING F8.)
- `E.privacy-pro-ui.grant-access` (M45/M79) — RECLASSIFIED: grant-access member UI is **Pro**, not
  Free. Free ships only the REST/DB infrastructure (`AccessController`, `mvs_access_grants`); the
  member-facing "Specific People" picker is Pro. Journey belongs in section E.

---

## FINDINGS (the actionable backlog)

| ID | Severity | Status | Finding | Where |
|----|----------|--------|---------|-------|
| F1 | High | CONFIRMED, FIXED | `mvs_show_online_status = followers` was a no-op (filter only handled `nobody`), so "Followers only" leaked online status to everyone. Added the `followers` branch (viewer must follow target). | `Core/Plugin.php` online-status filter |
| F2 | High | CONFIRMED | Block-user is REST-only: `POST/DELETE /users/{id}/block` + server enforcement exist, but NO frontend button in any template/block. Server-complete feature unreachable by members (violates frontend-only principle). Needs a Block control on profile + chat + a blocked-list view. | `templates/*`, `Social/ReportService` |
| F3 | Med | CONFIRMED (deferred) | Resume position is write-only — neither `media-single.php` nor the `media-player` block seeds `currentTime` from `GET .../resume`, so playback never resumes. Chapters reach the client only via the REST media response, with no player chapter UI. Feature wiring (player JS + context), not a one-liner — separate task. | `templates/media-single.php`, `src/blocks/media-player`, Pro `VideoController` |
| F4 | Med | FIXED | Play analytics `analyticsUrl`/`sessionId` were absent from `media-single.php`, so events dropped on the primary media page. Now wired (mirrors the block; Pro-gated). Member-facing analytics view is a separate enhancement, not a bug. | `templates/media-single.php` |
| F5 | Med | NOT A BUG | `UploadService::generate_thumbnails()` emits a WebP sibling + `thumb_*_webp` meta per variant during upload (separate path from the bulk `optimize_media`), so Explore grid tiles serve `<picture>`/WebP. | `Services/UploadService:1016-1038`, `Core/TemplateHelpers:402-409` |
| F6 | Low | VERIFY (pending) | Flickr OAuth callback success notice on the frontend `/my-media/` return — not yet reproduced (Pro + live OAuth). | Pro `Connectors/*` |
| F7 | Med | NOT A BUG | The upload REST response DOES expose the warning: `MediaController:751-753` calls `get_last_duplicate_warning()` and sets `$data['duplicate_warning']`. | `REST/MediaController:751` |
| F8 | Med | NOT A BUG | The All-Media column reads via the `ImageOptimizationService::META_*` constants (single source of truth, no literal-key drift). | `Admin/MediaListPage:529-548` |

Note: dashboard count/cover (M19 B1/B2) is the same class as the collections two-store bug — already
fixed for collections (Pro bridge) and verified clean for albums (single table). Re-verify on the
dashboard tabs when promoting `C.member.dashboard-hub`.
