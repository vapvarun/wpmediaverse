# Stories (Coming Soon)

> **Planned Feature** — Stories infrastructure exists in the codebase but is not yet available as a user-facing feature.

## Current Status

The backend `StoryService` is built and can mark any media item as a time-limited story with automatic expiration. However, there is currently:

- No "Post as Story" option in the upload form
- No dedicated REST endpoint for creating stories
- No story viewer UI for browsing stories

## What Works Today

### Instagram Layout — Recent Uploaders Bar

When the **Instagram layout** is active (Pro), the explore page shows a horizontal bar of circular avatars above the feed. This displays users who uploaded recently — similar to the Instagram stories tray visual style, but these are links to user profiles, not ephemeral story content.

![Instagram layout with story-style avatar bar](../images/layout-instagram.png)

### Backend Service

The `StoryService` class provides:

- `create( $media_id, $duration_hours )` — mark media as a story with expiration
- `is_active( $media_id )` — check if a story is still live
- `cleanup_expired()` — hourly cron removes expired story flags
- Default duration: 24 hours

### Meta Keys

| Key | Value | Description |
|-----|-------|-------------|
| `is_story` | `1` | Media is marked as a story |
| `story_expires_at` | `2026-04-01 12:00:00` | UTC expiration datetime |

### Hooks

| Hook | When | Parameters |
|------|------|------------|
| `mvs_story_created` | Media marked as story | `$media_id`, `$expires_at` |
| `mvs_story_expired` | Story auto-expired by cleanup cron | `$media_id` |

## Planned Features

The following are planned for a future release:

- "Post as Story" toggle in the upload modal
- Full-screen story viewer with tap-to-advance navigation
- Story highlight reels on user profiles
- Story reactions and reply-to-story DMs
- REST API endpoints for story CRUD
