# Pro REST API Reference

> Endpoints marked **(Pro)** require WPMediaVerse Pro 1.5.0+.

**Base URL:** `/wp-json/mvs-pro/v1/`

WPMediaVerse Pro registers its own REST namespace, `mvs-pro/v1`, alongside the free `mvs/v1` namespace. Pro must be active for any of these routes to be registered.

Authentication uses the same mechanism as the free API: pass an `X-WP-Nonce` header with a nonce from `wp_create_nonce( 'wp_rest' )` and include `credentials: 'same-origin'` so the request is tied to the logged-in user. Application Passwords (WP 5.6+) work for mobile/headless clients.

**Permission conventions used below:**

- **Public** — no authentication required (`__return_true` or open read). Some are rate-limited inside the service.
- **User** — any logged-in user (`is_user_logged_in`).
- **Owner/Admin** — the media owner or a user who can edit others' media.
- **Admin** — requires `manage_options` or `manage_mvs_settings` (noted per route).
- **HMAC** — no WordPress auth; request body is verified against an HMAC-SHA256 signature header.

Some feature areas only register their routes when the matching admin toggle is enabled (`mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`, `mvs_streaks_enabled`, `mvs_connectors_enabled`). When a feature is disabled its routes are not registered.

---

## Quota & Credits

User-facing quota summaries plus admin package/credit management and the signed external top-up webhook.

### GET /me/quota

Return the current user's quota summary (per-type usage and limits for image, video, audio).

**Auth:** User

---

### GET /me/quota/check

Lightweight pre-upload check: can the current user upload a given media type (and optional file size) right now?

**Auth:** User

**Parameters:**

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `media_type` | string | Yes | — | One of `image`, `video`, `audio` |
| `file_size` | int | No | `0` | Size in bytes, for storage-limit checks |

**Response:**

```json
{ "can_upload": true, "reason": "" }
```

---

### GET /me/credits/history

Return the current user's credit transaction history.

**Auth:** User

---

### GET /users/{user_id}/quota

Get a specific user's quota summary.

**Auth:** Admin

---

### POST /users/{user_id}/package

Assign a quota package to a user.

**Auth:** Admin

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `package_id` | int | Yes | Package to assign |

---

### POST /users/{user_id}/credits

Grant extra upload credits to a user for a specific media type.

**Auth:** Admin

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `media_type` | string | Yes | — | One of `image`, `video`, `audio` |
| `amount` | int | Yes | — | Credits to add (min `1`) |
| `note` | string | No | `""` | Optional ledger note |

---

### GET /packages

List all quota packages.

**Auth:** Admin

---

### POST /packages

Create a quota package.

**Auth:** Admin

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | — | Package name |
| `image_limit` | int | No | `0` | Image upload limit (0 = none) |
| `video_limit` | int | No | `0` | Video upload limit |
| `audio_limit` | int | No | `0` | Audio upload limit |
| `storage_bytes` | int | No | `0` | Storage cap in bytes |
| `is_default` | bool | No | `false` | Whether this is the default package |

---

### PUT /packages/{id}

Update a quota package.

**Auth:** Admin

---

### DELETE /packages/{id}

Delete a quota package.

**Auth:** Admin

---

### POST /credits/webhook

External credit top-up endpoint. Used by integrations that grant credits from an outside system.

**Auth:** HMAC — the request is **not** cookie/capability authenticated. The handler verifies the `X-MVS-Signature` header against `hash_hmac( 'sha256', $body, $secret )` using `hash_equals()`. Requests with an invalid or missing signature are rejected.

---

## Privacy

Advanced per-item and bulk privacy controls plus reusable per-user privacy presets.

### PUT /media/{id}/privacy

Update the privacy level of a single media item. Pro-only because it supports the advanced levels (e.g. specific-people, album-inherit).

**Auth:** Owner/Admin

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `privacy` | string | Yes | — | Privacy level (validated against the configured set) |
| `custom_users` | int[] | No | `[]` | User IDs allowed to view, when privacy is "Specific People" |
| `inherit_album` | bool | No | `false` | When true, the item follows its album's privacy |

---

### POST /media/bulk-privacy

Apply the same privacy level to many media items at once.

**Auth:** User (per-item ownership is enforced inside the service)

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `media_ids` | int[] | Yes | 1–100 media IDs to update |
| `privacy` | string | Yes | Privacy level to apply to all selected items |

