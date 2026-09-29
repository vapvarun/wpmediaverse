---
journey: video-chapters
plugin: wpmediaverse-pro
priority: high
roles: [member, anonymous]
covers: [MV-VID-001, MV-VID-002, MV-VID-003, video-chapters, privacy-preserving-404]
prerequisites:
  - "Both plugins active"
  - "A video media item (author + a private video for privacy testing)"
estimated_runtime_minutes: 5
---

# Video chapters: REST-only CRUD, correct sort, and a private video never leaks its chapter list

**Why this journey exists**: chapters have NO web UI anywhere — this is
deliberately an API-only feature for a native app/API client, and a QA pass
that finds no button for it is correct, not a bug. The one real defect class
here is privacy: a private video's chapters must return 404, never 403 — 403
would confirm existence to someone who shouldn't even know the video is there
(the exact bug class fixed for chapters in 2.5.1, still open for captions per
`video-captions.md`).

## Setup

- Site: `$SITE_URL`; video author `?autologin=<author>`; a different member `?autologin=<other>`.
- A public video and a private video, both with `media_type = video`.

## Steps

### 1. Author sets chapters, sorted regardless of submit order
- **Action**: as the author, `PUT /mvs-pro/v1/media/{id}/chapters` with `{"chapters":[{"time_seconds":120,"title":"Part 2"},{"time_seconds":30,"title":"Intro"}]}` (out of order).
- **Expect**: 200; `GET` the same route returns them sorted ascending by `time_seconds` (Intro then Part 2), stored via `media_repository->set($id,'chapters',...)`.
- **On fail**: whichever chapters controller/service handles the PUT — sort-on-write or sort-on-read must exist somewhere.

### 2. Malformed entries silently dropped, not erroring
- **Action**: PUT a chapters array including one entry missing `title` and one with a non-numeric `time_seconds`.
- **Expect**: 200; response omits the malformed entries; no error surfaced.

### 3. A non-author, non-moderator is refused
- **Action**: as `<other>` (neither author nor `moderate_mvs_media`), PUT chapters on the author's video.
- **Expect**: 403 `mvs_pro_forbidden` "You do not have permission to edit chapters for this media."

### 4. Non-video media type is refused with a specific code
- **Action**: PUT chapters against an image or audio media id.
- **Expect**: 422 `mvs_pro_not_video` "Chapters are only available for video media."

### 5. Private video, unrelated viewer — privacy-preserving 404, never 403
- **Action**: as `<other>` (no grant), `GET /mvs-pro/v1/media/{private_id}/chapters`. Repeat logged out.
- **Expect**: both get 404 `mvs_pro_not_found` "Media item not found." — never 403, so the response never confirms the private video exists.
- **On fail**: chapters GET permission callback — check it consults Free's privacy model before the video-type check (the exact class of bug fixed for chapters in 2.5.1).

### 6. Chapters surfaced in the core media REST response, sorted, only for video
- **Action**: `GET /mvs/v1/media/{id}` for (a) a video with chapters set, (b) a video with none, (c) an audio or image item.
- **Expect**: (a) `chapters` array populated + sorted; (b) `chapters: []` (always present for video, never omitted — the documented empty state, not a bug); (c) no `chapters` key at all.
- **On fail**: `ChapterService::append_chapters_to_response()`.

### 7. Chapter privacy is symmetric with the delivery route
- **Action**: with a private video, compare the chapters GET refusal (step 5) against whatever the media item's own privacy-gated read returns.
- **Expect**: both agree — a listing/response that says "not found" and a different surface that says "found" would be the same disagreement bug this plugin's other privacy journeys guard against.

### 8. Edge cases
- **Action**: PUT an empty `chapters: []` array; PUT a `thumbnail_url` that isn't a valid URI; PUT a title >200 chars; PUT chapters against a nonexistent media id.
- **Expect**: empty array clears all chapters (200, `chapters: []`); invalid URI dropped by `format: uri` REST arg validation; over-length title rejected by `maxLength`; nonexistent id returns 404 before the video-type check even runs.

## Pass criteria

1. Chapters always return sorted by `time_seconds` regardless of submit order; malformed entries drop silently.
2. Only the author or a `moderate_mvs_media` holder can write; wrong media type returns 422.
3. A private video's chapters are 404 for anyone without access, INCLUDING logged out — never 403.
4. The core media REST response appends `chapters` only for video media, always present (possibly empty) for video, absent for everything else.
5. All documented edge cases (empty array, invalid URI, over-length title, nonexistent id) behave exactly as specified.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Private video's chapters return 403 instead of 404 | permission callback checks ownership before privacy | chapters REST controller's permission callback |
| Chapters not sorted on read | sort missing on GET, only applied on write (or vice versa) | `ChapterService`/chapters controller |
| `chapters` key present on a non-video media item | `append_chapters_to_response()` missing the media-type early-return | wherever that method lives |
| Malformed entry causes a 500 instead of silent drop | validation throwing instead of filtering | the PUT handler's chapter-array sanitizer |
