# What's New in 2.2.0

WPMediaVerse 2.2.0 is a polish and privacy release: the whole REST surface can now follow your community's privacy setting, chat gets live reactions and a cleaner attachment experience, and a batch of visible UX fixes across profiles, the lightbox, Explore layouts, and admin settings.

> **Included in Free.** Every item on this page applies to the free version unless marked Pro. Paired with WPMediaVerse Pro 2.2.0 - install and test both together.

## Private-community REST gate

When the host community is private (for example a BuddyNext site in private mode), the entire REST surface now requires a logged-in user - including public reads, and including the Pro `mvs-pro/v1` namespace (tournament brackets and other public Pro reads) - so nothing leaks to logged-out visitors through the API. Unauthenticated requests get `401 mvs_community_private`. The gate is off by default and developer-controllable via the `mvs_rest_require_auth` and `mvs_rest_can_access` filters. See [REST API: Authentication](../developer-guide/rest-api.md).

## Messaging improvements

- **Live reactions** - a reaction added to a message you already received now appears for you in real time, without a page reload.
- **Media-only attachments** - chat attachments now accept images, video, and audio only. Documents (PDF, DOC, ZIP) are rejected with a clear message, client- and server-side. Site owners who need documents back can extend the `mvs_dm_allowed_file_types` filter. See [Direct Messages](../features/direct-messages.md).
- **Upload feedback** - attaching a video or audio file shows an "Uploading..." chip with a spinner, and Send waits until the upload finishes - a message can no longer go out without its file.

## Profile and lightbox fixes

- **Live follower counts** - the follower and following numbers on a member profile update the moment you press Follow or Unfollow, on the default layout and on every Pro layout.
- **Favorite heart** - the lightbox Favorite button now fills and turns red when favorited (the state saved correctly before, but the color was stuck).

## Explore empty states (Pro layouts)

The Flickr, Pinterest, and Dribbble Explore layouts now show a proper empty state when a search has no results or a tag does not exist - a heading naming the term, a "Browse all media" button, and popular-tag chips - instead of a generic message or, for unknown tags, a silently unfiltered feed. Matches the behavior the default layout has always had.

## Admin polish

- The "Settings saved." notice can be dismissed, fades out on its own after a few seconds, and no longer follows you across settings sections.

## Compatibility

- **BuddyBoss and older BuddyPress** - fixed a fatal error where `bp_get_group_url()` does not exist.
- Requires and pairs with WPMediaVerse Pro 2.2.0 (Pro is a lockstep release: shared competition-media formatting internals, no Pro-only feature changes).