---

### GET /privacy/presets

List the current user's saved privacy presets.

**Auth:** User

---

### POST /privacy/presets

Save a new privacy preset for reuse.

**Auth:** User

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | — | Preset name (≤100 chars) |
| `privacy` | string | Yes | — | Privacy level to store |
| `custom_users` | int[] | No | `[]` | User IDs to include when privacy is "Specific People" |

---

## Video — Transcoding

FFmpeg-backed transcoding actions and admin queue/server inspection.

### GET /media/{id}/transcodes

Retrieve stored transcode renditions and current job status for a video.

**Auth:** Owner/Admin

`status` values: `queued`, `processing`, `failed`, `complete`.

---

### POST /media/{id}/transcode

Trigger a transcode job for a video. Defaults to the configured presets when none are supplied.

**Auth:** Owner/Admin

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `presets` | string[] | No | Specific presets to run (each must be a known preset key) |

---

### DELETE /media/{id}/transcodes

Delete all transcoded files for a video.

**Auth:** Owner/Admin

---

### GET /transcode/status

Admin overview of the transcode job queue.

**Auth:** Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `all` | One of `queued`, `processing`, `failed`, `complete`, `all` |
| `per_page` | int | `20` | Jobs per page (1–100) |
| `page` | int | `1` | Page number |

---

### GET /transcode/config

Report FFmpeg availability and the configured transcode presets.

**Auth:** Admin

---

## Video — Chapters & Resume

### GET /media/{id}/chapters

List chapter markers for a video.

**Auth:** User (must be able to read the media)

---

### PUT /media/{id}/chapters

Replace all chapter markers for a video.

**Auth:** Owner/Admin (chapter-edit permission)

**Body:**

```json
{
  "chapters": [
    { "time_seconds": 0,   "title": "Introduction" },
    { "time_seconds": 120, "title": "Main Feature", "thumbnail_url": "https://…/chap.jpg" }
  ]
}
```

Each chapter requires `time_seconds` (int ≥ 0) and `title` (1–200 chars); `thumbnail_url` is optional.

---

### GET /media/{id}/resume

Get the current user's saved resume position for a video.

**Auth:** User

---

### POST /media/{id}/resume

Save the current playback position. Called periodically by the Pro player.

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `position` | number | Yes | Seconds into the video (≥ 0) |

---

### DELETE /media/{id}/resume

Clear the current user's resume position for a video.

**Auth:** User

---

## Captions

WebVTT caption retrieval, manual upload, Whisper generation, deletion, and job status.

### GET /media/{id}/captions

Return caption metadata (VTT URL, language, provider, word count, duration, generated-at). Returns `404` when no captions exist.

**Auth:** User (must be able to read the media)

---

### PUT /media/{id}/captions

Upload/replace a caption track by sending raw WebVTT content.

**Auth:** Owner/Admin

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `vtt_content` | string | Yes | The full WebVTT document |

---

### POST /media/{id}/captions/generate

Queue an OpenAI Whisper transcription job to auto-generate captions.

**Auth:** Owner/Admin

---

### DELETE /media/{id}/captions

Remove the caption track from a video/audio item.

**Auth:** Owner/Admin

---

### GET /media/{id}/captions/status

Poll the status of a caption generation job.

**Auth:** User (must be able to read the media)

---

## Analytics

Public play-event ingestion plus owner/admin analytics surfaces.

### POST /media/{id}/events

Record a video play/heatmap event. Public and rate-limited inside the service; mirrors the free `/media/{id}/view` route.

**Auth:** Public (rate-limited)

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `event_type` | string | Yes | — | One of the allowed event types |
| `position` | number | Yes | — | Playhead position in seconds (≥ 0) |
| `duration` | number | No | `0` | Segment duration in seconds (≥ 0) |
| `session_id` | string | Yes | — | Player session identifier (1–64 chars) |

---

### GET /media/{id}/analytics

Full analytics for a single media item, including the heatmap buckets.

**Auth:** Owner/Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `bucket_count` | int | `100` | Heatmap resolution (10–500 buckets) |

---

### GET /analytics/top

Top media by engagement.

**Auth:** Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `period` | string | `30d` | One of `today`, `7d`, `30d`, `all` |
| `limit` | int | `10` | Number of items (1–100) |
| `sort` | string | `engagement` | One of `engagement`, `plays`, `completion` |

