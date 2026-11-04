# What's New in 2.6.3

MediaVerse 2.6.3 makes the download setting hold on every player and link, opens media from BuddyPress activity, and lets blocks find albums and media by name.

> **Included in Free** unless marked Pro. Paired with MediaVerse Pro 2.6.3 - install and test both together. Requires PHP 8.1 or newer.

## Downloads

- With downloads off for the site or for an item, players show no download option in their menu and no right-click menu.
- Signed download links and files sent in messages follow the setting when they are opened, not only when they were made.
- **Pro** Instagram layout players follow the same setting.

See [Privacy and Access Control](../features/privacy-access-control.md).

## BuddyPress

- Media tiles in the activity stream open their item, where members can react, comment and report.
- Members-only photos show to members in the activity stream instead of a placeholder.
- In the post box, Attach media is a plain tool beside Poll, with its label and a tooltip, on Reign, BuddyX and other themes.

See [Activity Media](../buddypress/activity-media.md).

## Blocks

- Album Viewer and Media Player pick an album or file by searching its title or browsing a list, and the editor shows the chosen item by name.
- Media Player lists only video and audio, and its search filters by what you type.
- Media Grid offers Category and Tag as searchable lists instead of typed slugs.
- Blocks saved with typed ids or slugs keep working and now show the item's name.
- The PDF Viewer block is no longer offered in the block inserter, because PDF uploads are turned off. Pages that already use it keep working, and so does the `[mvs_pdf_viewer]` shortcode. To share PDFs with members, use Documents (Pro).

See [Blocks](../features/blocks.md).

## Messages

- Attaching a file to a message works in Safari.
- Files sent before 2.6.2 show again in their conversations.
- Who can message you keeps short choices and explains the selected one underneath, including who can still send a message request.

See [Direct Messages](../features/direct-messages.md).

## Uploads and mentions

- When a pick mixes photos and videos and only one kind is allowed, the upload window names the files left out and keeps the note until you close it.
- Mentions of handles that contain dots or hyphens reach the right member.

## For site owners

- A signed link to an item moved to the trash stops working, including the owner's older links.
- Contributors and Authors no longer see the Albums and Collections admin screens, which only site managers can open.
- Site Health says what the direct-delivery check returned and which Apache rule fixes it.

See [Common Issues](../troubleshooting/common-issues.md).

## Pro

- **Pro** Challenge and tournament cards with no cover show a banner in the site colour with a trophy.
- **Pro** The leaderboard's XP tab shows WB Gamification points.
- **Pro** A document can be shared with a member by the @handle the community shows.
- **Pro** Documents: New folder opens as a panel under its button, full width on phones.
- **Pro** Stories: screen readers announce story buttons as buttons, and the Your story tile shows keyboard focus.

See [Pro Overview](../pro-features/overview.md).

## For developers

- New action `mvs_users_primed` fires after each batch of members is loaded, so avatar and profile plugins can batch their own queries.
- New filter `mvs_resolve_user` matches an @handle to a member.
- GET `/mvs/v1/media` accepts a comma-separated `media_type` list, such as `video,audio`.
- Albums and uploads accept an optional original date, so an importer keeps the source site's dates.

See [Hooks and Filters](../developer-guide/hooks-filters.md).
