# What's New in 2.6.0

MediaVerse 2.6.0 is a simpler MediaVerse. There is one way to keep an item, the lightbox is clearer, My Media is lighter, and the settings screens went from about 130 controls to about 55. Members get emails they choose, site owners can switch private messaging off completely, and document folders got a proper trash.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.6.0 - install and test both together.

## Save keeps an item

There is one button for keeping something: **Save**. Everything a member saves, and everything they favorited before 2.6.0, is in their private **Favorites** collection. With Pro, the Save picker lists Favorites first, then the member's other collections.

## A clearer lightbox and a lighter My Media

The lightbox shows previous and next whenever there is a neighbour, supports swipe on touch, shows the item title, and shows cover art behind audio. Upload details appear after a file is picked, the + button uploads in one tap, and untitled uploads get a readable title from the file name. Every list has one sort control, and empty lists hide search and sort.

## Fewer settings, in plain words

Settings show only while their switch is on, and developer-only settings left the screen. One **Layout** choice replaces the separate layout settings (Pro's Instagram, Pinterest, Flickr and Dribbble layouts are part of it). Logs moved under Tools, Reports is a Moderation tab, and a Mobile App tab holds app sign-in, branding, terms and the abuse contact. Settings you had before keep their saved values, so nothing changes on update.

## Member emails and storage

Emails for photo battle invites, shared documents and reviewed reports can each be switched on in **Settings > General > Emails**, and members can turn activity emails off with one profile switch or the unsubscribe link. An optional storage limit per member, unlimited by default, replaces Pro's quota packages and credits; existing limits migrate automatically. A **Who can upload media** role picker decides who uploads, and upload controls hide for everyone else.

## Messages can be turned off

**Settings > Messages > Messages** turns private messaging on or off for the whole site. Off, the chat, the /messages/ page, Message buttons, the messaging API and Pro group chat disappear, and so do the Messages pages of community plugins built on MediaVerse, such as BuddyNext. Conversations are kept for when you turn it back on, and members can still export or erase them.

**Who can send messages: Nobody** is still there for a softer stop: no new messages, while members keep reading what they have. See [Messages Settings](../settings/social.md).

## Document folders get a real trash (Pro)

Trashing a folder now takes everything inside it: its subfolders and every document, which can no longer be opened, downloaded or found by search. Restoring the folder brings back exactly what it took, and a file trashed on its own before stays in the trash. A trashed folder's name is free straight away, and a restored folder whose name was taken comes back as "Name (restored)".

The trash empties itself: folders are deleted permanently 30 days after they were trashed (folders already in the trash when you update get 30 days from the update). Change the period with the `mvs_folder_trash_retention_days` filter.

On a space's shared drive, only the space owner, moderators and admins can rename, move, trash or restore folders. Members create folders and manage the ones they made. See [Documents](../pro-features/documents.md).

## Correct times on any host

Stored times are UTC even when the database server or the site runs in another timezone, so usage history, space links, device tokens, trending windows, view retention and the Pro Competitions dashboard no longer drift by the server's offset.

## Privacy and security

- Private items look exactly like missing ones on pages, in the API and in album counts.
- Draft media is no longer returned to signed-out visitors through the API.
- Block style fields no longer accept CSS that could inject into the page.
- Files inside a trashed folder can no longer be reached (Pro).

## For developers

- New filters: `mvs_messaging_enabled`, `mvs_show_favorite_button`, `mvs_default_media_title`, `mvs_email_subject`, `mvs_email_body`, `mvs_community_profile`, `mvs_profile_edit_redirect`, `mvs_show_demo_import`; new action `mvs_report_resolved`.
- Pro: `mvs_folder_trash_retention_days`, `mvs_folder_before_purge`, `mvs_document_folder_deleted`, and `DELETE /mvs-pro/v1/folders/{id}?force=true`. Folder responses carry `can_manage` and the list returns `X-MVS-Can-Create-Folder`, so apps render controls from MediaVerse's rules.
- Per-media access rules and the Lock Overlay block were removed (MediaVerse is not a membership plugin), and so were Pro's quota packages, credits and membership mapping.
- Uninstall keeps member data unless the owner opts in.

See the full changelog in `readme.txt` for every change.