---

### GET /analytics/overview

Site-wide analytics summary.

**Auth:** Admin

---

## Boosts

Spend gamification points to promote a media item's visibility.

### GET /boosts

List the current user's boosts.

**Auth:** User

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `""` | Filter by boost status |
| `per_page` | int | `20` | Items per page |
| `page` | int | `1` | Page number |

`X-WP-Total` is returned in the response header.

---

### POST /boosts

Create a boost for a media item. Returns `201` with the new `boost_id`.

**Auth:** User

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `media_id` | int | Yes | — | Media item to boost |
| `impressions_target` | int | No | `500` | Target number of impressions |

---

### GET /boosts/balance

Return the current user's point balance and the boost cost/limit settings.

**Auth:** User

**Response:**

```json
{ "balance": 1200, "cost_per_100": 50, "max_impressions": 5000 }
```

---

## Streaks

> Requires `mvs_streaks_enabled`.

### POST /streaks/buy-freeze

Purchase a streak-freeze token with gamification points. Fails with a `400` if the gamification plugin is inactive or the user lacks enough points.

**Auth:** User

**Response:**

```json
{ "freezes": 3, "balance": 1100 }
```

---

## Challenges

> Requires `mvs_challenges_enabled`. Themed photo challenges with entries, voting, and finalized results.

### GET /challenges

List challenges.

**Auth:** Public

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `status` | string | `""` | Filter by status |
| `per_page` | int | `20` | Items per page (max via `mvs_rest_pagination_max`, default 100) |
| `page` | int | `1` | Page number |

---

### POST /challenges

Create a challenge.

**Auth:** Admin

**Body (key fields):** `title` (required), `description`, `theme`, `cover_media_id`, `start_date` (required), `end_date` (required), `voting_end_date` (required), `max_entries_per_user` (default 1), and XP awards `xp_1st` / `xp_2nd` / `xp_3rd` / `xp_participation`.

---

### GET /challenges/{id}

Get a single challenge.

**Auth:** Public

---

### PUT /challenges/{id}

Update a challenge.

**Auth:** Admin

---

### POST /challenges/{id}/cancel

Cancel a challenge.

**Auth:** Admin

---

### GET /challenges/{id}/entries

List entries for a challenge.

**Auth:** Public

**Parameters:** `per_page` (default 20), `page` (default 1), `orderby` (default `votes`).

---

### POST /challenges/{id}/entries

Submit an entry to a challenge.

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `media_id` | int | Yes | Media item to enter |

---

### POST /challenges/{id}/entries/{entry_id}/vote

Vote for a challenge entry.

**Auth:** User

---

### DELETE /challenges/{id}/entries/{entry_id}/vote

Remove the current user's vote from an entry.

**Auth:** User

---

### GET /challenges/{id}/results

Get finalized results for a challenge.

**Auth:** Public

---

## Battles

> Requires `mvs_battles_enabled`. 1v1 photo battles.

### GET /battles

List battles.

**Auth:** Public

**Parameters:** `user_id` (default 0), `status` (default `""`), `per_page` (default 20), `page` (default 1).

---

### POST /battles

Create (challenge a user to) a battle.

**Auth:** User

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `opponent_id` | int | Yes | — | The user being challenged |
| `theme` | string | No | `""` | Optional battle theme |

---

### GET /battles/{id}

Get a single battle.

**Auth:** Public

---

### POST /battles/{id}/accept

Accept a battle challenge.

**Auth:** User

---

### POST /battles/{id}/decline

Decline a battle challenge.

**Auth:** User

---

### POST /battles/{id}/submit

Submit a media entry for a battle.

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `media_id` | int | Yes | Media item to submit |

---

### POST /battles/{id}/vote

Cast a vote in a battle.

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `voted_for_id` | int | Yes | The user (battle side) being voted for |

---

## Tournaments

> Requires `mvs_tournaments_enabled`. Single-elimination bracket tournaments.

### GET /tournaments

List tournaments.

**Auth:** Public

**Parameters:** `status` (default `""`), `per_page` (default 20), `page` (default 1).

---

### POST /tournaments

Create a tournament.

**Auth:** Admin

**Body (key fields):** `title` (required), `description`, `theme`, `cover_media_id`, `bracket_size` (default 8), `registration_start` (required), `registration_end` (required), `round_duration_hours` (default 48), `xp_round_win` (default 150), `xp_tournament_win` (default 500).

