# Pro REST API Reference

**Base URL:** `/wp-json/mvs-pro/v1/`

WPMediaVerse Pro registers its own REST namespace. All endpoints require an active Pro license. Authentication uses the same mechanism as the free API: pass `X-WP-Nonce` with a nonce from `wp_create_nonce( 'wp_rest' )`.

Unless noted, all endpoints require a logged-in user. Admin-level endpoints additionally require the `manage_mvs_settings` capability.

The API uses WordPress REST API rate limiting. Excessive requests return `429 Too Many Requests`.

---

## Competitions

### GET /competitions

List all active competitions (challenges, battles, and tournaments).

**Auth:** User

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `type` | string | (all) | Filter by type: `challenge`, `battle`, `tournament` |
| `status` | string | `active` | Filter by status: `active`, `pending`, `closed` |
| `per_page` | int | `10` | Items per page (max: 100) |
| `page` | int | `1` | Page number |

**Response:**

```json
{
  "items": [
    {
      "id": 5,
      "type": "challenge",
      "title": "Black and White Week",
      "status": "active",
      "starts_at": "2025-04-01T00:00:00Z",
      "ends_at": "2025-04-07T23:59:59Z",
      "entry_count": 34
    }
  ],
  "total": 3,
  "pages": 1
}
```

---

### GET /competitions/{id}

Get a single competition with full details and leaderboard.

**Auth:** User

