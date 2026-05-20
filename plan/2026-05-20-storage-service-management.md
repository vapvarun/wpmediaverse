# Storage Service Management — admin control spec (model for next plugins)

Driven by the R2 rollout (2026-05-20). The R2 driver is verified working
end-to-end (see below), but switching storage service exposed an **admin-control
gap**: the admin has no proper, complete way to move all existing media onto the
service they just enabled, and no visibility into what lives where. This spec
defines the admin experience. Think as the site admin, not as someone with
WP-CLI.

## What's verified working (R2 driver) — not the gap
- `CloudflareR2\StorageDriver` (path-style, region=auto, account host): 13/13
  driver tests — small signed PUT, >50MB streamed cURL, special-char keys,
  exists true/false, delete, url(); admin Test Connection button; and **live
  display of image + video + audio** from the `r2.dev` public domain
  (image rendered, video `readyState=4` + ffmpeg poster, audio `readyState=4`).
- So the storage *driver* layer is solid. The gap is the *management UX*.

## The core behaviour (confirmed correct, to preserve)
1. **Media URL is computed from the enabled service** at render time (direct-CDN
   mode → active driver `url()`; otherwise the gated `/serve` proxy reads the
   local file). This is correct — do not add per-media backend tracking.
2. **Switching service does NOT move existing files.** Until migrated, old media
   404s under the new service (the `sunrise` symptom). This is expected, but the
   admin must be given control to fix it.
3. **Switch service ⇒ re-upload all existing media** to the new service is the
   intended remedy (`wp mvs migrate-storage --from=<old> --to=<new>` under the
   hood).

## Current admin state (`wpmediaverse-pro` CloudOpsManager) — the gaps
- Storage Management panel on Settings → Storage with two admin-post actions:
  "Move next 20" (migrate a batch to the active driver) + "Delete next 20"
  (cleanup local after verified upload). Batch size 20 per click.
- Counter: `CloudOps::count_candidates()` → a single `needs_migration` number.
- **Gaps for "proper control":**
  1. **No migrate-all with progress.** 70 media = 4+ manual clicks. Admin wants
     one control + a progress bar that runs to completion (chunked under the hood
     via Action Scheduler or repeated AJAX, but one click for the admin).
  2. **No per-service counters.** Admin can't see how media is distributed:
     "Local: 20 · BunnyCDN: 0 · R2: 0" with bytes. Needed to understand a switch.
  3. **No switch-service flow.** Changing Storage Driver should detect "N media
     still on <old>" and surface a clear "Migrate N items to <new> now" control
     + warn that media won't display until migrated.
  4. **Cloud-only media with no local source** (20 of 72 here) can't be
     re-uploaded from local — the UI must show these as "stranded on <service>"
     and offer migrate `--from=<that service>` rather than silently failing.

## Proposed admin UX (the model)
- **Storage Overview card** (top of Storage tab): a per-service table —
  Service | Status (Active/Configured) | Media count | Total bytes — computed
  from each media's resolvable location. Drives the rest.
- **"Migrate all to <active service>" control**: one button → chunked job with a
  live progress bar (X/Y, failures listed), resumable, idempotent. Reuses
  `CloudOps::migrate_one`; replaces "click Move next 20 repeatedly".
- **Switch-service guard**: on changing Storage Driver, inline notice
  "You have N media on <old>. New uploads go to <new>; existing media will not
  display until migrated. [Migrate now]" — never a silent break.
- **Stranded-media surfacing**: items whose source service is unreachable shown
  explicitly with the correct `--from` migrate option.
- **Per-service counters** feed both the overview and the switch guard.

## Why this is the portfolio model
Every Wbcom plugin that gains cloud storage will hit the same switch/migrate/
display/counter problem. Building the admin control once — overview table,
migrate-all-with-progress, switch guard, per-service counts — gives a reusable
pattern (and a reusable verification runbook, see
`docs/development/STORAGE-DRIVER-VERIFICATION.md`).

## Scope / sequencing (proposed, not yet built)
1. `CloudOps`: add `counts_by_service()` (per-service media count + bytes) and a
   resolver for "which service currently holds media N".
2. CloudOpsManager: Storage Overview card + migrate-all progress control
   (chunked AJAX/Action Scheduler) + switch-service guard notice.
3. Browser-verify per item; keep `wp mvs migrate-storage` as the power-user/CLI
   path underneath.

Acceptance: from the admin UI alone (no CLI), an admin can switch to R2, see the
per-service distribution, click once to migrate all existing media with a
progress bar, and have every image/video/audio display from R2 afterwards.

## Open decision
This is a sizable Pro feature. Confirm scope before building — particularly
(a) migrate-all engine (Action Scheduler vs chunked AJAX) and (b) how far to go
on stranded cloud-only media.
