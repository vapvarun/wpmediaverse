# Pro REST API Reference

> Endpoints marked **(Pro)** require WPMediaVerse Pro. Sections tagged with a version (e.g. "New in 1.9.0") were added in that release or later — check your installed Pro version if a route 404s.

**Base URL:** `/wp-json/mvs-pro/v1/`

WPMediaVerse Pro registers its own REST namespace, `mvs-pro/v1`, alongside the free `mvs/v1` namespace. Pro must be active for any of these routes to be registered.

Authentication uses the same mechanism as the free API: pass an `X-WP-Nonce` header with a nonce from `wp_create_nonce( 'wp_rest' )` and include `credentials: 'same-origin'` so the request is tied to the logged-in user. Application Passwords (WP 5.6+) work for mobile/headless clients.

> **Note (2.2.0):** the free plugin's private-community gate (`mvs_rest_require_auth`) covers `mvs-pro/v1` too — Pro appends its namespace via the `mvs_rest_gated_route_prefixes` filter, so on a private community even Pro's public reads (e.g. tournament brackets) require login. Each route additionally enforces its own permission callback as listed below.

**Update methods.** Every route documented below with `PUT` also accepts `PATCH` and `POST` - WordPress registers those three together as its "editable" method group, so all three reach the same handler with the same arguments and the same response. `PUT` is used as the canonical form throughout this page.