**Response includes:** `rules`, `prizes`, `leaderboard` (top 10 entries by vote count), `user_entry` (current user's submitted entry if any).

---

### POST /competitions/{id}/entries

Submit a media entry to a competition.

**Auth:** User

**Body:**

```json
{ "media_id": 123 }
```

Returns `409 Conflict` if the user has already submitted an entry and the competition does not allow multiple entries.

---

### DELETE /competitions/{id}/entries/{entry_id}

Withdraw a competition entry. Only allowed before the competition closes.

**Auth:** User (entry owner only)

---

### POST /competitions/{id}/entries/{entry_id}/vote

Vote for a competition entry. One vote per user per competition.

**Auth:** User

**Body:**

```json
{ "value": 1 }
```

---

### POST /competitions (admin)

Create a new competition.

**Auth:** Admin

**Body:**

```json
{
  "type": "challenge",
  "title": "Spring Photo Challenge",
  "description": "Share your best spring photos.",
  "starts_at": "2025-04-01T00:00:00Z",
  "ends_at": "2025-04-07T23:59:59Z",
  "voting_method": "public",
  "max_entries_per_user": 1,
  "prizes": ["1st: Pro license", "2nd: Free theme"]
}
```

---

### PUT /competitions/{id} (admin)

Update a competition. Changing `starts_at` or `ends_at` on an active competition triggers a notification to existing entrants.

**Auth:** Admin

---

### DELETE /competitions/{id} (admin)

Delete a competition and all its entries. This action is irreversible.

**Auth:** Admin

---

## Media (Pro Extensions)

### GET /media/{id}/transcodes

List available transcode renditions for a video.

**Auth:** User (must have view access to the media)

**Response:**

```json
{
  "status": "complete",
  "renditions": [
    { "label": "720p", "url": "https://…/video-720p.mp4", "size_bytes": 14200000 },
    { "label": "480p", "url": "https://…/video-480p.mp4", "size_bytes": 7100000 },
    { "label": "360p", "url": "https://…/video-360p.mp4", "size_bytes": 3800000 },
    { "label": "hls",  "url": "https://…/playlist.m3u8",  "size_bytes": null }
  ]
}
```

`status` values: `pending`, `processing`, `complete`, `failed`.

---

### POST /media/{id}/transcodes

Manually trigger transcoding for a video that was uploaded before transcoding was enabled, or to re-transcode after changing quality settings.

**Auth:** Admin

**Response:** `202 Accepted`

---

### GET /media/{id}/chapters

List chapter markers for a video.

**Auth:** User (must have view access)

**Response:**

```json
{
  "chapters": [
    { "id": 1, "label": "Introduction", "time_seconds": 0 },
    { "id": 2, "label": "Main Feature",  "time_seconds": 120 }
  ]
}
```

---

### PUT /media/{id}/chapters

Replace all chapter markers for a video.

**Auth:** User (media owner or `edit_others_mvs_media`)

**Body:**

```json
{
  "chapters": [
    { "label": "Introduction", "time_seconds": 0 },
    { "label": "Main Feature",  "time_seconds": 120 }
  ]
}
```

---

### GET /media/{id}/resume

Get the current user's resume position for a video.

**Auth:** User

**Response:**

```json
{ "position_seconds": 342 }
```

---

### POST /media/{id}/resume

Save the current playback position. Called automatically by the Pro video player every 10 seconds.

**Auth:** User

**Body:**

```json
{ "position_seconds": 342 }
```

---

### GET /media/{id}/captions

List available caption tracks (WebVTT files) for a video.

**Auth:** User (must have view access)

**Response:**

```json
{
  "tracks": [
    { "id": 1, "language": "en", "label": "English", "url": "https://…/captions-en.vtt", "generated": true }
  ]
}
```

---

### POST /media/{id}/captions

Upload a manual caption track.

**Auth:** User (media owner or `edit_others_mvs_media`)

**Body (multipart/form-data):**

| Field | Required | Description |
|-------|----------|-------------|
| `file` | Yes | WebVTT file |
| `language` | Yes | BCP-47 language code (e.g., `en`, `fr`) |
| `label` | No | Human-readable label shown in the player |

---

### POST /media/{id}/captions/generate

Trigger OpenAI Whisper transcription to auto-generate captions.

**Auth:** Admin

**Response:** `202 Accepted`

---

### DELETE /media/{id}/captions/{caption_id}

Delete a caption track.

**Auth:** User (media owner or `edit_others_mvs_media`)

---

### GET /media/{id}/analytics

Get play analytics for a single video: total plays, unique viewers, average watch percentage, and a per-day play count for the last 30 days.

**Auth:** User (media owner) or Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `days` | int | `30` | Number of days of history to return (max: 365) |

**Response:**

```json
{
  "total_plays": 412,
  "unique_viewers": 308,
  "avg_watch_pct": 64,
  "daily": [
    { "date": "2025-04-01", "plays": 18 }
  ]
}
```

---

### POST /media/{id}/boost

Boost a media item so it appears at the top of the Explore feed for a set duration. Deducts from the user's boost balance.

**Auth:** User (media owner)

**Body:**

```json
{ "duration_hours": 24 }
```

---

### PUT /media/{id}/privacy

Update the privacy level of a media item. This is a Pro-only endpoint because it supports the advanced privacy options (`group`, `custom`, `presets`).

**Auth:** User (media owner or `edit_others_mvs_media`)

**Body:**

```json
{
  "privacy": "custom",
  "allowed_user_ids": [5, 12, 33],
  "expires_at": "2026-01-01T00:00:00Z"
}
```

---

## Admin

### GET /admin/quotas

List storage and media count quotas for all users or a filtered subset.

**Auth:** Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `user_id` | int | (all) | Filter to a single user |
| `exceeded` | bool | (all) | Set to `true` to list only users who have exceeded their quota |
| `per_page` | int | `20` | Users per page |
| `page` | int | `1` | Page number |

**Response:**

```json
{
  "items": [
    {
      "user_id": 42,
      "display_name": "Jane Smith",
      "storage_used_bytes": 524288000,
      "storage_limit_bytes": 1073741824,
      "media_count": 34,
      "media_limit": 100
    }
  ],
  "total": 150,
  "pages": 8
}
```

---

### PUT /admin/quotas/{user_id}

Override the quota for a specific user. Overrides take precedence over membership-level defaults.

**Auth:** Admin

**Body:**

```json
{
  "storage_limit_bytes": 2147483648,
  "media_limit": 200
}
```

Pass `null` for either field to remove the override and revert to the membership-level default.

---

### DELETE /admin/quotas/{user_id}

Remove all quota overrides for a user.

**Auth:** Admin

---

### GET /admin/analytics

Get site-wide video analytics: total plays, total watch time, top media by plays, and daily play counts.

**Auth:** Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `days` | int | `30` | Number of days of history (max: 365) |
| `top_n` | int | `10` | Number of top media items to include |

**Response:**

```json
{
  "total_plays": 9841,
  "total_watch_seconds": 1843200,
  "top_media": [
    { "media_id": 55, "title": "Summer Reel", "plays": 412 }
  ],
  "daily": [
    { "date": "2025-04-01", "plays": 320 }
  ]
}
```

---

## Error Responses

Pro endpoints use the same error format as the free API:

```json
{
  "code": "mvs_pro_license_inactive",
  "message": "WPMediaVerse Pro license is not active.",
  "data": { "status": 403 }
}
```

Additional Pro error codes:

| Code | Status | Meaning |
|------|--------|---------|
| `mvs_pro_license_inactive` | 403 | Pro license not active on this site |
| `mvs_transcode_unavailable` | 503 | FFmpeg not found or transcoding service unreachable |
| `mvs_quota_exceeded` | 403 | User has reached their storage or media count limit |
| `mvs_competition_closed` | 409 | Competition is no longer accepting entries |
| `mvs_already_entered` | 409 | User has already submitted an entry to this competition |
| `mvs_boost_insufficient` | 402 | User does not have enough boost balance |
| `mvs_caption_format` | 400 | Uploaded file is not valid WebVTT |
