# Activity Stream Media

> **Included in Free** - MediaVerse is the most complete media solution for BuddyPress communities. Integration is optional - the plugin works standalone on any WordPress site, but when BuddyPress is active, it unlocks profile tabs, group media, activity stream, and notifications automatically.

MediaVerse records media events as BuddyPress activity items and enhances existing activity with media thumbnails and inline video players.

## Activity Types Registered

MediaVerse registers two custom activity action types:

| Action Type | Component | Label |
|-------------|-----------|-------|
| `mvs_media_upload` | `wpmediaverse` | Media Uploads |
| `mvs_media_upload` | `groups` | Group Media Uploads |
| `mvs_comment` | `wpmediaverse` | Media Comments |

These appear in the BuddyPress activity filter dropdown.

## When Activity Is Recorded

| Event | Action Hook | Activity Recorded |
|-------|-------------|-------------------|
| Media uploaded via UploadService | `mvs_media_uploaded` | "Username uploaded a new [type]" with thumbnail |
| Comment posted on media | `mvs_comment_created` | "Username commented on [media title]" (synced as an activity comment) |
| Media added to an album | `mvs_album_items_added` | Updates existing upload activity to reference the album |
| Media assigned to a group | `mvs_media_group_assigned` | Reassigns activity to the group component |
| Bulk album upload | `mvs_album_items_added` | One grouped activity for all files in the same upload action - see below |

### Bulk album upload activity grouping (1.2.0)

When a user uploads multiple files at once via the album upload modal, MediaVerse emits **one** grouped activity entry for the whole batch instead of one entry per file:

> **Username uploaded 3 photos to album _Portrait Series_** - with a 3-thumbnail grid

The mechanism: the upload modal sends `?album_upload=1` with each per-file `POST /media` request. The `mvs_media_uploaded` listener records a skip flag on the activity row, suppressing per-media activity creation. After the JS link call finishes, `mvs_album_items_added` fires once with the bundled media IDs and the listener emits a single grouped `bp_activity_add` with the thumbnail grid as content. Single-file album uploads still produce a per-photo activity (no bundling needed); ad-hoc photo posts via the activity composer keep their existing one-row-per-post behaviour.

## Activity Format

Upload activities use the format:
> **Username** uploaded a new **[type]**

When the media belongs to an album:
> **Username** uploaded a new **[type]** to album **[Album Name]**

For group uploads:
> **Username** uploaded a new **[type]** in the group **[Group Name]**

## Thumbnail Injection

MediaVerse injects media thumbnails into activity items in two ways:

1. `bp_get_activity_content_body` filter (priority 0) - transforms activity content to include an image tag.
2. `bp_activity_entry_content` action - injects thumbnails for activities with empty content (common for imported media).

Inline video players are injected for video media via `inject_video_player_in_activity`.

## Attaching Media via the Activity Post Form

The **Attach Media** button appears in the BuddyPress activity post form for members and inside groups.

Users select previously uploaded media or upload new files inline. The media IDs are attached to the activity via `bp_activity_posted_update` / `bp_groups_posted_update`.

## Posts With Several Items

A post made from several uploads follows its **strictest** item. If one item is private, the whole post is hidden from everyone else. Deleting one photo keeps the post and its other items.

Showing the post without its private items is planned for a later release. It is not available today.

Each tile on a post shows only to viewers who may open that item. When you make an upload from the Upload page, the BuddyPress lightbox steps through every item of the post.

## Privacy Changes

When you change an item's privacy, the activity post follows. This includes changes made through the REST API with `PUT /mvs/v1/media/{id}`. The hook is `mvs_media_privacy_changed`.

## Comments on Activity Posts

A comment made on an item in MediaVerse is mirrored onto its activity post as an activity comment. A reply sits under its parent comment, so the thread reads the same in both places.

| What you do in MediaVerse | What happens on the activity post |
|---------------------------|-----------------------------------|
| Post a comment | An activity comment appears. |
| Edit a comment | The edit shows in MediaVerse, and the comment is removed from the activity post, as BuddyPress does not edit comments. |
| Delete a comment | The activity comment is deleted, with its reply notifications. |
| Delete a comment that has replies | The replies and their activity comments are deleted too. |

Comments made before 2.6.2 carry no stored link to their activity comment. The first time you edit or delete one, MediaVerse looks for the activity comment once. It must match the same member, the same post, the same text and a time within 10 seconds. It acts only on exactly one match, and records the link. With no match or several matches, it leaves the activity comment alone.

To keep replies when a comment is deleted, return `false` from `mvs_comment_delete_replies`. See [Hooks & Filters](../developer-guide/hooks-filters.md).

## BuddyNext Is a Separate Path

BuddyPress and BuddyNext are alternatives. If your site runs BuddyNext, media links open the BuddyNext post the media was shared in, and BuddyNext publishes the feed cards. See [BuddyNext Integration](../buddynext/overview.md) and [Feed Media](../buddynext/feed-media.md).

On any community plugin, MediaVerse lets the host point member links and avatars at its own profile pages, instead of MediaVerse's standalone `/media/@handle/` profile. On BuddyPress sites, member links in grids, cards, notifications and share text already go to the BuddyPress member profile.

## Allowed HTML Tags in Activity

MediaVerse extends the `bp_activity_allowed_tags` filter to allow its custom HTML attributes (`data-mvs-*`, `data-wp-*`) to pass through BP's kses filter without being stripped.
