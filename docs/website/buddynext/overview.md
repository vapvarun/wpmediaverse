# BuddyNext Integration Overview

MediaVerse integrates with [BuddyNext](https://buddynext.com/) so a member's uploads appear in the community feed, on their profile, and inside spaces - without leaving the engine that stores them.

> **BuddyNext and BuddyPress are alternatives, not a stack.** Run one or the other. If your site uses BuddyPress instead, see [BuddyPress Integration](../buddypress/overview.md) - the same MediaVerse features are available through that path.

## Requirements

| | |
|---|---|
| BuddyNext | 1.2.0 or later (the MediaVerse bridge ships inside BuddyNext) |
| MediaVerse | 2.4.0 or later |
| MediaVerse Pro | Optional - adds documents, layouts and competitions to the surfaces below |

## Which side owns what

The integration is deliberately one-directional: **BuddyNext is the display layer and MediaVerse owns the media**. BuddyNext owns the profile and space media and document screens, and the notifications and emails for media activity. MediaVerse supplies the media and the privacy rules. The bridge lives in BuddyNext (`WPMediaVerseBridge`), not in MediaVerse, so MediaVerse ships no BuddyNext-specific code and the two can be updated independently.

MediaVerse exposes the seams; BuddyNext consumes them:

- **Filters and actions** - uploads, deletions, reactions, favourites, follows, mentions, messages and document-drive access
- **The media repository** - titles, durations and per-viewer signed thumbnails, resolved at render time rather than frozen into a post

## What the integration adds

- **Feed cards for uploads.** A public upload publishes a card into the BuddyNext feed. Photos become native photo posts carrying the media ids; video, audio and documents become typed `media` cards that link back to the item.
- **Per-viewer resolution.** A card stores only the media id. Its title and cover are read at render for the person reading the feed, so tightening a file's privacy immediately stops showing its poster and name to others.
- **Profile media.** A member's uploads are reachable from the Media tab on their BuddyNext profile. With MediaVerse Pro, members also get a Files tab for their own documents.
- **Space media.** A space owner can switch on a Media tab and a Files tab for the space. Media uploaded into a space is announced in that space's feed.
- **Notifications and emails.** BuddyNext sends them. See [Notifications](notifications.md). MediaVerse sends no activity emails while BuddyNext is active.

## My Media on BuddyNext sites

The Edit profile section and the "Complete your profile" banner are not shown in My Media. Members edit their profile in BuddyNext. On phones My Media shows media first, with a compact profile row, and hides the drag-and-drop box where the floating upload button exists.

## Turning it on and off

The bridge is wired whenever both plugins are active. Each surface is gated by the per-aspect **Integrations** toggle in BuddyNext, which is the single source of truth - there is no separate master switch.

When BuddyNext is running, MediaVerse hides its own versions of the screens BuddyNext already owns, such as the chat panel and the Messages page, so members do not see two of anything.

## Single-media URLs

By default a `/media/{slug}/` link resolves to the feed post the media was shared in, so media lives in the community rather than as a separate page. Site owners who prefer MediaVerse's own media pages can change **Media links** to **Open a dedicated media page** in the BuddyNext settings, under General, Discovery. Documents are unaffected and always open their own page.
