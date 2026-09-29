---
journey: video-captions
plugin: wpmediaverse-pro
priority: high
roles: [member, administrator, anonymous]
covers: [MV-VID-008, MV-VID-009, MV-VID-010, MV-VID-011, MV-VID-012, MV-VID-013, captions, whisper, privacy-gap]
prerequisites:
  - "Both plugins active; OpenAI key configured under Settings > AI & Moderation for the happy paths"
  - "A video and an audio media item"
  - "Action Scheduler available"
estimated_runtime_minutes: 10
---

# Caption generation queues, reaps stale jobs, fails cleanly without a key or over the size cap, and the web player never renders a caption track — API-client only, and there is a real unfixed privacy gap on the read route

**Why this journey exists**: captions are generated via a remote Whisper API
call (never a local binary — this plugin has zero exec-family calls anywhere,
Coding Rule 21), entirely fire-and-forget from the upload path, with NO web UI
to consume the result — the same "API-client only" pattern as chapters, but
with one real defect chapters does NOT have: the captions READ route has no
privacy check at all, unlike chapters' 2.5.1 fix. This journey documents that
gap explicitly as a finding, not a "verified fine."

## Setup

- Site: `$SITE_URL`; media author `?autologin=<author>`; a different member `?autologin=<other>`.
- OpenAI key present for happy-path steps; removed/invalidated for the failure steps.

## Steps

### 1. Auto-caption on upload, with the master toggle on
- **Action**: enable `captions_auto` (under `mvs_pro_settings`), confirm a valid OpenAI key is set; upload a short video/audio file as the author.
- **Expect**: `TranscriptionService::on_media_uploaded()` queues `mvs_pro_transcribe_media` via Action Scheduler (falling back to `wp_schedule_single_event` 5s out if AS unavailable); `GET /media/{id}/captions/status` immediately reads `queued`.
- **On fail**: `includes/Captions/*` transcription queueing on `mvs_media_uploaded`.

### 2. Status transitions and completion
- **Action**: poll the status endpoint again after ~10-30s.
- **Expect**: `queued` -> `processing` -> `complete`; once complete, `GET /media/{id}/captions` returns a `vtt_url` at `{uploads}/mvs-captions/{media_id}.vtt`.

### 3. No progress indicator anywhere in the upload flow — by design
- **Action**: watch the upload flow's UI during captioning.
- **Expect**: nothing — captioning is silent background work after the upload response already returned; no progress bar exists and none should be expected.

### 4. Manual generate — permission and type gates
- **Action**: as `<other>` (non-owner, non-admin), POST `/media/{id}/captions/generate`. As the owner, POST generate on an IMAGE item. As the owner, POST generate on a valid video.
- **Expect**: non-owner -> 403 `mvs_pro_forbidden`. Image -> 422 `mvs_pro_captions_unsupported_type` "Captions can only be generated for video or audio media." Valid video -> 202 Accepted `{status:"queued", message:"..."}`.

### 5. Duplicate-generate while already queued is silently deduped
- **Action**: POST generate twice in quick succession on the same media.
- **Expect**: second call still returns 202 as if freshly queued, but server-side dedupes — no second job actually runs. A client cannot distinguish "just queued" from "already in flight" from the response alone (documented limitation, not a bug to silently fix without a decision).

### 6. THE PRIVACY GAP — captions read has no privacy check at all (confirmed defect, not verified-fine)
- **Action**: as `<other>` (no relation to the media), and again fully logged out, `GET /media/{private_id}/captions` and `GET /media/{private_id}/captions/status` on a PRIVATE or members-only video whose captions are complete.
- **Expect (actual, current behavior)**: succeeds — returns language, word count, duration, generation timestamp, and `vtt_url` to anyone who knows the media id, with NO privacy check. This is the exact bug class chapters had before the 2.5.1 fix, LEFT UNFIXED here.
- **Report, don't silently "fix"**: file this as a confirmed finding against `includes/Captions/*Controller`'s read permission callback (compare directly to the fixed chapters permission callback in `video-chapters.md` step 5) — this journey's job is to prove the gap exists and stays visible in the regression suite, not to patch it without a product decision.

### 7. Reaper marks a stuck job failed after 15 minutes and allows a clean retry
- **Action**: force (or simulate) a job stuck in `processing` for >15 minutes (`PROCESSING_TIMEOUT`); observe the next reaper tick (`mvs_pro_captions_reap_stale`, every 5 minutes); then re-request generation for the same media.
- **Expect**: reaper marks it `failed` with "Transcription timed out and was automatically stopped. Try generating captions again." and fires `mvs_pro_captions_reaped`; the re-request re-queues normally (status no longer `queued`/`processing`).

