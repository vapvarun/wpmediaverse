# Activity Stream Media

> **Included in Free** - WPMediaVerse is the most complete media solution for BuddyPress communities. Integration is optional - the plugin works standalone on any WordPress site, but when BuddyPress is active, it unlocks profile tabs, group media, activity stream, and notifications automatically.


WPMediaVerse records media events as BuddyPress activity items and enhances existing activity with media thumbnails and inline video players.

## Activity Types Registered

WPMediaVerse registers two custom activity action types:

| Action Type | Component | Label |
|-------------|-----------|-------|
| `mvs_media_upload` | `wpmediaverse` | Media Uploads |
| `mvs_media_upload` | `groups` | Group Media Uploads |
| `mvs_comment` | `wpmediaverse` | Media Comments |

These appear in the BuddyPress activity filter dropdown.

## When Activity Is Recorded

| Event | Action Hook | Activity Recorded |
|-------|-------------|-------------------|
| Media uploaded via UploadService | `mvs_media_uploaded` | "Username uploaded [media title]" with thumbnail |
| Media published via admin/WP-CLI | `publish_mvs_media` | Fallback activity without thumbnail |
| Comment posted on media | `mvs_comment_created` | "Username commented on [media title]" |
| Media added to an album | `mvs_album_items_added` | Updates existing upload activity to reference the album |
| Media assigned to a group | `mvs_media_group_assigned` | Reassigns activity to the group component |
| Bulk album upload | `mvs_album_items_added` | One grouped activity for all files in the same upload action - see below |

### Bulk album upload activity grouping (1.2.0)

When a user uploads multiple files at once via the album upload modal, WPMediaVerse emits **one** grouped activity entry for the whole batch instead of one entry per file:

> **Username uploaded 3 photos to album _Portrait Series_** - with a 3-thumbnail grid

The mechanism: the upload modal sends `?album_upload=1` with each per-file `POST /media` request. The `mvs_media_uploaded` listener records a skip flag on the activity row, suppressing per-media activity creation. After the JS link call finishes, `mvs_album_items_added` fires once with the bundled media IDs and the listener emits a single grouped `bp_activity_add` with the thumbnail grid as content. Single-file album uploads still produce a per-photo activity (no bundling needed); ad-hoc photo posts via the activity composer keep their existing one-row-per-post behaviour.

## Activity Format

![BuddyPress activity item showing a media upload](../images/bp-activity-stream.jpg)

Upload activities use the format:
> **Username** uploaded a **[type]** - [media title]

For group uploads:
> **Username** uploaded a **photo** in the group **[Group Name]** - [media title]

## Thumbnail Injection

WPMediaVerse injects media thumbnails into activity items in two ways:

1. `bp_get_activity_content_body` filter (priority 0) - transforms activity content to include an image tag.
2. `bp_activity_entry_content` action - injects thumbnails for activities with empty content (common for imported media).

Inline video players are injected for video media via `inject_video_player_in_activity`.

## Attaching Media via the Activity Post Form

The **Attach Media** button appears in the BuddyPress activity post form for members and inside groups.

![BuddyPress activity post form with Attach Media button](../images/bp-activity-media-grid.jpg)

Users select previously uploaded media or upload new files inline. The media IDs are attached to the activity via `bp_activity_posted_update` / `bp_groups_posted_update`.

## Allowed HTML Tags in Activity

WPMediaVerse extends the `bp_activity_allowed_tags` filter to allow its custom HTML attributes (`data-mvs-*`, `data-wp-*`) to pass through BP's kses filter without being stripped.
