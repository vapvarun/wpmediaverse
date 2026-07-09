# Stories

> **Pro feature.** Stories (time-limited, ephemeral posts) ship in WPMediaVerse Pro. The free plugin provides the upload-flow entry point described below, but story creation, the viewer, and view receipts all require Pro.

## What's in Free

The Media Upload block includes an **"Also share as a story"** checkbox next to the tag input, but only when the `mvs_stories_enabled` option is on:

```php
$mvs_stories_on = ( '1' === get_option( 'mvs_stories_enabled', '0' ) );
```

`mvs_stories_enabled` defaults to off and is set by Pro when it registers the Stories feature — so on a free-only install this toggle stays hidden and no story code runs. Free no longer ships a `StoryService`; the class was relocated to Pro in 1.9.0 (it was built ahead of the create-flow and viewer UI, then moved wholesale once those shipped).

## What Pro Adds

See [Stories (Pro)](../pro-features/stories.md) for the full feature: 24-hour default expiry (1-168h configurable per story), a tap-to-advance viewer, "seen by" receipts, and the `mvs-pro/v1` REST routes that drive it (`GET /stories`, `POST /media/{id}/story`, `DELETE /media/{id}/story`, `POST /stories/{id}/view`, `GET /stories/{id}/viewers`).

## Hooks

`mvs_story_created` and `mvs_story_expired` are Pro hooks (fired from `WPMediaVersePro\Stories\StoryService`). See [Hooks & Filters — Access & Privacy](../developer-guide/hooks-filters.md#13-access--privacy).
