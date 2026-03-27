# REST API Reference

**Base URL:** `/wp-json/mvs/v1/`

All write operations require authentication. Pass the `X-WP-Nonce` header with a nonce generated via `wp_create_nonce( 'wp_rest' )`.

The API uses WordPress REST API rate limiting. Excessive requests return `429 Too Many Requests`.

---

## Media

### GET /media

List media items. Respects privacy rules for the authenticated user.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | `1` | Page number |
| `per_page` | int | `10` | Items per page (max: 100) |
| `media_type` | string | (all) | Filter by type: `image`, `video`, `audio` |
| `author` | int | (all) | Filter by user ID |
| `tag` | string | (all) | Filter by `mvs_tag` slug |
| `category` | string | (all) | Filter by `mvs_category` slug |
| `orderby` | string | `date` | Sort field: `date`, `title`, `views` |
| `order` | string | `DESC` | Sort direction: `ASC`, `DESC` |
| `search` | string | (none) | Full-text search |

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

Upload a new media file. Requires `upload_mvs_media` capability.

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

Get a single media item. Returns `403 Forbidden` if the user cannot view it.

### PUT /media/{id}

Update a media item. Requires ownership or `edit_others_mvs_media`.

**Body (JSON):**

```json
{
  "title": "Updated Title",
  "description": "Updated description",
  "privacy": "private"
}
```

### DELETE /media/{id}

Delete a media item and its stored file. Requires ownership or `delete_others_mvs_media`.

### GET /media/{id}/signed-url

Generate a time-limited signed URL for a private file. Requires view access to the media.

---

## Albums

### GET /albums

List albums. Supports `page`, `per_page`, `author`, `orderby`, `order`.

### POST /albums

Create an album. Requires `upload_mvs_media`.

```json
{
  "title": "My Album",
  "description": "Optional",
  "privacy": "public"
}
```

### GET /albums/{id}

Get an album with its media list.

### PUT /albums/{id}

Update an album.

### DELETE /albums/{id}

Delete an album (does not delete the media items it contains).

### GET /albums/{id}/items

List media in an album.

### POST /albums/{id}/items

Add media to an album.

```json
{ "media_ids": [101, 102, 103] }
```

### DELETE /albums/{id}/items/{media_id}

Remove a media item from an album.

---

## Collections

### GET /collections

List collections.

### POST /collections

Create a collection.

```json
{
  "title": "Nature",
  "type": "smart",
  "rules": { "tags": ["nature"], "media_type": "image" },
  "privacy": "public"
}
```

### GET /collections/{id}

Get a collection with its resolved item list.

### PUT /collections/{id}

Update a collection.

### DELETE /collections/{id}

Delete a collection.

---

## Reactions

### GET /media/{id}/reactions

Get reaction counts grouped by type.

### POST /media/{id}/reactions

Add or change your reaction.

```json
{ "type": "love" }
```

### DELETE /media/{id}/reactions

Remove your reaction.

---

## Comments

### GET /media/{id}/comments

List comments. Supports `page`, `per_page`.

### POST /media/{id}/comments

Post a comment. Use `@username` syntax for mentions.

```json
{ "content": "Great photo @jane!" }
```

### PUT /media/{id}/comments/{comment_id}

Edit your comment.

### DELETE /media/{id}/comments/{comment_id}

Delete a comment. Requires ownership or `moderate_mvs_media`.

---

## Access Control

### POST /media/{id}/access

Grant a user access to private/custom media.

```json
{
  "user_id": 55,
  "expires_at": "2026-01-01T00:00:00Z"
}
```

### DELETE /media/{id}/access/{user_id}

Revoke access for a user.

---

## Favorites

### POST /media/{id}/favorite

Add media to favorites.

### DELETE /media/{id}/favorite

Remove from favorites.

### GET /favorites

List the current user's favorites.

---

## Follows

### POST /users/{id}/follow

Follow a user.

### DELETE /users/{id}/follow

Unfollow a user.

### GET /users/{id}/followers

List followers.

### GET /users/{id}/following

List who a user follows.

---

## User Profile

### GET /profile

Get the current user's profile.

### PUT /profile

Update profile fields.

```json
{
  "first_name": "Jane",
  "last_name": "Smith",
  "display_name": "jsmith",
  "bio": "Photographer"
}
```

### POST /profile/avatar

Upload a new profile avatar (multipart/form-data, `file` field).

### DELETE /profile/avatar

Remove the custom avatar and revert to Gravatar.

---

## Moderation

### GET /moderation/queue

List flagged media items. Requires `moderate_mvs_media`.

### POST /moderation/{id}/approve

Approve a media item.

### POST /moderation/{id}/reject

Reject a media item (sets post_status to `draft`).

### POST /moderation/{id}/analyze

Trigger AI analysis on a media item.

---

## Bulk Operations

### POST /bulk

Perform bulk operations on multiple media items. Requires appropriate capabilities.

```json
{
  "action": "delete",
  "media_ids": [101, 102, 103]
}
```

Supported actions: `delete`, `publish`, `privatize`, `approve`, `reject`.

---

## Stats

### GET /stats

Get site-wide media statistics.

### GET /stats/media/{id}

Get per-item statistics (views, downloads, reactions).

---

## Notifications

### GET /notifications

List the current user's WPMediaVerse notifications.

### PUT /notifications/{id}

Mark a notification as read/unread.

---

## Reports

### POST /media/{id}/report

Submit a content report.

```json
{
  "reason": "inappropriate",
  "details": "Optional explanation"
}
```

---

## Tags

### GET /tags

List `mvs_tag` terms. Supports `search`, `per_page`.

### POST /tags

Create a new tag. Requires `moderate_mvs_media`.

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
| `mvs_duplicate` | 409 | Duplicate file (when duplicate_action=skip) |
| `mvs_not_found` | 404 | Resource not found |
| `rest_forbidden` | 403 | Access denied by privacy rules |
| `mvs_storage_failed` | 500 | Storage driver error |
