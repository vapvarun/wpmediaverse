# WPMediaVerse (Free) — Master Roadmap

> Single source of truth. Updated: 2026-03-29
> Process: plan → review → implement. Architecture decisions are final.

---

## BLOCKER: Architecture Decision (before v1.0)

The plugin currently uses 31 custom tables + CPT. This is a mixed approach
that must be resolved BEFORE release. Architecture can't change after v1.0.

### Decision Needed: What stays, what goes?

**KEEP (custom tables for many-to-many, justified):**
- mvs_reactions (user × media × type)
- mvs_favorites (user × media)
- mvs_follows (user × user)
- mvs_album_items (album × media)
- mvs_activity (feed)
- mvs_notifications (per-user read tracking)
- mvs_blocks (user × user)
- Messaging tables (4) — conversations, participants, messages, reactions

**QUESTION: Keep or move to postmeta?**
- mvs_media_index — duplicates wp_posts for fast queries
- mvs_media_stats — view/download/reaction counters
- mvs_media_views — individual view records
- mvs_access_rules — privacy rules per media
- mvs_access_grants — privacy grants per user
- mvs_mentions — @mentions
- mvs_reports — user reports

**DELETE (dead, replaced, or unused):**
- mvs_battles, mvs_battle_votes (old, replaced by unified Pro schema)
- mvs_challenges, mvs_challenge_votes (old)
- mvs_tournaments, mvs_tournament_*, mvs_tournament_votes (old)
- mvs_email_leads (0 rows, no feature)
- mvs_transactions (0 rows, no feature)
- mvs_error_log (use WP debug.log instead)
- wptests_* (10 test artifacts)

### Factors:
- postmeta = simpler, WordPress-native, works with all WP plugins
- Custom tables = faster queries at scale, but more maintenance
- v1.0 architecture is FINAL — can't restructure after release
- Platform target: Dribbble/Flickr/Pinterest level

---

## DONE (this session)

- [x] Settings page Jetonomy card layout
- [x] Dead CSS removed
- [x] Sidebar links (Quotas, Reports added)
- [x] Sidebar JS fix (external links navigate)
- [x] Overview permissions link fixed
- [x] Permission fail → wp_die (all pages)
- [x] All pages under WPMediaVerse menu (proper parent)
- [x] Menu cleaned to 7 items (CSS hide for tool pages)
- [x] Titles + menu highlighting work on all 15 pages
- [x] Missing capabilities fixed (edit/trash row actions)
- [x] Status badge CSS added
- [x] Pro CSS enqueue on hidden pages
- [x] Success feedback on form handlers
- [x] ReportManager description added
- [x] Tournament winner display_name
- [x] Plans consolidated (ROADMAP.md + ADMIN-UX-GUIDELINES.md only)

---

## TODO: After Architecture Decision

- [ ] Clean up dead tables
- [ ] DM integration (move from Pro to Free)
- [ ] Gamification hooks (ActivityService + NotificationService filters)
- [ ] Admin page pagination (6 pages)
- [ ] Pre-release checklist (version bump, build, readme, QA)

---

## Pre-Release Checklist

- [ ] Architecture decision finalized
- [ ] php -l — zero errors
- [ ] npm run build — blocks compile
- [ ] Remove console.log / error_log / var_dump
- [ ] Bump version
- [ ] .distignore + .pot file
- [ ] readme.txt
- [ ] QA suite
- [ ] Build ZIP
- [ ] Tag + push
