# REST API Reference

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro. Everything documented below ships in the free plugin.

**Base URL:** `/wp-json/mvs/v1/`

All routes below use the `mvs/v1` namespace (the messaging routes share the same namespace).

**Authentication.** Reads of public data are open. Every write — and every `/me/*` route — requires an authenticated user. Pass the `X-WP-Nonce` header with a nonce generated via `wp_create_nonce( 'wp_rest' )` and send cookies with `credentials: 'same-origin'`, or use a WordPress Application Password for non-browser clients.

**Authorization model.** Three levels are used throughout:

- **Public** — no auth; privacy is enforced inside the query so private rows never leak.
- **Authenticated** — any logged-in user (`is_user_logged_in()`).
- **Capability** — a specific capability such as `upload_mvs_media`, `moderate_mvs_media`, or `manage_mvs_access`.

**Rate limiting.** Many routes are throttled per user/IP (the limit is noted where it is unusually tight). Exceeding a limit returns `429 Too Many Requests`.

---

## Media

### GET /media

**Auth:** Public (privacy enforced in query). Rate-limited to 120/min.

List media items. Returns only rows the caller is allowed to see.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | `1` | Page number |
| `per_page` | int | `20` | Items per page (max: 100, filterable via `mvs_rest_pagination_max`) |
| `media_type` | string | (all) | Filter by type: `image`, `video`, `audio`, `document` |
| `author` | int | (all) | Filter by user ID |
| `slug` | string | (none) | Fetch a single item by post slug |
| `tag` | string | (all) | Filter by `mvs_tag` slug |
| `category` | string | (all) | Filter by `mvs_category` slug |
| `orderby` | string | `date` | Sort: `date`, `trending`, `popular` (filterable via `mvs_feed_sort_options`) |
| `scope` | string | `public` | `public` or `all` (owner/privileged callers) |
| `s` | string | (none) | Full-text search term |
| `group_covers` | bool | `false` | Collapse gallery groups to a single cover item |

**Response:**

```json
{
  "items": [
    {
      "id": 123,
      "title": "Sunset Photo",
      "description": "",
      "media_type": "image",
      "file_url": "https://example.com/wp-content/uploads/wpmediaverse/2025/03/photo.jpg",
      "privacy": "public",
      "author_id": 1,
      "album_id": null,
      "views": 42,
      "reactions_count": 5,
      "comments_count": 2,
      "created_at": "2025-03-27T12:00:00Z"
    }
  ],
  "total": 50,
  "pages": 5
}
```

### POST /media

**Auth:** Capability — `upload_mvs_media` (or `manage_options`).

Upload a new media file.

**Body (multipart/form-data):**

| Field | Required | Description |
|-------|----------|-------------|
| `file` | Yes | The file to upload |
| `title` | No | Media title (defaults to filename) |
| `description` | No | Text description |
| `privacy` | No | Privacy level (default: site setting) |
| `group_id` | No | BuddyPress group ID (required when `privacy=group`) |
| `album_id` | No | Add to this album after upload |
| `is_story` | No | `true` to mark as a story |

**Response:** `201 Created` with the new media object.

### GET /media/{id}

**Auth:** Public (privacy check in permission callback). Returns `403` if the caller cannot view it, `404` if it does not exist.

Get a single media item.

### PUT /media/{id}

**Auth:** Capability — owner with `edit_mvs_medias`, or `edit_others_mvs_medias`.

Update a media item.

**Body (JSON):**

```json
{
  "title": "Updated Title",
  "description": "Updated description",
  "privacy": "private"
}
```

### DELETE /media/{id}

**Auth:** Capability — owner with `delete_mvs_medias`, or `delete_others_mvs_medias`.

Delete a media item and its stored file.

### POST /media/{id}/replace

**Auth:** Capability — same as `PUT /media/{id}` (edit permission).

Replace the underlying file of an existing media item while keeping its ID, comments, reactions, and stats. Send the new file as `multipart/form-data` with a `file` field.

### POST /media/{id}/view

**Auth:** Public.

Record a view for the item. Increments the view counter and writes a row to `mvs_media_views`.

### POST /media/{id}/download

**Auth:** Public. Rate-limited to 30/min.

Record a download event and increment `mvs_media_stats.downloads`. Refused with `403` when the global **Allow Downloads** toggle is off OR the per-media `allow_download` meta is `'0'`.

### POST /media/{id}/share

**Auth:** Public. Rate-limited to 60/min.

