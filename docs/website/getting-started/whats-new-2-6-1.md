# What's New in 2.6.1

MediaVerse 2.6.1 tightens privacy, makes Explore easier to browse, and clears up the prompts members see when they upload and organize media.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.6.1 - install and test both together.

## Privacy

- Private and members-only albums and collections are listed on the /album/ and /collection/ pages only to people allowed to see them.
- The tag cloud counts only public items, and the tag list shows logged-out visitors only tags used on public items.
- Duplicate detection checks only the member's own uploads. A file another member uploaded is not a duplicate, and the notice says "You had already uploaded N of these files."

## Explore

- Sort by Newest, Trending, Oldest or Most viewed. Trending ranks by recent reactions, comments and views.
- A Type filter (All types, Photos, Videos, Audio) shows on Explore and on the Pro feed layouts.
- Lists with Load More load the next 3 pages as you scroll, then show the button so the footer stays reachable.

See [Display Settings](../settings/display.md#explore-sort-type-filter-and-loading).

## Upload

- "Matching tags" pills complete the word a member types in Tags.
- The full upload page shows the picked file's thumbnail and name.
- When **Allow Users to Set Privacy** is off, members see who will see their upload, for example "Public: anyone can see. The site owner sets this for every upload."

## Lightbox

- The Save button shows "Saved" while the item is in the member's Favorites.
- Image alt text is the AI description when there is one, otherwise the title.

## Albums

- Adding a photo that is more private than the album names the photos, and the button reads 'Show them as "Public"' (or the album's privacy).
- Changing an album to a stricter privacy asks first and says how many photos are affected.
- In wp-admin, **Album type** is a choice: Standard album or Playlist (audio only).
- WordPress's password and private options are hidden for albums and collections. See [Albums](../features/albums.md).

## Emails

- Two new emails in **Settings > General > Emails**: New follower, and Comment on their media. They start on for a new install and off for a site that updates.
- On BuddyNext sites, BuddyNext sends member emails for MediaVerse activity and the Emails section does not appear. The account deletion confirmation is always sent.

## My Media

- On BuddyNext sites, My Media no longer shows the Edit profile section or the "Complete your profile" banner. Members edit their profile in BuddyNext.
- On phones, My Media shows media first with a compact profile row.

## For developers

- Media objects in the REST API include `alt`, filtered by `mvs_media_alt_text`.
- `GET /mvs/v1/tags` returns only public-item tags to logged-out visitors.
- The core routes `/wp/v2/mvs-albums`, `/wp/v2/mvs-collections` and `/wp/v2/mvs_tag` are removed. Use the `mvs/v1` routes. `/wp/v2/mvs_category` remains.
- New `mvs-collections-changed` event. See [Hooks & Filters](../developer-guide/hooks-filters.md).

See the full changelog in `readme.txt` for every change.
