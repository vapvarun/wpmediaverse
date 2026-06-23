# WPMediaVerse — Functionality to Journey Coverage Map

This document is the coverage backbone for the pre-release smoke runbook (`AGENT_SMOKE_RUNBOOK.md`). Every customer-reachable functionality in both Free and Pro must have a row here, and every row must map to a runbook journey (section C/D/E) that would catch a regression in that feature. A feature whose row says `GAP — none` or `SURFACE-ONLY` is unprotected: a customer can hit a broken flow that no smoke pass would catch. This is a living document — update it in the same PR that adds or changes a feature.

Two universal gates apply when judging coverage:
1. **No silent action** — every toggle/submit/save shows pending then success or error.
2. **No dead-end** — anything created/saved/configured has a reachable view whose count/preview reflects the change.

A journey that only checks a REST contract or a surface render (200 status, row count) but does NOT verify the user-visible feedback AND the downstream view is marked `SURFACE-ONLY`.

Sources: `qa/inventory/WHAT-TO-CHECK.md`, `audit/manifests/manifest.summary.json`, `audit/pro/manifests/manifest.summary.json`, `CLAUDE.md`, `../wpmediaverse-pro/CLAUDE.md`, `qa/runbooks/AGENT_SMOKE_RUNBOOK.md`.

---

## Actor 1 — Anonymous Visitor (logged-out)

| # | Functionality | What the actor expects (end to end) | Existing coverage |
|---|---------------|--------------------------------------|-------------------|
| A1 | Browse the Explore feed | Visitor lands on `/media/`, sees real thumbnails with metadata, advances pages, sees only public media | `C.anon.explore-feed` |
| A2 | Search the Explore feed (results found) | Visitor types in the Explore search box, sees autocomplete suggestions after 250ms debounce, navigates with keyboard (ArrowDown/Up/Enter/ESC), selects a result, lands on filtered feed | `C.anon.explore-feed` — partial: feed covered but autocomplete keyboard nav and suggestion render only implied, not walk-step explicit. `SURFACE-ONLY` |
| A3 | Search the Explore feed (zero results) | Visitor searches for a nonexistent term, sees a friendly empty state naming the term, a "Browse all media" button, and popular-tag chips — not a generic blank | `C.anon.search-empty-state` + `D.search-empty-state` |
| A4 | Browse by tag | Visitor clicks a tag chip, lands on `/media/?mvs_tag=<tag>` with filtered results and a clear-filter link; unknown tag produces a clean empty state (not a fatal) | `C.anon.tag` |
| A5 | View a single media item | Visitor opens `/media/<slug>/`, sees the image (signed-URL streams 200), title, description, tags, owner, social meta in `<head>` (og:image, og:title, twitter:card); gated actions redirect to login cleanly | `C.anon.single-media` |
| A6 | View a user profile | Visitor opens `/media/@<user>/`, sees header, media grid, pagination at `/page/2/`; private fields (email, drafts) do not appear in HTML | `C.anon.user-profile` |
| A7 | View a public album | Visitor opens `/album/<slug>/`, sees album cover + items. A private album shows a lock state, not the items. | `C.anon.album-collection` |
| A8 | View a public collection | Visitor opens `/collection/<slug>/`, sees items from smart rules; when rules evaluate to zero, sees "no items match rules" — not blank | `C.anon.album-collection` |
| A9 | Hit the dashboard as anon | Visitor opens `/my-media/`, sees a styled "Log in" CTA with `redirect_to` round-trip — not a plain sentence | `C.anon.dashboard-gate` + `D.dashboard-anon-gate` |
| A10 | View BP member media tab | Visitor opens `/members/<user>/media/` — thumbnails are real image URLs (not the page URL) from the uploads directory | `C.bp-integration` + `D.bp-thumbnail-leak` |
| A11 | View BP group media tab | Visitor opens `/groups/<slug>/media/` — group media renders, empty state ("no media in this group") for empty group | `C.bp-integration` — group tab listed but no distinct empty-state assertion. `SURFACE-ONLY` |
| A12 | OG / Twitter Card meta on single media | Visitor shares `/media/<slug>/` link to a social platform, the platform's crawler sees `og:title`, `og:image`, `og:description`, `twitter:card` in `<head>` | `C.anon.single-media` |
| A13 | View Compete hub (Pro) | Anonymous visitor lands on `/compete/`, sees three cards (Battles/Challenges/Tournaments) with live counts and a "log in to participate" CTA | `E.compete-hub` |
| A14 | View battles list (Pro, feature on) | Visitor opens `/media/battles/`, sees active battles or a "no active battles" + create-battle CTA | `E.battles` — listing page render and empty state not separately asserted. `SURFACE-ONLY` |
| A15 | View challenges list (Pro, feature on) | Visitor opens `/media/challenges/`, sees active challenges or "no active challenges" empty state | `E.challenges` — listing page render and empty state not separately asserted. `SURFACE-ONLY` |
| A16 | View tournaments list (Pro, feature on) | Visitor opens `/media/tournaments/`, sees brackets in registration or "no tournaments in registration" empty state | `E.tournaments` — listing page render and empty state not separately asserted. `SURFACE-ONLY` |
| A17 | Feature-off pages absent (Pro) | When a Pro feature toggle is OFF, its frontend page (`/compete/`, `/media/battles/`, etc.) does not exist or returns a clean 404/redirect — no console errors | `E.feature-toggle-degradation` |
| A18 | Rewrite rules active on fresh install | After a clean activation, all plugin URLs (`/media/`, `/album/`, `/collection/`, `/compete/`, etc.) return 200 on the first request without a manual permalink flush | `A3` + `D.rewrite-flush` |

---

## Actor 2 — Member (logged-in end user)

