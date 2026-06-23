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
| F3 | Med | VERIFY | Video chapters + resume not wired into `media-single.php` player context (only the `media-player` block). Chapters/resume may be API-only with no player UI on the main video page. | `templates/media-single.php` vs `src/blocks/media-player/render.php` |
| F4 | Med | VERIFY | Play analytics `analyticsUrl`/`sessionId` not in `media-single.php` context — analytics likely dropped on the primary video surface; and members may have no frontend view of their own analytics. | same as F3; `Pro AnalyticsController` |
| F5 | Med | VERIFY | Image optimization may not WebP-optimize thumbnail variants on upload (only the original / explicit bulk), so Explore grid tiles could serve JPEG not `<picture>`/WebP. Verify `thumb_*_webp` meta after upload. | `Services/ImageOptimizationService`, `Core/TemplateHelpers` |
| F6 | Low | VERIFY | Flickr OAuth callback may not surface a success notice on the frontend `/my-media/` return; connectors panel gated by `mvs_connectors_enabled`. | Pro `Connectors/*` |
| F7 | Med | VERIFY | `mvs_duplicate_action = warn`: confirm the REST upload response actually exposes `duplicate_warning` to the UI (getter exists; controller wiring unverified). | `Services/UploadService`, `REST/MediaController` |
| F8 | Med | VERIFY | All-Media optimization column: confirm `ImageOptimizationService::META_*` constant names match the keys the optimizer writes (key drift would make the column always read "Not optimized"). | `Admin/MediaListPage`, `Services/ImageOptimizationService` |

Note: dashboard count/cover (M19 B1/B2) is the same class as the collections two-store bug — already
fixed for collections (Pro bridge) and verified clean for albums (single table). Re-verify on the
dashboard tabs when promoting `C.member.dashboard-hub`.
