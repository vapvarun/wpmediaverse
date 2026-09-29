---
journey: video-resume-playback
plugin: wpmediaverse-pro
priority: high
roles: [member, anonymous]
covers: [MV-VID-004, MV-VID-005, MV-VID-006, MV-VID-007, video-resume, resume-playback]
prerequisites:
  - "Both plugins active; Free's mvs/media-player block"
  - "Two member accounts; a video longer than 120 seconds"
estimated_runtime_minutes: 8
---

# Resume playback is per-user, silent, throttled, and never surfaces an error to the viewer

**Why this journey exists**: resume position is entirely invisible-by-design —
no toast, no "your position was saved" indicator, and every client-side failure
path is deliberately swallowed so it can never interrupt playback. This journey
proves the three hardcoded gates (120s minimum duration, 5s minimum position,
95% near-end cutoff) hold, that saves are throttled (not on every `timeupdate`
tick) and survive a tab close via `keepalive`, and that resume is genuinely
per-user with zero cross-user leakage — none of which a functional smoke test
would catch since "nothing visibly happens" is the correct behavior in most of
these steps.

## Setup

- Site: `$SITE_URL`; Member A and Member B, both `?autologin=<login>`.
- A single video >120 seconds, embedded via `mvs/media-player`.

## Steps

### 1. Auto-resume on reload, same user
- **Action**: as Member A, play the video to ~1:00, close the tab (or navigate away) before 95% completion. Reopen the same video page as Member A.
- **Expect**: on `loadedmetadata`, the player calls `GET /mvs-pro/v1/media/{id}/resume`, seeks `<video>.currentTime` to ~60s, shows a "Resumed at 1:00" chip with a "Start over" button that auto-hides after 6 seconds.

### 2. Resume is per-user, not per-video
- **Action**: reopen the same video as Member B.
- **Expect**: no seek, no chip — Member B has no resume position for this video (`user_meta` key `_mvs_resume_{media_id}`, scoped to the requesting user).

### 3. No resume, no REST call, for a logged-out visitor
- **Action**: open the same video logged out.
- **Expect**: no seek, no chip, and NO REST call at all — the client short-circuits on an empty `resumeUrl` rather than calling the endpoint and getting refused.

### 4. Saves are throttled, not per-tick
- **Action**: as Member A, play continuously past the resume position for >30 seconds, watching network requests to `POST /resume`.
- **Expect**: at most one save per 15 seconds during continuous play — not one per `timeupdate` event.

### 5. Pause triggers an immediate save
- **Action**: pause at a distinctive timestamp (e.g. 0:45).
- **Expect**: an immediate `POST {position: 45}` fires on pause, independent of the 15s throttle.

### 6. Tab-close save survives via keepalive
- **Action**: start playing, then close/hide the tab mid-playback (or trigger `pagehide`/`visibilitychange`).
- **Expect**: `flushResumeOnHide()` fires a best-effort `fetch(..., {keepalive:true})` — reopen shortly after and confirm the position near the close point was saved.

### 7. Server auto-clears near-end saves
- **Action**: seek/play to >=95% of duration, allow a save to fire (pause there).
- **Expect**: `ResumeService::save_position()` deletes the stored position instead of storing it once `position >= duration * 0.95` — reopening shows no resume chip.

### 8. Completion and manual "Start over" both clear the position immediately
- **Action**: let the video play to `ended`. On a different session with a saved position, click "Start over" on the resume chip.
- **Expect**: both call `DELETE /resume` (204, no body) and hide the chip; "Start over" additionally resets `currentTime = 0` immediately client-side, without waiting for the DELETE response — no confirm dialog (non-destructive view-state, not data destruction).

### 9. Every client failure path is silent, never visible
- **Action**: simulate a resume GET/POST/DELETE network failure (block the endpoint or throttle network).
- **Expect**: playback proceeds unaffected; no error toast, no console-visible user-facing message — the catch blocks are empty by design. A QA report of "no confirmation progress saved" is not a bug.

### 10. Direct API client — endpoints and validation
- **Action**: `GET /mvs-pro/v1/media/{id}/resume` logged out; then as an authenticated user GET/POST/DELETE; then POST a negative `position`.
- **Expect**: logged-out GET -> 401 `mvs_pro_unauthorized`; authenticated calls succeed as documented — POST returns the freshly-read-back position (re-read via `get_position()` after `save_position()`, so a position auto-cleared by the 95% rule reports accurately rather than echoing raw input); negative `position` rejected by REST schema (`minimum: 0`) before reaching the service.

### 11. Resume boundaries — exact edges
- **Action**: test a video exactly at 120.000s duration (should NOT qualify — only strictly over); a saved position within 5s of start (ignored, nothing to resume).
- **Expect**: both edge cases behave exactly as the hardcoded constants specify.

### 12. Direct API client can (harmlessly) resume a non-video item
- **Action**: as an authenticated user, POST a resume position against an audio, image, or document media id directly via the API.
- **Expect**: succeeds — the resume endpoints only check the media row exists, with no video-type check like chapters has. Confirm nothing breaks; note this is intentionally more permissive than the "for a video" framing in the docs, unreachable through the frontend player (which only calls resume for video).

## Pass criteria

1. Resume seeks and shows the chip only for the same user who left playback, never cross-user, never for a logged-out visitor (and makes no REST call in that case).
2. Saves throttle to ~1/15s during play, save immediately on pause, and survive a tab close via `keepalive`.
3. The 95% near-end auto-clear, the 120s minimum-duration gate, and the 5s minimum-position gate all hold at their exact boundaries.
4. Completion and "Start over" both clear the position and hide the chip; "Start over" resets playback instantly without waiting for the network.
5. Every resume-related network failure is invisible to the viewer.
6. The direct API surface matches its documented status codes and validation, including the non-video-item permissiveness.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Resume position leaks to a different user | user-meta key not scoped by `get_current_user_id()` | `ResumeService::save_position()`/`get_position()` |
| Save fires on every `timeupdate` | throttle removed from the client | `mvs/media-player` block's resume JS |
| Chip stays visible past 95% completion | server-side auto-clear threshold missing | `ResumeService::save_position()` |
| A network failure shows a visible error | empty catch block replaced with a surfaced error | resume JS fetch wrapper |
| Logged-out visitor triggers a REST call | client not short-circuiting on empty `resumeUrl` | `mvs/media-player` `render.php` / resume JS init |