| # | Functionality | What the actor expects (end to end) | Existing coverage |
|---|---------------|--------------------------------------|-------------------|
| M1 | Upload a single photo (public) | Member opens the upload modal, picks a file, chooses public privacy, submits. Media appears in `/media/`, in `/my-media/`, in the member's profile, and (BP active) in `/members/<user>/media/`. All 3 thumb sizes generated. | `C.member.upload-public` |
| M2 | Upload a gallery (2-6 images) | Member uploads multiple images in one submission; one index row + meta group created, all thumbs generated; gallery appears as a unit on feed and profile | `C.member.upload-public` — gallery variant mentioned in WHAT-TO-CHECK §2 but no distinct gallery journey step. `SURFACE-ONLY` |
| M3 | Upload a video (with poster) | Member uploads a video; a poster is generated (ffmpeg path, getID3); thumbnail URL is non-empty in REST; grid tile shows the poster image | `C.member.video-poster-fallback` + `D.blank-video-poster` |
| M4 | Upload a video (no ffmpeg / posterless) | Member uploads a video with no ffmpeg and no embedded cover; REST `thumbnail_url` returns the bundled default poster SVG (never empty); grid tile renders an img | `C.member.video-poster-fallback` + `D.blank-video-poster` |
| M5 | Upload at privacy levels (public / members / friends / private) | Member uploads at each privacy level; the four viewer types (anon / non-friend / friend / author) each see or cannot see the media correctly; activity row `hide_sitewide` / `_mvs_activity_privacy` meta correct | `C.member.upload-privacy-matrix` + `D.privacy-fix-2026-05-07` |
| M6 | Private upload creates activity row | Member uploads with `privacy=private`; a row is written to `mvs_activity` (was previously skipped) | `D.private-media-activity-row` |
| M7 | Upload rejected over size limit | Member tries to upload a file over `mvs_max_upload_size`; receives a specific human-readable error; no DB row created | `C.member.upload-rejections` |
| M8 | Upload rejected for disallowed MIME | Member tries to upload a MIME not in `mvs_allowed_file_types`; receives a reason; no DB row created | `C.member.upload-rejections` |
| M9 | Upload with watermark (Pro) | Member uploads an image when `mvs_watermark_type` is configured; uploaded image carries the overlay at the configured position; original preserved separately | `E.watermarking` |
| M10 | Upload at quota cap (Pro) | Member tries to upload when at their quota cap; upload is blocked with an upgrade CTA (not a generic 500) | `E.quota` |
| M11 | Upload triggers AI moderation (Pro) | Member uploads content that trips the AI moderator; media is flagged automatically per `mvs_ai_auto_moderate` + `mvs_moderation_auto_action` | `E.ai-providers` |
| M12 | Upload triggers transcoding (Pro) | Member uploads a video when FFmpeg is configured; `_mvs_transcodes` meta populated with output URLs; `mvs_pro_transcode_complete` fires; player picks the right output | `E.video-intelligence` |
| M13 | Upload triggers caption generation (Pro) | Member uploads a video with Whisper configured; `_mvs_captions` meta populated (VTT/SRT URLs); `mvs_pro_captions_generated` fires; VTT renders inline on the player | `E.video-intelligence` |
| M14 | Edit own media (lightbox modal) | Owner sees an Edit cog on `/my-media/` cards ONLY (not on Explore/Album/Collection cards); clicking opens a modal pre-filled with title/description/privacy/allow_download; saving with empty title is gated; Save sends PUT and the card refreshes in place | `C.member.lightbox-edit-modal` |
| M15 | Edit media categories (persist through cache) | Member edits categories via PUT `/mvs/v1/media/{id}`; a subsequent GET shows the term name in `categories[]` even with a persistent object cache; an empty array clears; `wp_set_object_terms()` error returns 500 not 200 | `C.member.edit-categories-persist` + `D.categories-cache-miss-drop` |
| M16 | Edit media tags | Member edits tags via PUT `/mvs/v1/media/{id}` `{"tags":[...]}` and a GET confirms the tags persisted; OPTIONS on `/mvs/v1/media/{id}` declares `tags` in `args` | `C.member.edit-categories-persist` — tags covered as parallel to categories but no dedicated assertion for tags. `SURFACE-ONLY` |
| M17 | Delete own media | Member deletes their own media; rows in `mvs_media_index`, `mvs_media_meta`, `mvs_media_stats` removed; file removed from disk; `mvs_media_deleted` fires; media disappears from every listing | `C.member.delete-own` |
| M18 | Bulk trash / restore / delete own media | Member selects multiple media, chooses Move to Trash; rows flip to `status=trashed`; Restore brings them back; Delete permanently removes rows + meta + stats + file; zero-selection shows a friendly error | `C.member.bulk-trash-restore-delete` |
| M19 | View own media in dashboard (4 tabs) | Member visits `/my-media/`, sees 4 tabs (All / Albums / Collections / Favorites) with real content; each tab has its own distinct empty state | `GAP — none` (tabs rendered but no journey walks each tab's populated+empty state distinctly) |
| M20 | View quota usage widget (Pro) | Member visits `/my-media/` and sees a usage widget showing current vs cap; changing quota in admin reflects in the widget | `E.quota` — widget reflection not separately asserted in a member-side journey. `SURFACE-ONLY` |
| M21 | Open lightbox | Member clicks a thumbnail; lightbox opens with image, 6-reaction bar, toolbar; ESC closes; F toggles fullscreen | `C.member.lightbox` + `D.esc-close-lightbox` |
| M22 | Lightbox — react with emoji | Member clicks a reaction (Like/Love/Haha/Wow/Sad/Angry); count increments; `aria-pressed` flips; switching emoji replaces the row; clicking same emoji twice removes it | `C.member.reactions-favorites-comments` + `D.lightbox-reactions-a11y` |
| M23 | Lightbox — share media | Member clicks Share; `navigator.share` or `navigator.clipboard.writeText` fires; a toast confirms; `mvs_media_stats.shares` increments; `window.prompt` never appears | `C.member.lightbox` + `D.share-no-prompt-fallback` |
| M24 | Lightbox — download media | Member clicks Download; original asset downloads; `mvs_media_stats.downloads` increments once; rate limit (30/min/user) returns 429 on excess; Download hidden when global `mvs_allow_downloads` is off | `C.member.lightbox` — download hidden-when-off assertion and download stat increment not explicitly walk-stepped. `SURFACE-ONLY` |
| M25 | Lightbox — fullscreen | Member clicks Fullscreen or presses F; native Fullscreen API enters on the image panel; ESC or F exits; toolbar still operable in fullscreen | `C.member.lightbox` |
| M26 | React on feed / profile | Member reacts to media outside the lightbox (feed card or profile grid card); same toggle + replace + unreact semantics; counts update | `C.member.reactions-favorites-comments` — reactions outside lightbox asserted generically. `SURFACE-ONLY` |
| M27 | Favorite media | Member favorites a media; row in `mvs_favorites`; heart icon state reflects; Favorites tab in dashboard shows it | `C.member.reactions-favorites-comments` — favorites tab appearance after toggle not explicitly walked. `SURFACE-ONLY` |
| M28 | Comment on media | Member posts a comment; row in `wp_comments` scoped to media; comment appears immediately | `C.member.reactions-favorites-comments` |
| M29 | Edit own comment (within 15-min window) | Member edits their own comment within `mvs_comment_edit_window`; update persists; after the window, edit is blocked | `C.member.reactions-favorites-comments` — edit-window enforcement not walked explicitly. `SURFACE-ONLY` |
| M30 | Delete own comment (and child comments) | Member deletes their own comment; comment + child comments removed | `C.member.reactions-favorites-comments` — delete explicitly covered in WHAT-TO-CHECK but no journey walk step asserts it. `SURFACE-ONLY` |
| M31 | Follow a user | Member clicks Follow; row in `mvs_follows`; idempotent (second click is unfollow or no-op per UI); target gets a notification; `mvs_user_followed` fires once | `C.member.follow-mention` |
| M32 | Unfollow a user | Member unfollows; `mvs_follows` row removed; counts adjust | `C.member.follow-mention` — unfollow not explicitly asserted in the journey. `SURFACE-ONLY` |
| M33 | @mention in a comment | Member types `@username` in a comment; mention becomes a link in the rendered comment; target user gets a notification | `C.member.follow-mention` |
| M34 | Report media | Member clicks Report; `mvs_reports` row created; `mvs_report_submitted` fires; report appears in admin moderation queue | `C.admin.moderation-flow` — reporter-side (submit + confirmation) not walked from the member persona. `SURFACE-ONLY` |
| M35 | Block another user | Member blocks a user; row in `mvs_blocks`; blocked user's content hidden from the member's feeds; DMs from blocked user rejected | `GAP — none` (block action and its effects have no journey coverage) |
| M36 | View another member's profile | Member visits `/media/@<other>/`; public profile with grid + follower counts; "Follows you" badge visible when applicable | `C.anon.user-profile` — covers the page render but the "Follows you" badge and follower count accuracy not asserted. `SURFACE-ONLY` |
| M37 | Create an album | Member creates a new album; post in `wp_posts` type `mvs_album`; album appears in `/my-media/` Albums tab and has its own `/album/<slug>/` page | `GAP — none` (album creation journey not in runbook) |
| M38 | Add items to an album | Member adds media to an album; `mvs_album_items` row created; items appear on `/album/<slug>/` | `GAP — none` |
| M39 | Reorder album items | Member drags items in the album editor; `mvs_album_items.sort_order` updated atomically with no gaps; reloading reflects the new order | `GAP — none` |
| M40 | Set album cover | Member sets a cover for the album; cover meta updated; album page and album card in dashboard both reflect the change immediately | `GAP — none` |
| M41 | Set album privacy (public / members / private) | Member changes album privacy; visitors in each viewer category see or are blocked correctly; private shows a lock state to anon | `C.anon.album-collection` — member-side setting of album privacy not walked. `SURFACE-ONLY` |
| M42 | View own collections | Member views the Collections tab in `/my-media/`; each collection has a cover, title, and item count | `GAP — none` (no collections-tab populated journey) |
| M43 | Create a manual collection (Free) | Member creates a collection (post type `mvs_collection`); collection appears in `/my-media/` Collections tab and has its own `/collection/<slug>/` page | `GAP — none` |
| M44 | Save media into multiple collections (Pro picker) | Member opens the "Save to collection" picker on a media item; picks one or more of their Free collections; `mvs_pro_collection_items` row(s) created; re-opening the picker shows the saved state; removing a collection clears the row; duplicate insert deduped by UNIQUE KEY | `E.multi-collection-save` |
| M45 | Grant access to private media | Member configures access rules for a private media item; granted user can view it; non-granted user is still blocked | `GAP — none` (access rules + grants have no member-side journey) |
| M46 | View signed URL for private media | Member with access views private media via a `/serve` endpoint; valid token returns 200 + correct Content-Type; expired token returns 403; tampered token returns 403; logged-out + no token returns 403 | `C.member.signed-url` |
| M47 | Public media cacheable on local driver | Member's public media URLs are stable (two calls return identical signed URL); serve endpoint returns `Cache-Control: public, max-age=604800`; private media returns `no-store` | `C.member.public-media-cacheable` + `D.public-media-cacheable-local` |
| M48 | Send a DM (text) | Member opens `/messages/`, starts a conversation, sends a text; message appears in the conversation list for both parties; `mvs_message_sent` fires once | `C.member.dm-send-receive` |
| M49 | Send a DM (media attachment) | Member attaches a media item to a DM; message row + media reference stored; attachment renders in the conversation | `C.member.dm-send-receive` — media attachment variant not walk-stepped explicitly. `SURFACE-ONLY` |
| M50 | DM read receipts | Recipient opens a conversation; `mvs_conversation_participants.last_read_at` updates; `mvs_conversation_read` fires | `C.member.dm-send-receive` — read receipt update not walk-stepped explicitly. `SURFACE-ONLY` |
| M51 | DM access control (`mvs_dm_access`) | Member tries to DM a non-follower when `mvs_dm_access` blocks it; DM is rejected with a message | `C.member.dm-send-receive` |
| M52 | DM minimum account age (`mvs_dm_min_age`) | Member account newer than the threshold tries to send a DM; request is rejected | `C.member.dm-send-receive` |
| M53 | Block user stops DMs | Member blocks another user; subsequent DMs from the blocked user are rejected | `C.member.dm-send-receive` — block-stops-DM tested in the DM journey. |
| M54 | Group DM create/send/read (Pro) | Member creates a group DM with 3 others; group conversation in `mvs_conversations` + 4 participant rows; messages post and appear for members; non-member call returns 403 | `E.group-dm` |
| M55 | BP activity composer — attach media | Member on a BP site sees "Attach media" button with visible label "Attach media" and Lucide image-plus icon at 18px (not icon-only bare box); button and privacy select aligned on same row with 0px yDelta | `C.member.activity-composer-attach` + `D.activity-button-icon-only` + `D.activity-privacy-alignment` |
| M56 | BP activity composer — 1-image preview | Member attaches 1 image; preview tile is 120-150px wide, 1:1 aspect, max-height 150px (not 200-320px hero) | `C.member.activity-preview` + `D.activity-preview-hero-regression` |
| M57 | BP activity composer — 2-6 image preview | Member attaches 2-6 images; grid uses per-count CSS Grid templates; collapses to 2col at ≤640px | `C.member.activity-preview` |
| M58 | Streak badge renders on display name | Member's display name shows streak badge with both `title` AND `aria-label` with identical copy; `wp_kses` allowlists permit `aria-label` on `span` in all 5 render paths | `C.member.streak-badge` + `D.streak-badge-aria` |
| M59 | Daily upload streak tracking (Pro) | Member uploads daily; `_mvs_current_streak` user meta increments; streak badge reflects; missing a day resets streak unless freeze covers the gap | `E.streaks` |
| M60 | Buy a streak freeze (Pro) | Member purchases a streak freeze via the UI; points deducted atomically via `PointsEngine::debit()`; `_mvs_streak_freezes` incremented; insufficient points returns 400 | `E.streaks.freeze-proportional-cost` |
| M61 | Streak freeze proportional cost (Pro) | A member with 1 freeze who skips 5 days (gap > freezes) loses the streak; member with 3 freezes who skips 3 days (gap <= freezes) preserves the streak and debits 3 freezes | `E.streaks.freeze-proportional-cost` |
| M62 | Boost media (Pro) | Member spends gamification points to boost a media item; `mvs_boosts` row created; boosted item rises in feed ranking; expired boosts cleaned by `mvs_expire_boosts` cron | `E.boosts` |
| M63 | Enter a challenge (Pro) | Member enters an active challenge; `mvs_competition_entries` row created; `mvs_challenge_entry_submitted` fires; entry appears on the challenge page | `E.challenges` |
| M64 | Vote on a challenge entry (Pro) | Member votes on an entry during the voting period; `mvs_competition_votes` row created (unique per voter + entry); vote count increments visibly | `E.challenges` — voting period and vote count display not walk-stepped explicitly. `SURFACE-ONLY` |
| M65 | Start a 1v1 battle (Pro) | Member starts a battle; opponent notified; both can submit entries; `mvs_battle_created` fires; battle appears in admin Battle Monitor | `E.battles` |
| M66 | Register for a tournament (Pro) | Member registers; `mvs_competition_entries` row created; bracket auto-generates when capacity is hit; `mvs_tournament_created` fires; `/media/tournaments/<slug>/` shows the bracket | `E.tournaments` |
| M67 | Video chapters and resume playback (Pro) | Member watches a video with chapters; `ChapterService` serves chapter data; resume playback restores position on return | `GAP — none` (video chapters / resume playback have no journey in the runbook) |
| M68 | Video play analytics (Pro) | Member's play events are recorded; heatmaps accumulate in `AnalyticsService`; video analytics tab in admin reflects the play | `GAP — none` (video play analytics have no member-side or admin-side journey) |
| M69 | Connect Flickr account (Pro) | Member connects their Flickr account via OAuth; `ConnectorManager` stores the token; Flickr feed appears in the layout; disconnecting clears the validation transient | `GAP — none` (Flickr connector flow has no journey in the runbook) |
| M70 | Instagram feed layout (Pro) | Member or admin enables the Instagram layout; `InstagramFeedService` / `InstagramLayout` renders a connected feed; per-layout CSS enqueued; empty state when not connected | `E.instagram-feed` — OAuth flow and feed populate not walk-stepped. `SURFACE-ONLY` |
| M71 | View notifications bell | Member sees notification badge in the bell; clicking the bell lists notifications; each links to the correct destination; `mvs_notifications` row for each event | `C.notifications` |
| M72 | Notification types: follow, reaction, comment, mention | Following, reacting, commenting, and mentioning all create a `mvs_notifications` row for the correct recipient and send a readable notification | `C.notifications` + `C.notifications.hook-contract` |
| M73 | Notification hook contract (`mvs_notification_created`) | Hook fires with 7 args; arg[5] is a non-empty message string, arg[6] is a valid URL; 5-arg listener still fires without a PHP warning | `C.notifications.hook-contract` + `D.notification-hook-message-link` |
| M74 | Email notifications (digest / instant / unsubscribe) | Member receives email for notification events per their preferences; digest vs instant mode respected; unsubscribe link works | `C.notifications.email` |
| M75 | BP notification dedup | When BP is active, the BP nav bell renders MVS notifications; the dashboard `.mvs-notification-bell` is suppressed (no double-render) | `C.notifications` |
| M76 | Grid thumbnail size respects setting | Grid pages and REST responses use the `medium` thumbnail variant by default (post-1.7.0); changing the setting to `large` makes all 5 render paths serve large variants; lightbox still uses full/large | `C.member.grid-thumbnail-size` + `D.grid-thumb-size-default` |
| M77 | Grid render query budget (N+1 fix) | A 12+ tile grid page costs ≤6 DB queries (not ~170); `MediaRepository::prefetch()` and `AccessRulesService::prefetch_active_rules()` called before every loop across all 5 paths; access control not regressed | `C.member.grid-render-query-budget` + `D.grid-render-n-plus-1` |
| M78 | Upload popular tag pill in modal | Member types in the upload modal; top-8 popular-tag pills render; clicking a pill appends the tag to the input with no duplicates | `GAP — none` (upload modal tag pill interaction has no runbook journey step) |
| M79 | Advanced privacy controls UI (Pro) | Member sees access-rules UI on their media settings; can set who can view (access rules + grants); `PrivacyController` persists and gates `PrivacyService::can_view()` correctly | `E.privacy-pro-ui` |
| M80 | Image optimization — upload-time WebP/AVIF | Member uploads a JPEG/PNG; WebP sibling is generated (and AVIF if configured); `<picture>` element serves the right format in the grid | `GAP — none` (image optimization output and WebP/AVIF serving have no journey) |
| M81 | Edit own profile | Member visits `/media/edit-profile/`; form is visible and pre-filled; saving updates profile data; logged-out visitor is redirected/gated | `GAP — none` (profile edit form journey is missing from runbook) |
| M82 | Online status dot | Member's online status dot is shown or hidden per `mvs_show_online_status` site setting and per-user `_mvs_show_online` override | `GAP — none` (online status display has no journey) |

---

## Actor 3 — Site Owner / Admin

| # | Functionality | What the actor expects (end to end) | Existing coverage |
|---|---------------|--------------------------------------|-------------------|
| O1 | Plugin activation (Free) | Admin activates WPMediaVerse; all 21 `mvs_*` tables created; `mvs_db_version` set; admin menu renders; no PHP fatal in debug.log | `A1` |
| O2 | Plugin activation (Pro on top of Free) | Admin activates Pro; Pro tables created; Pro admin pages appear; deactivating Free disables Pro with a clear admin notice; reactivating Free re-enables Pro | `A2` |
| O3 | First-request routing without permalink flush | After activation, all plugin URLs return 200 on the first request | `A3` + `D.rewrite-flush` |
| O4 | Pages auto-created on activation | Pro activation creates `/compete/` page once; re-activation does not duplicate | `A4` |
| O5 | Default settings sensible | Out-of-box, `mvs_default_privacy=public`, `mvs_signed_url_ttl` non-zero, chat panel visibility set to a documented value | `A5` |
| O6 | Upgrade migration runs quietly | Upgrading from the prior version completes with no `from`-origin debug.log entries; pre-existing media, albums, collections, competitions still render and function | `B1` |
| O7 | Pro option-prefix migration idempotent | Re-running the 1.2.0 → 1.x option migration does not corrupt data; running it twice is a no-op | `B2` |
| O8 | Schema additions back-fill correctly | New columns or tables from this release are back-filled for existing rows per migration contract; no NULL where code expects a value | `B3` |
| O9 | Admin Overview renders | Admin visits the WPMediaVerse overview page; stat cards show real numbers; seeded-zero state shows guidance | `C.admin.plugin-pages` |
| O10 | Admin Settings (all 8 Free tabs) | Each of the 8 Free settings tabs renders without PHP notice/warning/fatal; content loads without AJAX errors | `C.admin.plugin-pages` |
| O11 | Admin Settings save wires through to frontend | Every setting key in WHAT-TO-CHECK §3 has a real reader; saving each setting changes the behavior it claims; dead-weight settings (no reader) are flagged | `C.admin.settings-readers` |
| O12 | `mvs_max_upload_size` wire-through | Saving a new value causes uploads over the limit to be rejected | `C.admin.settings-readers` |
| O13 | `mvs_allowed_file_types` wire-through | Saving disallowed types causes MIME-blocked upload rejections | `C.admin.settings-readers` |
| O14 | `mvs_default_privacy` wire-through | New uploads inherit the configured default privacy | `C.admin.settings-readers` |
| O15 | `mvs_grid_columns` wire-through | `/media/` renders with the configured column count | `C.admin.settings-readers` |
| O16 | `mvs_items_per_page` wire-through | Feed pagination window matches the configured value | `C.admin.settings-readers` |
| O17 | `mvs_thumbnail_size` wire-through (default medium) | After 1.7.0, the default is `medium`; grids serve medium-variant URLs; changing to `large` updates all 5 render paths | `C.member.grid-thumbnail-size` + `D.grid-thumb-size-default` |
| O18 | `mvs_thumbnail_style` wire-through | Square vs original thumbnail shape honored in grid | `C.admin.settings-readers` — no dedicated walk step verifying this shape visually. `SURFACE-ONLY` |
| O19 | `mvs_signed_url_ttl` wire-through | Private media signed URLs expire after the configured TTL; public media on local driver gets a stable URL + long cache header | `C.member.signed-url` + `C.member.public-media-cacheable` |
| O20 | `mvs_chat_panel_visibility` wire-through | `everywhere` / `mvs_pages` / `bp_pages` / `disabled` modes each produce the correct presence/absence of `.mvs-chat-panel` in markup | `C.admin.settings-readers` — four modes not individually walk-stepped. `SURFACE-ONLY` |
| O21 | `mvs_allow_downloads` wire-through | When off, the lightbox Download button is hidden everywhere and `record_download` REST returns 403 | `C.member.lightbox` — global-off gate tested; see M24 note. `SURFACE-ONLY` |
| O22 | `mvs_dm_access` + `mvs_dm_min_age` wire-through | DM access blocked for non-followers / new accounts per the setting | `C.member.dm-send-receive` |
| O23 | `mvs_show_online_status` wire-through | Online dot shown or hidden per setting | `GAP — none` (no walk step verifies the dot is absent when the setting is off) |
| O24 | `mvs_comment_edit_window` wire-through | Edit window enforced; after the window, editing a comment is blocked | `C.member.reactions-favorites-comments` — window enforcement not walk-stepped. `SURFACE-ONLY` |
| O25 | `mvs_duplicate_action` wire-through | Warn / skip / allow behavior honored on duplicate uploads | `GAP — none` |
| O26 | `mvs_strip_exif` wire-through | EXIF removed from uploaded files when the setting is on | `GAP — none` |
| O27 | `mvs_ai_provider` + API key wire-through | AI moderation uses the configured provider; `google_vision` vs `openai` vs `aws` | `E.ai-providers` — provider switching not walk-stepped. `SURFACE-ONLY` |
| O28 | `mvs_ai_auto_moderate` wire-through | Uploads trigger moderation automatically when enabled | `E.ai-providers` |
| O29 | `mvs_moderation_auto_action` wire-through | Flagged media gets the configured auto-action (hide / trash / approve) | `E.ai-providers` — specific auto-action variants not walked. `SURFACE-ONLY` |
| O30 | `mvs_watermark_type` + text + position wire-through | Uploaded images carry the watermark at the configured position | `E.watermarking` |
| O31 | `mvs_page_explore` / `mvs_page_upload` / `mvs_page_dashboard` wire-through | Plugin links target the configured pages | `C.admin.settings-readers` — page links not explicitly walk-verified. `SURFACE-ONLY` |
| O32 | Feature toggles (battles / challenges / tournaments / boosts / streaks) | Toggling each OFF removes admin menu items, frontend pages, and scheduled actions without breaking surrounding surfaces; toggling ON restores cleanly | `E.feature-toggle-degradation` |
| O33 | Admin Moderation Queue renders | Admin sees flagged items with reporters' reasons; can approve / trash / mark spam; action disappears from source tab and appears in destination tab | `C.admin.moderation-flow` |
| O34 | Admin Moderation — User Reports tab (Pro) | Pro adds a "User Reports" tab to the moderation page with pending count badge; user-level actions appear there | `C.admin.plugin-pages` — Pro User Reports tab render; action handling not walk-stepped separately. `SURFACE-ONLY` |
| O35 | Admin Stats renders (Free) | Stats page with charts renders; no-data placeholders instead of blank canvas | `C.admin.plugin-pages` |
| O36 | Admin Stats — Video Analytics tab (Pro) | Pro adds a "Video Analytics" tab to the Stats page; video play data renders | `C.admin.plugin-pages` — tab render, not data accuracy. `SURFACE-ONLY` |
| O37 | Admin Logs renders | Log viewer page renders without fatal | `C.admin.plugin-pages` |
| O38 | Admin All Media — table + filters + pagination | Admin sees all media with filters (status, type) and pagination; filtered-empty vs truly-empty states distinct | `C.admin.plugin-pages` |
| O39 | Admin All Media — bulk trash / restore / delete | Admin bulk-selects, trashes, restores, and permanently deletes; rows/files removed; success notice with count; zero-selection shows friendly error | `C.member.bulk-trash-restore-delete` (admin persona) + `D` (bulk actions asserted) |
| O40 | Admin All Media — image optimization columns | Optimization column on All Media shows `-X.X% / WebP ready / No lossless gain` badges; per-row Optimize + Details row actions work | `GAP — none` (optimization columns and row actions have no journey) |
| O41 | Admin Setup Wizard renders | Setup Wizard page renders without fatal; completes its steps | `C.admin.plugin-pages` — walk just confirms no fatal; full wizard flow not stepped. `SURFACE-ONLY` |
| O42 | Admin Pro Competitions Dashboard | Active competition counts + quick links render | `C.admin.plugin-pages` |
| O43 | Admin Pro Challenge Manager | Admin creates a challenge with start/end dates, entry rules, prize tiers; challenge appears on `/media/challenges/` | `E.challenges` |
| O44 | Admin Pro Tournament Manager | Admin creates a tournament with capacity; bracket auto-generates on capacity; admin can manually resolve matches | `E.tournaments` + `E.tournaments.sparse-bracket` |
| O45 | Admin Pro Battle Monitor | Admin sees active battles with status and quick links | `E.battles` + `C.admin.plugin-pages` — Monitor render covered; no admin-side resolve action walked. `SURFACE-ONLY` |
| O46 | Admin Pro Quota & Credits | Admin configures a quota package; package appears in list; credit log shows history; member at cap is blocked from uploading | `E.quota` + `C.admin.plugin-pages` |
| O47 | Admin Pro Theme Library | Themes grid renders; default themes seeded on activation | `C.admin.plugin-pages` — theme selection and its frontend effect not walked. `SURFACE-ONLY` |
| O48 | Admin Pro Migration Tool (rtMedia / MediaPress / BuddyBoss) | Admin sees migration tool; detects source data; dry run reports counts; actual run imports via `UploadService::handle()` (not direct DB); dedup prevents re-import | `E.migration-importers` |
| O49 | Admin Pro Gamification Settings | Admin configures XP values (challenge ranks, tournament, battle win XP, streak freeze cost); changes persist | `E.gamification.configured-xp` — admin save and persist asserted. |
| O50 | Admin Pro Storage Management UI | Admin sees "Move next 20" + "Delete next 20" per driver; AJAX migration runs in chunks with progress bar; only public media is cloud-eligible | `E.cloud-storage` + `D.cloud-privacy-gate` |
| O51 | Admin Pro AI settings — provider + key | Admin sets `mvs_pro_google_vision_key` / `mvs_pro_aws_*`; cloud vision provider becomes reachable | `E.ai-providers` — admin-save to provider-reachable not explicitly stepped. `SURFACE-ONLY` |
| O52 | Admin Pro S3 / BunnyCDN / R2 / DO Spaces credentials | Admin enters storage credentials; Test Connection succeeds; upload lands in the bucket; public URL served from CDN | `E.cloud-storage` |
| O53 | Admin Pro License page | License page renders; active/inactive badge shows correctly; `is_valid()` does NOT gate any feature (updates-channel only) | `C.admin.plugin-pages` — license-gates-nothing audit check not stepped. `SURFACE-ONLY` |
| O54 | Admin Pro FFmpeg path setting | Admin sets `mvs_pro_ffmpeg_path`; transcoding uses that binary; Site Health test `wpmediaverse_video_posters` reports ffmpeg availability correctly | `E.video-intelligence` + `C.member.video-poster-fallback` |
| O55 | WP-CLI commands (Free 17 subcommands) | `wp mvs <subcommand>` exposes all documented subcommands per `MVS_CLI_COMMANDS` registry | `C.admin.bulk-and-cli` |
| O56 | WP-CLI `wp mvs migrate-storage` (Pro) | Migrates public media to cloud; non-public rows skipped by WHERE clause; `mvs_cloudops_allow_non_public_to_cloud` filter controls the exception | `C.admin.bulk-and-cli` + `D.cloud-privacy-gate` |
| O57 | WP-CLI `wp mvs cleanup-local` (Pro) | Removes local files for already-cloud-hosted public media; non-public media not touched | `C.admin.bulk-and-cli` + `D.cloud-privacy-gate` |
| O58 | WP-CLI `wp mvs backfill_ai` | AI backfill CLI subcommand runs and processes media without fatal | `C.admin.bulk-and-cli` |
| O59 | WP-CLI importers (rtMedia / MediaPress / BuddyBoss) | `wp mvs import --source=<platform>` batched import with dedup via `mvs_media_meta` import key | `E.migration-importers` |
| O60 | Cron — all events scheduled on activation | Every expected cron event is scheduled after activation; none are orphaned after deactivation; cron executes on manual trigger | `C.cron` |
| O61 | Site Health test `wpmediaverse_video_posters` | Appears in WP Site Health; reports ffmpeg availability; non-blank status string | `C.member.video-poster-fallback` + `D.blank-video-poster` |
| O62 | Competition cron sweeps bounded (Pro) | Each of the 5 competition cron queries is bounded to LIMIT 50 per tick; 200-row seed does not exhaust memory or time out | `E.competitions.cron-bounded` |
| O63 | Streak daily-check keyset-paginated (Pro) | `StreakService::daily_check()` is keyset-paginated (100/batch, 2000/run) with async continuation; >2000 streak users are all processed without timeout | `E.streaks.daily-check-bounded` |
| O64 | Battle win XP configured (Pro) | `mvs_pro_battle_win_xp` option honored; battle stores snapshot at creation; winner earns the configured amount | `E.battles.win-xp-configured` |
| O65 | Challenge XP configured (Pro) | Challenge `xp_1st/2nd/3rd/participation` values honored; finalize fires the right amounts per rank | `E.gamification.configured-xp` |
| O66 | Tournament XP configured (Pro) | Tournament `xp_round_win` / `xp_tournament_win` honored; per-match and per-finalize correct | `E.gamification.configured-xp` |
| O67 | Competition winners notified (Pro) | Challenge: top-3 notified; 1-entry challenge: only 1 winner notification fires. Tournament: eliminated player notified; champion notified. Battle: both winner and loser notified | `E.gamification.winners-notified` |
| O68 | Tournament sparse bracket (Pro) | `bracket_size=16` with 3 registrants: no fatal; exactly 1 real match; no both-null rows; status transitions to `active`; admin manual resolve sets winner and notifies loser | `E.tournaments.sparse-bracket` |
| O69 | Membership quota adapters (Pro) | MemberPress / PMPro / WooCommerce membership changes propagate to `QuotaService` via the matching `QuotaAdapter` | `E.quota` — adapter wire-through not explicitly asserted in a journey. `SURFACE-ONLY` |
| O70 | Blocks — all 9 Free blocks render (populated + empty) | All `mvs/*` blocks render populated AND empty on a test page without bare `return;` | `C.blocks` |
| O71 | Blocks — all 12 Pro blocks render (populated + empty) | All `mvs-pro/*` blocks render populated AND empty; per-layout CSS enqueued from `render.php` per Coding Rule 6 | `C.blocks` + `D.pro-block-layout-enqueue` |
| O72 | Shortcodes — all 12 Free shortcodes render | All `[mvs_*]` shortcodes render on a test page (populated + empty state), no bare `return;` | `C.shortcodes` |
| O73 | Pro feed layouts (Instagram / Flickr / Pinterest / Dribbble) | Each of the 4 layout modes renders `/media/` with the correct template; per-layout CSS enqueued; invalid slug falls back to grid (not silent failure) | `E.instagram-feed` + `D.pro-feed-layout-fallback` + `D.pro-block-layout-enqueue` |
| O74 | Cloud storage — file lands in bucket (S3 / BunnyCDN) | Admin configures S3 or BunnyCDN; uploads a public image; file lands in bucket; public URL returned; `mvs_cloud_thumbnail_url` filter applied | `E.cloud-storage` |
| O75 | Cloud exists() Range-GET (BunnyCDN) | BunnyCDN `exists()` uses Range-GET (not HEAD) on a known-uploaded key and returns `true` | `D.cloud-existence-head-vs-range` |
| O76 | S3 key encoding (non-AWS endpoints) | Upload a key with slashes (e.g. `2026/05/photo.jpg`); SigV4 PUT preserves slashes in canonical URI; works against R2/MinIO/B2 | `D.s3-key-encoding` |
| O77 | Cross-browser: RTL locale | On an RTL locale (ar), primary surfaces render right-to-left without horizontal overflow; icons mirror/stay correctly | `F.rtl` |
| O78 | Accessibility: keyboard + ARIA across surfaces | Tab order logical; focus ring visible; icon-only buttons have `aria-label`; lightbox reactions fully wired per D.lightbox-reactions-a11y; mobile tabs keep active tab in view | `F.a11y` + `D.lightbox-reactions-a11y` |
| O79 | Cross-browser: Firefox Desktop + Safari iOS | Upload modal file picker, lightbox swipe/reaction tap, privacy select, Share native sheet, clipboard.writeText all work on respective engines | `F.firefox-desktop` + `F.safari-ios` |
| O80 | `shared-ui-shell.css` renamed to `shared-ui-frame.css` | Zero references to `shared-ui-shell.css` in codebase; chat panel and lightbox load `shared-ui-frame.css` | `D.shared-ui-shell-rename` |
| O81 | BP CSS file ownership | BP-only selectors only in `bp-integration.css`; `frontend.css` has none; all BP integrations enqueue both handles | `C.bp-integration` + `D.bp-css-ownership` |
| O82 | Frontend assets on every plugin page | `mvs-frontend` CSS + `mvs-lucide` JS enqueued on every page that uses plugin markup (incl. 404 + BP activity composer) | `C.bp-integration` + `D.frontend-asset-bleed` |
| O83 | i18n — textdomain loaded at right time | No "Function _load_textdomain_just_in_time was called incorrectly" notice in debug.log on WP 6.7+ | `D.i18n-textdomain-too-early` |
| O84 | Script-module i18n (`@wordpress/i18n` shim) | Pages using script-module blocks: `window.wp.i18n.__` shim present; strings translated | `D.script-module-i18n` |

---

## GAPS & WEAK COVERAGE

Functionalities whose status is `GAP — none` or `SURFACE-ONLY`. These are the flows where customers will hit undetected regressions. Listed by actor; each entry names the row number above.

> **Burn-down progress (2026-06):** all 19 `GAP — none` items now have journeys. Promoted into
> `AGENT_SMOKE_RUNBOOK.md`: M37-M41 (`C.member.albums-lifecycle`), M42-M43 (`E.collections-lifecycle`).
> Drafted in `JOURNEY-BACKLOG-AND-FINDINGS.md` (ready to promote): M19, M35, M45, M67, M68, M69, M78,
> M80, M81, M82, O23, O25, O26, O40. The burn-down produced **2 confirmed bugs** — F1 followers
> online-status leak (FIXED) and F2 block-user has no frontend UI (open) — plus a 6-item VERIFY
> backlog (F3-F8). M45 reclassified Free->Pro. SURFACE-ONLY upgrades are the next batch.

### Anonymous Visitor — Gaps

| # | Functionality | Status |
|---|---------------|--------|
| A2 | Explore autocomplete — keyboard nav + suggestion render | `SURFACE-ONLY` |
| A11 | BP group media tab — empty state | `SURFACE-ONLY` |
| A14 | Battles listing page — render + empty state | `SURFACE-ONLY` |
| A15 | Challenges listing page — render + empty state | `SURFACE-ONLY` |
| A16 | Tournaments listing page — render + empty state | `SURFACE-ONLY` |

### Member — Gaps

| # | Functionality | Status |
|---|---------------|--------|
| M2 | Upload a gallery (2-6 images) as a distinct journey | `SURFACE-ONLY` |
| M16 | Edit media tags (separate from categories) | `SURFACE-ONLY` |
| M19 | Dashboard `/my-media/` — all 4 tabs populated + empty states | `GAP — none` |
| M20 | Quota usage widget reflects admin changes (member view) | `SURFACE-ONLY` |
| M24 | Lightbox download — stat increment + hidden-when-off | `SURFACE-ONLY` |
| M26 | React on feed / profile card (outside lightbox) | `SURFACE-ONLY` |
| M27 | Favorite media + Favorites tab reflects the change | `SURFACE-ONLY` |
| M29 | Edit own comment — window enforcement | `SURFACE-ONLY` |
| M30 | Delete own comment | `SURFACE-ONLY` |
| M32 | Unfollow a user | `SURFACE-ONLY` |
| M34 | Report media — reporter-side feedback | `SURFACE-ONLY` |
| M35 | Block another user (block + content-hidden + DM-blocked) | `GAP — none` |
| M36 | "Follows you" badge and follower count accuracy | `SURFACE-ONLY` |
| M37 | Create an album | COVERED — `C.member.albums-lifecycle` |
| M38 | Add items to an album | COVERED — `C.member.albums-lifecycle` |
| M39 | Reorder album items | COVERED — `C.member.albums-lifecycle` |
| M40 | Set album cover | COVERED — `C.member.albums-lifecycle` |
| M41 | Set album privacy (member-side action) | COVERED — `C.member.albums-lifecycle` |
| M42 | View own collections (Collections tab) | COVERED — `E.collections-lifecycle` |
| M43 | Create a manual collection | COVERED — `E.collections-lifecycle` |
| M45 | Grant access to private media | `GAP — none` |
| M49 | DM with media attachment | `SURFACE-ONLY` |
| M50 | DM read receipts | `SURFACE-ONLY` |
| M64 | Vote on a challenge entry | `SURFACE-ONLY` |
| M67 | Video chapters and resume playback | `GAP — none` |
| M68 | Video play analytics (member and admin) | `GAP — none` |
| M69 | Connect Flickr account (OAuth flow) | `GAP — none` |
| M70 | Instagram feed layout — OAuth + feed populate | `SURFACE-ONLY` |
| M78 | Upload popular tag pill interaction | `GAP — none` |
| M79 | Advanced privacy controls UI (Pro) — access-rule effects | `SURFACE-ONLY` |
| M80 | Image optimization — WebP/AVIF output + `<picture>` serving | `GAP — none` |
| M81 | Edit own profile (edit-profile page, logged-out gate) | `GAP — none` |
| M82 | Online status dot shown/hidden per setting | `GAP — none` |

### Admin / Site Owner — Gaps

| # | Functionality | Status |
|---|---------------|--------|
| O18 | `mvs_thumbnail_style` wire-through (shape visible) | `SURFACE-ONLY` |
| O20 | `mvs_chat_panel_visibility` — all 4 modes | `SURFACE-ONLY` |
| O21 | `mvs_allow_downloads` global-off hides Download + 403 | `SURFACE-ONLY` |
| O23 | `mvs_show_online_status` wire-through | `GAP — none` |
| O24 | `mvs_comment_edit_window` enforcement | `SURFACE-ONLY` |
| O25 | `mvs_duplicate_action` behavior | `GAP — none` |
| O26 | `mvs_strip_exif` behavior | `GAP — none` |
| O27 | AI provider switching (google vs openai vs aws) | `SURFACE-ONLY` |
| O29 | `mvs_moderation_auto_action` — per-action variant | `SURFACE-ONLY` |
| O31 | Plugin page links target configured pages | `SURFACE-ONLY` |
| O34 | Admin Pro User Reports tab — action handling | `SURFACE-ONLY` |
| O36 | Admin Stats Video Analytics tab — data accuracy | `SURFACE-ONLY` |
| O40 | Admin All Media — optimization columns + row actions | `GAP — none` |
| O41 | Setup Wizard — full wizard flow (not just no-fatal) | `SURFACE-ONLY` |
| O45 | Admin Battle Monitor — admin-side resolve action | `SURFACE-ONLY` |
| O47 | Theme Library — theme selection and frontend effect | `SURFACE-ONLY` |
| O51 | Admin Pro AI settings save → provider reachable | `SURFACE-ONLY` |
| O53 | License page — `is_valid()` gates nothing assertion | `SURFACE-ONLY` |
| O69 | Membership quota adapters wire-through | `SURFACE-ONLY` |

---

*Generated: 2026-06-23. Next scheduled update: with the next feature PR or at the 1.9.0 planning session.*
