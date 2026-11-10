# Stories

> **PRO** - Requires MediaVerse Pro. This feature is not in the free plugin.

MediaVerse Pro adds WhatsApp-style ephemeral stories: mark any upload as a story and it is visible to your followers for a limited time, then disappears automatically.

> The stories bar and viewer are one block, so they look and behave the same on MediaVerse pages, in BuddyNext's home feed and on the mobile web. The mobile app uses the same API.

## How Stories Work

1. Turn the feature on at **MediaVerse > Settings > Display > Stories** (tick **Enabled**). It is off by default. Once enabled, every upload form shows an **"Also share as a story"** checkbox for members who may post stories, and the Stories block renders its bar. Choose how long stories last in **Story length** just below (6 hours to 7 days; 24 hours by default). The checkbox text shows the length you chose.
2. A member ticks that box when uploading. The upload becomes a story for the length you set. Photos, video and audio can be stories. (An app or API client can ask for a shorter story, never a longer one.)
3. Viewers see active stories from people they follow, plus their own, in a horizontal bar. Selecting one opens a full-screen viewer that advances on tap, with a progress bar per story. The viewer works with the keyboard.
4. A view is recorded when a viewer opens a story. The author's own views are not counted.
5. Members can react to someone else's story with the same six reactions media has (like, love, haha, wow, sad, angry), one per person; tap again to remove it.
6. On their own story the author sees **Seen by**, with each viewer's reaction beside their name and a tally of reactions, and **End story** to take it down for everyone at once.
7. Stories are removed automatically by an hourly job. A site owner can end any story early from **MediaVerse > Stories**.

Who can see a story follows the media's privacy. The author always sees their own. Other members see stories set to Public, and Members-only stories when they are signed in. Private items never appear.

The Instagram layout shows the stories bar at the top of the feed. The bar appears only with that layout; on other layouts, place the Stories block on a page. In the mobile app, stories work with any layout.

## Gutenberg Block

**`mvs/pro-stories`** renders the stories bar and the fullscreen viewer, built entirely on the WordPress Interactivity API. Logged-in visitors also see a "Your story" add tile at the start of the bar - picking an image uploads it and posts it as a story in place, no separate upload flow needed. The add tile accepts photos and video; to post audio as a story, use the upload form checkbox. The tile only shows for members who hold the **Post stories** capability.

**Block Settings:**

| Attribute | Default | Description |
|-----------|---------|-------------|
| `count` | `30` | Number of authors' stories to load in the bar |
| `avatarSize` | `64` (px) | Avatar circle diameter |

The block renders nothing when Stories is not enabled, and shows only the "Your story" add tile (no bar) for a logged-in viewer whose network has no active stories yet. Anonymous visitors see nothing when there are no active stories.

## Admin

**MediaVerse > Stories** lists every active story site-wide with its author, title, status, expiry time and **Seen by** count, and a **Force expire** action for moderation. Only users who can manage MediaVerse settings see this screen. A site owner never needs database access to pull down a story.

**Who may post.** **MediaVerse > Permissions** has a **Post stories** column (shown while Stories is enabled). Every role that can upload keeps it by default, so no site loses the feature on update; untick a role to stop its members posting. Everyone can still view stories and end their own.

## Where Story State Lives

Story flags are stored as free plugin media meta, not a separate Pro copy of the media:

| Key | Description |
|-----|-------------|
| `is_story` | `1` while the story is active |
| `story_started_at` | UTC datetime the story was created |
| `story_expires_at` | UTC datetime the story disappears |

There is no separate story-views table. View receipts reuse Free's `mvs_media_views`, and "seen by" is derived by windowing on `story_started_at` so a re-shared story doesn't inherit an earlier run's viewers.

## REST API

**Base URL:** `/wp-json/mvs-pro/v1/`

### GET /stories

List active stories - defaults to the viewer's network (people they follow, plus themselves), or pass `author_id` to view one person's stories.

**Auth:** Public (an empty result for logged-out visitors, since the default scope needs a viewer)

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `author_id` | int | (viewer's network) | Scope to one author |
| `page` | int | `1` | Page number |
| `per_page` | int | `50` | Items per page (max 100) |

**Response:**

```json
[
  {
    "media_id": 42,
    "media_type": "image",
    "thumbnail_url": "https://example.com/wp-content/uploads/wpmediaverse/2026/07/42-medium.jpg",
    "expires_at": "2026-07-10T09:00:00Z",
    "viewed": false,
    "author": {
      "id": 7,
      "name": "Jamie Rivera",
      "avatar": "https://example.com/avatar.jpg",
      "profile_url": "https://example.com/media/@jamie/"
    }
  }
]
```

### POST /media/{id}/story

Mark a media item as a story.

**Auth:** Owner/Admin, and the member needs the Post stories capability

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `duration_hours` | int | your Story length | Visibility window in hours. A value above your Story length is capped to it |

**Response:** `{ "media_id": 42, "is_story": true, "expires_at": "2026-07-10T09:00:00Z" }`

### DELETE /media/{id}/story

End a story early. The media item itself is untouched - only the story designation is cleared.

**Auth:** Owner/Admin

**Response:** `{ "media_id": 42, "is_story": false }`

### POST /stories/{id}/view

Record a view receipt for the current user. The author's own views are never recorded - "seen by" counts the audience, not the owner.

**Auth:** User, subject to the media's normal privacy check

**Response:** `{ "recorded": true }`

### GET /stories/{id}/viewers

"Seen by" list for a story.

**Auth:** Owner/Admin

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `page` | int | `1` | Page number |
| `per_page` | int | `50` | Items per page (max 100) |

**Response:** `{ "viewers": [ { "user_id": 3, "name": "Alex", "avatar": "...", "profile_url": "...", "viewed_at": "2026-07-09T18:02:00Z", "reaction": "love" } ], "total": 12, "reactions": { "love": 5, "like": 3 } }`. `reaction` is empty when the viewer did not react.

### POST /media/{id}/reactions

Reactions use the Free route `/wp-json/mvs/v1/media/{id}/reactions` with `{ "reaction_type": "love" }`; the same type again removes it.

## Hooks

| Hook | Type | Description | Parameters |
|------|------|-------------|------------|
| `mvs_story_created` | action | Fires after a story is created | `$media_id`, `$user_id`, `$expires_at` |
| `mvs_story_expired` | action | Fires when a story ends (cron cleanup or manual end) | `$media_id` |

Full reference: [Hooks & Filters](../developer-guide/hooks-filters.md#13-access--privacy).

## History

Stories originated as a free-plugin primitive but were never surfaced with a create-flow or viewer. In 1.9.0, `StoryService` was relocated to Pro and shipped as a complete feature: the upload toggle, viewer carousel, REST API, and view receipts. Free retains only the toggle UI, which stays hidden until Pro turns on `mvs_stories_enabled`.
