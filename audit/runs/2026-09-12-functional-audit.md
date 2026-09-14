# MediaVerse functional audit - 2026-09-12

**For the owner:** the 14 core flows work for admins and members. The audit found 12 defects, now Bug cards: 1 P0 (strangers can react inside private DMs), 4 P1 (blocking does not stop viewing, profile Load More shows other members' media, Instagram Load More cards are dead, follow/favorite notifications are lost under BuddyPress), 4 P2, and 3 P3 layout-uniformity breaks.

- Build: Free 2.4.2 + Pro, mediaverse.local (Reign theme, BuddyPress, BunnyCDN storage, Pro licence active).
- Method: /wp-card-qa. Step 0 files, C-1 core paths walked as their named roles, two-member rule, admin last. Every absence treated as a lead until proven live. Findings triaged on reach, impact and location.
- Personas: journey_subscriber 105, journey_other 107, journey_author 108, journey_editor 109, journey_blocker 49, journey_blocked 50, journey_bystander 51, admin.

## C-1 core paths

| # | Flow | Role | Result |
|---|---|---|---|
| 1 | Member uploads a photo, it shows in Explore | journey_subscriber | PASS (201 in 185 ms; page 1, correct author) |
| 2 | Member sees the upload in their dashboard | journey_subscriber | PASS (sidebar count matches the grid) |
| 3 | Anonymous visitor browses Explore and opens an item | anonymous | PASS (react asks to log in; comment box says log in) |
| 4 | Private item: owner, other member, anonymous, nonexistent | 105 / 107 / anon | PASS by documented design: media 403 "This media is private", no file/og leak; nonexistent 404. See owner question 1 |
| 5 | Another member reacts and comments | journey_other | PASS (owner notified; undone) |
| 6 | Member reports, admin moderates | 107 / admin | PASS (toast, User Reports badge 3 to 2 after Dismiss) |
| 7 | Public video plays | anonymous | PASS (served from BunnyCDN, playing at 2.5 s) |
| 8 | Album create and upload into it | journey_subscriber | PASS on success; refusal path hangs, card 06 |
| 9 | Document shared with one member | 105 / 107 / 51 | PASS (grantee 200, bystander 404 identical to nonexistent) |
| 10 | Direct message | 105 to 107 | PASS; strangers can react inside it, card 01 |
| 11 | A setting changes the frontend | admin | PASS (items per page) |
| 12 | BuddyPress activity and profile Media tab | journey_subscriber | PASS; follow/favorite notifications lost, card 05 |
| 13 | Pro quota blocks uploads | journey_author, journey_subscriber | PASS on upload; Replace file bypasses it, card 08 |
| 14 | Cloud storage | - | PASS (local first, queued sync flips the URL to b-cdn) |

## Filed Bug cards

| Card | Priority | Finding |
|---|---|---|
| [01](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296867076) | P0 | Any logged-in member can add reactions inside other people's private DMs, even on nonexistent messages |
| [02](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296867415) | P1 | Blocking a member does not stop them viewing your media |
| [03](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296867603) | P1 | Profile Load More (Grid) fills a profile with other members' media |
| [04](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296867776) | P1 | Instagram layout: Load More cards have dead like/save, no follow/share/comment |
| [05](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296867984) | P1 | With BuddyPress active, follow and favorite notifications never reach the member |
| [06](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296868313) | P2 | Album page upload hangs on "Uploading 1 of 1..." when the server refuses the file |
| [07](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296868480) | P2 | Explore page 1 still shows media from members you blocked |
| [08](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296868662) | P2 | Replacing a media file skips the upload quota |
| [09](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296868773) | P2 | Uninstall leaves MediaVerse capabilities on user roles |
| [10](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296868932) | P3 | View counts only on Flickr and Dribbble cards |
| [11](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296869077) | P3 | Sort control only on the Grid layout |
| [12](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10296869184) | P3 | Profile header says "posts" on two layouts, "media" on the rest |

Cards 02 and 07 share one fix seam (block check in PrivacyService plus the shared query). Card 06 follows the same "refusal must reach the member" pattern as the upload modal.

## Checked and not a finding

- Upload modal quota refusal: the modal does show the server message as a 3 s toast (view.js ~1251, z-index above the overlay); my first sample came at 4.5 s.
- Uploads not reaching BunnyCDN: the queued sync moves them about a minute after upload; a raw read taken earlier looked local.
- Notification listeners missing: a WP-CLI artefact; the REST path writes rows.
- new_message link: /messages/ is a real published page.
- Empty profile states: role-correct, with an Upload Media button for the owner.
- Documents: denied equals nonexistent (branded 404), as designed.

## Owner questions (decisions, not cards)

1. A denied private photo answers 403 "This media is private" and still shows the item title, owner name and date (TemplateLoader ~700 says this is deliberate for media, while documents get 404). Keep it, or hide title/owner like documents?
2. After an upload refusal the modal keeps the file selected with the toast only. Preference, no card.

## Report-only

- CORE_PATHS ranking drift (C-4): documents is the most-reported area in 747 Done cards but ranked #9.
- audit/ROLE_MATRIX.md says editors are denied Moderation; they get it (200). Update the matrix in the QA-docs step.
- Undocumented hooks: Free 97 of 357, Pro 81 of 210.
- With Pro active the Free Reports submenu is hidden (by design) but admin.php?page=mvs-reports still loads by URL.
- mvs_media_index.comment_count drifts from mvs_media_stats (stats are what the UI shows).
- BuddyNext team: avatar remainder on card 10252323883 and a custom-privacy activity lead. Reign team: its avatar card.

## Not verified this pass

- Path 1: the 8 s upload success message was not caught on screen (2.7 s screenshot).
- Avatar upload uses wp_handle_upload / wp_insert_attachment (ProfileService.php:257/264) instead of the media pipeline. Code lead only; prove live before carding.

## Cleanup

All test data was removed through the product's own routes: media 63342/63343/63345, document 63344 and grant 99, album 207, comment 39, DM message 34, notifications 119-122 (plus BP rows 30/31), temporary block row 3, report 13 (dismissed in admin), and the temporary quota packages on 105/108. Evidence screenshots: ~/Local Sites/mediaverse/app/qa-artifacts/audit-2026-09-12/ (attached to cards 02, 03, 06).
