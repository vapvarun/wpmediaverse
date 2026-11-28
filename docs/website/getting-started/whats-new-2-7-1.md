# What's New in 2.7.1

MediaVerse 2.7.1 lets a host plugin stop a suspended member on every message path, accepts videos recorded on phones, and makes a takedown on cloud storage remove the public copy.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.7.1 - install and test both together. Pro 2.7.1 requires Free 2.7.1.

## Members

- Videos recorded on a phone or a screen recorder (a QuickTime-branded .mp4, or a .mov) upload wherever MP4 is allowed, from the feed, the dashboard and the Upload Media page. The file dialog lists .mov too. A site that removes .mov from the WordPress upload types keeps refusing it.
- On a member's profile, the tag chips filter that member's media and stay on the profile. Only the tags that member used are offered, and a member with no tags shows a short message.
- Creating a collection or saving an album the server refuses no longer says it worked; the dialog stays open and shows the reason.
- In My Media the bulk Add to album list is ready when you select items, the item count drops straight after a delete, and tags typed with commas in the edit dialog become separate tags.
- Following someone from their profile header or from a post in the Instagram layout updates every Follow button for that member.
- Keyboard focus rings show on the lightbox Close and Fullscreen buttons, dashboard thumbnails, the edit dialog checkboxes and chat rows.
- On the BuddyPress profile, the Upload Media and Create Album buttons have about 24px of space above them on every theme and profile layout, so they no longer touch the sub-tabs.

## Site owners

- Rejecting, flagging or trashing a public photo on cloud storage (Bunny, S3, R2, Spaces) now brings its files back to the server and removes the CDN copy, so the old link stops working. This is the same thing that already happened when a photo was made private.
- A cloud sync no longer records a CDN link for a photo size the CDN does not hold.

## Developers

- New filter `mvs_can_create_conversation` lets a host plugin refuse group creation. See [Hooks and filters](../developer-guide/hooks-filters.md).
- `mvs_message_content_check` now also runs for attachment-only messages, with an empty text.
- With BuddyNext 1.3.0 (re-roll) installed, its suspended-member gates use these two seams. Without it they do nothing.

## Pro

- The Pinterest feed sits on the theme background in dark mode.
- On the Instagram profile, Follow is a filled button, Following and Message have a visible outline, and the row wraps on a phone.
- A shortcode placed without its id shows editors a plain heading, where to find the id and an example.
- The `[mvs_pro_leaderboard]` shortcode keeps rows and names readable in dark mode.