Record a share event and increment `mvs_media_stats.shares`. Called by the lightbox Share button after a successful `navigator.share` / clipboard copy.

### GET /media/{id}/access

**Auth:** Public.

Report whether the current user can view the item (resolves privacy rules and any access grants). Returns an access decision, not the file.

### GET /media/{id}/group

**Auth:** Public.

Return every item that belongs to the same gallery/upload group as `{id}` (used to build multi-item lightboxes).

### GET /media/{id}/signed-url

**Auth:** Authenticated with view access to the media.

Generate a time-limited signed URL for a private file. The signed URL points at `/serve` and carries an HMAC-SHA256 signature binding the request to a user, media ID, and expiry.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `download` | bool | `false` | Issue a download (attachment) URL |
| `ttl` | int | (setting) | Override the signed-URL lifetime, in seconds |

### GET /serve

**Auth:** Public — the HMAC signature on the URL is the credential (analogue of an S3 pre-signed URL). For non-public media the handler also re-checks `can_view` per request.

Stream the underlying file (full file or a thumbnail variant) for a validated signed URL. Drains output buffers and disables `zlib.output_compression` before streaming so byte counts match `Content-Length`. Honours `Range:` headers for video/audio.

| Param | Required | Description |
|-------|:--------:|-------------|
| `mvs_id` | yes | Media ID |
| `mvs_uid` | yes | User ID the URL was signed for (`0` for anonymous public media) |
| `mvs_exp` | yes | Unix expiration timestamp |
| `mvs_sig` | yes | HMAC-SHA256 signature |
| `mvs_size` | no | `large` / `medium` / `thumbnail` / `watermark` to serve a variant |
| `mvs_dl` | no | When `1`, sets `Content-Disposition: attachment` and increments the download counter |

### GET /me/media

**Auth:** Authenticated.

List the current user's own media, including private and pending items. Accepts the same parameters as `GET /media`.

---

## Albums

### GET /albums

**Auth:** Public (privacy enforced in query).

List albums. Supports `page`, `per_page`, `author`, `orderby`, `order`.

### POST /albums

**Auth:** Authenticated with album-create permission.

Create an album.

```json
{
  "title": "My Album",
  "description": "Optional",
  "privacy": "public"
}
```

### GET /albums/{id}

**Auth:** Public (private albums 404 for non-owners).

Get an album with its media list.

### PUT /albums/{id}

**Auth:** Authenticated — owner / edit permission.

Update an album.

### DELETE /albums/{id}

**Auth:** Authenticated — owner / delete permission.

Delete an album (does not delete the media items it contains).

### PUT /albums/{id}/reorder

**Auth:** Authenticated — owner / edit permission.

Reorder the items inside an album.

```json
{ "order": [103, 101, 102] }
```

### POST /albums/{id}/items

**Auth:** Authenticated — owner / edit permission.

Add media to an album.

```json
{ "media_ids": [101, 102, 103] }
```

### DELETE /albums/{id}/items/{media_id}

**Auth:** Authenticated — owner / edit permission.

Remove a single media item from an album.

### PUT /albums/{id}/cover

**Auth:** Authenticated — owner / edit permission.

Set the album cover.

```json
{ "media_id": 101 }
```

---

## Collections

### GET /collections

**Auth:** Authenticated. Returns the current user's collections.

### POST /collections

**Auth:** Authenticated.

Create a collection (manual or smart).

```json
{
  "title": "Nature",
  "type": "smart",
  "rules": { "tags": ["nature"], "media_type": "image" },
  "privacy": "public"
}
```

### GET /collections/{id}

**Auth:** Public (privacy check in permission callback).

Get a collection with its resolved item list.

### PUT /collections/{id}

**Auth:** Authenticated — owner only.

Update a collection.

### DELETE /collections/{id}

**Auth:** Authenticated — owner only.

Delete a collection.

### PUT /collections/{id}/rules

**Auth:** Authenticated — owner only.

Set the smart-collection rules used to resolve its items.

```json
{ "rules": [ { "field": "tag", "value": "nature" } ] }
```

---

## Reactions

All reaction operations live on a single route that varies by HTTP method.

### GET /media/{id}/reactions

**Auth:** Public.

Get reaction counts grouped by type. When a logged-in user calls it, the response also indicates that user's own reaction.

### POST /media/{id}/reactions

**Auth:** Authenticated.

Add or change your reaction. Note the field name is `reaction_type`.