**Feature toggles.** A Pro feature that is switched off never registers its routes, so its endpoints return `404`, not `403`. If a documented route 404s on a Pro site, check the matching toggle in [Settings Reference](../settings/settings-reference.md#feature-toggles) before assuming a version mismatch. This affects the Stories and Connectors routes in particular, since both ship disabled by default.

**Permission conventions used below:**

- **Public** — no authentication required (`__return_true` or open read). Some are rate-limited inside the service.
- **User** — any logged-in user (`is_user_logged_in`).
- **Owner/Admin** — the media owner or a user who can edit others' media.
- **Admin** — requires `manage_options` or `manage_mvs_settings` (noted per route).
- **HMAC** — no WordPress auth; request body is verified against an HMAC-SHA256 signature header.

Some feature areas only register their routes when the matching admin toggle is enabled (`mvs_battles_enabled`, `mvs_challenges_enabled`, `mvs_tournaments_enabled`, `mvs_boosts_enabled`, `mvs_connectors_enabled`, `mvs_stories_enabled`). When one of those features is disabled its routes are not registered. Streaks is the exception: `POST /streaks/buy-freeze` registers regardless of `mvs_streaks_enabled` and refuses at call time instead.

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

> Unlike the other gamification areas, this route registers whether or not `mvs_streaks_enabled` is on.

### POST /streaks/buy-freeze

Purchase a streak-freeze token with gamification points. Returns `503 mvs_gamification_unavailable` when the points backend is absent, and `400 mvs_insufficient_points` when the user cannot afford the cost (`mvs_pro_streak_freeze_cost`, default 100).

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

## Stories **(New in 1.9.0)**

WhatsApp-style ephemeral stories. Story state is stored as free media meta (`is_story` / `story_started_at` / `story_expires_at`). There is no separate story-views table: a story view **is** a media view, so receipts are written to the free `mvs_media_views` table and "seen by" is derived from those rows, window-scoped to the story's active period via `story_started_at` and excluding the author. Replying to a story reuses the existing free DM routes — there is no separate reply endpoint here. Requires `mvs_stories_enabled`.

### GET /stories

List active stories. Defaults to the viewer's network (people they follow, plus themselves); pass `author_id` to scope to one profile.

**Auth:** Public (returns an empty set for logged-out visitors since the default scope needs a viewer)

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `author_id` | int | (viewer's network) | Scope to one author's stories |
| `page` | int | `1` | Page number |
| `per_page` | int | `50` | Items per page (max 100) |

**Response:** array of `{ media_id, media_type, thumbnail_url, expires_at, viewed, author: { id, name, avatar, profile_url } }`. `X-WP-Total` / `X-WP-TotalPages` headers carry pagination.

---

### POST /media/{id}/story

Mark a media item as a story.

**Auth:** Owner/Admin

**Body:**

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `duration_hours` | int | `24` | Visibility window, 1-168 hours |

**Response:** `{ media_id, is_story: true, expires_at }`

---

### DELETE /media/{id}/story

End a story early. The media itself is untouched — only the story designation is cleared.

**Auth:** Owner/Admin

**Response:** `{ media_id, is_story: false }`

---

### POST /stories/{id}/view

Record a view receipt for the current user. The author's own views are never recorded — "seen by" counts the audience, not the owner.

**Auth:** User (subject to the media's normal privacy check)

**Response:** `{ recorded: true }`

---

### GET /stories/{id}/viewers

"Seen by" list for a story.

**Auth:** Owner/Admin

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | `1` | Page number |
| `per_page` | int | `50` | Items per page (max 100) |

**Response:** `{ viewers: [ { user_id, name, avatar, profile_url, viewed_at } ], total }`. `X-WP-Total` / `X-WP-TotalPages` headers carry pagination.

---

## App (Mobile App) **(New in 1.9.0)**

Routes that support the native mobile app: branding/feature-flag delivery, push-device registration, and the app leaderboard. See [Mobile App](../pro-features/mobile-app.md) for the feature-level explanation of white-label branding and feed layout.

### GET /wp-json/mvs/v1/app/config

The single call a native/headless client makes before theming itself and deciding which feature surfaces to mount. This route ships in the **Free** plugin (namespace `mvs/v1`, not `mvs-pro/v1`); Pro contributes to its response via filters rather than registering its own route.

**Auth:** Public (`__return_true`)

**Response:**

```json
{
  "accent_color": "#7C3AED",
  "logo_url": "https://example.com/wp-content/uploads/2026/07/logo.png",
  "login_bg_url": null,
  "dark_mode_default": false,
  "layout": "instagram",
  "pro_active": true,
  "features": {
    "messaging": true,
    "reactions": true,
    "comments": true,
    "favorites": true,
    "albums": true,
    "collections": true,
    "follows": true,
    "notifications": true,
    "activity": true,
    "battles": false,
    "challenges": true,
    "tournaments": false,
    "boosts": false,
    "streaks": true,
    "video": true,
    "stories": true
  }
}
```

Site name, description, icon, and auth discovery come from the core WordPress `/wp-json/` index, not this route - `/app/config` only carries what the core index cannot express: branding and feature flags. `accent_color`, `logo_url`, `login_bg_url`, and `dark_mode_default` are `null`/`false` unless Pro's white-label branding settings are configured (see [Mobile App](../pro-features/mobile-app.md)). `layout` mirrors the site owner's `mvs_pro_feed_layout` choice (`grid` when Pro is inactive). The `features` map is Free's always-on capabilities plus Pro's toggle-driven flags (`battles`, `challenges`, `tournaments`, `boosts`, `streaks`, `video`, `stories`) — each Pro flag is only `true` when its matching admin toggle is on.

---

### POST /mvs-pro/v1/push/register-device

Register (or refresh) the current user's device push token so the app can deliver push notifications.

**Auth:** User

**Body:**

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `expo_push_token` | string | Yes | - | The device's Expo push token |
| `platform` | string | No | `""` | One of `""`, `ios`, `android`, `web` |
| `device_name` | string | No | `""` | Human-readable device label |

**Response:**

```json
{ "registered": true }
```

---

### DELETE /mvs-pro/v1/push/register-device

Remove a device push token for the current user (e.g. on logout or app uninstall).

**Auth:** User

**Body:**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `expo_push_token` | string | Yes | The device's Expo push token to remove |

**Response:**

```json
{ "removed": true }
```

---

### GET /mvs-pro/v1/leaderboard

Paginated leaderboard for the app's gamification screen. Backed by the same `LeaderboardService` as the `pro-leaderboard` block, so the app and web show identical rankings. Returns the ranked page plus the current viewer's own rank in a single round trip.

**Auth:** Public. Public rows are cached (5 minutes by default via `mvs_pro_leaderboard_cache_ttl`); the viewer's own rank is cached per-user and only computed when logged in.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `source` | string | `reactions` | One of `reactions`, `media_count`, `gamification_xp` |
| `period` | string | `all` | One of `all`, `30d`, `7d` |
| `page` | int | `1` | Page number |
| `per_page` | int | `10` | Rows per page (1–100) |

**Response:**

```json
{
  "rows": [
    { "rank": 1, "user_id": 5, "display_name": "Jane", "avatar_url": "https://…", "profile_url": "https://…", "score": 342, "metric_label": "reactions" }
  ],
  "total": 128,
  "viewer_rank": 14,
  "viewer_score": 27
}
```

`X-WP-Total` and `X-WP-TotalPages` headers carry pagination. `viewer_rank` is `null` when the current user is not logged in or has no score yet.

---

## Admin

### POST /admin/gamification-welcome/dismiss

Dismiss the gamification first-run welcome banner (site-wide option).

**Auth:** Admin (`manage_mvs_settings`)

---

## Documents, Folders & Drives **(New in 2.4.0)**

The document library. A **drive** is a library scope, addressed by a token of the form `type:id` - `user:12` for a member's personal drive, `space:7` for a Space drive. Omit the token and every route defaults to the caller's own personal drive.

MediaVerse **embeds** documents; it does not convert them. `GET /documents/{id}/preview` streams a PDF inline, server-renders the text family as sanitised HTML, and returns a descriptive card for everything else.

> **Licence note.** Documents are the one exception to Pro's licence model: the EDD licence buys updates, not features, everywhere else, but document **writes** are gated. On a site with an inactive licence, every write on this surface (upload, replace, rename, move, privacy, trash, restore, folder create, share) is refused with `403 mvs_documents_read_only`. Reads are never gated - listing, searching, opening, downloading and previewing all keep working, and a member never loses access to files they already put there. Routes still register (a gate that unregisters a route turns a readable refusal into an unexplained `404`). Two carve-outs: anyone with `manage_options` or `manage_mvs_documents` is exempt, and `DELETE /permissions/{grant_id}` stays open, because trapping a document in somebody else's hands is a safety failure rather than a commercial lever.

### GET /documents

List documents in a drive.

**Auth:** Read access to the named drive. Usually a logged-in member, but a drive the permission ladder marks readable is listable without a session when the owner has switched anonymous links on. A drive the viewer may not know about answers `404`, not `403`.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `drive` | string | (your own drive) | Drive token, e.g. `space:7` |
| `folder` | int | `0` | Folder to list; `0` is the drive root |
| `status` | string | `publish` | `publish` or `trash`. `trash` lists the member's trashed documents |
| `page` | int | `1` | Page number |
| `per_page` | int | `50` | Items per page (max 100) |
| `orderby` | string | `created_at` | One of `created_at`, `title`, `file_size` |
| `order` | string | `desc` | `asc` or `desc` |

Unknown `status` / `orderby` / `order` values return `400` rather than being silently ignored.

---

### GET /documents/search

Full-text search across the documents the caller can see.

**Auth:** User

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `q` | string | Yes | — | Search term |
| `drive` | string | No | (all visible drives) | Scope to one drive, e.g. `space:7` |
| `page` | int | No | `1` | Page number |
| `per_page` | int | No | `20` | Results per page (max 50) |

---

### GET /documents/{id}

Get a single document.

**Auth:** User with read access to the document

---

### PUT /documents/{id}

Update a document's metadata - rename, re-describe, change privacy, or move it to another folder.

**Auth:** Owner/Admin. Write-gated.

| Field | Type | Description |
|-------|------|-------------|
| `title` | string | New title |
| `description` | string | New description |
| `privacy` | string | One of `private`, `space`, `members`, `public` |
| `folder` | int | Destination folder ID; `0` for the drive root |

---

### DELETE /documents/{id}

Trash a document. It is **not** destroyed - `POST /documents/{id}/restore` brings it back.

**Auth:** Owner/Admin. Write-gated.

---

### POST /documents/upload

Create a document. Send the file as `multipart/form-data`.

**Auth:** User with write access to the target drive. Write-gated.

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `doc_type` | string | No | — | The type the caller believes this is. A disagreement with the actual file is refused, never silently corrected. Omit it to make no claim |
| `folder` | int | No | `0` | Folder to file into. A folder carries its own drive and wins over `drive` |
| `drive` | string | No | (your own drive) | Drive to upload into at the root, as `type:id` - only consulted when `folder` is `0` |
| `title` | string | No | (filename) | Document title |
| `description` | string | No | `""` | Description |
| `privacy` | string | No | (site setting) | One of `private`, `space`, `members`, `public` |

---

### POST /documents/{id}/replace

Swap new bytes into an existing document. The document keeps its ID, slug, title, folder, privacy and grants, so every link already shared still resolves; the superseded file stays recoverable for 30 days.

**Auth:** Owner/Admin - the same permission ladder as `PUT /documents/{id}`. Write-gated.

Optional `doc_type` behaves exactly as on upload.

---

### POST /documents/{id}/restore

Restore a trashed document.

**Auth:** Owner/Admin. Write-gated.

---

### POST /documents/bulk

Apply one action to a mixed selection of documents and folders in a single call. Reports what happened **per item**, so a client can mark the rows that failed rather than re-fetching to find out which.

**Auth:** User. Authority over each item is proved per item, by the same service the drive form uses. Write-gated.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | One of `move`, `trash`, `restore`. `share` / `unshare` are deliberately absent - they take a per-item grantee |
| `items` | string[] | Yes | 1-100 entries, each `document:<id>` or `folder:<id>` |
| `value` | string | No | Destination folder ID for `move`; `0` for the drive root. Ignored otherwise |

---

### GET /me/shared

Documents shared **with** the current member. A different question from "my drive", and it has no folder to scope by, so it is its own route. Takes the same collection parameters as `GET /documents`.

**Auth:** User. Unlike `GET /documents`, this route names no drive, so an anonymous caller is always refused.

---

### GET /drives

The drives this viewer can see - how a client discovers a Space library at all, rather than being told a document's drive after the fact.

**Auth:** User. Names no drive, so an anonymous caller is always refused.

---

### GET /documents/{id}/download

Stream the document as an attachment.

**Auth:** Read access to the document. Not write-gated - downloads keep working on an unlicensed site.

Refused with `404 mvs_document_not_found` when the item is not a document, or when it has been trashed. Trash is the member's "take it back" action, so it withdraws the file from delivery for everyone including the owner; restore it first.

---

### GET /documents/{id}/preview

Preview the document at whichever tier its type supports:

1. **PDF** streams inline and the response is the file itself.
2. **The text family** comes back as server-rendered, sanitised HTML in a JSON response - the client never receives the file.
3. **Everything else** comes back as a card describing the file and where to download it.

**Auth:** Read access to the document. Not write-gated. Same trashed/not-a-document refusals as `/download`.

---

### GET /folders

List folders in a drive.

**Auth:** User with read access to the drive

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `drive` | string | (your own drive) | Drive token, e.g. `user:12` |
| `parent` | int | `0` | Parent folder; `0` is the drive root |
| `status` | string | `active` | `active` or `trashed`. `trashed` is flat and ignores `parent` |
| `page` | int | `1` | Page number |
| `per_page` | int | `50` | Items per page (max 100) |
| `orderby` | string | `name` | One of `name`, `created_at`, `updated_at` |
| `order` | string | `ASC` | Sort direction |

---

### POST /folders

Create a folder.

**Auth:** User with write access to the drive. Write-gated.

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `name` | string | Yes | — | Folder name |
| `drive` | string | No | (your own drive) | Drive token |
| `parent` | int | No | `0` | Parent folder ID |

---

### GET /folders/{id}

Get a single folder.

**Auth:** User with read access

---

### PUT /folders/{id}

Rename, re-parent, or re-privacy a folder.

**Auth:** Owner/Admin. Write-gated.

| Field | Type | Description |
|-------|------|-------------|
| `name` | string | New folder name |
| `parent` | int | New parent folder ID |
| `privacy` | string | One of `private`, `space`, `members`, `public` |

---

### DELETE /folders/{id}

Trash a folder.

**Auth:** Owner/Admin. Write-gated.

---

### POST /folders/{id}/restore

Restore a trashed folder.

**Auth:** Owner/Admin. Write-gated.

---

### GET /documents/{id}/permissions

List the grants on a document.

**Auth:** Whoever may manage sharing for the document.

---

### POST /documents/{id}/permissions

Grant a user or a role access to a document.

**Auth:** Whoever may manage sharing for the document. Write-gated.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `grantee_type` | string | `user` | `user` or `role` |
| `user_id` | int | — | The member to grant to, when `grantee_type` is `user` |
| `user_login` | string | — | Alternative to `user_id` - a person types a name, not a row ID |
| `role` | string | — | The role to grant to, when `grantee_type` is `role` |
| `permission` | string | `view` | One of `view`, `comment`, `edit` |
| `expires_at` | string | — | Optional expiry |

---

### POST /documents/{id}/permissions/link

Mint an anonymous share link for a document.

**Auth:** Whoever may manage sharing for the document. Write-gated. Returns `403 mvs_link_sharing_disabled` when anonymous links are switched off site-wide.

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `permission` | string | `view` | One of `view`, `comment`, `edit` |
| `expires_at` | string | — | Optional expiry |

---

### DELETE /permissions/{grant_id}

Revoke a grant.

**Auth:** Whoever may revoke this grant. **Not** write-gated - see the licence note above.

---

### Document error codes

These codes are frozen: a client may branch on them, and their meanings do not change without a coordinated release.

| Code | Status | Means |
|------|--------|-------|
| `mvs_unauthorized` | 401 | Not signed in. Send the member to sign-in |
| `mvs_documents_unavailable` | 403 | Signed in, but documents are not available to this account. Hide the tab; do not retry or offer sign-in |
| `mvs_documents_read_only` | 403 | Documents are read-only across the whole site right now. Show the library, hide every write control everywhere |
| `mvs_drive_not_found` | 404 | That drive is not visible to you, including a secret Space. Treat as no such drive |
| `mvs_drive_forbidden` | 403 | The drive exists and you may know it exists, but its contents are not yours to read. Offer the way in, not the library |
| `mvs_drive_read_only` | 403 | The drive is visible and readable, but you may not write to it. Show the library, hide upload |
| `mvs_document_not_found` | 404 | Not readable by you, or gone. Treat as missing either way |
| `mvs_document_forbidden` | 403 | Readable but not editable. Show it, hide edit |
| `mvs_document_type_not_allowed` | 400 | This site refuses that type; `data.doc_type` carries it |
| `mvs_document_too_large` | 400 | Over the limit |
| `mvs_link_sharing_disabled` | 403 | Anonymous links are off on this site. Hide the option rather than offering it |
| `mvs_document_scan_failed` | 400 | The site scanner rejected the file. Not a type problem - do not suggest another format |

Any other document refusal should be treated as "the request was refused, show the message" rather than branched on.

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
| `mvs_gamification_unavailable` | 503 | Points backend not available (streak freeze) |
| `mvs_insufficient_points` | 400 | Not enough gamification points for the requested action |

---

## Group conversations

Group messaging routes. The one-to-one conversation routes live in the Free [REST API reference](rest-api.md); these add the multi-participant layer on top of the same conversation store.

Two permission levels apply:

- **Group admin** - the creator, plus anyone promoted to `admin`. Required to rename a group or change its membership.
- **Participant** - any member of the group. Required to leave it.

### POST /groups

Create a group conversation.

**Auth:** Authenticated.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `participant_ids` | array of integer | yes | User IDs to add. The creator is added automatically as group admin. |
| `title` | string | no | Group name. Clients usually fall back to a list of participant names when this is empty. |

Fires `mvs_group_conversation_created`.

### PUT /groups/{id}

Update a group - in practice, rename it.

**Auth:** Group admin.

Also accepts `PATCH` and `POST`.

### POST /groups/{id}/participants

Add a participant.

**Auth:** Group admin.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `user_id` | integer | yes | The member to add. |
| `role` | string | no | `admin` or `member`. Defaults to `member`. |

Fires `mvs_participant_added`.

### DELETE /groups/{id}/participants/{user_id}

Remove a participant.

**Auth:** Group admin.

Fires `mvs_participant_removed`.

### PUT /groups/{id}/participants/{user_id}/role

Promote or demote a participant.

**Auth:** Group admin.

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `role` | string | yes | One of `admin`, `member`. |

Also accepts `PATCH` and `POST`.

### POST /groups/{id}/leave

Leave a group you are a participant of.

**Auth:** Participant.

This is deliberately separate from `DELETE /groups/{id}/participants/{user_id}`: leaving is something any participant may do to themselves, while removing someone else requires group admin. Do not implement "leave" by having the client call the removal route with its own user ID - that path checks for admin and will return `403` for ordinary members.

---

## Media collections

### GET /media/{media_id}/collections

Return which of the caller's collections this media item belongs to.

**Auth:** Authenticated.

Used to render the checked state of a "save to collection" menu without a separate lookup per collection.

### POST /media/{media_id}/collections

Add or remove a media item from one collection.

**Auth:** Authenticated.

| Parameter | Type | Required | Default | Description |
|-----------|------|----------|---------|-------------|
| `collection_id` | integer | yes | - | Target collection. |
| `member` | boolean | no | `true` | `true` adds the item, `false` removes it. |

A single toggle endpoint rather than separate add and delete routes: send `member: false` to remove.
