# What's New in 2.6.2

MediaVerse 2.6.2 keeps chat files inside the chat, makes galleries behave the same everywhere, and tidies BuddyPress posts, messaging and the licence screen.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.6.2 - install and test both together. Requires PHP 8.1 or newer.

## Galleries

- Files uploaded together show as one tile with a badge on Explore, the Media Grid block, the feeds and the BuddyPress profile and group Media tabs.
- The badge counts the items the person looking may see, and the lightbox steps through exactly those items.
- A gallery stays listed when its first item is made private or deleted: the next item a viewer may see fronts it.
- Under a filter (Explore > Videos, a tag, a category or a search) a gallery is one tile fronted by its first matching item, and the lightbox steps through the matching items only.
- Each step in the lightbox shows that item's own reactions and comments.
- The List layout shows each item's title, date, counts and uploader beside the thumbnail, on Explore, profiles, the BuddyPress Media tabs and the Media Grid block.

See [Upload Media](../features/media-upload.md).

## Comments and notifications

- Deleting a comment also deletes its replies. The prompt says how many replies go with it.
- The notifications a deleted comment raised are removed with it.
- Following, favouriting or reacting again notifies the owner only once.
- Read notifications older than 90 days are removed daily.
- Reading a conversation clears its message notifications.
- The notifications bell on My Media closes on an outside click or Escape and shows times such as "2 hours ago".

See [Social Features](../features/social-features.md).

## Messages

- Files sent in a chat live only in that conversation: they are not listed in My Media or the media list. Files attached but never sent are deleted after a day.
- Clicking an image in a chat opens it full size. Videos show a frame and audio files get a player.
- A conversation appears in the other member's inbox only once the first message is sent.
- "Pinned" shows only on pinned conversations, and the typing indicator clears as soon as the message arrives.
- A blocked pair cannot reopen their conversation, does not see each other online, and a refused message says why.
- A sent message offers one "for everyone" control: Unsend while it is new, Delete after that. Both ask before they act.
- Photo and video messages have a Message options button, so Reply, React and Unsend work on touch screens. Escape closes an open message menu.
- A new conversation shows the member's name and picture in the list straight away.
- The Messages page and the media profile no longer show a blog post's title as their heading.

See [Direct Messages](../features/direct-messages.md).

## BuddyPress

- Comments deleted or edited in MediaVerse are removed from the activity post, with their replies and notifications, including comments made before 2.6.2.
- The BuddyPress lightbox steps through every photo of a post made from the Upload page.
- Visitors can open photos from the profile and group Media tabs, and Load More on a group tab shows only that group's media.

## Pro

- **Pro** Captions are written for videos only by default, and members can request a transcript only while auto-captions is on.
- **Pro** Boost is offered only on items a boost accepts, on the media page and the Instagram feed card.
- **Pro** Competitions accept only public, approved media.
- **Pro** BuddyBoss and MediaPress imports keep photo privacy: filing a private photo in an imported album no longer makes it public.
- **Pro** A document attached to a BuddyNext post opens, for viewing only, for the people who can read that post.
- **Pro** Licence activation and deactivation go through the bundled update SDK, and activating no longer turns on usage tracking.

See [Pro Overview](../pro-features/overview.md).

## For developers

- GET `/mvs/v1/media` accepts `group`, and GET `/mvs/v1/media/{id}/group` accepts the listing's filter (`media_type`, `tag`, `category`, `s`, `group`).
- New filters `mvs_comment_delete_replies`, `mvs_unsent_chat_file_hours` and `mvs_privacy_level_value`.
- A database update adds indexes and refreshes BuddyPress activity privacy. It may take a moment on large sites.

See the [Developer Guide](../developer-guide/rest-api.md).