```json
{ "reaction_type": "love" }
```

### DELETE /media/{id}/reactions

**Auth:** Authenticated.

Remove your reaction.

---

## Comments

### GET /media/{id}/comments

**Auth:** Public (visibility follows the parent media's privacy).

List comments. Supports `page`, `per_page` (max 100).

### POST /media/{id}/comments

**Auth:** Authenticated. Use `@username` syntax for mentions.

```json
{
  "content": "Great photo @jane!",
  "parent": 0,
  "from_activity": 0
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `content` | Yes | Comment text |
| `parent` | No | Parent comment ID for threaded replies (default `0`) |
| `from_activity` | No | Source BuddyPress activity ID, when posted from the activity stream |

### PUT /media/{id}/comments/{comment_id}

**Auth:** Authenticated — owner (within the edit window) or `moderate_mvs_media`.

Edit a comment.

### DELETE /media/{id}/comments/{comment_id}

**Auth:** Authenticated — owner or `moderate_mvs_media`.

Delete a comment.

---

## Favorites

### GET /media/{id}/favorite

**Auth:** Authenticated.

Return whether the current user has favorited the item.

### POST /media/{id}/favorite

**Auth:** Authenticated.

Add the item to favorites (toggles on). Optional `collection_id` saves it into a specific collection.

### DELETE /media/{id}/favorite

**Auth:** Authenticated.

Remove the item from favorites.

### GET /me/favorites

**Auth:** Authenticated.

List the current user's favorites. Supports `collection_id`, `page`, `per_page`.

---

## Access Control & Grants

These routes manage per-media access rules and direct user grants. All require the media owner or the `manage_mvs_access` capability.

### GET /media/{media_id}/rules

**Auth:** Owner or `manage_mvs_access`.

List the access rules attached to a media item.

### POST /media/{media_id}/rules

**Auth:** Owner or `manage_mvs_access`. Rate-limited to 30/min.

Replace the full rule set for a media item.

```json
{
  "rules": [
    { "rule_type": "follower", "rule_value": "1" },
    { "rule_type": "purchase", "rule_value": "1", "price": 4.99, "currency": "USD" }
  ]
}
```

Each rule's `rule_type` must be one of `AccessRulesService::RULE_TYPES`.

### DELETE /media/{media_id}/rules/{rule_id}

**Auth:** Owner or `manage_mvs_access`.

Delete a single access rule.

### POST /media/{media_id}/grant

**Auth:** Owner or `manage_mvs_access`.

Grant a specific user access to the media.

```json
{
  "user_id": 55,
  "source": "manual",
  "expires_at": "2026-01-01T00:00:00Z"
}
```

`source` defaults to `manual` and must be one of `AccessRulesService::GRANT_SOURCES`.

### DELETE /media/{media_id}/grant/{user_id}

**Auth:** Owner or `manage_mvs_access`.

Revoke a user's grant.

### GET /me/grants

**Auth:** Authenticated.

List the media the current user has been granted access to.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | `20` | Items per page (max: 100) |
| `page` | int | `1` | Page number |
| `active_only` | bool | `true` | Exclude expired grants |

---

## Follows

### POST /users/{id}/follow

**Auth:** Authenticated. Rate-limited to 30/min.

Follow a user.

### DELETE /users/{id}/follow

**Auth:** Authenticated.

Unfollow a user.

### GET /users/{id}/followers

**Auth:** Public.

List a user's followers (display name + avatar).

### GET /users/{id}/following

**Auth:** Public.

List who a user follows.

### GET /me/following

**Auth:** Authenticated.

List who the current user follows.

### GET /me/followers

**Auth:** Authenticated.

List the current user's followers.

---

## User Profile

### GET /me/profile

**Auth:** Authenticated.

Get the current user's profile.

### PUT /me/profile

**Auth:** Authenticated.

Update profile fields.

```json
{
  "first_name": "Jane",
  "last_name": "Smith",
  "display_name": "jsmith",
  "description": "Photographer"
}
```

### POST /me/avatar

**Auth:** Authenticated.

Upload a new profile avatar (multipart/form-data, `file` field).

### DELETE /me/avatar

**Auth:** Authenticated.

Remove the custom avatar and revert to Gravatar.

---

## Users

### GET /users/{id}

**Auth:** Public. Rate-limited to 60/min.

Get a user's public profile: bio, avatar URL, follower/following counts, and public media count. `user_login` / `user_registered` are returned only to the user themselves or to admins (enumeration hardening).

### GET /users/{id}/media

**Auth:** Public (privacy enforced in query).

List a user's visible media. Supports `page`, `per_page`.

### GET /users/search

**Auth:** Public.

Search for users by display name or username.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `q` | string | (required) | Search term |
| `per_page` | int | `10` | Results per page (max: 50) |

---

## Reports & Blocking

### POST /media/{id}/report

**Auth:** Authenticated. Rate-limited to 10/min.

Submit a content report against a media item.

```json
{
  "reason": "inappropriate",
  "details": "Optional explanation"
}
```

`reason` must be one of `ReportService::REASONS`.

### POST /users/{id}/report

**Auth:** Authenticated.

Report a user. Same `reason` / `details` body as media reports.

### POST /users/{id}/block

**Auth:** Authenticated.

Block a user.

### DELETE /users/{id}/block

**Auth:** Authenticated.

Unblock a user.

### GET /me/blocked

**Auth:** Authenticated.

List the users the current user has blocked.

---

## Moderation

All moderation routes require the `moderate_mvs_media` capability.

### GET /moderation/queue

List flagged / pending media items. Supports collection params (`page`, `per_page`).

### GET /moderation/counts

Return queue counts (pending, flagged, etc.) for building badges and tabs.

### POST /moderation/{id}/approve

Approve a media item.

### POST /moderation/{id}/reject

Reject a media item. Optional `reason` string is recorded.

### POST /moderation/{id}/analyze

Trigger AI analysis (description / tagging / safety) on a media item.

### GET /ai/usage

Return AI usage / budget figures for the moderation dashboard.

---

## Bulk Operations

### POST /media/bulk

**Auth:** Authenticated with the relevant per-action capability. Rate-limited to 10/min, max 100 IDs per call.

Perform a bulk action on multiple media items.

```json
{
  "action": "delete",
  "media_ids": [101, 102, 103]
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `action` | Yes | One of `delete`, `move_to_album`, `change_privacy` |
| `media_ids` | Yes | Array of media IDs (max 100) |
| `album_id` | When `action=move_to_album` | Destination album ID |
| `privacy` | When `action=change_privacy` | New privacy value |

---

## Stats

### GET /media/{id}/stats

**Auth:** Public for public media; `403` for media the caller cannot view.

Per-item statistics (views, reactions, comments, downloads).

### GET /me/stats

**Auth:** Authenticated.

Aggregate statistics across the current user's own media.

---

## Tags

### GET /tags

**Auth:** Public.

List / autocomplete `mvs_tag` terms.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `search` | string | (none) | Filter by name |
| `per_page` | int | `20` | Results per page (max: 100) |
| `orderby` | string | `name` | `name` or `count` |

### POST /tags

**Auth:** Capability — `create_tag_permissions_check` (any user who can upload media).

Create a new tag. Body: `name` (required), optional `slug`.

### GET /tags/cloud

**Auth:** Public.

Return top tags with usage counts for a tag cloud. Optional `limit` (default 50, max 200).

### POST /tags/merge

**Auth:** Capability — admin (`moderate_mvs_media`).

Merge one tag into another. All media on `source_id` are re-tagged with `target_id` and `source_id` is deleted.

```json
{ "source_id": 12, "target_id": 7 }
```

### PUT /tags/{id}

**Auth:** Capability — admin.

Rename a tag.

```json
{ "name": "New Tag Name" }
```

### DELETE /tags/{id}

**Auth:** Capability — admin.

Delete a tag.

---

## Notifications

### GET /me/notifications

**Auth:** Authenticated.

List the current user's notifications.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | `20` | Items per page (max: 100) |
| `page` | int | `1` | Page number |
| `filter` | string | `all` | Filter set (e.g. `all`, `unread`) |

The total count is returned in the `X-WP-Total` header.

### GET /me/notifications/count

**Auth:** Authenticated.

Return the current user's unread notification count.

### POST /me/notifications/read

**Auth:** Authenticated.

Mark notifications as read. Pass an `ids` array to mark specific notifications, or omit it to mark all as read.

```json
{ "ids": [12, 13, 14] }
```

---

## Admin

### POST /admin/welcome/dismiss

**Auth:** Authenticated (per-user state).

Dismiss the admin welcome banner for the current user.

---

## Activity Feed

### GET /feed

**Auth:** Public (private events never appear; `following` scope is empty for anonymous callers).

Return the activity feed.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `scope` | string | `public` | `public` (all public media) or `following` (followed users) |
| `per_page` | int | `20` | Items per page (max: 100) |
| `page` | int | `1` | Page number |

### GET /users/{id}/activity

**Auth:** Public.

Return a user's public activity (uploads, album creations, reactions). Supports `page`, `per_page`.

---

## Messaging

Direct-messaging routes share the `mvs/v1` namespace. **All require authentication.** Conversations started by users you do not follow land in the **Requests** tab until accepted or declined.

### GET /me/conversations

List the current user's conversations.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `tab` | string | `all` | `all`, `unread`, or `requests` |
| `per_page` | int | `20` | Conversations per page (max: 50) |
| `page` | int | `1` | Page number |

### POST /conversations

Start a new conversation.

```json
{ "recipient_id": 42 }
```

**Response:** `201 Created` with the new conversation object.

### GET /conversations/{id}

Get a single conversation's metadata and participants.

### PATCH /conversations/{id}

Update the current user's per-conversation preferences.

```json
{
  "is_muted": true,
  "is_pinned": false,
  "is_archived": false
}
```

### DELETE /conversations/{id}

Leave (soft-delete) the conversation for the current user.

### GET /conversations/{id}/messages

List messages in a conversation (newest-first).

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | `30` | Messages per page (max: 100) |
| `before` | int | `0` | Return messages with ID less than this (cursor pagination) |

### POST /conversations/{id}/messages

Send a message.

```json
{
  "content": "Hey, love the photo!",
  "message_type": "text",
  "media_id": null,
  "attachment_id": null,
  "parent_id": null,
  "metadata": {}
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `content` | Yes (unless an attachment/media is sent) | Message text |
| `message_type` | No | `text` (default) or `media` |
| `media_id` | No | Attach an existing media post |
| `attachment_id` | No | Attach a file uploaded via `POST /messages/upload` |
| `parent_id` | No | Reply to this message ID |
| `metadata` | No | Arbitrary structured metadata |

### POST /conversations/{id}/read

Mark all messages in the conversation as read for the current user.

### POST /conversations/{id}/typing

Send a typing-indicator event (no persistent storage; fires a real-time event only).

### POST /conversations/{id}/accept

Accept a message request — moves the conversation from **Requests** to **All**.

### POST /conversations/{id}/decline

Decline a message request — removes the conversation from the inbox.

### DELETE /messages/{id}

Soft-delete a message for the current user (content hidden, record retained).

### DELETE /messages/{id}/unsend

Hard-delete (unsend) a message. Only available within the edit window and only for the message owner.

### POST /messages/{id}/reactions

Add an emoji reaction to a message.

```json
{ "emoji": "heart" }
```

### DELETE /messages/{id}/reactions

Remove your emoji reaction from a message.

### POST /messages/upload

Upload an attachment for use in a DM. Returns a reference ID to pass as `attachment_id` (or `media_id`) when sending the message. Body: `multipart/form-data` with a single `file` field.

```json
{ "media_id": 204, "url": "https://example.com/..." }
```

### GET /me/messages/unread-count

Return the total unread message count for the current user.

```json
{ "count": 3 }
```

### GET /messages/poll

Long-poll for new messages. The server holds the connection open and responds as soon as a new message arrives or the timeout is reached.

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `since` | int | (required) | Return messages with ID greater than this value |
| `conversation_id` | int | `0` | Scope the poll to a single conversation |

---

## Error Responses

All errors follow the WP REST API error format:

```json
{
  "code": "mvs_invalid_type",
  "message": "This file type is not allowed.",
  "data": { "status": 400 }
}
```

Common error codes:

| Code | Status | Meaning |
|------|--------|---------|
| `mvs_invalid_type` | 400 | MIME type not in allowed list |
| `mvs_file_too_large` | 400 | File exceeds max upload size |
| `mvs_blocked_extension` | 400 | Dangerous file extension |
| `mvs_no_ids` | 400 | Bulk request had no media IDs |
| `mvs_duplicate` | 409 | Duplicate file (when duplicate_action=skip) |
| `mvs_not_found` | 404 | Resource not found |
| `mvs_user_not_found` | 404 | User not found |
| `mvs_not_logged_in` / `mvs_unauthorized` | 401 | Authentication required |
| `mvs_forbidden` / `rest_forbidden` | 403 | Access denied by privacy/capability rules |
| `mvs_storage_failed` | 500 | Storage driver error |
| (rate limit) | 429 | Too many requests |
