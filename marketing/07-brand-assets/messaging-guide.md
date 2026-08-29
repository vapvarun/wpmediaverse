# WPMediaVerse Messaging Guide

Reference document for anyone writing copy — sales pages, emails, social posts, ad creative, support docs. Keeps the voice consistent across channels and contributors.

---

## What WPMediaVerse Is

WPMediaVerse is a WordPress media platform plugin. It gives communities a dedicated place to upload, organise, and engage with photos and videos — separate from the activity feed, with real media features that WordPress does not provide out of the box.

It works standalone or alongside BuddyPress. It uses custom database tables instead of the WordPress post system. The Pro version adds layout modes, gamification (photo battles, challenges, tournaments), cloud storage, video chapters and captions, and quota management.

---

## The Core Problem We Solve

Community site owners reach for BuddyPress, then discover that media management is an afterthought. The activity feed handles photos the way a Facebook wall handles them — they scroll past and disappear. There is no real gallery, no competition engine, no per-user quota, no cloud storage routing.

They turn to plugins like rtMedia or MediaPress, which have been around for years but show it. The UI is dated, the extensibility is limited, and customisation means hacking template files.

WPMediaVerse is a purpose-built media layer. It does not try to replace BuddyPress or rebuild your whole site. It adds exactly what is missing: a proper media home.

---

## Brand Voice

### How We Sound

**Direct.** We say what a feature does. "AI moderation flags content before it reaches your feed" — not "intelligent content analysis empowers community safety."

**Specific.** Concrete examples over abstract claims. "Per-user storage quotas that integrate with MemberPress" beats "advanced quota management."

**Honest about scope.** We do not claim to do everything. Free has a clear set of features. Pro has a clear set of additions. We say what each version includes and what it does not.

**Helpful, not hyped.** We are explaining a product to a person who has a real problem to solve. The tone is a knowledgeable colleague, not a press release.

### What We Avoid

- "Revolutionary", "game-changing", "next-level", "powerful", "robust" — all filler
- Vague benefit phrases: "take your community to the next level"
- Passive voice in feature descriptions
- Burying the price — show it, and show what it includes
- Overselling the free version and underselling the gap to Pro

---

## Taglines

These are tested options in order of preference. Use the primary for most placements. Alternatives for ad copy where shorter is better.

**Primary:**
"The media layer your community site is missing."

**Alternatives:**
- "Real media management for WordPress communities."
- "Upload. Organise. Compete. Your community's media home."
- "More than an activity feed."
- "Built for communities that take media seriously."

**For Pro specifically:**
- "Compete, showcase, and scale — WPMediaVerse Pro."
- "Galleries, gamification, and cloud storage in one plugin."

---

## Elevator Pitches

### 30-Second Version (for product pages, hero sections)

WordPress communities deserve better media management than an activity feed can provide. WPMediaVerse gives your members a dedicated photo and video platform — with albums, reactions, comments, direct messages, and AI moderation included in the free version. Pro adds four layout modes, photo competitions and tournaments, S3 and BunnyCDN cloud storage, video chapters, resume playback and auto-captions, and per-user quotas that connect to MemberPress or WooCommerce.

### 10-Second Version (for ads, social bios)

A photo and video platform for WordPress communities. Upload, album, compete, and moderate — without leaving WordPress.

### Developer Version (for technical audiences)

WPMediaVerse is built on custom tables — not wp_posts. Every major operation fires documented action and filter hooks. The REST API covers the full media lifecycle. BuddyPress integration is a separate layer, not a dependency. Pro gamification uses a single manifest file against the wb-gamification engine.

---

## Key Messages by Audience

### Community Admins (non-technical)

1. Your members can upload and organise photos without you doing anything extra.
2. AI moderation catches inappropriate content before it goes public — you review flagged items, not every upload.
3. It works with your existing BuddyPress setup. You do not need to rebuild anything.
4. The free version is genuinely useful. Pro adds the features that make it a competition and showcase platform.

### BuddyPress Developers

1. Custom tables only. No post type abuse, no taxonomy hacks.
2. Every operation is hookable. `mvs_before_upload`, `mvs_after_reaction`, `mvs_media_query_args` — the full list is in the developer docs.
3. BuddyPress integration is a separate module. It enhances BP; it does not depend on it.
4. The gamification layer integrates via a single manifest file against wb-gamification. No custom bridge classes to maintain.

### Education Admins

1. Per-user storage quotas connect directly to MemberPress or WooCommerce roles — no custom code needed.
2. AI moderation with configurable sensitivity handles the volume you cannot review manually.
3. Per-item privacy controls let students choose who sees their work — public, members only, or private.
4. GDPR: user data export and deletion are built in, not bolted on.

### Photography Club Owners

1. Photo Battles let two photos go head-to-head with community voting.
2. Challenges let you set a theme and deadline; members submit entries and a leaderboard shows results.
3. Tournaments run bracket-style competitions across your whole community.
4. Members earn points and badges for uploading, commenting, and competing — not just for winning.
5. Gallery and masonry layout modes make member work look like a portfolio, not a social feed.

### Agency Owners

1. Works with any WordPress theme — no platform lock-in.
2. S3 and BunnyCDN integration keeps client hosting costs manageable on media-heavy sites.
3. Clean, documented architecture. Customise with hooks, not template forks.
4. Agency licensing available — one price, use it across client sites.

---

## Competitive Positioning (Short Form)

**vs. rtMedia:** rtMedia is the incumbent but has not evolved its UI or architecture in years. WPMediaVerse uses custom tables, has a more modern media experience, and ships gamification and cloud storage that rtMedia does not offer.

**vs. MediaPress:** MediaPress is stable and BuddyPress-native, but customisation requires working around it rather than with it. WPMediaVerse has cleaner extension points and adds video chapters, auto-captions, and a competition engine that MediaPress does not have.

**vs. BuddyBoss Media:** BuddyBoss is a full platform — theme plus plugin — that costs significantly more and locks you into their ecosystem. WPMediaVerse is a media layer that works with your existing site and theme.

---

## Feature Naming Conventions

Use these names consistently across all copy. Do not improvise variations.

| Feature | Correct Name | Avoid |
|---------|-------------|-------|
| Layout modes | Instagram mode, Flickr mode, Pinterest mode | "display templates", "view modes" |
| Competitions | Photo Battles, Photo Challenges, Tournaments | "contests", "competitions feature" |
| Points system | Points and badges (via gamification) | "gamification system", "XP" |
| Storage | Cloud storage (S3, BunnyCDN) | "CDN support", "offload" |
| Video | Video chapters, resume playback, auto-captions | "video transcoding", "HLS", "adaptive streaming" — we do not transcode |
| Privacy | Per-item privacy controls | "visibility settings", "access control" |
| Moderation | AI moderation | "smart moderation", "automated filtering" |
| Quotas | Per-user storage quotas | "storage limits", "user quotas" |

---

## What Not to Promise

These are things we should not claim in marketing copy unless they become explicitly supported:

- "Works with every BuddyPress plugin" — BP integration is tested with core BP only
- "Zero performance impact" — cloud storage offloads media; local storage still hits disk during upload
- "No coding required" for developer-level customisations
- White-label support — not currently a built-in feature (rename requires manual work)
- Multi-language / WPML support — not explicitly confirmed
