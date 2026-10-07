# Stories

> **Pro feature.** Stories (time-limited, ephemeral posts) ship in MediaVerse Pro. The free plugin provides the upload-flow entry point described below, but story creation, the viewer, and view receipts all require Pro.

## What's in Free

The Media Upload block includes an **"Also share as a story"** checkbox next to the tag input, but only when MediaVerse Pro is active and its Stories feature is switched on. On a free-only install the checkbox stays hidden and no story code runs. Stories moved wholly to Pro in 1.9.0.

## What Pro Adds

See [Stories (Pro)](../pro-features/stories.md) for the full feature: 24-hour default expiry (1-168h configurable per story), a tap-to-advance viewer, "seen by" receipts, and the `mvs-pro/v1` REST routes that drive it (`GET /stories`, `POST /media/{id}/story`, `DELETE /media/{id}/story`, `POST /stories/{id}/view`, `GET /stories/{id}/viewers`).

## Hooks

`mvs_story_created` and `mvs_story_expired` are Pro hooks (fired from `WPMediaVersePro\Stories\StoryService`). See [Hooks & Filters - Access & Privacy](../developer-guide/hooks-filters.md#13-access--privacy).
