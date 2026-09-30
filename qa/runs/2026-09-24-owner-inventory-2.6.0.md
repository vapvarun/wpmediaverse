# Owner inventory - 2.6.0 cycle start (Free + Pro)

**Date:** 2026-09-24 · **Branch:** `2.6.0` · **Site:** mediaverse.local (Reign + BuddyNext, Free+Pro active, licence active)
**Method:** live, not code-read. Admin menu + settings read from the rendered wp-admin; every admin and
frontend URL fetched in the logged-in browser (admin) and again with no cookies (visitor); every
frontend route loaded in a 390px frame for overflow. Code-derived lens: `docs/qa/OWNER_INVENTORY.md`
(Free) and `docs/qa/OWNER_INVENTORY-pro.md` (Pro), regenerated today.

Result: **no PHP notice, fatal, wp_die or 5xx on any of 21 admin screens or 31 frontend routes**, both
roles. No page-level horizontal overflow at 390px.

## 1. What the owner has - counts

| | Free | Pro |
|---|---|---|
| Admin screens (menu) | 14 under MediaVerse | +Stories, Quota & Credits, Import under MediaVerse; own **Competitions** menu (5) |
| Hidden admin screens | - | `mvs-reports`, `mvs-analytics` (reached from Moderation / Stats tabs) |
| Settings tabs | 8 | +4 (Documents, Connected Accounts, Competitions, License) and extra sections inside Display, Storage, AI, Webhooks |
| Options read by code | 63 | 73 |
| Frontend pages assigned | Explore, My Media, Upload, Explore Documents | Compete |
| Blocks | 9 | 15 |
| Shortcodes | 14 | 13 |
| REST routes (code count) | 97 | 87 |
| Emails (`wp_mail`) | 0 (BP notifications only) | 4 (challenge created / entry / winner / participant) |

## 2. Admin screens

| Menu | Screen | Slug | Live state on the model site |
|---|---|---|---|
| MediaVerse | Overview | `wpmediaverse` | Quick Links, Frontend Pages, Recent Uploads (5), System Status, Delete Demo Data |
| | All Media | `mvs-media` | table 20/page, filter, search, bulk, pagination |
| | Documents | `mvs-documents` | table 20/page, filter, bulk |
| | Albums / Collections | CPT lists | 9 / 9 |
| | Tags | `mvs-tags` | table, search, bulk, edit/delete |
| | Stories (Pro) | `mvs-stories` | empty hero "No active stories right now" |
| | Moderation | `mvs-moderation` | tabs AI Flagged 0 · Pending Review 0 · Resolved/Rejected 0 · **User Reports 2** (Pro) |
| | Stats | `mvs-stats` | Overview · Video Analytics (Pro); Top Media by Views, AI Usage |
| | Quota & Credits (Pro) | `mvs-quotas` | Packages · Membership Mapping · User Quotas · Credit Log · Upgrade Prompt |
| | Integrations | `mvs-integrations` | Wbcom family cards (BuddyNext, Jetonomy, WB Gamification, Learnomy, WP Career Board, Listora) |
| | Import (Pro) | `mvs-migration` | rtMedia · MediaPress · BuddyBoss cards + backup warning |
| | Logs | `mvs-logs` | 50 rows, filter, Clear All |
| | Settings | `mvs-settings` | see section 3 |
| | Setup | `mvs-setup` | wizard (in menu) |
| Competitions (Pro) | Dashboard | `mvs-competitions` | Needs Attention 1, Quick Actions, Autopilot Status + Run Now |
| | Photo Challenges | `mvs-challenges` | Scheduled · Active 1 · Voting 3 · Finalized 11 · All 15 |
| | Tournaments | `mvs-tournaments` | Registration · Active · Finalized 1 · All 1 |
| | Photo Battles | `mvs-battles` | Voting · Active · Pending · Completed 4 · All 5 |
| | Themes | `mvs-theme-library` | 4 themes + Add Custom Theme |

Also registered outside our menu: Tools → Scheduled Actions (bundled Action Scheduler) and 4 Site Health
tests (tables, upload dir, required pages, media privacy).

## 3. Settings - 12 tabs, every section

