# REST API Reference

> Endpoints and hooks marked **(Pro)** require WPMediaVerse Pro.


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

### GET /tags/cloud

Return all tags with usage counts, suitable for rendering a tag cloud.

### POST /tags/merge

Merge two tags. Requires `moderate_mvs_media`. All media carrying `source_id` will be re-tagged with `target_id` and `source_id` will be deleted.

```json
{
  "source_id": 12,
  "target_id": 7
}
```

### PUT /tags/{id}

Rename a tag. Requires `moderate_mvs_media`.

```json
{ "name": "New Tag Name" }
```

---

## Notifications

### GET /notifications

List the current user's WPMediaVerse notifications.

### PUT /notifications/{id}

Mark a notification as read/unread.

### POST /notifications/mark-all-read

Mark all of the current user's notifications as read.

---

## Messaging

**Base path:** `/wp-json/mvs/v1/`

All messaging endpoints require authentication. Message requests (conversations from users you do not follow) land in the **Requests** tab until accepted or declined.

### GET /me/conversations

List the current user's conversations.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `tab` | string | `all` | Filter conversations: `all`, `unread`, `requests` |
| `per_page` | int | `20` | Conversations per page (max: 50) |
| `page` | int | `1` | Page number |

### POST /conversations

Start a new conversation.

```json
{ "recipient_id": 42 }
```

**Response:** `201 Created` with the new conversation object.

### GET /conversations/{id}/messages

List messages in a conversation. Returns newest-first.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `per_page` | int | `30` | Messages per page (max: 100) |
| `before` | int | (none) | Return messages with ID less than this value (cursor pagination) |

### POST /conversations/{id}/messages

Send a message. Requires that the conversation is not in a declined-request state.

```json
{
  "content": "Hey, love the photo!",
  "parent_id": null,
  "message_type": "text",
  "media_id": null
}
```

| Field | Required | Description |
|-------|----------|-------------|
| `content` | Yes (if no `media_id`) | Message text (max length controlled by `mvs_message_max_length`) |
| `parent_id` | No | Reply to this message ID |
| `message_type` | No | `text` (default) or `media` |
| `media_id` | No | Attach an existing media post to the message |

### PATCH /conversations/{id}

Update conversation preferences for the current user.

```json
{
  "is_muted": true,
  "is_pinned": false,
  "is_archived": false
}
```

### DELETE /conversations/{id}

Leave (soft-delete) the conversation for the current user.

### POST /conversations/{id}/read

Mark all messages in the conversation as read for the current user.

### POST /conversations/{id}/typing

Send a typing indicator event. Typically called while the user is composing. No persistent storage — triggers a real-time event only.

### POST /conversations/{id}/accept

Accept a message request. Moves the conversation from the **Requests** tab to **All**.

### POST /conversations/{id}/decline

Decline a message request. The conversation is removed from the inbox.

### DELETE /messages/{id}

Soft-delete a message for the current user. The message content is hidden but the record is retained.

### DELETE /messages/{id}/unsend

Hard-delete (unsend) a message. Only available within the edit window defined by `mvs_comment_edit_window`. Requires message ownership.

### POST /messages/{id}/reactions

Add an emoji reaction to a message.

```json
{ "emoji": "heart" }
```

### DELETE /messages/{id}/reactions

Remove your emoji reaction from a message.

### POST /messages/upload

Upload an attachment to use in a DM. Returns a temporary media reference ID to pass as `media_id` when sending the message.

**Body:** `multipart/form-data` with a single `file` field. Max size controlled by `mvs_dm_max_upload_size`.

**Response:**

```json
{ "media_id": 204, "url": "https://example.com/..." }
```

### GET /me/messages/unread-count

Return the total unread message count for the current user.

**Response:**

```json
{ "count": 3 }
```

### GET /messages/poll

Long-poll for new messages since a given message ID. The server holds the connection open (up to 30 seconds) and responds as soon as a new message arrives or the timeout is reached.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `since` | int | Return messages with ID greater than this value |

---

## Activity Feed

### GET /mvs/v1/feed

Return the activity feed for the current user.

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `scope` | string | `public` | Feed scope: `public` (all public media) or `following` (media from followed users) |
| `per_page` | int | `20` | Items per page (max: 100) |
| `page` | int | `1` | Page number |

### GET /mvs/v1/users/{id}/activity

Return the public activity for a specific user — media uploads, album creations, and reactions.

---

## Users

### GET /mvs/v1/users/{id}

Get a user's public profile, including bio, avatar URL, follower/following counts, and public media count.

### GET /mvs/v1/users/{id}/media

List a user's public media. Supports `page`, `per_page`, `media_type`, `orderby`, `order`.

### GET /mvs/v1/users/search

Search for users by display name or username.

**Parameters:**

| Parameter | Type | Description |
|-----------|------|-------------|
| `q` | string | Search term (minimum 2 characters) |

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
