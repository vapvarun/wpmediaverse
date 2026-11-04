---
journey: download-off-players-and-owner-screens
plugin: wpmediaverse
priority: high
roles: [subscriber, other-member, contributor, editor, administrator]
covers: [download-off-player-guard, album-admin-screen-cap, bp-composer-attach-tool, drive-new-folder-panel, direct-delivery-health]
prerequisites:
  - "BuddyPress active with the activity component (Reign + BuddyPress Polls), and separately BuddyNext 1.2.8+"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 12
---

# Downloads off holds on every player; owner screens show only what a role can open

"Allow downloads" off for an item (or the site switch off) means no Download
button, no Download in the browser's player menu, and no right-click menu on
the player. It is a deterrent: the help text says so, and stream URLs stay
long-lived so community media never breaks (owner decision, 2026-11-03).

## Steps

### 1. Players for a no-download item (Member B) - card:10458760669
- **Action**: Member A uploads an MP3 and an MP4 and unticks "Allow viewers to download this media" in My Media > Edit. Member B opens them in the Explore lightbox, the single media page, a media-player block, an audio playlist album, a BuddyPress activity post, and (with BuddyNext) the profile Media tab and BuddyNext lightbox.
- **Expect**: every `<audio>`/`<video>` for those items (grid previews included) carries `controlsList="nodownload"`; right-click on it opens no menu; `assets/js/frontend/player-guard.js` is on the page. A downloadable item on the same page has no attribute and right-click works.

### 2. The switch is on every owner edit form - card:10458760669
- **Action**: Member A opens Edit from My Media, from the single media page, and from the lightbox.
- **Expect**: each shows the checkbox with the hint "It cannot stop someone who can play or view it from saving or recording it."; saving round-trips `allow_download` (REST GET shows the new value). With the site switch off the field is not shown.

### 3. Admin menu by role - card:10463043524
- **Action**: Log in as Contributor, Author, Editor, then Administrator; open wp-admin.
- **Expect**: no MediaVerse > Albums / Collections entry below Administrator, and `edit.php?post_type=mvs_album` answers 403 for them. Members still create, rename and delete albums from My Media (mvs/v1) and BuddyNext (/me/albums).

### 4. Attach media in the BuddyPress post box - card:10459252444
- **Action**: As a subscriber open /activity/ on Reign with BuddyPress Polls, at 1280 and 390; then without Polls; then BuddyX, BuddyX Pro, Kadence.
- **Expect**: Attach media is borderless, 14px/600, about 20px after the Poll icon (aligned with the text box when it is the first tool), keeps its label, 40px tall, has a hover tooltip; picking a file shows privacy + tags in the same row; no sideways scroll.

### 5. New folder in Documents - card:10379702559
- **Action**: Open My Media > Documents (admin, or a licensed site), click New folder at 1280, 1024 and 390, create one.
- **Expect**: the New folder button stays in the toolbar row; the name field + Create open as a panel under it (full-width line at 390); the folder is created.

### 6. Site Health when direct delivery fails - card:10370404673
- **Action**: Replace uploads/wpmediaverse/.htaccess with `Require all denied`, delete option `mvs_direct_delivery` and transient `mvs_media_public_probe`, load wp-admin, open Site Health.
- **Expect**: "Media is private but sent through PHP, which is slower", with "Last check: the test file got HTTP 403." and, on Apache, the .htaccess to use. Remove the file, reset the two keys: "Media is private and served fast".