| Tab | Sections (Pro-added in *italics*) | Owner-facing controls |
|---|---|---|
| General | General · Pages | max upload MB, allowed types (JPEG/PNG/GIF/WebP/MP4/WebM/MP3/OGG), default privacy, allow member privacy, duplicate detection (warn/skip/allow), strip EXIF (GPS only), app sign-in; page pickers for Dashboard, Explore, Upload, Explore Documents |
| Messaging | Messaging | who can DM (4 levels), min account age, chat panel visibility (4 modes), online status visibility |
| Display | Media Display · *Feed Layout* · *Stories* · *Mobile App Branding* · *Image Watermarking* | grid columns 2-5, items/page 12/24/48, default layout grid/justified/list, thumbnail quality, large size px, lightbox size (4), allow downloads; *layout Grid/Instagram/Pinterest/Flickr/Dribbble; stories on/off; app accent/logo/login bg/dark default; watermark on, apply-to all/roles, type text/image/both, text tokens, image, position (6), opacity, size, colour* |
| Storage | Storage · *S3* · *BunnyCDN* · *R2* · *DO Spaces* · *Storage Management* | driver (5), signed URL TTL, view retention days, compress, WebP, AVIF, filename hashed/original; per-driver credentials + CDN host; *migrate all / move next 20 / delete local next 20* (live: 33 in cloud, 61 local, 5 private kept) |
| Documents (Pro) | *Documents* · *Orphaned Files* | enable, roles, max MB (0 = server), allowed types (4 groups), default privacy (4), anonymous links, search inside documents; orphan dry-run check |
| AI | AI Features · *Google Vision* · *Rekognition* · *Anthropic* · *Auto-Captions* | provider (4), OpenAI key + model, auto-analyze, descriptions, tags, auto-apply tags, auto-moderate, monthly budget $; per-provider keys; Claude model; captions auto-generate, language (13), provider |
| Moderation | Moderation | ToS / EULA / guidelines URLs, abuse email, member reporting, AI-flag action (flag/hide/reject/delete), AI flag categories (6), custom terms, auto-hide threshold |
| Permissions | matrix renderer | role x capability grid (includes Use Documents) |
| Connected Accounts (Pro) | *Connectors* | enable connectors, Flickr key + secret |
| Webhooks | Webhooks · *Credit Webhook* | URL, secret, events (5); *HMAC credit webhook secret + endpoint doc* |
| Competitions (Pro) | *Competition Features* · *Boost Pricing* · *Weekly Autopilot* · *Upload Streaks* | master switch + battles / challenges / tournaments / boosts, battle XP; points per 100 impressions, max impressions, expiry; autopilot day/hour/entry/voting/max entries; streaks, freezes, freeze cost |
| License (Pro) | EDD licence | key + status (updates only, gates nothing except Documents writes) |

Live toggles on the model site: every competition feature, stories, autopilot, streaks, documents = on;
documents search = off; watermark = off; layout = grid.

## 4. Frontend screens

`A` = admin, `G` = logged-out visitor. All 200 unless noted.

| Route | A | G |
|---|---|---|
| `/` Explore (page) · `/media/` | grid + tag cloud | same |
| `/media/{slug}/` | single media; BuddyNext-linked items redirect to `/p/{id}/` by design | same |
| `/media/@{user}/` | profile; unknown user → branded 404 | same |
| `/media/edit-profile/` | form + blocked members | → `/login/?redirect_to=` |
| `/my-media/` + `/media/ /albums/ /collections/ /favorites/ /profile/ /documents/` | dashboard tab | "Your creative space awaits" auth gate |
| `/my-media/documents/shared/ /trash/ /{folder}/` | drive scopes | auth gate |
| `/upload-media/` | uploader | "Log in to upload" |
| `/explore-document/` | public document rows | same |
| `/messages/` | inbox (BuddyNext hub route) | → login |
| `/compete/` | hub: My Activity, My Results | hub: Active Challenge, Open Tournaments |
| `/media/challenges/` · `/{id}/` | list · detail with entry picker | same |
| `/media/battles/` · `/{id}/` | list (opens on Voting tab) · detail | same |
| `/media/tournaments/` · `/{id}/` | list (opens on Open tab) · bracket | same |
| `/album/` · `/album/{slug}/` · `/collection/` · `/collection/{slug}/` | archives + singles | same (private items withheld) |
| `/media-tag/{slug}/` · `/media-category/{slug}/` | filtered grid | same |
| `/media/{missing}/` | branded 404 "We couldn't find that media" | same |

## 5. Leads (not cards yet - each needs its live proof first)

1. **Stories moderation needs `manage_options`, not the delegated capability.** `StoriesPage` (menu,
   render, force-expire) and `StoryController` delete check `manage_options`, while the docblock says the
   page mirrors BattleMonitor / TournamentManager, which use `manage_mvs_settings`, and moderation elsewhere
   uses `mvs_moderation_screen`. An owner who delegates moderation cannot hand over story removal. Prove
   with a role holding `mvs_moderation_screen` but not `manage_options`.
2. **Battles and Tournaments lists open on an empty tab.** `/media/battles/` defaults to Voting and
   `/media/tournaments/` to Open; the model site has 4 completed battles and 1 finalized tournament, so a
   visitor lands on "No battles found" while results exist one tab over. UX call, not a defect.
3. **Tag term counts include private items.** `mvs_tag` "2026" has count 1 from a private document, so
   the term is non-empty to `get_terms()` while its archive shows "No media tagged 2026 yet". Check
   whether any public tag cloud or picker lists such tags.
4. **Checklist drift fixed in this pass:** `qa/inventory/WHAT-TO-CHECK.md` still described a
   `wpmediaverse_video_posters` Site Health test with an ffmpeg probe, removed with transcoding in 2.4.0.
   Replaced with the four real tests, and added rows for 15 surfaces the list did not cover.

Harness notes for the next walker: fetch-based empty-state scraping reports hidden templates (the
challenge detail "No challenges right now" was `display:none`); check visibility. `/wp/v2/users/me`
403s on wp-admin pages without `wpApiSettings`, so resolve the profile slug another way.