---

### GET /tournaments/{id}

Get tournament detail.

**Auth:** Public

---

### POST /tournaments/{id}/register

Register the current user for a tournament.

**Auth:** User

---

### DELETE /tournaments/{id}/register

Unregister the current user from a tournament.

**Auth:** User

---

### GET /tournaments/{id}/bracket

Get the tournament bracket.

**Auth:** Public

---

### GET /tournaments/{id}/participants

Get the tournament participants (display name + avatar only).

**Auth:** Public

---

### POST /tournaments/{id}/matches/{match_id}/submit

Submit a media entry for a bracket match.

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `media_id` | int | Yes | Media item to submit for the match |

---

### POST /tournaments/{id}/matches/{match_id}/vote

Vote in a bracket match.

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `voted_for_id` | int | Yes | The participant being voted for |

---

## Competitions (read-only roll-up)

### GET /competitions/active-summary

A single discovery roll-up combining the active challenge, open tournaments, and the most recent battles. Intentionally public so it can drive landing-page/widget discovery. When the requester is logged in, the response also includes their `my_activity`.

**Auth:** Public

**Response:**

```json
{
  "active_challenge": { "id": 5, "title": "Black and White Week" },
  "open_tournaments": [],
  "recent_battles": []
}
```

> There is no generic `/competitions` CRUD. Challenges, battles, and tournaments are managed through their own route groups above.

---

## Connectors

> Requires `mvs_connectors_enabled`. OAuth-based import/export connectors for external platforms (Flickr, etc.). Connector IDs are slugs (e.g. `flickr`).

### GET /connectors

List all registered connectors with each one's connection state.

**Auth:** User

---

### POST /connectors/{id}/connect

Initiate the OAuth flow for a connector.

**Auth:** User

**Body:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `use_custom` | bool | `false` | Use user-supplied API credentials instead of plugin defaults |
| `api_key` | string | `""` | Custom API key (when `use_custom` is true) |
| `api_secret` | string | `""` | Custom API secret (when `use_custom` is true) |
| `return_url` | string | `""` | Where to redirect after the OAuth callback (defaults to the connectors admin page) |

---

### POST /connectors/{id}/disconnect

Revoke and remove the connection.

**Auth:** User (must be connected to this connector)

---

### GET /connectors/{id}/status

Live validation of the connection.

**Auth:** User (must be connected)

---

### GET /connectors/{id}/photos

Browse the remote platform's photos.

**Auth:** User (must be connected)

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | `1` | Page number |
| `per_page` | int | `20` | Photos per page (1–30) |
| `album_id` | string | `""` | Restrict to a specific remote album/set |

---

### GET /connectors/{id}/albums

Browse the remote platform's albums.

**Auth:** User (must be connected)

---

### POST /connectors/{id}/import

Import selected remote photos into the media library.

**Auth:** User (must be connected)

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `photo_ids` | string[] | Yes | Remote photo IDs to import |

---

### POST /connectors/{id}/export

Export local media to the remote platform.

**Auth:** User (must be connected)

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `media_ids` | int[] | Yes | Local attachment IDs to export |

---

### POST /connectors/{id}/sync

Run an incremental delta sync of recently changed items.

**Auth:** User (must be connected)

---

## Admin

### POST /admin/gamification-welcome/dismiss

Dismiss the gamification first-run welcome banner (site-wide option).

**Auth:** Admin (`manage_mvs_settings`)

---

## Error Responses

Pro endpoints use the same error envelope as the free API:

```json
{
  "code": "mvs_pro_rest_forbidden",
  "message": "You do not have permission to manage WPMediaVerse Pro settings.",
  "data": { "status": 403 }
}
```

Common Pro error codes:

| Code | Status | Meaning |
|------|--------|---------|
| `mvs_pro_rest_forbidden` | 403 | Caller lacks the required Pro capability |
| `mvs_pro_no_captions` | 404 | No captions exist for the requested media |
| `mvs_pro_privacy_update_failed` | 400 | Privacy could not be updated (bad level or write failure) |
| `mvs_gamification_unavailable` | 400 | Gamification plugin not active (streak freeze) |
| `mvs_insufficient_points` | 400 | Not enough gamification points for the requested action |
