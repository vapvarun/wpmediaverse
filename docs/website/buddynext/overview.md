# BuddyNext Integration Overview

MediaVerse integrates with [BuddyNext](https://buddynext.com/) so a member's uploads appear in the community feed, on their profile, and inside spaces - without leaving the engine that stores them.

> **BuddyNext and BuddyPress are alternatives, not a stack.** Run one or the other. If your site uses BuddyPress instead, see [BuddyPress Integration](../buddypress/overview.md) - the same MediaVerse features are available through that path.

## Requirements

| | |
|---|---|
| BuddyNext | 1.2.0 or later |
| MediaVerse | 2.4.0 or later |
| MediaVerse Pro | Optional - adds documents, layouts and competitions to the surfaces below |

## Which side owns what

The integration is deliberately one-directional: **BuddyNext owns the community UX, MediaVerse owns the media**. The bridge lives in BuddyNext (`WPMediaVerseBridge`), not in MediaVerse, so MediaVerse ships no BuddyNext-specific code and the two can be updated independently.

MediaVerse exposes the seams; BuddyNext consumes them:

- **24 filters and actions** - uploads, deletions, reactions, favourites, follows, mentions, messages and document-drive access
- **The media repository** - titles, durations and per-viewer signed thumbnails, resolved at render time rather than frozen into a post

## What the integration adds

- **Feed cards for uploads.** A public upload publishes a card into the BuddyNext feed. Photos become native photo posts carrying the media ids; video, audio and documents become typed `media` cards that link back to the item.
- **Per-viewer resolution.** A card stores only the media id. Its title and cover are read at render for the person reading the feed, so tightening a file's privacy immediately stops showing its poster and name to others.
- **Profile media.** A member's uploads are reachable from their BuddyNext profile.
- **Space media.** Media uploaded into a space is announced in that space's feed.
- **Notifications.** Reactions, comments, mentions and follows raise BuddyNext notifications.

## Turning it on and off

The bridge is wired whenever both plugins are active. Each surface is gated by the per-aspect **Integrations** toggle in BuddyNext, which is the single source of truth - there is no separate master switch.

MediaVerse also exposes `mvs_buddynext_active`. When BuddyNext is running it returns true, and MediaVerse suppresses its own versions of surfaces BuddyNext already owns, so members do not see two activity feeds or two member directories.

## Single-media URLs

By default a `/media/{slug}/` link resolves to the feed post the media was shared in, so media lives in the community rather than as a separate page. Site owners who prefer MediaVerse's own media pages can change that with the `buddynext_media_single_pages` option. Documents are unaffected and always open their own page.