### 8. Reaper batches at scale
- **Action**: seed >100 stale-processing rows; trigger one reaper tick.
- **Expect**: keyset-paginated 100 rows per tick (`REAPER_BATCH_SIZE`), continuing via an async cursor for a backlog beyond that — never one long-running unbounded tick.

### 9. Missing/invalid Whisper key
- **Action**: blank the OpenAI key; POST generate.
- **Expect**: 503 `mvs_pro_provider_unavailable` "Transcription provider is not configured. Add your OpenAI API key under Settings > AI & Moderation." — refused BEFORE queuing, not a failed job.

### 10. Invalid key on an already-auto-queued job
- **Action**: with an invalid key and auto-captions on, upload a video.
- **Expect**: the job runs, Whisper responds non-200, `store_error()` sets `failed` with OpenAI's own error message as `captions_error` — visible only via the status endpoint's `reason` field.

### 11. Oversized file rejected before any API call
- **Action**: upload a >25MB video/audio file with a valid key and auto-captions on.
- **Expect**: rejected before any Whisper call, with "File is too large for Whisper (XX.XMB). Maximum is 25MB." stored as the failure reason. Confirm the exact-boundary behavior: `$file_size > MAX_BYTES` — 26214400 bytes exactly passes, one byte over fails.

### 12. Manual caption upload/replace/delete
- **Action**: PUT a non-"WEBVTT"-prefixed string; PUT valid WebVTT >500KB; PUT small valid WebVTT over an existing auto-generated caption; DELETE.
- **Expect**: 400 `mvs_pro_vtt_invalid`; 400 `mvs_pro_vtt_too_large` (500KB cap); valid replace deletes the old file, writes the new one with `provider:"manual"`, `language:"manual"`, "Captions saved successfully."; DELETE removes the file and clears all meta, 204.

### 13. HTML in submitted VTT is stripped, not rejected
- **Action**: PUT WebVTT whose cue text is wrapped in HTML tags.
- **Expect**: sanitizer strips ALL HTML before validating the WEBVTT header — formatting silently vanishes, no error.

### 14. Empty submission and delete-with-nothing-to-delete
- **Action**: PUT an empty string; DELETE on media with no existing captions.
- **Expect**: empty string -> 400 `mvs_pro_vtt_empty`; DELETE with nothing to delete -> still 204, no error (idempotent).

### 15. The web player never surfaces captions, even after generation succeeds — confirmed absence, not a bug to silently wire
- **Action**: with `captions_status: complete` and `vtt_url` populated, play the video in a browser and try the native `<video>` controls' CC button.
- **Expect**: no CC button at all — there is no `<track kind="subtitles">` anywhere in `render.php`. The `.vtt` file IS publicly fetchable at its URL (protected only by `Options -Indexes`, not auth) but undiscoverable by the browser. This must read as complete silence, not a broken feature — do not wire a `<track>` element without an explicit product decision, since that changes the file's exposure model from obscure-but-fetchable to directly discoverable in page source.

## Pass criteria

1. Auto-caption queues and completes silently, with correct status transitions and no upload-flow UI change.
2. Permission and media-type gates on manual generate hold; duplicate-generate dedupes server-side.
3. **Confirmed and reported (not silently fixed)**: the captions read route has no privacy check — a private video's caption metadata and `vtt_url` are readable by anyone who knows the media id.
4. The stale-processing reaper fires at 15 minutes, batches at scale, and allows a clean retry.
5. Missing key, invalid key, and oversized file each fail with their exact documented status/message.
6. Manual PUT/DELETE validate size, header, and HTML-stripping exactly as documented; DELETE is idempotent.
7. The web player renders no caption track and no CC button under any circumstance — confirmed absence.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Private video's captions readable by anyone | read permission callback has no privacy check (KNOWN, confirmed defect) | `includes/Captions/*Controller` read permission callback |
| Auto-caption never queues | `on_media_uploaded()` not hooked, or `captions_auto` not read | `includes/Captions/*` transcription queueing |
| Reaper never fires / never batches | `mvs_pro_captions_reap_stale` not scheduled, or unbounded query | reaper cron handler |
| Oversized file passes 25MB | boundary check uses `>=` where it should be `>`, or MAX_BYTES wrong | `Integrations/Whisper/CaptionProvider` |
| CC button appears in the player | a `<track>` element was added without a privacy decision | `mvs/media-player` `render.php` |
