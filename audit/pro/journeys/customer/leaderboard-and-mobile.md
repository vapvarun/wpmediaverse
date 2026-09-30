---
journey: leaderboard-and-mobile
plugin: wpmediaverse-pro
priority: normal
roles: [anonymous, member]
covers: [MV-BST-011, MV-BST-012, MV-BST-015, leaderboard, big-site-readiness]
prerequisites:
  - "Both plugins active — leaderboard has no owner enable/disable toggle, always active"
  - "At least one member with reactions/uploads, ideally past the visible top page"
estimated_runtime_minutes: 6
---

# Leaderboard sources/windows render correctly, viewer's own rank is cheap at scale, both fit at 390px

**Why this journey exists**: the leaderboard is the one gamification surface with
NO feature toggle anywhere — it's always on once Pro is installed. Its
`viewer_rank` field is deliberately cached in the OBJECT CACHE only, never a
transient, specifically to avoid one `wp_options` row per (user, source, window)
combination on a large member base — a big-site-readiness decision worth
proving holds. This journey also locks the `<ul>`-not-`<ol>` rendering choice,
which exists specifically to defeat themes that auto-number ordered lists.

## Setup

- Site: `$SITE_URL`
- A page with the `mvs/pro-leaderboard` block inserted, or use `GET /wp-json/mvs-pro/v1/leaderboard` directly.
- Member: `?autologin=<member>` who is NOT in the visible top page for at least one source.

## Steps

### 1. Reactions source (default), All Time window, logged-out
- **Action**: `curl "$SITE_URL/wp-json/mvs-pro/v1/leaderboard?source=reactions&window=all"`.
- **Expect**: rows with rank/avatar-URL/display-name/score/metric-label "reactions"; counts derive ONLY from public, approved, published media — a private/members-only or Document item never inflates a public score.

### 2. Media Count and 30-day / 7-day windows
- **Action**: repeat with `source=media_count`, then `window=30d` and `window=7d`.
- **Expect**: rows and scores change plausibly with the window; no error on any combination.

### 3. Gamification Points source with WB Gamification absent
- **Action**: with WB Gamification NOT active, `curl ".../leaderboard?source=gamification_xp"`.
- **Expect**: empty rows, `total: 0` — no error (`Plugin::has_gamification()` guard).
- **On fail**: `includes/Leaderboard/LeaderboardService.php`.

### 4. Empty-state copy and non-numbering markup
- **Action**: on a site/window with zero data, view the block; inspect the rendered HTML.
- **Expect**: "No leaders to display yet — get some reactions on your uploads!"; the rank list is a `<ul style="list-style:none">`, never an `<ol>` — verify specifically on a default WP theme that styles `<ol>` with auto-numbers (e.g. Twenty Twenty-Four content area) that no double-numbering appears.

### 5. Viewer's own rank, off-page
- **Action**: as the off-page member, `curl -H "X-WP-Nonce: $NONCE" ".../leaderboard?source=reactions"`.
- **Expect**: `viewer_rank`/`viewer_score` reflect the member's true position even though they're not in the visible page. Logged-out request gets `viewer_rank: null`.
- **On fail**: `LeaderboardService` viewer-rank resolver.

### 6. Viewer-rank cache is object-cache only, never a transient row
- **Action**: `mysql_query "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '_transient_%leaderboard%'"` immediately after step 5.
- **Expect**: `0` — confirms the cache uses `wp_cache_*` keyed per (source, window, user), not a `wp_options` transient, so a large member base never accumulates one row per user/source/window.
- **On fail**: `LeaderboardService` — reverted to a transient.

### 7. `gamification_xp` never reports a viewer rank
- **Action**: repeat step 5 with `source=gamification_xp`.
- **Expect**: `viewer_rank`/`viewer_score` null/0 — ownership of ranking is the external provider's, not this plugin's.

### 8. No persistent object cache — correctness over caching
- **Action**: on a site with only the default per-request object cache (no Redis/Memcached), repeat step 5 twice in the same page load context if possible, or confirm via code that the query re-runs.
- **Expect**: the viewer-rank query re-runs every request (acceptable) rather than erroring or silently caching nothing forever.

### 9. Mobile 390px — boost modal, streak widget, leaderboard block
- **Action**: `playwright_resize 390 844`; view (a) the leaderboard block, (b) the boost modal (see `04-boost-promotes-feed.md` for the modal itself), (c) the streak widget (see `streaks-flow.md`).
- **Expect**: leaderboard rows stack avatar/name/score without horizontal scroll; boost modal Cancel/Boost fit on-screen with >=40px tap targets; streak widget milestone markers wrap rather than overflow; streak badge next to a long display name does not force awkward wrapping in a tight card.

## Pass criteria

1. All three sources and all three windows return without error; `reactions`/`media_count` count only public+approved+published media.
2. `gamification_xp` degrades to empty (not an error) when WB Gamification is absent, and never reports a viewer rank.
3. Empty-state copy is exact; rank markup is `<ul>`, never `<ol>`, on a theme that auto-numbers `<ol>`.
4. Viewer's own rank is correct even off-page, cached in the object cache only (zero transient rows), and safely re-runs with no persistent cache.
5. Leaderboard, boost modal and streak widget all render correctly at 390px with >=40px tap targets.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Private/members media inflates a public rank | source query missing a privacy predicate | `includes/Leaderboard/LeaderboardService.php` |
| `gamification_xp` errors when WB Gamification absent | `has_gamification()` check missing | `LeaderboardService.php` |
| Rank list double-numbers on a default theme | markup reverted to `<ol>` | leaderboard block's `render.php` / template |
| A `wp_options` transient row appears per user | viewer-rank cache moved off the object cache | `LeaderboardService.php` |
| Leaderboard overflows at 390px | missing `@media` breakpoint | Pro leaderboard block CSS |
