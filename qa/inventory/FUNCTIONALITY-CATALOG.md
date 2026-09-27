# WPMediaVerse Functionality Catalog (Free + Pro)

**Version covered:** 2.6.0 (Free and Pro). **Last reviewed:** 2026-09-27.

This is the one file to hand to a tester, a QA vendor or an AI test agent. It lists every piece of
functionality in WPMediaVerse and WPMediaVerse Pro, who can use it, where it lives, how to test it,
what must happen, and what the person using it should experience. It assumes no access to the
code.

It has four parts:

1. **How to test** - environment, users, and the test matrix every entry runs under.
2. **The UX and presentation bar** - rules every screen must meet. Entries do not repeat them.
3. **The catalog** - one entry per testable feature, Free first, then Pro.
4. **Code organization audit** - the long-term health checks for the codebase itself (for the
   development team and code reviewers, not for black-box testers).

**Keeping it current.** Any change that adds, removes or changes a feature, setting, screen, REST
route, WP-CLI command or template updates this file in the same commit. A feature with no entry here
is a feature QA will not test.

---

## Part 1 - How to test

### 1.1 Environment

| Item | Requirement |
|---|---|
| WordPress | 6.5 or newer (the model site runs 7.0.x) |
| PHP | The plugin header declares 7.4+. Test at least 8.1 and 8.5, one pass with `WP_DEBUG` on; any notice or deprecation from the plugin is a defect |
| Plugins | **Free only** (WPMediaVerse) and **Free + Pro** (WPMediaVerse Pro active). Some entries also need BuddyPress |
| Pro licence | Active for normal testing. Pro features keep working without a licence, EXCEPT Document library writes, which are refused on an unlicensed site (see the DOC entries) |
| Mail | A mail catcher (Mailpit, MailHog or similar). Read the real messages; an email log saying "sent" is not proof |
| Web server | Apache for the main pass. Private-file protection relies on `.htaccess`; on nginx the plugin shows a Site Health warning instead (see HLT entries) |

### 1.2 Users (create before starting)

| Persona | Role | Used for |
|---|---|---|
| `visitor` | logged out | Public surfaces and every refusal check |
| `member-a` | Subscriber | The owner of the test content |
| `member-b` | Subscriber | A second member of the same role. Used to prove member-a's private or members-only items are refused to a peer. Every permission check needs both |
| `author` | Author | Role-based capability checks |
| `moderator` | Editor | Moderation queue, reports |
| `admin` | Administrator | Settings and the control run. Admin is always the last role walked, never the only one |

### 1.3 Test data

Seed before starting, as member-a unless noted:

- 25 or more photos (so every grid pages past its first 20), 2 videos, 2 audio files, 3 documents
  (PDF, DOCX, XLSX).
- One item at each privacy level offered in the upload form: Public, Members (logged-in only), Private, and Friends
  when BuddyPress friendships are active.
- Two albums (one Public, one Private) and one collection.
- member-b follows member-a; member-b comments on and reacts to one of member-a's photos.
- One direct-message conversation between member-a and member-b.
- For big-site checks: 2,000 or more media rows (MediaVerse > Overview has a demo-data import; WP-CLI
  options are listed in the CLI entries).

### 1.4 The test matrix (applies to every frontend entry)

Run each frontend entry under these conditions. An entry's own "Edge cases" line adds to this list.
It does not replace it.

| Axis | Values |
|---|---|
| Themes | Twenty Twenty-Five (block theme), one classic theme (Astra or Twenty Twenty-One), and the Wbcom themes Reign, BuddyX and BuddyX Pro |
| Colour scheme | Light, and dark where the theme offers it. MediaVerse follows the theme's dark signal (`data-theme="dark"`, `data-bx-mode="dark"`, `data-bn-theme="dark"` or a `dark-mode` class). It does not follow the operating system setting. Testing OS dark mode alone is not a dark-mode test |
| Viewport | 1440, 1024, 768, 430 and 390 px wide |
| Direction | Left-to-right, and right-to-left (switch the site language to Arabic or Hebrew) |
| Input | Mouse, keyboard only, and touch (device emulation) |
| Roles | Every role named in the entry's **Who** line, including the roles that must be refused |

### 1.5 Entry format

```
### MV-<AREA>-<NNN> - Feature name
- Edition:        Free or Pro
- Who:            roles that can use it, and roles that must be refused
- Where:          URL, wp-admin path, REST route or WP-CLI command
- Setup:          data and settings needed first
- Steps:          numbered, UI-level
- Expected:       what must happen, including what is saved
- UX expectation: what the person should experience: feedback, states, focus, what must NOT happen
- Settings that change it: admin label (option key) and its effect
- Edge cases:     beyond the Part 1.4 matrix
```

`VERIFY:` inside an entry means the behaviour could not be confirmed from the code when the entry
was written. Test it, and report whichever way it goes.

### 1.6 Reporting a defect

Every defect report states:
- the entry id
- the edition and version
- theme, viewport and colour scheme
- the role
- exact steps
- what was shown versus what was expected
- a screenshot

A permission defect also names the second, same-role member the check was run as.

---

## Part 2 - The UX and presentation bar (every screen, every entry)

These rules apply to every frontend surface, block, shortcode and wp-admin screen. A failure is a
defect against the entry being tested, even if its own Expected line passed. The standard comes from
the Wbcom UX foundation (plugin UI) and the universal parts of the Wbcom taste standard. Visual
direction for marketing pages is out of scope here.

### 2.1 The plugin owns its look

- [ ] Every MediaVerse surface looks correct on every theme in the matrix. A button, link, input or
      card that changes shape, colour or size because the theme styles bare `button`, `a` or `input`
      is a **MediaVerse** defect, not a theme defect.
- [ ] All screens of one area feel like one product: same buttons, same card padding, same focus
      ring, same badge styles, same empty-state layout.
- [ ] Icons are line icons from one set (Lucide). No emoji used as icons, no WordPress Dashicons on
      the frontend, no mixed icon styles on one screen.
- [ ] Colours come from the theme or the plugin's tokens. Nothing hard-coded clashes with the theme
      palette. No pure black (`#000`), no neon glows, no oversaturated accents.
- [ ] Text a member sees never shows a raw key, slug, option name, error code, `undefined`, `NaN`,
      `[object Object]` or an untranslated placeholder such as `%s`.
- [ ] No placeholder or demo content ships in the plugin's own UI (for example "John Doe", "Lorem
      ipsum", "Acme").

### 2.2 Every state is designed

For every list, grid, panel, modal and form:

| State | Must show |
|---|---|
| Loading | A skeleton or spinner shaped like the content. It must not jump when the content arrives (no layout shift) |
| Empty | An icon, a one-line title, a short explanation and, where the viewer can act, the next action ("Upload your first photo"). Never a bare "No items found" and never a blank area |
| Error | Why it failed, in plain words, where the action happened: inline for forms, a toast for transient actions. The rest of the page keeps working |
| Success | Confirmation that survives any reload the action triggers. What changed appears without a manual refresh |
| Refused | A role that may not do something does not see the control. A refused item looks exactly like a missing one (same message, same status). It never confirms that the private item exists |
| Paged | Every list of 20 or more pages or loads more. The last page and the "no more" state are handled. Counts match the rows |

### 2.3 Feedback and dialogs

- [ ] Every action gives feedback within about 100 ms (pressed state, spinner or disabled button).
      Double-clicking never submits twice.
- [ ] Destructive actions (delete, remove, leave, empty trash) and actions that make something more
      visible (for example making an album public) ask first in an on-page dialog. Browser
      `alert()`, `confirm()` and `prompt()` are never used.
- [ ] In a dangerous dialog, Cancel has focus when it opens. Esc closes it.
- [ ] Prompts submit on Enter and show their error inline, not in a new dialog.
- [ ] A toast does not cover the control that produced it, and it can be dismissed.
- [ ] Nothing flashes and then hides during page load (no hidden panel painted before scripts run).

### 2.4 Keyboard, focus and screen readers (WCAG 2.1 AA)

- [ ] Every control can be reached and used with the keyboard. Tab order follows the visual order.
- [ ] A visible focus ring appears on every focused control (keyboard focus). It is never removed
      without a replacement.
- [ ] Modals and the lightbox trap focus while open, close on Esc, and return focus to the control
      that opened them.
- [ ] Icon-only buttons have an accessible name (for example "Like", "Close", "More options").
- [ ] Images have alt text: the member's own alt text or title, or empty alt for pure decoration.
- [ ] Text contrast is at least 4.5:1 (3:1 for large text and UI outlines), in light AND dark. This
      includes placeholder text, helper text, disabled labels and text over photos (which needs a
      scrim).
- [ ] With "reduce motion" on in the operating system, animations over 150 ms are removed or
      reduced.
- [ ] Status changes (toasts, "Saved", live counts) are announced (`aria-live`).

### 2.5 Layout and responsiveness

- [ ] No horizontal page scroll at any matrix width. Long titles, file names, URLs and user names
      wrap or truncate with an ellipsis. They never push the layout.
- [ ] Touch targets are at least 40 x 40 px on touch devices (34 px only in dense admin tables).
- [ ] Button labels fit on one line at desktop. No two buttons on one screen do the same thing under
      different words.
- [ ] Hover-only affordances also work on touch, and hover styles do not "stick" after a tap.
- [ ] Right-to-left mirrors the layout (arrows, alignment, spacing) without overlap.
- [ ] The floating upload button and the chat panel never cover content or controls the member needs
      (for example the last row of a grid or a modal's buttons).
- [ ] Fixed or sticky elements are not hidden behind the theme header or the WordPress admin bar.

### 2.6 Dark mode

- [ ] Switch the theme to dark and walk the surface. Every surface, modal, badge, input, toast and
      empty state follows. There are no white panels, no invisible text and no borders that
      disappear.
- [ ] Switching back to light leaves nothing dark behind.

### 2.7 wp-admin screens

- [ ] Same header, card and button style across every MediaVerse admin screen. One primary action
      per screen.
- [ ] Every setting has a real label and a one-line description. A label is never an option key, and
      never a placeholder that disappears when you type.
- [ ] Saving shows a notice. An invalid value shows its error next to the field.
- [ ] A setting that another plugin controls is shown locked, with the reason (for example "Members
      only" when BuddyNext decides).
- [ ] Every screen with nothing configured says what to do next.
- [ ] Other plugins' nag notices do not crowd MediaVerse screens.
- [ ] Long admin lists (2,000 or more rows) page, filter by their main field (status, type,
      reason), sort, and load in under 2 seconds.

### 2.8 Performance a member can feel

- [ ] Explore, a profile and the dashboard load their first screen in under 2.5 s on a 2,000-item
      site (largest content paint).
- [ ] Images load lazily below the fold and use the smaller generated sizes (WebP where the server
      supports it), not the original upload.
- [ ] No console errors, and no failed network requests (4xx or 5xx) on any surface during a normal
      walk.

### 2.9 The surface sweep (one feature, every place it appears)

An entry names one screen. Before marking a feature PASS, walk it through every row that applies.
A feature that works on one surface and fails on a sibling is a defect.

| Axis | Walk each that exists for the feature |
|---|---|
| Entry points | Frontend page, block on a normal page, shortcode on a normal page, wp-admin screen, REST route, email, notification, WP-CLI, BuddyPress profile or group tab |
| Roles | Logged out, member (owner of the item), member (not owner), moderator, admin |
| Object states | Empty, one item, paged (21 or more), last item on the last page, private, members-only, in an album, trashed, deleted parent (for example the album was deleted), owner deleted |
| Links | Live, expired, revoked, wrong, and used up (for share links and download links). What is each role told? |
| Settings | Flip each setting in the entry's "Settings that change it" line. Walk every surface that reads it. Restore it |
| Data | What the screen shows matches what is stored. Reload, log out and in, and view from another browser |

---

## Part 3 - The catalog

Free frontend, then Free admin, settings, tools and REST, then Pro.

### Area: Explore (EXP)

#### MV-EXP-001 — Explore feed default view
- **Edition:** Free
- **Who:** Visitor, Member (both see the same public feed; Members Only setting can block Visitor — see MV-ACC-001)
- **Where:** `/media/` (or the page mapped in Settings > General > "Explore Page", if one is set — see MV-EXP-009). Template `templates/explore.php`. Data via `MediaRepository::query()` server-side on first load.
- **Setup:** At least one published media item with privacy `public` or `members`.
- **Steps:** 1. Visit `/media/` while logged out. 2. Note the grid of thumbnails. 3. Log in as a member and revisit.
- **Expected:** A grid of real thumbnails with title/metadata, paginated at the "Items Per Page" setting's size (default 12). Only `public` items are visible to a logged-out visitor; a member also sees `members`-privacy items and their own private/friends/group items.
- **UX expectation:** Populated state shows thumbnails immediately (server-rendered, no loading spinner on first paint). Empty state (no published media anywhere) shows a friendly "nothing shared yet" message with an upload call-to-action (hidden/replaced by a login prompt for a logged-out visitor, since they cannot upload). No page reload on any later filter/sort/load-more interaction (see MV-EXP-006/007).
- **Settings that change it:** `mvs_items_per_page` (Settings > Display > "Items Per Page": 12/24/48) controls page size. `mvs_grid_columns` (Settings > Display > "Grid Columns": 2-5, only shown when Layout = square) and `mvs_layout_choice` (Settings > Display > "Layout") control grid shape.
- **Edge cases:** Zero published media site-wide → empty state, not an error. 390px mobile → grid collapses to fewer columns per the layout CSS. A trashed or pending-moderation item never appears.

#### MV-EXP-002 — Explore search (`?q=`)
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `/media/?q=<term>` (search box on the Explore page). Read by `TemplateHelpers::explore_search()`. Backing REST: `GET /mvs/v1/media?s=<term>` for Load More continuation.
- **Setup:** Media items with distinct titles.
- **Steps:** 1. Type a term that matches a media title into the Explore search box. 2. Submit/observe results.
- **Expected:** Feed filters to items whose title/description matches (MySQL FULLTEXT with LIKE fallback). URL updates to `?q=<term>`.
- **UX expectation:** A zero-result search shows "no results for {term}" plus popular tags and a "browse all" link, not a blank grid. Search debounces (see MV-EXP-008) rather than firing on every keystroke.
- **Settings that change it:** none.
- **Edge cases:** Search term under 2 characters does nothing (no network call — see MV-EXP-008). Search combined with an active tag/category filter narrows further, does not reset the filter.

#### MV-EXP-003 — Legacy `?s=` search still works on `/media/`
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `/media/?s=<term>` on the raw `/media/` route only.
- **Setup:** None beyond MV-EXP-002.
- **Steps:** 1. Visit `/media/?s=coffee` directly (old bookmarked link style). 2. Compare against `/media/?q=coffee`.
- **Expected:** Identical results to `?q=`. `explore_search()` reads `$_GET['q']` first, then falls back to `$_GET['s']`. If the site has a mapped Explore page (MV-EXP-009), the 301 redirect from `/media/` to that page rewrites `s` to `q` on the way so the mapped page's search still works and is never hijacked into WordPress's own site search.
- **UX expectation:** Same as MV-EXP-002 — no visible difference to the tester between `?q=` and `?s=` on `/media/`.
- **Settings that change it:** none.
- **Edge cases:** A theme copy of `explore.php` made before 2.6.0 reads the old `s` parameter; the plugin hands the `q` term to it, so search must still work with such a copy both on `/media/` and on a mapped Explore page. Check: copy an old `explore.php` into the theme's `wpmediaverse/` folder and search.

#### MV-EXP-004 — Filter by tag
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `/media/?mvs_tag=<slug>` or the dedicated tag archive `/media-tag/<slug>/` (taxonomy `mvs_tag`, served via `explore.php`).
- **Setup:** At least one media item tagged with a known tag.
- **Steps:** 1. Click a tag chip (on a media card, single-media page, or the Explore tag cloud). 2. Observe the filtered feed.
- **Expected:** Feed narrows to items carrying that tag only. An unknown tag slug shows "not found" rather than the unfiltered feed.
- **UX expectation:** Empty result for a real-but-unused tag: "no media tagged X" with a clear-filter control. A tag whose only items are private also lands on this empty state for a viewer who can't see them, not an error.
- **Settings that change it:** none directly; per-item privacy still applies within the filtered set.
- **Edge cases:** Combining a tag filter with search and with sort must all apply together (AND, not override each other).

#### MV-EXP-005 — Filter by category
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `/media/?mvs_category=<slug>` or `/media-category/<slug>/` (taxonomy `mvs_category`).
- **Setup:** Media items assigned a category (via edit modal or upload).
- **Steps:** 1. Click a category link. 2. Observe the filtered feed.
- **Expected:** Same behaviour as tag filtering, scoped to `mvs_category`.
- **UX expectation:** Same empty/zero-result treatment as MV-EXP-004.
- **Settings that change it:** none.
- **Edge cases:** Same as MV-EXP-004.

#### MV-EXP-006 — Sort control
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Explore page toolbar, "Sort by" select + "Apply" button. Query params `sort` (`created_at` default / `oldest` / `views`) and `order`. Read via `TemplateHelpers::explore_sort()`; shared by Explore, profile pages and Load More (same GET params).
- **Setup:** At least 2 media items with different upload dates and view counts.
- **Steps:** 1. Change "Sort by" to "Oldest". 2. Click Apply. 3. Repeat with "Most viewed".
- **Expected:** "Newest" (default, `created_at` desc), "Oldest" (`created_at` asc), "Most viewed" (`views` desc). URL reflects the chosen `sort`/`order` so it's shareable/bookmarkable. Load More continues in the same order.
- **UX expectation:** This is a plain GET form submit (not an AJAX auto-apply) — selecting the dropdown alone does not re-sort until "Apply" is clicked; no full white-flash reload feel is expected but the page does navigate (server-rendered). An unrecognised/tampered `sort` value silently falls back to Newest rather than erroring.
- **Settings that change it:** none (sort is always available; not a toggle).
- **Edge cases:** Sort persists across pagination (page 2 keeps the chosen sort). Fewer than 1 item hides the whole toolbar (no dead controls over an empty grid).

#### MV-EXP-007 — Load More pagination
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `.mvs-load-more-btn` at the bottom of the Explore grid (also profile grids, album/collection archives). Calls `GET /mvs/v1/media` directly (plain `fetch()`, not a full block reload) carrying `page`, `per_page`, current `tag`/`category`/search/sort from data attributes.
- **Setup:** More media than one page's worth (> `mvs_items_per_page`).
- **Steps:** 1. Scroll to the bottom of Explore. 2. Click "Load More".
- **Expected:** The next page's items append below the existing grid without a full page reload; the button disappears once the last page is reached.
- **UX expectation:** Loading state on the button while the fetch is in flight (label change/disabled), no duplicate items appended on a double-click, no page scroll jump. Network failure shows a toast/error rather than silently doing nothing.
- **Settings that change it:** `mvs_items_per_page` controls the page size fetched each click.
- **Edge cases:** Also verify `/media/page/2/` direct URL still works (server-rendered pagination) and returns HTTP 200, not a soft-404 (Coding Rule fix, 2.3.0) — important for search-engine indexing of page 2+.

#### MV-EXP-008 — Explore search autocomplete
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Explore search box, dropdown suggestion list.
- **Setup:** At least 8+ media items with varied titles for a meaningful test.
- **Steps:** 1. Type 2+ characters into the search box. 2. Wait ~250ms. 3. Observe the dropdown. 4. Use ArrowDown/ArrowUp/Enter/Esc.
- **Expected:** After a 250ms debounce, up to 8 title matches appear as a dropdown (`GET /mvs/v1/media?s=&per_page=8`). ArrowDown/Up move selection, Enter navigates/searches the highlighted item, Esc closes the dropdown.
- **UX expectation:** No network request fires for fewer than 2 characters or for a query that returns zero matches — dropdown simply stays hidden, it does not show an empty-state row. Selecting via keyboard must visibly highlight the active option (for screen-reader and sighted keyboard users alike).
- **Settings that change it:** none.
- **Edge cases:** Rapid typing must not fire a request per keystroke (debounce truly at 250ms — a QA tool typing fast should see far fewer requests than characters typed).

#### MV-EXP-009 — `/media/` 301 redirect to mapped Explore page
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** wp-admin Settings > General, page-select field for Explore (option `mvs_page_explore`). Enforced in `TemplateLoader::redirect_archive_to_explore_page()`.
- **Setup:** Map any published WP page as the Explore page in settings.
- **Steps:** 1. Set the Explore page mapping. 2. Visit `/media/` directly. 3. Visit `/media/page/2/` if there's a 2nd page of results. 4. Visit `/media/?q=term`.
- **Expected:** `/media/` 301-redirects to the mapped page's own permalink; `page/2/` and the search/tag/type query string carry over onto the new URL (with `s` rewritten to `q` if present). Singles (`/media/<slug>/`) are never redirected — only the archive.
- **UX expectation:** This is a real HTTP 301 (verifiable in network tab / curl -I), not a client-side redirect — matters for SEO. No duplicate/indexable copy of Explore should remain reachable at the raw `/media/` address once mapped.
- **Settings that change it:** `mvs_page_explore` (the mapping itself). Filter `mvs_redirect_media_archive_to_explore_page` (default true) can disable the redirect — code-level escape hatch, not a UI setting.
- **Edge cases:** Unmapping the Explore page (clearing the setting) must make `/media/` serve Explore directly again with no redirect loop.

#### MV-EXP-010 — Explore tag cloud
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Explore page sidebar/partial `templates/partials/explore-tag-cloud.php`. Data via cache service `tag_cloud()`; REST `GET /mvs/v1/tags/cloud`.
- **Setup:** Several media items tagged with a handful of distinct tags.
- **Steps:** 1. Visit Explore. 2. Observe the tag cloud. 3. Click a tag.
- **Expected:** Tags rendered as clickable chips, sized/weighted by usage; clicking filters the feed (MV-EXP-004).
- **UX expectation:** No tag cloud markup at all when no tags exist anywhere on the site (not an empty box).
- **Settings that change it:** none.
- **Edge cases:** none beyond MV-EXP-004.

#### MV-EXP-011 — Album and Collection archives
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `/album/` and `/collection/` archive routes (CPT archive template `templates/cpt-archive.php`).
- **Setup:** At least one public album and one public collection.
- **Steps:** 1. Visit `/album/`. 2. Visit `/collection/`.
- **Expected:** A card grid of public albums (or collections respectively); members-only/private ones are excluded for a viewer who can't see them.
- **UX expectation:** "no albums yet" / equivalent empty state, not a blank page, when nothing public exists.
- **Settings that change it:** none.
- **Edge cases:** A members-only album must not appear to a logged-out visitor even as a locked/greyed tile — it's simply absent from the archive.

#### MV-EXP-012 — Single-media Open Graph / Twitter Card meta
- **Edition:** Free
- **Who:** Visitor, Member (affects link previews on Facebook/X/Slack/etc., not an on-page action)
- **Where:** `<head>` of any `/media/<slug>/` page, injected on `wp_head` priority 5.
- **Setup:** A public (or otherwise viewable) media item with a title/description/thumbnail.
- **Steps:** 1. View page source of a public media permalink. 2. Check for `og:title`, `og:image`, `og:description`, `twitter:card`.
- **Expected:** Tags present, using the item's title/description (trimmed to 280 chars) and a thumbnail (signed if the item is shareable-but-private). Absent entirely on non-media pages.
- **UX expectation:** For a viewer who is denied access (private item, or a signed-out visitor who would need to log in), NO Open Graph tags are emitted at all — a shared link must never leak the title, owner or image of gated media.
- **Settings that change it:** none.
- **Edge cases:** A document (`document`/`legacy_document` media_type) still gets a page and, per the single-media privacy rule, denial is a plain branded 404 (documents never get the "log in to view" prompt, since a filename can carry a client name).

---

### Area: Single media + lightbox (MED)

#### MV-MED-001 — View a single media page
- **Edition:** Free
- **Who:** Visitor, Member (subject to the item's privacy), Owner, Admin/Moderator
- **Where:** `/media/<slug>/` (or `/media/<numeric-id>/`). Template `templates/media-single.php`.
- **Setup:** A published media item.
- **Steps:** 1. Open a public item's permalink logged out. 2. Open a `members`-privacy item logged out. 3. Open the same item logged in as a member. 4. Open a `private` item as a non-owner.
- **Expected:** Public: renders normally for everyone. Members-only, logged out: a "Log in to view" page that names nothing (no title/owner/image/description) — signing in would grant access. Private/custom-list/pending/rejected, or any denial that signing in can't fix: the same branded 404 as a missing slug — this must NOT reveal that the item exists.
- **UX expectation:** The privacy check runs and decides the response BEFORE any page title, breadcrumb or Open Graph tag is set — a denied viewer's browser tab title must never say the item's real title. A denied response sets HTTP 403 (login-could-help case) or standard 404 (everyone-else case), never 200.
- **Settings that change it:** `mvs_default_privacy`, `mvs_allow_user_privacy` affect what privacy new items get, not this view logic itself.
- **Edge cases:** A numeric-ID URL for a trashed item must 404, not serve the trashed row. A slug matching a WP attachment post must not get redirected to the raw attachment file URL (explicit fix in `TemplateLoader`).

#### MV-MED-002 — Record a view
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Automatic on page load. `POST /mvs/v1/media/{id}/view` (`MediaController::record_view`).
- **Setup:** A viewable media item.
- **Steps:** 1. Open the single-media page. 2. Reload.
- **Expected:** View count increments (rate-limited so rapid reloads don't inflate it unboundedly).
- **UX expectation:** Entirely silent — no toast, no visible change except the view counter (visible to owner/admin in stats) ticking up.
- **Settings that change it:** none.
- **Edge cases:** A denied viewer's blocked page load must not still record a view for content they can't see.

#### MV-MED-003 — React with an emoji
- **Edition:** Free
- **Who:** Member (must be logged in and able to view the item); Visitor is refused
- **Where:** Reaction bar on the single-media page and lightbox. `POST`/`DELETE /mvs/v1/media/{media_id}/reactions`.
- **Setup:** Any viewable media item, logged in as a member who is not blocked by the owner.
- **Steps:** 1. Click "Like". 2. Click a different reaction (e.g. "Love") on the same item. 3. Click "Love" again to remove it.
- **Expected:** Six reaction types: Like, Love, Haha, Wow, Sad, Angry. Choosing a new reaction replaces the previous one (one reaction per user per item); clicking the active reaction again removes it. Counts update.
- **UX expectation:** The UI updates optimistically (reaction/counts flip immediately, before the server confirms) per the code's own description; a failed save reverts the optimistic change and shows a toast ("Could not save reaction."). Logged-out click shows "Please log in to react." toast instead of a network call. Each reaction button carries an `aria-label` (sentence form) and `aria-pressed`; the emoji glyph itself is `aria-hidden` so screen readers hear the label, not the emoji.
- **Settings that change it:** none (reaction types are a fixed vocabulary, not configurable in Free).
- **Edge cases:** A user blocked by the item's owner (either direction) cannot react — write is refused. Reacting to a members-only item you can't view is refused with the same visibility gate as viewing it.

#### MV-MED-004 — Favourite / bookmark an item
- **Edition:** Free
- **Who:** Member (logged in); Visitor is refused
- **Where:** Favourite (star/heart) icon on media cards, single-media page, and lightbox. `POST`/`DELETE /mvs/v1/media/{media_id}/favorite`.
- **Setup:** Any viewable media item.
- **Steps:** 1. Click the favourite icon while logged out. 2. Log in and click it. 3. Click again to remove.
- **Expected:** Logged out: toast "Please log in to favorite." and no request sent. Logged in: toggles on/off; the item appears in the My Media > Favorites listing (see MV-DSH-004) while favourited.
- **UX expectation:** Icon state (filled/outline) updates immediately; a failed toggle shows "Could not update favorite." and reverts the icon. No page reload.
- **Settings that change it:** none.
- **Edge cases:** A members-only or private item you cannot view cannot be favourited: it answers `404 mvs_not_found`, exactly like an item that does not exist. In stock Free, favouriting always targets your personal Favorites collection — Free's `mvs_collections_enabled` filter defaults `false`, so there is no multi-collection "Save to…" picker on this action; it is a plain on/off Favourite. (See MV-COL-004.)

#### MV-MED-005 — Comment on media
- **Edition:** Free
- **Who:** Member (logged in, not blocked by the owner); Visitor is refused
- **Where:** Comment box on the single-media page. `POST /mvs/v1/media/{media_id}/comments`; list via `GET` same route.
- **Setup:** Any viewable media item.
- **Steps:** 1. Log in. 2. Type a comment and submit. 3. Reload the page.
- **Expected:** Comment posts, appears in the thread (stored as a native WP comment scoped to the media's own `comment_type`), persists across reload.
- **UX expectation:** Logged-out attempt shows "Please log in to comment." toast, no request. Submit button should not allow a double-submit while the request is in flight. A failed post shows the server's own message when present, else "Could not post comment."
- **Settings that change it:** none for posting itself; `mvs_comment_edit_window` affects editing (see MV-MED-006).
- **Edge cases:** Duplicate-comment guard (60s) silently rejects an identical resubmission. A comment on a private item you no longer have access to must not remain visible to you afterward.

#### MV-MED-006 — Edit own comment within the edit window
- **Edition:** Free
- **Who:** Member who owns the comment (within window); Owner/Admin/Moderator for others (see moderation capability)
- **Where:** Edit control on your own comment, single-media page. `PUT /mvs/v1/media/{media_id}/comments/{comment_id}`.
- **Setup:** A comment you posted less than 15 minutes ago (default window).
- **Steps:** 1. Post a comment. 2. Immediately click Edit, change the text, save. 3. Wait past the window (or use a comment older than the window) and try again.
- **Expected:** Within the window: save succeeds, comment text updates in place. Past the window: edit is refused (403).
- **UX expectation:** Success shows "Comment updated." toast, no reload. A refusal past the window should surface as an error, not a silent no-op — and the Edit control ideally is not offered at all once the window has closed (verify in browser; if it's still shown, that's a UX gap to flag).
- **Settings that change it:** `mvs_comment_edit_window` (default 15 minutes; no field on any Settings tab; developers change it with code only).
- **Edge cases:** A member with `moderate_mvs_media` capability can delete (not edit) others' comments — see MV-MED-007.

#### MV-MED-007 — Delete a comment
- **Edition:** Free
- **Who:** Comment owner (any time, not just within the edit window); Moderator/Admin (capability `moderate_mvs_media`) for anyone's comment
- **Where:** Delete control on a comment. `DELETE /mvs/v1/media/{media_id}/comments/{comment_id}`.
- **Setup:** An existing comment.
- **Steps:** 1. Click Delete on your own comment. 2. Confirm. 3. As a moderator, delete another member's comment.
- **Expected:** Confirm dialog: "Delete this comment?" (Cancel focused by default per the shared confirm behaviour). Confirming removes the comment and any child replies.
- **UX expectation:** Success toast "Comment deleted."; failure shows the server's message or "Delete failed." List updates without a page reload. Delete control for someone else's comment is only rendered for a viewer whose `canModerateComments` flag is true — a plain member never sees a Delete affordance on comments that aren't theirs.
- **Settings that change it:** none.
- **Edge cases:** Deleting a comment with replies removes the whole subtree (per code comment); verify no orphaned reply is left visible.

#### MV-MED-008 — Share a media item
- **Edition:** Free
- **Who:** Visitor, Member (share works for anyone who can view the item — `permission_callback: __return_true`)
- **Where:** Share button, single-media page / lightbox. `POST /mvs/v1/media/{id}/share`.
- **Setup:** Any viewable media item.
- **Steps:** 1. Click Share. 2. Observe the OS share sheet if supported, or the clipboard-copy fallback.
- **Expected:** Tries `navigator.share()` first; if unsupported, falls back to `navigator.clipboard.writeText()` and shows a toast confirming the link was copied. The share count increments server-side either way.
- **UX expectation:** **Must never show a `window.prompt()` "Copy this link:" dialog** — this was a shipped bug removed during 1.2.0 QA and is an explicit regression lock. Toast confirms success; a copy failure shows an error toast telling the user to copy the URL manually, never a silent failure.
- **Settings that change it:** none.
- **Edge cases:** Share must work for a logged-out visitor on public content (no login gate on this action).

#### MV-MED-009 — Download a media item
- **Edition:** Free
- **Who:** Anyone who can view the item AND for whom downloads are enabled (site-wide + per-item)
- **Where:** Download button, single-media page / lightbox (and media-player block's `showDownload` attribute). Stat recorded via `POST /mvs/v1/media/{id}/download`; file served via the signed `/mvs/v1/serve` route.
- **Setup:** A public item with downloads allowed. A second item with the per-item "Allow download" turned off, for the negative case.
- **Steps:** 1. Click Download on an allowed item. 2. Repeat rapidly ~30+ times within a minute. 3. Click Download on a per-item-disabled item. 4. Turn off Settings > Display > "Allow Downloads" site-wide and retry any item.
- **Expected:** File downloads, `mvs_media_stats.downloads` increments once per click. Rate-limited at 30/minute/user — beyond that, refused with 429. Per-item disabled → 403 `mvs_download_blocked`. Site-wide off → button hidden everywhere and the endpoint answers 403 `mvs_downloads_disabled` even if called directly.
- **UX expectation:** The Download button itself is not rendered at all when the global setting is off or the per-item flag disables it (not shown-then-error). Rate-limit hit should surface as a clear "try again shortly" style message, not a silent failure.
- **Settings that change it:** `mvs_allow_downloads` (Settings > Display > "Allow Downloads", default on). Per-item `allow_download` field on the item (member/owner-editable via the edit modal, see MV-MED-011).
- **Edge cases:** Denied-privacy viewer gets 404 on download attempt, same as viewing.

#### MV-MED-010 — Fullscreen toggle
- **Edition:** Free
- **Who:** Visitor, Member (anyone viewing the media)
- **Where:** Fullscreen button on the lightbox/single-media image panel.
- **Setup:** Any image (or video/audio) item.
- **Steps:** 1. Click Fullscreen. 2. Press `F`. 3. Press `Esc`.
- **Expected:** Enters the browser's native Fullscreen API on the image panel; `F` key toggles it too. `Esc` exits fullscreen first (does not also close the lightbox in the same press).
- **UX expectation:** Toolbar buttons (reactions, favourite, share, etc.) remain operable while in fullscreen. A single `Esc` press only ever does one thing (un-maximise OR close), never both at once.
- **Settings that change it:** none.
- **Edge cases:** Fullscreen button carries an `aria-label`; keyboard-only users must be able to reach and trigger it.

#### MV-MED-011 — Edit own media (title/description/privacy/download toggle)
- **Edition:** Free
- **Who:** Owner (with `edit_mvs_medias` capability); Moderator/Admin with `edit_others_mvs_medias` for anyone's item
- **Where:** Edit control on the single-media page / dashboard card (cog icon, dashboard cards only — never shown on Explore/Album/Collection cards). `PUT /mvs/v1/media/{id}`.
- **Setup:** An item you own (or, as admin, any item).
- **Steps:** 1. Open the Edit modal on your own item. 2. Change title, description, privacy, and the "Allow download" toggle. 3. Save. 4. Try leaving Title empty and observe the Save button.
- **Expected:** Fields prefill from the item's current data. Save PUTs `title, description, slug, privacy, allow_download, tags[], categories[]` and the card/page updates live without reload. Empty title disables the Save button (client-side guard) rather than allowing a titleless save.
- **UX expectation:** ESC closes the modal without saving. A privacy CHANGE (not merely resubmitting the unchanged value) is refused with 403 `mvs_privacy_locked` when the member's privacy choice has been taken away (Settings > General > "Allow Users to Set Privacy" off) — someone with `manage_mvs_settings` is exempt from that lock. Save success updates the visible card/page in place; failure shows a toast, form stays open with entered values intact (no data loss on error).
- **Settings that change it:** `mvs_allow_user_privacy` gates whether a privacy CHANGE is accepted at all. `mvs_allow_downloads` (global) still overrides the per-item toggle when off.
- **Edge cases:** A photo currently inside an album cannot have its privacy changed independently — the album's privacy governs it while it's a member (see MV-ALB rules); changing privacy here while in an album should set the member's *own* choice aside for when it later leaves the album, not silently fail.

#### MV-MED-012 — Delete own media
- **Edition:** Free
- **Who:** Owner (with `delete_mvs_medias`); Moderator/Admin with `delete_others_mvs_medias`
- **Where:** Delete control on the single-media page / dashboard card. `DELETE /mvs/v1/media/{id}`.
- **Setup:** An item you own.
- **Steps:** 1. Click Delete. 2. Confirm in the dialog. 3. Verify it's gone from Explore, your dashboard, and any album it was in.
- **Expected:** Confirm dialog first (Cancel focused). Confirming removes the row from the index, its meta and stats, and the underlying file from disk; `mvs_media_deleted` fires.
- **UX expectation:** Success removes the card from every listing you're viewing without a full reload where technically feasible; on the single-media page itself, a redirect away makes sense after delete (verify in browser which happens). No way to trigger the delete twice from a double-click (the confirm gate itself prevents this).
- **Settings that change it:** none.
- **Edge cases:** Deleting an item that's inside an album must clean up the `mvs_album_items` row too (no dangling reference, no broken tile left in the album).

#### MV-MED-013 — Report a media item
- **Edition:** Free
- **Who:** Member (logged in); not shown to the item's owner; not shown to a logged-out visitor
- **Where:** Report control on the single-media page (only rendered for a logged-in non-owner when reporting is enabled). `POST /mvs/v1/media/{id}/report`.
- **Setup:** Ensure Settings > Moderation > "Member Reporting" is on (default on in Free — see Settings below; note the in-code comment near the Report button calling this "Pro only" is stale and contradicted by `ReportService::reports_enabled()`'s own default and by Free's own `Admin/ReportsPage.php` admin screen).
- **Steps:** 1. As a different member, open Report. 2. Pick a reason. 3. Submit.
- **Expected:** Reason picker offers: Spam, Harassment, Nudity or sexual content, Violence, Copyright infringement, Misinformation, Other. Submitting records the report (`mvs_reports` row) and, at the configured threshold (default 3 reports), auto-hides the item pending review.
- **UX expectation:** Success toast "Report submitted. Thank you."; reporting the same item twice shows an error/"already reported" message rather than filing a duplicate silently. The reason picker is rendered inside the same shared confirm-dialog component (Cancel-focused, Esc closes).
- **Settings that change it:** `mvs_enable_reports` (Settings > Moderation > "Member Reporting" / "Allow members to report media and members", default on). `mvs_report_auto_hide_threshold` (default 3, code/filter-level).
- **Edge cases:** Owner never sees the Report control on their own item (they see Edit/Delete there instead). Auto-hide after N reports is set in MediaVerse > Settings > Moderation > **Auto-Hide Threshold** (`mvs_report_auto_hide_threshold`, 0 disables).

#### MV-MED-014 — Lightbox — open from a grid card
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Any media card on Explore / profile / album / collection grids (when the block/shortcode's `showLightbox` attribute is on, default true).
- **Setup:** Any viewable media item in a grid.
- **Steps:** 1. Click a thumbnail on Explore. 2. Use the lightbox's next/prev navigation. 3. Close it.
- **Expected:** Opens an overlay with the image/video/audio and a sidebar of reactions, favourite, comments, share, download, report, edit/delete (per role) — the same actions as MV-MED-003 through MV-MED-013, in an overlay rather than a full page.
- **UX expectation:** State resets cleanly between items when navigating next/prev (no leftover comment-box draft or reaction highlight from the previous item). Keyboard: arrow keys navigate, Esc closes. `:focus-visible` outline is present on the lightbox's action/close/nav buttons for keyboard users.
- **Settings that change it:** the `showLightbox` attribute on the `media-grid` / `explore-feed` blocks (or shortcode default, always true) controls whether clicking opens a lightbox at all vs. navigating to the single page.
- **Edge cases:** Check byte-for-byte lightbox action parity with the single-media page (per Coding Rule 22's own historical bug: a lightbox clone that strips `data-wp-*` needs its own delegated handler per button — the Favourite button got one, Fullscreen did not for months). Test every lightbox action independently, don't assume single-media-page coverage implies lightbox coverage.

#### MV-MED-015 — Replace a file (keep metadata)
- **Edition:** Free
- **Who:** Owner (edit rights) or Admin/Moderator with `edit_others_mvs_medias`
- **Where:** `POST /mvs/v1/media/{id}/replace` Frontend: My Media dashboard > item > Edit modal, file-replace control (hidden file input).
- **Setup:** An existing item you can edit.
- **Steps:** 1. Open Edit. 2. Replace the underlying file. 3. Save.
- **Expected:** The file is swapped in place; the media ID, stats, reactions, view count and album membership all survive (only stale thumbnail/variant metadata is cleared and regenerated).
- **UX expectation:** No new "duplicate" item appears anywhere — same permalink, same lightbox entry, updated content.
- **Settings that change it:** same MIME/size/dangerous-extension guards as a fresh upload (see MV-UPL-004) apply identically here — no bypass via replace.
- **Edge cases:** Success toast "File replaced!"; failure toast "Replace failed.". The item keeps its id, URL, comments and view counts after a replace.

#### MV-MED-016 — Six-reaction accessibility
- **Edition:** Free
- **Who:** Member (any who can react)
- **Where:** Reaction bar, single-media page and lightbox.
- **Setup:** Any viewable item, screen reader or keyboard-only testing.
- **Steps:** 1. Tab through the reaction buttons. 2. Activate one with Enter/Space. 3. Inspect each button's accessible name.
- **Expected:** Each of the 6 reactions carries a sentence-form `aria-label` (not just the raw word), `aria-pressed` reflecting toggle state, and the emoji glyph itself is `aria-hidden`. The group wrapper carries `role="group" aria-label="Reactions"`.
- **UX expectation:** A screen-reader user must hear a real action name ("Love this", etc.), never just the bare emoji character or nothing.
- **Settings that change it:** none.
- **Edge cases:** This is a locked regression spec (a11y pass, 2026-05-03) — any drift here is a regression, not a stylistic choice.

#### MV-MED-017 — Report/Delete confirm keyboard behaviour
- **Edition:** Free
- **Who:** Member/Owner/Moderator (anyone who can trigger a destructive or reporting action)
- **Where:** Any confirm dialog raised from MED actions (delete comment, delete media, report media).
- **Setup:** Any of the above actions available to you.
- **Steps:** 1. Trigger a delete or report. 2. Note which button has focus. 3. Press Tab repeatedly. 4. Press Shift+Tab. 5. Press Esc.
- **Expected:** Cancel has initial focus (never the destructive button). Tab wraps forward within the dialog; Shift+Tab wraps backward. Esc closes and returns focus to the button that opened the dialog.
- **UX expectation:** This is a named regression lock elsewhere in the plugin ("Shift+Tab is the half people forget… drops focus into browser chrome") — test Shift+Tab explicitly, not just Tab.
- **Settings that change it:** none.
- **Edge cases:** none beyond the above.

#### MV-MED-018 — Denied-viewer page never leaks metadata via page source
- **Edition:** Free
- **Who:** Visitor / Member without access
- **Where:** `/media/<slug>/` for an item you cannot view.
- **Setup:** A `private` item belonging to someone else.
- **Steps:** 1. View page source of the denied page. 2. Check `<title>`, meta description, and any inline JSON.
- **Expected:** Page title reads "Log in to view" or the equivalent branded-404 title — never the real item title. No Open Graph tags at all (see MV-EXP-012).
- **UX expectation:** This must hold even for a search engine crawler or a "view source" check — it's a data-leak test, not a rendering nicety.
- **Settings that change it:** none.
- **Edge cases:** A document behaves differently — it always gets the plain 404, never the "log in to view" prompt, because a filename can carry a client's identity.

#### MV-MED-019 — Document single page (tiered preview)
- **Edition:** Free
- **Who:** Visitor/Member per the document's privacy; Owner/Admin
- **Where:** `/media/<slug>/` where the item's `media_type` is `document`. Requires Settings > General document master switch on (`mvs_documents_enabled`; Free has no admin toggle for it - the Documents switch is in Pro's settings).
- **Setup:** A public document uploaded (PDF).
- **Steps:** 1. Open a public document's permalink.
- **Expected:** Renders a preview inside the lightbox/page (not only a bare download link, since 2.5.0) plus a Download button, and a Share control for those permitted to grant it.
- **UX expectation:** A document with a missing underlying file shows "the file for this document is missing," never a blank/broken box. Documents master switch off → the whole page 404s (not a broken Download button on an otherwise-fine page).
- **Settings that change it:** the documents master switch (see MV-UPL note on documents being out of the Free media library — documents are ingested but the browsing "drive" is Pro).
- **Edge cases:** A document never appears in the Explore/media grid (positive type predicate on every media surface) — verify a document never shows as a broken tile anywhere in Explore, an album, or the admin All Media list.

#### MV-MED-020 — Comment moderation visibility
- **Edition:** Free
- **Who:** Plain member vs. Moderator (`moderate_mvs_media` capability) vs. Admin
- **Where:** Comment thread, single-media page.
- **Setup:** A comment from another member.
- **Steps:** 1. As a plain member, view someone else's comment — check for a Delete control. 2. As a moderator/admin, view the same comment.
- **Expected:** A plain member sees no Delete affordance on comments that aren't theirs. A moderator/admin sees Delete on every comment.
- **UX expectation:** The capability check is the SAME one the REST route enforces (`canModerateComments` context flag mirrors the server check) — there should be no case where the button is shown but the delete then 403s, or hidden but the delete would have succeeded.
- **Settings that change it:** the `moderate_mvs_media` capability, assigned per role (wp-admin role/capability management, not a MediaVerse-specific settings screen).
- **Edge cases:** none beyond MV-MED-007.

---

### Area: Upload (UPL)

#### MV-UPL-001 — Open the upload modal (FAB)
- **Edition:** Free
- **Who:** Member with `upload_mvs_media` capability (or `manage_options`); not offered to a logged-out Visitor
- **Where:** Floating action button (`.mvs-fab`) rendered in the footer on every MediaVerse page (Explore, dashboard, single media, album/tag archives). Opens the modal in `templates/partials/shared-ui-frame.php`, driven by `src/blocks/shared-ui/view.js`.
- **Setup:** Log in as a member whose role can upload.
- **Steps:** 1. Log in. 2. Click the FAB on Explore. 3. Log out and check the FAB is absent.
- **Expected:** Modal opens with photo/gallery/video/audio options. FAB itself is not rendered at all for a visitor or a member without upload rights (not shown-then-blocked).
- **UX expectation:** Modal opens with a visible dropzone + click-to-browse; closing via Esc or the close button discards any in-progress selection without submitting.
- **Settings that change it:** `mvs_upload_roles` (Settings > General > "Who can upload media") controls which roles get the `upload_mvs_media` capability.
- **Edge cases:** There is no "allow guest upload" setting anywhere — WordPress capabilities are inherently tied to a logged-in account, so guest upload is structurally impossible, not just gated by a toggle.

#### MV-UPL-002 — Upload a photo with title, description, tags
- **Edition:** Free
- **Who:** Member (uploader)
- **Where:** Upload modal. `POST /mvs/v1/media` (`MediaController::create_item`).
- **Setup:** A JPEG/PNG/GIF/WebP file under the size limit.
- **Steps:** 1. Open upload modal, choose Photo. 2. Select a file (dropzone or browse). 3. Expand "Add details". 4. Fill Title, Description, Tags (comma-separated) — try clicking a "Popular tags" pill too. 5. Submit.
- **Expected:** File uploads; item appears in Explore/dashboard with the entered title, description and tags attached; a popular-tag click appends that tag to the input without creating a duplicate.
- **UX expectation:** Upload shows progress/loading state while in flight; success closes the modal and shows a success toast/redirect, per-file thumbnail preview appears immediately on selection (before upload completes). A failed upload (bad type/too large) keeps the modal open with an error toast naming the reason, not a silent close.
- **Settings that change it:** `mvs_allowed_file_types` (Settings > General > "Allowed File Types"). `mvs_strip_exif` (Settings > General > "Remove location from photos", default on) strips GPS EXIF from the uploaded photo.
- **Edge cases:** "Popular tags" pill row is hidden entirely when no tags exist anywhere on the site yet.

#### MV-UPL-003 — Upload gallery (multiple images)
- **Edition:** Free
- **Who:** Member
- **Where:** Upload modal, "Gallery" option.
- **Setup:** 2-6 image files.
- **Steps:** 1. Choose Gallery. 2. Select multiple files. 3. Submit.
- **Expected:** All files upload as one group (single activity/action group), each becoming its own media item.
- **UX expectation:** Per-file thumbnail previews for every selected file, with a way to remove one before submitting (`removeUploadFile`). One combined success confirmation, not one toast per file.
- **Settings that change it:** same as MV-UPL-002.
- **Edge cases:** Mixing file types not valid for the chosen upload mode is rejected client-side with a toast naming how many files were skipped ("N file(s) skipped — upload one media type at a time.").

#### MV-UPL-004 — Upload video / audio
- **Edition:** Free
- **Who:** Member
- **Where:** Upload modal, "Video" / "Audio" options.
- **Setup:** An MP4/WebM video or MPEG/OGG audio file.
- **Steps:** 1. Choose Video (or Audio). 2. Select a file. 3. Submit.
- **Expected:** Uploads and appears with a generated poster (embedded cover frame, or a client-supplied frame) for video, or embedded/decorative artwork for audio.
- **UX expectation:** Video/audio types not on the allowed list are rejected client-side before any upload request is attempted ("N file(s) not allowed for {mode} upload.").
- **Settings that change it:** `mvs_allowed_file_types`.
- **Edge cases:** A posterless video must never render a blank/black tile — falls back to a bundled default poster image.

#### MV-UPL-005 — Upload rejected: oversize / disallowed type / empty file
- **Edition:** Free
- **Who:** Member
- **Where:** Upload modal / `POST /mvs/v1/media`.
- **Setup:** A file larger than "Max Upload Size", a file of a disallowed MIME (e.g. a renamed `.exe`), and a 0-byte file.
- **Steps:** 1. Try uploading each of the three in turn.
- **Expected:** All three refused before any DB row is created: empty file → `mvs_empty_file`; disallowed/hard-refused MIME (documents are always hard-refused into the media library, double-extension smuggling like `image.php.jpg` is caught) → `mvs_invalid_type`; oversize (measured server-side via `filesize()`, never trusting the client) → `mvs_file_too_large` naming the MB ceiling.
- **UX expectation:** Each refusal shows a specific, readable error message (not a generic "upload failed"); the modal stays open with other valid files (if any, in a gallery upload) unaffected.
- **Settings that change it:** `mvs_max_upload_size` (Settings > General > "Max Upload Size", default 100MB). `mvs_allowed_file_types`.
- **Edge cases:** A blocked-extension file (`.php`, `.phtml`, `.exe`, `.sh`, etc. — 24-entry blocklist) is refused regardless of what MIME type it claims to be; this is defense-in-depth on top of the MIME allowlist.

#### MV-UPL-006 — Duplicate detection
- **Edition:** Free
- **Who:** Member
- **Where:** Upload modal.
- **Setup:** Upload the same file twice (SHA-256 hash match).
- **Steps:** 1. Upload a file. 2. Upload the identical file again with "Duplicate Detection" set to "Warn". 3. Repeat with it set to "Skip" (block upload) and to "Allow".
- **Expected:** Warn: upload proceeds with a warning surfaced to the member. Skip: second upload is blocked (409-style refusal). Allow: no check at all, both copies exist.
- **UX expectation:** The warning (Warn mode) must be visibly different from a hard failure — the member should understand the file went through anyway.
- **Settings that change it:** `mvs_duplicate_action` (Settings > General > "Duplicate Detection": Warn/Skip/Allow, default Warn).
- **Edge cases:** none beyond the three modes above.

#### MV-UPL-007 — Set privacy and add to album while uploading
- **Edition:** Free
- **Who:** Member
- **Where:** Upload modal — Privacy select, "Add to album" select (existing albums + "+ Create new album…").
- **Setup:** At least one existing album to pick from, plus the create-new-album path.
- **Steps:** 1. Upload a file, choose a non-default privacy. 2. Pick an existing album from "Add to album". 3. Repeat, this time choosing "+ Create new album…" and typing a name.
- **Expected:** Item is created with the chosen privacy, then a follow-up `POST /albums/{id}/items` adds it to the chosen (or newly created) album — which then re-applies that album's privacy to the item per the 2.6.0 album rule (see MV-ALB-002), overriding the privacy you just picked if it differs.
- **UX expectation:** The Privacy select is not rendered at all when Settings > General > "Allow Users to Set Privacy" is off — the field silently disappears rather than showing and then being ignored. "Add to album" is a single-select (never multi) — structurally enforcing one-album-per-photo from the moment of upload.
- **Settings that change it:** `mvs_allow_user_privacy`, `mvs_default_privacy`.
- **Edge cases:** Choosing an album whose privacy is stricter than the one you picked silently wins (album governs) — the member should not be surprised that their explicit privacy choice was overridden; verify the UI communicates this (e.g. via the resulting item's shown privacy after save) rather than leaving it invisible.

#### MV-UPL-008 — Bulk actions on your own media
- **Edition:** Free
- **Who:** Member (own items only, dashboard multi-select); Admin/Moderator for others via wp-admin
- **Where:** My Media dashboard, multi-select toolbar. `POST /mvs/v1/media/bulk` (`action`: `delete` | `move_to_album` | `change_privacy` | `add_tags`, max 100 IDs per call).
- **Setup:** Several of your own media items in the dashboard's Media tab.
- **Steps:** 1. Select multiple items via checkboxes. 2. Choose "Move to album". 3. Repeat with "Change privacy" and "Add tags". 4. Try selecting 0 items and submitting.
- **Expected:** Each bulk action applies to every selected item in one call; a success notice states the count affected.
- **UX expectation:** Submitting with zero items selected shows a friendly error and makes no request — never a silent no-op or a call with an empty ID list. No destructive bulk action (delete) skips the shared confirm dialog.
- **Settings that change it:** none.
- **Edge cases:** A bulk "move to album" against a playlist-type album must skip any non-audio item in the selection (server-side per-item check), not fail the whole batch.

---

### Area: Albums (ALB)

#### MV-ALB-001 — Create an album
- **Edition:** Free
- **Who:** Member
- **Where:** My Media dashboard > Albums tab, "Create album" (opens the album modal in `dashboard-view.js`). `POST /mvs/v1/albums`.
- **Setup:** None; a title is required.
- **Steps:** 1. Open the Albums tab. 2. Click Create. 3. Type a title (required), optional description, choose privacy. 4. Optionally select existing media from the picker to seed the album. 5. Save.
- **Expected:** Album created; appears in the Albums tab and at its own permalink `/album/<slug>/`. If you're a member of a BuddyPress group and associate the album with it, its privacy is forced to `group`.
- **UX expectation:** Save is disabled/blocked with a clear message if Title is empty (client-side validation before the request, and the server itself 400s an empty title, which the JS surfaces rather than reporting a false "Album created!"). Success toast "Album saved!" (or similar); new album appears without a full page reload.
- **Settings that change it:** if `mvs_allow_user_privacy` is off, the album's privacy is forced to the site default regardless of what's picked.
- **Edge cases:** Picker media list is paginated (48/page) with "Load more" — verify a member with 100+ items can still find and select an older photo (a fixed `per_page=100` cutoff used to hide anything past the first 100).

#### MV-ALB-002 — Album privacy governs its photos, both ways (2.6.0 rule)
- **Edition:** Free
- **Who:** Member (album owner)
- **Where:** Album edit modal, privacy select. `PUT /mvs/v1/albums/{id}`.
- **Setup:** An album containing at least one photo whose own privacy differs from the album's current privacy (e.g. album is Public, one photo was uploaded as Private and set aside).
- **Steps:** 1. Change the album's privacy from Public to Members Only, save. 2. Verify every photo in the album now shows as Members Only wherever it appears (Explore, its own permalink, profile grid). 3. Change the album back to Public. 4. Verify a photo that was individually set to Private BEFORE joining the album returns to Private once it leaves the album (see MV-ALB-004) — not before.
- **Expected:** `AlbumService::apply_album_privacy()` pushes the album's privacy onto every member photo in BOTH directions (tightening AND loosening) — this is new in 2.6.0; before it was a one-way clamp that could leave photos private forever after an album was re-published. Each photo's own chosen privacy is kept aside (`own_privacy` meta) the first time the album overrides it.
- **UX expectation:** See MV-ALB-003 for the confirm dialog that fires when this change would make photos MORE visible than the member set them to.
- **Settings that change it:** filter `mvs_album_inherit_privacy` (code-level escape hatch, not a UI setting) turns the whole cascade off, restoring pre-2.3.0 independent album/photo privacy.
- **Edge cases:** A member locked out of choosing privacy (`mvs_allow_user_privacy` off) cannot trigger this cascade at all — the album-privacy write is refused for them the same as any other privacy change.

#### MV-ALB-003 — Confirm dialog before widening album privacy
- **Edition:** Free
- **Who:** Member (album owner/editor)
- **Where:** Album edit modal, on Save.
- **Setup:** An album whose members include at least one photo the member set to a stricter privacy than the album's new target (e.g. album going from Private to Public with one photo that was individually Members Only).
- **Steps:** 1. Edit the album, change privacy to something more open. 2. Click Save.
- **Expected:** Before saving, the client calls `GET /albums/{id}` for `own_privacy_counts` and counts any photo whose own privacy is stricter than the new target (plus any newly-added photo that's stricter). If the count is > 0, a confirm dialog appears instead of saving immediately: *"N photo(s) are set to be more private than "{privacy label}". While they are in this album, they will show as "{privacy label}"."* with a "Save anyway" button (not the generic "Confirm").
- **UX expectation:** Cancel is focused by default (per the shared confirm-dialog behaviour) so an accidental Enter does not silently widen anyone's photos. Confirming ("Save anyway") proceeds with the save; Cancel/Esc leaves the album's privacy unchanged. Only fires when the change WIDENS visibility — tightening privacy never prompts.
- **Settings that change it:** none (this is a fixed UX rule, not a toggle).
- **Edge cases:** This confirmation is **client-side only** — `AlbumController::update_item()` accepts the privacy change unconditionally once past the `mvs_privacy_locked` ownership check. A tester driving the REST API directly (e.g. a QA script, or a future native app) can widen an album's privacy with no prompt at all; this is a known gap in the code, not something to "fix" in testing, but worth flagging if the app/API surface is in scope.

#### MV-ALB-004 — One album per photo
- **Edition:** Free
- **Who:** Member
- **Where:** Album picker (upload modal's "Add to album" select, or the album edit modal's media picker).
- **Setup:** A photo already in Album A.
- **Steps:** 1. Edit Album B and add the same photo (already in Album A) to it. 2. Save. 3. Check Album A — the photo should no longer be there.
- **Expected:** Joining Album B removes the photo from every other album it was in (server-side `DELETE … WHERE media_id IN (...) AND album_id <> {this album}`), enforcing exactly one album per photo. Success toast reports how many photos were moved from other albums.
- **UX expectation:** The picker shows a visible note/conflict indicator on a photo that's already in a different album (so the member isn't surprised it disappears from the old album), rather than silently allowing the pick with no signal.
- **Settings that change it:** none — this is structural, not configurable.
- **Edge cases:** Re-adding a photo to the SAME album it's already in is a no-op (fails the unique-key insert silently, does not re-trigger the privacy cascade or the "moved" count).

#### MV-ALB-005 — Own privacy restored on leaving an album
- **Edition:** Free
- **Who:** Member
- **Where:** Album edit modal (remove item) or album delete.
- **Setup:** A photo currently showing the album's privacy (per MV-ALB-002) whose own chosen privacy differs.
- **Steps:** 1. Remove the photo from the album (or delete the whole album). 2. Check the photo's privacy on its own single-media page / edit modal.
- **Expected:** The photo's privacy reverts to what the member originally chose before the album ever overrode it (`restore_own_privacy()`), not to the album's last privacy or to the site default.
- **UX expectation:** No visible action needed from the member beyond removing/deleting — the restore happens automatically and silently (no separate confirm, since it's a release of a constraint, not a widening of one).
- **Settings that change it:** none.
- **Edge cases:** A photo that has never been in any album has no `own_privacy` meta to restore — untouched by this at all.

#### MV-ALB-006 — Set album cover
- **Edition:** Free
- **Who:** Member (album owner/editor)
- **Where:** Album edit modal, "Set cover" on a picker item. `PUT /mvs/v1/albums/{id}/cover`.
- **Setup:** An album with at least one image item and one video/audio item.
- **Steps:** 1. Click "Set cover" on an image item, save. 2. Try setting cover on a video/audio item.
- **Expected:** Image cover is accepted. Video/audio/document rejected as a cover (image-only). Cover is auto-added to the album if not already a member.
- **UX expectation:** Deselecting the current cover photo from the album (removing it from selection) clears the cover selection client-side, preventing a save that would 400 with `mvs_cover_not_in_album`.
- **Settings that change it:** none.
- **Edge cases:** Cover resolution order when nothing is explicitly pinned: first image item → first item of any renderable type → no cover shown.

#### MV-ALB-007 — Reorder album items
- **Edition:** Free
- **Who:** Member (album owner/editor)
- **Where:** Album edit view, drag-to-reorder. `PUT /mvs/v1/albums/{id}/reorder`.
- **Setup:** An album with 3+ items.
- **Steps:** 1. Drag an item to a new position. 2. Reload the album page.
- **Expected:** New order persists (`position` column updated atomically, no gaps).
- **UX expectation:** Reorder should feel immediate (optimistic UI); a failed save should revert the order rather than leaving the UI and server disagreeing.
- **Settings that change it:** none.
- **Edge cases:** none beyond persistence across reload.

#### MV-ALB-008 — Remove item from album / delete album
- **Edition:** Free
- **Who:** Member (owner/editor)
- **Where:** Album edit modal ("Delete album") or per-item remove. `DELETE /mvs/v1/albums/{id}` and `DELETE /mvs/v1/albums/{id}/items/{media_id}`.
- **Setup:** An existing album with items.
- **Steps:** 1. Remove one item. 2. Delete the whole album.
- **Expected:** Removing an item releases it (own privacy restored, MV-ALB-005) without touching the underlying media item. Deleting the album (`wp_delete_post`) releases ALL its items the same way, then removes the album post itself; the underlying photos remain in the media library, not deleted.
- **UX expectation:** Delete-album goes through the shared confirm dialog (Cancel focused). Success removes the album from every listing without a full reload where feasible.
- **Settings that change it:** none.
- **Edge cases:** none beyond the release behaviour already covered.

#### MV-ALB-009 — Album single page
- **Edition:** Free
- **Who:** Visitor / Member per the album's privacy; Owner/Admin
- **Where:** `/album/<slug>/`. Template `templates/album.php`. Privacy is gated at `template_redirect@5`, before the theme header renders (so a denied viewer's document `<title>` never leaks the album name).
- **Setup:** A public album and a members-only/private one.
- **Steps:** 1. View the public album logged out. 2. View the members-only album logged out. 3. Log in as a member and revisit.
- **Expected:** Public: cover + items visible to everyone. Members-only/private denied: branded 404, no album name/title leaked anywhere in the page (not even the tab title).
- **UX expectation:** Public album with zero items (all removed) shows "no items in album", not an error. Only the items the CURRENT viewer is entitled to see are shown (`AlbumService::viewable_item_ids()`) — a public album containing one private photo of the owner's must not show that photo to a stranger.
- **Settings that change it:** none.
- **Edge cases:** A member's own upload widget on the album page itself (owner-only "add more photos here" dropzone, per the album template) must not be shown to a non-owner.

#### MV-ALB-010 — Playlist-type album (audio only)
- **Edition:** Free
- **Who:** Member
- **Where:** Album creation, album type = playlist.
- **Setup:** A mix of audio and non-audio (image/video) items in the picker.
- **Steps:** 1. Create/edit an album, set its type to playlist. 2. Try adding a non-audio item.
- **Expected:** Non-audio items are rejected on add (server-side `file_type` check); only audio items are accepted.
- **UX expectation:** A rejected item in a bulk "add" operation should not silently vanish from the count with no explanation — verify the picker or the add response communicates which items were skipped and why.
- **Settings that change it:** none (album type is a per-album choice, not a site setting).
- **Edge cases:** The member-facing create modal has NO type choice in 2.6.0; a playlist album can only be created through REST (`type=playlist`). A playlist album still renders correctly on its album page. Treat the missing UI as a known gap, not a test failure.

#### MV-ALB-011 — Album privacy lock (owner turned off member privacy choice)
- **Edition:** Free
- **Who:** Member without privacy rights; Admin (`manage_mvs_settings`) is exempt
- **Where:** Album edit modal, privacy select. `PUT /mvs/v1/albums/{id}`.
- **Setup:** Settings > General > "Allow Users to Set Privacy" turned OFF.
- **Steps:** 1. As a plain member, try to change an album's privacy to a different value. 2. Resubmit the SAME (unchanged) privacy value. 3. As an admin, try changing it.
- **Expected:** A genuine CHANGE is refused with 403 `mvs_privacy_locked` for the plain member. Resubmitting the same value still "succeeds" (no-op save). Admin can change it regardless.
- **UX expectation:** The privacy select ideally isn't offered to change at all for a locked-out member (disabled/hidden) rather than being interactable and then silently failing — verify in browser which behaviour actually ships.
- **Settings that change it:** `mvs_allow_user_privacy`.
- **Edge cases:** none beyond the above.

#### MV-ALB-012 — Album archive & taxonomy interplay
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** `/album/` archive (see MV-EXP-011) and album cards on the dashboard.
- **Setup:** Multiple albums with mixed privacy.
- **Steps:** 1. View `/album/` logged out. 2. View the dashboard Albums tab logged in as the owner.
- **Expected:** Public archive lists only public albums to a visitor. The dashboard Albums tab lists ALL of the current member's own albums regardless of privacy (it's "my albums", not "public albums").
- **UX expectation:** Sort/filter toolbar on the dashboard Albums tab (same shared toolbar component as Explore) — "Sort by" Newest/Oldest/Most viewed plus a count.
- **Settings that change it:** `mvs_items_per_page` affects pagination size in both places.
- **Edge cases:** none beyond MV-ALB-009.

---

### Area: Collections (COL)

#### MV-COL-001 — Create a smart (rule-based) collection
- **Edition:** Free
- **Who:** Member
- **Where:** My Media dashboard > Collections tab, "Create" (defaults to type Smart in the create modal). `POST /mvs/v1/collections`; rules via `PUT /mvs/v1/collections/{id}/rules`.
- **Setup:** Media items with varied tags/categories/authors/dates to build a meaningful rule against.
- **Steps:** 1. Open Collections tab, click Create. 2. Leave type as "Smart". 3. Add a rule (e.g. tag = "sunset"). 4. Set privacy (Public or Members Only — only two choices). 5. Save.
- **Expected:** Collection created; its contents are computed live from the rule (`CollectionService::resolve()`) against `media_type`, `tag`, `category`, `author`, `date_after`, `date_before`, `privacy` — not a fixed membership list.
- **UX expectation:** A live/preview match count updates as rules are edited (`previewCount`), so the member can see roughly how many items the rule currently matches before saving.
- **Settings that change it:** none dedicated; privacy choices are hard-limited to the two Collection levels (see MV-COL-002).
- **Edge cases:** A rule that matches zero items shows "no items match rules" on the collection's own page, not an error.

#### MV-COL-002 — Collection privacy is only Public or Members Only
- **Edition:** Free
- **Who:** Member
- **Where:** Collection create/edit modal, privacy select.
- **Setup:** none.
- **Steps:** 1. Open the privacy select on a collection. 2. Compare the options against an album's privacy select.
- **Expected:** Only two choices — Public and Members Only — deliberately narrower than an album's 4+ levels (owner decision: "a collection is a curated surface made to be shown"). Any legacy/other stored value coerces back to Public when read.
- **UX expectation:** No "Private" or "Friends" option should appear on a collection at all — if it does, that's a regression against this explicit product decision.
- **Settings that change it:** none.
- **Edge cases:** none beyond the coercion behaviour above.

#### MV-COL-003 — Collection privacy never cascades to contained items
- **Edition:** Free
- **Who:** Member (collection curator)
- **Where:** Collection edit modal.
- **Setup:** A collection (smart or manual) containing at least one item that ISN'T the curator's own.
- **Steps:** 1. Change the collection's privacy. 2. Check the privacy of the contained items themselves.
- **Expected:** Unlike an album (MV-ALB-002), a collection's privacy change never rewrites the privacy of the media it contains — a curator changing a collection they own must not silently alter a stranger's photo.
- **UX expectation:** No confirm dialog fires here (there's nothing being widened on anyone else's item) — this is the deliberate opposite of MV-ALB-003.
- **Settings that change it:** none.
- **Edge cases:** Only public media, or the curator's own media, can be gathered into a collection at all (`CollectionService::may_contain()`); a member-only photo of someone else's cannot be added by a non-owner curator, and documents can never join a collection.

#### MV-COL-004 — Favourites is your automatic personal collection
- **Edition:** Free
- **Who:** Member
- **Where:** My Media dashboard > Collections tab > "Favorites" card (not on the main rail since 2.6.0 — reached via this card or the direct URL `/my-media/favorites/`).
- **Setup:** Favourite a few items (see MV-MED-004).
- **Steps:** 1. Favourite 2-3 items across different pages. 2. Open the Favorites card from the Collections panel.
- **Expected:** Every member has exactly one automatic, always-private Favorites collection that cannot be deleted (`DELETE` on it is refused with `mvs_favorites_collection_locked`) and never carries the "Private:" title prefix other private collections might.
- **UX expectation:** Un-favouriting an item elsewhere removes it from this list live (or on next visit) — no separate "remove from collection" action needed for Favorites specifically.
- **Settings that change it:** `orderby` supports `favorited` (default), `title`, `date` via the listing's sort control.
- **Edge cases:** none beyond MV-MED-004.

#### MV-COL-005 — Manual "Save to a collection" is gated off in stock Free
- **Edition:** Free
- **Who:** Member
- **Where:** Any consumer that would render a "Save to a collection" picker — the lightbox's Save action, `templates/media-single.php`, the upload modal, and the dashboard's Collections panel intro copy.
- **Setup:** None beyond having more than one collection.
- **Steps:** 1. Open the Save/Favourite action on any media item. 2. Look for an option to choose WHICH collection (beyond the default Favorites) to save into.
- **Expected:** In stock Free, `apply_filters('mvs_collections_enabled', false)` defaults FALSE everywhere it's checked — there is no multi-collection picker; "Save" is simply the Favourite on/off toggle from MV-MED-004. The Collections dashboard tab intro copy itself changes wording depending on this filter ("gathered by rules you set" vs. a Save-button-implying phrase), confirming Free's copy never promises a manual Save.
- **UX expectation:** This must be a CONSISTENT absence — the Save/Favourite action should look and behave identically everywhere in Free, never appearing as a multi-collection picker on one surface and a plain toggle on another.
- **Settings that change it:** the `mvs_collections_enabled` filter (code-level only; no wp-admin UI toggle was found for it in Free).
- **Edge cases:** A member can still create a "manual" type collection via the Collections tab's create modal (`collectionType: 'manual'` is selectable), but since there is no per-item Save-to-collection UI in Free, a manual collection beyond the automatic Favorites one has no member-facing way to be filled — flag this as a real gap if reproduced, not a misunderstanding: Open question for the product owner: the create modal's manual option should even be offered on Free-only, since nothing in Free can fill it.

#### MV-COL-006 — Collection single page
- **Edition:** Free
- **Who:** Visitor, Member per privacy; Owner
- **Where:** `/collection/<slug>/`. Template `templates/collection.php`; also `[mvs_collection id="…"]` shortcode.
- **Setup:** A public collection and a members-only one.
- **Steps:** 1. View the public collection logged out. 2. View the members-only one logged out.
- **Expected:** Public renders its (rule-matched or Favourited) items. Members-only denies a logged-out visitor (container privacy gate — added 2.5.1 to the shortcode specifically, since it previously rendered members-only contents to anyone).
- **UX expectation:** A rule that resolves to zero items shows "no items match rules"; a manual collection with zero saved items shows an equivalent empty state — these are two different empty-state causes and ideally read differently to the member (rules vs. nothing saved yet).
- **Settings that change it:** `mvs_items_per_page` for pagination.
- **Edge cases:** The `[mvs_collection]` shortcode on an arbitrary page respects the same privacy gate as the dedicated single page — verify a shortcode embed of a members-only collection on a totally different, publicly-cached page still denies a logged-out visitor.

---

### Area: Favourites (FAV)

#### MV-FAV-001 — Toggle favourite from a grid card
- **Edition:** Free
- **Who:** Member (logged in)
- **Where:** Media card favourite icon, anywhere media cards render (Explore, profile, album, collection grids).
- **Setup:** Any viewable item.
- **Steps:** 1. Click the favourite icon on a card without opening the lightbox. 2. Click it again.
- **Expected:** Same toggle as MV-MED-004, reachable without opening the item at all.
- **UX expectation:** Icon state updates immediately on the card itself; no need to open the item to see the change reflected.
- **Settings that change it:** none.
- **Edge cases:** none beyond MV-MED-004.

#### MV-FAV-002 — Favorites tab listing, search/sort
- **Edition:** Free
- **Who:** Member
- **Where:** My Media dashboard, Favorites (`GET /me/favorites`, args `collection_id`, `per_page`, `page`, `s`, `orderby`, `order`).
- **Setup:** 10+ favourited items across different titles/dates.
- **Steps:** 1. Open the Favorites panel. 2. Search by title. 3. Sort by Title / Date / Favourited (default).
- **Expected:** List narrows/reorders correctly for each control.
- **UX expectation:** Empty state ("nothing favourited yet") is distinct from a filtered-to-zero search result ("no results for {term}").
- **Settings that change it:** `mvs_items_per_page` for page size.
- **Edge cases:** Un-favouriting an item from within this very list should remove it from the list immediately, not require a refresh.

#### MV-FAV-003 — Favouriting respects privacy and collection membership rules
- **Edition:** Free
- **Who:** Member
- **Where:** Favourite toggle on a members-only item you can view but don't own.
- **Setup:** A members-only item belonging to someone else.
- **Steps:** 1. Favourite it while logged in as an eligible member. 2. Try favouriting a private item you cannot view (e.g. via a direct API call if testing the API surface).
- **Expected:** Favouriting a viewable item succeeds. Favouriting an item you cannot view is refused (`PrivacyService::can_view()` gate) — the icon should not even be interactive-looking on a page you were denied.
- **UX expectation:** none beyond the standard toggle feedback.
- **Settings that change it:** none.
- **Edge cases:** none beyond MV-COL-003's "may_contain" rule if ever targeting a non-Favorites collection.

#### MV-FAV-004 — Un-favourite notification does not fire
- **Edition:** Free
- **Who:** Member
- **Where:** Notification bell after un-favouriting something you'd previously favourited that generated a `media_favorite` notification for the owner.
- **Setup:** Member A favourites Member B's item (B gets a notification). Member A then un-favourites it.
- **Expected:** Only the initial favourite creates a `media_favorite` notification for the owner; un-favouriting fires no notification (nor does it retract the earlier one).
- **UX expectation:** none beyond confirming no duplicate/erroneous notification appears.
- **Settings that change it:** none.
- **Edge cases:** none.

---

### Area: Profiles / Follow / Block (PRF)

#### MV-PRF-001 — View a member's profile
- **Edition:** Free
- **Who:** Visitor, Member, the Owner themself
- **Where:** `/media/@<username>/`. Renders via `templates/explore.php` in profile mode (Free ships no dedicated `user-profile.php`; that file is a Pro/theme-override point only). A pre-fix login-based URL still resolves (back-compat lookup).
- **Setup:** A member with at least one public upload.
- **Steps:** 1. Visit a member's profile logged out. 2. Log in as a different member and revisit. 3. Log in as the profile owner and visit your own profile.
- **Expected:** Header (avatar, display name, follower/following counts) + grid of the profile owner's viewable media, privacy-filtered per viewer. An unknown username shows the branded 404 for "profile", not a generic 404.
- **UX expectation:** "@user hasn't uploaded yet" empty state when the grid is empty for this viewer (whether because there's truly nothing, or because everything is hidden from this particular viewer — same friendly message either way, never an error). No login is required just to VIEW a profile (unlike the Members Only setting, which is a separate site-wide gate — see MV-ACC-001).
- **Settings that change it:** none directly.
- **Edge cases:** `/media/@user/page/2/` must paginate correctly and answer HTTP 200 (not soft-404).

#### MV-PRF-002 — Follow / unfollow action row hides for the owner and for logged-out visitors
- **Edition:** Free
- **Who:** Member viewing someone ELSE's profile; hidden for the Owner and for a logged-out Visitor
- **Where:** `templates/partials/profile-actions.php`, included on the profile page.
- **Setup:** none.
- **Steps:** 1. View your own profile — check for Follow/Message/Report/Block buttons. 2. Log out and view any profile — check the same. 3. Log in as a different member and view someone else's profile.
- **Expected:** Case 1 and 2: zero action buttons rendered. Case 3: Follow button (and, if messaging/reports are enabled, Message and an overflow "More actions" menu with Report/Block) appears.
- **UX expectation:** none beyond the presence/absence check itself.
- **Settings that change it:** Messaging master switch and DM access level affect whether "Message" appears (see MV-MSG entries); `mvs_enable_reports` affects whether "Report" appears in the overflow menu.
- **Edge cases:** none.

#### MV-PRF-003 — Follow a member
- **Edition:** Free
- **Who:** Member (logged in, not blocked by the target)
- **Where:** "Follow" button on a profile page. `POST /mvs/v1/users/{id}/follow`.
- **Setup:** Two member accounts.
- **Steps:** 1. As Member A, visit Member B's profile. 2. Click Follow.
- **Expected:** Button flips to "Following"; follower/following counts on the profile header update from the response (no full reload). Member B receives a `new_follower` in-app notification ("%s started following you").
- **UX expectation:** Counts update from the SAME response that confirmed the follow — no separate re-fetch needed for the numbers to be correct.
- **Settings that change it:** none.
- **Edge cases:** Following someone who has blocked you (or whom you've blocked) is refused (`RestGuards::deny_if_blocked()`).

#### MV-PRF-004 — Unfollow a member
- **Edition:** Free
- **Who:** Member (logged in)
- **Where:** "Following" button (now acting as unfollow) on a profile page. `DELETE /mvs/v1/users/{id}/follow`.
- **Setup:** Already following the target.
- **Steps:** 1. Click "Following" to unfollow.
- **Expected:** Button reverts to "Follow"; counts update. No notification is generated for an unfollow.
- **UX expectation:** Unfollow is deliberately never blocked by a block relationship (it's one of the plugin's explicit "safety valve" actions that always succeeds) — verify a blocked-either-way pair can still unfollow if they'd somehow followed before the block.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-PRF-005 — View followers / following lists
- **Edition:** Free
- **Who:** Visitor, Member (public lists); Member viewing their own (`/me/following`, `/me/followers`)
- **Where:** `GET /mvs/v1/users/{id}/followers`, `GET /mvs/v1/users/{id}/following` (public); own via `/me/following`, `/me/followers`. Frontend surface: `templates/partials/follows-modal.php`.
- **Setup:** A member with a few followers/follows.
- **Steps:** 1. Open the followers/following modal from a profile. 2. Paginate if there are many.
- **Expected:** Paginated list of member cards (`X-WP-Total` header backs pagination).
- **UX expectation:** Empty state ("no followers yet") rather than a blank modal.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-PRF-006 — Block a member
- **Edition:** Free
- **Who:** Member (logged in), from the "More actions" overflow menu on someone else's profile
- **Where:** `.mvs-block-toggle` inside `.mvs-actions-menu`, profile page. `POST /mvs/v1/users/{id}/block`.
- **Setup:** Two member accounts, one with some public content.
- **Steps:** 1. As Member A, open the overflow menu on Member B's profile. 2. Click Block. 3. As Member B, try to view Member A's public media, follow them, comment on their media, or message them.
- **Expected:** Block succeeds (rate-limited 10/60s). Blocking is ONE-DIRECTIONAL by design: Member A (the blocker) keeps full access to Member B's public content; Member B (the one blocked) loses access to Member A's content everywhere (single media page, REST, profile grid, lightbox, thumbnails, downloads) and cannot follow, comment, react, favourite, share, or message Member A.
- **UX expectation:** Menu item label flips to "Unblock" once blocked, with the button's `aria-label` updated to match ("Unblock this member" / "Block this member"). No confirm dialog is used for Block itself (only Report and destructive deletes use the shared confirm) — Check this in the browser.
- **Settings that change it:** none.
- **Edge cases:** Un-reacting, declining a conversation, unfollowing, and account deletion are explicit exceptions that remain allowed even between a blocked-either-way pair — these should keep working as "retractions" even after a block.

#### MV-PRF-007 — Unblock a member
- **Edition:** Free
- **Who:** Member (logged in)
- **Where:** Same overflow menu, now showing "Unblock". `DELETE /mvs/v1/users/{id}/block`.
- **Setup:** Already blocked someone.
- **Steps:** 1. Click Unblock.
- **Expected:** Reciprocal access is restored (subject to any independent privacy settings — e.g. if the other member's content was never public, unblocking doesn't change that).
- **UX expectation:** none beyond the label flipping back.
- **Settings that change it:** none.
- **Edge cases:** There is NO admin surface to see, audit, or undo a block from wp-admin — a site owner cannot help a member who blocked someone by mistake except by asking the member to unblock themselves.

#### MV-PRF-008 — Blocked list
- **Edition:** Free
- **Who:** Member (own list only)
- **Where:** `GET /mvs/v1/me/blocked`. Frontend surface: `templates/partials/blocked-members.php` (shown in the Edit profile screen and the dashboard's Edit profile section - one shared list).
- **Setup:** Have blocked 1+ members.
- **Steps:** 1. Open your blocked-members list.
- **Expected:** Shows every member you've blocked, with an unblock action per row.
- **UX expectation:** Empty state when you've blocked no one.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-PRF-009 — Report a member (Pro-gated in Free)
- **Edition:** Free — **NOT functional in stock Free**
- **Who:** N/A on Free-only
- **Where:** Overflow menu's "Report" item on a profile page. `POST /mvs/v1/users/{id}/report`.
- **Setup:** none.
- **Steps:** 1. Look for a "Report" item in the profile overflow menu on Free-only.
- **Expected:** Member reporting (reporting a PERSON, as opposed to reporting a media item — see MV-MED-013, which IS available in Free) is a Pro feature — the code comment states the write 403s in Free unless a filter is set true, and the menu item's own visibility check (`ReportService::reports_enabled()`) gates whether it's even offered. Check in the browser on a real Free-only install whether the item is hidden entirely or shown-then-403s.
- **UX expectation:** If shown at all on Free, it must not silently fail — a 403 should surface as an error toast, not nothing.
- **Settings that change it:** none in Free's own UI.
- **Edge cases:** This is distinct from reporting a media ITEM, which works fully in Free (MV-MED-013) — don't conflate the two when triaging a bounce.

#### MV-PRF-010 — Edit profile (redirects to dashboard since 2.6.0)
- **Edition:** Free
- **Who:** Member (own profile only)
- **Where:** `/media/edit-profile/` — since 2.6.0, 302-redirects to the My Media dashboard's "Edit profile" panel (`/my-media/profile/`, or the dashboard's mapped page + `/profile/`) whenever that dashboard section exists. `PUT /mvs/v1/me/profile`.
- **Setup:** Log in as any member.
- **Steps:** 1. Visit `/media/edit-profile/` directly while logged in. 2. Observe the redirect. 3. Visit while logged out.
- **Expected:** Logged in: 302 to the dashboard's Edit profile panel. Logged out: redirected to login with a return-to back to the edit-profile URL.
- **UX expectation:** The redirect should be transparent — landing directly on a working, prefilled edit form, not an intermediate blank page.
- **Settings that change it:** filter `mvs_profile_edit_redirect` (default true, code-level) can restore the pre-2.6.0 standalone-page behaviour.
- **Edge cases:** If no My Media dashboard page exists yet (fresh install edge case), the standalone `templates/profile-edit.php` page should still render rather than redirecting nowhere.

#### MV-PRF-011 — Edit profile fields
- **Edition:** Free
- **Who:** Member (own profile)
- **Where:** Dashboard "Edit profile" panel (or the standalone page). `PUT /mvs/v1/me/profile`.
- **Setup:** none.
- **Steps:** 1. Change First name, Last name, Display name, Bio. 2. Change "Who can message you" (Everyone/Followers/Mutual/Nobody). 3. Change "Show your online status" (Yes/No). 4. Change "Email me about activity" (Yes/No). 5. Save.
- **Expected:** All fields save and reflect immediately; `dm_access`/`online_status`/`email_activity` affect messaging and email behaviour described in their respective areas.
- **UX expectation:** Save button shows a "Saving…" state; success/error messages render inline in the form (`.mvs-profile-message--success` / `--error`), not as a toast — verify this specific-to-this-form pattern in the browser.
- **Settings that change it:** none of these are site settings — they're per-member choices. First/last/display name fields are hidden entirely when a community plugin (e.g. BuddyPress/BuddyNext) already owns those fields ("Fields a community plugin owns are edited there, not here").
- **Edge cases:** none beyond the community-profile-fields deferral above.

#### MV-PRF-012 — Avatar upload / remove
- **Edition:** Free
- **Who:** Member (own avatar)
- **Where:** Edit profile panel, avatar section. `POST`/`DELETE /mvs/v1/me/avatar`.
- **Setup:** A JPEG/PNG/GIF/WebP image under 2MB.
- **Steps:** 1. Click "Change Avatar", pick a file over 2MB or of a disallowed type. 2. Pick a valid file. 3. Click "Remove (use Gravatar)".
- **Expected:** Valid upload replaces the avatar immediately in the preview. Oversize/invalid type refused. Remove reverts to the Gravatar fallback and hides the Remove button itself (only shown while a custom avatar exists).
- **UX expectation:** "Uploading…" label swap while in flight (button text toggles). No stale preview left showing after a successful remove.
- **Settings that change it:** none (2MB / allowed-types are hardcoded for the avatar upload path, not admin-configurable).
- **Edge cases:** none.

---

### Area: My Media Dashboard (DSH)

#### MV-DSH-001 — Dashboard access and rail
- **Edition:** Free
- **Who:** Member (own dashboard); Visitor is gated to login
- **Where:** The page mapped as "My Media page" (`mvs_page_dashboard`, Settings > General > Pages), or `[mvs_dashboard]` shortcode on any page. Template `templates/partials/dashboard-content.php`.
- **Setup:** Log in as a member.
- **Steps:** 1. Visit the My Media page logged out. 2. Log in and revisit.
- **Expected:** Logged out: a premium-styled login gate (icons, primary CTA, `redirect_to` back to the dashboard) — not a plain "please log in" line. Logged in: a rail with, in Free, exactly 5 sections grouped as: **library** group — Media, Albums, Collections (Favorites exists but is NOT on the rail, `nav:false`, since 2.6.0); **account** group — Edit profile. The **compete** rail group exists as a concept but is always empty on Free-only (no Free section declares it).
- **UX expectation:** Each rail item shows its own item count where the section reports one (e.g. Media's count = your own upload count) — "you have 0 albums" is shown as a real zero, not a missing rail item.
- **Settings that change it:** the dashboard page mapping itself (renaming/moving the page triggers a rewrite-rule flush automatically).
- **Edge cases:** Renaming or moving the My Media page must not break existing bookmarks to it or its sub-sections for up to 5 renames back (301 redirect from up to 5 remembered old paths).

#### MV-DSH-002 — Media tab
- **Edition:** Free
- **Who:** Member (own uploads)
- **Where:** My Media dashboard > Media tab, or `/my-media/media/`. `GET /mvs/v1/me/media`.
- **Setup:** A mix of your own uploads across privacy levels.
- **Steps:** 1. Open the Media tab. 2. Search, sort, filter.
- **Expected:** Lists every one of your own items regardless of privacy (it's your library, not a public feed). Same shared toolbar (search/sort/count) as Explore.
- **UX expectation:** Distinct empty state ("you haven't uploaded anything yet" + upload CTA) vs. a filtered-to-zero search.
- **Settings that change it:** `mvs_items_per_page`.
- **Edge cases:** Edit (cog) icon is visible on cards here specifically BECAUSE this is your own library — it is NOT shown on Explore/Album/Collection cards even for your own items appearing there.

#### MV-DSH-003 — Albums tab
- **Edition:** Free
- **Who:** Member (own albums)
- **Where:** My Media dashboard > Albums tab, `/my-media/albums/`. `GET /mvs/v1/albums?author={id}`.
- **Setup:** See MV-ALB-001.
- **Steps:** 1. Open the Albums tab.
- **Expected:** Lists your own albums of every privacy level, with Create/Edit/Delete actions.
- **UX expectation:** Same as MV-DSH-002's empty/filtered distinction, applied to albums.
- **Settings that change it:** `mvs_items_per_page`.
- **Edge cases:** none beyond the ALB entries above.

#### MV-DSH-004 — Collections tab
- **Edition:** Free
- **Who:** Member (own collections)
- **Where:** My Media dashboard > Collections tab, `/my-media/collections/`. `GET /mvs/v1/collections`.
- **Setup:** See MV-COL entries.
- **Steps:** 1. Open the Collections tab, including the Favorites card.
- **Expected:** Lists your own collections (smart + any manual ones you created) plus the Favorites card. Copy reads "Media from anyone on the site, gathered by rules you set" in stock Free (the manual-Save-capable copy variant only shows when `mvs_collections_enabled` is true, which it isn't by default — see MV-COL-005).
- **UX expectation:** none beyond the copy check above.
- **Settings that change it:** none.
- **Edge cases:** none beyond MV-COL entries.

#### MV-DSH-005 — Favorites (reachable, not on rail)
- **Edition:** Free
- **Who:** Member
- **Where:** `/my-media/favorites/` directly, or via the Favorites card inside the Collections panel — NOT a top-level rail item since 2.6.0.
- **Setup:** See MV-FAV entries.
- **Steps:** 1. Try to find "Favorites" in the main rail (should be absent). 2. Reach it via the Collections tab's Favorites card. 3. Try the direct URL.
- **Expected:** Both paths land on the same working Favorites listing.
- **UX expectation:** A member who bookmarked the old rail-item URL before 2.6.0 should still land somewhere real via the direct URL, not a 404.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-DSH-006 — Edit profile panel
- **Edition:** Free
- **Who:** Member (own profile)
- **Where:** My Media dashboard > "Edit profile" (account group). See MV-PRF-010/011.
- **Setup:** none.
- **Steps:** see MV-PRF-011.
- **Expected:** see MV-PRF-011. This panel is the ONE home for editing — the standalone `/media/edit-profile/` route redirects here (MV-PRF-010).
- **UX expectation:** VIEWING your own profile (as opposed to editing it) is a separate link in the rail head next to your name, navigating away to `/media/@you/` — it is deliberately NOT a panel like the rest of the rail, since a rail item that silently leaves the page while every other item switches a panel would be confusing; verify this distinction holds in the browser (a click on "View profile" should navigate, a click on "Edit profile" should switch the panel in place).
- **Settings that change it:** none.
- **Edge cases:** none beyond MV-PRF-010/011.

---

### Area: Notifications (NTF)

#### MV-NTF-001 — Notification bell and dropdown
- **Edition:** Free
- **Who:** Member (own notifications)
- **Where:** Rail head of the My Media dashboard ONLY on a standalone (non-BuddyPress) Free install — `.mvs-notification-bell` in `templates/partials/dashboard-content.php`. `GET /mvs/v1/me/notifications`, `GET /mvs/v1/me/notifications/count`.
- **Setup:** Trigger at least one notification (e.g. have another member follow you).
- **Steps:** 1. Open the dashboard. 2. Click the bell. 3. Observe the badge count and the list.
- **Expected:** Badge shows the unread count (cached 300s); dropdown lists notifications, unread ones visually distinguished (`mvs-notification-unread` class).
- **UX expectation:** There is NO site-wide/header notification bell outside the dashboard on a standalone Free install — a tester should not expect one on Explore or a single-media page; that's expected absence, not a bug, on Free-only without BuddyPress.
- **Settings that change it:** none for the bell itself.
- **Edge cases:** On a BuddyPress-active site, only the BP nav bell shows MVS notifications — the dashboard's own bell is suppressed to avoid a double-render (see MV-BP entries).

#### MV-NTF-002 — Mark notifications read
- **Edition:** Free
- **Who:** Member
- **Where:** "Mark all read" button in the notification dropdown, or clicking an individual notification. `POST /mvs/v1/me/notifications/read` (empty `ids` = mark ALL read; non-empty = just those).
- **Setup:** Several unread notifications.
- **Steps:** 1. Click one notification. 2. Click "Mark all read".
- **Expected:** Clicking one marks just that one read (and typically navigates to its target). "Mark all read" clears the badge to 0 and marks every unread row read.
- **UX expectation:** Badge count updates immediately from the response, no separate re-fetch needed.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-NTF-003 — Notification types generated
- **Edition:** Free
- **Who:** Member (recipient)
- **Where:** Triggered by: a new follower, a reaction on your media, a comment on your media, being @mentioned in a comment, a favourite on your media, a new DM, a resolved report you filed.
- **Setup:** Two member accounts to trigger each type.
- **Steps:** 1. Trigger each of the 7 types in turn from a second account (or via moderation for `report_resolved`). 2. Check your notification list after each.
- **Expected:** Exactly these 7 types exist: `new_follower`, `media_reaction`, `media_comment`, `media_mention`, `media_favorite`, `new_message`, `report_resolved`. No self-notification is generated (acting on your own content never notifies yourself).
- **UX expectation:** Each type's list-row copy should clearly identify who did what and to which item, with a working link to the target.
- **Settings that change it:** the "Emails" section under Settings > General controls whether a matching EMAIL is also sent (separate from the always-on in-app notification — see MV-NTF-004).
- **Edge cases:** `new_message` is created by the messaging system directly (`Messaging\NotificationListener`), not by the generic `NotificationService` hook list — but it still appears in the same unified notification feed.

#### MV-NTF-004 — Email notification preferences
- **Edition:** Free
- **Who:** Member (per-member opt-out); Admin (site-wide toggles)
- **Where:** Site-wide: Settings > General > "Emails" section (3 checkboxes, all default OFF: "Photo battle invites", "Documents shared with a member", "Report reviewed" — the first two are captioned "(MediaVerse Pro)" and have no Free-side trigger event). Per-member: "Email me about activity" checkbox in the Edit profile panel (MV-PRF-011).
- **Setup:** Turn "Report reviewed" on site-wide; file and have a moderator resolve a report as the test member.
- **Steps:** 1. Enable "Report reviewed" emails. 2. File a report, have it resolved. 3. Check the member's email. 4. Set the member's own "Email me about activity" to No and repeat.
- **Expected:** With the site toggle on and the member's own preference on: email sent. With the member's own preference off: NO activity email is sent regardless of site-wide toggles (member-level off is absolute).
- **UX expectation:** Every activity email should carry an unsubscribe link that flips the member's own preference off directly from the email (`EmailService::maybe_unsubscribe()`), without requiring a login.
- **Settings that change it:** the 3 site-wide checkboxes (all boolean, default false) + the per-member "Email me about activity" field.
- **Edge cases:** "Photo battle invites" and "Documents shared" toggles exist in Free's Settings UI but have no event that fires them on Free-only (they're Pro-triggered) — a tester enabling them on Free-only correctly sees no email ever, since nothing raises the underlying event. Account-deletion confirmation emails are always sent regardless of any of these toggles.

#### MV-NTF-005 — Mentions
- **Edition:** Free
- **Who:** Member (mentioned)
- **Where:** `@mention` inside a comment (mentions are parsed from comments only — never from media titles/descriptions). `GET /mvs/v1/me/mentions`.
- **Setup:** Comment on a media item and @mention another member by username.
- **Steps:** 1. Post a comment containing `@username`. 2. As that member, check your mentions.
- **Expected:** The mention becomes a link in the rendered comment; the mentioned member gets a `media_mention` notification and the mention is retrievable via `/me/mentions`.
- **UX expectation:** There is no @-autocomplete anywhere in the comment box UI as of this version — typing `@` does not suggest usernames. This is a known limitation, not a bug to "fix" — 
- **Settings that change it:** none.
- **Edge cases:** A mention typed into a media title or description is never parsed or notified — only comment text is scanned.

---

### Area: Messages / DM (MSG)

#### MV-MSG-001 — Messaging master switch
- **Edition:** Free
- **Who:** Admin (setting); affects everyone
- **Where:** Settings > Messages tab (sidebar label "Messages", page slug `mvs-settings-social`) > "Messages" checkbox, label "Turn on private messages" (option `mvs_messaging_enabled`, default ON).
- **Setup:** Turn the switch off.
- **Steps:** 1. Turn "Turn on private messages" off. 2. As a member, look for the chat panel icon, the "Message" button on profiles, and visit `/messages/` directly.
- **Expected:** Off: no messaging REST routes respond, no chat panel renders anywhere, `/messages/` page has nothing to show, every "Message" button disappears (including on integrations built on MediaVerse, e.g. BuddyNext). Existing conversations are preserved in the database and come back exactly as they were once the switch is turned back on.
- **UX expectation:** This must be a clean, total absence — no broken "Message" button that 404s, no chat icon that opens an empty panel. Turning it back on should restore prior conversations with no data loss, verified by reopening an old thread.
- **Settings that change it:** `mvs_messaging_enabled` itself.
- **Edge cases:** GDPR export/erase for messaging stays registered and functional even while the switch is off (data isn't inaccessible to a formal export request just because the feature UI is hidden).

#### MV-MSG-002 — Who can send messages (DM access level)
- **Edition:** Free
- **Who:** Admin (setting); Member (sender/recipient)
- **Where:** Settings > Messages > "Who can send messages" (option `mvs_dm_access`: Everyone / Followers only (others go to Requests) / Mutual followers only / Nobody). A per-recipient override also exists via their own Edit Profile "Who can message you" field, which can only be MORE restrictive than the site ceiling, never looser.
- **Setup:** Two member accounts, no follow relationship between them.
- **Steps:** 1. Set site-wide to "Everyone". Try messaging as A → B. 2. Set to "Followers only". Try again without a follow relationship — expect it becomes a Request instead of landing directly in the inbox. 3. Set to "Nobody". Try again.
- **Expected:** Everyone: message sends directly. Followers only (non-follower sender): message becomes a pending Request in the recipient's Requests tab rather than failing outright. Mutual: requires both directions following. Nobody: refused outright with "This member isn't accepting messages right now." — existing conversations remain readable, only new sends are blocked.
- **UX expectation:** The distinct "goes to Requests" behaviour for Followers-only must be visibly different from a hard refusal — the sender should get feedback that their message was sent as a request, not silently swallowed.
- **Settings that change it:** `mvs_dm_access` (site ceiling) + the recipient's own profile preference (can tighten, never loosen, the site ceiling).
- **Edge cases:** Minimum account age (`mvs_dm_min_age`, "Minimum Account Age (days)", default 0/off) additionally blocks a too-new sender with "Your account is too new to message this member yet." regardless of the access-level setting.

#### MV-MSG-003 — Start a new conversation
- **Edition:** Free
- **Who:** Member
- **Where:** Chat panel's "New conversation" / profile "Message" button. `POST /mvs/v1/conversations` (`recipient_id`, optional `as_request`).
- **Setup:** Two eligible member accounts.
- **Steps:** 1. Click "Message" on another member's profile (or start fresh from the chat panel). 2. Type a first message. 3. Send.
- **Expected:** Conversation is created (or reused, if one already exists with that recipient) and the message appears in it.
- **UX expectation:** Sending to yourself is refused ("You can't send a message to yourself."). An empty message is refused ("Your message is empty."). An over-length message is refused ("That message is too long to send.") before hitting the network if possible.
- **Settings that change it:** all of MV-MSG-002's access rules apply here.
- **Edge cases:** A recipient who has since blocked you refuses the send with "You can no longer message this member."

#### MV-MSG-004 — Send a text message
- **Edition:** Free
- **Who:** Member (existing conversation participant)
- **Where:** Chat composer. `POST /conversations/{id}/messages`.
- **Setup:** An existing conversation.
- **Steps:** 1. Type a message. 2. Send. 3. Send several rapidly to test rate limiting.
- **Expected:** Message appears in the thread instantly for the sender; the other participant sees it on next poll/refresh. Rapid sending beyond the rate limit is refused ("You're sending messages too quickly. Please wait a moment and try again.").
- **UX expectation:** Composer clears on successful send; a failed send should leave the typed text in the composer (not lose what the member typed) and show the specific denial reason from the server (blocked / disabled / rate-limited / etc. — see the full message table in MV-MSG-002/010) rather than a generic error.
- **Settings that change it:** `mvs_dm_access`, `mvs_dm_min_age`.
- **Edge cases:** A message to a conversation you're no longer a participant in (e.g. removed from a group) is refused ("You can no longer post to this conversation.").

#### MV-MSG-005 — Send an attachment
- **Edition:** Free
- **Who:** Member
- **Where:** Attachment picker in the composer. `POST /mvs/v1/messages/upload` then `POST /conversations/{id}/messages` with `attachment_id`.
- **Setup:** An image/video/audio file under 10MB (PDFs are excluded from DM attachments since 2.2.0 and still excluded).
- **Steps:** 1. Click the attachment icon. 2. Pick a valid file. 3. Try a file over 10MB. 4. Try a PDF.
- **Expected:** Valid media-type attachment uploads and sends as a message. Oversize file refused (10MB cap, filter-only — no wp-admin UI field for changing it). PDF refused (not a valid DM attachment type, verified by file content via `finfo_file()`, not by extension).
- **UX expectation:** Upload progress shown while the attachment uploads before the message actually sends. A rejected attachment should say clearly why (too large / wrong type), not just fail silently.
- **Settings that change it:** none in the wp-admin UI (10MB cap is filter-only, `mvs_dm_max_upload_size`).
- **Edge cases:** none beyond the type/size checks above.

#### MV-MSG-006 — Share an existing media item into a conversation
- **Edition:** Free
- **Who:** Member
- **Where:** Share action on a media item, targeting the chat (`openWithMediaShare` cross-store action). `POST /conversations/{id}/messages` with `media_id`.
- **Setup:** A media item you can view, and an existing/eligible conversation.
- **Steps:** 1. From a media item's share options, choose to send it via DM. 2. Pick or create the target conversation.
- **Expected:** A message referencing the existing media item is created (not a duplicate upload); the recipient sees an inline preview card for it (`chat-media-card.php`), respecting THEIR own view permission on it.
- **UX expectation:** If the recipient can't actually view the referenced item (e.g. it later goes private), the card should degrade gracefully, not break the chat rendering.
- **Settings that change it:** none beyond messaging access rules.
- **Edge cases:** none beyond the above.

#### MV-MSG-007 — React to a message
- **Edition:** Free
- **Who:** Member (conversation participant)
- **Where:** Message bubble reaction control. `POST`/`DELETE /messages/{id}/reactions` (`emoji` required).
- **Setup:** An existing message in a conversation you're part of.
- **Steps:** 1. React to a message with an emoji. 2. Remove the reaction.
- **Expected:** Reaction attaches to the message and is visible to all participants; removing it (a "safety valve" action) always succeeds even in a blocked-pair scenario in the rare case one existed before the block.
- **UX expectation:** No page/panel reload — reaction appears live in the thread.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-MSG-008 — Unsend vs. delete a message
- **Edition:** Free
- **Who:** Message sender (own messages)
- **Where:** Message bubble's own actions. `DELETE /messages/{id}` (delete for me) vs. `DELETE /messages/{id}/unsend` (unsend for everyone).
- **Setup:** A message you sent.
- **Steps:** 1. Use "Delete" on your own message. 2. Send another, then use "Unsend".
- **Expected:** Delete removes the message from YOUR view only (the other participant still sees it). Unsend removes it for everyone in the conversation.
- **UX expectation:** These two actions must be visibly distinct in the UI (different labels/icons) — a tester should be able to tell which one they're about to trigger. Unsend likely benefits from the shared confirm dialog given its everyone-facing effect — verify in the browser whether it's gated by a confirm.
- **Settings that change it:** none.
- **Edge cases:** none beyond the visibility distinction.

#### MV-MSG-009 — Mark conversation read / unread badge
- **Edition:** Free
- **Who:** Member
- **Where:** Opening a conversation. `POST /conversations/{id}/read`; badge via `GET /me/messages/unread-count`.
- **Setup:** An unread message waiting.
- **Steps:** 1. Note the unread badge. 2. Open the conversation. 3. Check the badge again.
- **Expected:** Opening the conversation marks it read and decrements the badge.
- **UX expectation:** Badge updates without needing a full chat-panel reopen or page refresh.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-MSG-010 — Message requests: accept / decline
- **Edition:** Free
- **Who:** Recipient of a request-tier conversation (see MV-MSG-002)
- **Where:** Requests tab in the chat panel/list (`tab=requests` on `GET /me/conversations`). `POST /conversations/{id}/accept` or `/decline`.
- **Setup:** A conversation that landed as a Request per MV-MSG-002.
- **Steps:** 1. Open the Requests tab. 2. Accept one. 3. Decline another.
- **Expected:** Accept moves it into the normal inbox and allows replying normally. Decline removes/archives it without a reply option. Accepting a request from someone who has since blocked you is explicitly denied.
- **UX expectation:** Decline (a "safety valve" retraction) should always succeed even in edge-case block scenarios.
- **Settings that change it:** none beyond MV-MSG-002's access-level setting that creates Requests in the first place.
- **Edge cases:** none beyond the above.

#### MV-MSG-011 — Group conversations
- **Edition:** Free
- **Who:** Member (participants)
- **Where:** "Start new group" in the chat panel (`startNewGroup`, `addNewGroupMember`, `updateNewGroupTitle`).
- **Setup:** 2+ other eligible members to add.
- **Steps:** 1. Start a new group, name it, add members. 2. Send a message. 3. Rename the group. 4. Remove a member. 5. Leave the group.
- **Expected:** Group conversation behaves like a 1:1 one but with a roster (`openRoster`) and a title; rename (`startRenameGroup`/`updateGroupTitleDraft`) and member add/remove work for participants with the right role.
- **UX expectation:** Leaving a group should have a distinct confirmation from leaving a 1:1 conversation (there's no "leave" concept in a 1:1) — verify a confirm dialog gates "Leave group" given it's effectively destructive to your own access.
- **Settings that change it:** none beyond the general messaging switches.
- **Edge cases:** none beyond the above.

#### MV-MSG-012 — Typing indicator
- **Edition:** Free
- **Who:** Member (conversation participants)
- **Where:** Composer. `POST /conversations/{id}/typing`.
- **Setup:** Two members in the same conversation, both with it open.
- **Steps:** 1. Start typing as Member A. 2. Watch Member B's view.
- **Expected:** B sees a "typing…" indicator while A is actively composing; it clears shortly after A stops.
- **UX expectation:** Indicator should not persist forever if A closes the tab mid-type (a TTL/timeout should clear it).
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-MSG-013 — Search within a conversation
- **Edition:** Free
- **Who:** Member (participant)
- **Where:** Conversation search field. `GET /conversations/{id}/messages/search?q=`.
- **Setup:** A conversation with several messages including a distinctive phrase.
- **Steps:** 1. Search for that phrase within the open conversation.
- **Expected:** Matching messages surface (up to 50 per page).
- **UX expectation:** Zero-result search shows a clear "no messages found" rather than an empty blank pane.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-MSG-014 — Chat panel visibility scoping
- **Edition:** Free
- **Who:** Admin (setting); affects all Members
- **Where:** Settings > Messages > "Chat Panel Visibility" (option `mvs_chat_panel_visibility`: Everywhere (default) / MediaVerse pages only (Explore, Dashboard, Albums, Member Profiles) / BuddyPress pages only (member + group) / Never show the slide-out (use only the dedicated `/messages/` page)).
- **Setup:** Set each of the 4 options in turn.
- **Steps:** 1. Set "Everywhere", check the floating chat icon on a totally unrelated page (e.g. the site's blog). 2. Set "MediaVerse pages only", recheck on both a MediaVerse page and a random page. 3. Set "Never show the slide-out", check `/messages/` still works directly.
- **Expected:** Icon appears/disappears exactly per the scope chosen; the dedicated `/messages/` page always works regardless of this setting (it only controls the floating slide-out icon).
- **UX expectation:** "Disabled" mode must emit NO `.mvs-chat-panel` markup at all anywhere (not just hidden via CSS) — verify via page source, not just visual absence.
- **Settings that change it:** `mvs_chat_panel_visibility` itself.
- **Edge cases:** none beyond the 4 modes.

#### MV-MSG-015 — Online status visibility
- **Edition:** Free
- **Who:** Admin (setting); Member (own override); other Members (viewers)
- **Where:** Settings > Messages > "Online Status Visibility" (Everyone / Followers only / Nobody). Per-member override in Edit Profile ("Show your online status": Yes/No).
- **Setup:** Two members, a follow relationship between one pair and not the other.
- **Steps:** 1. Set site-wide to "Followers only". 2. As a non-follower, check for an online dot on the target member. 3. As a follower, check the same.
- **Expected:** Non-follower sees no online indicator; follower does (when the target is actually online). A member's own "Show your online status: No" always wins over the site-wide setting for them specifically.
- **UX expectation:** none beyond the visibility check.
- **Settings that change it:** `mvs_show_online_status` + the per-member override.
- **Edge cases:** none.

#### MV-MSG-016 — DM timestamps are server-side (MediaVerse's own clock)
- **Edition:** Free
- **Who:** Member (any conversation participant, for verification purposes)
- **Where:** Message timestamps rendered in the chat UI.
- **Setup:** Send a message.
- **Steps:** 1. Send a message. 2. Compare the displayed time against the sending device's own clock, deliberately set to a different timezone/wrong time.
- **Expected:** The stored/displayed time reflects the MediaVerse SERVER's clock at the moment it wrote the row (UTC, `current_time('mysql', true)`), never a client/device-submitted timestamp — the public REST `send_message` schema has no timestamp field a client can set. Rendering then converts that UTC instant to the viewer's own local/site timezone for display.
- **UX expectation:** A tester deliberately skewing their device clock should NOT be able to make a message appear sent "in the future" or "in the past" relative to the server.
- **Settings that change it:** none (site timezone setting under wp-admin > General affects display conversion only, not the stored instant).
- **Edge cases:** none beyond the clock-skew check above.

---

### Area: Blocks & Shortcodes (BLK)

#### MV-BLK-001 — Media Grid block (`mvs/media-grid`) / `[mvs_gallery]`
- **Edition:** Free
- **Who:** Visitor, Member (viewing a normal WP page/post where the block or shortcode is placed)
- **Where:** Any post/page, inserted via the block editor ("Media Grid") or `[mvs_gallery type="" category="" tag="" orderby="date" order="desc" user_id="0" layout=""]`.
- **Setup:** Insert the block on a normal page; publish.
- **Steps:** 1. Add the block, set columns/type/category/tag/order attributes in the editor. 2. Publish and view the page. 3. Repeat with the shortcode instead, using equivalent attributes.
- **Expected:** Grid renders per the chosen attributes on the frontend. The shortcode is a thin wrapper around the same block render — results should be visually identical to the block for the same effective settings, EXCEPT that the shortcode's `columns`/`per_page` are always forced from the site's admin settings (`mvs_grid_columns`/`mvs_items_per_page`) and cannot be overridden by shortcode attributes, unlike the block's own `columns`/`perPage` attributes.
- **UX expectation:** Empty state ("nothing shared yet" equivalent) when the filter matches nothing. Lightbox opens on click when `showLightbox` is true (block default).
- **Settings that change it:** `mvs_grid_columns`, `mvs_items_per_page` (shortcode only, as above).
- **Edge cases:** A non-core `layout` value (anything other than grid/masonry/list/square/original) on the shortcode falls through to Pro's layout filter, then to the plain grid if Pro isn't active — verify Free-only never errors on an unrecognised layout string, it just shows the default grid.

#### MV-BLK-002 — Explore Feed block (`mvs/explore-feed`) / `[mvs_explore_feed]`
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Any page, block "Explore Feed" or `[mvs_explore_feed layout="" columns="3" per_page="12" filters="true" search="true"]`.
- **Setup:** Insert on a normal (non-Explore) page.
- **Steps:** 1. Insert with `showFilters`/`showSearch` on. 2. Test the embedded search box and any filter controls. 3. Toggle `filters`/`search` off and re-check.
- **Expected:** A self-contained discover feed, independent of whether this page is the site's mapped Explore page. Search/filters work identically to the main Explore page's controls when enabled; hidden entirely when their attribute is off.
- **UX expectation:** none beyond MV-EXP entries, reproduced in an arbitrary-page context.
- **Settings that change it:** none beyond the block/shortcode's own attributes.
- **Edge cases:** Placing this block on a page that ISN'T the mapped Explore page should work fine (it's the CommunityPrivacyGate's "arbitrary owner-embedded page" case — the page itself is not force-gated by the Members Only setting, only the underlying REST calls are, per MV-ACC-001).

#### MV-BLK-003 — Media Player block (`mvs/media-player`) / `[mvs_player]`
- **Edition:** Free
- **Who:** Visitor, Member per the referenced item's privacy
- **Where:** Any page, block "Media Player" (attribute `mediaId`) or `[mvs_player id="123" autoplay="false" loop="false" download="false"]`.
- **Setup:** A video or audio item's numeric ID.
- **Steps:** 1. Insert, set the media ID to a public item. 2. Set it to a private item belonging to someone else and view as a different member.
- **Expected:** Public item plays inline. Private/inaccessible item renders one of its defined empty states (not found / wrong privacy) rather than a broken player.
- **UX expectation:** `showDownload`/`download` attribute controls whether a Download button appears under the player, subject to the same global+per-item download gates as MV-MED-009.
- **Settings that change it:** `mvs_allow_downloads`.
- **Edge cases:** Embedding a DOCUMENT's ID here should be refused/empty (this block is for playable media, not documents).

#### MV-BLK-004 — Album Viewer block (`mvs/album-viewer`) / `[mvs_album]`
- **Edition:** Free
- **Who:** Visitor, Member per the album's privacy
- **Where:** Any page, block "Album Viewer" (attribute `albumId`) or `[mvs_album id="123" columns="3" show_title="true" show_description="true"]`.
- **Setup:** A public album and a members-only one.
- **Steps:** 1. Embed the public album on a normal page, view logged out. 2. Embed the members-only album, view logged out.
- **Expected:** Public renders its viewable items (per `AlbumService::viewable_item_ids()` — the SAME per-viewer filtering the album's own page and the REST route use, since 2.5.1's authorisation unification). Members-only denies a logged-out visitor.
- **UX expectation:** "no items in album" empty state for an album with zero viewable items to THIS viewer.
- **Settings that change it:** none.
- **Edge cases:** Before 2.5.1 this renderer had its own copy of the privacy check that could leak private items — confirm the current build shows only what the single canonical rule allows, especially for a mixed-privacy album.

#### MV-BLK-005 — Media Stats block (`mvs/media-stats`) / `[mvs_stats]`
- **Edition:** Free
- **Who:** Visitor, Member (renders aggregate public-facing stats, not a private dashboard)
- **Where:** Any page, block "Media Stats" or `[mvs_stats views="true" downloads="true" reactions="true" top="true" top_count="5"]`.
- **Setup:** Some media with views/downloads/reactions accumulated.
- **Steps:** 1. Insert with all toggles on. 2. Toggle each off individually and re-check.
- **Expected:** Shows the enabled stat cards + a "top media" list of `top_count` items; each toggle independently shows/hides its own card.
- **UX expectation:** "no data" placeholder rather than a blank/zero-looking chart area when the site has no stats yet.
- **Settings that change it:** none beyond the block/shortcode's own attributes.
- **Edge cases:** none.

#### MV-BLK-006 — Media Upload block (`mvs/media-upload`) / `[mvs_upload]`
- **Edition:** Free
- **Who:** Member (must have upload rights); Visitor sees the login gate
- **Where:** Any page, block "Media Upload" or `[mvs_upload max_files="10" show_privacy="true"]`.
- **Setup:** Insert on a normal page (e.g. as an alternative to the FAB modal).
- **Steps:** 1. View the page logged out. 2. Log in as an uploader and use the form.
- **Expected:** Same upload behaviour as the FAB modal (MV-UPL entries), just embedded inline on this page instead of as an overlay. `showPrivacy`/`show_privacy` toggles the privacy field's presence (still subject to `mvs_allow_user_privacy`).
- **UX expectation:** Logged-out visitor sees a clear "log in to upload" gate here too, matching the FAB's behaviour, not a broken/disabled form.
- **Settings that change it:** all of MV-UPL's settings apply identically.
- **Edge cases:** `mvs_before_upload_form` action fires before render (Pro uses it for a quota widget) — Free-only should just show the plain form with no gap/placeholder where that widget would go.

#### MV-BLK-007 — Member Photos block (`mvs/member-photos`) / `[mvs_member_photos]`
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Any page (sidebar, FSE template part, BuddyPress profile area), block "Member Photos" or `[mvs_member_photos user_id="0" columns="3" per_page="12" type="" show_header="true" actions="true"]`.
- **Setup:** A member with public photos; view the block with `user_id` unset on: (a) a BuddyPress member's profile page, (b) a single post authored by a member, (c) as the logged-in member's own dashboard widget.
- **Steps:** 1. Leave `userId`/`user_id` at 0 in each of the three contexts above. 2. Set an explicit user ID.
- **Expected:** Auto-resolve order when unset: explicit attribute → BuddyPress displayed member → post author → current logged-in user → empty state. An explicit ID always wins outright.
- **UX expectation:** "no user resolved" empty state specifically when logged out AND no user could be auto-detected (e.g. placed on a generic page with no BP context, no post author context, viewed by a Visitor) — this must be a distinct, honest empty state, not a silent blank block.
- **Settings that change it:** none beyond its own attributes.
- **Edge cases:** `show_header`/`showHeader` and `actions`/`showActions` independently toggle the member header and the action row (follow/message where applicable) within this embedded widget.

#### MV-BLK-008 — PDF Viewer block (`mvs/pdf-viewer`) / `[mvs_pdf_viewer]`
- **Edition:** Free
- **Who:** Visitor, Member per the document's privacy
- **Where:** Any page, block "PDF Viewer" (attributes `mediaId`, `height`, `showToolbar`) or `[mvs_pdf_viewer id="123" height="600" toolbar="true"]`.
- **Setup:** A public PDF document.
- **Steps:** 1. Embed with a valid public document ID. 2. Embed with: no ID, a non-existent ID, a media item that ISN'T a document, and a document the current viewer can't see.
- **Expected:** Valid case: iframe embed of the browser's native PDF viewer, `#view=FitH` URL fragment, respecting the same privacy/access rules as any other media, lazy-loaded. Each of the 4 invalid cases in Step 2 has its own distinct empty state (no id / not found / wrong type / privacy fail) — plus a 5th for a missing underlying asset file.
- **UX expectation:** `showToolbar`/`toolbar` attribute toggles the PDF viewer's own toolbar chrome. `height` is clamped 200-1400 by the shortcode even if a wild value is passed.
- **Settings that change it:** the documents master switch (whatever it takes to have `Plugin::documents_enabled()` true) gates whether a document renders at all anywhere, including here.
- **Edge cases:** Test all 5 named empty states individually — this is one of the plugin's own named regression-lock rows (`WHAT-TO-CHECK.md`).

#### MV-BLK-009 — `[mvs_dashboard]` shortcode
- **Edition:** Free
- **Who:** Member; Visitor sees a login gate
- **Where:** Any page (an alternative to mapping a dedicated page).
- **Setup:** Insert the shortcode on a normal page instead of using the mapped My Media page.
- **Steps:** 1. View logged out. 2. View logged in.
- **Expected:** Renders the exact same dashboard content/rail (`templates/partials/dashboard-content.php`) as the mapped page, wrapped in its own login gate for logged-out visitors.
- **UX expectation:** Section deep-links (`/my-media/<slug>/`) are specific to the MAPPED dashboard page's rewrite rules — using the shortcode on a different, unmapped page means sub-section URLs may not deep-link the same way; Check this distinction in the browser (does switching tabs work via JS state here even without the dedicated rewrite rules?).
- **Settings that change it:** none beyond the mapped-page settings that this shortcode doesn't strictly require.
- **Edge cases:** none beyond the deep-link caveat above.

#### MV-BLK-010 — `[mvs_collection]` shortcode
- **Edition:** Free
- **Who:** Visitor, Member per the collection's privacy
- **Where:** Any page. `[mvs_collection id="123" columns="3" per_page="20"]`.
- **Setup:** A public collection and a members-only one.
- **Steps:** 1. Embed the public collection's ID on an arbitrary page. 2. Embed the members-only one and view logged out.
- **Expected:** Public renders its resolved items in a simple grid. Members-only shows "Collection not found." to a denied viewer — this route gained the container privacy gate in 2.5.1 (it previously rendered members-only contents to anyone).
- **UX expectation:** A missing/wrong `id` attribute shows the instructive placeholder text ("Please provide a collection ID: …") only to someone editing the page (verify whether this placeholder is visible to end visitors too, which would be a minor content leak of internal syntax — flag if so).
- **Settings that change it:** none.
- **Edge cases:** `id="0"` or omitted shows the "provide a collection ID" message; a non-existent/unpublished/wrong-post-type ID shows "Collection not found." — same message as the privacy-denied case, so a stranger can't distinguish "doesn't exist" from "exists but you can't see it," which is correct (no enumeration leak).

#### MV-BLK-011 — `[mvs_profile_edit]` shortcode
- **Edition:** Free
- **Who:** Member (own profile); Visitor sees plain text prompt
- **Where:** Any page. `[mvs_profile_edit]`.
- **Setup:** none.
- **Steps:** 1. View the page logged out. 2. Log in and view/use the form.
- **Expected:** Logged out: plain "Please log in to edit your profile." text (not the fancier login-gate styling used elsewhere — confirmed in code: this is a plain paragraph while other login gates use the icon + button style; report it as a presentation defect against Part 2.2). Logged in: the same editable fields as MV-PRF-011.
- **UX expectation:** none beyond MV-PRF-011/012, reproduced on an arbitrary page.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-BLK-012 — `[mvs_documents]` shortcode
- **Edition:** Free
- **Who:** Visitor, Member
- **Where:** Any page. `[mvs_documents per_page="20" type="" folder="0"]`.
- **Setup:** Ensure the documents master switch is on and at least one public document exists.
- **Steps:** 1. Embed on a normal page with default attributes. 2. Turn the documents master switch off and reload. 3. Try the `folder` attribute (Pro-only drive feature).
- **Expected:** Default: public document listing, rows not tiles, with type chip/size/author/date and pagination. Switch off: an editor (someone with `manage_options`) sees an explanatory notice that documents are switched off; a plain visitor sees nothing at all (no notice, no broken UI). `folder` attribute on Free-only: an editor sees "Folder listings need MediaVerse Pro."; a visitor sees nothing.
- **UX expectation:** The editor-vs-visitor difference in messaging matters here — don't report "shows nothing" as a bug for a logged-out visitor when the code deliberately shows nothing to them and a real message only to someone who can act on it (`manage_options`/`edit_posts`).
- **Settings that change it:** the documents master switch; `mvs_documents_drive_html` filter (Pro-only, for the `folder`/`drive` params).
- **Edge cases:** `?drive=my-drive|shared|recent` query params on THIS shortcode's page are, since 2.6.0, 302-redirected to the dashboard's Documents section if one exists — Check this redirect doesn't create a loop if the shortcode page and the dashboard page are somehow the same page.

#### MV-BLK-013 — `[mvs_usage_history]` shortcode
- **Edition:** Free
- **Who:** Member (own history); Visitor sees nothing
- **Where:** Any page. `[mvs_usage_history limit="20"]`.
- **Setup:** Perform a few actions that write to the usage/credit ledger (uploads).
- **Steps:** 1. View the page logged out. 2. Log in and view.
- **Expected:** Logged out: renders literally nothing (empty string) — not even a login prompt. Logged in: your own append-only usage ledger, capped at `limit` rows.
- **UX expectation:** The complete silence for a logged-out visitor is intentional here (unlike `[mvs_profile_edit]`'s text prompt) — don't flag this specific shortcode's logged-out silence as inconsistent without checking the code first; it's this shortcode's designed behaviour.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-BLK-014 — `[mvs_lock_overlay]` shortcode (deprecated, renders nothing)
- **Edition:** Free
- **Who:** Anyone
- **Where:** Any page still containing this legacy shortcode from before 2.6.0.
- **Setup:** A page with `[mvs_lock_overlay]` left in its content from an old install.
- **Steps:** 1. View the page.
- **Expected:** Renders nothing at all — no raw shortcode text, no overlay, no error. This is deliberate: media locking rules were removed in 2.6.0, and the shortcode stays registered purely so old content doesn't show broken `[mvs_lock_overlay]` text.
- **UX expectation:** none — total, silent absence is correct here.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-BLK-015 — Internal Interactivity-only "blocks" are never insertable
- **Edition:** Free
- **Who:** Content editor (block inserter user)
- **Where:** The block inserter/search in the editor.
- **Setup:** none.
- **Steps:** 1. Open the block inserter. 2. Search for "MVS Shared UI", "MVS Dashboard View", "MVS Explore View", "MVS Media Social".
- **Expected:** None of these 4 appear in the inserter (`"supports":{"inserter":false}`) — they are internal Interactivity API stores the plugin's OTHER blocks/templates rely on, not user-facing blocks. Only the 8 real blocks (Media Grid, Explore Feed, Media Player, Album Viewer, Media Stats, Member Photos, PDF Viewer, Media Upload) are insertable.
- **UX expectation:** none — this is a negative-presence test.
- **Settings that change it:** none.
- **Edge cases:** none.

#### MV-BLK-016 — Documentation matches shipped shortcodes
- **Edition:** Free
- **Who:** N/A (documentation accuracy check, not a live-site test)
- **Where:** `docs/website/features/shortcodes.md` vs. the 14 shortcodes actually registered.
- **Setup:** none.
- **Steps:** 1. Compare the documented list against: `mvs_gallery`, `mvs_upload`, `mvs_album`, `mvs_player`, `mvs_stats`, `mvs_dashboard`, `mvs_collection`, `mvs_profile_edit`, `mvs_documents`, `mvs_explore_feed`, `mvs_lock_overlay`, `mvs_member_photos`, `mvs_pdf_viewer`, `mvs_usage_history`.
- **Expected:** All 14 are documented by name; Pro-only shortcodes (`mvs_pro_*`) are documented separately and shouldn't be tested as part of Free.
- **UX expectation:** N/A.
- **Settings that change it:** N/A.
- **Edge cases:** N/A.

---

### Area: Site access / Members Only / Privacy (ACC)

#### MV-ACC-001 — Members Only master switch
- **Edition:** Free
- **Who:** Admin (setting); affects every Visitor
- **Where:** Settings > General tab > "Members Only" checkbox, sub-label "Only logged-in members can see WP MediaVerse" (option `mvs_members_only`, default OFF).
- **Setup:** Turn the setting on.
- **Steps:** 1. As a logged-out visitor, try to visit: `/media/` (Explore), a single media permalink, a member's profile, an album/collection single page, and (if Pro's Compete is active) `/compete/`. 2. Try a direct REST call, e.g. `GET /wp-json/mvs/v1/media`. 3. Log in as a member and repeat all of the above.
- **Expected:** Every one of those page visits redirects a logged-out visitor to the login page (with a return-to back to what they asked for). The REST call answers 401 with error code `mvs_community_private`. A logged-in member sees everything exactly as before (unaffected).
- **UX expectation:** The redirect happens at `template_redirect` priority 3 — BEFORE any MediaVerse template renders, so no flash of gated content is visible even for an instant. Two REST routes stay exempt even with this on: `/mvs/v1/serve` (signed media URLs — the credential travels in the URL signature, not the session) and `/mvs/v1/app/config` (needed for a client to even begin authenticating) — verify these two still work for a logged-out request while everything else is gated.
- **Settings that change it:** `mvs_members_only` itself. If a community layer (e.g. BuddyNext's own private-community mode) is installed and already answering this signal, the checkbox is shown LOCKED, displaying that plugin's answer instead ("your community plugin keeps this site private… change it in that plugin's settings" or the reverse) — the Free checkbox becomes read-only informational text in that case.
- **Edge cases:** An arbitrary WP page where the site owner has manually embedded a MediaVerse block/shortcode (e.g. MV-BLK-002 on a random page) is deliberately NOT redirected by this gate — only MediaVerse's OWN server-rendered routes (Explore, single media, profile, album/collection singles, the mapped Explore/Explore-Documents pages) are covered; a homepage that happens to embed a grid is never taken hostage by this switch. The underlying REST DATA on that arbitrary page is still gated, though — so a visitor might see the page shell but the embedded grid itself would fail to load data while gated. Verify this exact split in the browser.

#### MV-ACC-002 — Default privacy for new uploads
- **Edition:** Free
- **Who:** Admin (setting); affects every Member's uploads
- **Where:** Settings > General > "Default Privacy Level" (option `mvs_default_privacy`: Public / Members Only / Private).
- **Setup:** Set each of the 3 choices in turn.
- **Steps:** 1. Set to "Private". 2. Upload a new item without touching the privacy field (if it's even shown — see MV-ACC-003). 3. Check the resulting item's privacy.
- **Expected:** New upload inherits whichever default is set, unless the member explicitly overrides it (and is allowed to — MV-ACC-003).
- **UX expectation:** none beyond the resulting privacy being correct.
- **Settings that change it:** `mvs_default_privacy` itself; its own default value is derived from whether the site was set up as a private community at activation (`PrivateCommunityDefault::default_privacy()`).
- **Edge cases:** none.

#### MV-ACC-003 — Allow Users to Set Privacy
- **Edition:** Free
- **Who:** Admin (setting); Member (uploader)
- **Where:** Settings > General > "Allow Users to Set Privacy" checkbox, label "Let members choose" (option `mvs_allow_user_privacy`, default ON).
- **Setup:** Turn it off.
- **Steps:** 1. Turn off "Let members choose". 2. Open the upload modal and the edit-media modal — check for a Privacy field. 3. Try changing an existing item's privacy via a direct REST call while off.
- **Expected:** Off: the Privacy field disappears from every surface that offers it (upload modal, edit modal, album create/edit, the BuddyPress activity composer's privacy picker) — every upload/edit uses the site default regardless. A direct attempt to CHANGE privacy via the API is refused with 403 `mvs_privacy_locked`; RE-sending the SAME (unchanged) value still succeeds (since edit screens submit the current value on every save). Someone with `manage_mvs_settings` (typically Admin) is exempt from the lock everywhere.
- **UX expectation:** This is a ONE rule read by every surface as of 2.5.1 (not five separate reimplementations) — verify the upload modal, edit modal, and album modal all disappear/lock their privacy field CONSISTENTLY when this is off; a drift where one surface still shows an interactive-but-doomed-to-403 privacy field would be a real bug to report.
- **Settings that change it:** `mvs_allow_user_privacy` itself.
- **Edge cases:** none beyond the consistency check above.

#### MV-ACC-004 — Site-wide "make the whole API private" (community layer only)
- **Edition:** Free
- **Who:** N/A on a genuinely standalone Free install; relevant only when a community plugin (e.g. BuddyNext) is active
- **Where:** `mvs_rest_require_auth` filter, armed by a HOST plugin, not by anything in Free's own UI beyond the Members Only checkbox covered in MV-ACC-001.
- **Setup:** This entry exists mainly so a tester doesn't confuse it with MV-ACC-001 — on Free-only (no BuddyNext), this filter is answered SOLELY by the Members Only checkbox; there's no separate "private community" concept in Free's own UI.
- **Steps:** N/A for pure Free testing — this is here for completeness; do not spend test time hunting for a second, separate "private community" toggle in Free's Settings screens, since there isn't one.
- **Expected:** N/A.
- **UX expectation:** N/A.
- **Settings that change it:** N/A on Free-only.
- **Edge cases:** N/A.

#### MV-ACC-005 — Suspended member cannot write
- **Edition:** Free
- **Who:** Admin (suspends via wp-admin Member Moderation screen — outside this frontend-only catalog's scope to set up, but the resulting frontend behaviour is in scope); the suspended Member
- **Where:** Any write action (upload, comment, react, message, etc.) attempted by a suspended member.
- **Setup:** Have an admin suspend a test member's account via the admin Member Moderation screen.
- **Steps:** 1. As the suspended member, try to upload, comment, or send a message.
- **Expected:** Every write is refused (`RestGuards::deny_if_suspended()` runs on every write route). Reads (viewing content) are unaffected.
- **UX expectation:** The refusal should surface as a clear message, not a generic network error — Record the exact wording shown to a suspended member attempting a write.
- **Settings that change it:** the suspension itself is an admin action per-member, not a global setting.
- **Edge cases:** A suspended member holding a valid Application Password (e.g. for API/app access) is STILL blocked by this specific guard — it's the one thing that stops them, since WordPress core's own login filter chain doesn't apply to Application Password requests.

---

### Area: BuddyPress integration (BP)

#### MV-BP-001 — Member profile "Media" tab
- **Edition:** Free
- **Who:** Visitor, Member (visiting a BuddyPress member's profile)
- **Where:** `{member-profile}/media/` — a top-level BuddyPress nav item "Media" (slug `media`), with sub-tabs "Media" (slug `all`, the default), "Albums" (slug `albums`), and "Documents" (slug `documents`, only present when the documents feature is enabled).
- **Setup:** BuddyPress active, a member with uploads and at least one album.
- **Steps:** 1. Visit a BuddyPress member's profile. 2. Click the "Media" nav item. 3. Switch between its "Media"/"Albums" sub-tabs. 4. Check for "Documents" with the documents switch on vs. off.
- **Expected:** Real thumbnails render (not a raw page URL / broken image), matching the member's viewable uploads. "Documents" sub-tab is present only when the documents feature is enabled site-wide.
- **UX expectation:** Empty state (no broken `<img>` tags) when the member has nothing to show under a given sub-tab.
- **Settings that change it:** the documents master switch (for the Documents sub-tab's presence).
- **Edge cases:** This tab must respect the SAME privacy rules as the standalone `/media/@user/` profile — a stranger should not see more (or less) here than there.

#### MV-BP-002 — Group "Media" tab
- **Edition:** Free
- **Who:** Visitor, Member (visiting a BuddyPress group)
- **Where:** `{group}/media/` — a single BuddyPress subnav item "Media" (slug `media`) on the group's own nav, present only if the BP Groups component is active.
- **Setup:** BuddyPress Groups active, a group with media uploaded to it (privacy `group`).
- **Steps:** 1. Visit a group. 2. Click "Media". 3. Navigate to a single album within the group tab (routed internally, not as a separate BP subnav item, since BP doesn't render nested subnavs in groups).
- **Expected:** Group-scoped media renders; a non-member of a private group sees "no media in this group" (or is denied per the group's own BP visibility) rather than the group's actual content.
- **UX expectation:** Same "no broken img tags" empty-state bar as MV-BP-001.
- **Settings that change it:** none beyond the group's own BuddyPress privacy.
- **Edge cases:** none beyond the shared rendering logic with MV-BP-001 (`BaseBPTabIntegration`).

#### MV-BP-003 — Upload creates a BuddyPress activity item
- **Edition:** Free
- **Who:** Member (uploader); activity is then visible per its own privacy to other Members/Visitors
- **Where:** BuddyPress activity stream (site-wide, profile, or group), triggered by a normal MediaVerse upload.
- **Setup:** BuddyPress Activity component active.
- **Steps:** 1. Upload a public item. 2. Check the site-wide activity stream. 3. Upload a `private` or `dm` item and re-check.
- **Expected:** Public (and any non-private/non-dm privacy) upload creates a real `bp_activity_add()` entry. A `private` or a `dm`-privacy upload deliberately writes NO activity row at all (so DM attachments never leak into the public activity stream).
- **UX expectation:** The activity card's media preview follows the LOCKED visual spec: a single image preview is a fixed 64px square (never a "hero" 200-320px preview regardless of file count); 2-6 images use a CSS grid with per-count column templates, collapsing to 2 columns at ≤640px. This is an explicit regression lock — measure it, don't eyeball it.
- **Settings that change it:** none beyond privacy itself gating whether an activity row is written.
- **Edge cases:** Deleting the media afterward must clean up its associated activity entry (no dead activity card advertising deleted content).

#### MV-BP-004 — Comment syncs into BuddyPress activity comments
- **Edition:** Free
- **Who:** Member (commenter)
- **Where:** A media item's activity card in the BP stream.
- **Setup:** A media item that has an activity entry (per MV-BP-003).
- **Steps:** 1. Comment on the media item via its own single-media page. 2. Check the activity card in the BP stream.
- **Expected:** The comment mirrors into the BP activity's own comment thread (via `bp_activity_add()` for the comment too), so a follower of the activity stream sees the conversation without visiting the media page directly.
- **UX expectation:** Editing/deleting the original comment (MV-MED-006/007) should ideally keep the two in sync — Check whether an edit or delete on the media page's comment propagates to the mirrored BP activity comment, or whether they can drift.
- **Settings that change it:** none.
- **Edge cases:** none beyond the sync-drift check above.

#### MV-BP-005 — Activity privacy filtering matches media privacy
- **Edition:** Free
- **Who:** Visitor, Member (any BP activity-stream viewer)
- **Where:** Site-wide activity stream, profile activity, group activity, and BP's own REST/AJAX load-more.
- **Setup:** Media uploaded at several different privacy levels (public, members, friends, group, private).
- **Steps:** 1. As a stranger, scroll the site-wide activity stream. 2. As a friend of the poster, do the same. 3. As a fellow group member, check group-scoped activity.
- **Expected:** Every activity row for a MediaVerse upload is filtered by the SAME privacy rule the media item itself uses — a stranger never sees a friends-only or group-only upload's activity card, regardless of where in BP they encounter it (stream, profile, group, or paginated load-more).
- **UX expectation:** The filtering must hold consistently across pagination — a privacy-denied item should not "leak through" on page 2+ of the activity stream if it was correctly hidden on page 1.
- **Settings that change it:** none beyond the media item's own privacy level.
- **Edge cases:** none beyond the pagination consistency check above.

#### MV-BP-006 — Activity composer's attach-media control and privacy picker
- **Edition:** Free
- **Who:** Member (posting a new activity update)
- **Where:** BuddyPress "What's new" activity composer.
- **Setup:** BuddyPress Activity active.
- **Steps:** 1. Open the composer. 2. Click "Attach media". 3. Check it sits alongside the privacy picker.
- **Expected:** A proper button (not an icon-only bare box) with a visible "Attach media" label and an image-plus icon, `aria-label` present. It sits in the same row as the activity's own privacy select, both at a consistent height and border style — this is a locked visual spec against a specific theme conflict (Reign's own `<select>` styling), not a general aesthetic preference.
- **UX expectation:** No vertical misalignment between the attach-media button and the privacy select on themes known to fight this (verify specifically on wb-reign-theme if available, since that's the documented regression source).
- **Settings that change it:** none — this is a fixed template/CSS contract, not a toggle.
- **Edge cases:** none beyond the alignment check above.

#### MV-BP-007 — BuddyPress notification bell is the single source (no double bell)
- **Edition:** Free
- **Who:** Member
- **Where:** BuddyPress's own notifications nav bell vs. the My Media dashboard's own bell (MV-NTF-001).
- **Setup:** BuddyPress active, a MediaVerse notification triggered.
- **Steps:** 1. Trigger a notification (e.g. a follow). 2. Check the BP nav bell. 3. Visit the My Media dashboard and check its own bell.
- **Expected:** Only the BP nav bell shows MediaVerse notifications when BuddyPress is active. The dashboard's own `.mvs-notification-bell` is suppressed on a BP-active site to avoid a double-render of the same notifications in two places.
- **UX expectation:** No duplicate notification count/entry visible to the member across both surfaces at once.
- **Settings that change it:** none — determined automatically by BuddyPress's presence.
- **Edge cases:** none.

#### MV-BP-008 — Handing the frontend to a community layer (BuddyNext)
- **Edition:** Free
- **Who:** N/A (an integration-boundary check, not a member-facing action)
- **Where:** Any MediaVerse-owned surface, when a host community plugin sets the `mvs_buddynext_active` filter true.
- **Setup:** This only applies when BuddyNext (or an equivalent host) is active; on plain BuddyPress or standalone Free, this filter is false/absent and MediaVerse renders its own full chrome as normal.
- **Steps:** N/A for a pure Free+BuddyPress-only test session — flag if this filter is somehow true without BuddyNext installed, since that would be a misconfiguration, not a MediaVerse bug.
- **Expected:** When true, MediaVerse stands down its own assets, panels and notification bell, deferring the UX entirely to the host (BuddyNext). This is out of scope for a Free-only + plain-BuddyPress QA pass.
- **UX expectation:** N/A for this catalog's scope.
- **Settings that change it:** N/A — set by the host plugin, not by anything in MediaVerse's own Settings screens.
- **Edge cases:** N/A.

---


### Area: SET — Settings

#### MV-SET-001 — Fair-use storage limit per member
- **Edition:** Free
- **Who:** Site owner/admin sets it (`manage_options` or `manage_mvs_settings`); every uploading member is bound by it.
- **Where:** MediaVerse > Settings > General
- **Setup:** None.
- **Steps:** 1. Open Settings > General. 2. Set "Fair-use storage limit per member (MB)" to a small number (e.g. 5). 3. Save. 4. As a member, upload files until the cap is exceeded.
- **Expected:** Option `mvs_storage_limit_mb` persists (default `0` = no limit). Once a member's used storage plus the new file exceeds the limit, the upload is refused. A per-user override exists as user meta `mvs_storage_limit_mb` (blank = falls back to site limit, `0` = unlimited for that user) — set on the member's profile screen (see MV-ADM-016).
- **UX expectation:** Settings screen shows the field labelled "Fair-use storage limit per member (MB)", not the raw option key. Saving shows the standard WordPress "Settings saved." admin notice. The upload UI must show a clear, specific error (not a generic failure) telling the member they are over their storage limit, not a silent drop. No confirmation needed to change a numeric limit (non-destructive). A per-user profile field left blank must visibly say it falls back to the site limit — not read as "0 MB".
- **Settings that change it:** "Fair-use storage limit per member (MB)" (`mvs_storage_limit_mb`), default `0`. Filter: `mvs_storage_limit_bytes`.
- **Edge cases:** 0 = unlimited, not "no uploads". Per-user override always wins over the site value when set.

#### MV-SET-002 — Max Upload Size
- **Edition:** Free
- **Who:** Admin sets; applies to every uploading role.
- **Where:** MediaVerse > Settings > General
- **Setup:** None.
- **Steps:** 1. Set a low value (e.g. 1 MB, entered in the field's unit). 2. Save. 3. Attempt to upload a larger file as a member.
- **Expected:** Option `mvs_max_upload_size` (integer, bytes; default `104857600` = 100 MB) persists. An oversized upload is rejected with a clear size-limit message, not a generic 500 or silent failure.
- **UX expectation:** Field label reads "Max Upload Size", value shown in a human unit (MB), not raw bytes. Rejection message names the limit so the member knows what to do next, not just "Upload failed."
- **Settings that change it:** "Max Upload Size" (`mvs_max_upload_size`), default `104857600` (100 MB).
- **Edge cases:** Value is still capped by PHP's own `upload_max_filesize` / `post_max_size` — a MediaVerse limit larger than the server's is silently ineffective. Confirmed: the settings screen has no warning about this mismatch at all. A site owner who sets a MediaVerse limit above what PHP allows gets no on-screen hint that the server will quietly win.

#### MV-SET-003 — Allowed File Types
- **Edition:** Free
- **Who:** Admin sets; applies to every uploading role.
- **Where:** MediaVerse > Settings > General
- **Setup:** None.
- **Steps:** 1. Untick a file type (e.g. video/mp4). 2. Save. 3. Attempt to upload that type.
- **Expected:** Option `mvs_allowed_file_types` (comma string; default `image/jpeg,image/png,image/gif,image/webp,video/mp4,video/webm,audio/mpeg,audio/ogg`) persists as ticked. A disallowed type is refused at upload with a message naming the allowed types, not a generic error. Documents (PDF/Office) are hard-refused regardless of this setting (`UploadService::hard_refused_mimes()`) — ticking nothing here does not open the door to documents.
- **UX expectation:** Checkboxes show human file-type labels ("JPEG images", "MP4 video") not MIME strings. The upload form's own "accept" hint should match what is actually allowed, so a member isn't invited to pick a file the server will then reject.
- **Settings that change it:** "Allowed File Types" (`mvs_allowed_file_types`).
- **Edge cases:** Confirmed: unticking every box and saving does neither "allow everything" nor "block everything" silently. The save is refused outright and the previous selection is kept, with an on-screen notice: "Pick at least one file type. With none ticked, members could not upload anything, so the previous selection was kept."

#### MV-SET-004 — Duplicate Detection
- **Edition:** Free
- **Who:** Admin sets; affects every uploader.
- **Where:** MediaVerse > Settings > General
- **Setup:** Have one file already uploaded, ready to re-upload the identical bytes.
- **Steps:** 1. Set to "Block the upload" (`skip`). 2. Save. 3. Re-upload the identical file (same SHA-256). 4. Repeat with "Allow" (`warn`) and "Skip check" (`allow`).
- **Expected:** `mvs_duplicate_action` accepts `warn` (allow + warn), `skip` (labelled "Block the upload", refuses), `allow` (no hash check at all). Default `warn`.
- **UX expectation:** The warn path must show the member an actual on-screen warning (not just log it) that this file was already uploaded, with a way to proceed or cancel. The block path must explain WHY the upload didn't go through, not fail silently.
- **Settings that change it:** "Duplicate Detection" (`mvs_duplicate_action`), default `warn`.
- **Edge cases:** Detection is a global byte-hash match — two different members uploading the same public domain image will collide with each other's files, not just their own.

#### MV-SET-005 — Remove location from photos (EXIF GPS strip)
- **Edition:** Free
- **Who:** Admin sets; applies to every image upload.
- **Where:** MediaVerse > Settings > General
- **Setup:** A JPEG with GPS EXIF data.
- **Steps:** 1. Confirm the setting is on (default). 2. Upload the GPS-tagged JPEG. 3. Download the stored original and inspect its EXIF.
- **Expected:** `mvs_strip_exif` (boolean, default `true`) removes only the GPS IFD pointer/data; camera make/model, exposure, and IPTC/XMP copyright/credit survive. Files already stored before the setting was toggled are never rewritten retroactively. Also runs on `/media/{id}/replace`.
- **UX expectation:** No UI feedback needed per upload (this is silent-by-design privacy protection), but the setting's on-screen description must say "location only" so an owner doesn't believe photo credits are being stripped too. Turning it off must not retroactively restore GPS to already-stripped files (no false expectation).
- **Settings that change it:** "Remove location from photos" (`mvs_strip_exif`), default `true`.
- **Edge cases:** Fails closed — if the EXIF parser can't read the segment, the whole segment is dropped rather than leaving GPS data behind.

#### MV-SET-006 — Remove Data on Delete (uninstall)
- **Edition:** Free
- **Who:** Admin sets; consulted only at plugin uninstall (not deactivation).
- **Where:** MediaVerse > Settings > General
- **Setup:** A test install expendable enough to actually uninstall.
- **Steps:** 1. Leave the box unticked, deactivate then delete the plugin from Plugins list. Confirm tables/media survive (reinstalling shows old data). 2. On a throwaway copy, tick the box, then delete the plugin. Confirm tables/options/postmeta/capabilities are gone.
- **Expected:** `mvs_delete_data_on_uninstall` (boolean, default `false`). When true, uninstall drops all 23+ `mvs_*` tables, plugin options, related post meta, and role capabilities. Uploaded files and the pages MediaVerse created are NEVER touched by this setting either way.
- **UX expectation:** Description text must warn this is irreversible ("removes media records, albums, messages, settings and permissions... cannot reinstall to recover them") before the admin ticks it — this is a destructive, delayed-effect setting, so the warning has to be visible at tick-time, not just in a confirm dialog at uninstall (WordPress's own plugin-delete confirm is generic and won't mention this).
- **Settings that change it:** "Remove Data on Delete" (`mvs_delete_data_on_uninstall`), default `false`.
- **Edge cases:** If MediaVerse Pro is also installed, Free's uninstall must NOT drop tables/options while Pro is still relying on them — the option is meant to be read by whichever plugin is uninstalled last.

#### MV-SET-007 — Storage driver (Where files are stored)
- **Edition:** Free (Free is `local`-only; cloud drivers need Pro)
- **Who:** Admin sets.
- **Where:** MediaVerse > Settings > Storage
- **Setup:** None for Free (no Pro license).
- **Steps:** 1. Open Storage tab. 2. Confirm only "Local" is usable without Pro (cloud options present but inert/locked).
- **Expected:** `mvs_storage_driver` (string, default `local`); accepts `local`, `s3`, `bunnycdn`, `r2`, `dospaces` but the latter four require Pro active.
- **UX expectation:** If Pro isn't active, cloud options must be visibly disabled/locked with an explanation ("Requires MediaVerse Pro"), not selectable-but-broken. A constant defined in `wp-config.php` for a credential renders that field locked with "Defined in wp-config.php" and the stored option is ignored — the UI must say so, not show a blank editable field that silently does nothing.
- **Settings that change it:** "Where files are stored" (`mvs_storage_driver`), default `local`.
- **Edge cases:** Switching drivers does not move existing files — that requires `wp mvs migrate-storage` (see MV-CLI-011).

#### MV-SET-008 — Compress uploaded images (Optimize Originals)
- **Edition:** Free
- **Who:** Admin sets; applies to every image upload.
- **Where:** MediaVerse > Settings > Storage
- **Setup:** A JPEG larger than the target quality would produce.
- **Steps:** 1. Turn on. 2. Save. 3. Upload a large JPEG. 4. Compare stored file size to the original.
- **Expected:** `mvs_optimize_originals` (boolean, default `false`). Re-encodes at quality 92 and commits ONLY if the result is smaller (temp-write-compare-rename) — never lossy-upsizes, never replaces with a larger file.
- **UX expectation:** No destructive surprise: a source already below q92 is left byte-identical, so re-running optimization on the same file twice shows no further size change (idempotent) — a QA tester should see this and not read it as "broken."
- **Settings that change it:** "Compress uploaded images" (`mvs_optimize_originals`), default `false`.
- **Edge cases:** Not lossless; a customer expecting bit-identical originals must leave this off.

#### MV-SET-009 — Backend-only storage tuning (no screen control since 2.6.0)
- **Edition:** Free
- **Who:** Admin via WP-CLI/`wp option`, or a developer via a filter/mu-plugin — not reachable from any settings screen.
- **Where:** `wp option get/update <key>` (no admin UI)
- **Setup:** WP-CLI access.
- **Steps:** 1. `wp option get mvs_signed_url_ttl` (expect `3600`). 2. `wp option update mvs_signed_url_ttl 600`. 3. Repeat for `mvs_view_retention_days` (default `90`), `mvs_filename_strategy` (default `hashed`), `mvs_generate_webp` (default `true`), `mvs_generate_avif` (default `false`).
- **Expected:** Each option is still registered and functionally live (signed URL TTL changes how long a signed media link works; retention controls how long view-event rows are kept before cron prunes them; filename strategy controls stored filenames; WebP/AVIF control which derivative formats are generated) — they were removed from the visible settings screen because most sites want the default, not because they stopped working.
- **UX expectation:** None of these need a "saved" UI notice since there is no screen — but a tester should confirm `wp option update` actually changes runtime behavior (e.g. a new signed URL respects the new TTL) rather than silently being ignored.
- **Settings that change it:** As listed; all "no screen control since 2.6.0 (set in code/WP-CLI)".
- **Edge cases:** `mvs_cloud_direct_public_urls` is a fifth option in this family that is fully INERT since 1.4.0 — changing it has no observable effect at all; do not report that as a bug.

#### MV-SET-010 — Default Privacy Level
- **Edition:** Free
- **Who:** Admin sets the default; every uploading member is affected unless they override it per-upload (MV-SET-011).
- **Where:** MediaVerse > Settings > General
- **Setup:** None.
- **Steps:** 1. Set to "Members Only". 2. Save. 3. Upload as a member without touching the privacy control (or with "Allow Users to Set Privacy" off). 4. Check the stored item's privacy.
- **Expected:** `mvs_default_privacy` (string) accepts `public`/`members`/`private`. Default is `public` on a normal site, but `members` on a fresh install of a private community (BuddyNext private mode) — `PrivateCommunityDefault::default_privacy()` decides this at install time, not afterward.
- **UX expectation:** If the site later becomes private while this is still `public`, the admin must see the one-time dismissible notice from MV-ADM-021 offering to switch — the setting itself must NOT silently change under them.
- **Settings that change it:** "Default Privacy Level" (`mvs_default_privacy`), default `public` (or `members` on private-community install).
- **Edge cases:** Changing this later never touches already-uploaded media, only future uploads.

#### MV-SET-011 — Allow Users to Set Privacy
- **Edition:** Free
- **Who:** Admin sets; affects every uploading member.
- **Where:** MediaVerse > Settings > General
- **Setup:** None.
- **Steps:** 1. Turn off. 2. As a member, open the upload form. 3. Confirm no privacy selector is shown and the upload lands at the site default. 4. Attempt `PUT /mvs/v1/media/{id}` changing privacy directly via the API as that member.
- **Expected:** `mvs_allow_user_privacy` (boolean, default `true`). When off, the privacy select disappears from the upload form, album create/update, the BuddyPress activity form/picker and every template picker — one switch, all surfaces (since 2.5.1). A direct API attempt to CHANGE the privacy level while this is off returns 403 `mvs_privacy_locked`; re-sending the SAME level still succeeds. Anyone with `manage_mvs_settings` keeps full control regardless.
- **UX expectation:** The 403 must not just be a raw JSON error to a mobile app — the frontend/app should present "Your community doesn't allow changing privacy" rather than a blank failure. The upload form's disappearance of the control must not leave an orphaned empty section.
- **Settings that change it:** "Allow Users to Set Privacy" (`mvs_allow_user_privacy`), default `true`.
- **Edge cases:** This is a single unified switch since 2.5.1 — before that, only upload obeyed it and a member could edit privacy right after; verify this is not regressed.

#### MV-SET-012 — Who can upload media
- **Edition:** Free
- **Who:** Admin sets; determines which roles get `upload_mvs_media`.
- **Where:** MediaVerse > Settings > General
- **Setup:** A non-admin test user in each candidate role.
- **Steps:** 1. Untick Subscriber. 2. Save. 3. Log in as a Subscriber and attempt `POST /mvs/v1/media` and the frontend upload form.
- **Expected:** `mvs_upload_roles` is a pure UI transport array; the real effect is on the `upload_mvs_media` capability, added/removed per role on save (`MediaCapabilities::apply_role_selection()`). A Subscriber without the cap gets 403 `mvs_forbidden` from the API and the frontend upload control should not be shown/should be disabled. Administrators can always upload regardless of this list.
- **UX expectation:** Reading the option back (e.g., via `wp option get mvs_upload_roles`) is NOT the source of truth — a tester who checks only the option value and not the actual role capability will be misled; the settings-reference explicitly warns of this. The UI checkboxes must reflect the CURRENT capability state on page load, not a stale option value.
- **Settings that change it:** "Who can upload media" (`mvs_upload_roles`), default empty array (transport only).
- **Edge cases:** A separate, more granular Permissions matrix (Edit Own/Others, Delete Own/Others, Moderate, Manage Settings per role) exists in code (`PermissionsManager`) but has NO settings-tab entry since 2.6.0 — see MV-ADM-022. This checkbox only controls Upload.

#### MV-SET-013 — Allow Downloads
- **Edition:** Free
- **Who:** Admin sets; affects every viewer of media that shows a download control.
- **Where:** MediaVerse > Settings > Display
- **Setup:** None.
- **Steps:** 1. Turn off. 2. View a media item on the frontend as a member.
- **Expected:** `mvs_allow_downloads` (boolean, default `true`). Download controls/links disappear or are disabled site-wide when off.
- **UX expectation:** Off should not leave a dead "Download" button that does nothing on click — the control itself should be absent.
- **Settings that change it:** "Allow Downloads" (`mvs_allow_downloads`), default `true`.
- **Edge cases:** Turning this off does not prevent right-click-save on a publicly-served file; it only removes the plugin's own download affordance.

#### MV-SET-014 — Members Only (community-wide privacy gate)
- **Edition:** Free
- **Who:** Admin sets on a standalone site; if a community plugin (e.g. BuddyNext) already answers this question, the field is locked and read-only.
- **Where:** MediaVerse > Settings > General
- **Setup:** For the "host decides" path, BuddyNext (or another plugin filtering `mvs_rest_require_auth`) must be active.
- **Steps:** 1. On a standalone site (no BuddyNext), tick "Members Only". 2. Log out and visit Explore, a media page, a profile, or Compete. 3. Attempt a `GET /mvs/v1/media` call logged out. 4. Reinstall/activate a community plugin that sets `mvs_rest_require_auth`, revisit the setting.
- **Expected:** `mvs_members_only` (boolean, default `false`) arms `CommunityPrivacyGate`. When on (standalone) or when a community plugin's filter answers true, logged-out visitors are redirected to the login page from every MVS frontend surface, and the API refuses them (403). When a community plugin decides, the field renders LOCKED, showing that plugin's current answer, with copy explaining the switch must be changed in that plugin's own settings.
- **UX expectation:** The locked state MUST show the current effective value (on/off) and WHY it's locked, in plain English ("your community plugin keeps this site private... change it in that plugin's settings") — not a greyed-out checkbox with no explanation. This is exactly the "locked/read-only state with its explanation" case the owner called out.
- **Settings that change it:** "Members Only" (`mvs_members_only`), default `false`. Filters: `mvs_rest_require_auth`, `mvs_rest_can_access`.
- **Edge cases:** `/serve` (signed media) and `/app/config` stay exempt from this gate even when Members Only is on — a signed URL a logged-out person already holds still works.

#### MV-SET-015 — Mobile App tab (App Sign-In, Terms, Abuse Contact, EULA)
- **Edition:** Free
- **Who:** Admin sets.
- **Where:** MediaVerse > Settings > Mobile App
- **Setup:** None.
- **Steps:** 1. Turn off "App Sign-In". 2. Attempt `POST /mvs/v1/auth/app-password` as a member. 3. Set a Terms of Service URL and Abuse Contact Email; check `GET /mvs/v1/app/config`.
- **Expected:** `mvs_app_password_login` (boolean, default `true`) — off refuses the Application Password exchange (filterable via `mvs_app_password_login_enabled`). `mvs_terms_url` / `mvs_abuse_contact_email` (both empty by default, contact falls back to site admin email) surface in the app config endpoint. `mvs_eula_url` (empty default, "standard Apple licence" fallback) has no screen control since 2.6.0.
- **UX expectation:** All four fields moved to this tab in 2.6.0 from General/Moderation — a tester following old docs/screenshots looking on General or Moderation for these fields is expected to be redirected mentally to Mobile App; the settings page itself must not still show these fields duplicated on the old tabs.
- **Settings that change it:** As listed.
- **Edge cases:** Confirmed: turning off App Sign-In does NOT revoke Application Passwords already issued, and it does not even stop them from working. This setting only gates the `/auth/app-password` exchange endpoint (issuing a NEW credential) — WordPress's own native Application Password authentication for credentials already issued keeps working regardless of this toggle. A member who already has an app password can keep using it after the admin turns App Sign-In off.

#### MV-SET-016 — Layout (Display)
- **Edition:** Free
- **Who:** Admin sets; affects every visitor's grid rendering.
- **Where:** MediaVerse > Settings > Display
- **Setup:** None.
- **Steps:** 1. Choose "List" from the Layout select. 2. Save. 3. View Explore/dashboard/album/collection pages on the frontend.
- **Expected:** The one Layout select is a UI transport: a Free choice (`square`/`original`/`list`) writes `mvs_thumbnail_style` (default `original`) and resets the Pro feed layout back to `grid`; a Pro skin choice writes `mvs_pro_feed_layout` instead. Since 2.5.1 this setting also reaches album and collection pages, not only Explore/dashboard.
- **UX expectation:** The single select must show only options the current edition supports — a Free-only site should not see Pro skin names (Instagram/Pinterest/Flickr/Dribbble) as selectable, or if shown, must clearly mark them "Pro" and not silently apply them without Pro active.
- **Settings that change it:** "Layout" (`mvs_layout_choice` → `mvs_thumbnail_style` / `mvs_pro_feed_layout`), default `original`.
- **Edge cases:** Since 2.5.1 this changed album/collection appearance for existing sites that never touched the setting — a QA regression check should confirm this was communicated, not just silently applied.

#### MV-SET-017 — Grid Columns
- **Edition:** Free
- **Who:** Admin sets; affects grid layout site-wide.
- **Where:** MediaVerse > Settings > Display
- **Setup:** None.
- **Steps:** 1. Set to 5. 2. Save. 3. View Explore on desktop and at 390px width.
- **Expected:** `mvs_grid_columns` (integer, default `3`) changes the grid column count. Responsive behavior should still collapse to fewer columns on narrow viewports regardless of the configured desktop count.
- **UX expectation:** At 390px, 5 desktop columns must NOT render as 5 tiny unreadable columns — the responsive breakpoint must override the numeric setting on mobile.
- **Settings that change it:** "Grid Columns" (`mvs_grid_columns`), default `3`.
- **Edge cases:** Explore/dashboard shortcodes deliberately do NOT accept a `columns` attribute override (Basecamp 10297763946) — an owner editing a shortcode's `columns="4"` attribute in an existing page has no effect; only this setting does.

#### MV-SET-018 — Items Per Page
- **Edition:** Free
- **Who:** Admin sets; affects every grid/list pagination.
- **Where:** MediaVerse > Settings > Display
- **Setup:** A library with more than the configured count.
- **Steps:** 1. Set to 6. 2. Save. 3. View Explore with 20+ items; confirm exactly 6 show before Load More/pagination.
- **Expected:** `mvs_items_per_page` (integer, default `12`) governs frontend pagination page size.
- **UX expectation:** Load More / pagination controls must appear only when there ARE more items than the page size — an empty or single-page library shouldn't show a dead "Load More" button.
- **Settings that change it:** "Items Per Page" (`mvs_items_per_page`), default `12`.
- **Edge cases:** Same shortcode-attribute immunity as Grid Columns — cannot be overridden per-page via shortcode attribute.

#### MV-SET-019 — Backend-only display tuning (thumbnail/lightbox source, no screen control)
- **Edition:** Free
- **Who:** Admin via WP-CLI/`wp option` only.
- **Where:** `wp option get/update <key>`
- **Setup:** WP-CLI access.
- **Steps:** 1. `wp option update mvs_lightbox_image_source original`. 2. Open the lightbox on a media item; confirm it loads the full original instead of the `large` derivative.
- **Expected:** `mvs_thumbnail_size` (default `large`), `mvs_large_image_size` (integer px, default `1024`), `mvs_lightbox_image_source` (default `large`) all still function; removed from the visible screen in 2.6.0 because most sites want the default.
- **UX expectation:** No UI notice since no screen; a tester should verify the change is reflected on the NEXT lightbox open (cache should not mask the change), not that a UI confirms anything.
- **Settings that change it:** As listed.
- **Edge cases:** `mvs_lightbox_image_source=original` trades load speed for quality — worth confirming file size served actually changes, not just the option value.

#### MV-SET-020 — Messages (master switch)
- **Edition:** Free
- **Who:** Admin sets; turns the entire messaging feature on/off site-wide.
- **Where:** MediaVerse > Settings > Messages
- **Setup:** None.
- **Steps:** 1. Turn off. 2. Confirm no messaging routes are registered (`GET /mvs/v1/me/conversations` 404s, not 403), no chat panel/Message buttons/`/messages/` page, no Pro group chat. 3. Turn back on; confirm existing conversations are intact (not deleted) and GDPR export/erase still cover them.
- **Expected:** `mvs_messaging_enabled` (boolean, default `true`; filter of the same name). `Core/Plugin::init_messaging()` skips booting the whole engine when off — this is a hard kill switch, not a soft hide.
- **UX expectation:** BuddyNext (if present) must hide its own Messages entry point when this is off — cross-plugin consistency, not a dangling menu item pointing at a dead feature. Toggling it back on should not lose any conversation history.
- **Settings that change it:** "Messages" (`mvs_messaging_enabled`), default `true`.
- **Edge cases:** Distinct from "Who can send messages: Nobody" (MV-SET-021), which is a softer stop — existing conversations stay readable, only new ones are blocked.

#### MV-SET-021 — Who can send messages
- **Edition:** Free
- **Who:** Admin sets; affects every member's ability to start a DM.
- **Where:** MediaVerse > Settings > Messages
- **Setup:** Two test members who do not follow each other.
- **Steps:** 1. Set to "Followers". 2. As member A (not followed by/following B), attempt to start a conversation with B. 3. Repeat with "Mutual" and "Nobody".
- **Expected:** `mvs_dm_access` accepts `everyone` (default), `followers`, `mutual`, `nobody` (shown as "Nobody (no new messages; existing conversations stay readable)"). A disallowed attempt is refused, existing conversations remain visible/readable under "Nobody".
- **UX expectation:** The refusal for a disallowed new conversation must be a clear, specific message ("This member only accepts messages from people they follow"), not a generic 403 the app has to guess at.
- **Settings that change it:** "Who can send messages" (`mvs_dm_access`), default `everyone`.
- **Edge cases:** "Followers" here has nothing to do with media privacy's `friends`/`group` levels — same English word, unrelated systems; don't conflate them in test reports.

#### MV-SET-022 — Minimum Account Age (messaging)
- **Edition:** Free
- **Who:** Admin sets; affects newly-registered members.
- **Where:** MediaVerse > Settings > Messages
- **Setup:** A freshly-registered test account.
- **Steps:** 1. Set to 3 days. 2. As the brand-new account, attempt to send a message. 3. Wait/backdate registration past 3 days and retry.
- **Expected:** `mvs_dm_min_age` (integer days, default `0` = no restriction). New accounts under the threshold are refused with a clear reason.
- **UX expectation:** Anti-spam framing should be visible to the blocked member ("New accounts can send messages after N days") rather than a raw permission error.
- **Settings that change it:** "Minimum Account Age (days)" (`mvs_dm_min_age`), default `0`.
- **Edge cases:** Interacts with "Who can send messages" — both gates must be satisfied.

#### MV-SET-023 — Chat Panel Visibility
- **Edition:** Free
- **Who:** Admin sets; affects where the floating/persistent chat panel appears.
- **Where:** MediaVerse > Settings > Messages
- **Setup:** None.
- **Steps:** 1. Change from "Everywhere" to a narrower value. 2. Navigate across different page types checking panel presence.
- **Expected:** `mvs_chat_panel_visibility` (string, default `everywhere`) restricts which pages show the persistent chat UI.
- **UX expectation:** Removing the panel from a page must not leave layout gaps or a broken toggle button referencing it.
- **Settings that change it:** "Chat Panel Visibility" (`mvs_chat_panel_visibility`), default `everywhere`.
- **Edge cases:** Confirmed full accepted set: `everywhere` (default), `mvs_pages` (MediaVerse pages only), `bp_pages` (BuddyPress pages only), `disabled` (chat panel off entirely).

#### MV-SET-024 — Online Status Visibility
- **Edition:** Free
- **Who:** Admin sets; affects what other members can see about a member's online state.
- **Where:** MediaVerse > Settings > Messages
- **Setup:** Two members, one messaging the other.
- **Steps:** 1. Set to a restricted value (e.g. followers-only, if available) other than "Everyone". 2. As a non-follower, view the member's profile/chat header for an online indicator.
- **Expected:** `mvs_show_online_status` (string, default `everyone`) governs visibility of online/last-seen indicators.
- **UX expectation:** A restricted visitor should see NO indicator (not a stale/wrong one) when denied.
- **Settings that change it:** "Online Status Visibility" (`mvs_show_online_status`), default `everyone`.
- **Edge cases:** Confirmed full accepted set: `everyone` (default), `followers`, `nobody`.

#### MV-SET-025 — Community Guidelines URL
- **Edition:** Free
- **Who:** Admin sets; linked from report/moderation surfaces.
- **Where:** MediaVerse > Settings > Moderation
- **Setup:** None.
- **Steps:** 1. Enter a URL. 2. Save. 3. Check the frontend report-content dialog and/or moderation-related UI for the link.
- **Expected:** `mvs_guidelines_url` (empty default) — link appears where relevant once set.
- **UX expectation:** Empty value must simply omit the link, not show a broken/empty href.
- **Settings that change it:** "Community Guidelines URL" (`mvs_guidelines_url`).
- **Edge cases:** Confirmed: there is no strict validation that rejects a non-URL string. The field passes through WordPress's standard URL-cleaning function (which strips disallowed characters/protocols) but does not reject or bounce back a value that isn't a proper URL — it saves whatever survives that cleaning, even if it no longer looks like a usable link.

#### MV-SET-026 — Member Reporting (master switch)
- **Edition:** Free
- **Who:** Admin sets; affects every member's ability to report content.
- **Where:** MediaVerse > Settings > Moderation
- **Setup:** None.
- **Steps:** 1. Turn off. 2. As a member, attempt `POST /mvs/v1/media/{id}/report`. 3. Visit MediaVerse > Moderation > Reports tab.
- **Expected:** `mvs_enable_reports` (boolean, default `true`). Off stops new reports arriving; the Reports admin screen shows a warning notice ("Member reporting is turned off, so no new reports can arrive. Turn it back on under Settings, AI & Moderation.") rather than pretending reporting still works.
- **UX expectation:** The exact warning notice text above must appear on the Reports screen when off — this is a concrete, code-verified string to check for.
- **Settings that change it:** "Member Reporting" (`mvs_enable_reports`), default `true`.
- **Edge cases:** Existing/pending reports remain visible and actionable even with reporting off.

#### MV-SET-027 — Auto-Hide Threshold
- **Edition:** Free
- **Who:** Admin sets; affects any reported media item.
- **Where:** MediaVerse > Settings > Moderation
- **Setup:** 3+ distinct test member accounts able to report the same item.
- **Steps:** 1. Confirm threshold is 3 (default). 2. Have 3 different members report the same media item. 3. Confirm the item is auto-hidden pending review, still visible to the owner/moderator.
- **Expected:** `mvs_report_auto_hide_threshold` (integer, default `3`) — number of DISTINCT reporters (not report count) after which the item auto-hides pending moderator review.
- **UX expectation:** The owner and moderators must still see the hidden item (with a "hidden pending review" indicator) in the admin moderation queue and in their own view — a takedown must stay reviewable, per CAPABILITIES.md.
- **Settings that change it:** "Auto-Hide Threshold" (`mvs_report_auto_hide_threshold`), default `3`.
- **Edge cases:** Confirmed: the same member cannot report the same item twice at all — a second attempt is refused before it's ever counted, so duplicate reports from one member can never inflate the auto-hide threshold.

#### MV-SET-028 — AI Moderation (toggle + flag behavior)
- **Edition:** Free
- **Who:** Admin sets; requires an OpenAI key (MV-SET-030) to actually run.
- **Where:** MediaVerse > Settings > Moderation (as of 2.6.0, ALL of these fields — the AI Moderation switch, the flag-action choice, the category checklist and the custom-terms box — live on the Moderation tab only. They moved off the AI tab in 2.6.0 because they are moderation decisions, not AI-provider setup. A tester following an older screenshot that shows them on the AI tab is looking at a stale reference.)
- **Setup:** A valid OpenAI API key configured.
- **Steps:** 1. Turn on "AI Moderation". 2. Set "When AI Flags Content" to "flag" (hide until reviewed). 3. Upload content that should trip a category in "AI Flag Criteria". 4. Check MediaVerse > Moderation > AI Flagged tab.
- **Expected:** `mvs_ai_auto_moderate` (boolean, default `false`); `mvs_moderation_auto_action` accepts `flag`/`reject`/`delete` (default `flag`); `mvs_ai_moderation_categories` (array, defaults to all: nudity, violence, hate, self-harm, drugs, spam — an emptied value falls back to the full list rather than disabling flagging); `mvs_ai_moderation_custom_terms` (empty default). Flagged items appear in the "AI Flagged" moderation tab (which itself is hidden when there's nothing flagged AND AI moderation is off).
- **UX expectation:** The three category/custom-term rows must show ONLY while AI Moderation is on (progressive disclosure) — showing them unconditionally is a bug. An emptied category list must not silently mean "flag nothing" — it should still visibly show/behave as "all categories."
- **Settings that change it:** As listed.
- **Edge cases:** The monthly AI budget cap (MV-SET-033) also gates moderation calls, not just describe/tag — running out of budget mid-month should degrade moderation gracefully (log/skip), not fatal.

#### MV-SET-029 — AI Provider + OpenAI API Key
- **Edition:** Free
- **Who:** Admin sets.
- **Where:** MediaVerse > Settings > AI
- **Setup:** An OpenAI API key (or a wp-config.php constant).
- **Steps:** 1. Enter the key in the field. 2. Save. 3. Alternatively, define the constant in `wp-config.php` and reload the screen.
- **Expected:** `mvs_ai_provider` (default `openai`); `mvs_openai_api_key` (empty default). `mvs_openai_model` has no screen control since 2.6.0 (default `gpt-4o-mini`).
- **UX expectation:** A key defined via `wp-config.php` constant renders the field LOCKED with "Defined in wp-config.php" text and the stored DB option must be ignored, not merged/overridden confusingly. The key value itself should be masked, not echoed back in plaintext in the HTML source.
- **Settings that change it:** As listed.
- **Edge cases:** An invalid/expired key should surface as a clear failure in AI-dependent features (moderation, auto-describe/tag) rather than a silent no-op.

#### MV-SET-030 — Auto-Analyze Uploads
- **Edition:** Free
- **Who:** Admin sets; affects every upload once AI is configured.
- **Where:** MediaVerse > Settings > AI
- **Setup:** Valid OpenAI key.
- **Steps:** 1. Turn on. 2. Upload an image. 3. Check the AI status column on MV-ADM-004 (All Media) for "Processing" → "Complete".
- **Expected:** `mvs_ai_auto_analyze` (boolean, default `false`).
- **UX expectation:** Processing state must be visible (not just eventually-consistent with no feedback) — the AI column badge states (Processing/Complete/Accepted/Rejected/Failed/Pending) must reflect real-time status.
- **Settings that change it:** "Auto-Analyze Uploads" (`mvs_ai_auto_analyze`), default `false`.
- **Edge cases:** A failed AI call (bad key, budget exceeded) must land in "Failed" status visibly, not hang in "Processing" forever.

#### MV-SET-031 — Generate Descriptions / Generate Tags / Auto-Apply Tags
- **Edition:** Free
- **Who:** Admin sets.
- **Where:** MediaVerse > Settings > AI
- **Setup:** Valid OpenAI key, Auto-Analyze or equivalent trigger on.
- **Steps:** 1. Turn on "Generate Tags" but leave "Auto-Apply Tags" off. 2. Upload media. 3. Confirm tags are SUGGESTED (visible for review) but not attached automatically. 4. Turn on Auto-Apply Tags and repeat — confirm tags now attach automatically.
- **Expected:** `mvs_ai_auto_describe` (default `true`), `mvs_ai_auto_tag` (default `true`), `mvs_ai_auto_apply_tags` (default `false`). The pairing is deliberate: generating without applying is the documented "safe starting configuration."
- **UX expectation:** The review-then-apply distinction must be visible in the admin UI (e.g. the AI Review link on MV-ADM-006) — a reviewer needs to see suggested tags distinctly from applied ones.
- **Settings that change it:** As listed.
- **Edge cases:** Turning off "Generate Tags" while "Auto-Apply Tags" stays on has nothing to apply — should not error.

#### MV-SET-032 — Monthly AI Budget ($)
- **Edition:** Free
- **Who:** Admin sets; caps spend across describe/tag/moderate.
- **Where:** MediaVerse > Settings > AI
- **Setup:** Enough AI-triggering uploads to approach the cap at the configured cost-per-call.
- **Steps:** 1. Set a very low budget (e.g. $0.05). 2. Trigger enough AI calls to exceed it. 3. Confirm further AI calls stop and the Stats page (MV-ADM-017) AI Usage panel shows the cutoff.
- **Expected:** `mvs_ai_monthly_budget` (number, default `10`; `0` = no limit), paired with `mvs_ai_cost_per_call` (default `0.01`, no screen control, filterable).
- **UX expectation:** The Stats > AI Usage panel must show Budget alongside API Calls/Successful/Failed/Cost so an owner can see WHY calls stopped, not just that they stopped.
- **Settings that change it:** "Monthly AI Budget ($)" (`mvs_ai_monthly_budget`), default `10`.
- **Edge cases:** Confirmed: the budget resets on the calendar month boundary (spend is tracked per "YYYY-MM"), not a rolling 30-day window. Spend resets to zero on the 1st of each month regardless of when in the previous month the budget was hit.

#### MV-SET-033 — Email notification toggles (battle invites, documents shared, report outcome)
- **Edition:** Free
- **Who:** Admin sets per-notification-type; affects whether members receive these transactional emails.
- **Where:** MediaVerse > Settings > General (Emails section)
- **Setup:** A member with a triggering event pending (e.g. someone reports their content).
- **Steps:** 1. Turn on "Report reviewed". 2. Resolve a report on that member's content. 3. Confirm the member receives an email. 4. Turn it off and repeat — confirm no email.
- **Expected:** `mvs_email_battle_invite`, `mvs_email_document_shared`, `mvs_email_report_outcome` start ON on a new install and OFF on a site updated to 2.6.0 (so an update never starts mailing members). Test both: a fresh install, and an update from 2.5.x.
- **UX expectation:** Each toggle's label should name the actual trigger event plainly ("Report reviewed" = "we tell the reporter/reported member what happened to their report").
- **Settings that change it:** As listed.
- **Edge cases:** Confirmed: "Photo battle invites" is a Pro-gamification-adjacent notification type shipped in Free's email settings, and it genuinely has no visible effect without MediaVerse Pro's Battles feature active — nothing in Free ever triggers a battle-invite notification, only Pro's Battles code does. Toggling it on a Free-only site does nothing observable.

#### MV-SET-034 — Pages (Explore/My Media/Upload/Explore Documents)
- **Edition:** Free
- **Who:** Admin sets (or Activator sets automatically at install).
- **Where:** MediaVerse > Settings > General (Pages section)
- **Setup:** None.
- **Steps:** 1. Confirm each field already points at a real published page (set by activation). 2. Change one to point at a different page containing the correct shortcode. 3. Save and confirm the frontend link (e.g. Overview's "Explore Page" quick link) follows the new value.
- **Expected:** `mvs_page_dashboard`, `mvs_page_explore`, `mvs_page_upload`, `mvs_page_explore_documents` — all integer post IDs, default `0` (unset) until Activator runs.
- **UX expectation:** A `0`/unset value must show as "Missing" with a clear next action (as Overview does — see MV-ADM-001), never a broken link.
- **Settings that change it:** As listed.
- **Edge cases:** `mvs_page_explore_documents` is only created by Activator when documents are Pro-enabled OR legacy `legacy_document` rows exist — a Free-only fresh install correctly has NO value here; that is not a bug.

#### MV-SET-035 — Webhook Configuration
- **Edition:** Free
- **Who:** Admin sets; sends events to an external URL.
- **Where:** MediaVerse > Settings > Webhooks
- **Setup:** A receiving endpoint (e.g. a request-bin URL) to inspect payloads.
- **Steps:** 1. Add a webhook for `media.uploaded`. 2. Save. 3. Upload a media item. 4. Inspect the received payload for the signature header and event shape. 5. Temporarily break the endpoint (return 500) and confirm up to 3 retries occur.
- **Expected:** `mvs_webhooks` (array, empty default). 5 events: `media.uploaded`, `media.deleted`, `media.moderated`, `media.reaction`, `media.comment`. Payloads are signed. Retries need Action Scheduler; without it a failed delivery logs to `mvs_error_log` and is dropped (no retry).
- **UX expectation:** The settings UI must let an admin add/remove/edit webhook URLs and per-event toggles without touching code. Confirmed: there is no "send test event" / test-fire control anywhere in the UI — an admin cannot verify a webhook is wired correctly without triggering a real event (e.g. a real upload) and checking their receiving endpoint.
- **Settings that change it:** "Webhook Configuration" (`mvs_webhooks`), default empty array.
- **Edge cases:** With Action Scheduler absent, failures are silently capped at 1 attempt — an owner relying on retries needs Action Scheduler present (it ships bundled under `libs/`, so this should normally be available).

### Area: ADM — Admin screens

#### MV-ADM-001 — Overview / Dashboard page
- **Edition:** Free
- **Who:** Any role with `mvs_settings_screen` cap (admin by default); refused otherwise.
- **Where:** MediaVerse (top-level menu, first item — `admin.php?page=wpmediaverse`)
- **Setup:** None (works empty).
- **Steps:** 1. Open the page as admin. 2. As a role without the cap, attempt the same URL directly.
- **Expected:** Stat tiles (Total Media, Documents, Albums, Pending Review, Total Views, Storage Used); Quick Links (All Media, Settings, Moderation, Stats); Frontend Pages status block (Active/Missing per page, with a link to fix if missing); Recent Uploads table (or "No media uploaded yet." + "Upload First Media" CTA when empty); a first-run Welcome checklist (Configure settings / Upload first media / Customize permissions). A capability-less role gets `wp_die()` with "You do not have permission to access this page."
- **UX expectation:** Empty states use the exact copy above, not a blank table. Numbers must be live counts (re-check after uploading one item that the Total Media tile increments) — a stale cached count would fail this. The refusal for a non-privileged role is a full `wp_die()` page, not a silent redirect.
- **Settings that change it:** None directly (reads live data + the Pages options from MV-SET-034).
- **Edge cases:** "Missing" pages must still degrade gracefully — no fatal if `mvs_page_explore` etc. point at a deleted/trashed page ID.

#### MV-ADM-002 — Import Demo Data
- **Edition:** Free
- **Who:** Admin only (checked in the AJAX handler).
- **Where:** MediaVerse > Overview > "Quick Start with Demo Content" card
- **Setup:** A fresh/near-empty install (best demonstrated on one with no real content).
- **Steps:** 1. Click "Import Demo Data". 2. Confirm the button shows "Importing..." state. 3. Wait for completion; confirm redirect to Explore page. 4. Re-run without cleaning up first.
- **Expected:** AJAX action seeds ~12 sample media items, albums, reactions. A SECOND run while demo data already exists must return `wp_send_json_error()` (Coding Rule 20 — a refusal must never be a success envelope) with a clear message, not silently do nothing while claiming success.
- **UX expectation:** "Importing..." replaces the button label during the request; a genuine failure shows "Import failed. The server returned an invalid response. Please check wp-content/debug.log." — not a blank failure. Success redirects to Explore so the owner immediately sees the seeded content, satisfying "what must NOT happen": the button must never report success while doing nothing.
- **Settings that change it:** None (one-shot action, not a persisted setting).
- **Edge cases:** Non-admin cannot trigger this even by crafting the AJAX call directly — `wp_send_json_error( 'Permission denied.' )`.

#### MV-ADM-003 — Delete Demo Data
- **Edition:** Free
- **Who:** Admin only.
- **Where:** MediaVerse > Overview > "Delete Demo Data" (shown once demo data exists)
- **Setup:** Demo data already imported (MV-ADM-002).
- **Steps:** 1. Click "Delete Demo Data". 2. Confirm a JS confirm dialog appears before anything happens. 3. Accept; confirm demo users/media/albums/collections are gone and real user data is untouched.
- **Expected:** Confirm text: "Delete all demo users and the media, albums, and collections they own? Your real user data will not be touched. This cannot be undone." Button shows "Deleting..." during the request.
- **UX expectation:** This is destructive — the confirm dialog is mandatory before the request fires, per the destructive-action rule. Cancelling the dialog must make zero server calls.
- **Settings that change it:** None.
- **Edge cases:** Check: click "Delete Demo Data" on a site with no demo data present (or click it twice in a row). Confirmed from code: this is NOT a silent no-op success — the response comes back as an error ("No demo users found. Nothing to clean.") and the status text renders in the button's red/error styling, even though nothing is actually wrong. It's a minor cosmetic quirk (the message itself is clear and non-alarming), not a crash — but a tester should not be surprised to see red text here on a second click.

#### MV-ADM-004 — All Media admin list
- **Edition:** Free
- **Who:** `manage_options` or `upload_mvs_media`; others get `wp_die()`.
- **Where:** MediaVerse > All Media (`admin.php?page=mvs-media`)
- **Setup:** 20+ media items across types/privacy/statuses to exercise filters and pagination meaningfully (big-site check: seed 2000+ rows and confirm the list still paginates via LIMIT/OFFSET, not a full table scan).
- **Steps:** 1. Open the page. 2. Filter by Type. 3. Filter by Privacy. 4. Search by title. 5. Switch status tabs (All/Published/Pending/Draft/Trash). 6. Page through results.
- **Expected:** Status tabs with live counts (`mvs-stat-card` tiles: All/Published/Pending/Draft/Trash). Columns: Thumb, Title, Author, Type, Privacy, Status, AI, Date. Filters combine (type + privacy + search) via GET params. Empty state differs by cause: "No media matches your filters" + "Clear filters" link when filters are active and empty, vs. "No media yet... Once users upload media it will appear here." when the library itself is empty. `paginate_links()`-based pagination, 20 per page.
- **UX expectation:** The two distinct empty states (filtered-empty vs. truly-empty) must not be conflated — a filtered-empty state without a visible "Clear filters" link is a UX regression. At 2000+ rows, pagination must still be fast (server-side LIMIT/OFFSET, confirmed via the code path) — this is exactly the big-site-readiness checklist.
- **Settings that change it:** None (reads live data).
- **Edge cases:** A capability-less user hitting the URL directly must see the permission wp_die(), not an empty list.

#### MV-ADM-005 — Media bulk actions (Trash / Restore / Delete permanently)
- **Edition:** Free
- **Who:** Same as MV-ADM-004, each action pair-checked with the matching capability + nonce.
- **Where:** MediaVerse > All Media (bulk actions dropdown, top and bottom)
- **Setup:** Several media items in different statuses.
- **Steps:** 1. Select several published items, choose "Move to Trash", Apply. 2. Confirm redirect shows "%d items moved to Trash." 3. Switch to Trash tab, select items, "Restore". 4. Select items, "Delete permanently" — confirm a JS confirm fires first ("Permanently delete the selected media? This cannot be undone.").
- **Expected:** Bulk actions run on the page's `load-` hook so redirects happen before any output (avoids "headers already sent"). Success messages use `_n()` proper singular/plural ("%d item moved to Trash." / "%d items moved to Trash."). Nonce field `mvs_bulk_nonce` / action `mvs_bulk_media` required.
- **UX expectation:** Delete-permanently MUST show the confirm dialog before firing — trash/restore (non-destructive/reversible) do not need one. The plural/singular message must be grammatically correct for 1 vs. many items — a tester selecting exactly 1 item should not see "1 items".
- **Settings that change it:** None.
- **Edge cases:** Submitting bulk with nothing selected should show "No media selected." (client-side), not a server round-trip.

#### MV-ADM-006 — Media row actions (View / Edit / Details / AI Review / Trash / Restore / Delete)
- **Edition:** Free
- **Who:** Same capability gate as the list; AI Review only present when AI is active on that item.
- **Where:** MediaVerse > All Media, per-row action links
- **Setup:** A media item with an AI-processed status.
- **Steps:** 1. Hover a row; confirm View/Details/Trash links. 2. Click "AI Review" on a flagged item and confirm the review screen shows suggested description/tags for accept/reject.
- **Expected:** Row actions render conditionally on status (Trash only if not already trashed; Restore + Delete Permanently only in Trash view). "AI Review" link appears only when the item has AI-generated content pending review.
- **UX expectation:** "Delete Permanently" row link carries the same destructive confirm text as the bulk action, per-item.
- **Settings that change it:** None.
- **Edge cases:** Clicking AI Review for a media ID that no longer exists (deleted after page load) should show "Media not found." with a link back to All Media, not a fatal.

#### MV-ADM-007 — Media "Optimize" row action / thumbnail repair
- **Edition:** Free
- **Who:** Same capability gate as MV-ADM-004.
- **Where:** MediaVerse > All Media, per-row "Optimize" action (image rows) and repair-thumbnail action
- **Setup:** An image that can be optimized further and/or a video/image with a missing thumbnail.
- **Steps:** 1. Trigger Optimize on one row. 2. Confirm a message like "Optimized media #%1$d: %2$s to %3$s (saved %4$s%%)." 3. Trigger repair-thumb on an item eligible for repair; on one NOT eligible (no local original, no embedded video cover) confirm the "no repair path available" message.
- **Expected:** Per-row redirect-based actions guarded by cap + nonce pair (per Coding Rule set). Failure message: "Could not optimize media #%1$d: %2$s."
- **UX expectation:** The message must explain WHY a repair isn't possible ("needs its original file on local disk; a video needs a cover image embedded in the file") rather than a generic failure — this is a code-verified exact string to check for.
- **Settings that change it:** Interacts with MV-SET-008 (optimize quality/behavior).
- **Edge cases:** Running Optimize twice on an already-optimized file should not further shrink or corrupt it (idempotent, same as the setting-level behavior).

#### MV-ADM-008 — Documents admin screen (Pro-gated)
- **Edition:** Free (code present, but the menu item and page only appear when `mvs_documents_enabled` filter is true, which defaults `false` in Free — this feature requires MediaVerse Pro, or the presence of legacy `legacy_document` rows from a pre-2.4.0 upgrade)
- **Who:** N/A on a Free-only site — the menu entry should not even be visible.
- **Where:** MediaVerse > Documents (only present when documents are enabled)
- **Setup:** A Free-only site with no Pro and no legacy documents.
- **Steps:** 1. Confirm no "Documents" menu item appears under MediaVerse on a Free-only site. 2. If the site upgraded from pre-2.4.0 and has legacy document rows, confirm those old files are still reachable on the FRONTEND (see step 3 below) — but do not expect a wp-admin menu item to appear for them.
- **Expected:** The wp-admin "Documents" menu item is gated purely on whether Pro is active — full stop, no legacy-file exception. Corrected from an earlier version of this catalog entry, which claimed the admin menu itself reappears when legacy document rows exist; that is not what the code does. The legacy-file exception is real, but it only applies to the FRONTEND "Explore Documents" page (`[mvs_documents]`), which IS created on activation/upgrade when either Pro is active OR the site has legacy document rows — so members can still browse old pre-2.4.0 files there. The backend admin list page for Documents stays Pro-only regardless.
- **UX expectation:** A Free-only fresh install seeing no Documents menu is CORRECT, not a bug. A site with legacy document rows and no Pro should still have a working "Explore Documents" frontend page, even though it has no matching wp-admin screen.
- **Settings that change it:** None visible in Free; Pro sets the enabling filter for the admin menu.
- **Edge cases:** The frontend "Explore Documents" page (the actual legacy-file exception) is created by the normal page-adoption logic used for every MediaVerse page, so it follows the same safe rules — it won't take over an unrelated page that happens to share its title.

#### MV-ADM-009 — Tags list
- **Edition:** Free
- **Who:** Same moderation-level capability gate as tag management (`moderate_mvs_media` or `manage_options`).
- **Where:** MediaVerse > Tags
- **Setup:** 20+ tags to exercise search/pagination (and 2000+ to check big-list behavior).
- **Steps:** 1. Search by tag name. 2. Clear search. 3. Sort by Name/Count columns. 4. Page through results. 5. Select several tags, Bulk Actions > Delete.
- **Expected:** Search box with a "Clear" link when a search is active. Sortable Name/Count columns. Bulk delete with a top+bottom bulk-actions bar (WP admin list-table convention). Empty state: "No Tags Found" / "No tags found."
- **UX expectation:** Confirmed: there is only ONE empty-state message here ("No Tags Found" / "No tags found."), used whether the tag library is genuinely empty or a search simply matched nothing. Unlike All Media's two-state pattern (which shows a "Clear filters" link when a search/filter is active), Tags does not distinguish "you searched for something that doesn't exist" from "there are no tags at all" — a tester should not expect a distinct "no results for your search" message here.
- **Settings that change it:** None.
- **Edge cases:** Bulk-deleting a tag still linked to media should remove the term without breaking the media rows (tag reference simply drops).

#### MV-ADM-010 — Tag edit screen (rename, slug, linked media)
- **Edition:** Free
- **Who:** Same as MV-ADM-009.
- **Where:** MediaVerse > Tags > Edit (per-tag)
- **Setup:** A tag linked to at least one media item.
- **Steps:** 1. Open a tag's edit screen. 2. Change name/slug, Update. 3. Confirm the Linked Media table (ID/Title/Type/Date) lists correctly and paginates for a tag with many items. 4. Delete a single tag from its own edit-adjacent delete link with the confirm dialog.
- **Expected:** Edit form title "Edit Tag: %s". Linked Media count message: "This tag is linked to %d media item(s)." Delete confirm: "Are you sure you want to delete this tag?"
- **UX expectation:** A tag with ZERO linked media shows "No media items are linked to this tag." rather than an empty table with headers and no explanation.
- **Settings that change it:** None.
- **Edge cases:** Editing a tag's slug to collide with an existing term slug should be handled gracefully (WP term slug uniqueness), not fatal.

#### MV-ADM-011 — Tag merge tool
- **Edition:** Free
- **Who:** Same as MV-ADM-009.
- **Where:** MediaVerse > Tags > Edit > "Merge Into Another Tag"
- **Setup:** Two tags, the source with several linked media.
- **Steps:** 1. On the source tag's edit screen, choose a target tag under "Merge Into Another Tag". 2. Submit — confirm a JS confirm fires: "Merge this tag into the selected tag? This cannot be undone." 3. Confirm the source tag's media all now carry the target tag and the source tag is gone (or emptied), processed via a batched background job.
- **Expected:** `MERGE_HOOK = 'mvs_tag_merge_batch'` — merge runs as a batched action (safe for a tag linked to thousands of items) rather than one giant synchronous query. "No other tags exist to merge into." shown when there's nothing to pick.
- **UX expectation:** The destructive confirm text above must appear before any merge request is sent — this is irreversible.
- **Settings that change it:** None.
- **Edge cases:** Merging a tag into itself must be rejected: "Choose two different tags to merge." Merging into a tag that was deleted between page-load and submit: "One of the selected tags no longer exists."

#### MV-ADM-012 — Moderation queue tabs (AI Flagged / Pending Review / Resolved-Rejected)
- **Edition:** Free
- **Who:** `manage_options` or `moderate_mvs_media`; others `wp_die()`.
- **Where:** MediaVerse > Moderation
- **Setup:** Items in each state — some AI-flagged (needs MV-SET-028 on), some pending, some resolved/rejected. 2000+ items to check pagination.
- **Steps:** 1. Open the page — confirm the first non-empty tab is the default. 2. Switch tabs; confirm live counts on each tab badge. 3. With AI Moderation off and nothing flagged, confirm the "AI Flagged" tab is absent entirely (not shown-but-empty).
- **Expected:** Tab set is built dynamically: "AI Flagged" only appears if there's a nonzero flagged count OR AI Moderation is on. "Pending Review" and "Resolved / Rejected" always present. Extensible via `mvs_moderation_tabs` filter — Free's Reports tab (MV-ADM-014) attaches here too when Pro's User Reports isn't present.
- **UX expectation:** Empty queue state: "Queue is Clear" / "No items in this queue. All clear!" — a positive, unambiguous empty state, not a bare blank table.
- **Settings that change it:** MV-SET-028 (AI Moderation) controls tab visibility as described.
- **Edge cases:** A non-privileged role must get the full wp_die() page, not a partial/broken render.

#### MV-ADM-013 — Moderation approve / reject (single + bulk)
- **Edition:** Free
- **Who:** Same as MV-ADM-012.
- **Where:** MediaVerse > Moderation, per-row Approve/Reject and the bulk-actions bar
- **Setup:** Items in Pending or AI Flagged state.
- **Steps:** 1. Approve a single item — confirm "Media item approved." notice and the item leaves the queue. 2. Reject a single item with a reason — confirm "Media item rejected." 3. Select several, Bulk Actions > "Approve Selected" / "Reject Selected", Apply — confirm "%d item(s) approved/rejected." with correct pluralization.
- **Expected:** Single actions post `mvs_moderation_action=approve|reject` with cap+nonce; bulk actions post `bulk_approve`/`bulk_reject`. A rejected item stays visible to owner/moderator (enforced in `PrivacyService::check_access()`), refused to everyone else at its permalink, signed file URL, and thumbnail URL alike.
- **UX expectation:** After approve/reject, the acted-on item must disappear from its ORIGINAL tab and appear correctly filed in the destination tab on next visit — a stale item still showing after action is a bug. The rejected-but-owner-can-still-see-it behavior must be visibly distinguishable (e.g. a "Rejected" badge) so the owner isn't confused about why only they can see it.
- **Settings that change it:** None directly (acts on live items).
- **Edge cases:** Approving/rejecting an item another moderator just acted on (already resolved) should show a clear "already handled" outcome, not a duplicate action or silent no-op — multi-actor concurrency.

#### MV-ADM-014 — Reports admin screen (member reports review)
- **Edition:** Free
- **Who:** `manage_options` or `moderate_mvs_media`.
- **Where:** MediaVerse > Moderation > Reports tab (also reachable at its own hidden page slug, kept only so `get_admin_page_title()` resolves and old bookmarks work — removed from the sidebar on every OTHER screen)
- **Setup:** At least one report in each status (Pending/Resolved/Dismissed).
- **Steps:** 1. Open Reports. 2. Filter by status tab (Pending/Resolved/Dismissed). 3. On a pending report, click "Resolve" — confirm status changes and message "Report marked as resolved." 4. Click "Dismiss" on another — "Report dismissed." 5. On a resolved item, "Reopen" — "Report reopened."
- **Expected:** Columns: Reported (item), Reason, Details, Reported by, When, Action. Deleted reporter shows "Deleted member" rather than a broken user reference. Action buttons are status-dependent (Resolve/Dismiss when pending, Reopen when resolved/dismissed).
- **UX expectation:** "Nothing here. No reports with this status." for an empty status tab. A capability-less visit must be a 403 `wp_die()` ("You are not allowed to moderate reports."), not a redirect that silently drops the attempt.
- **Settings that change it:** MV-SET-026/027 control whether new reports can arrive and the auto-hide threshold.
- **Edge cases:** The email toggle "Report reviewed" (MV-SET-033) should fire when a report's status genuinely changes here.

#### MV-ADM-015 — Member Moderation: Suspend / Restore
- **Edition:** Free
- **Who:** Requires the native WordPress "edit users" capability, and only against a target who is (a) not the acting admin themselves and (b) not an administrator-level user. A suspended member cannot post/comment/react/message anywhere (site or app) while restored members can immediately.
- **Where:** wp-admin > Users (native WordPress Users list — row action + a "MediaVerse" status column; also the Edit User / your-profile screen)
- **Setup:** A test member account.
- **Steps:** 1. On Users list, use the row action "Suspend" on a test member. 2. Confirm the "MediaVerse" column shows "Suspended" (bold). 3. Attempt an action as that member (comment/react/upload) via the API — confirm it's refused even if they hold a valid Application Password (core skips the login filter chain for those, so this is the one thing still enforcing it). 4. Use "Restore" — confirm "Active" status returns and actions succeed again.
- **Expected:** Row action label flips Suspend ⇄ Restore. Admin notice on action: "Member suspended. They can still browse, but cannot post, comment, react, or message — on the website or in the app." / "Member restored. They can post again."
- **UX expectation:** The suspended member can still BROWSE (read-only) — a tester confirming a suspension "worked" only by checking they're logged out entirely is testing the wrong thing; browsing must still work, only writes are blocked. The notice text above is exact and code-verified.
- **Settings that change it:** None (an action, not a setting).
- **Edge cases:** Suspending a member with a scheduled account deletion pending (MV-PRV-007) — check the two features don't conflict; "Cancel" link for scheduled deletion is a separate row link.

#### MV-ADM-016 — Member Moderation: per-user storage limit override
- **Edition:** Free
- **Who:** Same capability as viewing/editing another user's profile (`edit_users`-level).
- **Where:** wp-admin > Users > Edit User (own or another member's profile screen)
- **Setup:** A member who has already used some storage.
- **Steps:** 1. Open a member's profile edit screen. 2. Find the MediaVerse storage-limit field; leave blank, save — confirm description "Leave blank to use the site limit, or 0 for no limit. Using %s now." shows their CURRENT usage. 3. Set an explicit MB value; save; confirm it now overrides the site default for that member only (MV-SET-001).
- **Expected:** Field reads/writes user meta `mvs_storage_limit_mb`; absent = site limit, `0` = unlimited for that user specifically.
- **UX expectation:** The description must show the member's actual current usage (a real number, formatted via `size_format()`), not a placeholder — this is a concrete code-verified string to check.
- **Settings that change it:** Overrides MV-SET-001 for this one user.
- **Edge cases:** Setting 0 here for one member while the site has a nonzero limit must actually exempt only that member — verify a second member is still capped.

#### MV-ADM-017 — Stats page
- **Edition:** Free
- **Who:** `manage_options` or `manage_mvs_settings`.
- **Where:** MediaVerse > Stats
- **Setup:** Media with views/reactions recorded; AI usage this month.
- **Steps:** 1. Open Stats. 2. Switch date range (Today/This Week/This Month/All Time). 3. Confirm Top Media by Views table updates per range. 4. Click "Export CSV" — confirm a nonce-gated download (`mvs_export_stats_csv`) with columns ID, Title, Views, Reactions, Comments, Shares. 5. Check the AI Usage panel (API Calls, Successful, Failed, Cost, Budget when set).
- **Expected:** "No Stats Yet" / "Views will appear once users start browsing media." when empty. The CSV export asks the SAME aggregation method (`AdminAggregatesService::top_media_by_views()`) the on-screen table uses, so the two can never disagree.
- **UX expectation:** The CSV filename is date-stamped (`wpmediaverse-stats-YYYY-MM-DD.csv`) — worth a spot check. A capability-less or unauthenticated request to the export URL must be refused (nonce + capability check both must fail closed), not silently return an empty CSV.
- **Settings that change it:** None (reads live aggregates).
- **Edge cases:** At very high row counts the export is capped to top 100 by views — a site with thousands of media items exports only the top 100, not everything; that is intentional, not a bug. Confirmed: this cap is NOT documented anywhere on the Stats screen — there is no on-screen note near the Export CSV link telling the owner the export is capped. A site owner exporting for a full audit could reasonably assume they got everything.

#### MV-ADM-018 — Log Viewer
- **Edition:** Free
- **Who:** `manage_options`-level (`mvs_settings_screen`).
- **Where:** MediaVerse > (Tools/MediaVerse Logs — reachable page, legacy URL redirects here)
- **Setup:** Some log entries at different levels/contexts (trigger an error condition, e.g. a bad AI key call).
- **Steps:** 1. Open Log Viewer. 2. Filter by Level. 3. Filter by Context. 4. Click "Reset" to clear filters. 5. Expand a row's "Details" `<summary>`. 6. Click "Clear All Logs" — confirm the JS confirm ("Are you sure you want to clear all logs?") fires first. 7. Page through 50-per-page results.
- **Expected:** "No Log Entries" / "No log entries found." when empty (post-clear shows "All logs have been cleared."). Columns: Date, Level, Context, Message, User, IP. Table is paginated via `paginate_links()`, 50/page. Description states logs older than 30 days are auto-pruned.
- **UX expectation:** Clear-All must be behind the confirm dialog (destructive). The IP/User columns are sensitive data — confirmed: they are visible only on this capability-gated Log Viewer screen. No REST route reads the log table at all, so this data is never exposed through the API or any other surface.
- **Settings that change it:** None directly (cron prunes automatically at 30 days — not owner-configurable in the visible UI, matching MV-SET-009's pattern of backend-only retention knobs elsewhere).
- **Edge cases:** Clearing logs while a filter is active — confirm it clears ALL logs (not just the filtered subset), matching the button's stated scope.

#### MV-ADM-019 — Collection Settings meta box (manual / smart rules)
- **Edition:** Free
- **Who:** Same as editing a Collection post (album/collection edit capability).
- **Where:** Edit screen for a Collection post type, "Collection Settings" meta box
- **Setup:** A Collection post, some tagged/categorized media to match rules against.
- **Steps:** 1. Choose "Manual" — confirm the description "Add media with the Save button on any media item." and no rule builder shows. 2. Choose "Smart" — add rules (Media Type / Tag / Category / Author / Privacy / Date After / Date Before), all-must-match. 3. Save and confirm the collection resolves to the matching items on the frontend.
- **Expected:** Rule row selects: Media Type (image/video/audio/document), Privacy (public/members/private — note: only 2 real privacy levels are actually enforced for collections per CAPABILITIES.md, so "private" as a RULE CRITERION differs from the collection's own privacy, which is public/members only), Date After/Before.
- **UX expectation:** "+ Add Rule" must let an admin add/remove rule rows dynamically without a page reload breaking the form. Smart rules with zero matches should resolve to an empty (not broken) collection on the frontend.
- **Settings that change it:** Interacts with the Collection's own top-level privacy control described in CAPABILITIES.md (public/members only, coerced back to public if a bad value is ever stored).
- **Edge cases:** A "document" type rule can never realistically match on a Free-only site with no Pro-driven documents visible in this rule context. Confirmed safe: this is explicitly documented in code as a known no-op — the rule key has never actually filtered by document type (it matches against the MIME type, and documents are stored as `application/pdf`, not "document"), so it just returns zero matches. It does not crash or error.

#### MV-ADM-020 — Integrations page
- **Edition:** Free
- **Who:** `manage_options` for the full page; a summary card on Overview is visible to whoever can see Overview.
- **Where:** MediaVerse > Overview (Integrations summary card) → MediaVerse > Integrations (full page, reached only from that card since 2.6.0, not the sidebar)
- **Setup:** None (works with zero companion plugins installed) and, to test the positive path, one of: BuddyNext, Jetonomy, WB Gamification, Learnomy, WP Career Board, WB Listora.
- **Steps:** 1. Open Integrations. 2. For an uninstalled companion, click "Install free" — confirm it one-click installs+activates. 3. For an installed-but-inactive one, confirm the button reads "Activate". 4. For an active one, confirm "Connected" badge. 5. Attempt install as a non-`install_plugins` role.
- **Expected:** Status badges on both the Overview card and the full page: Connected / Installed, inactive / Not installed. Copy: "Extend MediaVerse with the Wbcom stack. Each plugin works on its own - installing one here does not tie it to MediaVerse." A non-privileged install attempt: `wp_die()` 403 "You do not have permission to install plugins."
- **UX expectation:** "Each plugin works on its own" framing must be true in practice — deactivating a companion later should not break MediaVerse. Both screens use the same wording for the same state.
- **Settings that change it:** None (reflects live plugin state).
- **Edge cases:** The page stays reachable at its own URL and self-removes from the sidebar on every OTHER screen — a bookmark to it must still work even though it's not in the nav.

#### MV-ADM-021 — Private Community Default notice
- **Edition:** Free
- **Who:** `manage_options` only (the notice and its two-button form).
- **Where:** Admin notice shown on any MediaVerse screen
- **Setup:** A community plugin (e.g. BuddyNext) set to private mode (`mvs_rest_require_auth` filter answers true), while `mvs_default_privacy` is still `public` and the admin hasn't answered this notice before.
- **Steps:** 1. Turn the host community private. 2. Visit any MediaVerse admin screen. 3. Confirm the warning notice appears: "Your community is private, but new uploads default to Public." + "Public files can be opened by anyone who has the file address, including people who are not members." 4. Click "Use Members Only" — confirm `mvs_default_privacy` becomes `members` and the notice never reappears. 5. Reset the answered flag and instead click "Keep Public" — confirm the setting is untouched but the notice still never reappears (answered either way).
- **Expected:** Two POST buttons in one form (not GET links) because the action changes a setting — nonce-protected (`mvs_private_default`). `mvs_private_default_answered` option records the answer was given, regardless of which button.
- **UX expectation:** This is exactly a "locked/read-only state with explanation" cousin — the notice must ask ONCE, never nag repeatedly, and must be a form POST (not a clickable GET link) since a link-follow must not silently change a site setting.
- **Settings that change it:** Can change MV-SET-010 to `members` if "Use Members Only" is chosen.
- **Edge cases:** A non-admin visiting the same screens must never see this notice (checked via `current_user_can('manage_options')`).

#### MV-ADM-022 - Role permissions (by design, no matrix screen since 2.6.0)
- **Edition:** Free
- **Who:** Admin.
- **Where:** MediaVerse > Settings > General > **Who can upload media**.
- **Setup:** None.
- **Steps:** 1. Confirm there is no Permissions tab in the Settings sidebar. 2. Untick a role under "Who can upload media", save, log in as that role, and confirm the upload button is gone and `POST /mvs/v1/media` is refused. 3. Tick it again and confirm upload returns.
- **Expected:** The full role matrix was removed from the screen in 2.6.0 on purpose (admin simplification). "Who can upload media" is the one control owners need. Role choices saved on the old matrix stay in force after the update.
- **UX expectation:** The checkbox list always matches who can really upload (it reads the roles, not a copy).
- **Settings that change it:** `mvs_upload_roles` (writes the `upload_mvs_media` capability).
- **Edge cases:** A developer can bring the old matrix back with the `mvs_settings_sections` filter; that is an intentional extension point, not a defect.

### Area: WIZ — Setup wizard, demo data, activation

#### MV-WIZ-001 — First-activation redirect to Setup Wizard
- **Edition:** Free
- **Who:** Whoever activates the plugin (admin).
- **Where:** Triggered automatically by plugin activation.
- **Setup:** A fresh install/activation.
- **Steps:** 1. Activate the plugin. 2. Confirm the next admin page load redirects to the Setup Wizard, not the plugins list.
- **Expected:** `set_transient( 'mvs_activation_redirect', true, 30 )` — the redirect only fires within a 30-second window after activation, and only once (transient consumed on read).
- **UX expectation:** Bulk-activating multiple plugins at once must not redirect (WordPress core convention). Confirmed: the redirect code explicitly checks for WordPress's own bulk-activate URL parameter and skips the redirect when present, alongside separate skips for AJAX and WP-CLI requests — a forced redirect during a bulk-activate does not happen.
- **Settings that change it:** None.
- **Edge cases:** Reactivating an already-configured site should still show the wizard (it's activation-triggered, not "first ever" triggered) — confirm this doesn't clobber existing settings, since the wizard's steps only write Display settings, not everything.

#### MV-WIZ-002 — Setup Wizard: Welcome step
- **Edition:** Free
- **Who:** `mvs_settings_screen` cap; others `wp_die()`.
- **Where:** Hidden page reached via the activation redirect or MediaVerse > Setup (not in the visible sidebar — `hide_from_menu()`)
- **Setup:** None.
- **Steps:** 1. Land on Welcome. 2. Confirm the feature list (albums/collections, social features, AI moderation/privacy, optional BuddyPress integration) and the progress indicator show step 1 of 3. 3. Click "Skip setup" — confirm it exits to a sensible destination without forcing completion.
- **Expected:** Steps enum: Welcome → Display → Done (the old "Pages" step name is normalized to "Display" for anyone following an old link with `step=pages`).
- **UX expectation:** "Skip setup" must be a clearly visible escape hatch, not buried — a new admin who doesn't want a wizard should not be trapped in it.
- **Settings that change it:** None on this step.
- **Edge cases:** Requesting `step=pages` directly (an old bookmark) must land on Display, not error.

#### MV-WIZ-003 — Setup Wizard: Display step
- **Edition:** Free
- **Who:** Same as MV-WIZ-002.
- **Where:** Setup wizard, step 2
- **Setup:** None.
- **Steps:** 1. Set Items Per Page. 2. Choose a layout (Grid, Justified rows, List). 3. Click Continue.
- **Expected:** Writes `mvs_items_per_page` and the layout transport (MV-SET-016/018) via `mvs_wizard_step=display` POST, nonce-protected.
- **UX expectation:** Values chosen here must be reflected when the admin later opens Settings > Display — the wizard and the real settings screen must never disagree about the current stored value.
- **Settings that change it:** MV-SET-016 (Layout), MV-SET-018 (Items Per Page).
- **Edge cases:** Submitting the form with no `mvs_wizard_step` field (a forged/incomplete POST) is ignored (`handle_wizard_save()` returns early).

#### MV-WIZ-004 — Setup Wizard: Done step
- **Edition:** Free
- **Who:** Same as MV-WIZ-002.
- **Where:** Setup wizard, step 3
- **Setup:** Completed step 2.
- **Steps:** 1. Reach Done. 2. Confirm links onward (e.g. to Overview/Explore) work.
- **Expected:** "Your Media Hub is Ready!" confirmation screen ending the flow.
- **UX expectation:** From here, the admin should have an obvious next click (not a dead end). Confirmed: the Done screen shows "Visit Explore Page" and "My Dashboard" links (each only appearing if that page actually exists) plus a primary "Go to Overview" button — never a dead end.
- **Settings that change it:** None.
- **Edge cases:** Revisiting `step=done` later (bookmarked) should not re-run any save logic, just render the confirmation.

#### MV-WIZ-005 — Activation creates frontend pages
- **Edition:** Free
- **Who:** Runs automatically on activation/upgrade; not owner-triggered.
- **Where:** N/A (runs in `Activator::activate()` / `maybe_upgrade()`)
- **Setup:** A fresh install, and separately a site with a pre-existing page titled exactly "My Media" that is NOT the plugin's.
- **Steps:** 1. Activate on a fresh site. 2. Confirm 3 pages are created and published: Explore Media (`[mvs_gallery]`), My Media (`[mvs_dashboard]`), Upload Media (`[mvs_upload]`) — and a 4th, Explore Documents, ONLY if Pro-documents are enabled or legacy document rows exist. 3. On the pre-existing-page site, confirm the plugin does NOT silently take over an unrelated page that happens to share a title — it only adopts a page that already carries the matching shortcode.
- **Expected:** Adoption logic checks in order: (1) option already points at a live page with the shortcode, (2) an existing published page at the expected slug with the shortcode, (3) an existing published page by exact title with the shortcode, (4) creates new. An unrelated page with the right title but the WRONG or no shortcode is never adopted.
- **UX expectation:** No data loss on an owner's own unrelated "My Media" page — this is a concrete regression class (Basecamp-referenced) the code explicitly guards against; a false adoption would be a serious bug.
- **Settings that change it:** Populates MV-SET-034 (Pages options) with the resulting post IDs.
- **Edge cases:** Explore Documents is the ONLY page NOT created on a plain Free-only fresh install — confirmed correct, not a bug (see MV-ADM-008).

#### MV-WIZ-006 — Activation never edits site navigation menus
- **Edition:** Free
- **Who:** N/A (structural guarantee, not a user action).
- **Where:** Appearance > Menus, after activation
- **Setup:** A theme/menu with "Automatically add new top-level pages" enabled.
- **Steps:** 1. Enable that WP core menu option. 2. Activate/reactivate the plugin (triggering page creation). 3. Confirm the newly created Explore/My Media/Upload pages do NOT appear in the nav menu automatically, and the site owner can freely add them by hand if wanted.
- **Expected:** `_wp_auto_add_pages_to_menu` is deliberately detached around the page-creation calls, then reattached — activation must never edit a site's navigation (Coding Rule 17, born from a real customer complaint).
- **UX expectation:** The reverse — pages silently appearing in the site's live nav after a plugin update — is explicitly the "must NOT happen" case here.
- **Settings that change it:** None.
- **Edge cases:** A subsequent WordPress core auto-add for a genuinely unrelated new page (created by the owner, not the plugin) must still work normally after the detach/reattach — the hook must be restored, not permanently removed.

#### MV-WIZ-007 — Import Demo Data (cross-reference)
- **Edition:** Free
- **Who:** Admin only.
- **Where:** MediaVerse > Overview > "Quick Start with Demo Content" card.
- **Setup:** See MV-ADM-002.
- **Steps:** See MV-ADM-002 — full step list, expected AJAX behavior and exact failure-copy are documented there; duplicated here only as an index entry because the task's area-code table files "demo data" under WIZ while the trigger control physically lives on the ADM-coded Overview screen.
- **Expected:** Same as MV-ADM-002.
- **UX expectation:** Same as MV-ADM-002.
- **Settings that change it:** None.
- **Edge cases:** Same as MV-ADM-002.

#### MV-WIZ-008 — Delete Demo Data (cross-reference)
- **Edition:** Free
- **Who:** Admin only.
- **Where:** MediaVerse > Overview > "Delete Demo Data".
- **Setup:** See MV-ADM-003.
- **Steps:** See MV-ADM-003 — same duplication note as MV-WIZ-007 above.
- **Expected:** Same as MV-ADM-003.
- **UX expectation:** Same as MV-ADM-003.
- **Settings that change it:** None.
- **Edge cases:** Same as MV-ADM-003.

### Area: HLT — Site Health

#### MV-HLT-001 — MediaVerse Database Tables
- **Edition:** Free
- **Who:** Any admin viewing Site Health (Tools > Site Health > Status tab).
- **Where:** wp-admin > Tools > Site Health
- **Setup:** None for the pass case; to see the fail case, drop one `mvs_*` table on a disposable install.
- **Steps:** 1. Load Site Health Status. 2. Confirm "MediaVerse Database Tables" is green: "All required database tables exist." 3. On the disposable install, drop a table and reload — confirm it goes red/critical listing the missing table(s) with the remedy "Try deactivating and reactivating the plugin to recreate tables."
- **Expected:** Pass label: "MediaVerse database tables are present." Fail label: "MediaVerse database tables are missing."
- **UX expectation:** The failing test must name WHICH tables are missing, not just fail generically, and must give the concrete remedy text above — a tester should be able to act on the message alone.
- **Settings that change it:** None.
- **Edge cases:** Runs against `Migrator::tables()` as the single source of truth, so a newly added table in a future release is automatically covered without editing this test.

#### MV-HLT-002 — MediaVerse Upload Directory
- **Edition:** Free
- **Who:** Any admin.
- **Where:** Tools > Site Health
- **Setup:** For the fail case, make the uploads/wpmediaverse directory non-writable (chmod) on a disposable install.
- **Steps:** 1. Confirm pass: "Media files can be uploaded successfully." 2. On the non-writable copy, reload — confirm fail: "MediaVerse upload directory is not writable" naming the directory path.
- **Expected:** Pass: "MediaVerse upload directory is writable." Fail names the actual path (e.g. `/wp-content/uploads/wpmediaverse`).
- **UX expectation:** The failing result must show the actual filesystem path so a site owner (or their host) can fix permissions without guessing.
- **Settings that change it:** None.
- **Edge cases:** A directory that exists but has 0 files yet must still pass (writability, not "has content").

#### MV-HLT-003 — MediaVerse Required Pages
- **Edition:** Free
- **Who:** Any admin.
- **Where:** Tools > Site Health
- **Setup:** For the fail case, trash the Explore or My Media page on a disposable install.
- **Steps:** 1. Confirm pass: "All required pages exist and are published." 2. Trash a required page, reload — confirm fail: "MediaVerse pages are missing" with remedy "Try deactivating and reactivating the plugin to recreate pages."
- **Expected:** The test checks the two pages MediaVerse cannot work without: Explore (`mvs_page_explore`) and My Media (`mvs_page_dashboard`). The Upload and Explore Documents pages are optional and are shown on the Overview page-status block (MV-ADM-001) instead.
- **UX expectation:** Same remedy pattern as MV-HLT-001 — actionable, not just alarming.
- **Settings that change it:** Reads MV-SET-034.
- **Edge cases:** A page that exists but lost its shortcode content (edited by the owner) is a DIFFERENT failure mode this specific test does NOT catch — confirmed: it checks only that the page is published (post status), and never looks at whether the shortcode is still present in the content. A published page with the shortcode accidentally deleted will still show "MediaVerse pages are set up," even though the feature is broken on the frontend.

#### MV-HLT-004 — MediaVerse Media Privacy
- **Edition:** Free
- **Who:** Any admin.
- **Where:** Tools > Site Health
- **Setup:** A live, reachable install (loopback requests must work) — nginx vs Apache matters here.
- **Steps:** 1. Confirm pass: "Stored media cannot be downloaded by guessing its address. Every request goes through a permission check." 2. On an nginx host (where the plugin's `.htaccess`-style deny rules do nothing), confirm the test correctly reports the failure state with the exact nginx rule to paste, rather than a false pass. 3. Simulate blocked loopback requests (firewall) and confirm the test reports "could not be confirmed" rather than a false pass or fail.
- **Expected:** `probe_public_access()` writes a canary file into the upload directory and fetches it over real HTTP. Fail label: "Media files can be downloaded by anyone with the link", explaining the Apache-only deny-rule limitation and giving the literal nginx config block to paste. Inconclusive label: "Media privacy could not be confirmed" (loopback blocked).
- **UX expectation:** Three distinct outcomes (pass / genuinely fail / inconclusive) must never collapse into two — a host with blocked loopback must NOT be reported as "safe" (false pass) or as "leaking" (false fail); "could not be confirmed" is the only honest answer there.
- **Settings that change it:** None.
- **Edge cases:** The canary file must never be a dotfile (a known trap noted in project memory) or it can itself become invisible to the very probe meant to test visibility.

#### MV-HLT-005 — MediaVerse Template Overrides
- **Edition:** Free
- **Who:** Any admin.
- **Where:** Tools > Site Health
- **Setup:** A child theme (or any active theme) with a copy of a MediaVerse template in its own `wpmediaverse/` folder, one up to date and, separately, one with no/old `@version` header.
- **Steps:** 1. With no theme overrides, confirm pass: "Your theme's MediaVerse templates are up to date" — "Your theme does not override any MediaVerse template, or every copy it has matches the current version." 2. Add a current-version override, confirm it still passes. 3. Age the override (revert to an old copy with no or an old `@version`), reload — confirm fail listing that file, phrased either "your copy has no version (made before 2.6.0), current is %s" or "your copy is version %1$s, current is %2$s".
- **Expected:** Fail label: "Your theme has outdated copies of MediaVerse templates" with remedy: "Ask your theme developer to update them: copy the current file from the plugin's templates folder over the theme's copy in its wpmediaverse folder, then re-apply the theme's changes."
- **UX expectation:** The test must name the SPECIFIC outdated file(s), not just "some templates are outdated" — a theme developer needs to know exactly what to re-copy.
- **Settings that change it:** None (compares files, not options).
- **Edge cases:** A template that stops reading a request value on the theme's stale copy (e.g. Explore search silently breaking) is exactly the class of bug this test exists to surface early — see MV-TPL-002.

### Area: CLI — WP-CLI

#### MV-CLI-001 — wp mvs stats
- **Edition:** Free
- **Who:** Server/SSH access with WP-CLI + a role capable of running WP-CLI (typically same as shell access, not a WP role).
- **Where:** `wp mvs stats`
- **Setup:** Some media/social data.
- **Steps:** Run `wp mvs stats`.
- **Expected:** Prints plugin-wide statistics to the terminal (media counts, social counts, etc.).
- **UX expectation:** N/A (CLI feedback, not a GUI) — but output must be human-readable and not require `--format=json` to be usable at a glance.
- **Settings that change it:** None.
- **Edge cases:** Should run cleanly on an empty install (all zeros), not error.

#### MV-CLI-002 — wp mvs migrate
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs migrate`
- **Setup:** A database at an older schema version (or already current, to test the no-op path).
- **Steps:** 1. Run on an up-to-date DB — confirm "Already at version {target}. Nothing to do." 2. Run on a stale DB — confirm it logs progress and ends with "Database migrated to version {target}."
- **Expected:** Idempotent; safe to run repeatedly.
- **UX expectation:** Must clearly state the FROM and TO version numbers, not just "done."
- **Settings that change it:** None (schema, not options).
- **Edge cases:** Interrupting a long migration mid-run and re-running should resume/complete cleanly, not corrupt schema state. From code: the "database is now up to date" marker is only saved once, AFTER every pending step in the batch has run — so an interruption partway through means a re-run repeats every step from the last saved version, including ones that already completed before the interruption. That is only safe because the individual steps are written to be safe to run more than once (e.g. adding a setting only if it's not already there, or updates that no-op when the data is already correct) — the newest steps checked follow this pattern consistently. A re-run after an interruption should therefore complete cleanly rather than corrupt anything, though this rests on every step maintaining that same discipline rather than a single "resume from where it stopped" mechanism.

#### MV-CLI-003 — wp mvs prune-views
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs prune-views [--days=N] [--dry-run]`
- **Setup:** View-event rows older than the retention window (MV-SET-009's `mvs_view_retention_days`, default 90).
- **Steps:** 1. Run with `--dry-run` — confirm "Would delete {count} view records older than {days} days." with no actual deletion. 2. Run for real — confirm "Pruned {deleted} view records older than {days} days."
- **Expected:** Matches the same retention window the daily cron (`mvs_purge_old_views`) enforces automatically — this command is the manual/on-demand version of that cron.
- **UX expectation:** Dry-run must report the SAME count a real run would delete — a mismatch between dry-run and real-run counts is a bug.
- **Settings that change it:** Reads MV-SET-009.
- **Edge cases:** Running with 0 eligible rows should say "0", not error or hang.

#### MV-CLI-004 — wp mvs cleanup-expired
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs cleanup-expired`
- **Setup:** A site running MediaVerse Pro's document-sharing feature, with at least one expired share link.
- **Steps:** Run the command.
- **Expected:** "Cleaned up {cleaned} expired access grants." Corrected from an earlier version of this catalog entry: the `mvs_access_grants` table itself was NOT removed in 2.6.0 — only a different, older feature ("per-media access rules," a separate table) was removed. The `mvs_access_grants` table is alive, still created on every install, and is actively written to by MediaVerse Pro's document-sharing feature (time-limited share links). This command is a live, useful maintenance task on any site using Pro Documents sharing with expiring links — it is not dead or deprecated.
- **UX expectation:** N/A.
- **Settings that change it:** None.
- **Edge cases:** On a Free-only site with no Pro document-sharing links ever created, this command will legitimately report "0" cleaned every time, since nothing populates the table without that Pro feature — that is expected, not a sign the command is broken.

#### MV-CLI-005 — wp mvs reindex
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs reindex`
- **Setup:** A library, ideally with some inconsistency (e.g. a media row missing its stats row).
- **Steps:** Run the command; watch the periodic "Processed {total} media items..." progress log; confirm final "Reindex complete. {total} media items checked, {stats_added} stats rows created."
- **Expected:** Verifies `mvs_media_index` consistency and backfills any missing `mvs_media_stats` rows.
- **UX expectation:** Progress must be visible on a large library (thousands of rows) — silent multi-minute execution with no output would look hung.
- **Settings that change it:** None.
- **Edge cases:** Safe to re-run repeatedly with no side effects on an already-consistent library.

#### MV-CLI-006 — wp mvs cache-flush
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs cache-flush`
- **Setup:** None.
- **Steps:** Run the command.
- **Expected:** "All MediaVerse caches flushed." — clears the plugin's `CacheService` layer (and any object-cache-backed groups it uses).
- **UX expectation:** N/A.
- **Settings that change it:** None.
- **Edge cases:** Confirmed: this is scoped narrowly to MediaVerse's own cache group only (it does not touch the site's whole object cache) when the caching backend supports flushing a single group. On a backend that doesn't support that, the fallback only clears one specific known cache key rather than everything MediaVerse has cached — so on those backends a tester may still see some stale MVS-cached values survive a flush.

#### MV-CLI-007 — wp mvs backfill-activity-thumbnails
- **Edition:** Free
- **Who:** Shell access; requires BuddyPress active.
- **Where:** `wp mvs backfill-activity-thumbnails [--source=rtmedia|mediapress|buddyboss] [--dry-run]`
- **Setup:** BuddyPress active with legacy activity entries imported from rtMedia/MediaPress/BuddyBoss that have empty content.
- **Steps:** 1. Without BuddyPress active, run — confirm "BuddyPress activity component is not active." error and exit. 2. With BuddyPress active and nothing to backfill, confirm "No activities with empty content found. Nothing to backfill." 3. With real candidates and no `--yes`, confirm a WP_CLI::confirm() prompt ("Proceed with backfill?") after a warning about modifying `bp_activity` and recommending a DB backup.
- **Expected:** A destructive-adjacent DB-write command that requires explicit confirmation unless `--yes` is passed.
- **UX expectation:** The backup warning must appear BEFORE the confirm prompt, every time, non-skippable except via `--yes`.
- **Settings that change it:** None (data migration, not settings).
- **Edge cases:** `--source=bogus` is refused by WP-CLI itself ("Invalid value specified for 'source'") because the command declares its allowed values.

#### MV-CLI-008 — wp mvs regenerate-thumbnails
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs regenerate-thumbnails [--media-ids=…] [--dry-run]`
- **Setup:** Image media, some with a "reachable local file" and some without (e.g. cloud-only in a Pro context).
- **Steps:** Run; confirm per-item skip logging ("Skip (no reachable local file): media {id}") for ones it can't process, and a final tally: "%s %d image(s); skipped %d; failed %d."
- **Expected:** Non-destructive to the original; only regenerates derivative sizes.
- **UX expectation:** Skipped vs. failed must be distinguishable in the summary — a "no local file" skip is expected/benign on cloud storage, while a genuine failure (corrupt file) is not; conflating them would mislead an owner debugging a real problem.
- **Settings that change it:** Reads MV-SET-019 thumbnail-size options for the regenerated sizes.
- **Edge cases:** Passing a specific `--media-ids` list that includes a non-image ID should skip it cleanly, not error the whole batch.

#### MV-CLI-009 — wp mvs moderation-stats
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs moderation-stats`
- **Setup:** Media in various moderation states.
- **Steps:** Run the command on an empty index ("No media found in the index.") and again with data present.
- **Expected:** Summarizes moderation-state distribution across the library.
- **UX expectation:** N/A.
- **Settings that change it:** None.
- **Edge cases:** None significant beyond the empty-state message above.

#### MV-CLI-010 — wp mvs sync-activity-privacy
- **Edition:** Free
- **Who:** Shell access; requires BuddyPress active.
- **Where:** `wp mvs sync-activity-privacy [--dry-run]`
- **Setup:** BuddyPress active with MVS-linked activity entries whose `hide_sitewide`/privacy meta may have drifted from the media's own privacy level.
- **Steps:** 1. Without BuddyPress, confirm the same "not active" error as MV-CLI-007. 2. With nothing to sync: "No MVS activities found. Nothing to sync." 3. With real drift and no `--yes`: warning about updating `hide_sitewide` + activity privacy meta, backup recommendation, then a confirm prompt.
- **Expected:** Reconciles BuddyPress activity visibility to match the current MediaVerse privacy level of the linked media, for both standalone upload activities and composer activities.
- **UX expectation:** Same destructive-write confirmation pattern as MV-CLI-007 — consistent CLI safety UX across the two BuddyPress-touching commands.
- **Settings that change it:** None (reconciliation, not settings).
- **Edge cases:** Confirmed: an activity linked to media that was hard-deleted since is skipped cleanly (counted as skipped) rather than erroring the batch.

#### MV-CLI-011 — wp mvs migrate-storage
- **Edition:** Free (command ships in Free; actually moving TO a cloud driver requires Pro)
- **Who:** Shell access.
- **Where:** `wp mvs migrate-storage --from=<driver> --to=<driver> [--limit=N] [--keep-source] [--include-non-public] [--dry-run]`
- **Setup:** On Free alone, only `local` is available, so a real cross-driver move needs Pro active with a cloud driver configured.
- **Steps:** 1. Omit `--from`/`--to` — confirm error: "Both --from and --to are required." 2. Use the same value for both — "Source and destination drivers must differ." 3. On Free-only, target `s3`/`bunnycdn` — "Source/Destination driver '{x}' not available. Pro plugin required for s3/bunnycdn." 4. With Pro + valid drivers, run `--dry-run` first, then for real with a confirm prompt (no `--yes`), then check the printed "Next step" flip-the-option reminder.
- **Expected:** Never auto-flips `mvs_storage_driver` (MV-SET-007) on completion — the admin must run the printed `wp option update mvs_storage_driver <to>` manually, and any failed items block that reminder in favor of a retry warning.
- **UX expectation:** The explicit two-step "migrate files, THEN flip the option yourself" design must never silently switch the active driver — a tester should confirm the option is genuinely unchanged immediately after a clean migration run.
- **Settings that change it:** Does not itself change MV-SET-007, but is the prerequisite before doing so safely.
- **Edge cases:** `--include-non-public` is required to move private/members-only media into a public cloud bucket — the command refuses by default specifically to avoid accidentally publicizing private files.

#### MV-CLI-012 — wp mvs cloud-thumbs-backfill
- **Edition:** Free (command ships in Free; requires Pro's cloud driver active)
- **Who:** Shell access.
- **Where:** `wp mvs cloud-thumbs-backfill [--driver=…] [--limit=N] [--dry-run]`
- **Setup:** Active driver already set to a cloud driver (Pro).
- **Steps:** 1. On `local` driver, confirm error: "Active driver is 'local' — nothing to push to cloud. Set mvs_storage_driver to s3 or bunnycdn first." 2. On Free without Pro's driver classes, confirm "Storage driver '{x}' is not available. Pro plugin required for s3/bunnycdn." 3. With Pro configured, run with `--dry-run`, then for real with the confirm prompt (heavy network+CPU warning, "Recommend running off-peak.").
- **Expected:** Downloads each original from cloud, regenerates 3 size variants locally, re-uploads each — resource-intensive by design.
- **UX expectation:** The "off-peak" warning must appear before the confirm — a tester running this at peak traffic should at least be warned.
- **Settings that change it:** None directly.
- **Edge cases:** A failed original download for one item logs a warning and continues the batch rather than aborting everything.

#### MV-CLI-013 — wp mvs backfill-placeholder-color
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs backfill-placeholder-color [--media-id=N] [--dry-run]`
- **Setup:** Image media without a stored dominant/placeholder color.
- **Steps:** Run for a single `--media-id` that is not an image — confirm error: "Media #%d is not an image (type: %s)." Run in bulk; confirm unreadable originals log a warning and are skipped, not fatal.
- **Expected:** Sets a per-image placeholder color (used for blur-up/loading states on the frontend) without altering the image itself.
- **UX expectation:** N/A directly, but the resulting placeholder color should visibly show as a loading-state background on the frontend for images without one yet.
- **Settings that change it:** None.
- **Edge cases:** A media #id that doesn't exist should error clearly, not silently no-op.

#### MV-CLI-014 — wp mvs cleanup-local
- **Edition:** Free (command ships in Free; requires an active Pro cloud driver to be meaningful and safe)
- **Who:** Shell access.
- **Where:** `wp mvs cleanup-local [--driver=…] [--limit=N] [--keep-thumbs] [--dry-run]`
- **Setup:** Media already fully migrated to a cloud driver and verified reachable there.
- **Steps:** 1. On `local` driver: "Active driver is 'local' — cleaning local files would break the site. Migrate to a cloud driver first." (hard refusal). 2. On a cloud driver with Pro, dry-run first, then real run — confirm the IRREVERSIBLE warning and a required confirm, and that the tool re-verifies cloud reachability per file before deleting the local copy ("Cloud verify FAILED for media #%d... keeping local file as safety").
- **Expected:** Only touches PUBLIC media rows by design (non-public is intentionally left alone, per the command's own success message when nothing matches).
- **UX expectation:** The safety-net behavior (refusing to delete a local file whose cloud copy fails verification) is the single most important thing to confirm here — this is exactly a "what must NOT happen" (data loss) guard, and it must actually trigger on a broken cloud copy, not just exist in the help text.
- **Settings that change it:** None.
- **Edge cases:** Reverting from cloud back to `local` afterward requires running `migrate-storage --to=local` first — this command alone cannot undo itself.

#### MV-CLI-015 — wp mvs relocalize-private
- **Edition:** Free (ships in Free; matters most on a site that has/had a cloud driver via Pro)
- **Who:** Shell access.
- **Where:** `wp mvs relocalize-private [--dry-run]`
- **Setup:** Non-public media rows whose URLs still point at cloud storage (a repair scenario after a privacy change didn't fully repatriate — see CAPABILITIES.md's note on 2.5.1's fix for this).
- **Steps:** 1. With nothing to heal: "No non-public media rows match. Nothing to heal." 2. With real candidates, dry-run lists each ("media #%d (privacy=%s) would be relocalized"), then a real run with the DB-backup warning + confirm.
- **Expected:** Rewrites `mvs_media_index.file_url` and `mvs_media_meta` thumb_* values back to local paths for non-public rows; already-clean rows are skipped and counted separately.
- **UX expectation:** Final summary must distinguish healed vs. already-clean counts — "%s %d row(s). Skipped %d already-clean row(s)."
- **Settings that change it:** None directly; complements the automatic 2.5.1 repatriation listener described under MV-SET-007's edge cases.
- **Edge cases:** This is the manual repair tool for exactly the scenario CAPABILITIES.md flags as "not verified this run" for live-bucket demotion — a good real-world regression check.

#### MV-CLI-016 — wp mvs optimize / wp mvs optimize-bulk
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs optimize <media_id>` and `wp mvs optimize-bulk [--dry-run] [--include-variants]`
- **Setup:** One or more images eligible for re-compression (MV-SET-008).
- **Steps:** 1. `wp mvs optimize` with a missing/invalid ID — "Missing or invalid <media_id>." 2. Run on a real image ID — confirm the same savings-percentage report as the admin row action (MV-ADM-007). 3. Run `optimize-bulk --dry-run`, then for real, with and without `--include-variants`.
- **Expected:** Identical underlying logic/behavior to the admin "Optimize" row action — CLI and admin-UI paths must agree (same commit-only-if-smaller rule).
- **UX expectation:** Bulk summary must report total processed with per-item errors surfaced, not swallowed.
- **Settings that change it:** Reads MV-SET-008's quality setting.
- **Edge cases:** "No image rows match the filters." when there's nothing eligible — must not be treated as an error exit code.

#### MV-CLI-017 — wp mvs backfill-ai
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs backfill-ai [--dry-run]`
- **Setup:** A valid OpenAI key (MV-SET-029), media items missing AI description/tags.
- **Steps:** 1. With nothing needing backfill: "No media need AI backfill." 2. Dry-run: "%d media would be processed (dry run)." 3. Real run — confirm budget cap (MV-SET-032) is respected mid-run, not just at the start.
- **Expected:** Applies the same describe/tag settings (MV-SET-031) retroactively to existing media.
- **UX expectation:** If the monthly budget is exhausted partway through, the command should stop/report gracefully rather than keep calling a provider that will just fail repeatedly.
- **Settings that change it:** Reads MV-SET-029/031/032.
- **Edge cases:** Confirmed: running twice in a row does not double-charge already-processed items. The "needs backfill" query is deliberately keyed on whether AI has ever RUN on an item (not on whether it produced a description) — so an item that completed with an empty result, or one that failed, is not retried on the next run. Only `--force` reprocesses everything regardless.

#### MV-CLI-018 — wp mvs repair-storage
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs repair-storage [--dry-run]`
- **Setup:** A library with a half-finished storage-driver migration (some files moved, some not).
- **Steps:** 1. With nothing to repair: "Nothing to repair." 2. Dry-run, then real run — idempotent, copy-only operation per its own docblock.
- **Expected:** Never deletes anything — purely additive/repairing (copies missing files into place).
- **UX expectation:** Safe to run repeatedly with no risk, which the copy-only design should make true — a good "does this ever make things worse" spot check.
- **Settings that change it:** None.
- **Edge cases:** Safe to run even when nothing is actually broken.

#### MV-CLI-019 — wp mvs diagnose-cpt-ids
- **Edition:** Free
- **Who:** Shell access.
- **Where:** `wp mvs diagnose-cpt-ids`
- **Setup:** A library, ideally one that has had post-ID collisions between `mvs_album`/`mvs_collection` CPT rows and other post types.
- **Steps:** Run the command; inspect the diagnostic report.
- **Expected:** Surfaces ID-collision risk between MediaVerse CPTs and other content (a real, documented failure class the plugin guards against elsewhere via `CptIdCollisionService`).
- **UX expectation:** Read-only diagnostic — must make no changes, only report.
- **Settings that change it:** None.
- **Edge cases:** Should run cleanly and report "no issues" on a healthy site, not merely output nothing.

#### MV-CLI-020 — wp mvs cert
- **Edition:** Free
- **Who:** Shell access, run against a live/staging WP install (`MVS_WP_PATH` in the local-CI context, or directly via `wp mvs cert` on the target site).
- **Where:** `wp mvs cert`
- **Setup:** A working WordPress install with the plugin active.
- **Steps:** Run the command; review the JSON/summary report it produces.
- **Expected:** Boot-smokes every REST route, checks for dead-toggle oracles (a setting that changes nothing) and reports toggle coverage — this is the plugin's own automated "does every setting actually do something" audit.
- **UX expectation:** N/A (developer/QA tool, not an end-user surface).
- **Settings that change it:** None (read-only diagnostic).
- **Edge cases:** A release should not ship with a red `wp mvs cert` result — if this catalog and a `wp mvs cert` run disagree about a setting's effect, trust `wp mvs cert`'s live probe over static reading.

### Area: PRV — Privacy (export/erase), user deletion

#### MV-PRV-001 — Personal Data Export: MediaVerse (all data)
- **Edition:** Free
- **Who:** Admin only, via wp-admin > Tools > Export Personal Data.
- **Where:** Tools > Export Personal Data
- **Setup:** A member with media, comments, reactions, follows, reports, DM history, etc.
- **Steps:** 1. Request an export for that member's email. 2. Select the "MediaVerse (all data)" exporter (or "Export all" which includes it). 3. Confirm the generated export/ZIP includes their media, social data, and anything else the plugin holds — including things easy to forget, per the code's own comment: the usage ledger and the error log if it references them.
- **Expected:** Registered via `wp_privacy_personal_data_exporters` as `wpmediaverse-all`, generated from the same `MemberDataMap` the eraser also uses, so export and erase can never disagree about what counts as "their data."
- **UX expectation:** The exporter must be visibly listed by name ("MediaVerse (all data)") in the core Export Personal Data tool's exporter list — a tester should be able to see it named there, not just infer it ran.
- **Settings that change it:** None.
- **Edge cases:** A large member's export must paginate (the callback takes a `$page` argument) rather than time out on a single giant pass — relevant at the 2000+-row big-account scale.

#### MV-PRV-002 — Personal Data Export: MediaVerse Media
- **Edition:** Free
- **Who:** Admin only.
- **Where:** Tools > Export Personal Data
- **Setup:** A member who has uploaded media.
- **Steps:** Request export; confirm the "MediaVerse Media" exporter section lists every media item the member authored, UNFILTERED (deliberately includes private/deleted-adjacent items — an export must disclose everything, not just what's publicly visible).
- **Expected:** `wpmediaverse-media` exporter, backed by `MediaRepository::author_media_export_rows()`.
- **UX expectation:** Must include private media too — an exporter that only showed public items would under-disclose, which is a real Article 15 compliance risk the code comments explicitly call out.
- **Settings that change it:** None.
- **Edge cases:** A member with zero media should produce an empty-but-present section, not omit the exporter entirely (consistency of structure across members).

#### MV-PRV-003 — Personal Data Export: MediaVerse Social Data
- **Edition:** Free
- **Who:** Admin only.
- **Where:** Tools > Export Personal Data
- **Setup:** A member with reactions, comments, follows, favorites, reports filed/received, DM history.
- **Steps:** Request export; confirm the "MediaVerse Social Data" exporter section appears and covers reactions, follows and favorites. Corrected from an earlier version of this catalog entry: this specific exporter does NOT cover comments or reports — comments are real WordPress comments, so they are already covered by WordPress core's own built-in comment exporter, and reports filed by the member are covered only by the broader "MediaVerse (all data)" exporter (MV-PRV-001), not this one.
- **Expected:** `wpmediaverse-social` exporter — an older, narrower exporter kept for continuity; the comprehensive one for a full Article 15 export is "MediaVerse (all data)" (MV-PRV-001).
- **UX expectation:** Same visibility expectation as MV-PRV-001/002 — named and listed. This exporter does not paginate (it always finishes on the first page), so a large account's reactions/follows/favorites are all pulled in one pass.
- **Settings that change it:** None.
- **Edge cases:** Confirmed: DM message content is NOT included in this specific "MediaVerse Social Data" exporter — it only covers reactions, follows and favorites. A member's sent-message content IS covered, but only via the separate "MediaVerse (all data)" exporter (MV-PRV-001), which pulls every column for rows the member sent (so only their own side of a conversation, never the other participant's messages).

#### MV-PRV-004 — Personal Data Erasure: MediaVerse
- **Edition:** Free
- **Who:** Admin only, via Tools > Erase Personal Data.
- **Where:** Tools > Erase Personal Data
- **Setup:** A member with a full spread of data (media, social, DM, follows, blocks, reports).
- **Steps:** 1. Request erasure for the member's email. 2. Confirm the single "MediaVerse" eraser runs to completion across multiple pages for a large member without leaving a half-erased state on a timeout. 3. Confirm bidirectional cleanup: another member's `mvs_follows`/`mvs_blocks`/`mvs_reports` ROWS REFERENCING the erased member are also cleaned, not just the erased member's own rows.
- **Expected:** One eraser (`wpmediaverse`), generated from `MemberDataMap`, replacing an older pair of hand-written erasers specifically because the old pair could disagree with the exporter about what "all their data" meant.
- **UX expectation:** The Tools > Erase Personal Data screen must show this eraser progressing/completing, and a re-run on an already-erased member should be a clean no-op, not an error.
- **Settings that change it:** None.
- **Edge cases:** Erasure of a member who is the OTHER party in an active DM conversation with a non-erased member: confirmed, the conversation thread itself is NOT destroyed for the remaining participant, as long as they are still an active participant. What actually happens: the erased member's own participation row and every message THEY sent are permanently deleted (not just anonymized) — so the remaining participant will see that side of the conversation disappear, while the thread itself and the remaining participant's own messages stay intact. If erasing a member would leave a conversation with no participants at all, the whole thread is cleaned up.

#### MV-PRV-005 — User deletion: "Attribute all content to" (reassign)
- **Edition:** Free
- **Who:** Any admin able to delete users (Users > All Users > Delete).
- **Where:** wp-admin > Users > Delete (the native WordPress delete-user screen)
- **Setup:** A member with media (some in a shared "team drive" context if applicable) and a second member to reassign to.
- **Steps:** 1. Delete a member who has media, choosing "Attribute all content to" a specific successor. 2. Confirm the successor now owns that media (author changed), stats/reactions/album membership on those items are preserved, not reset. 3. Repeat choosing "Delete all content" instead — confirm their media cascades to deletion (batched, not a single giant synchronous query for a heavy account).
- **Expected:** `deleted_user` action hook, `$reassign` carries the "Attribute all content to" choice exactly as WordPress core provides it. Reassignment happens in two phases: first any "team drive" media gets a filtered fallback successor (if the chosen reassign target doesn't apply there), then everything else goes to the admin's explicit choice.
- **UX expectation:** The reassignment dropdown is native WordPress UI — this task's job is to confirm MediaVerse actually HOOKS it correctly, i.e. picking a successor must visibly move authorship, not just leave orphaned rows silently pointing at a deleted user ID.
- **Settings that change it:** None (a one-time action).
- **Edge cases:** Reassigning to a target who is ALSO being deleted in the same batch, or who doesn't exist — code guards against reassigning to the same user being deleted or to a nonexistent user (`get_userdata( $reassign )` check).

#### MV-PRV-006 — User deletion: media-only members get the reassignment choice
- **Edition:** Free
- **Who:** Same as MV-PRV-005.
- **Where:** wp-admin > Users > Delete
- **Setup:** A member who has ONLY MediaVerse media/content and zero WordPress posts/pages/comments of their own (the case WordPress core alone would normally skip the reassignment UI for, since core only checks posts).
- **Steps:** 1. Attempt to delete such a member. 2. Confirm WordPress STILL shows the "What should be done with content owned by this user?" reassignment section, even though they have no native posts.
- **Expected:** `users_have_additional_content` filter (a real WordPress core filter) is hooked so MediaVerse-only content counts as "has content" for this purpose — without this hook, WordPress would silently offer no reassignment choice and the admin might not realize the member owned anything.
- **UX expectation:** This is a "must NOT happen" case in reverse — a media-only member being deleted with NO reassignment option shown would be a real defect (their media would just cascade-delete or orphan with no owner choice offered). Confirm the section genuinely appears.
- **Settings that change it:** None.
- **Edge cases:** Confirmed: a member with exactly one trashed (not published) media item still counts toward "has additional content" — the check that looks for a member's media doesn't filter by status at all, so trashed, draft, pending or published items all count equally toward showing the reassignment section.

#### MV-PRV-007 — Member self-service account deletion
- **Edition:** Free
- **Who:** Any logged-in member, for their own account only.
- **Where:** `DELETE /mvs/v1/me/deletion` (REST; the frontend UI trigger is typically a profile/settings page control), `GET /mvs/v1/me/deletion` to check status
- **Setup:** A test member account with a real password (not SSO-only, since password confirmation is required).
- **Steps:** 1. Request deletion, confirming with the account password. 2. Confirm the account enters a grace period (`mvs_account_deletion_grace_days`, filterable) rather than deleting immediately, and is suspended (browsable-only) during the wait. 3. Confirm every Application Password and active session is revoked immediately on request (defends against a stolen-session deletion). 4. Cancel the pending deletion by signing in with the real password before the grace period ends — confirm the account returns to normal (this is also reachable via MV-ADM-015's "Cancel" link on the admin side).
- **Expected:** `Services/AccountDeletionService::request()` requires the account password even if the caller authenticated via Application Password (the plugin's stated genuine-second-factor reasoning) — wrong password returns `mvs_invalid_password`. A grace period of 0 (owner-configured) deletes immediately with no window.
- **UX expectation:** A wrong password on the deletion request must give a clear, specific error, not a generic failure — and the immediate credential/session revocation should be something a tester can independently verify (e.g. confirm an app password issued before the request stops working right after the request, even though the account isn't deleted yet).
- **Settings that change it:** `mvs_account_deletion_grace_days` filter (no settings-screen UI — code/filter only).
- **Edge cases:** A cron sweep (`process_due()`) executes deletion for every account whose grace period has expired — confirm a member who cancelled in time is correctly excluded from that sweep, not accidentally caught by a race.

#### MV-PRV-008 — Remove Data on Delete (uninstall coverage, cross-reference)
- **Edition:** Free
- **Who:** Admin only.
- **Where:** MediaVerse > Settings > General; effect occurs at plugin uninstall.
- **Setup:** See MV-SET-006 for the full setting entry.
- **Steps:** See MV-SET-006. Cross-referenced here under Privacy because it is the concrete answer to "does uninstalling actually remove personal data" — with the setting ON, uninstall removes ALL 23 `mvs_*` tables (sourced live from `Migrator::tables()`, not a second hand-maintained list that can drift) plus related options/postmeta/capabilities.
- **Expected:** Uploaded files and the pages the plugin created are never touched either way; if MediaVerse Pro is still installed, Free's uninstall must not drop tables Pro still needs.
- **UX expectation:** Same as MV-SET-006.
- **Settings that change it:** "Remove Data on Delete" (`mvs_delete_data_on_uninstall`).
- **Edge cases:** Same as MV-SET-006.

#### MV-PRV-009 — Privacy Policy content suggestion
- **Edition:** Free
- **Who:** Admin, via Settings > Privacy.
- **Where:** wp-admin > Settings > Privacy (the site's Privacy Policy page editor)
- **Setup:** A site with a Privacy Policy page (WordPress prompts to create one if missing).
- **Steps:** 1. Open the Privacy Policy page editor. 2. Look for a MediaVerse-suggested content block (via `add_privacy_policy_content()`, hooked on `admin_init`). 3. Click to add the suggested text into the policy.
- **Expected:** MediaVerse contributes suggested policy language describing what it collects (media, social interactions, DM content, device tokens for push, etc.) for the owner to include.
- **UX expectation:** Suggested text should be genuinely usable as-is or with light editing — a tester should judge whether it accurately describes what's actually collected per this catalog's other entries (device tokens, DM content, AI provider calls sending image data to OpenAI, etc.) rather than being generic boilerplate.
- **Settings that change it:** None (informational content only).
- **Edge cases:** Confirmed accurate: the suggested copy explicitly covers EXIF/location stripping, reactions/comments/follows/favorites/mentions, that direct messages are stored so conversation participants can read them, view tracking, that "if the site owner turns on AI tagging or moderation, images you upload are sent to OpenAI for analysis," device push tokens for the mobile app, and a plain-English list of what erasure keeps and why (reports, usage records, shared conversations). It matches what the plugin actually does across every other entry in this catalog — no inaccuracy found.

### Area: TPL — Template overrides and versioning

#### MV-TPL-001 — Theme template override mechanism
- **Edition:** Free
- **Who:** Theme developers / site owners with a child theme; the effect is visible to every visitor.
- **Where:** `<active-theme>/wpmediaverse/<template-name>.php` (any file in the plugin's `templates/` tree, except `templates/admin/`)
- **Setup:** A child theme.
- **Steps:** 1. Copy a plugin template (e.g. `templates/collection.php`) into the active theme's `wpmediaverse/` folder unmodified. 2. Confirm the frontend renders identically (the theme copy is now what's loaded — `TemplateLoader`'s `locate_template`-style lookup). 3. Edit the theme's copy (e.g. change a heading) and confirm the change shows.
- **Expected:** A theme copy always takes precedence over the plugin's own file, for every overridable template.
- **UX expectation:** No visible difference for an unmodified copy — a byte-identical override must render identically to the plugin's own file, so a tester can distinguish "override exists but is stale" from "override exists and matches."
- **Settings that change it:** None; a filesystem-level mechanism. Pro adds its own template root via `mvs_template_roots`.
- **Edge cases:** A theme override that reads a request parameter the plugin's current template no longer uses (or vice versa) can silently break functionality without any visible error — exactly the class of regression MV-HLT-005 exists to catch via the `@version` header, since a broken override otherwise looks like a normal page.

#### MV-TPL-002 — Template `@version` header and staleness detection
- **Edition:** Free
- **Who:** Same as MV-TPL-001; surfaced to the admin via Site Health (MV-HLT-005).
- **Where:** Each overridable template file's PHP docblock (`@version`), compared by `HealthCheckService::test_template_overrides()`
- **Setup:** As MV-TPL-001, but with a DELIBERATELY OLD copy (missing `@version`, or an older version string than the plugin's current file).
- **Steps:** 1. Confirm the Site Health test (MV-HLT-005) flags the specific stale file by name. 2. Update the theme's copy to match the current plugin version's markup and bump/add its `@version`. 3. Confirm Site Health clears.
- **Expected:** `@version` moves ONLY on a release that changes a template's markup or variables — per Coding Rule 24, a template that stops reading/sending a request value is exactly the kind of change that must bump `@version`, precisely because that class of change is otherwise invisible until a customer reports broken behavior.
- **UX expectation:** The staleness detection is the entire point here — a tester's job is to confirm it actually fires for a genuinely outdated copy, not just that the header exists.
- **Settings that change it:** None.
- **Edge cases:** A theme copy that is NEWER than the plugin's current version (a theme dev got ahead of a plugin downgrade). Confirmed: the comparison only flags a theme copy whose version is strictly LOWER than the plugin's current version — a theme copy that is the same or newer is never flagged, exactly as expected.

#### MV-TPL-003 - Pro layout templates and theme copies
- **Edition:** Free + Pro
- **Who:** Theme developers.
- **Where:** `wp-content/themes/<theme>/wpmediaverse/<template>.php`
- **Setup:** Pro active with a layout other than the default grid (for example Pinterest). Copy a template Pro replaces for that layout (for example `explore.php`) into the theme's `wpmediaverse/` folder and add a visible marker.
- **Steps:** 1. Load Explore. 2. Remove the theme copy and reload.
- **Expected:** With the theme copy present, the theme copy renders (the marker shows): a theme override always wins over the Pro layout's own template. Without it, the Pro layout template renders.
- **UX expectation:** The owner's customisation is never silently replaced by switching layouts.
- **Settings that change it:** MediaVerse > Settings > Display > Layout.
- **Edge cases:** The `mvs_template_roots` filter only tells Site Health where to find template versions (MV-TPL-002); it does not decide which file loads. `mvs_locate_template` does.

### Area: API — REST (`mvs/v1`), grouped by resource

General pattern confirmed across controllers: an unauthenticated caller on a write route gets `401 mvs_unauthorized` ("You must be logged in."); an authenticated caller lacking the needed capability gets `403 mvs_forbidden`/`mvs_rest_forbidden` ("You do not have permission to..."); a private/hidden media item requested by `GET` returns `404 mvs_not_found` regardless of whether it truly doesn't exist or exists-but-is-hidden — a denied read is INDISTINGUISHABLE from a missing one, by design, so enumeration can't confirm existence.

**The hidden-item rule (applies to every route below, Free and Pro).** To a caller who may not VIEW an item (a private or members-only media item, album, collection, comment, message, document or folder), every method on that item (GET, PUT, PATCH, DELETE, POST to a sub-route) answers exactly like a nonexistent id: same status, same error code, same message. A caller who CAN view an item but may not change it (for example someone else's public photo) gets `403` with a "You do not have permission to..." message. Test it with two members of the same role: compare the answer for B's private item with the answer for an id that does not exist; they must be identical.

#### MV-API-001 — Media: list and single read
- **Edition:** Free
- **Who:** Public (`__return_true`) for `GET /media` and `GET /media/{id}`; per-item privacy still applies inside the callback.
- **Where:** `GET /mvs/v1/media`, `GET /mvs/v1/media/{id}`
- **Setup:** A mix of public/members/private media across several authors.
- **Steps:** 1. Unauthenticated: `GET /mvs/v1/media` — confirm only PUBLIC items are returned, and paging/`X-WP-Total` reflect only the visible set, not the true total. 2. Unauthenticated: `GET /mvs/v1/media/{id}` for a private item — confirm `404 mvs_not_found`, byte-for-byte the same shape as requesting a nonexistent ID. 3. As the owner or a member with view rights, repeat and confirm the item now returns normally. 4. `GET /mvs/v1/media?slug=<exact-slug>` and `GET /mvs/v1/media?include=<id1,id2,...>` (batch, capped at 100, privacy-gated per ID, order preserved).
- **Expected:** Rate-limited at 120 requests/min per user/IP (`media_read`) to deter scraping — confirm a burst past that returns a rate-limit error rather than an open firehose.
- **UX expectation:** A denied item must look EXACTLY like a missing one (status 404, same error code `mvs_not_found`, same message) — no field, header or timing difference an app could use to infer "this exists but I can't see it" vs "this never existed."
- **Settings that change it:** MV-SET-010/011/014 (privacy defaults, member-only lock, Members Only gate).
- **Edge cases:** `?slug=` with a non-string/array value must not fatal (a previously-shipped bug class this route now guards against explicitly with an `is_string()` check).

#### MV-API-002 — Media: create (upload)
- **Edition:** Free
- **Who:** `manage_options` or `upload_mvs_media` capability; a role without it is refused.
- **Where:** `POST /mvs/v1/media`
- **Setup:** A member role with and without `upload_mvs_media` (MV-SET-012).
- **Steps:** 1. As a role without the cap: `403 mvs_forbidden` ("You do not have permission to upload media."). 2. As a capable role, upload a file within MV-SET-002/003's limits. 3. Upload an oversized/disallowed-type file — confirm a specific, actionable error, not a generic 500.
- **Expected:** Success returns the created media object; the item's initial privacy follows MV-SET-010/011.
- **UX expectation:** A disallowed-type or oversized upload must return an error an app can show verbatim to the member (naming the limit/type), not just an HTTP status code with no message.
- **Settings that change it:** MV-SET-002, MV-SET-003, MV-SET-004, MV-SET-005, MV-SET-010, MV-SET-011, MV-SET-012, MV-SET-028 (AI auto-moderation on new uploads).
- **Edge cases:** A duplicate (by hash) upload behaves per MV-SET-004's configured action.

#### MV-API-003 - Media: update and delete
- **Edition:** Free
- **Who:** The item's own author (with `edit_mvs_medias`/`delete_mvs_medias`) OR anyone with `edit_others_mvs_medias`/`delete_others_mvs_medias`. Everyone else is refused.
- **Where:** `PUT`/`PATCH /mvs/v1/media/{id}`, `POST /mvs/v1/media/{id}/replace`, `DELETE /mvs/v1/media/{id}`
- **Setup:** Members A and B (same role). A owns one PUBLIC and one PRIVATE item.
- **Steps:** 1. As B, PUT and DELETE A's public item. 2. As B, PUT and DELETE A's private item. 3. As B, PUT and DELETE id 99999999 (does not exist). 4. As A, edit and delete their own item. 5. As an admin, edit B's item.
- **Expected:** Step 1: `403 mvs_forbidden` ("You do not have permission to edit/delete this media item."). Steps 2 and 3: identical `404 mvs_not_found` ("Media item not found."). Steps 4 and 5 succeed.
- **UX expectation:** A member never learns that someone else's private item exists. Scripts must compare the error CODE as well as the status.
- **Settings that change it:** MV-SET-011 (a privacy CHANGE on a locked item returns `403 mvs_privacy_locked` to the owner).
- **Edge cases:** Same rule for albums (`PUT`/`DELETE /mvs/v1/albums/{id}` and its `/reorder`, `/items`, `/cover` sub-routes), collections, comments (`/media/{id}/comments/{cid}`), bulk "move to album", and deleting or unsending a message in someone else's conversation.

#### MV-API-004 — Media: bulk actions
- **Edition:** Free
- **Who:** Requires the base `edit_mvs_medias` capability (the same "edit own" capability as a single-item edit) — one flat check for the whole endpoint, regardless of which bulk action is requested; it does not separately require "edit others" or "delete others."
- **Where:** `POST /mvs/v1/media/bulk`
- **Setup:** Several media items, mixed ownership.
- **Steps:** 1. As a member, bulk-act only on their own items — succeeds. 2. As the same member, include one item they don't own in the same batch — confirmed behavior: the batch is NOT refused outright. Items the member doesn't own are silently dropped from processing before the action runs, and the response reports both `requested` (how many IDs were sent) and a `skipped` count, so the caller can tell some items were dropped due to ownership rather than being told "12 processed" when only 4 actually were. It is not a fully itemized per-ID reason list, but it is not opaque either.
- **Expected:** A single permission check gates the whole bulk endpoint.
- **UX expectation:** The endpoint is NOT all-or-nothing (confirmed above), so this concern doesn't apply as originally framed — the remaining thing to verify in the browser is that the app/UI actually surfaces the `skipped` count to the member rather than only showing "processed," so a partial batch doesn't read as a full success.
- **Settings that change it:** Same as MV-API-003.
- **Edge cases:** An empty or malformed ID list should be a clean validation error, not a fatal.

#### MV-API-005 — Media: replace file
- **Edition:** Free
- **Who:** Same ownership rule as update (MV-API-003).
- **Where:** `POST /mvs/v1/media/{id}/replace`
- **Setup:** An existing media item.
- **Steps:** 1. Replace the file as the owner — confirm the media ID, stats, reactions, views and album membership all survive unchanged; only stale thumbnail/variant metadata clears and regenerates. 2. Attempt the same file-type/size/duplicate/EXIF rules as a fresh upload (MV-API-002) — confirm they're enforced here too (no bypass via replace).
- **Expected:** This is an UPDATE-in-place, not a new media row — permalinks/shares pointing at the old ID keep working with new content.
- **UX expectation:** Reactions/comments/view counts must visibly persist across a replace — a tester seeing the counter reset to zero after replacing a file has found a real regression.
- **Settings that change it:** MV-SET-003/005 (same MIME/EXIF rules as upload apply here too).
- **Edge cases:** Documents remain hard-refused via replace too, matching the media-library upload restriction — no bypass path.

#### MV-API-006 — Media: view / download / share / report tracking
- **Edition:** Free
- **Who:** `view` and typically `download`/`share` are public-readable actions gated by the item's own privacy (not a separate capability); `report` requires MV-SET-026 to be on.
- **Where:** `POST /mvs/v1/media/{id}/view`, `/download`, `/share`, `/report`
- **Setup:** A media item, a reporting member.
- **Steps:** 1. `POST .../view` on a public item logged out — succeeds, increments the view counter (visible on MV-ADM-017). 2. `POST .../report` with reporting OFF (MV-SET-026) — confirm refusal. 3. `POST .../report` with it on — confirm the report lands in MV-ADM-014's Pending queue and counts toward MV-SET-027's auto-hide threshold.
- **Expected:** View events respect MV-SET-009's retention window for how long the raw event row persists (aggregate stays).
- **UX expectation:** Reporting an item you already reported: confirmed graceful — the second attempt is refused with a clear `400` error, "Unable to submit report. You may have already reported this." (not a silent duplicate, and not a generic failure). Reporting your OWN media item: confirmed this is NOT blocked (only self-reporting a USER is blocked) — a member reporting their own upload succeeds as long as they haven't already reported it. That's a minor, low-impact quirk rather than a security issue, but worth knowing before reading a self-report as a bug.
- **Settings that change it:** MV-SET-026, MV-SET-027, MV-SET-009.
- **Edge cases:** Viewing a private item you're not allowed to see should not silently record a view (the privacy gate should block before the view-tracking side effect, not after).

#### MV-API-007 — Media: signed URL, access, group, stats
- **Edition:** Free
- **Who:** `GET .../signed-url` requires view rights on the item (`get_signed_url_permissions_check`); `GET .../access`, `/group`, `/stats` similarly privacy-gated.
- **Where:** `GET /mvs/v1/media/{id}/signed-url`, `/access`, `/group`, `/stats`
- **Setup:** A private media item, an owner and a non-permitted member.
- **Steps:** 1. As the owner, request a signed URL, confirm it expires per MV-SET-009's TTL and works within that window. 2. Past expiry, confirm the signed URL is refused UNLESS the item is public (public items keep serving an expired-but-validly-signed URL to protect page caches — a deliberate, documented exception). 3. As a non-permitted member, request `/group` for an album-linked item — confirm it now applies the SAME per-item privacy check the rest of the read surface does (2.5.1 fix — previously this route was a gap).
- **Expected:** `/access` reflects the caller's actual permission state for that item (useful for an app deciding what UI to show).
- **UX expectation:** The public-item expired-URL exception must be intentional-looking (still serves the file) not accidentally-broken-looking — but a PRIVATE item's expired URL must be refused, no exception.
- **Settings that change it:** MV-SET-009 (`mvs_signed_url_ttl`).
- **Edge cases:** A tampered signature (one changed character) must fail `hash_equals()` and be refused — never partially trusted.

#### MV-API-008 — Media: comments
- **Edition:** Free
- **Who:** Read is public/privacy-gated; create requires being logged in and allowed to view the item; edit/delete requires being the comment's author within the edit window, or a moderation capability.
- **Where:** `GET/POST /mvs/v1/media/{id}/comments`, `PUT/PATCH/DELETE /mvs/v1/media/{id}/comments/{comment_id}`
- **Setup:** A commentable item, a comment just posted.
- **Steps:** 1. Post a comment, then edit it within MV-SET editing window (`mvs_comment_edit_window`, default 900s/15min, no screen control). 2. Wait past the window and attempt to edit again — confirm refusal. 3. Post the exact same comment text twice within 60 seconds — confirm the separate duplicate-comment guard blocks the second.
- **Expected:** Comments are real WordPress comments under a dedicated `comment_type`. Confirmed: they are deliberately EXCLUDED from wp-admin's native Comments screen — the plugin actively filters its own comment type out of any comment query that doesn't explicitly ask for it, so MediaVerse comments never appear on Comments > All Comments (or any other native comment-listing screen) by default. There is no secondary admin surface here; moderating MediaVerse comments happens only through MediaVerse's own screens/API.
- **UX expectation:** The edit-window expiry message must tell the member their window has closed, not just refuse silently.
- **Settings that change it:** `mvs_comment_edit_window` (no screen control).
- **Edge cases:** Commenting on a private item you can't view must be refused before the comment is ever created, mirroring MV-API-006's view-before-track ordering.

#### MV-API-009 — Media: reactions and favorites
- **Edition:** Free
- **Who:** Logged-in members (`auth_check`/similar); read of the reaction/favorite counts follows item privacy.
- **Where:** `GET/POST/DELETE /mvs/v1/media/{id}/reactions`, `GET/POST/DELETE /mvs/v1/media/{id}/favorite`
- **Setup:** Two members, one item.
- **Steps:** 1. As member A, react to the item, confirm the count updates. 2. React again with a different emoji — confirmed behavior: this REPLACES the first reaction with the new one; it does not add a second, simultaneous reaction. It is one reaction per user per item, and reacting the SAME way twice removes it (a toggle). 3. Favorite the item; confirm it appears in `GET /mvs/v1/me/favorites` (MV-API-015). 4. Unauthenticated attempt on either — `401 mvs_unauthorized`.
- **Expected:** Both are simple logged-in-required actions with no extra capability beyond being a member.
- **UX expectation:** Un-reacting/un-favoriting (DELETE) on something never reacted-to/favorited should be a graceful no-op, not an error.
- **Settings that change it:** None directly.
- **Edge cases:** Reacting to a private item you can't view must be refused, matching the comment/view pattern.

#### MV-API-010 — Albums: CRUD, items, cover, reorder
- **Edition:** Free
- **Who:** Read is public/privacy-per-item inside the album; create requires `upload_mvs_media`; update/delete/items/cover/reorder require ownership OR `edit_others_mvs_medias`/`delete_others_mvs_medias`.
- **Where:** `GET/POST /mvs/v1/albums`, `GET/PUT/PATCH/DELETE /mvs/v1/albums/{id}`, `GET/POST/DELETE /mvs/v1/albums/{id}/items`, `GET/DELETE /mvs/v1/albums/{id}/items/{media_id}`, `PUT/PATCH /mvs/v1/albums/{id}/cover`, `/reorder`
- **Setup:** An album with several items, some public some private, owned by member A; member B without "others" caps.
- **Steps:** 1. As B, `GET /albums/{id}/items` — confirm only items B is individually allowed to view are listed (per-item privacy still applies inside an album — the 2.5.1 fix made this a single shared rule, `AlbumService::viewable_item_ids()`, rather than each renderer having its own copy that could drift). 2. As B, attempt `PUT /albums/{id}` (not owner) — `401` if logged out, `403` if logged in without the cap (NOT masked as 404 — album edit/delete uses the explicit-403 ownership pattern, confirmed in code). 3. As A, reorder items and set a cover — succeeds.
- **Expected:** Bulk "Add to album" from the dashboard/Explore now goes through the SAME `AlbumService::add_items()` method a single add does (2.5.1), so a batch add gets the same privacy clamp and playlist audio-only check as one-at-a-time — confirm a batch add of a mixed audio+video selection into an audio-only playlist-style album is rejected/filtered consistently, not silently accepted for the batch path.
- **UX expectation:** An app or template rendering an album must never leak a private item's thumbnail/title to a viewer who can't individually view that item — this was a real, fixed gap, so it is worth specifically re-testing per release.
- **Settings that change it:** MV-SET-010/011/012 (privacy + upload roles feed into album permissions).
- **Edge cases:** "Add to album" is deliberately Add, not Move — an item added to a second album must remain in the first.

#### MV-API-011 — Collections: CRUD, items, rules
- **Edition:** Free
- **Who:** Read is public/`members`-gated at the collection level (only two privacy levels exist for collections, unlike media's nine); create/update/delete require ownership via `owner_permissions_check`.
- **Where:** `GET/POST /mvs/v1/collections`, `GET/PUT/PATCH/DELETE /mvs/v1/collections/{id}`, `GET/PUT/PATCH /mvs/v1/collections/{id}/items`, `/rules`
- **Setup:** A `members`-privacy smart collection with rules matching some private media belonging to OTHER members.
- **Steps:** 1. As a logged-out visitor, `GET /collections/{id}` on a `members`-privacy collection — confirm refusal (this is the container-level gate `[mvs_collection]` gained in 2.5.1, previously missing). 2. As a permitted member, confirm the resolved items exclude any OTHER member's private media even if a smart rule would otherwise match it (`CollectionService::may_contain()` — public media, or the curator's own, never someone else's private item, and never a document).
- **Expected:** `get_privacy()` coerces any invalid stored privacy value back to `public` defensively.
- **UX expectation:** A collection showing a curated grid must never surface a private item belonging to someone who isn't the curator — this is exactly the sort of leak the 2.5.1 authorization round specifically closed; re-test it.
- **Settings that change it:** MV-ADM-019 (Collection Settings meta box) is the primary editing surface; this API is the programmatic equivalent.
- **Edge cases:** A rule targeting `media_type=document` will never actually match anything a collection is allowed to contain, since documents are excluded from collections outright.

#### MV-API-012 — Tags: list, cloud, create, merge, update, delete
- **Edition:** Free
- **Who:** `GET /tags` and `/tags/cloud` are public; `POST /tags` requires the `upload_mvs_media` capability (confirmed — tied to upload, as expected: `manage_options` also qualifies); update/delete/merge require `admin_check` (`moderate_mvs_media`).
- **Where:** `GET /mvs/v1/tags`, `GET /mvs/v1/tags/cloud`, `POST /mvs/v1/tags`, `PUT/PATCH/DELETE /mvs/v1/tags/{id}`, `POST /mvs/v1/tags/merge`
- **Setup:** A moderator-capable and a plain-member account.
- **Steps:** 1. As a plain member, attempt `DELETE /tags/{id}` — `403 mvs_forbidden` ("You do not have permission to manage tags."). 2. As a moderator, merge two tags via the API and confirm it produces the same batched result as MV-ADM-011's UI merge tool (same underlying batch hook).
- **Expected:** Read routes are fully public — a logged-out app screen can show the tag cloud without authentication.
- **UX expectation:** A non-moderator member should not even see tag-management controls in an app built against this API, since every write beyond creating a tag while uploading is refused.
- **Settings that change it:** None directly.
- **Edge cases:** Confirmed parity: the API's merge route rejects a self-merge (source and target the same tag, `400 mvs_same_tag`) and rejects a stale/nonexistent source or target tag (`404 mvs_not_found`) — the same guards as the admin UI's merge tool, enforced independently at the API layer, not only in the wp-admin form.

#### MV-API-013 — Moderation: queue, counts, analyze/approve/reject
- **Edition:** Free
- **Who:** `moderate_mvs_media` required for every route in this group, including the read-only counts/list.
- **Where:** `GET /mvs/v1/moderation`, `/moderation/counts`, `POST /mvs/v1/moderation/{id}/analyze`, `/approve`, `/reject`
- **Setup:** A moderator account and a plain-member account, items pending review.
- **Steps:** 1. As a plain member, `GET /mvs/v1/moderation` — `403 mvs_rest_forbidden` ("You do not have permission to moderate media."). 2. As a moderator, approve/reject via the API and confirm it produces identical results to MV-ADM-013's admin-UI actions (same underlying `ModerationService`).
- **Expected:** Even the READ (queue listing, counts) is capability-gated here, unlike most list routes in this plugin — the moderation queue itself is never public.
- **UX expectation:** A native app's moderator role screen should be fully drivable through this group alone with no admin-only fallback needed.
- **Settings that change it:** MV-SET-028 (AI moderation feeds the analyze/flag pipeline this group manages).
- **Edge cases:** Two moderators approving the same item within seconds of each other — confirm the second gets a clean "already handled" outcome, not a duplicate action or error (multi-actor concurrency).

#### MV-API-014 — Users: profile, media, activity, followers/following, search, block, follow, report
- **Edition:** Free
- **Who:** Most reads (`/users/{id}`, `/media`, `/activity`, `/followers`, `/following`, `/search`, `/suggested`) are public; `/block`, `/follow`, `/report` require login (`logged_in_check`); `PUT`ing another user's admin fields requires `edit_users` (`manage_users_check`).
- **Where:** `GET /mvs/v1/users/{id}`, `/activity`, `/followers`, `/following`, `/media`, `GET /mvs/v1/users/search`, `/suggested`, `POST/DELETE /mvs/v1/users/{id}/block`, `/follow`, `POST /mvs/v1/users/{id}/report`
- **Setup:** Two members, A and B, where A has blocked B.
- **Steps:** 1. As B (blocked by A), `GET /users/A/media` — confirm A's media, profile listing and its counts are now hidden from B specifically (the 2.5.0 fix — a block actually hides things on both the read side and Explore's listing, including page 1, not only later pages). 2. As B, attempt to message or otherwise interact with A — should also be refused via the write-side block gate (`RestGuards`). 3. As B, `POST /users/A/report` — this must remain allowed even though A blocked B (a blocked member must always be able to report their blocker — an explicit "exempt" classification in `RestGate`).
- **Expected:** No admin surface exists in wp-admin for viewing/auditing/undoing a block (CAPABILITIES.md confirms this limit explicitly) — the API is the ONLY way to see or manage blocks.
- **UX expectation:** Report and unblock/unfollow must always be exempt from the block gate ("a blocked member must always be able to withdraw consent or report their blocker") — this is a specific, code-documented design rule worth testing directly, not assuming.
- **Settings that change it:** None (block/follow/report have no admin settings).
- **Edge cases:** Since there's no admin UI for blocks, a site owner cannot currently un-block two members on a customer's behalf except by asking one of them to do it via the API/app — flag as a known gap, not a bug to "fix" in Free.

#### MV-API-015 — Me: profile, media, stats, storage, favorites, blocked, interests, transactions, notifications, avatar, deletion, devices
- **Edition:** Free
- **Who:** The logged-in caller only, for their own data — every route in this group is "check_logged_in"/"logged_in_check"-gated and scoped to `wp_get_current_user()`, never another user's ID.
- **Where:** `GET/PUT/PATCH /mvs/v1/me/profile`, `GET /mvs/v1/me/media`, `/stats`, `/storage`, `/favorites`, `/blocked`, `/interests`, `/transactions`, `/notifications`, `/notifications/count`, `POST /mvs/v1/me/notifications/read`, `POST/DELETE /mvs/v1/me/avatar`, `GET/DELETE /mvs/v1/me/deletion`, `POST/DELETE /mvs/v1/me/devices`, `POST /mvs/v1/me/interests`, `/dismiss`, `/onboarding/complete`
- **Setup:** A logged-in member with some history in each area.
- **Steps:** 1. Confirm every one of these routes, unauthenticated, returns `401` (no data leak — there is no "me" to answer for). 2. As the member, exercise the avatar upload/delete (Gravatar fallback when deleted) and the transactions ledger (append-only, running balance — confirm no PUT/edit route exists to alter past entries, only new events append). 3. `GET /me/storage` and confirm it matches MV-SET-001's math (site limit unless a per-user override from MV-ADM-016 exists).
- **Expected:** `/me/deletion` and `/me/devices` map directly to MV-PRV-007's account-deletion flow and MV-ADM-015-adjacent push-token registry respectively.
- **UX expectation:** Every field returned must be the CALLER's own — this group should never accept a `user_id` parameter that could redirect it to someone else's data; that would be a serious authorization bug.
- **Settings that change it:** MV-SET-001 (storage), MV-SET-032/033 (budget/email toggles feed notification content), MV-SET-015 (App Sign-In gates whether a device token registration flow via app-password even applies).
- **Edge cases:** `GET /me/transactions` on an account with zero activity should return an empty list cleanly, not an error.

#### MV-API-016 — Conversations and messages
- **Edition:** Free
- **Who:** Participants only, gated by MV-SET-020/021/022; logged-in required throughout.
- **Where:** `GET/POST /mvs/v1/conversations`, `GET/PATCH/DELETE /mvs/v1/conversations/{id}`, `POST .../accept`, `/decline`, `/read`, `/typing`, `GET/POST /mvs/v1/conversations/{id}/messages`, `GET .../messages/search`, `GET .../media`, `DELETE /mvs/v1/messages/{id}`, `/unsend`, `POST/DELETE /mvs/v1/messages/{id}/reactions`, `POST /mvs/v1/messages/upload`, `GET /mvs/v1/messages/poll`, `GET /mvs/v1/me/conversations`, `/me/messages/unread-count`
- **Setup:** Two members, MV-SET-020 on, MV-SET-021 set to a restrictive value for one test pass.
- **Steps:** 1. With messaging OFF (MV-SET-020), confirm every route in this group 404s (routes not registered at all — a hard kill switch, not a 403). 2. With messaging on and MV-SET-021 restrictive, confirm a disallowed new-conversation attempt is refused with a specific reason. 3. Upload an attachment — confirm the 10MB cap and MIME sniff via `finfo_file()` (extension alone is not trusted), and that PDFs are excluded even here. 4. Unsend a message — confirm it's removed for all participants, distinctly from a plain delete.
- **Expected:** `/messages/poll` is the fallback transport (`RestPollingTransport`) for clients without a push/websocket layer.
- **UX expectation:** The 404-not-403 distinction here (whole feature off) vs 403 (feature on but this specific action refused) matters for an app's error handling — confirm the app can distinguish "messaging isn't a thing here" from "you can't do that."
- **Settings that change it:** MV-SET-020, MV-SET-021, MV-SET-022, MV-SET-023, MV-SET-024.
- **Edge cases:** A non-participant attempting any conversation-scoped route (by guessing an ID) must be refused, not shown a response that confirms the ID's existence. Confirmed: conversations consistently use the EXISTENCE-MASKING pattern (like media reads), not the explicit-403 ownership pattern (like albums and media writes) — every conversation-scoped route, including destructive ones like `DELETE /conversations/{id}`, returns the same `404 not_found` for both "doesn't exist" and "exists but you're not a participant." This is a deliberate, code-documented choice specifically so a refusal never confirms a conversation exists — notably stricter than the media/album write endpoints (see the MV-API-001/003 note above), which do leak existence on PUT/DELETE.

#### MV-API-017 — Auth: nonce refresh, Application Password exchange
- **Edition:** Free
- **Who:** `GET /auth/nonce` requires being logged in already (cookie session); `POST /auth/app-password` is intentionally public (`__return_true`) since it's how a member gets their FIRST credential.
- **Where:** `GET /mvs/v1/auth/nonce`, `POST /mvs/v1/auth/app-password`
- **Setup:** A member's real username/email + password; a suspended member (MV-ADM-015); MV-SET-015's App Sign-In off.
- **Steps:** 1. `POST /auth/app-password` with correct credentials — confirm an Application Password is issued. 2. With MV-SET-015 off, repeat — confirm refusal. 3. As a suspended member, repeat — confirm refusal (the suspension gate applies even to issuing a fresh credential). 4. Attempt rapid repeated calls — confirm rate limiting kicks in BEFORE any credential is even read/checked (per the code's own stated ordering).
- **Expected:** Failures are uniform (don't distinguish "wrong username" from "wrong password" from "TLS required" in ways that would help an attacker enumerate valid usernames) — and a 409 is returned rather than silently bypassing any 2FA the site has.
- **UX expectation:** The nonce-refresh route existing at all is purely a browser-JS reliability detail (auto-retry after a 403 `rest_cookie_invalid_nonce`) — a tester should not expect end-user-visible behavior from it directly, only that logged-in browsing sessions don't randomly break with stale-nonce errors.
- **Settings that change it:** MV-SET-015 (`mvs_app_password_login`).
- **Edge cases:** Attempting this over plain HTTP (not TLS) on a site that requires it — confirm a clear refusal, not a credential silently issued over an insecure channel.

#### MV-API-018 — App: config and interests (pre-login discovery)
- **Edition:** Free
- **Who:** `GET /app/config` and `GET /app/interests` are fully public (pre-login), `POST /me/interests` and `GET /me/interests` (interests read/write for the current member) require login.
- **Where:** `GET /mvs/v1/app/config`, `GET /mvs/v1/app/interests`, `GET/POST /mvs/v1/me/interests`
- **Setup:** None for the public routes.
- **Steps:** 1. Logged out, `GET /app/config` — confirm it returns site-level feature flags/branding an app needs BEFORE a user logs in, and that it is EXEMPT from the Members Only gate (MV-SET-014) even on a fully private community, since an app needs this to even render its login screen. 2. As a member, set interests via `POST /me/interests`, then confirm `GET /mvs/v1/users/suggested` (MV-API-014) reflects an interest-based boost.
- **Expected:** `app/config`'s public-by-necessity status is explicitly documented in code (Rule-2 allowlisted, alongside `/serve` and `/auth/app-password`).
- **UX expectation:** A private community's Members Only gate must NOT accidentally start blocking `/app/config` in some future change — that would break the app's ability to even show a login screen; this is a specific regression to watch for.
- **Settings that change it:** MV-SET-014 (must stay exempt, not "should").
- **Edge cases:** `app/config` content should differ meaningfully based on which features/settings are active (e.g. whether messaging is on) so the app doesn't render dead UI for a disabled feature — cross-check against MV-SET-020's hard-off behavior.

#### MV-API-019 — Admin: welcome dismiss
- **Edition:** Free
- **Who:** Logged-in (`logged_in_permissions_check`). Confirmed: this is ANY authenticated user, not scoped to admin-area users — the code's own comment says "Any authenticated user — welcome dismiss is per-user state," so a regular member calling this endpoint is just as valid as an admin.
- **Where:** `POST /mvs/v1/admin/welcome/dismiss`
- **Setup:** A fresh install showing a welcome banner/notice.
- **Steps:** 1. Dismiss the welcome element via this endpoint. 2. Reload the relevant admin screen and confirm it stays dismissed (persisted, not session-only).
- **Expected:** A small persisted user/site preference flip.
- **UX expectation:** Dismissal should be durable across sessions/browsers for the same admin, not reset on next login.
- **Settings that change it:** None (a one-off UI-state action).
- **Edge cases:** Calling it twice should be a harmless no-op.

#### MV-API-020 — AI usage
- **Edition:** Free
- **Who:** Settings managers (`manage_options` or `manage_mvs_settings`), the same people who see the AI Usage panel on MediaVerse > Stats. A moderator without those gets 403.
- **Where:** `GET /mvs/v1/ai/usage`
- **Setup:** Some AI calls already made this month.
- **Steps:** As an admin-capable caller, fetch usage — confirm it matches the numbers shown on MV-ADM-017's AI Usage panel exactly (same underlying data, two surfaces).
- **Expected:** Calls, successes, failures, cost, and budget remaining.
- **UX expectation:** The API and the admin-screen numbers must never disagree — if they do, one of the two is reading stale/cached data.
- **Settings that change it:** MV-SET-032 (budget), MV-SET-030/031 (what generates the usage in the first place).
- **Edge cases:** A non-privileged caller attempting this should be refused, not shown site-wide spend data.

#### MV-API-021 — Feed
- **Edition:** Free
- **Who:** Public read (`__return_true`), privacy-filtered per item like MV-API-001.
- **Where:** `GET /mvs/v1/feed`
- **Setup:** Media/activity from multiple members, mixed privacy, a follow graph.
- **Steps:** 1. Logged out — confirm only public items appear. 2. As a member following some authors, request the feed with `scope=following` — confirmed algorithm: there is no automatic blending or ranking. `scope` is an explicit request parameter (`public` / `following` / `user`) the caller must set; within whichever scope is requested, results are ordered purely reverse-chronologically (newest first) — never a "followed-first" weighting mixed into a default feed. An app wanting a followed-authors feed must explicitly request `scope=following`.
- **Expected:** This is a read surface an app can use for its home/explore feed without a separate custom query.
- **UX expectation:** Must exclude blocked-pair content per MV-API-014's block rules — a feed is exactly the kind of surface a block gap would slip through if not applied consistently.
- **Settings that change it:** MV-SET-010/011/014.
- **Edge cases:** Private/DM uploads must never appear in the feed for anyone other than the parties involved (CAPABILITIES.md confirms these deliberately write no activity row at all).

#### MV-API-022 — Serve (signed media delivery)
- **Edition:** Free
- **Who:** Anyone holding a valid signed URL (credential travels WITH the request, so this route is exempt from the cookie-session-based Members Only gate, MV-SET-014, and from the write-focused `RestGate`).
- **Where:** `GET /mvs/v1/serve`
- **Setup:** A private media item and its current signed URL (MV-API-007).
- **Steps:** 1. Fetch via a valid, unexpired signed URL — confirm the actual file bytes return (with content-negotiated WebP/AVIF per `Accept` header when available, MV-SET-009). 2. Fetch with an expired signature on a PRIVATE item — refused. 3. Fetch with an expired signature on a PUBLIC item — served anyway (the documented cache-friendliness exception, `mvs_serve_expired_public_urls` filterable to off). 4. Fetch an item that has since been rejected by moderation or whose author has since blocked the requester — confirm the SAME live permission re-check applies here as on every other read (2.5.0's fix: no more `'public'` short-circuit that could serve a since-moderated or since-blocked item straight from its signed URL).
- **Expected:** Every request re-checks `can_view()` live, even for items that were public when the URL was signed.
- **UX expectation:** The public-expired-URL exception must be the ONLY exception — a private item, or a since-rejected/blocked item, must never be servable merely because a signature is syntactically valid.
- **Settings that change it:** MV-SET-009 (`mvs_signed_url_ttl`), the `mvs_serve_expired_public_urls` filter.
- **Edge cases:** A tampered signature must fail `hash_equals()` outright, with no partial-trust fallback.

### Area: LAY

#### MV-LAY-001 — Global layout mode switcher
- **Edition:** Pro (control is Free's single "Layout" select; Pro's four skins are added to it via a filter, only present when Pro is active)
- **Who:** Site owner / anyone with `manage_options` (Settings page capability). Regular members cannot see or change it.
- **Where:** wp-admin > MediaVerse > Settings > Display > "Layout" field.
- **Setup:** Pro active. No licence requirement (license never gates features).
- **Steps:** 1. Open Settings > Display. 2. Open the "Layout" select — options are "Grid - square crops", "Justified rows - original proportions", "List - one row per item" (Free), plus "Instagram Feed", "Pinterest Masonry", "Flickr Justified", "Dribbble Shots" (Pro). 3. Pick a Pro skin, e.g. "Instagram Feed". 4. Click Save Settings. 5. Visit the Explore page and a member profile media tab.
- **Expected:** Saving writes `mvs_pro_feed_layout` (Pro skin) and leaves `mvs_thumbnail_style` untouched; picking a Free grid choice instead writes `mvs_thumbnail_style` and resets `mvs_pro_feed_layout` back to `grid`. Explore and every profile media tab immediately render in the new layout on next page load (no separate per-page setting needed). Default value (fresh install, option absent) is `grid` — Free's default grid, not Instagram.
- **UX expectation:** Select shows the currently-active skin pre-selected (resolved via a filter, so a Pro skin overrides Free's grid selection when active). Save follows WordPress's normal Settings API flow — a page reload with the standard "Settings saved" admin notice; no AJAX, no partial update. Nothing to disable/enable — the field is always present once Pro is active. Choosing an unrecognized/tampered value falls back silently to whatever was previously selected (sanitizer default), never a fatal or blank select.
- **Settings that change it:** "Layout" (`mvs_layout_choice` is the form's transport field only; state persists in `mvs_thumbnail_style` for grid choices or `mvs_pro_feed_layout` for Pro skins).
- **Edge cases:** Pro deactivated while a Pro skin was selected — the site quietly falls back to Free's grid (Pro's filter no longer runs), no error, and the stored `mvs_pro_feed_layout` value is left untouched in the DB so re-activating Pro restores the same skin. `mvs_active_layout` filter can force a layout by context (e.g. always Flickr on the archive) regardless of the saved option — verify this isn't accidentally left in a child theme/mu-plugin during testing.

#### MV-LAY-002 — Instagram layout on Explore
- **Edition:** Pro
- **Who:** Any visitor (public feed by default); logged-in members see follow/favorite state on cards.
- **Where:** The Explore page URL (site's configured `mvs_page_explore`), when Layout = "Instagram Feed".
- **Setup:** Layout set to Instagram. At least a few published media items for a non-empty feed.
- **Steps:** 1. Set Layout to "Instagram Feed". 2. Visit Explore. 3. Confirm vertical card feed renders (author avatar, full-width media, reaction/comment/share row, caption). 4. Scroll to trigger Load More.
- **Expected:** One card per upload (multi-image gallery uploads collapse to one card via cover-group exclusion, not one card per file). Load More button appends the next page via REST without a full reload; button becomes a "You're all caught up!" message when there are no more pages.
- **UX expectation:** Empty site (no media at all) shows "No media has been shared yet" / "Be the first to share something with the community!" with no crash. Load More shows a spinner while fetching and disables re-click until the request resolves (button + spinner pair, `.mvs-load-more-spinner`). Nothing duplicates on double-click of Load More (idempotent page cursor).
- **Settings that change it:** Items per page (`mvs_items_per_page`, Free) controls page size; "Layout" (this entry).
- **Edge cases:** Anonymous visitor on a site with `users_can_register` off sees the logged-out banner without a Register link, only Log In.

#### MV-LAY-003 — Pinterest layout on Explore
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Explore page, Layout = "Pinterest Masonry".
- **Setup:** Layout set to Pinterest.
- **Steps:** 1. Set Layout to Pinterest. 2. Visit Explore. 3. Confirm CSS-columns masonry with cards preserving original image proportions. 4. Scroll for infinite/Load More.
- **Expected:** Masonry columns fill without large gaps. Confirmed defect: the docs claim column count "is controlled by the Grid Columns display setting (2-4)," but Pinterest's column count is hardcoded in CSS breakpoints (4 columns desktop, 3 tablet-landscape, 2 tablet-portrait, 1 mobile) and never reads the Grid Columns setting at all — changing Grid Columns has zero effect on the Pinterest layout. Testers should expect 4/3/2/1 columns by viewport width regardless of what Grid Columns is set to.
- **UX expectation:** Same empty/search/tag empty states as every other layout (shared partial) — a genuinely empty site never shows a blank white area, always the "No media has been shared yet" hero.
- **Settings that change it:** "Layout" (this entry); items per page.
- **Edge cases:** Very tall images should not create an oversized single column that pushes the layout off-balance — verify visually at 1440px.

#### MV-LAY-004 — Flickr layout on Explore
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Explore page, Layout = "Flickr Justified".
- **Setup:** Layout set to Flickr.
- **Steps:** 1. Set Layout to Flickr. 2. Visit Explore. 3. Confirm justified rows (images resized to fill full row width at a consistent row height, no ragged/blank row ends). 4. Click a tile.
- **Expected:** Clicking opens the shared lightbox. Confirmed defect: the docs claim "the lightbox updates: Flickr mode shows EXIF camera data in the sidebar," but no EXIF reading or display feature exists anywhere in the plugin — the shared lightbox component is identical regardless of which layout is active (no per-layout branching, no camera-data sidebar, no swipe-carousel special case for Instagram either, despite the same doc sentence claiming that too). Testers should expect the plain shared lightbox with no EXIF data on any layout, including Flickr.
- **UX expectation:** Same shared empty/search-empty/tag-not-found states as Instagram/Pinterest/Dribbble (one partial, one set of strings) — a QA pass that finds different empty-state wording on Flickr vs the others is a real regression, not a design choice.
- **Settings that change it:** "Layout" (this entry); items per page.
- **Edge cases:** A row with only one very wide or very narrow image at the end of the feed should not render a broken/blank trailing row.

#### MV-LAY-005 — Dribbble layout on Explore
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Explore page, Layout = "Dribbble Shots".
- **Setup:** Layout set to Dribbble.
- **Steps:** 1. Set Layout to Dribbble. 2. Visit Explore. 3. Confirm shot-grid cards with hover overlay title, author row, and like/view stat icons. 4. Hover a card.
- **Expected:** Hover reveals the title overlay; footer always shows author avatar/name plus a heart (likes) icon and, only when views > 0, an eye (views) icon — views icon is conditionally hidden, not shown as "0".
- **UX expectation:** Stat icons render at the styled 14x14px size (dribbble.css), never at browser-default SVG viewBox size — this is exactly the regression Coding Rule 4 exists to prevent (see MV-LAY-016). Screen-reader text for stats uses full words ("%s like"/"%s likes", "%s view"/"%s views"), not just the raw number.
- **Settings that change it:** "Layout" (this entry); items per page.
- **Edge cases:** Animated GIFs. Confirmed defect: the docs claim "Animated GIFs play on hover," but there is no GIF-specific hover-to-play code anywhere — the card markup uses the same static thumbnail helper as every other media type, and the Dribbble JS store is explicitly CSS-only with no such behavior. Testers should expect a GIF to render as a static thumbnail on hover, the same as any other image.

#### MV-LAY-006 — Instagram layout on a member profile
- **Edition:** Pro
- **Who:** Any visitor viewing `/media/@username/`; the profile owner sees additional inline-edit affordances.
- **Where:** A member's profile media tab URL, Layout = Instagram.
- **Setup:** Layout = Instagram; target member has published media.
- **Steps:** 1. Set Layout to Instagram. 2. Visit a member's profile. 3. Confirm profile header (avatar, name, bio, media count, follower/following counts, Follow button, DM button gated by messaging privacy) above the same card feed as Explore.
- **Expected:** Media count shown is `count_visible_by_author()` — what THIS viewer can actually open, not the author's total (so a stranger never sees a count that includes private/members-only items they can't click into).
- **UX expectation:** Confirmed: the Follow button toggles state without a page reload. It flips the label optimistically first, then sends a fetch (`POST`/`DELETE` to `users/{id}/follow`) in the background, and reverts the label if the request fails — no page reload either way. DM/message entry point is hidden entirely (not shown-then-blocked) when messaging privacy disallows it for this viewer — no dead button.
- **Settings that change it:** "Layout" (this entry).
- **Edge cases:** Viewing your own profile shows no Follow/DM button (is_own short-circuits both).

#### MV-LAY-007 — Pinterest layout on a member profile
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Member profile URL, Layout = Pinterest.
- **Setup:** Layout = Pinterest; target member has published media.
- **Steps:** 1. Set Layout to Pinterest. 2. Visit a member profile. 3. Confirm masonry feed under the profile header, filtered to this author only.
- **Expected:** Feed is scoped to `author_id` = the profile's user, same query engine as Explore, same empty/search states if the profile owner has 0 items.
- **UX expectation:** An author with zero visible items to THIS viewer (e.g. everything private) shows the plain empty hero, not an error and not a feed showing items the viewer cannot open.
- **Settings that change it:** "Layout" (this entry).
- **Edge cases:** none beyond the shared ones already covered under Explore.

#### MV-LAY-008 — Flickr layout on a member profile
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Member profile URL, Layout = Flickr.
- **Setup:** Layout = Flickr; target member has published media.
- **Steps:** 1. Set Layout to Flickr. 2. Visit a member profile. 3. Confirmed defect: the docs claim the profile shows a "filmstrip-style contact sheet view," but the profile page renders the exact same justified-row gallery component used on Explore (same CSS classes, same shared grid partial), just scoped to one author — there is no distinct filmstrip/contact-sheet treatment anywhere in the templates. Testers should expect the same justified-row look as Explore, not a different filmstrip style.
- **Expected:** Justified-row feed scoped to the profile's author, same as Explore.
- **UX expectation:** Same shared empty-state and stat-icon consistency as the Explore variant.
- **Settings that change it:** "Layout" (this entry).
- **Edge cases:** none beyond the shared ones already covered under Explore.

#### MV-LAY-009 — Dribbble layout on a member profile
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Member profile URL, Layout = Dribbble.
- **Setup:** Layout = Dribbble; target member has published media.
- **Steps:** 1. Set Layout to Dribbble. 2. Visit a member profile. 3. Confirmed defect: the docs claim "featured work" appears at the top before the full grid, but there is no featured-work section anywhere — the profile page renders the same shot-grid gallery used on Explore (same shared grid partial), scoped to one author, with no split between a "featured" area and "the rest." Testers should expect one continuous grid, not a featured section followed by a full grid.
- **Expected:** Shot-grid feed scoped to the profile's author.
- **UX expectation:** Same shared empty-state and stat-icon consistency as the Explore variant.
- **Settings that change it:** "Layout" (this entry).
- **Edge cases:** none beyond the shared ones already covered under Explore.

#### MV-LAY-010 — Search (?q=) parity across all four layouts
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Explore page under each of the four layouts; search field submits `?q=<term>` (never `s=`, which is WordPress's own site search).
- **Setup:** Each layout active in turn; media titled/tagged with a known term.
- **Steps:** 1. Under each layout, type a term into the search field and submit. 2. Try a term with zero matches. 3. Toggle the "Media"/"People" search-mode tabs.
- **Expected:** All four layouts read/write the identical `q` parameter through one shared resolver — a search that works on Instagram must behave identically on Pinterest/Flickr/Dribbble (same result set, same param). Zero-result search shows: "No results for "<term>"" plus "Try a different keyword or browse by popular tag:" with a Browse-all-media button and up to 8 popular-tag chips.
- **UX expectation:** The "People" search mode populates results client-side into a results container that is hidden by default (`style="display:none"`) — verify it actually shows/hides on toggle and doesn't leave stale results visible after switching back to Media mode. Search field is pre-filled with the current term on reload (round-trips through the URL, not lost on refresh).
- **Settings that change it:** none — search behavior is not configurable, only layout choice changes card appearance.
- **Edge cases:** Cross-layout regression history: this exact parity broke twice before 2.6.0 (Instagram briefly missing the shared search/tag partial entirely) — worth an explicit re-check any time a layout template is touched.

#### MV-LAY-011 — Sort (Newest / Oldest / Most viewed) parity across all four layouts
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Explore page toolbar, all four layouts (`?sort=` / `?order=`).
- **Setup:** Each layout active in turn; several items with different creation dates and some view activity.
- **Steps:** 1. Under each layout, use the "Sort by" dropdown: Newest, Oldest, Most viewed. 2. Submit and confirm order changes. 3. Reload with the resulting URL.
- **Expected:** Same three options, same labels, same default (Newest = `created_at` DESC) on every layout. "Oldest" is the one-select spelling of `created_at` ASC; a legacy `?sort=title` link still works even though "Title" isn't in the dropdown.
- **UX expectation:** Toolbar shows an item count ("%s item"/"%s items", pluralized) beside the sort control, and disappears entirely when there are 0 items (nothing to sort) rather than showing an empty/disabled control. Submitting is a normal GET form ("Apply" button) — full page reload, not AJAX; the current search/tag/category selections are carried as hidden fields so sorting never drops an active filter.
- **Settings that change it:** none.
- **Edge cases:** Confirm the toolbar's hidden fields correctly preserve an active tag AND an active search term simultaneously when sort is changed — a common place for one filter to get silently dropped.

#### MV-LAY-012 — Tag/category chip filtering parity across all four layouts
- **Edition:** Pro
- **Who:** Any visitor.
- **Where:** Explore page tag cloud / `?mvs_tag=` and `?mvs_category=`, all four layouts, plus `/media-tag/*` and `/media-category/*` archives.
- **Setup:** Each layout active in turn; at least one tag/category with tagged media.
- **Steps:** 1. Click a tag chip under each layout. 2. Try a nonexistent tag slug directly in the URL. 3. Visit a `/media-tag/*` archive directly (not via Explore) under a Pro layout.
- **Expected:** Clicking a real tag filters the feed and the page heading reads "Tag: <name>" (or "Category: <name>"). An unknown/invalid slug shows the empty state "Tag "<slug>" not found" / "Category "<slug>" not found" with a Browse-all-media button and popular-tag chips — never a silently-unfiltered full feed (this was a real, fixed bug: Instagram once dropped the tag filter server-side AND client-side).
- **UX expectation:** The `/media-tag/*` and `/media-category/*` taxonomy archives must load the ACTIVE layout's own CSS/JS, not just the plain grid's — a tag archive rendering with layout markup but no layout stylesheet is a distinct, previously-real regression (separate from the block-level Coding Rule 4 case in MV-LAY-016).
- **Settings that change it:** "Layout" (this entry).
- **Edge cases:** Load More on a tag-filtered feed must carry the tag forward to page 2+ (`data-tag` attribute on the Load More button) — check this specifically after changing layout, since this exact regression previously shipped only on Instagram while the other three layouts already carried it.

#### MV-LAY-013 — The four feed blocks in the block editor
- **Edition:** Pro
- **Who:** Any editor-capable user (post/page editor).
- **Where:** Block inserter — "Instagram Feed", "Flickr Feed", "Pinterest Feed", "Dribbble Feed" (category: MediaVerse Pro).
- **Setup:** Pro active; at least a few media items published.
- **Steps:** 1. Insert each block into a post/page. 2. Set `perPage` and `scope` (public / followers / self) in the block sidebar. 3. Save and view the front end. 4. Insert the same block twice on one page with different `scope` values.
- **Expected:** Each block renders that specific layout's feed independent of the site-wide "Layout" setting — e.g. a Dribbble Feed block works on a site whose global Layout is Instagram. `scope` and `perPage` block attributes actually change the rendered query (per-block override of `render_feed()` args), not just the site-wide default.
- **UX expectation:** In the editor, the block should show a live/representative preview, not a placeholder box (zero-friction-admin-first expectation) — verify the editor preview genuinely reflects real data, not a static mock. `align: wide/full` support should visibly stretch the block beyond the content column when selected.
- **Settings that change it:** none of the Display settings apply to a block instance — the block's own attributes are authoritative.
- **Edge cases:** `scope: "self"` on a page viewed by a logged-out visitor — must show the logged-out-appropriate empty/prompt state, not attempt to resolve "self" to user ID 0 and silently show nothing unexplained.

#### MV-LAY-014 — Mobile / 390px behavior across all four layouts
- **Edition:** Pro
- **Who:** Any visitor on a phone-width viewport.
- **Where:** Explore + profile, all four layouts, at 390px width.
- **Setup:** Each layout active in turn.
- **Steps:** 1. Resize/emulate to 390px. 2. Load Explore under each layout. 3. Check search bar, tag chips, sort toolbar, and card grid for horizontal overflow. 4. Load a profile under at least two layouts.
- **Expected:** Instagram collapses to a single column; Pinterest/Flickr/Dribbble masonry/justified/grid collapse to 1-2 columns with no horizontal scrollbar and no clipped text.
- **UX expectation:** Touch targets (Load More button, tag chips, sort submit, story avatars) meet the plugin's touch-floor token, not a smaller ad-hoc size. The logged-out banner (when present) must not overlap or push the feed off-screen at this width.
- **Settings that change it:** "Layout" (this entry).
- **Edge cases:** Long tag/category names in chips must wrap or truncate, never force the tag row to overflow the viewport width.

#### MV-LAY-015 — Cross-layout feature-parity audit
- **Edition:** Pro
- **Who:** QA (not a member-facing flow — a verification pass across MV-LAY-002 through 012).
- **Where:** Explore under each of the four layouts, side by side.
- **Setup:** Same dataset, same viewer, cycling the "Layout" setting through instagram / pinterest / flickr / dribbble.
- **Steps:** 1. For each layout, note whether search, sort, tag chips, pagination/Load More, empty states, and stat display are present and behave identically. 2. Flag any control present on one layout but missing/different on another.
- **Expected:** By design, all four layouts (via the shared `AbstractConnectorFeedLayout` base for Pinterest/Flickr/Dribbble, and a parallel-but-synced implementation for Instagram) share the same search resolver, the same `explore-filters.php` partial, the same sort toolbar, and the same empty-state partial. A control present in one layout and missing in another is a defect, not an intentional design difference — this exact class of bug (tag filtering present on 3 layouts, silently missing on the 4th) has shipped and been fixed before 2.6.0.
- **UX expectation:** No layout should show extra or fewer toolbar controls than the others for the same feed state (e.g. all empty, all searched, all tag-filtered). Wording (empty-state text, sort labels, search placeholder) must be byte-identical across layouts since it comes from one shared string, not four copies.
- **Settings that change it:** "Layout" (this entry, cycled through each value for the audit).
- **Edge cases:** Re-run this audit specifically whenever any single layout's feed-body template is edited — the historical failure mode is exactly "fixed 3 of 4, missed 1."

#### MV-LAY-016 — Block render.php must enqueue its layout's assets (regression class)
- **Edition:** Pro
- **Who:** QA / developer verification, not member-facing.
- **Where:** Any page/post containing one of the 4 feed blocks, with the site-wide "Layout" setting on a DIFFERENT layout than the block (or on `grid`).
- **Setup:** Site-wide Layout = grid (or any layout other than the block's). Insert, say, the Dribbble Feed block on an ordinary page.
- **Steps:** 1. Load the page containing the block. 2. Open DevTools Network tab. 3. Confirm the layout's own CSS (`mvs-layout-dribbble`) and JS module are present in the loaded assets, not just Free's generic frontend CSS.
- **Expected:** The block's own `render.php` always instantiates its Layout class AND calls `enqueue_assets()` in the same file — enforced by a CI rule specifically because it shipped broken once (stat icon SVGs rendered at default viewBox size instead of the styled 14x14px, since the site-wide LayoutManager path — the only thing that used to enqueue those assets — never runs on an ordinary block/shortcode page).
- **UX expectation:** Visually, every stat icon, badge and layout-specific control on a feed block embedded on a random page must look IDENTICAL to the same layout on the Explore page. A block that "looks unstyled" (raw/oversized SVGs, missing hover states) even though the feed itself renders data is exactly this regression, not a content problem.
- **Settings that change it:** none — this is independent of the site-wide "Layout" setting by design (a block is a per-instance override).
- **Edge cases:** Double-enqueue is expected to be harmless (`wp_enqueue_*` dedupes by handle) if both the site-wide layout and a block use the same layout on one page — confirm no visual duplication or console warning results.

### Area: CMP

#### MV-CMP-001 — Compete hub landing at /compete/
- Edition: Pro
- Who: any visitor may load the page. Logged-in members see "My Activity"/"My Results" and points balance; logged-out see "Create an account" prompts instead of action buttons.
- Where: `/compete/` (query var `mvs_compete_page=1`), rendered by `templates/compete-hub.php` wrapping `templates/compete-hub-body.php` via `Frontend\CompeteHubRenderer`.
- Setup: `mvs_competitions_enabled` = 1, and at least one of `mvs_battles_enabled`/`mvs_challenges_enabled`/`mvs_tournaments_enabled` = 1. No license required.
- Steps: 1) Visit `/compete/` logged out — verify layout and CTAs. 2) Log in, revisit — verify header changes (points chip if the free WB Gamification plugin is active, "Back to My Media" link). 3) With no active competitions anywhere, verify the "Ready to compete?" first-run guided view appears instead of the feed. 4) Create/accept a battle, revisit — verify the first-run view is replaced by the live feed and "My Activity" shows the battle card.
- Expected: Header title "Compete", tagline "Enter challenges, join tournaments, and battle fellow photographers." Loading state shows "Loading competitions…"; a failed fetch shows "Could not load competitions. Please check your connection and try again." with a Retry button.
- UX expectation: Points chip only appears if the free WB Gamification plugin is active and the member is logged in — no "0 points" ghost chip when the backend is absent. First-run "Ready to compete?" state and the live feed state must never both partially render (one or the other, never a blended half-state). Master toggle OFF must produce a real WP 404 (verified via `status_header(404)`), not a blank plugin-branded page.
- Settings that change it: `mvs_competitions_enabled` (master, off by default) — off means no rewrite rule, real 404 on `/compete/`. `mvs_battles_enabled`/`mvs_challenges_enabled`/`mvs_tournaments_enabled` each show/hide their respective section and their first-run "mode" card. `mvs_page_dashboard` (option, the "My Media" page id) drives the "Back to My Media" link target, falling back to `/my-media/` if unset.
- Edge cases: master toggle OFF → real WP 404, not a blank plugin page. All three sub-toggles OFF while master is ON → hub still 200s but every section/card is hidden — verify it doesn't render an empty husk with no explanation. Unlicensed Pro (license lapsed) → hub fully functional, no gating (competitions are not license-gated). Mobile 390px → single-column stack. Community set to Members-only/private → page is gated per `mvs_community_gated_page` filter and should turn guests away like Explore/Profile do.

#### MV-CMP-002 — Compete hub is not reachable from site navigation by default
- Edition: Pro
- Who: site owner (setup task), all visitors (discoverability impact).
- Where: Appearance → Menus (WordPress core), or a code-level filter `mvs_pro_inject_compete_nav`.
- Setup: Competitions enabled with at least one sub-feature on.
- Steps: 1) Enable competitions + battles on a fresh site. 2) Do not touch the nav menu. 3) Browse the site as a member looking for a way into Compete.
- Expected: There is no automatic "Compete" link anywhere — not in the primary nav, not in the My Media dashboard rail (nav injection was dropped in 1.6.0 as bad practice; the dashboard rail Compete tab was removed in 2.4.2 after it caused a blank-panel bug). The only ways in are a manually-added `/compete/` menu item, a direct URL, the `mvs/pro-compete-hub` block placed on a page, or the code filter `mvs_pro_inject_compete_nav` returning true.
- UX expectation: A site owner who enables competitions and expects members to just find it will be surprised — nothing in the UI signals this gap, no onboarding notice, no "add this to your menu" prompt. Worth flagging to whoever owns onboarding copy.
- Settings that change it: `mvs_pro_inject_compete_nav` filter (default false, no UI checkbox — code-only).
- Edge cases: this is a genuine "shows inactive when configured" risk if a site owner enables competitions and assumes members can find it.

#### MV-CMP-003 — Active Challenge card
- Edition: Pro
- Who: logged-in members see "Enter Challenge"/"View Entries"; logged-out see "Create an account… to enter challenges."
- Where: Compete hub, Section 2 ("Active Challenge"), only rendered when `mvs_challenges_on` is true.
- Setup: `mvs_competitions_enabled` + `mvs_challenges_enabled` = 1; at least one active challenge for the non-empty state.
- Steps: 1) With no active challenge, load hub — verify empty state. 2) Create/activate a challenge, reload — verify title, theme (only shown if it differs from the title), entry count, time-remaining countdown, progress bar, and up to N thumbnail previews render.
- Expected: Empty state: megaphone icon, "No active challenge right now. Check back soon!" with a "Browse Past Challenges" button to `/media/challenges/`. Progress bar has `role="progressbar"` with `aria-valuenow`/`aria-valuetext` bound reactively.
- UX expectation: A challenge with zero entries must render the progress bar/thumbnail strip without erroring or showing a broken 0/0 state. Anon CTA must be the "create an account" message, never a silent link that goes nowhere. Theme line hidden (not duplicated) when it equals the title.
- Settings that change it: `mvs_challenges_enabled` — off hides the entire section (not just disables it).
- Edge cases: challenge with zero entries; challenge whose theme equals its title; anon user.

#### MV-CMP-004 — Open Tournaments card
- Edition: Pro
- Who: logged-in members can Register; logged-out see "Create an account… to register."
- Where: Compete hub, Section 3 ("Open Tournaments"), only rendered when `mvs_tournaments_on` is true.
- Setup: `mvs_competitions_enabled` + `mvs_tournaments_enabled` = 1; at least one tournament in registration for the non-empty state.
- Steps: 1) Empty state check. 2) Add a tournament with open spots — verify bracket size, spots-remaining count (only shown when `spots_remaining` is truthy), Register button only visible when `can_register` is true, "View Bracket" always visible.
- Expected: Empty state: bar-chart icon, "No open tournaments at the moment." with "View All Tournaments" link to `/media/tournaments/`.
- UX expectation: A full tournament (spots_remaining = 0) must HIDE the Register button, never show it disabled-and-clickable. A tournament already started (can_register false) shows only "View Bracket," no dangling Register button.
- Settings that change it: `mvs_tournaments_enabled` — off hides the section entirely.
- Edge cases: tournament full; tournament already started; anon user.

#### MV-CMP-005 — Battle Arena card and Recent Results matchups
- Edition: Pro
- Who: logged-in "Challenge Someone" CTA; logged-out "Create an account… to start battling."
- Where: Compete hub, Section 4 ("Battle Arena"), rendered only when `mvs_battles_on` is true. Links to `/media/battles/`.
- Setup: `mvs_competitions_enabled` + `mvs_battles_enabled` = 1. At least one completed battle to see "Recent Results".
- Steps: 1) With zero completed battles, verify the "Recent Results" sub-block is hidden entirely (not an empty table). 2) Complete a battle, reload — verify the matchup card shows both photos side by side, winner gets a green checkmark badge and a `--winner` class on their side.
- Expected: Recent Results shows up to 5 completed battles.
- UX expectation: A tie (including 0-0) resolves to the challenger under the plugin's tie rule — the winner badge must reflect that resolution, not display an ambiguous "no winner" state. A battle against a since-deleted user needs an avatar/name fallback, not a broken image.
- Settings that change it: `mvs_battles_enabled` — off hides the whole section.
- Edge cases: tie handling; deleted-user battle; mobile 390px.

#### MV-CMP-006 — Hub REST summary endpoint
- Edition: Pro
- Who: public, no authentication required (deliberate — drives unauthenticated discovery widgets).
- Where: `GET /wp-json/mvs-pro/v1/competitions/active-summary`.
- Setup: `mvs_competitions_enabled` = 1. Confirmed: this route is always registered on `rest_api_init` regardless of the toggle (it's the discovery endpoint used by public banners/widgets); each section of the response (active_challenge/open_tournaments/recent_battles) is independently gated inside the handler, returning null/empty for any competition type that's off rather than 404ing the whole route.
- Steps: 1) Call the endpoint logged out. 2) Call it with an active challenge, open tournaments, and completed battles present. 3) Call it again within the cache TTL after a write (e.g. resolve a battle) — verify the cache is busted immediately rather than waiting out the TTL.
- Expected: JSON with `active_challenge` (object or null), `open_tournaments` (array), `recent_battles` (last 5 completed). Response should update within the same request cycle as a triggering write (cache-version bump), not just after a fixed TTL.
- UX expectation: N/A visually (pure API) — but the cache-busting-on-write behavior must actually hold, or the hub cards will show stale counts right after an admin/member action.
- Settings that change it: none directly — reflects whichever sub-features are on/off implicitly through empty data.
- Edge cases: master switch off → route stays registered but every section returns null/empty (see above; not a 404). Community set to private: confirmed NOT a leak — the whole `mvs-pro/v1` namespace, including this route, is covered by the site-wide private-community REST gate (Pro adds `/mvs-pro/v1/` to the gate's prefix list specifically so public reads like this one don't leak); a signed-out visitor on a members-only site gets refused before this endpoint's own code ever runs.

#### MV-CMP-007 — First-run guided view (all competitions quiet)
- Edition: Pro
- Who: all visitors.
- Where: Compete hub, `.mvs-compete-firstrun` block, shown when `state.isEmpty` is true.
- Setup: Competitions on, but nothing currently active in any enabled sub-feature.
- Steps: 1) Enable only Battles (challenges/tournaments off) with zero battles anywhere. 2) Load hub — verify only the Battle "mode" card is listed under "Here's how you can play," and the single CTA is "Start a photo battle" (no secondary "Browse challenges"/"View tournaments" buttons since those are off).
- Expected: Heading "Ready to compete?", lede "Put your photography up against the community. Here's how you can play." CTA button hierarchy: primary button is whichever of Battle/Challenge/Tournament is enabled first; the rest render as secondary.
- UX expectation: Only enabled modes ever appear — no dead links to a disabled feature. If all three sub-toggles are off while master is on, verify the firstrun view doesn't render zero mode-cards with a dangling "Here's how you can play" and no card underneath.
- Settings that change it: each of `mvs_battles_enabled`/`mvs_challenges_enabled`/`mvs_tournaments_enabled` independently controls one mode block + one CTA button.
- Edge cases: all three sub-toggles off while master is on.

#### MV-CMP-008 — Dashboard tab-switching regression guard (interaction bug class)
- Edition: Pro (interacts with Free's My Media dashboard)
- Who: any logged-in member using My Media.
- Where: `/my-media/` dashboard, any Free/Pro tab (Documents, Compete-adjacent panels, etc.).
- Setup: Both plugins active, at least one Pro dashboard-registered section via `mvs_dashboard_sections` (e.g. Documents) alongside Free's native tabs.
- Steps: 1) Load My Media. 2) Switch between every tab in sequence, including the Documents tab that Pro registers through `mvs_dashboard_sections`. 3) Confirm no tab ever renders a blank panel and no other tab's content leaks through.
- Expected: The historical bug was a JS `switchTab` that name-checked for `'documents'` literally and intercepted Pro's Compete registration, rendering a blank panel. Compete no longer registers through this rail (removed in 2.4.2, replaced by the standalone `/compete/` hub — see MV-CMP-002), so this specific collision cannot recur for Compete, but any NEW section registered via `mvs_dashboard_sections` (Documents today) must be regression-tested the same way.
- UX expectation: Rapid double-click between tabs and browser back/forward while a tab is loading must not corrupt which panel is shown. A section whose capability callback denies the current user must be omitted from the rail entirely, never shown as a broken empty tab.
- Settings that change it: none — this is a wiring/regression check, not a toggle.
- Edge cases: rapid double-click between tabs; browser back/forward mid-load; a capability-denied section.

#### MV-CMP-009 — Compete hub Gutenberg block (`mvs/pro-compete-hub`)
- Edition: Pro
- Who: editors/admins placing the block; frontend visitors viewing the page it's on.
- Where: Block editor, category "wpmediaverse-pro", `src/blocks/pro-compete-hub/`.
- Setup: none required to insert the block; competitions must be on for it to show real content.
- Steps: 1) Insert the block on any page/post as an admin. 2) Publish and view as a logged-out visitor, then as a member. 3) Disable `mvs_competitions_enabled` and reload the page containing the block.
- Expected: Block renders the same `CompeteHubRenderer::render()` output as the standalone hub page, wrapped in `get_block_wrapper_attributes()` plus visibility classes. When gated off, rendering returns an empty string for ordinary visitors and an admin-only notice for users who can `manage_options`.
- UX expectation: The off-state admin notice must be visible only to admins, never to a logged-out or regular-member visitor (who must see nothing at all, not a broken shell). Server-rendered per request — must not cache stale competition state on an AMP/cached page.
- Settings that change it: `mvs_competitions_enabled` and each sub-toggle — same gating as the page.
- Edge cases: block placed inside a private/members-only area; block on a cached page; Site Editor/FSE template placement.

#### MV-CMP-010 — Compete pages respect the Members-only / private-community gate
- Edition: Pro
- Who: guests on a site configured as members-only.
- Where: `/compete/`, `/media/battles/`, `/media/challenges/`, `/media/tournaments/` — all four query vars checked in the `mvs_community_gated_page` filter.
- Setup: Free's "Members-only" community setting on, competitions on.
- Steps: 1) Turn the site private. 2) Log out. 3) Try to load each of the four Compete-family URLs directly.
- Expected: Each should be turned away the same way Explore/Profile are on a private community (login wall / redirect), because Pro's filter explicitly adds these four query vars to the gate.
- UX expectation: All four URLs must behave identically (same wall/redirect), not have one accidentally slip through unguarded.
- Settings that change it: the site's Members-only privacy setting (Free) combined with competitions being on.
- Edge cases: the REST summary endpoint (MV-CMP-006) is explicitly public by design and does NOT appear to be wired into this same gate — verify whether that's an intentional carve-out or a leak on a private community.

#### MV-CMP-011 — Points balance chip depends on the optional gamification backend
- Edition: Pro (chip), Free WB Gamification plugin (backend, optional)
- Who: logged-in members only.
- Where: Compete hub header.
- Setup: free "WB Gamification" plugin installed and active.
- Steps: 1) Load hub with WB Gamification inactive — verify no points chip at all (not a broken "0 points" chip). 2) Activate WB Gamification, reload — verify chip shows formatted balance and links to the WB Gamification hub page if configured, or degrades to a non-linked span with the same label if not.
- Expected: chip label always "<n> points"; aria-label/tooltip text: "<n> reward points. Earn them by competing and engaging; spend them on boosts. Select to open your rewards hub." when linked.
- UX expectation: Guest user never renders the chip. WB Gamification active but no hub page configured must render a plain span, not a dead link.
- Settings that change it: `wb_gam_hub_page_id` option (link target), `mvs_pro_compete_points_url` filter (owner can repoint the chip).
- Edge cases: WB Gamification active but no hub page configured; points balance of 0.

### Area: BAT

#### MV-BAT-001 — Create a battle challenge (choose a specific opponent)
- Edition: Pro
- Who: any logged-in member (no role restriction beyond being logged in). Battles are strictly 1v1 by chosen opponent_id — there is no "open challenge" mode.
- Where: `/media/battles/` "Challenge Someone" form, or `POST /wp-json/mvs-pro/v1/battles`.
- Setup: `mvs_competitions_enabled` + `mvs_battles_enabled` = 1.
- Steps: 1) Open Battles page, click "Challenge Someone." 2) Search opponent by username (typeahead). 3) Optionally set a theme (free text, sanitized). 4) Submit.
- Expected: Success toast "Challenge sent to %s!" On failure: "Failed to create battle." The battle is created in `pending` status; both a challenger and opponent entry row and one match row are created atomically.
- UX expectation: The "Challenge Someone" block does not render at all for logged-out visitors — no dead form. Submit should disable while in flight so a double-click can't fire two challenge requests.
- Settings that change it: none affect creation mechanics; `mvs_pro_battle_win_xp` (default 100) is snapshotted into the new battle's settings at creation time, so a later change to this option never retroactively re-prices an in-flight battle.
- Edge cases: challenging yourself → `mvs_battle_self` error, "You cannot challenge yourself." Challenging a non-existent user id → `mvs_battle_invalid_opponent`, 404. Challenging someone you already have a pending/accepted/active/voting battle with → `mvs_battle_exists`, 409, "An active battle already exists with this user."

#### MV-BAT-002 — Opponent receives and responds to a challenge
- Edition: Pro
- Who: the challenged member specifically — `accept()`/`decline()` both check `mvs_battle_not_opponent` if any other user tries.
- Where: `/media/battles/` Pending tab, or `POST /mvs-pro/v1/battles/{id}/accept` / `/decline`.
- Setup: an existing battle in `pending` status where the current user is the opponent.
- Steps: 1) Opponent opens Battles, Pending tab. 2) Accept or Decline.
- Expected: Accept → toast "Challenge accepted! Submit your photo now."; battle status moves to `accepted`/`active`. Decline → toast "Challenge declined."; battle status becomes `declined` (terminal, no XP penalty).
- UX expectation: Once accepted/declined, re-showing the same Accept/Decline buttons on a stale page load and clicking again must show a clean "already handled" message (`mvs_battle_not_pending`, 400), not a raw error or a duplicate action.
- Settings that change it: none.
- Edge cases: someone other than the opponent tries to accept/decline → 403. Battle already accepted/declined acted on again (double-click / two tabs) → clean "already handled" state. Challenger tries to accept/decline their own challenge → same 403.

#### MV-BAT-003 — Submit media entry for a battle
- Edition: Pro
- Who: either participant (challenger or opponent), while the battle is `accepted` or `active`.
- Where: Battles page "Submit Your Photo" section, or `POST /mvs-pro/v1/battles/{id}/submit`.
- Setup: an accepted/active battle; the submitter has at least one media item, or can upload a new one inline.
- Steps: 1) Open the battle. 2) Pick an existing photo from the media picker, or click "Upload a new photo." 3) Submit.
- Expected: Success toast "Photo submitted! Good luck!" On failure: "Submission failed." Once BOTH participants have submitted, the battle auto-advances to `voting`.
- UX expectation: Submit button and upload button are siblings, not nested, deliberately, so a member with an empty media library still has the upload path visible rather than a dead Submit button. The submitting player should see clear feedback that they're now waiting on their opponent.
- Settings that change it: `DEFAULT_SUBMIT_HOURS` (48h, code constant). Confirmed: there is no settings-page field for this anywhere in admin; the only way to change it is the `mvs_battle_submit_hours` developer filter.
- Edge cases: submitting after `submit_deadline` has passed → `mvs_battle_submit_expired`, 400. Submitting media that isn't yours → `mvs_battle_invalid_media`, 400. A non-participant trying to submit → 403.

#### MV-BAT-004 — Voting phase and casting a vote
- Edition: Pro
- Who: any logged-in member EXCEPT the two participants (403). Logged-out users can view but not vote.
- Where: Battles page Voting tab, or `POST /mvs-pro/v1/battles/{id}/vote`.
- Setup: a battle in `voting` status with both entries submitted.
- Steps: 1) Open a voting battle as a third-party member. 2) Click "Vote for this photo" on either side.
- Expected: Success toast "Vote recorded!" Vote button hides once voted, replaced by a "Voted" badge.
- UX expectation: Anon user gets no vote button at all — instead a "Log in to vote" link to the login page, never a clickable control that then 401s. Participants viewing their own battle should see voting UI disabled/hidden, not a control that 403s on click.
- Settings that change it: `DEFAULT_VOTE_HOURS` (48h constant) sets the voting deadline.
- Edge cases: participant tries to vote in their own battle → 403. Voting for a user id that isn't one of the two participants → 400. Voting after `vote_deadline` → 400. Voting on a battle not in `voting` status → 400.

#### MV-BAT-005 — Battle resolution and winner determination
- Edition: Pro
- Who: system (auto, via the 5-minute Action Scheduler tick) or an admin (manual "Resolve" in Battle Monitor).
- Where: `BattleService::resolve()`, admin action, or the automatic 5-minute tick.
- Setup: a battle in `voting` status.
- Steps: 1) Let the vote deadline pass with votes recorded (or none). 2) Wait for the next tick, or have an admin click "Resolve" in Battle Monitor.
- Expected: Winner = whichever side has `votes >= opponent's votes` — a tie is deliberately resolved in the CHALLENGER's favor, not a true tie state. Battle status becomes `completed`; `winner_id` is stored.
- UX expectation: A zero-vote (0-0) resolution to "challenger wins" should be visually distinguishable from a decisively-voted outcome if possible — otherwise it can look like a bug rather than the documented tie rule. Admin clicking "Resolve" on a battle NOT in voting status must show a clear explanatory error, not a silent redirect.
- Settings that change it: `mvs_pro_battle_win_xp` (snapshotted at creation, applied via the Gamification bridge — needs WB Gamification active; without it there's simply no points awarded, no error).
- Edge cases: zero votes cast by the deadline → still resolves to the challenger under the tie rule. A battle never accepted or never fully submitted by its deadline → `expired` status (no winner declared), a separate path from voting-deadline resolution.

#### MV-BAT-006 — Battle notifications (in-app + the one emailed event)
- Edition: Pro (events), Free (notification storage + optional email)
- Who: the two participants.
- Where: in-app notification bell (Free's NotificationService), plus email if the owner turned it on.
- Setup: none extra for in-app. For email: Free admin setting "Emails" section → "Photo battle invites" checkbox (option `mvs_email_battle_invite`, default OFF).
- Steps: 1) Challenge a member — verify they get an in-app "X challenged you to a photo battle" notification immediately. 2) With the owner's email toggle ON, verify the opponent also receives an email with subject "You have a photo battle invite on <site>." With it OFF, verify no email is sent (in-app only). 3) Resolve a battle — verify winner gets "You won a photo battle!" and loser gets "A photo battle you were in has ended" (both in-app only; no email exists for these two types).
- Expected: Email is only ever sent for `battle_invite`, never for `battle_won`/`battle_lost`, even with the email toggle on. Confirmed intentional and matches the docs: Free's own Emails Section documents exactly one battle email type ("Photo battle invites") and no win/loss email; won/lost are in-app notifications only, by design, not a gap.
- UX expectation: No email attempted for a recipient with no valid email address (must fail silently, not fatal).
- Settings that change it: `mvs_email_battle_invite` (Free general settings, default OFF); the member-level "email me about activity" master switch and per-email unsubscribe link (applies on top of the site-wide toggle).
- Edge cases: opponent equals challenger (blocked upstream at creation). A Free version too old to know these notification types: confirmed unreachable — Pro checks Free's version against its own minimum-required-Free-version constant at boot and refuses to initialize ANY of its code (including notification registration) if Free is too old, so this scenario cannot occur in practice.

#### MV-BAT-007 — Battles list and single-battle deep link
- Edition: Pro
- Who: everyone (list is public); vote/accept/submit actions still require login and participant checks.
- Where: `/media/battles/` (tabbed list: Voting/Active/Pending/Completed), and a single battle deep link via `mvs_battle_id` query var. `GET /mvs-pro/v1/battles` and `GET /mvs-pro/v1/battles/{id}`.
- Setup: `mvs_competitions_enabled` + `mvs_battles_enabled` = 1.
- Steps: 1) Load `/media/battles/` — verify it opens on the first tab that actually has battles, not always defaulting to an empty "Voting" tab. 2) Deep-link to a specific battle id — verify an "All battles" back-button appears (hidden otherwise) and returns to the tab the deep link's battle belongs to.
- Expected: pagination via `per_page`/`page` REST params (default 20/page 1); total count header carries the total for admin-table-style pagination.
- UX expectation: Deep link to a nonexistent battle id must show "This battle is not available." rather than a blank page.
- Settings that change it: none beyond the master/sub toggles.
- Edge cases: deep link to a battle id that doesn't exist → 404, clean message. Deep link to a battle the viewer is a pure spectator on — still viewable, vote button shown if voting is open and logged in.

#### MV-BAT-008 — Battle Monitor admin: moderate, resolve, cancel, delete
- Edition: Pro
- Who: users with `manage_mvs_settings` capability — everyone else gets `wp_die` "You do not have permission to access this page."
- Where: wp-admin → MediaVerse → Photo Battles (`admin.php?page=mvs-battles`), submenu of the MediaVerse parent (since 2.6.0, sits right after Stats along with Competitions Dashboard).
- Setup: `manage_mvs_settings` capability; at least one battle to see the table.
- Steps: 1) Open Battle Monitor, verify tabs: Voting/Active/Pending/Completed/All, each with a live count. 2) Resolve a voting battle from the table — confirm dialog "Resolve this battle now and declare a winner?" 3) Cancel a pending/accepted/active battle — confirm dialog "Cancel this battle? This cannot be undone." 4) Delete any battle — confirm dialog "Permanently delete this battle and all its data?"
- Expected: success notices render a dismissible green notice; a concurrent/stale action (already resolved/cancelled by another admin, or the row was deleted) redirects with a specific red notice text rather than claiming false success — e.g. "That battle no longer exists — it may have already been deleted."
- UX expectation: Every one of Resolve/Cancel/Delete must show its own destructive confirm dialog with the exact wording above before firing — none should execute on a bare click. Cancel default focus should be the safe (non-destructive) option per the shared confirm-dialog pattern.
- Settings that change it: none — this page is pure moderation, no settings live here.
- Edge cases (multi-actor concurrency): two admin tabs open, one resolves a battle, the other clicks Resolve on the same now-`completed` battle → clean error notice, not a fatal or silent redirect. Cancel a battle that's already `completed`/`cancelled` → clean notice. Pagination at 50/page with 2000+ battles — real LIMIT/OFFSET, not a client-side slice.

#### MV-BAT-009 — Battle expiry (unaccepted or unsubmitted battles)
- Edition: Pro
- Who: system only (no user action).
- Where: `BattleService::resolve_expired()`, driven by the 5-minute tick.
- Setup: `mvs_competitions_enabled` + `mvs_battles_enabled` = 1; a battle whose `submit_deadline` has passed while still `pending`/`accepted`/`active` (never reached voting).
- Steps: 1) Create a battle, don't accept it, wait past the 48-hour submit deadline (or backdate for QA). 2) Wait for the next tick.
- Expected: status becomes `expired` (a TERMINAL status alongside `completed`/`declined` — added specifically because an earlier version made expired battles vanish from every list surface).
- UX expectation: An expired battle must show clearly as "Expired" in every list it would otherwise have appeared in, not silently disappear leaving participants wondering what happened.
- Settings that change it: none owner-facing; deadline length is the submit/vote hour constants (48h each).
- Edge cases: a battle stuck in `voting` past its `vote_deadline` with zero votes cast is resolved by the vote-count resolver (challenger wins the 0-0 tie) rather than marked `expired` — the two code paths (never-submitted vs never-voted) produce two different outcomes users would expect.

#### MV-BAT-010 — Voting/entry cutoff enforcement matches display
- Edition: Pro
- Who: any participant/voter.
- Where: `/media/battles/` card countdown, REST vote/submit endpoints.
- Setup: a battle nearing its submit or vote deadline.
- Steps: 1) Watch a battle's countdown approach zero. 2) At/after the deadline, attempt to submit or vote via the UI.
- Expected: UI countdown and server-side deadline enforcement must agree — the button must disable/hide before or exactly when the server would reject the action, never leave a live-looking vote button that 400s on click.
- UX expectation: If clock skew causes a client-perceived "still time left" vs a server-expired deadline, the resulting error toast must clearly say the deadline passed, not a confusing generic "Vote failed."
- Settings that change it: none.
- Edge cases: client/server clock skew at the exact deadline boundary.

#### MV-BAT-011 — License-not-required for battles
- Edition: Pro
- Who: any site running Pro with an expired/never-activated license.
- Where: entire Battles feature end to end.
- Setup: Pro installed, license deliberately left inactive/expired, `mvs_competitions_enabled` + `mvs_battles_enabled` = 1.
- Steps: 1) Deactivate/let the Pro license lapse. 2) Repeat the full battle lifecycle: create, accept, submit, vote, resolve.
- Expected: everything works with zero restriction — battles are explicitly NOT the one license-gated feature (that's Documents writes only). No "upgrade to unlock" interstitial anywhere in this flow.
- UX expectation: No stray license nag banner should appear anywhere in the Battles UI.
- Settings that change it: none — license state has no effect here by design.
- Edge cases: none beyond confirming the negative holds structurally.

#### MV-BAT-012 — `mvs/pro-battle` block (single battle embed)
- Edition: Pro
- Who: editors placing it; all visitors viewing the page.
- Where: block editor, `src/blocks/pro-battle/`, attribute `battleId` (number, default 0).
- Setup: at least one existing battle to pick.
- Steps: 1) Insert the block with no battle chosen — as an admin, verify the notice "Pick a battle in the block sidebar to see it here."; as a non-admin visitor, verify it renders nothing (no notice, no broken shell). 2) Pick a real battle id — verify it renders the same single-battle view as the deep-linked page.
- Expected: `battleId <= 0` short-circuits before any query; feature-off (`mvs_battles_enabled` = 0) returns the shared admin-only notice for admins, empty string for everyone else.
- UX expectation: Editor-side and front-end empty/off states must both be clean, never a fatal or half-rendered block.
- Settings that change it: `mvs_battles_enabled`.
- Edge cases: `battleId` pointing at a battle that has since been deleted — must show graceful empty/not-found, not a PHP notice.

#### MV-BAT-013 — `mvs/pro-battles-active` block (all open battles)
- Edition: Pro
- Who: editors placing it; all visitors.
- Where: block editor, `src/blocks/pro-battles-active/`, no attributes.
- Setup: `mvs_battles_enabled` = 1 for real content.
- Steps: 1) Insert with zero active battles — verify a clean empty state, not a broken loop. 2) Add active battles — verify all render with photos, vote totals, and time remaining.
- Expected: same renderer path as the full Battles page's active tab, so vote actions wired through the block must work exactly like the standalone page.
- UX expectation: The block's vote interactions must produce the same toasts/behavior as the full page — no degraded experience just because it's embedded.
- Settings that change it: `mvs_battles_enabled`.
- Edge cases: block used on a page also containing the full `/media/battles/` content — verify no state collision between two Interactivity API instances on one page.

#### MV-BAT-014 — Mobile layout at 390px
- Edition: Pro
- Who: all mobile visitors.
- Where: `/media/battles/`, battle cards, Battle Monitor is desktop-admin-only.
- Setup: none.
- Steps: 1) Load Battles list at 390px viewport. 2) Open a battle card — verify the challenger/opponent side-by-side matchup either stacks or is preserved responsively.
- Expected: no horizontal scroll, tap targets (vote buttons, accept/decline, submit) at least 44px, "Challenge Someone" form usable on a small screen.
- UX expectation: Opponent search results dropdown must not overflow the viewport width; long display names wrap rather than break layout.
- Settings that change it: none.
- Edge cases: opponent search dropdown at narrow width; long display name truncation.

#### MV-BAT-015 — Concurrency: voting/accepting after the outcome is already decided
- Edition: Pro
- Who: two or more members racing an action on the same battle.
- Where: `/media/battles/`, vote and accept endpoints.
- Setup: a battle close to a state transition.
- Steps: 1) Open the same battle in two tabs as the same voter. 2) Vote in tab A. 3) Immediately vote (for the other side) in tab B before A's response returns.
- Expected: server is the single source of truth — verify whether a second vote from the same user changes their vote, is silently ignored, or errors, and that the UI reconciles to whatever the server actually recorded rather than trusting the optimistic UI state from tab B.
- UX expectation: A vote rejected because the battle already resolved mid-flight must show a clean "this battle is over" message, not corrupt the displayed vote count.
- Settings that change it: none.
- Edge cases: admin resolving a battle at the exact moment a vote request is in flight; two admins clicking Resolve/Cancel on the same row simultaneously (covered by MV-BAT-008's error-notice handling).

---

Note: the existing manual runbook's Journey P1 (Compete Hub) describes an older "3 cards always visible" layout that no longer matches the current 2.6.0 template (conditional first-run view plus My Activity/My Results/Active Challenge/Open Tournaments/Battle Arena sections). The runbook should be refreshed against the current template before being used as a pass/fail reference again.

### Area: CHL

#### MV-CHL-001 — Challenges list view (theme, deadline, prize, status)
- **Edition:** Pro
- **Who:** Anyone (logged-out and logged-in) can view. REST list route `permission_callback` is `__return_true`.
- **Where:** `/media/challenges/` (`templates/challenges.php` → `templates/challenges-body.php`); `GET /mvs-pro/v1/challenges`.
- **Setup:** `mvs_competitions_enabled` = `1` AND `mvs_challenges_enabled` = `1`. At least one challenge in each status for full coverage (scheduled/active/voting/finalized/cancelled).
- **Steps:** 1. Visit `/media/challenges/` logged out. 2. Note which tab opens by default. 3. Switch between Active/Voting/Finalized tabs (competition tabs come from `ChallengeService::count_by_status()`).
- **Expected:** Page opens on the first tab that actually has challenges (`TabPicker::first_with_items()`), not a hardcoded "Active" tab that could be empty. Each card shows theme, cover image (falls back to a derived cover from entries if none set), countdown, and a status badge using the labels: "Coming Soon" (scheduled), "Open for Submissions" (active), "Voting Open" (voting), "Results" (finalized), "Cancelled" (cancelled).
- **UX expectation:** Empty tab shows one of: "Submissions open soon. Check back when the challenge starts." (scheduled), "No entries yet. Be the first to submit!" (active), "Submissions are closed. No entries were submitted." (voting), "This challenge has finished. No entries were submitted." (finalized), "This challenge was cancelled." (cancelled) — never a blank white panel. A failed list fetch shows "Failed to load challenges. Please refresh." List load must not require login; no login prompt should appear on this page for browsing.
- **Settings that change it:** Master switch `mvs_competitions_enabled` and `mvs_challenges_enabled` (Settings → Competitions tab) — either OFF removes the page/route/menu entirely (404, not an empty page).
- **Edge cases:** Toggle off → `/media/challenges/` must 404 cleanly, not show a broken shell. Zero challenges of any status. 390px — cards stack full width per the P20 mobile sweep.

#### MV-CHL-002 — Challenge detail view
- **Edition:** Pro
- **Who:** Anyone can view (`GET /mvs-pro/v1/challenges/{id}` is `__return_true`).
- **Where:** `/media/challenges/` deep-linked via `?mvs_challenge_id={id}` (query var `mvs_challenge_id`, read in `challenges-body.php`), or the `pro-challenge` block.
- **Setup:** A challenge in any status; deep link with its id.
- **Steps:** 1. Open the deep link. 2. Confirm the correct challenge opens directly (not the list). 3. Check theme/description/rules, current entry count, and voting status are shown.
- **Expected:** Detail resolves via `deep_link_id = absint(get_query_var('mvs_challenge_id', 0))`; wrong/nonexistent id shows an empty/not-found state, not a PHP notice.
- **UX expectation:** Entry count and status badge reflect DB state live (no stale cache showing "Open for Submissions" after the entry deadline has actually passed server-side). Deep link to a cancelled challenge must show the cancelled state clearly, not silently redirect to the list.
- **Settings that change it:** None beyond the master/sub toggle.
- **Edge cases:** Deep link to an id from a different challenge type (e.g. a tournament id) — must not cross-render; deep link when challenges are toggled off.

#### MV-CHL-003 — Submit entry: new upload
- **Edition:** Pro
- **Who:** Logged-in members only (`is_user_logged_in` on the entries POST route).
- **Where:** `/media/challenges/` detail view, Submit Entry action; `POST /mvs-pro/v1/challenges/{id}/entries`.
- **Setup:** Challenge status must be `active` (submission window open); user logged in.
- **Steps:** 1. Open an active challenge. 2. Choose "upload new photo". 3. Upload and submit.
- **Expected:** New media is created, then `ChallengeService::submit_entry()` validates via `CompetitionMedia::is_entrable()` (must exist, be owned by the user, and be a media-library type — a document cannot be entered). On success, entry row inserted and `mvs_challenge_entry_submitted` fires.
- **UX expectation:** Success toast "Entry submitted successfully!" (from the store's `entrySubmitted` string). Upload failure shows "Upload failed. Please try again."; submission failure shows "Submission failed."; a dropped connection shows "Network error. Please try again." No duplicate entry created if the user double-clicks Submit (should disable the button while in flight — verify in the browser, not assumed).
- **Settings that change it:** `max_entries_per_user` on the challenge (default 1) caps how many times this can succeed for the same user.
- **Edge cases:** Upload of a non-image/video file; upload interrupted mid-flight; user with 0 storage quota remaining (Free-side check).

#### MV-CHL-004 — Submit entry: pick from existing media
- **Edition:** Pro
- **Who:** Logged-in members.
- **Where:** Same as CHL-003, "choose from your media" path.
- **Setup:** User already has media in their library; challenge active.
- **Steps:** 1. Open Submit Entry. 2. Pick an existing media item instead of uploading. 3. Submit.
- **Expected:** Same validation path as CHL-003. Two extra guarded cases apply: (a) submitting a media item the user does not own is rejected with "Invalid media or not your content." (b) submitting the *same* media id twice into the same challenge (by the same or a different route) is rejected with "This media has already been submitted." (409, `mvs_challenge_duplicate_media`).
- **UX expectation:** The media picker should only surface the user's own media (not require the backend rejection to be the only guard) — a picker that lets you attempt someone else's media and then fails server-side is a worse UX than filtering client-side. Verify which is actually true in the browser.
- **Settings that change it:** None.
- **Edge cases:** Re-submitting a previously-submitted (from an earlier, cancelled challenge) media id into a new challenge should succeed — the duplicate check is scoped to `competition_id`, not global.

#### MV-CHL-005 — Submit entry after the deadline is blocked
- **Edition:** Pro
- **Who:** Logged-in members.
- **Where:** Same submit flow, on a challenge whose `end_date` has passed (status still `active` until the tick runs) or already `voting`.
- **Setup:** A challenge with `end_date` in the past but the 5-minute tick hasn't flipped it yet, OR a challenge already in `voting` status.
- **Steps:** 1. Try to submit an entry to a challenge past its `end_date`. 2. Try to submit to a challenge already in `voting`/`finalized`/`cancelled` status.
- **Expected:** `submit_entry()` returns `mvs_challenge_not_active` (400) once status is no longer `active`, and `mvs_challenge_entries_closed` (400) if status is still `active` but `end_date` has already elapsed (belt-and-suspenders check ahead of the cron tick).
- **UX expectation:** The Submit control itself should be disabled with the countdown showing "Deadline passed" / a "Voting in progress" message rather than letting the user attempt the call and eat a network error — per the QA runbook's documented pass criterion for this exact case.
- **Settings that change it:** None.
- **Edge cases:** Race where the countdown UI hasn't refreshed status yet but the deadline has technically passed (client clock skew) — confirm the disabled state is server-truth-driven (re-check on submit attempt), not purely client countdown-driven.

#### MV-CHL-006 — Voting on entries
- **Edition:** Pro
- **Who:** Logged-in members (`is_user_logged_in`); anonymous can view entries but not vote.
- **Where:** Challenge detail in `voting` status; `POST`/`DELETE /mvs-pro/v1/challenges/{id}/entries/{entry_id}/vote`.
- **Setup:** Challenge status `voting`, `voting_end_date` not yet passed, at least 2 entries from different users.
- **Steps:** 1. Try to vote for your own entry. 2. Vote for another entrant's entry. 3. Vote for a second, different entry in the same challenge. 4. Try to vote for the same entry twice. 5. Remove (unvote) your vote.
- **Expected:** Self-vote is rejected with "You cannot vote for your own entry." (403). A vote for a different entry succeeds and increments `vote_count`. **Verify:** the uniqueness constraint is `(votable_type='entry', votable_id, user_id)` — unique per *entry*, not per *challenge* — so a member can legally cast one vote on each of several different entries in the same challenge; this is not "one vote per challenge" despite that phrasing appearing in some QA notes. A second vote on the *same* entry is rejected with "You have already voted for this entry." Unvote decrements `vote_count` (floored at 0) and succeeds only if a vote existed ("You have not voted for this entry." on a no-op attempt).
- **UX expectation:** Toast "Vote recorded!" on success, "Vote failed." on error, "Failed to remove vote." on unvote failure. The voted entry should visually reflect the new vote state immediately (optimistic or refetch) without a full page reload. The button for the user's own entry should be visibly disabled/greyed with an explanatory tooltip, not just silently rejected on click.
- **Settings that change it:** None — no per-site config for vote limits.
- **Edge cases:** Voting exactly as `voting_end_date` passes (must be rejected once past, "Voting period has ended."); two simultaneous vote clicks on the same entry from the same session (INSERT IGNORE against the unique key is the sole concurrency guard — confirm no double-increment).

#### MV-CHL-007 — Deadline countdown accuracy
- **Edition:** Pro
- **Who:** Anyone viewing the list or detail.
- **Where:** `/media/challenges/` cards and detail view.
- **Setup:** Challenges at various distances from their `end_date`/`voting_end_date`.
- **Steps:** 1. Compare displayed countdown against server time for a challenge >1 day out, one <24h out, one <1h out, and one already past.
- **Expected:** Countdown format switches per the store's i18n strings: `%1$dd %2$dh left` when days remain, `%1$dh %2$dm left` under a day, `%dm left` under an hour, and "Deadline passed" once expired.
- **UX expectation:** The countdown must be based on server-relative time (via the REST payload's timestamps), not the visitor's possibly-wrong local clock, and must keep counting down live client-side rather than requiring a page refresh to update.
- **Settings that change it:** None (dates are per-challenge, set at creation).
- **Edge cases:** Site timezone vs UTC mismatch (dates are stored in `gmdate()` / UTC — confirm the frontend converts correctly for the site's `timezone_string`); challenge with `end_date` in the past but not yet ticked to `voting` (countdown shows "Deadline passed" while badge may lag by up to 5 minutes until the tick runs — document this as expected, not a bug).

#### MV-CHL-008 — Finalization and winner announcement
- **Edition:** Pro
- **Who:** System-driven (cron tick) or admin-triggered via Challenge Manager; results viewable by anyone.
- **Where:** Backend: `ChallengeService::finalize()` fired by the `mvs_finalize_expired_challenges` transition hook (part of the 5-minute `mvs_competitions_tick`), or admin "Finalize" action in Challenge Manager. Frontend: `GET /mvs-pro/v1/challenges/{id}/results`.
- **Setup:** Challenge in `voting` status past `voting_end_date` (auto) or admin manually finalizing.
- **Steps:** 1. Let a challenge's voting period expire (or use the admin Finalize action). 2. View the challenge's results.
- **Expected:** Entries are ranked by `vote_count DESC, created_at ASC` (earlier submission wins a tie on votes). Top 3 become `winner_1st/2nd/3rd`; ranks persisted to each entry's `rank` column. Status becomes `finalized`, `winner_id` set to 1st place. `get_results()` refuses (`WP_Error`) on a non-finalized challenge.
- **UX expectation:** Results view highlights the winner distinctly (not just first-in-list styling that could be confused with sort order); a tie in votes is resolved silently by submission order and should not display as an ambiguous "tie" to the visitor. If fewer than 3 entries exist, unfilled ranks (`winner_2nd`/`3rd` = 0) must not render as "won by user #0" or a broken avatar.
- **Settings that change it:** `xp_1st`/`xp_2nd`/`xp_3rd`/`xp_participation` (set at challenge creation, defaults 200/100/50/10) determine the XP shown/awarded. Confirmed by code: finalizing a challenge fires an action that the separate WB Gamification plugin listens for and turns into a 200/100/50-point award; MediaVerse Pro then intercepts that award through its points-resolution filter and substitutes the challenge's own configured XP amount for that rank. So the number actually credited to the winner is the challenge's own xp_1st/xp_2nd/xp_3rd/xp_participation value, not WB Gamification's flat default — this requires the free WB Gamification plugin to be installed and active; without it, no XP is awarded at all (the challenge still finalizes normally).
- **Edge cases:** Zero entries at finalization (all winner slots 0 — no error, no winner emails go out since the entrant loop finds nobody). Exactly 1 or 2 entries (2nd/3rd slots stay 0). Finalizing a challenge whose status is not `voting` (rejected, `mvs_challenge_not_voting`). Very large entry counts (finalize batches rank updates 500 at a time — verify no timeout at 2,000+ entries).

#### MV-CHL-009 — Four email notification events
- **Edition:** Pro
- **Who:** Challenge creator (email 1); entrant (email 2); winners (email 3); non-winning participants (email 4). Autopilot-created challenges have no creator, so email 1 is skipped for those.
- **Where:** `ChallengeNotificationListener`, triggered off `mvs_challenge_created`, `mvs_challenge_entry_submitted`, `mvs_challenge_finalized`.
- **Setup:** WP mail sending must work (use a mail-catcher or log plugin); need a challenge with a human creator, at least one entrant, and finalization with a clear winner and at least one non-winner.
- **Steps:** 1. Create a challenge via admin as a logged-in user with an email → check "[Sitename] Your challenge is set up" arrives to the creator. 2. Submit an entry → check "Entry received: {title}" arrives to the entrant. 3. Finalize with 3+ entrants → check winners get "{1st/2nd/3rd} place in {title}!" and every other entrant gets 'The "{title}" challenge has ended'.
- **Expected:** Exactly four distinct `wp_mail()` templates: (1) creator "set up" confirmation, (2) entrant "entry received" confirmation, (3) per-winner placement congratulation (rank-aware subject/body), (4) consolation email to every entrant not in the top 3. Each subject/body pair is filterable (`mvs_challenge_email_created_*`, `_entry_*`, `_winner_*`, `_participant_*`). In-app notifications also fire for entry-received, won, and participated (three notification types, not four — the creator "set up" email has no in-app counterpart).
- **UX expectation:** No email sent if the recipient's account has no valid email (`is_email()` check) — must fail silently, not fatal. Winner rank label reads "1st place"/"2nd place"/"3rd place" (and "%dth place" for any rank beyond 3, though only top 3 currently get awarded — dead branch worth noting, not a bug to fix here).
- **Settings that change it:** None. Confirmed: there is no site-level toggle for challenge emails anywhere in Settings, and this is not a gap to report — Free's "Emails" section and its member-facing "Email me about activity" opt-out only cover Free's own three email types (battle invites, documents shared, report outcomes); challenge emails are a separate Pro-only channel that was never wired into that on/off list or the member opt-out. A member currently has no way to turn challenge emails off short of an email client filter.
- **Edge cases:** Autopilot-created challenge (creator email skipped, no fatal). Zero entrants at finalization (no winner/participant emails sent). A user with an invalid/missing email address anywhere in the chain.

#### MV-CHL-010 — Autopilot: weekly scheduled challenge creation
- **Edition:** Pro
- **Who:** System (Action Scheduler); configured by admin.
- **Where:** `AutopilotService`, Action Scheduler recurring hook (weekly cadence), configured in Settings → Competitions tab, "Weekly Autopilot" section.
- **Setup:** `mvs_challenges_enabled` = 1, `mvs_autopilot_enabled` = 1, at least one enabled+unused theme in the Theme Library pool (52 built-in themes seeded by default from `data/default-themes.php`).
- **Steps:** 1. Enable Autopilot with a chosen day/hour. 2. Wait for (or force) the scheduled action to fire. 3. Confirm a new challenge appears with the picked theme.
- **Expected:** On each weekly run, `pick_next_theme()` selects the next enabled+unused theme (themes are shuffled by category so consecutive picks vary), creates a challenge via `ChallengeService::create()` using that theme's name/description/slug, and marks the theme used. If every enabled theme has been used, the pool resets (`used=false` for all) and shuffles again before the next pick. If **no enabled theme exists at all**, no challenge is created and `mvs_autopilot_no_theme_available` fires with no listener anywhere in the plugin — confirmed log-only, no admin notice exists. No docs promise one, so this is not a defect; a site owner wanting an alert would need to hook that action themselves.
- **UX expectation:** Turning Autopilot off (or `mvs_challenges_enabled`/`mvs_competitions_enabled` off) unschedules the recurring Action Scheduler job immediately via the `update_option_*`/`add_option_*` hooks — re-enabling reschedules on the next `init`. Settings screen must show which theme will run next and the computed next-run timestamp (`get_status()` exposes `next_theme` and `schedule`), not leave the admin guessing.
- **Settings that change it:** `mvs_autopilot_enabled` (off by default), `mvs_autopilot_day` (default Monday), `mvs_autopilot_hour` (default 9am, site timezone), `mvs_autopilot_entry_days` (5/7/10/14, default 7), `mvs_autopilot_voting_days` (2/3/5/7, default 3), `mvs_autopilot_max_entries` (per-entrant cap).
- **Edge cases:** Toggling `mvs_challenges_enabled` off and back on mid-week (unschedule/reschedule). All themes disabled (no eligible theme → no-op, verify no fatal). Changing day/hour after the job is already scheduled (must reschedule, not run twice).

#### MV-CHL-011 — Theme Library admin (challenge themes)
- **Edition:** Pro
- **Who:** `manage_mvs_settings` capability only.
- **Where:** `wp-admin` → MediaVerse → Challenge Themes (`admin.php?page=mvs-theme-library`).
- **Setup:** `mvs_challenges_enabled` = 1. Confirmed: with it off, the "Challenge Themes" submenu is fully absent (the page class is only ever constructed when Challenges is on, so `admin_menu` never registers the item) — not present-but-non-functional.
- **Steps:** 1. View the theme grid, filter by category/status. 2. Toggle a built-in theme's enabled state. 3. Add a custom theme (name + description). 4. Try to delete a built-in theme. 5. Delete a custom theme.
- **Expected:** `handle_toggle()` flips `enabled` for a theme by slug (nonce `mvs_toggle_theme_{slug}`). `handle_add()` (nonce `mvs_add_theme`) appends a new entry, auto-generating the slug via `sanitize_title()`, marked `custom => true`, rejecting duplicate slugs. `handle_delete()` (nonce `mvs_delete_theme_{slug}`) only succeeds for `custom === true` themes — deleting a built-in theme returns false; built-ins can only be disabled, never removed.
- **UX expectation:** Attempting to delete a built-in theme should not even show a delete control (only a toggle) — if a Delete button is visible on built-ins, that is a bug: the backend already refuses it silently (`delete_theme()` returns `false`), and a silent no-op with no on-screen feedback is a UX defect worth flagging even though the data is safe.
- **Settings that change it:** None beyond the toggles here; feeds directly into Autopilot's pool.
- **Edge cases:** Adding a theme with a slug colliding with an existing one (rejected). Deleting every enabled theme (Autopilot then has nothing to pick — see CHL-010). 390px grid layout.

#### MV-CHL-012 — Challenge Manager admin (create/edit/moderate lifecycle)
- **Edition:** Pro
- **Who:** `manage_mvs_settings` capability only.
- **Where:** `wp-admin` → MediaVerse → Photo Challenges (`admin.php?page=mvs-challenges`).
- **Setup:** `mvs_challenges_enabled` = 1.
- **Steps:** 1. Create a challenge (title, theme, dates, XP, cover). 2. Edit a scheduled/active/voting challenge's dates. 3. Manually "Start Now" an active-eligible challenge, "End Entries" early, "Finalize" early, and "Cancel". 4. Try each transition on a challenge in the wrong state.
- **Expected:** `handle_save()`/`handle_update()` validate title + date ordering (`start < end < voting_end`) exactly as the REST create path does. `handle_start()`/`handle_end_entries()`/`handle_finalize()` map to `activate()`/`close_entry_period()`/`finalize()` and only succeed from the correct source status (scheduled→active, active→voting, voting→finalized); wrong-state attempts return a `WP_Error` surfaced via `add_settings_error` + a redirect-and-display pattern (`render_action_notice()`). `handle_cancel()` refuses on an already-finalized challenge.
- **UX expectation:** Every action (create/update/cancel/start/end-entries/finalize) redirects with a query flag (`?created=1`, `?updated=1`, `?cancelled={id}`, `?started={id}`, etc.) that `render_action_notice()` turns into a visible admin notice — a fix landed specifically because these used to complete silently with no on-screen confirmation. Destructive actions (Cancel) should be confirmed before executing, not fire immediately on click — verify a confirm dialog is present in the browser.
- **Settings that change it:** None — this page IS the settings surface per-challenge.
- **Edge cases:** Editing dates on a `finalized`/`cancelled` challenge (blocked). List/tab counts (`compute_status_counts()`) must match the frontend hub counts exactly (explicit QA pass criterion in the runbook). 2000+ challenges — verify pagination exists on this list (large-site checklist).

#### MV-CHL-013 — pro-challenge block (single challenge embed)
- **Edition:** Pro
- **Who:** Editors/admins placing the block; visitors viewing the page.
- **Where:** Block editor, block `mvs/pro-challenge`, attribute `challengeId` (default 0).
- **Setup:** `mvs_challenges_enabled` = 1; a specific challenge id to embed.
- **Steps:** 1. Insert the block on a page. 2. Set `challengeId` in the inspector. 3. Publish and view the frontend.
- **Expected:** Renders the same single-challenge detail experience as the deep-linked `/media/challenges/?mvs_challenge_id=X` view (per `render.php`).
- **UX expectation:** With `challengeId` = 0 (unset) or pointing at a nonexistent id, the block should show an editor-side placeholder/empty state, not a fatal or a blank front-end gap.
- **Settings that change it:** `mvs_challenges_enabled`/`mvs_competitions_enabled` OFF — block should render its off-state (admin-only notice per the runbook's block-matrix pattern; visitors see nothing), not a broken shell.
- **Edge cases:** Block placed while feature is on, then toggled off after publish (existing pages must not error). 390px block rendering.

#### MV-CHL-014 — pro-challenges-list block
- **Edition:** Pro
- **Who:** Editors/admins; visitors viewing the page.
- **Where:** Block editor, block `mvs/pro-challenges-list` (no configurable attributes).
- **Setup:** `mvs_challenges_enabled` = 1; multiple challenges across statuses.
- **Steps:** 1. Insert the block. 2. Publish and view frontend list output.
- **Expected:** Shows every active and upcoming challenge with theme, deadline, and entry count — same data source as the `/media/challenges/` list.
- **UX expectation:** Empty state (no active/upcoming challenges) must show a clear message, not an empty `<div>`.
- **Settings that change it:** `mvs_challenges_enabled` off → notice for admins, nothing for visitors (per runbook's block-off-state convention).
- **Edge cases:** Very large challenge count on a page using this block — confirm it doesn't unboundedly render every historical challenge (verify pagination/limit exists).

#### MV-CHL-015 — Master/sub toggle off behavior
- **Edition:** Pro
- **Who:** Admin toggling; all visitors affected.
- **Where:** Settings → Competitions tab; affects `/media/challenges/`, admin menu, REST routes, Autopilot cron, and both blocks.
- **Setup:** Start with challenges fully working, then flip `mvs_challenges_enabled` off (master `mvs_competitions_enabled` left on), then flip the master off too.
- **Steps:** 1. Turn off `mvs_challenges_enabled` only. 2. Visit `/media/challenges/`, the admin menu, and the REST routes. 3. Turn the master `mvs_competitions_enabled` off entirely. 4. Repeat the same checks, plus check the Compete hub.
- **Expected:** With the sub-toggle off: the "Photo Challenges" and "Challenge Themes" admin submenus, the `/media/challenges/` route, and the ChallengeController's REST routes are all unregistered — `/media/challenges/` should 404, not render an empty template. With the master off: this cascades (no menu items at all under Competitions, Autopilot cron unscheduled, the Compete hub hides the Challenges card entirely per Journey P1.5's explicit pass criterion — "hidden ... not a greyed card or broken link").
- **UX expectation:** No orphaned nav links anywhere pointing at a 404'd page. Turning it back on must restore full functionality without needing a resave of unrelated settings (data — challenges, entries, themes — must survive the toggle cycle untouched).
- **Settings that change it:** `mvs_competitions_enabled`, `mvs_challenges_enabled`.
- **Edge cases:** Toggling off mid-vote or mid-entry-submission (in-flight REST calls should fail cleanly, not corrupt data). An already-bookmarked deep link to a challenge, visited after toggle-off.

#### MV-CHL-016 — Mobile 390px
- **Edition:** Pro
- **Who:** All visitors on mobile.
- **Where:** `/media/challenges/` list, detail, submit, and voting flows; Challenge Manager and Theme Library admin at mobile widths.
- **Setup:** Browser viewport at 390px; at least one active and one voting challenge.
- **Steps:** 1. Load the list — verify cards stack full-width. 2. Open detail — verify layout doesn't require horizontal scroll. 3. Submit an entry and vote from a 390px viewport.
- **Expected:** Matches Journey P20's documented pass: challenges cards render full-width at 390px, no horizontal overflow.
- **UX expectation:** Tap targets (submit button, vote button, tab switches) meet the 40px minimum touch target; upload/media-picker modal is usable at this width without zoom.
- **Settings that change it:** None.
- **Edge cases:** Long theme titles/descriptions wrapping correctly; countdown text not truncating awkwardly at narrow width.

#### MV-CHL-017 — WP-CLI `wp mvs competitions tick` / `wp mvs competitions recompute`
- **Edition:** Pro
- **Who:** Server admin/developer via WP-CLI (shell access), not a member-facing surface.
- **Where:** `wp mvs competitions tick`, `wp mvs competitions recompute` (registered by `CompetitionsScheduler`, shared across Battles/Challenges/Tournaments — not challenge-specific, but drives challenge transitions too).
- **Setup:** WP-CLI available; at least one challenge each in `scheduled`/`active`/`voting` state with deadlines already in the past (to force a visible transition).
- **Steps:** 1. Run `wp mvs competitions tick` and confirm scheduled challenges past `start_date` become `active`, active ones past `end_date` become `voting`, and voting ones past `voting_end_date` get `finalize()`d. 2. Run `wp mvs competitions recompute` and confirm it re-runs without erroring.
- **Expected:** `tick` fires all six transition hooks in one pass (`mvs_resolve_expired_battles`, `mvs_activate_scheduled_challenges`, `mvs_close_challenge_entries`, `mvs_finalize_expired_challenges`, `mvs_start_registered_tournaments`, `mvs_resolve_expired_matches`) — this is the same routine the 5-minute Action Scheduler cadence runs automatically, so the CLI command is a manual "run it now" rather than challenge-only. Each hook is wrapped in try/catch so one failure doesn't block the others; success message "Competitions tick executed." `recompute` deletes the one-shot migration flag option and re-runs `run_migration()`. Confirmed: this does NOT recalculate scores or XP — it (1) unschedules legacy duplicate per-hook Action Scheduler recurring actions left over from pre-2.3.0 installs, then (2) fires each of the six transition hooks once (the same hooks the 5-minute tick runs), then returns a `COUNT(*)` of competitions still not finalized/cancelled. "Recomputed N competition(s)." means "N competitions are still open after re-running the transitions," not "N competitions had their score recalculated."
- **UX expectation:** N/A (CLI-only, no visual surface) — but the command output message must accurately reflect what happened (no silent no-op on zero eligible rows; message should still print with N=0).
- **Settings that change it:** None — this is infrastructure, not user-configurable.
- **Edge cases:** Running `tick` with competitions/challenges toggled off (hooks fire but no listener should be registered — verify no fatal from calling into a disabled service). Running `recompute` twice in a row (idempotent).

### Area: TRN

#### MV-TRN-001 — Tournaments list view (registration window, bracket size, prize)
- **Edition:** Pro
- **Who:** Anyone can view (`GET /mvs-pro/v1/tournaments` is `__return_true`).
- **Where:** `/media/tournaments/` (`templates/tournaments.php` → `templates/tournaments-body.php`).
- **Setup:** `mvs_competitions_enabled` = 1 and `mvs_tournaments_enabled` = 1; tournaments in `registration`/`active`/`finalized` states.
- **Steps:** 1. Visit `/media/tournaments/` logged out. 2. Check which tab opens by default. 3. Switch Registration/Active/Finalized tabs.
- **Expected:** Opens on the first non-empty tab (`TabPicker::first_with_items()` against registration/active/finalized). Cards show status badges: "Registration Open", "In Progress", "Completed", "Cancelled".
- **UX expectation:** Empty-tab and error states use the store's real strings: "Failed to load tournaments. Please refresh." on fetch failure; no blank panel on zero tournaments in a tab. Confirmed: the empty state exists and is per-tab (`state.showEmpty` flips to true whenever the active tab's fetched list is empty), rendering "No tournaments right now. Check back soon!" — it just isn't named with an `empty*` i18n key like Challenges, it's a plain bound string.
- **Settings that change it:** `mvs_competitions_enabled` + `mvs_tournaments_enabled` — either off removes the route entirely (404).
- **Edge cases:** Zero tournaments in every status; toggle off; 390px stacking.

#### MV-TRN-002 — Registration window (register / unregister)
- **Edition:** Pro
- **Who:** Logged-in members (`is_user_logged_in` on both register and unregister routes).
- **Where:** `/media/tournaments/` detail; `POST`/`DELETE /mvs-pro/v1/tournaments/{id}/register`.
- **Setup:** Tournament in `registration` status, current time within `registration_start`/`registration_end`.
- **Steps:** 1. Register while status is `registration`. 2. Unregister before the bracket is generated. 3. Try to register/unregister once the tournament has moved to `active`.
- **Expected:** `register_participant()` inserts an entry with `seed = current_count + 1` (registration-order seed, later reshuffled at bracket generation) and only succeeds while status is (a snapshot read of) `registration` — locked via `SELECT ... FOR UPDATE` at bracket-generation time to prevent a late registration sneaking in mid-generation (Basecamp 10068991206 fix). `unregister_participant()` only works while still in `registration`.
- **UX expectation:** Toast "Successfully registered!" on success, "Registration failed." on error. Once registration closes, the Register button must be disabled/hidden rather than clickable-and-failing. A member who unregisters should immediately see their own name drop off the participant list without a page reload.
- **Settings that change it:** None directly; the window itself is set at tournament creation (`registration_start`/`registration_end`).
- **Edge cases:** Registering exactly as the registration window closes (must race safely against `generate_bracket()`'s row lock — see TRN-018 for the concurrency scenario). Attempting to register twice: confirmed rejected — an explicit duplicate-registration check exists inside the same row-locked transaction as the registration-window check, returning `mvs_tournament_already_registered` ("Already registered.", 409).

#### MV-TRN-003 — Bracket creation and seeding
- **Edition:** Pro
- **Who:** System (automatic, when registration closes) or admin ("Start Now" in Tournament Manager).
- **Where:** `TournamentService::generate_bracket()`, fired by `mvs_start_registered_tournaments` (part of the tick) or `handle_start()` in Tournament Manager (`admin.php?page=mvs-tournaments`).
- **Setup:** Tournament in `registration` with at least 2 registered participants; a chosen `bracket_size` (4/8/16/32/64) set at creation.
- **Steps:** 1. Let registration close naturally (or click "Start Now" early). 2. Inspect the generated round-1 bracket.
- **Expected:** Entries are shuffled (`shuffle()`) and re-seeded 1..N in the new random order (registration-order seeding is discarded at this point). Slots are padded to `bracket_size` with nulls (byes). Round 1 matches are created for real pairings; `total_rounds = log2(bracket_size)`; status flips to `active`; `mvs_tournament_started` fires. Attempting this on a non-`registration`-status tournament (already started) returns `mvs_tournament_already_started`. Fewer than 2 total entries returns `mvs_tournament_too_few` and — when hit via the automatic cron path (not the manual admin Start) — the tournament is auto-cancelled with reason `insufficient_participants`.
- **UX expectation:** Admin manually starting a tournament with under 2 participants should see the `mvs_tournament_too_few` error message on-screen (via `add_settings_error`), not a silent redirect back to the list. A tournament auto-cancelled for insufficient participants should show as "Cancelled" to any registered participant, ideally with a reason, not just vanish.
- **Settings that change it:** `bracket_size` and `round_duration_hours` (default 48h), set at tournament creation, drive slot count and per-round submit/vote deadlines (`submit_deadline` = now + round_duration; `vote_deadline` = now + 2× round_duration).
- **Edge cases:** Exactly 2 registered participants in an 8-slot bracket (6 byes to sort through — see TRN-004). Manual "Start Now" clicked twice quickly (second call should be blocked by the same status check, not double-generate).

#### MV-TRN-004 — Sparse bracket / bye handling (odd or under-capacity entrant counts)
- **Edition:** Pro
- **Who:** System-generated; visible to all viewers of the bracket.
- **Where:** `generate_bracket()`'s slot-pairing loop.
- **Setup:** A tournament with `bracket_size = 8` (or 16/32/64) but far fewer actual registrants — e.g. 3 or 5 participants — or an odd number relative to the slot count.
- **Steps:** 1. Create an 8-slot tournament, register exactly 3 or 5 participants, let/force the bracket to generate. 2. Inspect round 1.
- **Expected:** Slots beyond the entry count are `null`. A match with one real entry and one `null` is a bye: the real entrant is auto-advanced (`winner_entry_id` set immediately, `status = 'bye'`), no submission/voting needed. A match where **both** slots are `null` (bracket size far exceeds entrant count) creates **no match row at all** — this was a fixed fatal (Basecamp 9966421635: dereferencing a null winner) and must not regress. Later-round advancement (`try_advance()`) treats `'bye'` the same as `'completed'` when checking whether a round is finished (`status NOT IN ('completed', 'bye')`).
- **UX expectation:** The bracket view must render a bye distinctly — the runbook's own pass criterion is "byes indicated" and "Bye" showing on the bracket rather than an empty/confusing slot, and the frontend i18n string for this is "Automatic Advance" (`matchBye`). A double-bye "phantom match" (both-null case) must not appear in the bracket UI as an empty box waiting for two players who will never arrive.
- **Settings that change it:** `bracket_size` chosen at creation relative to actual registrant turnout — this is the entire lever that produces sparse brackets; no separate "allow byes" setting exists.
- **Edge cases:** All-bye first round (e.g. 2 participants in a 64-slot bracket — 1 real match, everything else empty/bye chains). A participant who gets a bye in round 1 then faces a real opponent in round 2 — confirm they advance correctly without ever having submitted media.

#### MV-TRN-005 — Viewing the bracket
- **Edition:** Pro
- **Who:** Anyone (`GET /mvs-pro/v1/tournaments/{id}/bracket` is `__return_true`).
- **Where:** `/media/tournaments/` detail view, "Bracket" panel; also the Tournament Manager admin detail page (`render_detail()`).
- **Setup:** A tournament past `generate_bracket()` (status `active` or `finalized`), ideally spanning 2+ rounds.
- **Steps:** 1. View the bracket for an in-progress tournament. 2. View it for a finalized one.
- **Expected:** Rounds render left-to-right (or the theme's chosen orientation) with each match showing both players, current match status (Awaiting Start / Submissions Open / Voting Open / Match Complete / Automatic Advance), and the current round highlighted. On the admin side, `render_detail()` also lists every participant with seed number and elimination round ("Eliminated R{n}" or "Active"), and shows the champion once finalized.
- **UX expectation:** No round should render blank while its predecessor round is still incomplete — matches for a not-yet-reached round simply don't exist yet (no phantom "TBD vs TBD" placeholders unless that's an intentional pre-seeded look; verify which is actually shown). Bracket must horizontally scroll with a fade-edge hint on narrow viewports rather than clipping.
- **Settings that change it:** None.
- **Edge cases:** A tournament with only byes in round 1 (bracket looks sparse but must still render every real round). Very large brackets (64 slots) — verify the admin list doesn't try to render all matches unpaginated in a way that's unusable.

#### MV-TRN-006 — Submit media for a match
- **Edition:** Pro
- **Who:** Only the two participants in that specific match (`is_user_logged_in` + participant check).
- **Where:** `POST /mvs-pro/v1/tournaments/{id}/matches/{match_id}/submit`.
- **Setup:** Match status `active`, before `submit_deadline`.
- **Steps:** 1. As player A, submit media (new upload or existing). 2. As a non-participant, attempt to submit to the same match. 3. As player B, submit — confirm the match flips to `voting` only once both have submitted.
- **Expected:** Non-participants get `mvs_match_not_participant` (403). Media must pass `CompetitionMedia::is_entrable()` (owned, media-library type — no documents). Match status stays `active` until *both* `player_a_media_id` and `player_b_media_id` are set, then auto-flips to `voting`.
- **UX expectation:** Toast "Photo submitted!" / "Submission failed." The submitting player should see clear feedback that they're now waiting on their opponent (not just a generic "submitted" with no indication voting hasn't started yet).
- **Settings that change it:** `round_duration_hours` (set at tournament creation) determines the submit window length.
- **Edge cases:** Submitting after `submit_deadline` (rejected, "Submission deadline passed." — see TRN-007 for what happens if this expires unsubmitted). Re-submitting to overwrite your own already-submitted media before your opponent has submitted: confirmed allowed — the update simply overwrites your column with no "already submitted" guard, until both sides have submitted and the match flips to voting (at which point a further submit attempt is rejected because the match is no longer active).

#### MV-TRN-007 — Submit deadline missed (stale active match resolution)
- **Edition:** Pro
- **Who:** System (hourly-equivalent tick via `resolve_expired_matches()`).
- **Where:** Part of `mvs_resolve_expired_matches` transition hook, run inside the 5-minute `mvs_competitions_tick`.
- **Setup:** A match still `active` with `submit_deadline` already passed, where zero, one, or both players failed to submit.
- **Steps:** 1. Let a match's submit deadline pass with only player A having submitted. 2. Let another pass with neither having submitted. 3. Observe the resolution.
- **Expected:** Whoever submitted wins by default; if neither submitted, player A (the higher seed) wins by default. The loser is marked eliminated (`eliminated_in_round`), match status becomes `completed`, and `mvs_tournament_match_resolved` fires — same downstream effects (elimination notification) as a normal voting resolution.
- **UX expectation:** The eliminated player should receive the same "You were eliminated from a tournament" in-app notification as a normal loss, not a different/no notification just because it was a no-submission default loss — verify this is actually the case since the win path is shared code but worth confirming in the browser.
- **Settings that change it:** `round_duration_hours` controls how long this window is.
- **Edge cases:** Both players fail to submit (player A auto-wins with no submitted media on either side — confirm the bracket UI doesn't try to render two empty media tiles as if a real match happened).

#### MV-TRN-008 — Voting per matchup
- **Edition:** Pro
- **Who:** Logged-in members who are not one of the two match participants (`is_user_logged_in` + self-vote block).
- **Where:** `/media/tournaments/` match view; `POST /mvs-pro/v1/tournaments/{id}/matches/{match_id}/vote`.
- **Setup:** Match status `voting`, before `vote_deadline`, both media submitted.
- **Steps:** 1. As either participant, try to vote in your own match. 2. As a third-party member, vote for player A. 3. Try to vote again in the same match. 4. Try to vote for a user who isn't in this match.
- **Expected:** Self-vote by either participant is rejected (`mvs_match_self_vote`, 403). A vote for a non-participant id is rejected (`mvs_match_invalid_vote`, 400). Vote uniqueness is enforced the same way as challenges — `INSERT IGNORE` against `(votable_type='match', votable_id, user_id)` — a second vote attempt returns "Already voted." Vote increments `player_a_votes` or `player_b_votes` on the match row.
- **UX expectation:** Toast "Vote recorded!" / "Vote failed." Participants viewing their own match should see voting UI disabled/hidden rather than a clickable control that 403s.
- **Settings that change it:** None (no configurable vote weighting).
- **Edge cases:** Voting exactly at `vote_deadline` (rejected once passed, "Voting deadline passed."). Simultaneous votes from the same user on two different matches in the same tournament (independent, both should be allowed).

#### MV-TRN-009 — Match resolution and tie handling
- **Edition:** Pro
- **Who:** System (automatic on vote-deadline expiry) or admin (manual force-resolve).
- **Where:** `resolve_match()`, called from `resolve_expired_matches()` (cron) or `TournamentManager::handle_resolve_match()` (admin, nonce `mvs_resolve_match_{match_id}`).
- **Setup:** Match in `voting` status, votes cast (or tied at 0-0), admin wanting to force a decision before the deadline.
- **Steps:** 1. Let a match's vote deadline pass with `player_a_votes > player_b_votes`. 2. Let one pass tied at equal votes (including 0-0, e.g. nobody voted). 3. As admin, manually force-resolve a still-open match via the Tournament Manager detail page.
- **Expected:** Winner is whoever has `>=` votes on player A's side — meaning **a tie (including 0-0) always goes to player A**, the higher seed. This is a documented, intentional rule, not a bug. Loser is marked eliminated for the current round. `mvs_tournament_match_resolved` fires either way. The admin manual path calls the identical `resolve_match()` then explicitly calls `advance_rounds()` afterward so the bracket doesn't wait for the next cron tick.
- **UX expectation:** A 0-0 match resolving to "player A wins" should be visually explained if possible (e.g. a note that no votes were cast), not presented identically to a decisively-voted match, to avoid the appearance of a broken tiebreaker. Admin force-resolve should require confirmation (it's an irreversible elimination) and clearly show which match is about to be resolved.
- **Settings that change it:** None — the tie rule is fixed in code, not configurable.
- **Edge cases:** Force-resolving a match that already auto-resolved moments earlier via cron (should return a clean "not in voting phase" error, not double-eliminate). Force-resolving from the wrong tournament's admin context (nonce is scoped per match_id, should reject a mismatched attempt).

#### MV-TRN-010 — Round advancement
- **Edition:** Pro
- **Who:** System (`advance_rounds()` runs every tick once all current-round matches are `completed` or `bye`).
- **Where:** `try_advance()`, part of `mvs_resolve_expired_matches`.
- **Setup:** All matches in the current round of an active tournament resolved (completed or bye), with more than one round remaining.
- **Steps:** 1. Resolve every match in round 1 (mix of real wins and byes). 2. Wait for/force the next tick. 3. Confirm round 2 matches are generated pairing the round-1 winners in order.
- **Expected:** `try_advance()` only proceeds once the incomplete-match count for the current round is 0. It reads winners in `match_position` order and pairs them consecutively for the next round, applying the same submit/vote deadline formula as round 1. `current_round` in `settings` is bumped. If the round that just completed was the final round, no new matches are generated — instead the sole remaining winner is crowned champion (see TRN-011).
- **UX expectation:** The bracket UI should show the next round's matches appear (with fresh "Submissions Open" status) without requiring the visitor to refresh — verify whether this is polling-based or requires a manual reload, per the runbook's stated pass criterion of updating "without refresh (polling or optimistic)".
- **Settings that change it:** `round_duration_hours` from tournament creation applies identically to every round.
- **Edge cases:** A round where every match was a bye (still must advance on the same cadence, not get stuck). Very large bracket (64 slots, 6 rounds) — confirm no round is skipped or double-generated under concurrent tick runs (see TRN-018).

#### MV-TRN-011 — Final results and champion display
- **Edition:** Pro
- **Who:** Anyone viewing; system crowns the champion.
- **Where:** Tournament detail once `status = finalized`; admin detail page.
- **Setup:** A tournament through its final round with exactly one winner remaining.
- **Steps:** 1. Resolve the final match. 2. View the tournament as a visitor and in Tournament Manager.
- **Expected:** `try_advance()` detects `next_round > total_rounds`, sets `status = finalized`, `winner_id` = the champion's user id, `resolved_at` = now, and fires `mvs_tournament_finalized`. The admin detail page shows "Champion: {name}"; the frontend status badge reads "Completed".
- **UX expectation:** The champion should be prominently and unmistakably displayed (not just implied by being the last name standing in a bracket) — the admin page's explicit "Champion:" label sets the bar; verify the frontend has an equivalent, not just a bracket the visitor has to trace by eye.
- **Settings that change it:** `xp_tournament_win` (default 500) and `xp_round_win` (default 150) set at creation. Confirmed end-to-end, same mechanism as challenges: a match resolving fires an action WB Gamification listens for (award action `mvs_tournament_round_win`, carrying `match_id`), and the tournament finalizing fires another (`mvs_tournament_win`, carrying `tournament_id`); MediaVerse Pro's points-resolution filter catches both and substitutes `xp_for_round_win()`/`xp_for_tournament_win()` for WB Gamification's flat default. Requires the free WB Gamification plugin active, same as challenges.
- **Edge cases:** A tournament that never got past round 1 due to insufficient participants (never reaches finalized via this path — it was cancelled instead, see TRN-003). Two champions determined near-simultaneously by a race between manual force-resolve and the cron tick (should not double-fire `mvs_tournament_finalized`).

#### MV-TRN-012 — Notification events (in-app only, no emails)
- **Edition:** Pro
- **Who:** Eliminated players (per match loss); the grand champion (on finalization).
- **Where:** `TournamentNotificationListener`, hooked to `mvs_tournament_match_resolved` and `mvs_tournament_finalized`.
- **Setup:** A resolved match with a real loser (not a bye); a finalized tournament with a champion.
- **Steps:** 1. Resolve a real match. 2. Check the losing player's in-app notifications. 3. Finalize a tournament. 4. Check the champion's in-app notifications.
- **Expected:** Confirmed by a full read of the file (zero `wp_mail()` calls anywhere in it) — this is real and intentional, not an oversight to fix: Tournaments send only two in-app notification types — "You were eliminated from a tournament" (`tournament_eliminated`) and "You won a photo tournament!" (`tournament_won`) — there is **no `TournamentNotificationListener` email path at all**, unlike Challenges' four `wp_mail()` templates. A bye match fires no elimination notification (no loser exists). The champion notification is self-scoped (`allow_self = true`, same pattern as challenge winners).
- **UX expectation:** If the site owner or a QA report expects tournament winners to get an email like challenge winners do, that expectation is wrong against the current code — this is an intentional asymmetry between the two features, not a missing feature, unless product decides otherwise. Do not "fix" this without confirming it's actually a gap versus a design choice.
- **Settings that change it:** None found.
- **Edge cases:** A player eliminated via the "stale match" default-loss path (TRN-007) — confirm they still get the elimination notification through the shared `mvs_tournament_match_resolved` hook. A champion whose account has since been deleted between winning and notification dispatch (defensive `get_userdata` checks should no-op, not fatal).

#### MV-TRN-013 — Tournament Manager admin (create, monitor, cancel, force-resolve)
- **Edition:** Pro
- **Who:** `manage_mvs_settings` capability only.
- **Where:** `admin.php?page=mvs-tournaments`.
- **Setup:** `mvs_tournaments_enabled` = 1.
- **Steps:** 1. Create a tournament (title, bracket size 4/8/16/32/64, registration window, round duration, XP). 2. View the bracket monitor for an active one. 3. Force-resolve a stuck match from the detail page. 4. Cancel a tournament mid-registration and mid-active. 5. Manually "Start Now" a registration-phase tournament early.
- **Expected:** `handle_save()` validates the same rules as the REST create path (title required, valid bracket size, `registration_end > registration_start`). `handle_cancel()` refuses on an already-finalized tournament but allows cancelling from `registration` or `active`. `handle_start()` calls `generate_bracket()` directly (skips waiting for the registration deadline). `handle_resolve_match()` requires `manage_mvs_settings` and a valid nonce per match id, then calls `resolve_match()` + `advance_rounds()`.
- **UX expectation:** Every action redirects with a status flag surfaced via `render_action_notice()` — same pattern and same historical bug (actions completing silently) as Challenge Manager; verify the fix is actually visible for every action listed, not just create/update. Cancelling a live tournament with participants mid-bracket should warn the admin this eliminates everyone still in it, not fire on a bare click.
- **Settings that change it:** None beyond what's entered per-tournament here.
- **Edge cases:** Cancelling a tournament that has already auto-cancelled itself for insufficient participants (should be a clean no-op, not a double-transition error). 2000+ tournaments — verify list pagination and status-tab counts stay accurate (large-site checklist).

#### MV-TRN-014 — pro-tournament block (single tournament embed)
- **Edition:** Pro
- **Who:** Editors/admins placing it; visitors viewing.
- **Where:** Block editor, `mvs/pro-tournament`, attribute `tournamentId` (default 0).
- **Setup:** `mvs_tournaments_enabled` = 1; a specific tournament id.
- **Steps:** 1. Insert the block, set `tournamentId` in the inspector. 2. Publish and view. 3. Deep-link with `?mvs_tournament_id=X` and confirm it opens the same view (per the runbook's explicit pass criterion for this block).
- **Expected:** Renders the bracket + registration/match UI for that one tournament, identical to the deep-linked frontend view.
- **UX expectation:** `tournamentId` = 0 or an invalid id shows a clear empty/placeholder state in the editor and on the frontend, not a fatal.
- **Settings that change it:** `mvs_tournaments_enabled` off → admin sees a notice, visitors see nothing (per the runbook's documented off-state convention for this exact block, P23.5.1).
- **Edge cases:** Block on a page alongside a `pro-tournaments-list` block referencing the same data — confirm no state collision between two Interactivity API instances on one page.

#### MV-TRN-015 — pro-tournaments-list block
- **Edition:** Pro
- **Who:** Editors/admins; visitors.
- **Where:** Block editor, `mvs/pro-tournaments-list` (no attributes).
- **Setup:** `mvs_tournaments_enabled` = 1; several tournaments across statuses.
- **Steps:** 1. Insert the block. 2. Publish and view.
- **Expected:** Lists active and upcoming tournaments with status badges, start dates, and entry CTAs (per the block's own description).
- **UX expectation:** Off-state (`mvs_tournaments_enabled` = '0') shows an admin-only notice in the editor; visitors on the published page see an empty result, not an error (P23.5.2's explicit pass criterion).
- **Settings that change it:** `mvs_tournaments_enabled`.
- **Edge cases:** Zero qualifying tournaments (empty list, not broken markup).

#### MV-TRN-016 — Master/sub toggle off behavior
- **Edition:** Pro
- **Who:** Admin toggling; all visitors affected.
- **Where:** Settings → Competitions tab; affects `/media/tournaments/`, admin menu, REST routes, and both blocks.
- **Setup:** Working tournaments feature, then flip `mvs_tournaments_enabled` off, then the master `mvs_competitions_enabled` off.
- **Steps:** 1. Turn off `mvs_tournaments_enabled` only, check `/media/tournaments/`, admin menu, REST routes. 2. Turn off the master too, check the Compete hub.
- **Expected:** Same pattern as CHL-015: sub-toggle off removes the "Tournaments" admin submenu and the frontend route/REST routes (404, not empty page); master off cascades and hides the Tournaments card entirely on the Compete hub (Journey P1.5's explicit pass criterion, tested against "Tournaments" by name in the runbook).
- **UX expectation:** No dead links. Re-enabling must not lose or corrupt any in-progress bracket data.
- **Settings that change it:** `mvs_competitions_enabled`, `mvs_tournaments_enabled`.
- **Edge cases:** Toggling off mid-active-bracket (matches mid-voting) — verify the resolve/advance cron simply stops running for it while off, and correctly resumes on re-enable rather than fast-forwarding through missed deadlines unexpectedly.

#### MV-TRN-017 — Mobile 390px bracket rendering
- **Edition:** Pro
- **Who:** All visitors on mobile.
- **Where:** `/media/tournaments/` bracket view at 390px.
- **Setup:** An active multi-round tournament (8+ players) viewed at 390px.
- **Steps:** 1. Open the bracket on a 390px viewport. 2. Scroll horizontally through rounds.
- **Expected:** Matches Journey P20.2's documented pass: "Tournament bracket — horizontal scroll with fade-edge hint," and the tournament cards list is responsive per P4/P20 screenshots referenced in the runbook's findings table.
- **UX expectation:** The current round should be scrolled-into-view by default (not requiring the visitor to manually scroll to find where the action is); fade-edge hint must clearly signal there's more bracket to scroll to.
- **Settings that change it:** None.
- **Edge cases:** A 64-slot, 6-round bracket at 390px — confirm horizontal scroll performance and that round labels stay legible/pinned.

#### MV-TRN-018 — Multi-actor concurrency (registration close, vote-after-round-closed)
- **Edition:** Pro
- **Who:** Multiple concurrent members/admins.
- **Where:** `generate_bracket()`'s transaction lock; `vote()`'s deadline check; `resolve_match()`'s status check.
- **Setup:** A tournament right at its registration deadline with a registration attempt racing the auto-bracket-generation tick; a match right at its vote deadline with a vote racing the auto-resolve tick.
- **Steps:** 1. Simulate a registration POST landing at the same moment `generate_bracket()` runs (or fire both back-to-back rapidly). 2. Simulate a vote landing at the same moment `resolve_expired_matches()` resolves that match.
- **Expected:** `generate_bracket()` takes `SELECT ... FOR UPDATE` on the competition row before snapshotting entries, so a registration that commits after the lock is taken is excluded from that bracket cleanly (not partially included, not corrupting the entry list) — this was an explicit fix (Basecamp 10068991206) for exactly this race. A vote submitted after the match has already flipped past `voting` status is rejected (`mvs_match_not_voting`) rather than accepted into a match that's already resolved. Two simultaneous votes for the same user on the same match are guarded by the `INSERT IGNORE` unique-key pattern, not a check-then-insert race.
- **UX expectation:** A member whose registration lands just after the cutoff should see a clear "registration closed" state, not a confusing "successfully registered" toast for a tournament they were actually excluded from. A vote rejected because the match already resolved should say the match is over, not a generic error.
- **Settings that change it:** None — this is a data-integrity guarantee, not a configurable behavior.
- **Edge cases:** Two admins simultaneously force-resolving the same match from two browser tabs (second attempt should hit the "not in voting phase" guard cleanly, not double-eliminate or corrupt `winner_entry_id`).

#### MV-TRN-019 — WP-CLI `wp mvs competitions tick` / `wp mvs competitions recompute`
- **Edition:** Pro
- **Who:** Server admin/developer via WP-CLI, not member-facing.
- **Where:** `wp mvs competitions tick`, `wp mvs competitions recompute` — shared infrastructure registered by `CompetitionsScheduler`, not tournament-specific, but two of its six transition hooks are tournament-owned (`mvs_start_registered_tournaments`, `mvs_resolve_expired_matches`).
- **Setup:** A tournament with a passed `registration_end` still in `registration` status, and/or a match with a passed `submit_deadline`/`vote_deadline` still open.
- **Steps:** 1. Run `wp mvs competitions tick` and confirm the registration-closed tournament generates its bracket (or auto-cancels if under 2 participants) and any expired matches resolve/advance. 2. Run `wp mvs competitions recompute` and confirm no error.
- **Expected:** Same shared `tick()` routine as CHL-017 — fires all six hooks together in one manual pass, matching what the 5-minute Action Scheduler cadence does automatically; per-hook try/catch means a tournament-side failure doesn't block challenge/battle transitions in the same run.
- **UX expectation:** N/A (CLI-only). Output must be truthful about zero-eligible-row runs (no false "success" implying work was done when nothing was due).
- **Settings that change it:** None.
- **Edge cases:** Running `tick` on a site with `mvs_tournaments_enabled` off (hook fires, but no tournament listener should act — verify no fatal, no phantom transition on data that shouldn't be touched while the feature is disabled).

---

**Notes on scope/verification confidence:**
- Both features are entirely unaffected by license state (updates-only license); the single Documents write-gating exception does not apply to CHL or TRN.
- Challenges have a real four-email `wp_mail()` layer (creator/entrant/winner/participant) PLUS three in-app notification types; Tournaments have only two in-app notification types and no email layer at all — this asymmetry is verified from code, not assumed, and should not be "fixed" without a product decision.
- Vote uniqueness in both features is per-entry/per-match, not per-competition — a member can vote for multiple different challenge entries in the same challenge, which is a small but real contradiction of the phrase "one vote per round" appearing elsewhere in QA notes.
- `wp mvs competitions tick`/`recompute` are shared across Battles/Challenges/Tournaments, not owned by either area alone.
- Items marked "Check:" were not traced to a definitive code answer in this pass and should be confirmed in the browser/DB before being scored as pass or fail.

### Area: BST

#### MV-BST-001 — Boost a media item (spend points, cost calculation)
- **Edition:** Pro
- **Who:** Any logged-in member who owns the media; refused for non-owners and logged-out users (401/400 `mvs_boost_invalid_media`)
- **Where:** `POST /wp-json/mvs-pro/v1/boosts` (media single page / Instagram feed card "Boost" button)
- **Setup:** `mvs_competitions_enabled=1` AND `mvs_boosts_enabled=1` (Settings > MediaVerse > Gamification tab, `wp-admin/admin.php?page=mvs-settings#gamification`), free WB Gamification plugin active, member owns an entrable media item, has enough points
- **Steps:** 1) As owner, open single-media page. 2) Click "Boost" (visible only if `BoostAffordance::is_available()`). 3) Modal opens: slider 100-5000 impressions (step 100, default 500). 4) Cost preview updates live (`cost_per_100` x ceil(target/100)). 5) Click "Boost".
- **Expected:** Row inserted into `wp_mvs_boosts` with status `active`, points debited via `WBGam\Engine\PointsEngine::debit()`, `expires_at` = now + `mvs_pro_boost_expiry_days`. A second boost attempt on the same still-active media returns 409 `mvs_boost_already_active` ("This media already has an active boost."). Target under 100 → 400 "Minimum 100 impressions." Target over the admin's max → 400 "Maximum %d impressions per boost." Insufficient points → 400 "Need %1$d points, you have %2$d."
- **UX expectation:** Modal is `role="dialog" aria-modal="true"`, traps focus (`focusBoostModal`) and closes on Escape (`onBoostModalKeydown`); close button has `aria-label="Close"`. Loading state shows "Loading..." while balance fetches. Boost button disables (`!state.canAfford`) when balance can't cover the slider value — member should never be able to submit a boost they can't afford. On submit the button swaps to "Boosting..." Success shows "Boost activated!" inline (not a toast/alert); failure shows "Boost failed." or "Network error." in the same message area, styled error vs success via a CSS class swap, not a page reload. Must NOT be double-spendable: a second click while `state.creating` is true must be inert (button disabled during the request) — verify no double-debit on a fast double-click.
- **Settings that change it:** "Points per 100 Impressions" (`mvs_pro_boost_cost_per_100`, default 50), "Max Impressions per Boost" (`mvs_pro_boost_max_impressions`, default 5000), "Boost Expiry (Days)" (`mvs_pro_boost_expiry_days`, default 7) — all under Boost Pricing, shown only when Media Boosts is enabled.
- **Edge cases:** WB Gamification plugin absent → button never renders (`points_backend_available()` false); if hit directly via REST anyway, 503 `mvs_boost_gamification_unavailable`. Media that is a Document (not eligible) → 400 `mvs_boost_invalid_media` since boosts only target Explore-feed-entrable media. Debit succeeds but the boost row insert fails → a compensating refund event fires (`mvs_boost_refund`); if THAT also fails it only logs to `error_log`, no member-visible remediation — flag as a real support gap, not a UI bug. 390px: modal must remain full-width/scrollable, slider and Cancel/Boost buttons stacked.

#### MV-BST-002 — Boost expiry, impression tracking, and Explore feed promotion
- **Edition:** Pro
- **Who:** System (Action Scheduler hourly cron) + any viewer of the Explore feed
- **Where:** Hourly cron calling `BoostService::expire_boosts()`; `mvs_feed_media_ids` filter promoting boosted items in the Explore feed REST response
- **Setup:** An active boost exists with `impressions_target` and `expires_at` set
- **Steps:** 1) Load Explore feed repeatedly as different viewers so impressions accumulate. 2) Wait for/force the hourly boost-expiry cron. 3) Also force impressions_delivered >= impressions_target via repeated feed loads.
- **Expected:** Boost promoted to front of feed results (top 5 active boosts). Each feed view that shows a boosted item increments `impressions_delivered` (batched via object cache when Redis/Memcached present, else one UPDATE per feed request). When `impressions_delivered >= impressions_target`, status flips to `completed` even before `expires_at`. When `expires_at` passes with status still `active`, the hourly cron flips it to `expired`.
- **UX expectation:** There is no member-facing "boost ended" notification in code — status change is silent/backend-only; the owner discovers it only by re-opening the Boost UI (no active boost = plain "Boost" button again, no history/list UI wired to `GET /boosts` in any frontend surface found). This absence is worth flagging to product, not assuming a notice exists.
- **Settings that change it:** "Boost Expiry (Days)" (`mvs_pro_boost_expiry_days`).
- **Edge cases:** Object-cache flush race — the read-then-delete on the cached impression counter is not atomic (documented `ponytail:` comment in code); an increment landing in that gap is lost, acceptable for a progress metric only. Verify a completed/expired boost never re-promotes after expiry.

#### MV-BST-003 — Boost affordance (owner-only "Boost" control) across surfaces
- **Edition:** Pro
- **Who:** Media owner only; hidden entirely for non-owners, logged-out visitors, or when unavailable
- **Where:** Single-media social bar (`mvs_media_single_actions` hook) and Instagram feed card
- **Setup:** Boosts available (see MV-BST-004)
- **Steps:** 1) View your own media as owner — button shows. 2) View someone else's media — button absent. 3) View your own media with boosts unavailable (toggle off, or WB Gamification absent) — button absent.
- **Expected:** Button has `aria-label="Boost this media"` and a tooltip "Boost"; enqueues the boosts Interactivity store and modal once per page regardless of how many boost buttons appear (`did_action` guard on `mvs_boosts_store_enqueued`).
- **UX expectation:** No broken shell when unavailable — the button and its container simply do not render (no disabled ghost button, no error state).
- **Settings that change it:** none directly — gated by MV-BST-004's dependencies.
- **Edge cases:** Two boost buttons on one page (feed card + single view, unlikely but check no duplicate stores/modals mount).

#### MV-BST-004 — Boosts master-switch and points-backend dependency
- **Edition:** Pro
- **Who:** Site owner (Settings) + members (frontend effect)
- **Where:** `wp-admin/admin.php?page=mvs-settings#gamification`
- **Setup:** none
- **Steps:** 1) Leave `mvs_competitions_enabled` OFF, turn `mvs_boosts_enabled` ON via direct option update (its settings row is hidden in the UI while the master switch is off) — verify `competition_feature_on('boosts')` still returns false. 2) Turn master ON, leave Media Boosts OFF — verify still false. 3) Turn both ON — verify true. 4) With both ON, deactivate/rename WB Gamification so `points_backend_available()` is false — verify the Boost button disappears even though both switches are on.
- **Expected:** Boosts require BOTH `mvs_competitions_enabled=1` AND `mvs_boosts_enabled=1` — the same master + sub-toggle pattern as Battles/Challenges/Tournaments. `points_backend_available()` is a third, independent gate specific to boosts and streak-freezes only.
- **UX expectation:** When the master switch is off, the admin settings screen shows ONLY the master checkbox row — the Media Boosts / Battles / Challenges / Tournaments rows are not rendered at all (not just disabled), per the "off is off, no half-broken UI" product rule. Stored sub-toggle values are preserved (not reset to 0) while hidden, so re-enabling the master restores prior choices.
- **Settings that change it:** "Competitions" master (`mvs_competitions_enabled`), "Media Boosts" (`mvs_boosts_enabled`).
- **Edge cases:** Confirm no REST route, admin menu, or template registers when master is off (per the "should not be able to tell the feature exists" design comment).

#### MV-BST-005 — Upload streak counting, day-to-day
- **Edition:** Pro
- **Who:** Any logged-in member who uploads media
- **Where:** Fires internally on `mvs_media_uploaded`; visible via streak widget on `/my-media/` dashboard and `GET /mvs-pro/v1/me/streak`
- **Setup:** `mvs_streaks_enabled=1`
- **Steps:** 1) Upload on day 1 — streak becomes 1. 2) Upload again same day — no change (guarded: `$last_date === $today` returns early). 3) Upload next consecutive day — streak increments. 4) Skip a day, then upload — see MV-BST-006 for freeze logic; without freezes, streak resets to 1.
- **Expected:** `_mvs_current_streak` and `_mvs_longest_streak` user meta update correctly; milestone check fires at exactly 7/30/100/365 days.
- **UX expectation:** No client-visible feedback at upload time (no "streak +1" toast in the upload flow itself) — the only surfacing is the dashboard widget and the display-name badge, both re-rendered on next page load, not live-pushed. Nothing should silently corrupt `_mvs_last_upload_date` — verify multiple uploads in one day never inflate the streak.
- **Settings that change it:** "Enable Streaks" (`mvs_streaks_enabled`).
- **Edge cases:** First-ever upload (no prior `_mvs_last_upload_date`) sets streak to 1, not 0. Uploading exactly at local-vs-UTC day boundary (uses `wp_date('Y-m-d')`, site timezone) — test near midnight.

#### MV-BST-006 — Streak freeze: gap-bridging on upload, and the daily cron reset
- **Edition:** Pro
- **Who:** System (both the upload handler and the Action Scheduler daily check)
- **Where:** `on_upload()` (gap bridged retroactively when the next upload arrives) and `daily_check()` (proactive, runs daily at 02:00 site time)
- **Setup:** `mvs_streak_freezes_enabled=1`, member has `_mvs_streak_freezes` > 0
- **Steps:** 1) Build a streak. 2) Miss exactly one day with freezes enabled and >=1 freeze available — next day's `daily_check()` run should consume exactly one freeze and back-date `_mvs_last_upload_date` to yesterday, preserving the streak. 3) Repeat with freezes disabled or freezes = 0 — streak resets to 0, `_mvs_longest_streak` untouched.
- **Expected:** Gap-bridging charges ONE freeze per missed day (fixed in 2.6.0 — a 5-day gap now costs 5 freezes via `on_upload()`, not 1). The recurring daily cron processes in keyset-paginated batches of 100, capped at 2000 users per tick, continuing async beyond that — verify on a large user base this does not time out or skip users.
- **UX expectation:** Nothing prompts the member mid-gap; the freeze consumption is entirely silent/backend, discovered only by checking the widget's freeze count after the fact or by the streak simply continuing. Confirmed: no "Freeze used" notification exists anywhere in this plugin's code, and none of MediaVerse's own docs promise one either — this is not a defect, it is the intended silent behavior. Check: if a QA runbook elsewhere expects a "Freeze used" notification, that expectation is not something this plugin (or its docs) has ever committed to; treat it as an aspirational note, not a bug to fix.
- **Settings that change it:** "Allow Streak Freezes" (`mvs_streak_freezes_enabled`, default off).
- **Edge cases:** Freezes enabled but balance = 0 → streak resets exactly like freezes disabled. A multi-day gap that exceeds available freezes → resets (no partial credit).

#### MV-BST-007 — Buy a streak freeze token
- **Edition:** Pro
- **Who:** Logged-in member
- **Where:** `POST /wp-json/mvs-pro/v1/streaks/buy-freeze`; streak widget "Buy Freeze (%d pts)" button on `/my-media/`
- **Setup:** `mvs_streak_freezes_enabled=1` AND WB Gamification present (button hidden otherwise) AND balance >= freeze cost
- **Steps:** 1) Open dashboard streak widget. 2) Click "Buy Freeze (N pts)". 3) Confirm freeze count increments and balance decreases by cost.
- **Expected:** Points debited via `PointsEngine::debit()`; `_mvs_streak_freezes` incremented atomically (`UPDATE ... SET meta_value = meta_value + 1`, avoids lost-update on concurrent buys); user-meta cache explicitly cleared after the raw SQL update.
- **UX expectation:** Button shows "Buying..." while in flight (`state.buying`, disables the button). Success: "Freeze token purchased!" Failure paths: insufficient points → "Need %1$d points, you have %2$d." (400); backend absent → "Points system is unavailable, so streak freezes cannot be purchased right now." (503, should be unreachable since the button itself is hidden); deduction failure → "Could not deduct points for this freeze. Please try again." Must NOT grant a freeze without a successful debit (was a real prior bug, since fixed — verify the fix holds: an artificially failed debit must not still increment the freeze count).
- **Settings that change it:** "Freeze Cost (Points)" (`mvs_pro_streak_freeze_cost`, default 100).
- **Edge cases:** Two rapid clicks (double-buy) — the atomic increment prevents lost updates but does NOT prevent two successful purchases at double cost if the button isn't disabled fast enough; verify `state.buying` actually blocks a second click before the first response returns.

#### MV-BST-008 — Streak feature toggle OFF: full behavior
- **Edition:** Pro
- **Who:** Site owner
- **Where:** `wp-admin/admin.php?page=mvs-settings#gamification`, "Upload Streaks" section
- **Setup:** `mvs_streaks_enabled=0` (the default)
- **Steps:** 1) With streaks off, upload media as a member on several consecutive days. 2) Check `/my-media/` dashboard. 3) Check `GET /mvs-pro/v1/me/streak`. 4) Check display name anywhere (activity, comments, leaderboard).
- **Expected:** In 2.6.0, `StreakService::init()` exits immediately when the toggle is off: `on_upload` is never hooked, so streak counts do NOT accumulate while off (not merely hidden — genuinely not tracked), and the daily cron is never scheduled. The widget partial and display-name badge filter both independently short-circuit on the same option, so no UI trace appears either. `GET /me/streak` still works (not gated) and returns whatever meta values existed from a time when streaks were previously on, plus `enabled: false`.
- **UX expectation:** Turning streaks back on later resumes counting from scratch on the next upload (old `_mvs_last_upload_date` may be stale, so the very next upload could either extend or reset depending on how much time passed — confirm this doesn't look buggy to a returning member).
- **Settings that change it:** "Enable Streaks" (`mvs_streaks_enabled`).
- **Edge cases:** A member with an existing streak when the owner turns streaks OFF, then back ON later — verify no silent progress is invented or lost in a confusing way.

#### MV-BST-009 — Streak badge next to display name
- **Edition:** Pro
- **Who:** Any viewer, anywhere a member's display name renders (activity, comments, explore grid, leaderboard)
- **Where:** `mvs_user_display_name` filter, `Core/Plugin.php`
- **Setup:** `mvs_streaks_enabled=1`, member has `_mvs_current_streak > 0`
- **Steps:** 1) View a member's name in the activity feed, explore grid, and comments while they have an active streak.
- **Expected:** Flame icon + streak count appended inline, e.g. a span with both `title` and `aria-label` set to "%d day streak". No duplicate badge when the same name renders twice on one page.
- **UX expectation:** Confirmed the old QA finding is stale, not a live bug: the badge has exactly ONE render path in the whole codebase (a single `mvs_user_display_name` filter callback), and that one implementation sets both `title` and `aria-label` unconditionally — there is no second/different badge markup anywhere that could lack it. Note for testers: this filter is only applied by `get_display_name()`, which by its own docblock is meant for the single-media page author header and the lightbox sidebar; compact surfaces (explore grid, profile lists) intentionally use `get_display_name_plain()` instead, which strips all HTML — so the streak badge does not appear on grid cards at all, by design, not as a bug.
- **Settings that change it:** "Enable Streaks."
- **Edge cases:** Badge inside a BuddyPress activity item must not break BP's layout. 390px: badge must not force name-wrapping into an unreadable line.

#### MV-BST-010 — Streak milestone XP awards (7/30/100/365 days)
- **Edition:** Pro
- **Who:** Member who reaches a milestone
- **Where:** `StreakService::check_milestones()`, fires `mvs_streak_milestone`, recorded via Free's activity service
- **Setup:** Streaks on; WB Gamification present for the XP to actually apply (otherwise the activity note still posts, just with no points behind it)
- **Steps:** 1) Reach exactly 7 consecutive days. 2) Confirm an activity item posts: "Reached a 7-day upload streak! +50 points". 3) Repeat at 30 (250), 100 (1000), 365 (5000).
- **Expected:** XP amounts are fixed constants (50/250/1000/5000), NOT admin-configurable (no setting found for these — unlike battle/challenge/tournament XP which ARE configurable). `CompetePointsBridge` reads `xp_bonus` from event metadata to make WB Gamification award this exact scaled amount rather than its own flat default.
- **UX expectation:** Activity-recording failure (e.g., BuddyPress/activity service exception) is caught and only logged — streak data itself is already saved, so a member never loses milestone credit even if the celebratory post fails to appear. Verify no duplicate milestone post if `check_milestones` is somehow called twice for the same day.
- **Settings that change it:** none (fixed constants). Confirmed intentional and matches the customer docs exactly, which list the same four fixed amounts (50/250/1000/5000) with no mention of an admin control — not a missing setting.
- **Edge cases:** Milestone reached exactly on a freeze-bridged day (gap covered by a freeze) — confirm the milestone still fires off the resulting `current_streak` value.

#### MV-BST-011 — Leaderboard display (block + REST): sources and time windows
- **Edition:** Pro
- **Who:** Everyone, including logged-out visitors (public endpoint)
- **Where:** `mvs/pro-leaderboard` block (any page/post); `GET /wp-json/mvs-pro/v1/leaderboard`
- **Setup:** None required — leaderboard has NO owner enable/disable toggle found anywhere in code; it is always active once Pro is installed (always-on, not gated by competitions or gamification for `reactions`/`media_count` sources)
- **Steps:** 1) Insert the Leaderboard block. 2) Set Source to "Reactions" (default), "Media Count," or "Gamification Points." 3) Set Window to "All Time" (default), "30 Days," or "7 Days." 4) View as a logged-out visitor and as a member.
- **Expected:** Rows show rank, avatar (linked to profile), display name, score, and a metric label ("reactions" / "uploads" / "Points"). Rankings for `reactions`/`media_count` count ONLY public, approved, published media — private/members-only media and Documents never inflate a public score. `gamification_xp` source returns empty rows/total 0 automatically when WB Gamification is absent (`Plugin::has_gamification()` check) — no error, just an empty board.
- **UX expectation:** Empty state text: "No leaders to display yet — get some reactions on your uploads!" Rank numbers render inside a `<ul style="list-style:none">`, deliberately NOT an `<ol>`, specifically to prevent theme CSS that auto-numbers ordered lists from double-numbering ranks — check this specifically on a theme that styles `<ol>` (e.g., a default WP theme's content area).
- **Settings that change it:** none for visibility; `mvs_pro_leaderboard_cache_ttl` filter (default 300s) controls both the page cache and the viewer's-own-rank cache.
- **Edge cases:** `gamification_xp` window filtering is entirely delegated to `mvs_pro_leaderboard_xp_rows` (external filter) with no offset/total contract of its own — confirm pagination degrades sanely (page 1 only meaningfully paginated). 390px: block should stack avatar/name/score without horizontal scroll.

#### MV-BST-012 — Leaderboard viewer's own rank
- **Edition:** Pro
- **Who:** Logged-in viewer (anonymous gets `viewer_rank: null`)
- **Where:** Same `GET /leaderboard` response, `viewer_rank`/`viewer_score` fields
- **Setup:** Viewer has at least one ranked action (reaction received or upload) for `reactions`/`media_count` sources
- **Steps:** 1) As a member NOT in the visible top page, call the leaderboard endpoint. 2) Confirm `viewer_rank` reflects their true position even though they're off-page.
- **Expected:** Cached per (source, window, user) in the OBJECT CACHE only (`wp_cache_*`), deliberately never a transient — the code comment explains this is to avoid one `wp_options` row per user/source/window combination on a large member base (big-site readiness already handled here). `gamification_xp` source never returns a viewer rank (returns null/0) since that's owned by the external gamification provider.
- **UX expectation:** No visible loading flicker expected — rank appears alongside the board in the same response.
- **Settings that change it:** `mvs_pro_leaderboard_cache_ttl`.
- **Edge cases:** Site with no persistent object cache (default WP transient-less object cache is per-request only) — verify the viewer-rank query re-runs every request rather than silently caching nothing (acceptable) vs erroring.

#### MV-BST-013 — Gamification Settings admin page: full Boosts/Streaks field inventory
- **Edition:** Pro
- **Who:** Administrator (or any role with `manage_options`/whatever capability gates Settings access)
- **Where:** `wp-admin/admin.php?page=mvs-settings#gamification` (rendered as "Competitions tab in Settings")
- **Setup:** None
- **Steps:** Walk every field and its conditional visibility per the `show_when` dependency chain: Competitions (master) -> Photo Battles / Photo Challenges / Tournaments / Media Boosts (only visible when master ON) -> Battle win reward (only when Battles ON) -> Boost Pricing section (only when Boosts ON: Points per 100 Impressions, Max Impressions per Boost, Boost Expiry Days) -> Upload Streaks section (independent of the competitions master): Enable Streaks -> Allow Streak Freezes (only when Streaks ON) -> Freeze Cost (only when Freezes ON).
- **Expected:** Every toggle saves and persists on reload; hidden rows retain their stored value even while hidden (the stored value survives; only the row is withheld). Defaults: everything OFF except numeric costs which default to sane non-zero values (50/5000/7/100) so turning a feature on doesn't start at $0 cost or unlimited impressions.
- **UX expectation:** An owner running only Streaks (no competitions at all) still gets the "Active Streaks" panel on the Competitions Dashboard admin screen — deliberately NOT gated behind the competitions master switch.
- **Settings that change it:** self-referential — this IS the settings page.
- **Edge cases:** Toggling the master switch off with sub-features already on, save, reload, turn master back on — sub-feature checkboxes must show their PRE-existing state, not reset to unchecked. 390px: settings form fields and conditional show/hide JS must not break on mobile admin.

#### MV-BST-014 — WB Gamification points bridge (present vs absent)
- **Edition:** Pro
- **Who:** System — always wired regardless of competitions/streaks toggles
- **Where:** `Gamification/CompetePointsBridge`, hooks WB Gamification's `wb_gam_points_for_action` filter
- **Setup:** Two states to test: (a) WB Gamification NOT installed, (b) WB Gamification installed and active
- **Steps:** (a) With WB Gamification absent, confirm nothing errors anywhere in Boosts/Streaks/Battles/etc. — points-spending features simply hide their UI and 503 if forced. (b) With it installed, reach a streak milestone or win a battle/challenge/tournament and confirm the AWARDED amount matches the owner-configured value, not WB Gamification's own flat default.
- **Expected:** `resolve_points()` only overrides for MediaVerse's own action IDs (`mvs_challenge_winner`, `mvs_challenge_participate`, `mvs_tournament_round_win`, `mvs_tournament_win`, `mvs_battle_win`, `mvs_streak_milestone`); every other action passes through untouched.
- **UX expectation:** No visible surface of its own — pure backend correctness item; test by comparing the number a member's activity/notification says they earned against what actually posts to their WB Gamification balance.
- **Settings that change it:** indirectly, every XP-amount setting on Boosts/Streaks/Battles/Challenges/Tournaments feeds this bridge's resolution.
- **Edge cases:** WB Gamification installed but an action fires with missing/malformed metadata — bridge falls through and returns the original flat `$points`, never zero or an error.

#### MV-BST-015 — Mobile / 390px for Boosts, Streaks, Leaderboard
- **Edition:** Pro
- **Who:** Any member on a phone-width viewport
- **Where:** Boost modal, streak widget, leaderboard block, streak badge in feed/activity
- **Setup:** Resize browser to 390px or use device emulation
- **Steps:** Repeat MV-BST-001 (boost modal), MV-BST-005/007 (streak widget), MV-BST-011 (leaderboard block) at 390px.
- **Expected/UX expectation:** Boost modal fits viewport without horizontal scroll, Cancel/Boost stack or fit on one row with adequate tap targets; streak widget milestone markers wrap rather than overflow; leaderboard rows stack avatar/name/score cleanly; streak badge next to a name doesn't force awkward line-wrapping in tight card layouts (buttons must stay >=40px).
- **Settings that change it:** none — pure CSS/responsive.
- **Edge cases:** Long display names + streak badge + long milestone label combos at 390px.

### Area: STY

#### MV-STY-001 — Enable / disable Stories
- **Edition:** Pro
- **Who:** Site owner / `manage_options`.
- **Where:** wp-admin > MediaVerse > Settings > Display > "Stories" section (moved from Competitions in 2.5.1; option key unchanged).
- **Setup:** Pro active. No licence requirement.
- **Steps:** 1. Open Settings > Display. 2. Find "Stories" section, tick "Enabled". 3. Save. 4. Confirm the section description text.
- **Expected:** Saves `mvs_stories_enabled` = `1`/`0` (string). Section intro: "Ephemeral 24-hour stories that members post and others view, with "seen by" receipts." Field description: "The stories bar shows only with the Instagram layout. In the mobile app, stories work with any layout."
- **UX expectation:** Standard WordPress Settings API save (full reload, admin "Settings saved" notice) — no AJAX toggle. Turning the checkbox off does NOT delete any currently-active story data (`is_story`/expiry meta untouched) — it only stops new REST writes/registration paths from being exercised going forward; re-enabling immediately shows previously-active (still-unexpired) stories again.
- **Settings that change it:** "Stories" checkbox (`mvs_stories_enabled`) — this entry itself.
- **Edge cases:** Toggle is deliberately NOT hidden when Layout != Instagram, specifically because it also drives REST routes, the moderation admin screen, and the mobile app's stories flag — turning it on with Layout = grid is a valid, supported configuration (app has stories; the website's stories bar does not auto-appear). This is documented in the field description itself, not just internal comments.

#### MV-STY-002 — Posting a story from an upload surface
- **Edition:** Pro (checkbox is rendered by Free but requires Pro active + the toggle on).
- **Who:** Any logged-in member who can upload media.
- **Where:** Every surface using the media-upload block (not only a dedicated Upload page).
- **Setup:** Pro active, `mvs_stories_enabled` = 1.
- **Steps:** 1. Open any upload surface. 2. Confirm the checkbox "Also share as a story (visible for 24 hours)" appears next to the tag input. 3. Select an image/video/audio file, tick the checkbox, submit. 4. Try uploading a document type with the checkbox ticked (if documents are enabled).
- **Expected:** On success, the media is created AND marked as a story with a 24h expiry in one action — no second confirmation step. The checkbox does not appear at all on a Free-only site (Pro inactive) or when the Stories toggle is off — never shown-then-broken.
- **UX expectation:** If a non-story-eligible type were ever POSTed as a story (defense in depth, should not be reachable via this UI since the uploader restricts accepted types), the REST layer refuses with: "Only photos, videos and audio can be shared as a story." (400, not a silent success). Checkbox state is not lost/reset by other form interactions (e.g. changing privacy or tags) before submit.
- **Settings that change it:** "Stories" toggle (this entry) gates the checkbox's presence entirely.
- **Edge cases:** Checkbox pre-checks itself when arriving via `?mvs_story=1` (from the "Your story" tile) — display-only pre-fill, not itself an action; verify unchecking it still uploads normally without a story.

#### MV-STY-003 — "Your story" add tile (post directly from the stories bar)
- **Edition:** Pro
- **Who:** Logged-in members only (anonymous visitors never see this tile).
- **Where:** The `mvs/pro-stories` block's rendered bar, first position, before any other stories.
- **Setup:** Stories enabled; viewer logged in.
- **Steps:** 1. Load a page containing the Stories Bar block while logged in. 2. Click/tap the "Your story" tile (a labeled file input). 3. Pick an image.
- **Expected:** Picking a file uploads it and posts it as a story in place — no navigation to the upload page, no second step.
- **UX expectation:** While posting, the tile shows a spinner in place of the "+" badge (`context.posting` toggles both), and the visible label switches from "Your story" to "Posting…". Confirmed: after a successful post, the mechanism is a full browser page reload (`window.location.reload()`), not a targeted DOM insert or an Interactivity-API re-render — so testers should expect the whole page to reload, briefly showing the reload flash, rather than the story appearing to slide into the bar. Anonymous visitors see no tile and no bar at all when there are zero active stories in their would-be network (the whole block renders nothing, not an empty shell).
- **Settings that change it:** "Stories" toggle (this entry).
- **Edge cases:** If posting fails (network error, invalid file), the tile must return to its normal state and not get stuck showing "Posting…" indefinitely. Confirmed by code: the reset (`ctx.posting = false`) runs unconditionally at the end of the function outside the try/catch, covering both a thrown network error and a non-exception failure (e.g. the server rejecting the file) — every failure path returns the tile to normal.

#### MV-STY-004 — Stories bar auto-display on the Instagram layout
- **Edition:** Pro
- **Who:** Any visitor (logged-in members see more/different stories per privacy).
- **Where:** Explore and profile pages when Layout = Instagram.
- **Setup:** Layout = Instagram, Stories enabled, at least one followed author (or self) has an active story.
- **Steps:** 1. Set Layout to Instagram and Stories on. 2. Visit Explore. 3. Confirm the stories bar renders above the feed. 4. Turn Stories off and reload. 5. Turn Layout to a non-Instagram skin with Stories still on and reload.
- **Expected:** With Stories on + Instagram layout: bar renders (real `mvs/pro-stories` block output, not a placeholder "recent uploaders" list — that legacy version was replaced). With Stories off: nothing renders in that slot, no empty gap or leftover heading. With Layout != Instagram: the auto-embedded bar does NOT appear on the website (by design — the Instagram feed template is the only template that includes it automatically), even though Stories is fully on.
- **UX expectation:** The transition between these three states must never show a broken/half-rendered bar (e.g., avatars with no images, a "0 stories" placeholder) — it's either the real bar or nothing.
- **Settings that change it:** "Layout" and "Stories" together (both must be right for the auto bar to show).
- **Edge cases:** See MV-STY-005 for the "block can still be placed manually on any layout" nuance — don't confuse "no auto-embed" with "cannot be used."

#### MV-STY-005 — Stories bar manually placed on a non-Instagram layout / any page
- **Edition:** Pro
- **Who:** Site owner (places the block); any visitor (views it).
- **Where:** Any page/post via the block editor, regardless of the site's global Layout setting.
- **Setup:** Stories enabled. Insert the "Stories Bar" block (`mvs/pro-stories`) on a page while the site-wide Layout is Pinterest/Flickr/Dribbble/grid.
- **Steps:** 1. Insert the Stories Bar block on any page. 2. View the page on the front end regardless of global Layout. 3. Confirm stories render exactly as they would on Instagram.
- **Expected:** The block works identically no matter what the global feed layout is — the "Instagram only" language in the settings description refers to the AUTOMATIC embed inside the Instagram feed template, not a functional restriction on the block itself. REST routes (`/stories`, view/viewers), the moderation admin screen, and the app's stories flag are always active whenever the Stories toggle is on, independent of layout.
- **UX expectation:** No warning or degraded rendering should appear because the layout "doesn't match" — there is no such coupling in the block itself.
- **Settings that change it:** "Stories" toggle only; "Layout" has no effect on this manually-placed block.
- **Edge cases:** A grid-layout site with Stories on has a fully working stories feature in the mobile app even though the website shows no bar anywhere unless the owner manually adds the block — this is the explicit design intent called out in the setting's own description text, not an oversight to "fix."

#### MV-STY-006 — Viewing a story (image)
- **Edition:** Pro
- **Who:** Any logged-in viewer who can see the media per its privacy (own stories always visible to self).
- **Where:** Fullscreen story viewer opened from the stories bar.
- **Setup:** At least one active image story from a followed author (or self).
- **Steps:** 1. Click a story avatar in the bar. 2. Observe the viewer opening fullscreen. 3. Wait for auto-advance or click the prev/next chevrons. 4. Close via the X button.
- **Expected:** Viewer opens as a real dialog (`role="dialog"`, `aria-modal="true"`), landscape/square images fill the viewer (not capped to a narrow strip). A segmented progress bar shows one segment per story in the current author's set, animating across the active segment.
- **UX expectation:** Header shows author avatar, name, and a human "x ago" time (derived from story start ≈ expiry minus 24h). Close button has an accessible label ("Close stories"); prev/next chevrons are labeled "Previous story"/"Next story" for screen readers. Opening the viewer records a view (`POST /stories/{id}/view`) the first time only — reopening the same still-active story as the same viewer should not spam duplicate view rows (server dedupes by viewer+media via the DISTINCT-based viewer count, so double-counting isn't visible even if the client fires twice).
- **Settings that change it:** none beyond Stories being on.
- **Edge cases:** Author's own view of their own story is never recorded ("seen by" counts the audience, not the owner) — verify posting then immediately viewing your own story does not increment your own "seen by" count.

#### MV-STY-007 — Viewing a story (video / audio)
- **Edition:** Pro
- **Who:** Any logged-in viewer with access.
- **Where:** Fullscreen story viewer, video/audio story.
- **Setup:** An active video and an active audio story.
- **Steps:** 1. Open a video story. 2. Confirm autoplay starts muted with visible native controls. 3. Unmute via the native control. 4. Let it play to the end (or advance manually). 5. Repeat for an audio story.
- **Expected:** Video/audio autoplay muted (browsers always allow muted autoplay); controls let the viewer unmute. If autoplay is refused even muted, the viewer falls back to a timer-driven auto-advance (`avTimed`) instead of stalling on a paused/frozen frame. Progress bar segment fills in sync with actual playback time (`timeupdate`), not a fixed clock, for video/audio (vs. a fixed timer for images).
- **UX expectation:** If the member had previously unmuted an earlier video in the same session, the element intentionally stays unmuted for the next one UNLESS the browser refuses that without a fresh user gesture, in which case it retries muted rather than stalling silently — the viewer should never appear "stuck" with no visible progress.
- **Settings that change it:** none.
- **Edge cases:** Audio-only story: the stage should visually indicate audio mode (a distinct class/state) rather than showing a blank image area with no visual anchor.

#### MV-STY-008 — Dialog focus-trap and keyboard behavior
- **Edition:** Pro
- **Who:** Keyboard/screen-reader users specifically (accessibility-focused pass).
- **Where:** Fullscreen story viewer.
- **Setup:** At least two active stories to test navigation.
- **Steps:** 1. Open a story with keyboard/mouse. 2. Press Tab repeatedly. 3. Press Escape. 4. Reopen; click into the native video/audio controls, then press Escape while focus is inside them. 5. Press ArrowLeft/ArrowRight. 6. Test the same in an RTL locale.
- **Expected:** Tab wraps between the first and last visible focusable control inside the dialog only (never escapes to the underlying page). Escape closes the viewer from anywhere, INCLUDING while focus is inside the native video/audio controls (captured in the capture phase specifically because native media controls otherwise swallow Escape and reroute it to seeking). ArrowLeft/ArrowRight step to the previous/next story, with direction flipped under RTL.
- **UX expectation:** On open, focus moves to the Close button once the dialog is actually visible (not before the `hidden` attribute is removed, avoiding a focus-on-invisible-element bug). On close, focus returns to the story-bar button/avatar that opened it — never left dangling on a removed/hidden element or reset to `<body>`.
- **Settings that change it:** none.
- **Edge cases:** Confirm Escape-while-focus-inside-native-controls specifically on both Chrome and Firefox/Safari, since native media control keyboard handling differs by browser and this is the exact edge case the capture-phase listener was added to catch.

#### MV-STY-009 — "Seen by" viewer counts (owner-only)
- **Edition:** Pro
- **Who:** Story owner (or `manage_mvs_settings`) only — anyone else is refused.
- **Where:** `GET /mvs-pro/v1/stories/{id}/viewers` (drives an app/UI surface). Confirmed: no website-facing "seen by" list exists anywhere in the frontend templates — only the admin Stories table's count column and the raw REST response surface this; a member cannot see who viewed their own story from the website itself.
- **Setup:** A story with at least one distinct viewer who is not the author.
- **Steps:** 1. As the story's author, request the viewers list (via whatever UI consumes it, or directly). 2. As a different, non-owning member, attempt the same request. 3. As an anonymous visitor, attempt it.
- **Expected:** Owner/admin gets a paginated list (`viewers`, `total`, `X-WP-Total`/`X-WP-TotalPages` headers) — distinct viewers, most-recently-viewed first, excluding the author's own views. A non-owner gets 401 ("You must be logged in.") if logged out, or, if logged in but not the owner/admin and unable to see the media, 404 ("Media not found.") rather than 403 — deliberately indistinguishable from a missing item so a refusal never confirms a private story exists. A logged-in non-owner who CAN see the media but isn't the owner gets 403 ("You can only manage your own stories.").
- **UX expectation:** The distinction between the 404-for-privacy and 403-for-ownership responses must be preserved by any UI built on this endpoint — showing a generic "not found" for the privacy case and a clear ownership message for the real 403 case, never conflating them into one generic error banner.
- **Settings that change it:** none.
- **Edge cases:** A viewer who viewed the story, then the story owner re-posts a NEW story later — the new story's "seen by" must not inherit the old viewer list (windowed by `story_started_at`, so a re-shared story starts its viewer count at zero).

#### MV-STY-010 — Story expiry and hourly cleanup cron
- **Edition:** Pro
- **Who:** No direct user action — background/system behavior; visible effect to any viewer.
- **Where:** WP-Cron hook `mvs_story_cleanup` (hourly). Confirmed: there is no on-site UI for a member to end their own story early — the only entry points are the raw `DELETE /media/{id}/story` REST route (API/app-only) and the admin's unrelated Force-expire button (moderator-only, any story, not owner-initiated).
- **Setup:** A story with a short duration (e.g. 1 hour) to observe expiry quickly, or manipulate the DB timestamp for a faster test.
- **Steps:** 1. Create a story with `duration_hours=1`. 2. Wait for expiry (or trigger the cron manually / adjust the timestamp). 3. Confirm the story disappears from the bar and admin list. 4. Confirm the underlying media item itself still exists (not deleted).
- **Expected:** Cron clears only the story meta (`is_story`, `story_started_at`, `story_expires_at`) — the media item, its comments/reactions/views, are untouched. On a large site with more than 2,000 stories expiring in the same hourly window, cleanup processes in bounded batches and hands off any remainder to an async continuation rather than running one huge unbounded pass.
- **UX expectation:** From the viewer's side, an expired story simply stops appearing in the bar on next load — no error, no "story expired" toast anywhere in the website code. Check: whether the mobile app surfaces an expiry notification cannot be settled from this repo (it's a separate app codebase) — verify directly in the app if that matters.
- **Settings that change it:** `duration_hours` is set per-story at creation (1-168 hours, default 24), not a global setting.
- **Edge cases:** Force-expiring a story from the admin (see MV-STY-011) must have the identical end-state as natural cron expiry — same meta cleared, same "gone everywhere immediately" behavior — verify these two paths don't diverge (e.g. one leaving stray meta the other cleans up).

#### MV-STY-011 — Admin moderation: Stories list + Force expire
- **Edition:** Pro
- **Who:** `manage_mvs_settings` capability only.
- **Where:** wp-admin > MediaVerse > Stories.
- **Setup:** Stories enabled; at least one active story to see the populated table, and zero to see the empty state.
- **Steps:** 1. Visit the Stories admin page with zero active stories. 2. Create a story, revisit the page. 3. Click "Force expire" on a row. 4. Confirm the destructive-action dialog. 5. Confirm the outcome.
- **Expected:** Empty state: "No active stories right now" / "Stories are ephemeral 24-hour posts members share from the app or the site. While a story is live it appears here, where you can see who has viewed it and force-expire it if needed." with a Posted → Viewed → Expires (24h) lifecycle line. Populated table: Author (avatar + name), Title (or "#<media id>" if untitled), Status badge "Active", Expires (formatted date/time), Seen by (count), and a "Force expire" row action. Clicking Force expire shows a confirm dialog: "End this story now? It will disappear for everyone immediately." with a destructive-styled "Force expire" confirm button. After confirming, the page reloads with a success notice "Story expired." and the row is gone.
- **UX expectation:** The confirm dialog's destructive tone (danger styling) must be visually obvious. Confirmed: Cancel is the default focus target — the Stories page uses the exact same shared confirm-dialog markup as Battle Monitor/Tournament Manager, where the Cancel button is the first focusable element in the dialog and neither button carries an `autofocus` override, so the browser's native `<dialog>` focus behavior lands on Cancel every time. A user without `manage_mvs_settings` who reaches the page URL directly gets `wp_die` with "You do not have permission to access this page."; the same guard applies to the Force-expire handler itself ("You do not have permission to do this."), and a mismatched/missing nonce is rejected by `check_admin_referer` before anything runs.
- **Settings that change it:** none — this page always exists once Stories is enabled; a delegated admin (not just a super admin) can moderate here since the capability is shared with other moderation screens.
- **Edge cases:** Stories can number in the thousands on a large site — the admin list is paginated (Previous/Next, "Page X of Y"), never an unbounded dump; the "seen by" counts for a whole page are fetched in one batched query, not one query per row.

#### MV-STY-012 — REST API surface and permission checks
- **Edition:** Pro
- **Who:** Varies per route (see below); this entry is for verifying the contract itself, not one persona.
- **Where:** `mvs-pro/v1`: `GET /stories`, `POST /media/{id}/story`, `DELETE /media/{id}/story`, `POST /stories/{id}/view`, `GET /stories/{id}/viewers`.
- **Setup:** Stories enabled; at least one media item eligible to become a story, one ineligible (e.g. a document, if reachable).
- **Steps:** 1. `GET /stories` as a logged-out visitor with no `author_id` — expect an empty result (public callback, but the default "network" scope needs a viewer). 2. `GET /stories?author_id=<id>` as a logged-out visitor to view one person's public stories. 3. `POST /media/{id}/story` as the media's owner, then as a different logged-in member, then logged out. 4. `DELETE /media/{id}/story` under the same three personas. 5. `POST /stories/{id}/view` as a viewer who can/cannot see the media.
- **Expected:** `GET /stories` — public, paginated (`X-WP-Total`/`X-WP-TotalPages`), each item includes `media_id`, `media_type`, `thumbnail_url`, `expires_at`, `viewed` (viewer-relative), and a nested `author` object. Owner-only routes (create/delete/viewers) return 401 logged-out, 403 for a logged-in non-owner who CAN see the media ("You can only manage your own stories."), 404 for anyone who cannot see the media at all (privacy-preserving, whether logged in or not the right owner). `record_view` route: 401 logged out, 404 if the viewer cannot see the media, otherwise `{ "recorded": true }` and the author's own view is never recorded even if they call it on their own story.
- **UX expectation:** Every refusal is a proper `WP_Error` with a real status code and message — never a 200 with a "success: false" body (this plugin's own coding rule against refusal-as-success). A 404 used for privacy purposes must be indistinguishable from a genuine 404 for a nonexistent ID — don't let a UI layer built on this API leak "this exists but you can't see it" information.
- **Settings that change it:** "Stories" toggle governs whether these routes are registered at all. Confirmed: the entire Stories engine, REST controller, and admin page are only constructed when the toggle is on — with the toggle off, `register_rest_route()` for these endpoints is never called, so every one of these URLs returns a clean 404 (unknown route), not a registered-but-refused response.
- **Edge cases:** `duration_hours` is clamped server-side to 1-168 regardless of what's submitted (e.g. `0` or `9999` both get clamped, never rejected outright) — verify the clamp rather than a validation error is what actually happens.

#### MV-STY-013 — Mobile app feature-flag interaction
- **Edition:** Pro
- **Who:** Mobile app users.
- **Where:** The mobile app's stories feature flag. Confirmed: the surface is `GET /wp-json/mvs/v1/app/config`, whose `features.stories` boolean is set purely from the `mvs_stories_enabled` option, with no reference to the site's Layout setting anywhere in that code path.
- **Setup:** Stories enabled at the site level, regardless of the website's Layout setting.
- **Steps:** 1. Set Layout to a non-Instagram skin (or grid) with Stories on. 2. Open the mobile app pointed at this site. 3. Confirm stories work fully in-app.
- **Expected:** The app's stories feature is driven purely by `mvs_stories_enabled`, not by the website's Layout choice — this is the explicit reason the Stories toggle stays visible/independent regardless of Layout (see MV-STY-001/005). A site running the plain grid layout can have a fully working stories feature in the app while showing no bar anywhere on the website.
- **UX expectation:** Nothing in the app should reference or depend on which website layout is active; testers should not need to switch the site to Instagram to validate app-side stories.
- **Settings that change it:** "Stories" toggle only.
- **Edge cases:** Check: this entry's expectations are derived from the website's own settings-field code and comments describing intent (confirmed: `features.stories` in `/app/config` is driven purely by `mvs_stories_enabled`), not from reading the mobile app codebase, which lives in a separate repo — confirm the app actually behaves this way directly in the app.

#### MV-STY-014 — Feature-off and unlicensed-state edge behavior
- **Edition:** Pro
- **Who:** QA verification pass across every Stories surface.
- **Where:** Upload block, stories bar block, admin Stories page, REST routes — with (a) Stories toggle off, (b) Pro plugin deactivated, (c) Pro active but license expired/invalid.
- **Setup:** Three states to test in turn: toggle off; Pro deactivated; Pro active with an intentionally invalid/expired license key.
- **Steps:** 1. With Stories toggle off: check upload checkbox, stories bar block, admin menu item. 2. Deactivate Pro entirely (Stories toggle still `1` in the DB from before): repeat the same checks. 3. Reactivate Pro, set an invalid license key, leave Stories toggle on: repeat the same checks.
- **Expected:** (a) Toggle off: no upload checkbox, stories bar block renders nothing. Confirmed: the admin Stories menu item and page disappear entirely — the page class is only ever constructed when the toggle is on, so with it off there is no menu item and no page to visit at all, not an empty list. (b) Pro deactivated: the Free-side upload checkbox is hidden specifically because `stories_available()` checks `defined('MVS_PRO_VERSION')` — a deliberate fix for exactly this "checkbox shown but nothing consumes it" bug class; no broken shell should appear anywhere. (c) Invalid/expired license: per this plugin's explicit design rule, license state NEVER gates any feature — Stories must work completely normally; only the license status badge in Settings shows "Inactive" and the plugin's own auto-update channel is affected, nothing else.
- **UX expectation:** None of the three states should ever produce a half-rendered control (a checkbox with no effect, a bar with a broken image, an admin page that errors) — each state is either fully working or cleanly absent, never a visible-but-non-functional shell.
- **Settings that change it:** "Stories" toggle (state a); Pro plugin activation (state b, not a setting); license key (state c — explicitly must NOT change behavior here).
- **Edge cases:** Re-enabling Stories after being off does not require re-creating anything — any story meta that existed before the toggle was flipped off (if still within its expiry window) should still be honored once re-enabled, since the toggle does not delete data.

### Area: DOC

#### MV-DOC-001 — Opening your document drive (frontend)
- **Edition:** Pro (route+template owned by Free)
- **Who:** any role with `use_mvs_documents` capability (all roles by default) and master toggle on. Refused: logged-out visitor (401), role without the capability (403).
- **Where:** `/my-media/documents/` (Free's route+template); drive data via `GET /wp-json/mvs-pro/v1/documents` and `GET /wp-json/mvs-pro/v1/folders`.
- **Setup:** `mvs_pro_documents_enabled` on (absent = on); viewer has `use_mvs_documents`.
- **Steps:** 1. Log in as a member. 2. Visit `/my-media/documents/`. 3. Observe folders + documents render, empty state if none.
- **Expected:** Drive renders folders then documents; empty drive shows an explanatory empty state (documented copy explicitly mentions files are private until shared). Logged-out gets 401 `mvs_unauthorized` ("You must be signed in."). Role without capability gets 403 `mvs_documents_unavailable` ("Documents are not available on this account.").
- **UX expectation:** The Documents tab must be hidden from the nav entirely for a role without the capability — never offered-then-refused. Empty state must render its explanatory copy, not a blank panel. A revoked capability must be enforced live, even in an already-open browser tab (server re-checks on every write).
- **Settings that change it:** `mvs_pro_documents_enabled` (master switch, absent=ON) hides the whole surface when off; Permissions matrix "Use Documents" column / `mvs_pro_documents_use_roles` transport field controls the capability.
- **Edge cases:** master toggle off (surface fully hidden, nothing deleted); role capability revoked while a drive tab is already open in a browser (writes must still be blocked server-side); 390px stacking.

#### MV-DOC-002 — Uploading a document, licensed member
- **Edition:** Pro
- **Who:** any role with the use capability, licensed site.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/upload` (frontend upload control on the drive).
- **Setup:** Licence active; site accepts the file's type and size.
- **Steps:** 1. Open drive. 2. Choose a file within allowed type/size. 3. Submit.
- **Expected:** 201 with the new document row; permission check runs write-access check then the licence guard (licensed, passes). Document lands at the default privacy level unless `privacy` param sent.
- **UX expectation:** Upload progress/success feedback follows the same pattern as media uploads elsewhere in the plugin; a rejected type/size must show the specific reason (below), never a bare "upload failed."
- **Settings that change it:** `mvs_pro_documents_max_size`, `mvs_pro_documents_allowed_types`, `mvs_pro_documents_default_privacy`.
- **Edge cases:** `doc_type` param mismatch with actual file is refused, never silently corrected; oversized file → 400 `mvs_document_too_large`; disallowed type → 400 `mvs_document_type_not_allowed`; failed content scan → 400 `mvs_document_scan_failed`.

#### MV-DOC-003 — Uploading a document, unlicensed member (refused)
- **Edition:** Pro
- **Who:** ordinary member (not `manage_options`/`manage_mvs_documents`) on an unlicensed site.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/upload`.
- **Setup:** Deactivate/expire the Pro licence.
- **Steps:** 1. As ordinary member, attempt upload.
- **Expected:** 403 `mvs_documents_read_only`, message: "This site is not accepting changes to documents at the moment. Your files are still here — you can open, download and share them as before." Route still registers (never 404); refusal happens after the logged-in check (so a logged-out caller still gets 401 first, not a licence answer).
- **UX expectation:** The member sees the exact reassuring message above, never a generic error — it must be clear existing files are untouched and safe. The upload control should ideally reflect a read-only state proactively rather than let the member attempt and fail, but at minimum the failure message must be this specific one.
- **Settings that change it:** none (licence state only).
- **Edge cases:** activating the licence mid-session must immediately unlock writes on the next request.

#### MV-DOC-004 — Uploading a document, unlicensed but administering member
- **Edition:** Pro
- **Who:** a user with `manage_options` or `manage_mvs_documents`, unlicensed site.
- **Where:** same upload route.
- **Setup:** licence inactive.
- **Steps:** 1. As admin/documents-manager, upload a file.
- **Expected:** Succeeds — admins are exempt even unlicensed. Ordinary members on the same site are still refused.
- **UX expectation:** No special "you're exempt" messaging needed — it should simply work identically to a licensed site, with no visible difference to the admin.
- **Settings that change it:** none.
- **Edge cases:** confirm the exemption is per-request, not a cached role snapshot.

#### MV-DOC-005 — Replacing a document's bytes
- **Edition:** Pro
- **Who:** whoever can edit the document (owner, or an edit-level grantee); refused for a view/comment grantee.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/{id}/replace`.
- **Setup:** licensed (or admin exemption); document exists and is not trashed.
- **Steps:** 1. Select a document. 2. Submit a replacement file.
- **Expected:** Bytes swap; id/slug/title/folder/privacy/grants are unchanged. Old file archived, recoverable 30 days, then permanently deleted from disk.
- **UX expectation:** Success feedback should confirm the replace happened without implying anything else (sharing, privacy, folder) changed. A comment/view-level grantee attempting replace must see a clear permission error, not a hidden/disabled control with no explanation.
- **Settings that change it:** none named; ttl controlled by an internal constant/filter.
- **Edge cases:** unlicensed non-admin refused with the same read-only message as upload; `doc_type` mismatch on the replacement file refused, not corrected.

#### MV-DOC-006 — Downloading a document
- **Edition:** Pro
- **Who:** anyone view access allows (owner, grantee, matching privacy level, or valid anon-link holder). Never license-gated (reads always work).
- **Where:** `GET /wp-json/mvs-pro/v1/documents/{id}/download`.
- **Setup:** none beyond view access.
- **Steps:** 1. Request the download URL. 2. Browser saves file.
- **Expected:** Always served as `attachment` regardless of type, so nothing executes in the site's origin. Works even on an unlicensed site.
- **UX expectation:** Browser download prompt is the entire feedback loop — no in-page toast needed. A denied download must show a clean "not found" experience, not a broken/hanging request.
- **Settings that change it:** none.
- **Edge cases:** trashed document 404s for everyone including the owner; no view access → 404 (never 403, to avoid confirming existence).

#### MV-DOC-007 — Previewing a PDF (tier 1, inline)
- **Edition:** Pro
- **Who:** same view-access rule as download.
- **Where:** `GET /wp-json/mvs-pro/v1/documents/{id}/preview`; rendered inline via vendored pdf.js on the document's permalink.
- **Setup:** document is a PDF.
- **Steps:** 1. Open a PDF document's permalink or preview endpoint.
- **Expected:** PDF bytes stream inline (the only mime ever served inline) — opens embedded via pdf.js, no download prompt required to view.
- **UX expectation:** Back-link on this permalink reads "Documents" pointing at the documents archive, not "Explore" — confirm a photo's single page still says "Explore" (regression risk both ways). Missing underlying file shows "the file for this document is missing", never a blank panel.
- **Settings that change it:** none.
- **Edge cases:** missing file on disk; malformed PDF should still show the missing/error state cleanly.

#### MV-DOC-008 — Previewing text/Markdown/CSV (tier 2, server HTML)
- **Edition:** Pro
- **Who:** same view-access rule.
- **Where:** `/preview` route / permalink.
- **Setup:** document type is text, markdown, or CSV.
- **Steps:** 1. Open such a document.
- **Expected:** Renders as server-rendered HTML; the file itself never leaves the server (no raw .md/.html/.csv served inline — that would be stored-XSS-with-a-download-button, deliberately excluded).
- **UX expectation:** Malformed CSV/oversized text should still degrade to a readable render, not a blank page.
- **Settings that change it:** none.
- **Edge cases:** malformed CSV/oversized text degrade gracefully.

#### MV-DOC-009 — Previewing Office/ODF/RTF/archive (tier 3/4, download card)
- **Edition:** Pro
- **Who:** same view-access rule.
- **Where:** `/preview` route / permalink.
- **Setup:** document type is Word/Excel/PowerPoint/ODF/RTF, or an unsupported/archive type.
- **Steps:** 1. Open such a document.
- **Expected:** No preview, no LibreOffice conversion — a card with type, size, author, and a working Download button. MediaVerse is "an embedder, not a file processor" by design.
- **UX expectation:** The card must clearly explain this is a download-only type, not look like a broken preview attempt. An unsupported/archive type falls to the same clean card, never a blank panel.
- **Settings that change it:** none.
- **Edge cases:** an unsupported/archive type (e.g. zip) falls to the same card presentation.

#### MV-DOC-010 — Creating a folder
- **Edition:** Pro
- **Who:** anyone with write access to the target drive; unlicensed non-admin refused.
- **Where:** `POST /wp-json/mvs-pro/v1/folders`; frontend drive "New folder" control.
- **Setup:** licence active (or admin exemption).
- **Steps:** 1. Click New Folder. 2. Enter a name. 3. Submit.
- **Expected:** Folder created; name normalized, max 150 chars, max depth 12. Feedback: "Folder created."
- **UX expectation:** Each specific refusal below must produce its own clear message, not a generic "could not create folder."
- **Settings that change it:** none named as a setting; depth ceiling is filterable via app config.
- **Edge cases:** empty name → "A folder needs a name."; name too long; name already taken in that parent → "A folder with that name is already here."; nesting beyond max depth refused.

#### MV-DOC-011 — Renaming a folder
- **Edition:** Pro
- **Who:** drive owner / manage-folder authority.
- **Where:** `PUT/PATCH /wp-json/mvs-pro/v1/folders/{id}`.
- **Setup:** folder exists, not trashed.
- **Steps:** 1. Rename via drive UI.
- **Expected:** "Renamed." on success; duplicate name in same parent refused.
- **UX expectation:** Inline rename control should show the specific duplicate-name error rather than a generic failure, so the member knows exactly why.
- **Settings that change it:** none.
- **Edge cases:** invalid characters refused with a specific message.

#### MV-DOC-012 — Moving/nesting a folder
- **Edition:** Pro
- **Who:** drive owner.
- **Where:** `PUT/PATCH /wp-json/mvs-pro/v1/folders/{id}` (parent change) or bulk-move.
- **Setup:** target parent exists, on same drive, not trashed.
- **Steps:** 1. Drag/select destination folder. 2. Move.
- **Expected:** "Moved." Six distinct refusal codes for: moving into itself, into its own descendant, into a folder on a different drive, into a trashed parent, exceeding max depth, parent gone.
- **UX expectation:** Each of the six refusal cases must show a distinct, comprehensible message — a drag-and-drop UI that just silently snaps back with no explanation is a defect.
- **Settings that change it:** none.
- **Edge cases:** each of the six refusals above must be independently reproducible.

#### MV-DOC-013 — Trashing a document
- **Edition:** Pro
- **Who:** document owner or edit-level grantee; unlicensed non-admin refused.
- **Where:** `DELETE /wp-json/mvs-pro/v1/documents/{id}` (trashes, never destroys); drive form action `trash`.
- **Setup:** licence active (or admin exemption).
- **Steps:** 1. Select document. 2. Trash.
- **Expected:** Status flips to trashed; ALL active shares on it are cleared as part of trashing ("Moved to trash. It is no longer shared with anyone. You can put it back from Trash.").
- **UX expectation:** This action should show a confirm step given it revokes existing shares as a side effect — a member sharing a document with a collaborator must understand that trashing breaks that access, not just "removes it from my view." The message above should be shown at confirm time, not just after the fact.
- **Settings that change it:** none.
- **Edge cases:** document already trashed by someone else concurrently — repeat trash should be a no-op/gone rather than an error trap.

#### MV-DOC-014 — Restoring a document as the uploader
- **Edition:** Pro
- **Who:** the original uploader (author of the row).
- **Where:** `POST /wp-json/mvs-pro/v1/documents/{id}/restore`.
- **Setup:** document trashed, its folder (if any) NOT trashed.
- **Steps:** 1. Open Trash. 2. Restore.
- **Expected:** "Restored." If the containing folder is still trashed, restore is refused with "Restore the folder it was in first." rather than restoring into limbo.
- **UX expectation:** The folder-trashed refusal message must clearly direct the member to restore the folder first — not a dead end.
- **Settings that change it:** none.
- **Edge cases:** parent folder trashed blocks the restore even for the uploader.

#### MV-DOC-015 — Restoring a document as documents-admin (non-uploader)
- **Edition:** Pro
- **Who:** a user with `manage_mvs_documents` (or `manage_options`), not the uploader.
- **Where:** same restore route; the single gate is shared by the REST route, bulk restore, and the trash listing itself.
- **Setup:** document trashed, uploaded by someone else.
- **Steps:** 1. Documents admin opens the relevant trash view. 2. Restore.
- **Expected:** Succeeds — admins can restore regardless of authorship.
- **UX expectation:** This listing must show every viewer-restorable trashed doc, not just their own — an admin who can't see other members' trashed files can't do their job.
- **Settings that change it:** none.
- **Edge cases:** none beyond confirming the admin listing scope.

#### MV-DOC-016 — Restoring a document as an unrelated member (refused)
- **Edition:** Pro
- **Who:** any member who neither uploaded the document nor holds documents-admin capability, even if they previously held an edit share grant (grants are cleared on trash anyway).
- **Where:** `POST /documents/{id}/restore`.
- **Setup:** document trashed by a different member.
- **Steps:** 1. Attempt restore directly by id (bypassing the listing).
- **Expected:** Refused with "You don't own that document." This trashed document must never even appear in this member's trash listing.
- **UX expectation:** Because the item is invisible in the listing to begin with, this refusal is mainly a defense against a direct/guessed id — the more important behavior is that the listing correctly hides it in the first place.
- **Settings that change it:** none.
- **Edge cases:** an edit-level share grantee is explicitly NOT sufficient — restore is stricter than edit, deliberately (closed a prior regression where bulk restore let an edit grantee restore something the single route and listing both refused them).

#### MV-DOC-017 — Trash listing visibility
- **Edition:** Pro
- **Who:** every viewer sees only what they may restore (their own uploads; documents admins see everything).
- **Where:** `GET /wp-json/mvs-pro/v1/documents?status=trash`.
- **Setup:** mixed trash: some docs trashed by member A, some by member B, none.
- **Steps:** 1. As member A, list trash. 2. As documents admin, list trash.
- **Expected:** Member A sees only their own trashed docs; admin sees all. This is the single answer shared by the restore route, bulk restore, and this listing — no drift between "what I can see" and "what I can restore."
- **UX expectation:** A trash view listing something the viewer then can't restore (or vice versa) would be confusing and must not occur — the listing IS the permission boundary.
- **Settings that change it:** none.
- **Edge cases:** a trashed document 404s for EVERYONE outside this rule, including former viewers who used to have view access.

#### MV-DOC-018 — Permanent delete and on-disk removal
- **Edition:** Pro (delete-from-disk mechanics); permanent delete itself is a Free media-lifecycle action, not exposed on the document REST surface.
- **Who:** whoever has authority to permanently delete media in Free, or the automatic purge after retention.
- **Where:** Free's media permanent-delete path; Pro hooks the file-orphan event to remove the on-disk file (and any replaced-file archive).
- **Setup:** document permanently deleted (not just trashed).
- **Steps:** 1. Permanently delete a trashed document. 2. Check the documents storage directory on disk.
- **Expected:** File is actually gone from disk — a fix over prior behaviour, where permanent delete reported success but left the file on disk.
- **UX expectation:** The admin sees a normal "deleted" confirmation; the real proof is the disk state, not a different UI message — this is a correctness fix, not a UX change.
- **Settings that change it:** none.
- **Edge cases:** a document that had a "replaced" archive from a prior replace call must have that archive cleaned up too, not just the current file.

#### MV-DOC-019 — Default privacy setting
- **Edition:** Pro
- **Who:** site owner configures; affects every member's new uploads.
- **Where:** `admin.php?page=mvs-settings-documents`, "New documents start as" (option `mvs_pro_documents_default_privacy`).
- **Setup:** none.
- **Steps:** 1. Change the default privacy dropdown. 2. Save. 3. Upload a new document without specifying `privacy`.
- **Expected:** New document lands at the configured level. Default value if never touched: `private`. `space` is hidden from the picker entirely when BuddyNext is inactive.
- **UX expectation:** The dropdown must never offer a value that would then be refused at write time (i.e. `space` must be genuinely absent from the list when BuddyNext is off, not present-but-broken).
- **Settings that change it:** `mvs_pro_documents_default_privacy` (values: private/space/members/public; unrecognized stored value falls back to `private`).
- **Edge cases:** a value saved while BuddyNext was active (`space`) but BuddyNext later removed — reading falls back to `private` rather than erroring.

#### MV-DOC-020 — Private/members/public visibility per role
- **Edition:** Pro
- **Who:** owner sets a document's privacy to private/members/public; every role/viewer is tested against it.
- **Where:** drive form "privacy" action; `PATCH /wp-json/mvs-pro/v1/documents/{id}`.
- **Setup:** documents at each of the three universally-available levels.
- **Steps:** 1. Set a document to `private`. Verify only owner (and admins/grantees) can view. 2. Set to `members`. Verify any signed-in member with document access can view, logged-out cannot. 3. Set to `public`. Verify a logged-out visitor can view.
- **Expected:** Visibility strictly follows the level; the capability (`use_mvs_documents`) never gates a read — a member without the capability, or a logged-out visitor, can still open an already-public document.
- **UX expectation:** The privacy picker's label for each level must accurately describe who can see it, and the actual visibility behavior must match that label exactly — no level should behave more restrictively or more permissively than its description promises.
- **Settings that change it:** none directly; unrecognized posted value refused.
- **Edge cases:** the capability gate applies only to having-a-drive, never to reading something already shared/public.

#### MV-DOC-021 — Space privacy without BuddyNext (refused)
- **Edition:** Pro
- **Who:** any member attempting to set `space` privacy on a site without BuddyNext active.
- **Where:** document PATCH, folder privacy change, standalone drive form, default-privacy settings screen.
- **Setup:** BuddyNext not active/installed.
- **Steps:** 1. Attempt to PATCH a document's privacy to `space`. 2. Attempt the same on a folder (checked before rename/move). 3. Check the settings screen's default-privacy dropdown.
- **Expected:** Document/folder PATCH → 400, message "Space privacy needs BuddyNext, which is not active on this site." Upload with `privacy=space` in this state silently falls back to the site default instead of failing the upload. The settings screen simply does not offer `space` as an option.
- **UX expectation:** The refusal message must clearly name BuddyNext as the missing dependency, not a generic "invalid privacy" error — an owner needs to know exactly what to install to unlock it.
- **Settings that change it:** none — purely a BuddyNext-presence check.
- **Edge cases:** a document that already holds `space` privacy from before BuddyNext was removed keeps that stored value; only NEW writes are refused.

#### MV-DOC-022 — Space privacy with BuddyNext active
- **Edition:** Pro (bridged via BuddyNext)
- **Who:** any member who can write the target Space drive.
- **Where:** same PATCH/upload routes, now with BuddyNext detected active.
- **Setup:** BuddyNext active, a Space exists.
- **Steps:** 1. Upload/set a document to `space` privacy on a Space drive.
- **Expected:** Document becomes visible to that space's members via the bridge filter; on a personal (user) drive the same value resolves to the owner alone (which is why the picker hides `space` on personal drives).
- **UX expectation:** The picker must only ever offer `space` where it will actually do something meaningful (on a Space drive), never on a personal drive where it would silently resolve to owner-only.
- **Settings that change it:** none.
- **Edge cases:** an OPEN space's bridge answers "read" even to non-members by design (the alternative let any signed-in visitor open a private file).

#### MV-DOC-023 — Sharing a document with a named member
- **Edition:** Pro
- **Who:** document owner, or anyone with drive-write authority for space drives.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/{id}/permissions`, `grantee_type=user`.
- **Setup:** target member exists.
- **Steps:** 1. Open share panel. 2. Type member's username/email. 3. Choose permission level (view/comment/edit). 4. Submit.
- **Expected:** Grant created; feedback "Shared. They can reach it from 'Shared with me'." Grantee sees it at `GET /wp-json/mvs-pro/v1/me/shared`.
- **UX expectation:** The type-ahead should only surface real, resolvable members — attempting to submit an unresolvable name must show a specific "unknown member" message, not a generic failure. Sharing with the document's own owner is a redundant no-op that should be caught with a clear message, not silently accepted.
- **Settings that change it:** none.
- **Edge cases:** unknown username/email refused; sharing with the document's own owner refused ("They already own this document."); empty share field refused ("Type the member you want to share with.").

#### MV-DOC-024 — Revoking a share (licence-exempt)
- **Edition:** Pro
- **Who:** whoever can grant (same authority as granting).
- **Where:** `DELETE /wp-json/mvs-pro/v1/permissions/{grant_id}`; drive form `unshare` action.
- **Setup:** an active grant exists; test specifically on an UNLICENSED site.
- **Steps:** 1. On unlicensed site, revoke an existing share.
- **Expected:** Succeeds even though the site is otherwise write-locked — revoke is the one write exempt from the licence gate. Feedback: "Access withdrawn."
- **UX expectation:** The "Who has access" panel's Remove control must remain fully active and functional even when every other write control on the drive is disabled/read-only for licence reasons — an owner must always be able to cut someone off.
- **Settings that change it:** none.
- **Edge cases:** grant already revoked/gone → "That share no longer exists."

#### MV-DOC-025 — Sharing with a role (refused going forward)
- **Edition:** Pro
- **Who:** anyone attempting `grantee_type=role`, including site administrators.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/{id}/permissions`.
- **Setup:** none special.
- **Steps:** 1. Attempt to create a grant with `grantee_type: role`.
- **Expected:** 403, message: "Sharing with a whole role is no longer supported. Share with a person, or set the document's privacy." Refused unconditionally, even for admins — there is no exemption.
- **UX expectation:** The share panel's role option must be removed from the UI entirely, not merely disabled — offering a control that then always 403s is a defect. Existing legacy role grants must still show correctly in "Who has access," even though creating new ones is blocked, so an owner isn't confused about who currently has access.
- **Settings that change it:** none — removed for everyone, not a toggle.
- **Edge cases:** existing pre-removal role grants are NOT retroactively revoked — verify a legacy role grant still opens the document for members of that role even though creating a new one is blocked.

#### MV-DOC-026 — Permission share link (create)
- **Edition:** Pro
- **Who:** whoever has sharing authority for the document.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/{id}/permissions/link`.
- **Setup:** `mvs_pro_documents_anon_links` must be ON for this document/site.
- **Steps:** 1. Choose permission level for the link. 2. Create link.
- **Expected:** A token-bearing URL is returned that opens the document at the given permission level without signing in.
- **UX expectation:** The "create link" control must be hidden entirely when the site setting is off, not offered and then refused with an error the member can't act on.
- **Settings that change it:** `mvs_pro_documents_anon_links` (absent = OFF).
- **Edge cases:** attempting to create a link while the setting is off → refused, "Anonymous links are off on this site."

#### MV-DOC-027 — Anonymous share links on/off, and closing already-issued links
- **Edition:** Pro
- **Who:** anonymous (logged-out) visitor holding a link token.
- **Where:** any document route with the link token on the query string (page, preview, download all honor the same token).
- **Setup:** create a link while the setting is ON, then flip the anon-links setting OFF.
- **Steps:** 1. With the setting ON, mint a link and open it logged out — confirm it works. 2. Turn the setting OFF (site-wide). 3. Reopen the SAME already-issued link logged out.
- **Expected:** Step 1 succeeds. Step 3 is refused (re-checked on every redemption, not just at minting) — the grant row itself is left intact, so turning the setting back ON restores the link without re-sharing.
- **UX expectation:** A visitor hitting a closed link should see a clean, honest "this link is no longer available" state, not a broken page or a confusing error. Redemption must fail CLOSED under rate-limiting or any ambiguity — never grant access when uncertain.
- **Settings that change it:** `mvs_pro_documents_anon_links` — absent = OFF.
- **Edge cases:** redemption is rate-limited by IP (30/min) and fails closed — verify by hammering a link and confirming it locks out rather than staying open.

#### MV-DOC-028 — Searching a drive (title/content/tags, PDF exception)
- **Edition:** Pro
- **Who:** any member searching their own visible drive.
- **Where:** `GET /wp-json/mvs-pro/v1/documents/search?q=...&drive=...`.
- **Setup:** extraction enabled (absent = ON); documents with matching title, body text, and tags.
- **Steps:** 1. Search by exact title term. 2. Search by a term only in the body of a plain-text/Office doc. 3. Search by a tag. 4. Search a PDF by a term only in its body.
- **Expected:** Title/content/tag matches rank (content/title weighted ahead of tags). PDF is findable by title/tags only — never by body text, since extraction never runs on PDFs.
- **UX expectation:** A PDF that doesn't surface for a body-text search must not look like "search is broken" — if a QA pass files this as a bug, it should be corrected to note it's the documented PDF exception. A freshly-uploaded, not-yet-extracted document should show as "indexing" state, not a false "no results."
- **Settings that change it:** `mvs_pro_documents_extraction` — turning it off stops new indexing.
- **Edge cases:** query below the minimum term length handled gracefully (no crash, no full-table scan).

#### MV-DOC-029 — Space search covering linked files
- **Edition:** Pro (bridged via BuddyNext)
- **Who:** a Space member searching that space.
- **Where:** `GET /documents/search?drive=space:<id>&q=...`.
- **Setup:** a document uploaded natively into the space AND a separate document merely linked into it.
- **Steps:** 1. Search the space for a term unique to the linked (not native) document.
- **Expected:** The linked document appears in results — space search covers files linked into the space, not only ones uploaded there.
- **UX expectation:** From the member's point of view, a linked file should search and browse indistinguishably from a native one within that space — the linking mechanism is invisible to the end user.
- **Settings that change it:** none.
- **Edge cases:** the public Explore search is untouched by any of this — a document search scoped this way must never leak into the public search.

#### MV-DOC-030 — Linking a document into multiple spaces
- **Edition:** Pro (bridged via BuddyNext, no MediaVerse-native UI)
- **Who:** the document's owner/edit-grantee, who also has write access to each target space.
- **Where:** `POST /wp-json/mvs-pro/v1/documents/{id}/spaces`, or `POST /wp-json/mvs-pro/v1/documents/link` (by pasted URL/slug/id).
- **Setup:** BuddyNext active; two or more spaces the member can write to.
- **Steps:** 1. Link document into Space A. 2. Link the same document into Space B.
- **Expected:** Both succeed independently; the document appears at the root of each space's Files tab regardless of its home folder.
- **UX expectation:** Each of the three refusal cases below needs its own message — "already linked here," "space not found" (deliberately not-found rather than forbidden, to avoid confirming a secret space exists), and "you can't edit this file."
- **Settings that change it:** none.
- **Edge cases:** linking a document into the space it already lives natively on refused ("This file already lives in this space."); linking into a space you can't write refused as not-found; linking a file you don't own/can't edit refused as forbidden.

#### MV-DOC-031 — Unlinking a document from a space
- **Edition:** Pro
- **Who:** the space's moderator/owner OR the document's own owner (not any plain space member).
- **Where:** `DELETE /wp-json/mvs-pro/v1/documents/{id}/spaces/{space_id}`.
- **Setup:** an existing link.
- **Steps:** 1. As a plain space member (not moderator, not doc owner), attempt unlink — expect refusal. 2. As space moderator or doc owner, unlink.
- **Expected:** Step 1 refused, "You cannot remove this file from the space." Step 2 succeeds; the document remains intact on its home drive (unlinking never deletes the file).
- **UX expectation:** A plain member browsing a space's files should not even see an unlink control on files they can't remove, ideally — at minimum, the refusal message must be specific, not a generic permission error. Confirmed: this write IS licence-gated like other document writes — its route is a write method under the `/mvs-pro/v1/documents` surface and is not on the short exemption list (which only exempts `DELETE /permissions/{id}`), so a lapsed licence produces the standard read-only refusal for anyone below `manage_options`/`manage_mvs_documents`.
- **Settings that change it:** none.
- **Edge cases:** Licence behavior for the unlink route specifically: confirmed gated, per above — not an exception like the permissions-revoke route.

#### MV-DOC-032 — Space drive cleanup on space deletion
- **Edition:** Pro (bridged via BuddyNext)
- **Who:** triggered automatically, no direct user action.
- **Where:** listens to BuddyNext's space-purge action; continues via an Action Scheduler hook.
- **Setup:** a Space with 200+ documents and several folders, BuddyNext active.
- **Steps:** 1. Delete the Space in BuddyNext. 2. Check the space's drive documents/folders immediately, then after Action Scheduler runs.
- **Expected:** All native documents and folders on that space's drive are TRASHED (not permanently deleted) through the normal trash path, so every trash listener fires. Processed in chunks, remainder continued via Action Scheduler.
- **UX expectation:** Nothing is member-visible here beyond the eventual disappearance of the space's files from listings — this is background cleanup, not an interactive flow. Files merely linked into the deleted space (not natively hosted there) must be left completely alone.
- **Settings that change it:** none.
- **Edge cases:** documents merely LINKED into the deleted space must not be trashed.

#### MV-DOC-033 — Orphan reclaim dry run + delete via settings card
- **Edition:** Pro
- **Who:** site owner / documents admin (settings-screen access).
- **Where:** `admin.php?page=mvs-settings-documents` "Orphaned files" card.
- **Setup:** at least one truly orphaned file on disk (untouched for over 1 hour, no index/meta row referencing it).
- **Steps:** 1. Click "Check for orphaned files" (dry run). 2. Review the report (kept for the owner). 3. Click Delete (only appears once a check found orphans).
- **Expected:** Check reports a count + sample without deleting anything. Delete is POST-only and re-checks each file's orphan status at the moment it deletes it. Success message: "Deleted N orphaned document file(s) (<size>)."; partial failure names the count that couldn't be deleted plus "More remain: check again to continue." if capped.
- **UX expectation:** Delete button must only appear after a check found orphans — never available speculatively. The destructive nature of permanent disk deletion warrants a clear confirm step naming what will be deleted before it happens.
- **Settings that change it:** none — this is maintenance, not a setting.
- **Edge cases:** a file created less than an hour ago (mid-upload, mid-scan) must never be reclaimed.

#### MV-DOC-034 — Orphan reclaim via WP-CLI
- **Edition:** Pro
- **Who:** server/shell access (WP-CLI), no REST route exists for this deliberately.
- **Where:** `wp mvs-pro documents reclaim-orphans [--dry-run] [--scope=<subdir>] [--batch=<n>] [--yes]`.
- **Setup:** same orphan fixture as MV-DOC-033.
- **Steps:** 1. Run with `--dry-run` first. 2. Run with `--scope=<segment>/2025/03` to narrow. 3. Run with `--yes` to actually delete without an interactive prompt.
- **Expected:** Always reports before asking; a `--scope` narrowing that resolves OUTSIDE the documents tree is refused, not silently ignored.
- **UX expectation:** N/A visually (CLI-only) — but output must be truthful, never claim a deletion that didn't happen or vice versa.
- **Settings that change it:** none.
- **Edge cases:** default batch size per DB round trip; confirm `--batch` override is respected and still bounded.

#### MV-DOC-035 — Allowed-types restriction, including absent-vs-empty
- **Edition:** Pro
- **Who:** site owner configures; affects every uploader.
- **Where:** `admin.php?page=mvs-settings-documents`, "Allowed types" field.
- **Setup:** three states: (a) option never touched/absent, (b) explicitly set to a subset, (c) explicitly saved as an empty array.
- **Steps:** 1. Leave untouched, attempt uploading every supported type — all succeed. 2. Restrict to one type, attempt an excluded type. 3. Save with every checkbox unchecked (empty array), attempt any upload.
- **Expected:** (a) absent reads as "every type allowed." (b) only the selected types succeed. (c) an empty array is a distinct, deliberately saveable "accept nothing" state — every upload refused, and this must NOT be read as "not configured."
- **UX expectation:** The settings screen must make clear that "no checkboxes ticked" is a deliberate, valid "block everything" state, not an error or an accidentally-cleared field — an owner should not be confused into thinking they broke something.
- **Settings that change it:** `mvs_pro_documents_allowed_types`; also filterable, always intersected against the library's real type vocabulary.
- **Edge cases:** a file renamed to a fake extension must still be caught by content-based type checks, independent of this setting.

#### MV-DOC-036 — Max-size clamp to server limit
- **Edition:** Pro
- **Who:** site owner configures; affects every uploader.
- **Where:** `admin.php?page=mvs-settings-documents`, max-size field (in MB).
- **Setup:** set the option ABOVE the server's real upload limit.
- **Steps:** 1. Set the site's configured max size higher than the server actually allows. 2. Save. 3. Check the app config's advertised max size. 4. Attempt an upload sized between the server limit and the configured (higher) value.
- **Expected:** The advertised and enforced limit is clamped to the server's real ceiling — never advertises a number the server would reject. Leaving the option at 0 means "follow the server limit" exactly.
- **UX expectation:** A member must never be told "up to 100MB" and then have a 60MB upload rejected because the real server ceiling is 50MB — the advertised number and the enforced number must always agree.
- **Settings that change it:** `mvs_pro_documents_max_size` (0/absent = follow server); filterable, filter always wins over the setting.
- **Edge cases:** a per-role or quota-driven upload limit from elsewhere on the site must still be respected.

#### MV-DOC-037 — Capability grant via Permissions matrix
- **Edition:** Pro (contributes the columns; Free renders the screen)
- **Who:** site owner (`manage_options`).
- **Where:** `admin.php?page=mvs-settings` Permissions tab, columns "Use Documents" and "Manage Documents".
- **Setup:** none.
- **Steps:** 1. Uncheck "Use Documents" for a role (e.g. Subscriber). 2. Save. 3. Log in as that role and visit the drive.
- **Expected:** Role loses the capability; that member now gets 403 from every document route and the tab is hidden; existing documents they already own are unaffected in terms of reads by others, but they themselves can no longer use the drive going forward.
- **UX expectation:** The Permissions-tab checkbox and the Documents settings screen's own "Who can use documents" control must always show the SAME state — a mismatch between the two screens would mislead the owner about the real access state.
- **Settings that change it:** written through the same underlying capability function the Documents settings screen uses.
- **Edge cases:** verify the two screens stay in sync.

#### MV-DOC-038 — Capability grant via Documents settings ("Who can use documents")
- **Edition:** Pro
- **Who:** site owner.
- **Where:** `admin.php?page=mvs-settings-documents`, "Who can use documents" field.
- **Setup:** none.
- **Steps:** 1. Change role selection here instead of the Permissions tab. 2. Save. 3. Recheck the Permissions tab — the same capability should now reflect the change.
- **Expected:** Identical end-state to MV-DOC-037 — this option is transport-only, the real state lives in the capability.
- **UX expectation:** No drift between the two screens' displayed state, ever.
- **Settings that change it:** `mvs_pro_documents_use_roles`.
- **Edge cases:** none beyond confirming no drift between the two screens.

#### MV-DOC-039 — Per-user capability override filter
- **Edition:** Pro (Free-owned filter, exercised by Pro's document gates)
- **Who:** a developer/host filtering the per-user capability check.
- **Where:** code-level filter, resolved last, after the option and the role capability.
- **Setup:** a filter forcing the capability answer for a specific user.
- **Steps:** 1. Grant the role capability normally. 2. Filter forces `false` for one specific user id. 3. That user attempts the drive.
- **Expected:** Filter wins — that one user is refused even though their role has the capability, and vice versa. Resolution order for ALL document settings is option first, filter last.
- **UX expectation:** N/A member-visible surface beyond the standard 403 — this is a developer/integration seam, not something with its own UI.
- **Settings that change it:** none (code-only override), by design.
- **Edge cases:** BuddyNext is the documented real-world consumer of this filter for space-membership-driven access changes.

#### MV-DOC-040 — GDPR export/erase of document data
- **Edition:** Pro (implicit — no Pro-specific exporter/eraser exists; documents are media rows)
- **Who:** site admin running Tools > Export/Erase Personal Data.
- **Where:** wp-admin Tools > Export Personal Data / Erase Personal Data.
- **Setup:** a member with at least one document.
- **Steps:** 1. Run an export request for that member's email. 2. Run an erase request for the same.
- **Expected:** The document's title/description/etc. appear in the "Media Items" export group (unfiltered by privacy — an export discloses everything the person authored regardless of visibility); erase removes the row through the same media-wide path. No separate "Documents" export group label exists.
- **UX expectation:** The generic "Media Items" export group label is used for documents too, with no separate "Documents" label anywhere. No docs promise a distinct label, and this is a naming nitpick rather than a functional bug — not a defect.
- **Settings that change it:** none.
- **Edge cases:** confirm the physical file itself is also removed on erase, not just the database row.

#### MV-DOC-041 — Profile Documents sub-tab
- **Edition:** Pro (renders; Free emits the seam)
- **Who:** any BuddyPress profile viewer; content privacy-filtered per viewer.
- **Where:** BuddyPress member profile, "Documents" sub-tab beside Media and Albums.
- **Setup:** the profile owner has documents at various privacy levels; view as the owner, as another member, and logged out.
- **Steps:** 1. Visit own profile Documents tab — see all own documents. 2. Visit as another member — see only what's shared/members/public. 3. Visit logged out — see only public.
- **Expected:** Count and listing both privacy-filtered per viewer, consistently (count must match what's actually rendered).
- **UX expectation:** The displayed count and the actual list must never disagree — a count of 5 with 3 visible rows is a defect, not an acceptable discrepancy.
- **Settings that change it:** master toggle off hides the tab entirely.
- **Edge cases:** a profile with a very large number of candidate rows across privacy levels must still resolve correctly, not silently truncate the wrong set.

#### MV-DOC-042 — Admin document list, single view, and Pro panels
- **Edition:** Free (list/single-view screen) + Pro (extra panels)
- **Who:** admin/documents-manager capability in wp-admin.
- **Where:** `admin.php?page=mvs-documents` (list); single view with `&view=single&id=<id>`.
- **Setup:** documents in various states (trashed, shared, various privacy).
- **Steps:** 1. Open the list — verify Pro's extra row actions appear (e.g. folder path, sharing state). 2. Open a single document — verify Pro's admin panels render (permissions, folder, extraction status).
- **Expected:** List/single-view shell is Free's; Pro's panels appear seamlessly without a second, competing UI. No admin FOLDER list exists (deliberate, documented exception to the 3-entry-point rule).
- **UX expectation:** Pro's panels must feel like a native part of Free's screen, not a bolted-on second UI. A trashed document's row actions must differ appropriately (offer restore, not the write actions that don't apply).
- **Settings that change it:** none.
- **Edge cases:** trashed-row action set correctness.

#### MV-DOC-043 — Health check (Site Health)
- **Edition:** Pro
- **Who:** admin, via Tools > Site Health.
- **Where:** Site Health test, label "Document storage is private".
- **Setup:** run on a normal install (storage private) and, if possible, one where the documents directory is misconfigured as web-accessible.
- **Steps:** 1. Open Site Health > Status. 2. Locate the documents storage test.
- **Expected:** On a healthy site: passes, confirming the storage directory denies direct HTTP access (verified via an actual loopback HTTP fetch of a canary file). On a misconfigured host: fails with one of several specific labels describing exactly what's wrong.
- **UX expectation:** The failure labels must be specific enough that an admin without deep technical knowledge can act on them (e.g. "documents can be downloaded by anyone with the link" is actionable, a generic "storage misconfigured" is not).
- **Settings that change it:** none directly; result is cached and only re-probed on demand/after a settings change.
- **Edge cases:** a host that blocks loopback requests must report "unchecked" rather than falsely claiming protection.

#### MV-DOC-044 — App config surface for a native client
- **Edition:** Pro (contributes the `documents` block; Free owns the route)
- **Who:** any client calling the config endpoint (app, BuddyNext), per-user answers.
- **Where:** `GET /wp-json/mvs/v1/app/config`, `documents` block.
- **Setup:** vary licence state, role capability, and BuddyNext presence across three separate calls.
- **Steps:** 1. Call as a licensed, capable member. 2. Call as the same member on an unlicensed site. 3. Call as a member without the capability.
- **Expected:** `enabled` reflects the per-user capability (not a site-wide constant). `writable` is separate: false on an unlicensed site (reads still work, writes don't) — a client should hide upload/attach controls specifically when `writable` is false, while still showing the enabled library. `privacy_levels` omits `space` when BuddyNext is inactive.
- **UX expectation:** A client must never draw a tab it then gets 403'd from — `enabled` must be trustworthy per-user, not assumed site-wide. A stale client caching `enabled`/`writable` across a licence-state change would show the wrong buttons until it re-fetches.
- **Settings that change it:** every document setting surfaces here.
- **Edge cases:** verify the payload's change-detection (ETag/hash) updates appropriately when underlying settings change.

#### MV-DOC-045 — Document embed and document list blocks
- **Edition:** Pro
- **Who:** content editor placing blocks; viewer permission is re-resolved live, never cached from the editor's access.
- **Where:** Gutenberg blocks `mvs/pro-document-embed` ("Document") and `mvs/pro-document-list` ("Document List").
- **Setup:** a page/post with each block inserted, referencing a document/folder the editor can see but a test viewer cannot.
- **Steps:** 1. Insert `pro-document-embed` with `documentId` set to a private document. 2. Insert `pro-document-list` with `folderId` set to a folder containing mixed-privacy documents. 3. View the published page as the editor, then as an unrelated viewer.
- **Expected:** Embed block: editor sees the document as configured; unrelated viewer sees nothing/a permission-appropriate empty state — permission is resolved fresh on every page view, never baked in at insert time. List block: each viewer sees only the subset of documents in that folder they may open.
- **UX expectation:** An unrelated viewer must see a clean, unremarkable absence — never an error, never a "permission denied" callout that confirms a private document exists on the page.
- **Settings that change it:** none block-specific; inherits all document privacy/capability rules above.
- **Edge cases:** 390px rendering of the list block's rows; a `documentId`/`folderId` that no longer exists or was trashed should render a clean empty/missing state, never a PHP notice or blank block.

### Area: VID

#### MV-VID-001 — Add/list video chapters (REST only, no web UI)
- **Edition:** Pro
- **Who:** Read: anyone who can view the media item (privacy-gated, `can_read_media`). Write: media author, or a user with `moderate_mvs_media`; every other logged-in user is refused; logged-out is refused.
- **Where:** `GET /wp-json/mvs-pro/v1/media/{id}/chapters`, `PUT /wp-json/mvs-pro/v1/media/{id}/chapters`. No wp-admin page, no frontend UI anywhere — reachable only via a REST/API client (e.g. a native app or Postman).
- **Setup:** A video media item must already exist (`media_type = video`); Pro active.
- **Steps:** 1) As the author, PUT `{"chapters":[{"time_seconds":30,"title":"Intro"},{"time_seconds":120,"title":"Part 2"}]}`. 2) GET the same route and confirm the same two chapters return, sorted by `time_seconds` ascending regardless of submit order. 3) Repeat PUT as a different logged-in member who is neither author nor moderator.
- **Expected:** PUT stores JSON in post meta `_mvs_chapters` (via `media_repository->set($id,'chapters',...)`) and returns the sorted list; malformed entries (missing `title`, non-numeric `time_seconds`) are silently dropped, not erroring. Step 3 returns 403 `mvs_pro_forbidden` "You do not have permission to edit chapters for this media." Chapters against a non-video media id return 422 `mvs_pro_not_video` "Chapters are only available for video media." Chapters against a private video for a viewer without access return 404 `mvs_pro_not_found` "Media item not found." (never 403, to avoid confirming existence).
- **UX expectation:** There is no in-product way for a site owner or member to discover this feature exists — no button, no admin field, no settings link references it. A QA pass on the web UI will correctly find nothing; that absence is the intended design, not a bug, and must not be filed as one. A native/API client is the only consumer. No loading/empty/error state to check because there is no rendered surface.
- **Settings that change it:** none — chapters have no feature toggle.
- **Edge cases:** submitting an empty `chapters` array (clears all chapters, still 200 with `chapters: []`); a `thumbnail_url` that isn't a valid URI (dropped by the `format: uri` REST arg validation); title >200 chars (rejected by `maxLength`); calling PUT on a media id that doesn't exist (404 before the video-type check runs).

#### MV-VID-002 — Chapters surfaced in the media REST response
- **Edition:** Pro
- **Who:** Anyone who can read the media item's REST representation.
- **Where:** The core Free media REST payload (`GET /wp-json/mvs/v1/media/{id}`) — Pro appends a `chapters` key via `ChapterService::append_chapters_to_response()`.
- **Setup:** Chapters already set via MV-VID-001.
- **Steps:** 1) Fetch a video media item's REST response with chapters set. 2) Fetch a video media item's REST response with none set. 3) Fetch an audio/image media item's REST response.
- **Expected:** Step 1: `chapters` array populated, sorted ascending. Step 2: `chapters: []` (always present as a predictable key for video, never omitted). Step 3: no `chapters` key at all — the filter early-returns for non-video media types.
- **UX expectation:** This is a data contract for a native app, not something a browser visitor sees rendered. Nothing on the page should visibly change; the only observable effect is in the raw JSON. Confusing an empty `chapters: []` with "chapters broken" would be a false bug report — it's the documented empty state for a video with none set.
- **Settings that change it:** none.
- **Edge cases:** media item with `media_type` unset/null (treated as non-video, key omitted); a media item whose stored `_mvs_chapters` JSON is corrupted (returns `[]`, not an error).

#### MV-VID-003 — Chapter privacy on read (private video)
- **Edition:** Pro
- **Who:** Any role, including logged-out visitors.
- **Where:** `GET /mvs-pro/v1/media/{id}/chapters` on a media item marked private/restricted.
- **Setup:** A private video with chapters set, viewed by a user who is not the owner and has no grant.
- **Steps:** 1) Request chapters as the owner. 2) Request as an unrelated logged-in member. 3) Request logged out.
- **Expected:** Step 1: 200 with chapters. Steps 2 & 3: 404 `mvs_pro_not_found` "Media item not found." — never 403, so the response can't be used to confirm a private video exists (fixed in 2.5.1, which previously leaked the chapter list of any private video to anyone, signed out included).
- **UX expectation:** A caller must not be able to distinguish "media doesn't exist" from "media exists but you can't see it" — both must render identically (a generic "not found") in any client built against this route. Any client that shows a different message for the two cases has misused the route (both are meant to be indistinguishable).
- **Settings that change it:** whatever the media item's own privacy/visibility setting is (Free's privacy model), not a Pro-specific toggle.
- **Edge cases:** owner account deleted. Confirmed: this is a non-issue. The author lookup used for both the write-permission check and the media row itself is a plain integer column read (the stored author id), never a live `get_userdata()`/user-object lookup — so a deleted owner account changes nothing: the comparison and the privacy check behave identically whether or not that user id still has a live account.

#### MV-VID-004 — Web player auto-resume on load
- **Edition:** Pro (requires Pro active) + Free's `mvs/media-player` block, logged-in member only.
- **Who:** Logged-in members only — logged-out visitors get no resume at all (`resumeUrl` is only populated server-side when `is_user_logged_in()`).
- **Where:** Any frontend page rendering the `mvs/media-player` block for a video longer than 120 seconds (audio players never show this — the resume chip markup only exists in the video branch of `render.php`).
- **Setup:** A member watches a video >120s past 5 seconds and leaves (pause, navigate away, or close the tab) before reaching 95% completion, so a position is saved server-side (`ResumeService::COMPLETION_THRESHOLD = 0.95`).
- **Steps:** 1) As Member A, play a 5-minute video to ~1:00 and close the tab. 2) Reopen the same video page as Member A. 3) Reopen the same video page as Member B (different logged-in user). 4) Reopen as a logged-out visitor.
- **Expected:** Step 2: on `loadedmetadata`, the player calls `GET /mvs-pro/v1/media/{id}/resume`, seeks `<video>.currentTime` to ~60s, and shows a "Resumed at 1:00" chip with a "Start over" button (auto-hides after 6 seconds). Step 3: no seek, no chip — resume position is per-user (`user_meta` key `_mvs_resume_{media_id}`), not per-video. Step 4: no seek, no chip, no REST call at all (client-side short-circuits on empty `resumeUrl`).
- **UX expectation:** The chip text is "Resumed at {m:ss}" ("Resumed at" prefix is translatable via `wp_interactivity_state()`). "Start over" is a visible button, not a link — clicking it resets `currentTime` to 0, clears the saved position (fires DELETE), and hides the chip immediately, no confirm dialog (resetting playback position is non-destructive/low-stakes, so no confirm is appropriate). The chip is `hidden` by default via `data-wp-bind--hidden`, so on every normal cold load with nothing to resume, nothing appears — that silence is correct, not a missing feature. Loading is invisible (no spinner) since the resume check happens in the background after metadata loads; if the REST call fails or errors, resume silently no-ops and playback proceeds from 0 — this must never surface an error to the viewer (the JS catch block is empty by design).
- **Settings that change it:** none exposed in UI — the 120s minimum duration, 5s minimum position, and 95% near-end cutoff are hardcoded constants; there is no admin toggle to disable resume specifically.
- **Edge cases:** video exactly at the 120s boundary (2:00.000) does NOT qualify, only strictly over; saved position within 5s of start is ignored (nothing to resume); saved position at ≥95% of duration is ignored client-side even if somehow still present server-side; Pro deactivated mid-session (no resume, no error, same as guest).

#### MV-VID-005 — Resume position saving during playback
- **Edition:** Pro, logged-in member, video only.
- **Who:** Logged-in member watching a qualifying video.
- **Where:** Same `mvs/media-player` block, client-driven REST calls to `POST /mvs-pro/v1/media/{id}/resume`.
- **Setup:** Member is mid-playback of a qualifying video.
- **Steps:** 1) Play, then pause at 0:45. 2) Resume playing and let `timeupdate` fire repeatedly for >15s. 3) Close/hide the tab while playing (pagehide or visibilitychange).
- **Expected:** Step 1: pause triggers an immediate save (`POST {position: 45}`). Step 2: saves are throttled to at most one per 15 seconds during continuous play, not on every `timeupdate` tick. Step 3: `flushResumeOnHide()` fires a best-effort `fetch(...,{keepalive:true})` (not the wrapped `mvsRest.restFetch`) so the save survives the page unloading. Server-side, `ResumeService::save_position()` auto-clears (deletes) the position instead of storing it once `position >= duration * 0.95`.
- **UX expectation:** All of this is invisible to the viewer — no toast, no indicator that "your position was saved." Failures are swallowed (`.catch(() => {})` on every save call) and must never interrupt or visibly affect playback; a QA report of "no confirmation that progress saved" is not a bug, it's the intended silent behavior.
- **Settings that change it:** none.
- **Edge cases:** a save request racing a resume GET on the very next page load (server-side is last-write-wins on `update_user_meta`, no lock); saving `position: 0` (rejected server-side only by a `$position < 0` check, so 0 IS accepted and stored). Confirmed: the client never sends it in normal use — `isResumeSaveWorthy()` requires `currentTime` to be strictly greater than the 5-second minimum before any save call fires, so position 0 (or anything ≤5s) is filtered out in the browser before the request is ever made; only a direct API call can reach the server's more permissive check.

#### MV-VID-006 — Resume cleared on completion or manual "Start over"
- **Edition:** Pro, logged-in member, video only.
- **Who:** Logged-in member.
- **Where:** `mvs/media-player` block; `DELETE /mvs-pro/v1/media/{id}/resume`.
- **Setup:** Member has a saved resume position for a video.
- **Steps:** 1) Let the video play to the end (`ended` event fires `onComplete`). 2) On a fresh video with a saved position, click "Start over" on the resume chip.
- **Expected:** Both paths call `clearResumePosition()` → DELETE, which returns 204 with no body, and `hideResumeChip()`. Step 2 additionally resets `<video>.currentTime = 0` immediately client-side (does not wait for the DELETE response).
- **UX expectation:** After "Start over," the chip disappears immediately (no fade delay beyond CSS transition) and the video visibly jumps to 0:00 — there should be no visible flash of the old resumed position first. No confirmation dialog before "Start over"; treat that as correct (this is view-state, not data destruction) rather than filing a missing-confirm bug.
- **Settings that change it:** none.
- **Edge cases:** DELETE failing (network drop) — client already hid the chip and reset time optimistically, so a stale server-side position could reappear on the very next load; this is a real (minor) inconsistency window, not one the code guards against — note as a known gap, not a bug to "fix" without a decision.

#### MV-VID-007 — Resume REST endpoints for a direct API client
- **Edition:** Pro.
- **Who:** Any logged-in user (no ownership/role check — everyone can only ever read/write their OWN resume position, keyed by `get_current_user_id()`, so there's no cross-user exposure to test for by role).
- **Where:** `GET/POST/DELETE /mvs-pro/v1/media/{id}/resume`.
- **Setup:** None beyond a valid video media id and an authenticated request (cookie+nonce or Application Password).
- **Steps:** 1) GET as a logged-out request. 2) GET/POST/DELETE as authenticated. 3) POST with a negative `position`.
- **Expected:** Step 1: 401 `mvs_pro_unauthorized` "You must be logged in to use resume playback." Step 2: 200s as documented (POST returns the freshly-read-back position, not just an echo of the input — deliberately re-reads via `get_position()` after `save_position()` so a position that got auto-cleared by the 95% rule is reported accurately rather than echoing the raw input). Step 3: REST schema rejects it (`minimum: 0` on the `position` arg) before it reaches the service.
- **UX expectation:** N/A — pure API surface, no rendered UI to evaluate beyond correct HTTP status/JSON shape.
- **Settings that change it:** none.
- **Edge cases:** resume called against a non-existent media id: 404 `mvs_pro_not_found`. Confirmed server-side: all three resume endpoints (get/save/clear) only check that the media row exists — there is no video-type check like chapters has, so a direct API client CAN save/read/clear a "resume position" against an image, audio file, or document, not just a video. Nothing breaks if this is tried; it's simply more permissive than the docs' "for a video" framing suggests. In normal use nothing triggers this, since the frontend player block only ever calls resume for video.

#### MV-VID-008 — Auto-caption generation on upload
- **Edition:** Pro.
- **Who:** Any member who uploads video/audio media, when the site owner has enabled auto-captions.
- **Where:** Triggered automatically on `mvs_media_uploaded`; no member-facing control to opt in/out per upload.
- **Setup:** Site owner enables the "captions_auto" setting (stored under `mvs_pro_settings['captions_auto']`) and configures an OpenAI API key under Settings > AI & Moderation.
- **Steps:** 1) With auto-captions ON and a valid OpenAI key, upload a short video/audio file. 2) Poll `GET /media/{id}/captions/status` immediately, then again after ~10-30s.
- **Expected:** Step 1: `TranscriptionService::on_media_uploaded()` fires, queues `mvs_pro_transcribe_media` via Action Scheduler (falls back to a single `wp_schedule_single_event` 5s out if AS isn't available), status becomes `queued` immediately. Step 2: status transitions `queued` → `processing` → `complete`, and once complete `GET /media/{id}/captions` returns a `vtt_url` pointing at `{uploads}/mvs-captions/{media_id}.vtt`.
- **UX expectation:** No member-visible progress indicator anywhere in the upload flow itself — captioning happens silently in the background after the upload response has already returned. A member has no way to know captioning is even running unless they separately poll the status endpoint (API client only); this must not be reported as "captions don't show progress" since no web surface for this exists.
- **Settings that change it:** `captions_auto` (bool, default false, key inside `mvs_pro_settings`) — the master on/off for automatic generation. Language is a separate setting (`captions_language`, default `'en'`; `'auto'` maps to empty string = provider auto-detect).
- **Edge cases:** uploading a non-video/audio file (never queued); uploading while a previous job for the same media is still `queued`/`processing` (deduped, no second job, unless the existing job is stale — see MV-VID-010); file >25MB (Whisper's hard cap — job fails, see MV-VID-011).

#### MV-VID-009 — Manually request caption generation + status polling
- **Edition:** Pro.
- **Who:** Media author or a user with `manage_mvs_settings`. Read (`GET .../captions`, `.../captions/status`): confirmed defect — this only checks that the media row exists, with NO privacy check at all. Unlike the equivalent chapters route (which checks the Free privacy model and returns a privacy-preserving 404 for anything the caller can't see), the captions read permission callback never calls the privacy service, so anyone — including a logged-out visitor — can read a private or members-only video's caption metadata (language, word count, duration, generation timestamp) and its `vtt_url` just by knowing the media id. This is the same bug class that chapters had before being fixed in 2.5.1, left unfixed on the captions route.
- **Where:** `POST /mvs-pro/v1/media/{id}/captions/generate`, `GET /mvs-pro/v1/media/{id}/captions/status`.
- **Setup:** OpenAI key configured; media item is video or audio.
- **Steps:** 1) As a non-owner, non-admin member, POST generate. 2) As the owner, POST generate on an image (non video/audio) item. 3) As the owner, POST generate on a valid video, then poll status.
- **Expected:** Step 1: 403 `mvs_pro_forbidden`. Step 2: 422 `mvs_pro_captions_unsupported_type` "Captions can only be generated for video or audio media." Step 3: 202 Accepted with `{status: "queued", message: "Transcription has been queued. Check the status endpoint to track progress."}`; status endpoint returns `{status: "processing"}` then `{status: "complete", vtt_url: "..."}`.
- **UX expectation:** The 202 response is the entire feedback loop for this action — there is no member-facing "Generate captions" button anywhere in the templates for the member-facing side. Confirmed: no admin-side "Generate captions" action exists either — the admin Media list's only bulk actions are Trash/Restore/Delete, and its per-item AI actions ("AI re-run", "AI reject") are a completely different feature (AI description/tags, not video/audio caption transcription); nothing anywhere triggers caption generation except the direct REST call. Do not expect a progress bar; a client must poll.
- **Settings that change it:** none beyond the OpenAI key being present (`provider_is_available()` gates the whole endpoint with 503 if absent — see MV-VID-011).
- **Edge cases:** calling generate while a job is already `queued`/`processing` for the same media (silently no-ops server-side via dedup, but the endpoint still returns 202 as if freshly queued — a client can't tell the difference between "just queued" and "already in flight").

#### MV-VID-010 — Caption job stale-processing reaper and retry
- **Edition:** Pro.
- **Who:** System/cron — not member or admin triggered.
- **Where:** Recurring Action Scheduler hook `mvs_pro_captions_reap_stale`, scheduled every 5 minutes (`REAPER_INTERVAL_SECONDS`), keyset-paginated 100 rows per tick (`REAPER_BATCH_SIZE`).
- **Setup:** A caption job stuck in `processing` for longer than 15 minutes (`PROCESSING_TIMEOUT`) — e.g. a dead Action Scheduler worker or a killed PHP-FPM process mid-transcription.
- **Steps:** 1) Simulate (or wait for) a job stuck in `processing` past 15 minutes. 2) Observe the next reaper tick. 3) Re-request generation for that same media item.
- **Expected:** Step 2: reaper marks it `failed` with reason "Transcription timed out and was automatically stopped. Try generating captions again." and fires `mvs_pro_captions_reaped`. Step 3: because status is no longer `queued`/`processing`, `queue_transcription()` re-queues normally (no stale-block).
- **UX expectation:** From a member's perspective this looks identical to any other caption failure (`{status:"failed", reason: "..."}`) — nothing reaper-specific is shown; the message is generic and actionable ("try generating captions again").
- **Settings that change it:** none — `PROCESSING_TIMEOUT` (15 min), `REAPER_BATCH_SIZE` (100), `REAPER_INTERVAL_SECONDS` (5 min) are class constants, no admin field.
- **Edge cases:** a pre-1.9.0 row with no `_mvs_captions_started_at` timestamp at all — treated as immediately stale on the very next tick (self-healing for old stuck rows); a backlog >100 stale rows in one tick continues via an async cursor rather than blocking one long-running tick.

#### MV-VID-011 — Whisper key missing/invalid, and oversized file
- **Edition:** Pro.
- **Who:** Whoever can trigger generation (owner/admin) or upload (auto path).
- **Where:** `POST /media/{id}/captions/generate`; `Integrations/Whisper/CaptionProvider`.
- **Setup:** Remove or blank the OpenAI key (Free's `mvs_openai_api_key` option), or configure an invalid key.
- **Steps:** 1) With no key set, POST generate. 2) With an invalid key set, let an auto-queued job run. 3) Upload a >25MB video/audio file with a valid key and auto-captions on.
- **Expected:** Step 1: the endpoint itself refuses before queuing — 503 `mvs_pro_provider_unavailable` "Transcription provider is not configured. Add your OpenAI API key under Settings > AI & Moderation." Step 2: the job runs, Whisper responds with a non-200, and `store_error()` sets status `failed` with OpenAI's own error message as `captions_error`. Step 3: rejected before any API call, with "File is too large for Whisper (XX.XMB). Maximum is 25MB." stored as the failure reason.
- **UX expectation:** In all three cases the member sees no interruption to their upload (captions failing never blocks or errors the upload itself — it's fire-and-forget). The only way to learn WHY captioning failed is `GET .../captions/status` returning `{status:"failed", reason: "<specific message>"}`; if no reason is on file it falls back to "Unknown error. Check the site error log." — verify that fallback never masks a real captured error.
- **Settings that change it:** the OpenAI key is a Free-plugin setting (Settings > AI & Moderation), not a Pro one — Pro's caption feature depends on Free's key, there's no separate Pro-only key field for Whisper specifically.
- **Edge cases:** key present but revoked mid-job (same as invalid-key path, generic API error message from OpenAI passed through); file exactly at 25MB boundary (code uses `$file_size > self::MAX_BYTES`, so exactly 26214400 bytes passes, one byte over fails).

#### MV-VID-012 — Manual caption upload/replace and delete
- **Edition:** Pro.
- **Who:** Media author or `manage_mvs_settings`.
- **Where:** `PUT /media/{id}/captions` (`vtt_content` string body), `DELETE /media/{id}/captions`.
- **Setup:** none beyond ownership.
- **Steps:** 1) PUT a plain-text string that does NOT start with "WEBVTT". 2) PUT valid WebVTT >500KB. 3) PUT valid, small WebVTT over an existing auto-generated caption. 4) DELETE captions.
- **Expected:** Step 1: 400 `mvs_pro_vtt_invalid` "VTT content must begin with the WEBVTT header." Step 2: 400 `mvs_pro_vtt_too_large` "VTT content exceeds the maximum allowed size (500 KB)." Step 3: old auto file is deleted first, new file written, meta rewritten with `provider: "manual"`, `language: "manual"`, returns "Captions saved successfully." Step 4: file removed from disk, all caption meta cleared, 204 No Content.
- **UX expectation:** No member-facing form exists for this either — same API-client-only pattern as chapters. The sanitizer strips ALL HTML tags from the submitted VTT before validating the WEBVTT header, so a payload with HTML-wrapped cue text is stripped, not rejected — a client sending rich-text-formatted VTT will see its formatting silently vanish, not an error.
- **Settings that change it:** none.
- **Edge cases:** empty string submitted (400 `mvs_pro_vtt_empty` "VTT content cannot be empty."); DELETE on media with no existing captions (still 204, no error for deleting nothing).

#### MV-VID-013 — Web visitor sees no captions ever, even after generation succeeds
- **Edition:** Pro (feature exists) / Free (player renders).
- **Who:** Every web visitor, including ones with captions/subtitles turned on at the OS or browser level.
- **Where:** Any frontend page playing the video via `mvs/media-player`.
- **Setup:** Captions successfully generated (`captions_status: complete`, `captions_url` populated) for a video.
- **Steps:** 1) Play the video in a browser. 2) Try to enable captions via the native `<video>` controls' CC button.
- **Expected:** There is no CC button at all in the native controls, because there is no `<track kind="subtitles">` child element anywhere in `render.php` (confirmed via a full-repo grep of `src/`, `templates/`, `build/` in both plugins — zero `<track` hits). The `.vtt` file exists on disk and is publicly fetchable at its URL (protected only by an `Options -Indexes` `.htaccess`, not by auth), but the browser has no way to discover or load it as a caption track.
- **UX expectation:** This must present as complete silence — no broken CC icon, no "captions unavailable" message, nothing. A QA pass that plays a video expecting to see captions after generation is confirmed complete will correctly observe nothing and must NOT file this as a bug; it is the current, honest state of the feature (captions data exists for an API client, not for the bundled web player). Do not "fix" this by wiring a `<track>` element without an explicit product decision — that changes the caption file's exposure model (currently unauthenticated-fetchable but obscure; wiring it into `<track>` makes it directly discoverable in page source).
- **Settings that change it:** none — no setting toggles caption rendering because rendering doesn't exist.
- **Edge cases:** a private video's `.vtt` file is still fetchable by anyone with the URL (no privacy check on the static file itself, only obscurity) — worth flagging as a soft privacy gap, not a functional bug, since nothing links to it publicly.

#### MV-VID-014 — Video Analytics admin dashboard (overview, Top 10, per-media detail)
- **Edition:** Pro.
- **Who:** `manage_mvs_settings` only — everyone else gets `wp_die()` on direct access, or the tab simply isn't visible.
- **Where:** wp-admin, WPMediaVerse → Stats → "Analytics" tab (`admin.php?page=mvs-analytics`, hidden submenu under `mvs-stats`, URL-routable but not in sidebar). Per-media drill-down: same page with `?media_id={id}`.
- **Setup:** At least one recorded play event (from real playback, or a direct POST to `/media/{id}/events` for testing).
- **Steps:** 1) Visit the Analytics tab with zero play events recorded anywhere. 2) Visit after some plays/pauses/completions exist. 3) Click "View Analytics" on a Top-10 row.
- **Expected:** Step 1: empty state text "No play events recorded yet. The player will start sending events once viewers watch your videos." plus zeroed summary cards (Plays Today, Plays This Week, Avg Engagement, Top Media Tracked). Step 2: summary cards populate; Top 10 table (30-day window, sorted by engagement by default) shows Media Title (linked to permalink), Plays, a Completion % bar, an Engagement Score bar (0-100), "View Analytics" per row. Step 3: navigates to the per-media detail view with a heatmap (50 buckets), retention curve (sampled every 5 percentile points from 0-100), completion rate, avg watch duration, engagement score, top-5 drop-off points (5-second buckets ranked by pause/seek frequency).
- **UX expectation:** The page banner text explicitly states "Data covers the last 90 days of raw events" — this must match the actual pruning window (see MV-VID-015); if that copy and the cron constant ever drift, that's a real bug. A deleted media item shows as "(deleted)" in the Top 10 title column rather than a blank or broken link.
- **Settings that change it:** none — no filter/sort controls beyond the hardcoded 30-day/top-10/engagement-sort default on the overview (the underlying service DOES support `period`/`sort` params, but the admin page doesn't expose UI controls for them). Confirmed not a defect: the docs only ever describe the tab and its detail view, never a filter/sort control on the overview, so nothing promised is missing — the REST route `/analytics/top` exposes all three as query params for an API client/future UI, unused by the current admin page.
- **Edge cases:** a media item with plays but zero duration ever recorded (heatmap returns all-zero buckets, no divide-by-zero); 390px — standard wp-admin `wrap`/`wp-list-table` page with no custom mobile CSS observed; verify bars/table don't overflow at 390px.

#### MV-VID-015 — Play event ingestion, rate limiting, retention pruning, and delete-cleanup
- **Edition:** Pro.
- **Who:** Ingestion (`POST /media/{id}/events`) is `__return_true` — open to everyone including logged-out visitors, by design (mirrors Free's own view-counting route).
- **Where:** `POST /mvs-pro/v1/media/{id}/events`; daily cron `mvs_pro_prune_play_events`; `mvs_media_deleted` hook.
- **Setup:** none for ingestion; for pruning, events older than 90 days; for cleanup, a media item with recorded events gets deleted.
- **Steps:** 1) POST the same `session_id` twice within one second. 2) POST with an invalid `event_type` (not in play/pause/seek/complete/buffer). 3) Delete a media item that has play events. 4) Wait for (or manually trigger) the daily prune.
- **Expected:** Step 1: second call is silently rate-limited (1 event per session_id per second via a 1-second transient lock) and returns `{recorded: false}` with a 200, not an error. Step 2: REST schema rejects it before reaching the service (enum validation). Step 3: `AnalyticsService::forget_media()` hard-deletes all `mvs_play_events` rows for that media id via the `mvs_media_deleted` hook — analytics do not outlive the video. Step 4: rows older than 90 days are deleted in batches of 5000, up to 50 batches (250k rows) per cron run.
- **UX expectation:** A rejected/rate-limited event must never surface as a player-visible error — the `{recorded:false}` 200 response is deliberately non-alarming. Nothing about pruning or the delete-cleanup is visible anywhere in the UI; the only observable proof is that Analytics numbers for a deleted video disappear immediately, and very old data no longer shows up in a heatmap.
- **Settings that change it:** none — 90-day retention, 1-second rate limit, 5000-row batch, 50-batch cap are all hardcoded.
- **Edge cases:** a prune run hitting exactly 50 batches (250k rows) in one tick — not resumable within the same run; a genuinely larger backlog needs more than one day's cron tick to fully catch up — not a bug, a known/accepted ceiling.

### Area: PRV

#### MV-PRV-001 — Update privacy on a single media item (owner/admin only, with locked-site refusal)
- **Edition:** Pro
- **Who:** Media owner OR a role with `moderate_mvs_media`; refused for everyone else and logged-out users
- **Where:** `PUT /wp-json/mvs-pro/v1/media/{id}/privacy`
- **Setup:** Media item exists; caller owns it or moderates
- **Steps:** 1) As owner, PUT `{ privacy: "friends" }` on your own item. 2) As a different member, attempt the same — expect refusal. 3) As admin/moderator, attempt on someone else's item — expect success.
- **Expected:** 401 `mvs_pro_unauthorized` if logged out. 404 `mvs_pro_not_found` if the media id doesn't exist. 403 `mvs_pro_forbidden` if logged in but neither owner nor moderator. On success: 200 with `{ success: true, media_id, privacy, inherit_album, message: 'Privacy updated to "<Label>".' }` where Label is one of Everyone/Logged-in Members/Friends Only/Group Members/Only Me/Specific People.
- **UX expectation:** Re-submitting the SAME privacy level the item already has must succeed even when the site owner has the privacy lock ON (see MV-PRV-003) — the lock only blocks an actual CHANGE. A picker UI that always resubmits the current level on save (e.g., an unrelated field edit) must not start failing once the owner locks privacy.
- **Settings that change it:** none for who CAN act; see MV-PRV-003 for the lock.
- **Edge cases:** `custom` level with an empty `custom_users` array — code only rewrites the custom list "for a non-empty custom" request, so submitting `custom` with no users leaves the PREVIOUS custom list in place rather than clearing it; verify this doesn't surprise a member who expected the picker's empty state to mean "no one."

#### MV-PRV-002 — Bulk privacy update across multiple items
- **Edition:** Pro
- **Who:** Logged-in member (ownership checked per-item, not by the permission callback)
- **Where:** `POST /wp-json/mvs-pro/v1/media/bulk-privacy`
- **Setup:** Member owns some, not all, of a set of media IDs
- **Steps:** 1) Select up to 100 media IDs, some owned, some not. 2) POST `{ media_ids: [...], privacy: "private" }`.
- **Expected:** Non-owned items are silently split into `skipped`, never erroring the whole batch; owned items land in `updated`. Message text: "%d item(s) updated to \"<Label>\"." plus, if any skipped, " %d item(s) were skipped because you do not own them." If ZERO items updated (e.g., all skipped), response is still 200 (not an error status) with `success: false` and "No items were updated. You may not have permission to change privacy on the selected items."
- **UX expectation:** A caller must read `success`/`skipped.length`, not just HTTP status, to know whether anything actually happened — a naive "200 = success" toast would misreport a fully-skipped bulk action as a success. Max 100 IDs per request enforced by the REST schema — a larger selection must be chunked client-side or is rejected at the schema level.
- **Settings that change it:** admins bypass the ownership check via `moderate_mvs_media`.
- **Edge cases:** Confirmed defect: the customer-facing doc describes an admin "Media > All Media" Bulk Actions "Change Privacy" option calling this same endpoint, with a 4-step walkthrough. MediaVerse's own "All Media" admin page does have a real Bulk Actions dropdown with checkboxes and an Apply button, but its only options are "Move to Trash" (or "Restore"/"Delete permanently" in Trash view) — there is no "Change Privacy" option anywhere in that dropdown. The REST endpoint works exactly as documented; only the admin bulk-action UI described in the doc does not exist.

#### MV-PRV-003 — Privacy lock: Free's "Allow Users to Set Privacy" honored by Pro's routes
- **Edition:** Pro routes honoring a Free setting
- **Who:** Site owner (toggles the lock) + any non-privileged member (feels the effect)
- **Where:** Free: Settings > General, "Allow Users to Set Privacy" checkbox (`mvs_allow_user_privacy`, DEFAULT ON/true — out of the box members CAN change privacy; the owner opts INTO the lock by switching it off). Pro: both `PUT /media/{id}/privacy` and `POST /media/bulk-privacy`.
- **Setup:** Turn `mvs_allow_user_privacy` OFF
- **Steps:** 1) As a regular member, attempt to change an item's privacy to a DIFFERENT level via Pro's single-item route. 2) Attempt a bulk-privacy change as the same member. 3) Re-submit the item's CURRENT level unchanged (no-op save). 4) Attempt both as an admin/site owner.
- **Expected:** (1) 403 `mvs_privacy_locked`, "Privacy is set by the site owner, so it cannot be changed here." — same code/message as Free's own media routes, deliberately, so a client handles the lock identically regardless of which namespace it called. (2) Bulk is refused OUTRIGHT before even checking ownership — no partial processing. (3) Succeeds. (4) Site owner/anyone with `manage_mvs_settings` capability is exempt from the lock entirely and can always change any item's privacy.
- **UX expectation:** A frontend privacy picker should be HIDDEN (not just disabled-with-error) once locked — if any Pro-side UI still renders an active picker while locked, that's a design mismatch to flag, since the correct behavior is to not show the control at all, not to show it and then 403 on save.
- **Settings that change it:** "Allow Users to Set Privacy" (`mvs_allow_user_privacy`, Free plugin, Settings > General).
- **Edge cases:** An older Free version that predates the lock-check method is handled gracefully — Pro checks `method_exists()` first and defaults to allowing the change if Free doesn't support the lock at all, rather than erroring.

#### MV-PRV-004 — "Followers Only" privacy level is not actually enforceable (likely bug)
- **Edition:** Pro
- **Who:** Any member who sets an item to "Followers Only"; any of their followers trying to view it
- **Where:** Privacy picker offering the `followers` option (surfaced via `privacy_options` in the media REST response, `PrivacyUIService::PRIVACY_LEVELS`)
- **Setup:** None special
- **Steps:** 1) Set a media item's privacy to `followers` via `PUT /media/{id}/privacy` (Pro accepts it — it's in Pro's own valid-levels list). 2) As one of the owner's actual followers, attempt to view the item.
- **Expected (found via code, not assumption):** Free's actual visibility engine, `PrivacyService::can_view()`, has NO `case 'followers'` in its switch statement — it falls to `default: return false`. So the item becomes invisible to EVERYONE except the owner and moderators, functionally identical to "Only Me," while the UI describes it as "Only people who follow you can see this." Free's own write-time validation list also does not include `followers` at all.
- **UX expectation:** A follower who should be able to see the item sees nothing/a 403/404 depending on how the calling surface handles a denied `can_view()` — there is no error message that would tell either the owner or the follower why. This is worth a dedicated bug report rather than accepting it as expected behavior — the label and description actively promise something the code cannot deliver.
- **Settings that change it:** none — this is a code-level gap, not a setting.
- **Edge cases:** Confirm this reproduces on both the web (if a picker surfaces this option anywhere) and via direct REST call, and confirm it isn't secretly handled by an as-yet-unfound filter listener elsewhere in Pro.

#### MV-PRV-005 — Privacy presets: save and retrieve
- **Edition:** Pro
- **Who:** Logged-in member, own presets only (stored as personal user meta, never visible to others)
- **Where:** `GET /wp-json/mvs-pro/v1/privacy/presets`, `POST /wp-json/mvs-pro/v1/privacy/presets`
- **Setup:** None
- **Steps:** 1) POST `{ name: "Close friends only", privacy: "friends" }`. 2) GET presets, confirm it's listed. 3) Repeat until 20 presets exist. 4) Attempt a 21st.
- **Expected:** 201 Created on success with the saved preset object (`id`, `name`, `privacy`, `custom_users`, `created_at`). At 20 presets, further saves return 400 `mvs_pro_preset_save_failed`, "The preset could not be saved. You may have reached the maximum of 20 presets, or the name was empty." Empty `name` after sanitization also hits this same error.
- **UX expectation:** The error message deliberately conflates two distinct causes (cap reached vs empty name) into one string — a UI showing this verbatim should make clear to the member which one actually happened if it can tell, rather than leaving them guessing.
- **Settings that change it:** none (20-preset cap is a hardcoded constant, not a setting).
- **Edge cases:** Confirmed defect: the customer doc describes a full `is_default` feature ("Setting `is_default` to true makes this preset the pre-selected option on the upload form... Only one preset can be the default; saving a new default clears the flag from the previous one"), but the actual preset shape saved by the code is only `id, name, privacy, custom_users, created_at` — no `is_default` field exists anywhere in the plugin, and there is no upload-form auto-selection logic tied to any such flag. This is a planned-but-unbuilt feature described as if it ships; not a UI bug to chase, a docs correction (or a real feature gap) to flag.

#### MV-PRV-006 — Presets: relevance to current item, surfaced in media response
- **Edition:** Pro
- **Who:** Media owner viewing their own item's detail data
- **Where:** `privacy_preset_ids` field appended to every media REST response via `mvs_media_response` filter
- **Setup:** Owner has at least one saved preset matching the item's current privacy level (and, for `custom`, the exact same user list)
- **Steps:** 1) Save a preset matching an item's current privacy exactly. 2) Fetch that media item's REST response. 3) Confirm the preset's id appears in `privacy_preset_ids`.
- **Expected:** Matching is exact — for `custom` level, the preset's user list must match the item's current custom-access list element-for-element (sorted comparison) to be considered matching; a partial overlap does not count.
- **UX expectation:** This is informational metadata for a client UI to pre-highlight a matching preset in a dropdown — if no frontend consumes this field yet (no JS reading `privacy_preset_ids` was found in Pro's `src/`), flag that the REST contract exists ahead of any UI that uses it; not itself a bug, but a "wired on one side only" gap worth a note in the three-entry-points check.
- **Settings that change it:** none.
- **Edge cases:** Media authored by a deleted/nonexistent user → short-circuits and returns an empty array cleanly.

#### MV-PRV-007 — Album-level "inherit album privacy"
- **Edition:** Pro
- **Who:** Album owner/moderator
- **Where:** `inherit_album` param on `PUT /media/{id}/privacy`; enforced via the `mvs_privacy_can_view` filter at priority 5 (`PrivacyUIService::check_album_inheritance()`)
- **Setup:** Media item belongs to an album (`_mvs_album_id` meta) and has `_mvs_inherit_album_privacy` set true
- **Steps:** 1) Set an item to inherit its album's privacy. 2) Change the ALBUM's privacy level (public <-> restricted). 3) Confirm the media item's effective visibility follows the album without its own privacy field needing to change.
- **Expected:** Owner/admin (moderator) always pass regardless of inheritance. Non-privileged viewers are answered by re-asking Free's privacy engine against the ALBUM id in the correct (Space CPT) id-space specifically — never falls back to reading some unrelated row that happens to share the album's numeric id, a documented past bug class (id-space collision).
- **UX expectation:** Per the 2.6.0 changelog note, this now works in BOTH directions — making an album public again restores the photo's visibility, and a photo's own original privacy setting is preserved (not destroyed) while it inherits, then restored if it later leaves the album. Verify a photo removed from an inheriting album returns to its pre-inheritance privacy, not to some default.
- **Settings that change it:** `mvs_album_inherit_privacy` filter (site-owner/developer opt-out only, no admin UI checkbox found — filter-only).
- **Edge cases:** Album id resolves to a post that is NOT actually an `mvs_album` CPT (stale/corrupted meta) → inheritance check safely falls through to `null` (defers to normal privacy) rather than erroring or leaking.

#### MV-PRV-008 — Flickr import sync no longer overrides privacy while locked
- **Edition:** Pro (Flickr connector integration)
- **Who:** Member with a connected Flickr account; site owner controlling the lock
- **Where:** `Integrations/Flickr/Connector.php` sync path, gated by `PrivacyUIService::user_may_choose_privacy()`
- **Setup:** Flickr account connected; `mvs_allow_user_privacy` OFF (locked)
- **Steps:** 1) With the lock ON, import/re-sync a photo from Flickr whose Flickr-side visibility (public/friends/family) differs from the item's current MediaVerse privacy. 2) Confirm MediaVerse's privacy value is NOT overwritten by the sync while locked. 3) Turn the lock OFF and repeat — confirm privacy now DOES sync from Flickr's flags as before.
- **Expected:** The import/sync of title, description, and tags proceeds regardless of the lock; only the PRIVACY field's overwrite is skipped when locked.
- **UX expectation:** No visible notice to the member that their Flickr privacy setting was "ignored" — this is a silent, correct-by-design skip; don't mistake the absence of a sync as a connector failure when checking Flickr integration health while the lock is on.
- **Settings that change it:** "Allow Users to Set Privacy" (same lock as MV-PRV-003).
- **Edge cases:** A photo synced for the FIRST time (no prior MediaVerse privacy) while locked — confirm it lands on the site's configured Default Privacy Level rather than Flickr's flag or an empty value.

#### MV-PRV-009 — GDPR export/erase covers Pro's privacy-related data
- **Edition:** Pro registering into Free's data-lifecycle map
- **Who:** Site owner running a GDPR export/erasure request; the member whose data it is
- **Where:** `Privacy/ProMemberData.php`, hooks `mvs_member_erase_map` / `mvs_member_retain_map` / `mvs_user_data_purged`; standard wp-admin Tools > Export/Erase Personal Data
- **Setup:** Member has push device tokens, saved collections, and (per MV-PRV-005/006) privacy presets in their account
- **Steps:** 1) Run a personal-data ERASE request for a member who has push tokens and collections. 2) Confirm both are removed (device tokens table entry included specifically because leaving it would let the site keep pushing notifications to a "forgotten" member's phone). 3) Run a personal-data EXPORT and confirm the privacy policy addendum text is present under Settings > Privacy, mentioning push delivery through Expo, Flickr token storage, and what is deleted vs retained on erasure.
- **Expected:** On erasure: boosts, collections, and playback records are deleted; competition entries other members participated alongside are RETAINED with the member's name/identity removed (not deleted outright, to avoid corrupting shared competition data) — this exact policy is spelled out in the privacy-policy text Pro adds.
- **UX expectation:** The erasure/export tools are core WP screens, not custom Pro UI — verify Pro's rows actually appear in the standard admin "Personal Data Export/Erasure" request results list, not just that the privacy-policy prose claims they will.
- **Settings that change it:** none — this is compliance plumbing, always active when Pro is active.
- **Edge cases:** Privacy PRESETS (user meta, MV-PRV-005): confirmed defect — `_mvs_privacy_presets` is NOT covered by anything. Pro's erase/retain map (`mvs_member_erase_map`/`mvs_member_retain_map`) is explicitly table-only by design ("the single source of truth for every member-bearing TABLE"), never touches user meta, and neither Free's nor Pro's account-deletion purge deletes this meta key either (the purge handler only ever runs `$wpdb->delete()`/`update()` against tables from that same map). No exporter or eraser is registered for it anywhere. A GDPR export/erase request or a full account-deletion purge all leave a member's saved privacy presets (including any custom "close friends" user-id lists) behind indefinitely.

### Area: STO

#### MV-STO-001 — Configure Amazon S3 as active storage driver
- **Edition:** Pro
- **Who:** Admin/Super Admin only (settings gated by `manage_mvs_settings` cap). No frontend member access.
- **Where:** wp-admin -> WPMediaVerse -> Settings -> Storage tab (`admin.php?page=mvs-settings#storage`), section "Amazon S3 Storage", visible only when `mvs_storage_driver=s3` (settings sections use `show_when` client-side toggle).
- **Setup:** none beyond WP install. Fields: `mvs_pro_s3_bucket` (Bucket Name), `mvs_pro_s3_region` (default `us-east-1`), `mvs_pro_s3_access_key` (Access Key ID, masked), `mvs_pro_s3_secret_key` (Secret Access Key, masked), `mvs_pro_s3_cdn_domain` (optional custom CDN domain).
- **Steps:** 1) Set Storage driver select to Amazon S3. 2) Fill bucket, region, access key, secret key. 3) Save Changes. 4) Click "Test Connection" (AJAX `mvs_pro_test_s3`).
- **Expected:** Settings persist; `is_configured()` requires bucket+key+secret non-empty else `store()` returns false silently (no user-facing message from the driver itself — only ConnectionTester surfaces "S3 credentials are not configured."). Constants `MVS_PRO_AWS_ACCESS_KEY`/`MVS_PRO_AWS_SECRET_KEY` in wp-config.php take precedence over DB-stored values if defined.
- **UX expectation:** Test Connection success message: "Connected to S3 bucket "{bucket}" in {region}." Failure: "Failed to upload test file to S3." (bare — S3 driver does not implement `get_last_error()`, unlike BunnyCDN). No confirm dialog needed (non-destructive). Section only renders when S3 is the selected driver (other three driver sections hidden via `show_when`), so switching the driver dropdown must show/hide sections without a page reload — verify this happens live.
- **Settings that change it:** `mvs_storage_driver=s3` reveals the section; each field above controls upload destination directly.
- **Edge cases:** wp-config constant defined but DB fields blank (test must still pass); blank bucket/keys on Save (test button should report "not configured", not attempt upload); 390px admin viewport — form-table must stack; upload retries 3x with 1s/2s backoff on `put_object` failure (visible only in error_log, not UI).

#### MV-STO-002 — Configure BunnyCDN as active storage driver
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> Storage tab, section shown when `mvs_storage_driver=bunnycdn`.
- **Setup:** Fields: `mvs_pro_bunny_zone` (Storage Zone Name), `mvs_pro_bunny_api_key` (masked; described in code as "The read+write password from 'FTP & API Access'. Not your account login."), `mvs_pro_bunny_region` (dropdown, endpoint resolved via `Core\Regions::BUNNY`), `mvs_pro_bunny_cdn_hostname` (optional pull-zone hostname).
- **Steps:** 1) Select BunnyCDN driver. 2) Enter zone name + FTP&API password + region. 3) Save. 4) Test Connection (`mvs_pro_test_bunny`).
- **Expected:** `exists()`/upload/delete round-trip via Range-GET (BunnyCDN storage API has no HEAD support — verified in code: 401 on HEAD, so a `Range: bytes=0-0` GET is used instead, accepting 200 or 206 as "exists").
- **UX expectation:** Wrong region or wrong password both surface as the SAME message text from `describe_http_failure()`: "BunnyCDN rejected the credentials (HTTP 401) at {endpoint}. Two things produce this: the Storage Zone password is wrong (it is the FTP & API Access password, not the account API key), or the zone lives in a different region — BunnyCDN answers 401 rather than 404 when the region is wrong, so check the Region setting matches where the zone was created." This exact reason string is surfaced by ConnectionTester via `get_last_error()` (BunnyCDN is the only driver wired to report a specific reason instead of a generic "Failed to upload test file" fallback). 404 message names the zone; 5xx message says "usually temporary; the upload is retried automatically."; unknown codes get a raw "BunnyCDN returned HTTP %d." Any of these appends "Response: {first 120 chars of body}" if present.
- **Settings that change it:** `mvs_storage_driver=bunnycdn`; region dropdown changes the resolved endpoint host used for every request.
- **Edge cases:** Wrong region -> 401, must be verified to show the region-specific hint text above (not a generic auth failure). Large file (>50MB) uploads switch from `wp_remote_request` to a streamed cURL PUT (`STREAM_THRESHOLD` = 52428800 bytes) — verify a >50MB upload still succeeds via the cURL path. 3 retries with exponential backoff (1s, 2s) on failure. `delete()` treats HTTP 404 as success (already-gone case), not failure.

#### MV-STO-003 — Configure Cloudflare R2 as active storage driver
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> Storage tab, section shown when `mvs_storage_driver=r2`.
- **Setup:** Fields: `mvs_pro_r2_account_id`, `mvs_pro_r2_bucket`, `mvs_pro_r2_access_key` (masked), `mvs_pro_r2_secret_key` (masked), `mvs_pro_r2_cdn_domain` (optional; recommended). R2 driver extends the S3 driver (path-style addressing, host = `{account_id}.r2.cloudflarestorage.com`, SigV4 region hardcoded to literal `auto`).
- **Steps:** 1) Select Cloudflare R2. 2) Enter account ID, bucket, access/secret key. 3) Save. 4) Test Connection (`mvs_pro_test_r2`, uses the shared `round_trip()` helper: upload a small test object then delete it).
- **Expected:** `is_configured()` requires ALL FOUR of account_id, bucket, access_key, secret_key (stricter than S3, which does not require an account id).
- **UX expectation:** ProSettings.php shows an ADMIN NOTICE (not a driver error) when `mvs_storage_driver=r2` AND `mvs_pro_r2_cdn_domain` is empty — this is the "no-public-domain" warning called out in the task brief; it lives purely in ProSettings.php notice logic (line ~741-748), never in `StorageDriver.php`. The raw API host (`{account}.r2.cloudflarestorage.com`) is not publicly readable without a custom domain or r2.dev subdomain — confirm the warning text explains this rather than just naming a missing field. Test-connection failure message reuses the generic round-trip failure: "Upload of the test file failed — check the credentials, bucket name, and region."
- **Settings that change it:** `mvs_storage_driver=r2`; leaving `mvs_pro_r2_cdn_domain` blank falls back to the non-public API host for `url()`/`build_public_url()` — every image link literally breaks (403/unreadable) until a domain is set, even though the file uploaded fine.
- **Edge cases:** No CDN domain configured -> admin notice must appear; blank account_id alone should fail `is_configured()` even if bucket/keys are filled; verify path-style URI (`/{bucket}/{key}`) vs S3's virtual-hosted style is not visibly broken for filenames with special characters (rawurlencode is applied).

#### MV-STO-004 — Configure DigitalOcean Spaces as active storage driver
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> Storage tab, section shown when `mvs_storage_driver=dospaces`.
- **Setup:** Fields: `mvs_pro_do_bucket` (Space Name), `mvs_pro_do_region` (default `nyc3`), `mvs_pro_do_access_key` (masked), `mvs_pro_do_secret_key` (masked, "Secret Key"), `mvs_pro_do_cdn_domain` (optional; supports the `{name}.{region}.cdn.digitaloceanspaces.com` pattern or a fully custom domain).
- **Steps:** 1) Select DigitalOcean Spaces. 2) Enter Space name, region, keys. 3) Save. 4) Test Connection (`mvs_pro_test_dospaces`, round-trip helper).
- **Expected:** Host built as `{bucket}.{region}.digitaloceanspaces.com` (virtual-hosted, same addressing style as S3, inherited unchanged from the S3 base class). Success message: "Connected to DigitalOcean Space "{bucket}" in {region}."
- **UX expectation:** No DO-specific error hints exist (unlike BunnyCDN) — a wrong region or wrong key both fall through to the shared round-trip failure text: "Upload of the test file failed — check the credentials, bucket name, and region." Verify this generic message doesn't mislead admins the way BunnyCDN's did before its fix (this is a known gap, not a bug to report unless it demonstrably confuses testers).
- **Settings that change it:** `mvs_storage_driver=dospaces`; region changes the subdomain used for every request (not just a query param), so an existing Space's files become unreachable if the region is later edited to the wrong value.
- **Edge cases:** Blank CDN domain falls back to the raw `{bucket}.{region}.digitaloceanspaces.com` object URL, which the docblock says stays inherited/works like S3 (not gated the way R2 is) — verify images still load without a CDN domain set, unlike R2.

#### MV-STO-005 — Switch active storage driver
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> Storage tab, driver select (option `mvs_storage_driver`, values: `local`, `s3`, `bunnycdn`, `r2`, `dospaces`).
- **Setup:** At least one cloud driver configured with valid credentials before switching, or new uploads will silently fail to store.
- **Steps:** 1) Change driver dropdown. 2) Save Changes. 3) Observe the Storage Management panel per-service distribution table update.
- **Expected:** Only the selected driver's settings section is shown (others hidden by `show_when`). Existing media already on a previous driver does NOT move automatically — the Storage Management panel intro text explicitly says: "Storage is currently set to 'Local'... After switching, move existing media to the new service so it keeps displaying," and once cloud is active: "Manage media files between this server and {driver}."
- **UX expectation:** Storage Management overview table lists every service with a media count + human-readable size, marking the currently active one with an "Active" pill; a service row with 0 files is hidden unless it is the active driver. Switching drivers without migrating leaves old files' URLs pointed at the old service (they keep serving from there) — the guard banner text: "{N} media file(s) are still on their previous location and keep serving from there until you move them to {driver}. Migrate them so all media serves from one place."
- **Settings that change it:** `mvs_storage_driver` itself is the switch.
- **Edge cases:** Switching to `local` while cloud files exist — Migrate/Cleanup buttons on the panel disable/hide (`handle_migrate`/`ajax_migrate_chunk` explicitly refuse with "Active storage is Local. Choose a cloud storage service above first." / "Active driver is local — nothing to migrate to."). Switching between two cloud drivers without migrating leaves files split across two services simultaneously, which the per-service table should show plainly.

#### MV-STO-006 — Migrate all media to active cloud driver (one-click, chunked)
- **Edition:** Pro
- **Who:** Admin only (`current_user_can('manage_options')`, stricter than the `manage_mvs_settings` gate on the settings screen itself).
- **Where:** Settings -> Storage tab, Storage Management panel, "Migrate all" button (`[data-mvs-migrate-all]`). Backed by AJAX action `mvs_pro_cloud_migrate_chunk` (nonce-protected), batch size 20 (`CloudOpsManager::BATCH_SIZE`) per chunk.
- **Setup:** A cloud driver must be active and configured; at least 1 public media item still local (`migrate_left > 0` — the button is replaced by a green "All done" checkmark otherwise).
- **Steps:** 1) Click "Migrate all". 2) Observe progress bar filling and text updating ("{pct}% — {N} remaining"). 3) Wait for completion or a stall.
- **Expected:** Loop POSTs chunks recursively until the server reports `done: true`, then reloads the page after 800ms. Each chunk runs `CloudOps::migrate_one()` per row — the exact same code path as the CLI migrator.
- **UX expectation:** Button text changes to "Migrating…" and disables on click; progress wrapper (`.mvs-pro-storage-mgmt__progress`, initially `mvs-hidden`) becomes visible. On success: "All media migrated." then a full page reload. **Stall guard** (verified in `admin-storage-mgmt.js`): after each chunk, if `remaining >= prevRemaining` (no forward progress), the loop stops immediately and shows "Migration stopped on an error. See the last-run status above." — it does NOT retry or spin forever. Any XHR network error or a non-`success` JSON response also triggers the same failed-state text. Last-run status persists across page loads via `CloudOps::get_status()` and is rendered as "Last move: {N} file(s) processed." plus, if present, "Last error: {message}" in a `__warn` styled span — so a stalled run must visibly show the last error on next page load, never look like silent success.
- **Settings that change it:** none directly; `mvs_storage_driver` must not be `local`.
- **Edge cases:** Driver is `local` -> AJAX returns error "Active driver is local — nothing to migrate to." before any chunk runs. A persistent per-file upload error (e.g. bad credentials mid-run) must trigger the stall guard, not an infinite loop. 390px viewport — progress bar and button must not overflow. Concurrent admins clicking "Migrate all" in two tabs — WHERE clause is state-based (still-local rows), so it should self-heal rather than double-migrate, but verify no duplicate uploads if two chunks race on the same row.

#### MV-STO-007 — Free up server space (delete local copies, public-only)
- **Edition:** Pro
- **Who:** Admin only (`manage_options`).
- **Where:** Settings -> Storage tab, Storage Management panel, "Delete next {batch_size}" link (`.mvs-pro-storage-mgmt__cleanup-trigger`), `admin-post.php?action=mvs_pro_cloud_cleanup_batch` (nonce `_mvs_nonce`).
- **Setup:** Cloud driver active; public media already verified present on the cloud with a local file still on disk.
- **Steps:** 1) Click "Delete next {N}". 2) Confirm the destructive dialog. 3) Observe redirect + notice.
- **Expected:** Each candidate file is checked for local filesystem presence (`file_exists`) THEN routed through `CloudOps::cleanup_local_one()`, which itself re-verifies cloud presence before unlinking — confirmed in the memory brief and the code's batch loop (`query_public_cloud_candidates($batch*3, false)` over-fetches to account for skips). Non-public media is never included (`query_public_cloud_candidates` is scoped to public rows only) — the intro copy states this plainly: "Delete local copies of public media that are already in the cloud. Each file is checked in cloud before being removed from this server. Private media stays here."
- **UX expectation:** Confirm dialog uses the styled `window.mvsConfirm()` modal (tone: `destructive`) with the exact message "Delete the next batch of local copies? This cannot be undone." — clicking the link is `preventDefault()`'d and only navigates through after the async confirm resolves `true`; if `mvsConfirm` is not loaded, the click silently does nothing (fails closed — no native `confirm()` fallback, per the admin-ux-rulebook ban noted in the JS comments). On success, redirects back to `#storage` with a transient notice: "Cleanup batch finished. Deleted: {N} originals + {M} thumb variants. Skipped: {S}. Failed: {F}." (warning-styled if any failed). Persistent warn text on the panel itself: "This cannot be undone." is always visible, not just in the confirm dialog.
- **Settings that change it:** `mvs_storage_driver` must not be `local` (guarded server-side: "Active driver is local — cleaning local files would break the site." on `handle_cleanup()`).
- **Edge cases:** A file whose cloud-presence check fails (upload never finished) must be "skipped," not deleted or counted as failed-and-lost. Non-public/private media must NEVER appear in the candidate set regardless of batch size. 390px — confirm modal and action row must remain usable.

#### MV-STO-008 — Secret credential fields preserve value on blank resubmit
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> Storage tab, any of the 7 storage-related masked fields: `mvs_pro_bunny_api_key`, `mvs_pro_s3_access_key`, `mvs_pro_s3_secret_key`, `mvs_pro_r2_access_key`, `mvs_pro_r2_secret_key`, `mvs_pro_do_access_key`, `mvs_pro_do_secret_key`. (Two more non-storage credentials — `mvs_pro_google_vision_key`, `mvs_pro_anthropic_key` — and one connector credential, `mvs_pro_connector_flickr_app_secret`, share the identical mechanism but belong to AI/IMP areas respectively; `mvs_pro_aws_access_key`/`mvs_pro_aws_secret_key` are a SEPARATE pair for the Rekognition AI provider, not S3 storage — do not confuse the two "AWS" key pairs.)
- **Setup:** A secret already saved with a real value.
- **Steps:** 1) Open Settings -> Storage. 2) Without touching the secret field (it renders as `value=""` with a masked placeholder, never the real value), change any OTHER field on the same section (e.g. bucket name). 3) Click Save Changes.
- **Expected:** The `pre_update_option_{option}` filter registered in `ProSettings::__construct()` for every entry in `SECRET_OPTIONS` keeps the OLD value when the posted value is empty AND the old value is non-empty. The secret is NOT wiped.
- **UX expectation:** The field never prints the real secret into `value=` — masking and preserving are documented as "two halves of ONE contract" in the code (`ProSettings::SECRET_OPTIONS` docblock); a field rendered masked but missing from this list is the exact historic bug (Basecamp 10057408558, where AI provider keys were masked-but-unguarded and wiped on every Save). Verify via direct DB/option check (`wp option get mvs_pro_s3_secret_key`) that the value is unchanged after a blank-resubmit Save — the settings screen itself gives no explicit "secret preserved" confirmation message, so this must be verified out-of-band, not from a UI toast. A "Remove" control next to a saved secret (same pattern as Free's password fields) explicitly clears it via `SettingsHelper::secret_removal_requested()` — this is the ONLY way to actually blank a secret; a bare empty Save must never do it.
- **Settings that change it:** none — this is filter-chain behavior, not a toggle.
- **Edge cases:** Genuinely wanting to clear a key must use "Remove," not just blanking the field and saving (that path is guarded to no-op). Test on all 7 storage secrets, not just one, since each option has its own filter closure registered in a foreach loop.

#### MV-STO-009 — Private media never uploaded to cloud / never CDN-delivered
- **Edition:** Pro
- **Who:** Verify as any member who owns private media, and as Admin viewing storage stats.
- **Where:** Any private-privacy media upload while a cloud driver is active; Settings -> Storage Management panel "private media (kept here)" stat tile.
- **Setup:** Cloud driver configured and active; upload or have an existing private-privacy media item.
- **Steps:** 1) Upload media set to private privacy while S3/BunnyCDN/R2/DOSpaces is active. 2) Check Storage Management panel counts. 3) Attempt "Migrate all" and "Delete next N" with private media present.
- **Expected:** `StorageService::get_driver_for_privacy()` (Free) routes private media to the local driver regardless of the site's configured cloud driver — it is never uploaded to S3/BunnyCDN/R2/DOSpaces. It is proxied through the origin server, never delivered via a presigned/signed CDN URL (no SigV4 presign exists anywhere in Pro's storage code despite this being claimed in a stale docblock in Free's `Documents/StorageResolver.php` — flag this as a documented-vs-code discrepancy if encountered, not a bug to fix here).
- **UX expectation:** The "private media (kept here)" stat tile on the Storage Management panel must show a non-zero count when private media exists, and this count must NEVER decrease via "Migrate all" or "Delete next N" — both operations only ever touch `query_public_cloud_candidates()` rows. Private media is not merely "skipped with a message" in either UI flow; it is invisible to both candidate queries by design (no per-item "skipped: private" line appears in the batch summary counts — verify the migrate/cleanup summary text ("Migrated: X. Skipped: Y. Failed: Z.") never attributes a skip to privacy, since privacy-skipped rows are excluded before the query runs, not skipped during processing).
- **Settings that change it:** none — privacy-based routing is not configurable via a setting; it is hardcoded driver-selection logic.
- **Edge cases:** Making a previously-public (already-migrated-to-cloud) item private afterward — verify its already-uploaded cloud copy is not automatically deleted or re-downloaded (out of scope for the cloud-aware `/serve` work still pending per the code comments — confirm current behavior, don't assume a fix exists).

#### MV-STO-010 — Test Connection button per driver
- **Edition:** Pro
- **Who:** Admin only (`manage_mvs_settings`, AJAX nonce `mvs_pro_test_connection`).
- **Where:** Settings -> Storage tab, inline "Test Connection" control on each of the 4 driver sections. AJAX actions: `mvs_pro_test_s3`, `mvs_pro_test_bunny`, `mvs_pro_test_r2`, `mvs_pro_test_dospaces`.
- **Setup:** Credentials filled in (test can also be attempted with blanks to verify the guard).
- **Steps:** 1) Fill in driver fields (unsaved is fine). Confirmed: every driver's Test Connection reads credentials exclusively via `get_option()` (S3, Bunny, R2, and DigitalOcean all pull `$bucket`/`$key`/`$secret` straight from saved options, none from `$_POST`) — an unsaved field change is never reflected until Save is clicked first. 2) Click Test Connection.
- **Expected:** Each handler round-trips a small file (`mvs-connection-test-{random}.txt`, content "WPMediaVerse connection test"): upload then immediate delete. S3/BunnyCDN have bespoke per-field validation before attempting upload; R2/DOSpaces use the shared `round_trip()` helper with a generic failure message.
- **UX expectation:** Success strings are driver-specific and must be verified verbatim: S3 "Connected to S3 bucket "{bucket}" in {region}.", BunnyCDN "Connected to BunnyCDN zone "{zone}".", R2 "Connected to Cloudflare R2 bucket "{bucket}".", DOSpaces "Connected to DigitalOcean Space "{bucket}" in {region}.". Missing-credential guard message differs per driver too ("S3 credentials are not configured." / "BunnyCDN credentials are not configured." / "Cloudflare R2 credentials are not configured." / "DigitalOcean Spaces credentials are not configured.") and fires BEFORE any network call. Unauthorized user (capability check fails) gets a bare "Unauthorized." error — verify this is a clean AJAX error response, not a fatal.
- **Settings that change it:** none directly — reads whatever is currently saved for the relevant driver's options.
- **Edge cases:** Test button clicked on the section for a driver that is NOT the currently active `mvs_storage_driver` (e.g. testing BunnyCDN while S3 is active) — the test still runs against whichever driver section it belongs to, independent of the active driver. Constants defined in wp-config for keys must be honored by the test the same way they are by the driver's own constructor (verified in code for S3 and R2; confirm BunnyCDN/DOSpaces constant precedence is consistent).

#### MV-STO-011 — Storage Management panel on mobile (390px)
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> Storage tab at 390px viewport.
- **Setup:** Any driver active, with a mix of stats to render (in cloud / still local / private).
- **Steps:** 1) Open Settings -> Storage at 390px width. 2) Check overview table, stat tiles, action rows, progress bar.
- **Expected:** Per-service overview table (3 columns: service/files/size), 3-tile stat row, and 2 action rows (Migrate all / Delete next N) must not overflow or truncate.
- **UX expectation:** No horizontal scroll; buttons/links remain tap-reachable (verify against the 40px tap-target baseline in the ux-foundation skill); progress bar text wraps rather than clipping the "% — N remaining" string.
- **Settings that change it:** none — layout-only check.
- **Edge cases:** Long driver labels ("DigitalOcean Spaces", "Cloudflare R2") in the "Active" pill row at narrow width; long last-run error strings (e.g. BunnyCDN's verbose region-mismatch hint) wrapping inside `.mvs-pro-storage-mgmt__warn`.

---

### Area: WMK

#### MV-WMK-001 — Enable watermarking (images only, master toggle)
- **Edition:** Pro (drawing) requires Free (scope/config) + Pro both active — see MV-WMK-008 for Free-only.
- **Who:** Site owner (`manage_mvs_settings`, via the Storage settings tab).
- **Where:** wp-admin → WPMediaVerse → Settings → Storage tab, "Image Watermarking" section. Option `mvs_watermark_enabled` (bool, default false).
- **Setup:** Pro active.
- **Steps:** 1) Enable "Enable Watermark" with no other config changed (defaults: type=text, text=site name, position=bottom-right, opacity=40). 2) Upload a new image. 3) Upload a new video.
- **Expected:** Step 2: image is stamped in place with the site name in the bottom-right corner at 40% opacity before any derivative (WebP/AVIF/thumbnail) is generated, so every size inherits the mark. Step 3: video is completely unaffected — `Watermarker::stamp_file()` returns immediately (unhandled) for any mime not starting with `image/`.
- **UX expectation:** The field's own description states this explicitly: "Images only (video and audio are not watermarked). Applies to NEW uploads only, and permanently alters the stored image — existing media and later setting changes are not re-stamped, and there is no un-watermark." A QA pass that uploads a video expecting a watermark, or re-uploads settings expecting existing library images to retroactively gain a mark, is testing against behavior the settings page itself disclaims — not a bug.
- **Settings that change it:** `mvs_watermark_enabled` is the master switch; every other watermark field is hidden (`show_when: mvs_watermark_enabled`) until this is on.
- **Edge cases:** enabling watermark with `mvs_watermark_type=image` but no logo selected — image watermarking is silently skipped (see MV-WMK-003); enabling on a site where GD is unavailable (Imagick-only host) — the stamp always fails (see MV-WMK-009).

#### MV-WMK-002 — Text watermark with tokens
- **Edition:** Pro.
- **Who:** Site owner configures; every matched uploader (per scope) is affected.
- **Where:** Same Storage tab; fields `mvs_watermark_type=text`, `mvs_watermark_text`, `mvs_watermark_font_size`, `mvs_watermark_color`.
- **Setup:** Watermark enabled, type = Text.
- **Steps:** 1) Set text to `{site} — {username}` and upload as a member with a public `user_nicename` of `janedoe`. 2) Upload the same image as a different member. 3) Set an unusual font size (e.g. 80) and re-upload.
- **Expected:** Step 1: rendered text is `<Site Name> — @janedoe` — `{username}` resolves from `user_nicename` (never `user_login`, deliberately, since `user_login` can be a private credential and the stamped image may be public). Step 2: text updates per-uploader automatically (no re-save needed — tokens resolve at stamp time). Step 3: font scales proportionally to image width (calibrated for ~1000px-wide images), not a literal fixed pixel size.
- **UX expectation:** If no TrueType font is found on the server (checked via a `mvs_watermark_font_path` filter, then common system font paths, then a bundled Inter font as final fallback), text still renders — just via GD's tiny bitmap font, ignoring the configured size. This produces a watermark that looks "wrong" (much smaller than configured) without any visible warning to the site owner; a real, documented gap in feedback, not a total failure — the mark still exists, just not at the configured size.
- **Settings that change it:** `mvs_watermark_text` (default: site name), `mvs_watermark_font_size` (default 24), `mvs_watermark_color` (default `#ffffff`).
- **Edge cases:** `{username}` token with no `user_id` (e.g. a system/CLI-triggered stamp) — token left unresolved/literal since the replace is gated on `$user_id > 0`; 3-character hex color shorthand (`#fff`) — supported via `hex_to_rgb()` expansion.

#### MV-WMK-003 — Image (logo) watermark
- **Edition:** Pro.
- **Who:** Site owner configures.
- **Where:** Storage tab; `mvs_watermark_type=image`, `mvs_watermark_image_id`.
- **Setup:** Watermark enabled, type = "Image (logo)", a PNG with transparency selected from the Media Library.
- **Steps:** 1) Select a logo, upload an image. 2) Set type to "Image (logo)" but leave `mvs_watermark_image_id` at 0 (unset). 3) Set position to "Tiled".
- **Expected:** Step 1: logo is scaled to 20% of the base image's width (proportional height), alpha-blended at the configured opacity, placed per `mvs_watermark_position`. Step 2: "image watermarking is skipped" per the field's own description — the upload is stored completely un-watermarked, with no error surfaced. Step 3: logo repeats across the entire canvas on a grid, ~40px gaps.
- **UX expectation:** Step 2 is the highest-risk edge case in the whole feature: an owner who thinks they've "enabled watermarking" but forgot to pick a logo image gets ordinary, unmarked uploads with zero indication anything is wrong — no admin notice fires for this specific combination (contrast with MV-WMK-006's role-selection notice, which DOES warn). Flag this explicitly in any QA pass.
- **Settings that change it:** `mvs_watermark_image_id` (media attachment id, required for this mode), `mvs_watermark_position`, `mvs_watermark_opacity`.
- **Edge cases:** a corrupt/unreadable logo file (fails to load via GD, same silent-skip path); a logo in an unsupported format (only PNG/JPEG/GIF/WebP are loaded via `load_gd_image()` — anything else returns null and skips).

#### MV-WMK-004 — Logo + text ("both") mode
- **Edition:** Pro.
- **Who:** Site owner.
- **Where:** Storage tab; `mvs_watermark_type=both`.
- **Setup:** Watermark enabled, type = "Logo + text", both a logo AND text configured.
- **Steps:** 1) Set position to bottom-right, upload an image with both layers valid. 2) Remove the logo (id=0) but keep text, with type still "both".
- **Expected:** Step 1: logo draws at bottom-right (the configured position); text draws at the opposite corner (top-left) by default so the two layers never overlap. Step 2: logo layer is skipped (no image), text layer still draws successfully — either layer succeeding counts as a stamp.
- **UX expectation:** For `center` or `tile` logo positions, text has no natural "opposite corner," so it falls back to bottom-left as a neutral spot — verify this visually doesn't collide with a tiled logo pattern in practice.
- **Settings that change it:** `mvs_watermark_type=both`, plus two dev-only filters `mvs_watermark_text_position` / `mvs_watermark_text_opacity` (no UI field).
- **Edge cases:** both logo and text failing to draw — `$drawn` stays false, save is skipped entirely, original stored un-watermarked with no error surfaced (same silent-skip pattern as MV-WMK-003).

#### MV-WMK-005 — Position and opacity settings
- **Edition:** Pro.
- **Who:** Site owner.
- **Where:** Storage tab; `mvs_watermark_position` (center/bottom-right/bottom-left/top-right/top-left/tile), `mvs_watermark_opacity` (0-100).
- **Setup:** Watermark enabled.
- **Steps:** 1) Set opacity to 0. 2) Set opacity to 100. 3) Try each of the 6 position choices on a sample image, including "Tiled."
- **Expected:** Step 1: mark is drawn but fully transparent (invisible) — still counts as "drawn." Step 2: fully opaque, `imagecopy()` path used instead of `imagecopymerge()`. Step 3: all 6 positions place correctly per the documented margin logic (20px margin for logos, proportionally-scaled margin for text); "Tiled" repeats the mark across the full canvas for both logo and text.
- **UX expectation:** A 0% opacity watermark is functionally "enabled but invisible" — a site owner who accidentally drags the opacity slider to 0 gets no visible mark and no warning. Worth calling out in QA as a UX gap, not a functional bug.
- **Settings that change it:** `mvs_watermark_position`, `mvs_watermark_opacity` ("0 = transparent, 100 = fully opaque").
- **Edge cases:** opacity value outside 0-100 sent via a filter/direct DB edit — `apply_image_watermark()` clamps via `max(0, min(100, $opacity))`, but `apply_text_watermark()`'s alpha calc does NOT clamp at all before calling `imagecolorallocatealpha()`. Confirmed: this is unreachable through the admin UI in normal use (the Opacity field renders as a browser `<input type="number" min="0" max="100">`, matching the 0-100 range GD expects), so an out-of-range value only reaches this code via the `mvs_watermark_text_opacity` developer filter or a direct DB/option edit. GD's alpha parameter is only defined for 0-127; an out-of-range computed alpha is not validated by this code and can render with unpredictable transparency rather than a clean clamp to fully opaque/transparent — low-severity since it needs a filter or a DB edit to trigger, but worth mirroring `apply_image_watermark()`'s existing clamp for consistency.

#### MV-WMK-006 — Per-role scope ("Apply to")
- **Edition:** Pro (drawing) + Free (`WatermarkService::applies_to_user()`, the actual scope decision).
- **Who:** Site owner configures; scoped roles are the ones affected.
- **Where:** Storage tab; `mvs_watermark_apply` (all/roles, default "all"), `mvs_watermark_roles` (array of role slugs, default empty).
- **Setup:** Watermark enabled.
- **Steps:** 1) Leave "Apply to" at "All uploads" (default) and upload as any role. 2) Switch to "Uploads from selected roles," select only `contributor`, save. Upload as a contributor, then as a subscriber. 3) Switch to "roles" but select NO roles, save.
- **Expected:** Step 1: every upload watermarked regardless of role. Step 2: contributor's upload is watermarked; subscriber's is not. Step 3: nothing is watermarked at all for any role — explicitly a valid, save-able state, with a dedicated admin notice: "Apply to is set to 'Uploads from selected roles' but no role is selected, so nothing is being watermarked."
- **UX expectation:** Step 3's notice is the one case in this whole feature where the code DOES proactively warn the owner about a likely-unintended silent no-op — confirm this notice actually renders on the Storage tab when this combination is saved. The scope check happens once per upload at ingest time based on the uploader's CURRENT roles — a role change after upload never retroactively re-stamps or un-stamps anything already stored.
- **Settings that change it:** `mvs_watermark_apply`, `mvs_watermark_roles`.
- **Edge cases:** a user with multiple roles where only one is selected (matches via `array_intersect`, any matching role is enough); a role selected that no longer exists (harmless, never matches); Multisite/role-per-site nuance: confirmed not an issue — `get_userdata()` returns a `WP_User` whose `roles` property reflects the uploader's role on the CURRENT site, which is exactly what a per-site `mvs_watermark_roles` option needs; no cross-site role bleed.

#### MV-WMK-007 — Baked into stored original + every derivative; no serve-time or re-stamp
- **Edition:** Pro.
- **Who:** Every viewer of the image, regardless of role.
- **Where:** The stored original file and every derivative (WebP, AVIF, thumbnails).
- **Setup:** Watermark enabled and configured.
- **Steps:** 1) Upload a matching image, inspect the original file AND every generated size/format. 2) Disable watermarking, view a PREVIOUSLY uploaded (already-watermarked) image again. 3) Change the text/position/opacity setting, view that same previously-uploaded image.
- **Expected:** Step 1: mark is present in the ORIGINAL bytes on disk (stamped before optimization/derivative generation), so every derivative inherits it from the same source pixels. Steps 2 & 3: the previously-stored image is completely unaffected by later setting changes — same watermark, forever. "There is no per-media or serve-time watermarking" is stated directly in `WatermarkService`'s class docblock.
- **UX expectation:** An owner who disables watermarking expecting existing marked images to lose their mark, or who changes text/position expecting old images to update, will see NO change — by design. There is also, by design, no "remove watermark" feature of any kind.
- **Settings that change it:** none retroactively — every watermark setting only affects uploads FROM THAT POINT FORWARD.
- **Edge cases:** re-uploading the exact same file as a "new" upload — treated as fresh, stamped again under current settings; a file replace (MV-WMK-009) is a distinct ingest path from a fresh upload.

#### MV-WMK-008 — Free-only (no Pro) gets no watermark at all, even if configured
- **Edition:** Free.
- **Who:** Any site owner running Free without Pro.
- **Where:** Same Storage tab settings exist in Free's UI, but the actual stamping filter has no listener.
- **Setup:** Free active, Pro NOT active/installed. Configure and enable watermarking fully.
- **Steps:** 1) With Pro deactivated, enable watermarking and configure it completely. 2) Upload a matching image.
- **Expected:** The image uploads completely un-watermarked — `WatermarkService::stamp_new_upload()` calls the `mvs_watermark_stamp_file` filter and, with no Pro `Watermarker::stamp_file` listener registered, the filter returns its default `false` unchanged. Since `has_filter()` is also false here, no error is logged either — this is the one case where failure is silent by design.
- **UX expectation:** The Free settings screen shows a fully "enabled and configured" watermark UI with no indication anywhere that it does nothing without Pro. Check the Free settings screen copy and any pricing/feature-comparison page for language that could mislead a Free-only buyer into thinking watermarking works without Pro.
- **Settings that change it:** installing/activating Pro is the only thing that changes this outcome.
- **Edge cases:** Pro installed but deactivated mid-request (same as fully absent); Pro active but GD unavailable — that case DOES log an error (MV-WMK-009), unlike the no-Pro case.

#### MV-WMK-009 — Watermark failure with Pro active (GD unavailable) and replace-upload re-stamping
- **Edition:** Pro.
- **Who:** Site owner (sees the failure in logs); uploader (gets an unmarked file with no error shown to them).
- **Where:** Free's Logs screen (`Admin\LogViewerPage`); the replace-file REST path (`MediaController::replace_item`).
- **Setup:** Pro active, watermarking enabled/configured, but the server has no GD image library (Imagick-only host).
- **Steps:** 1) Upload a matching image on a GD-less host. 2) Replace an existing media item's file (not a fresh upload) with a new image, on a normal GD-capable host, with watermarking enabled.
- **Expected:** Step 1: `Watermarker::stamp_file()` fails to get a GD resource, draws nothing, filter returns false; but `has_filter()` is true so `WatermarkService` logs an ERROR: "Watermark stamp failed; the un-watermarked original was stored. Check that the GD image library is available on this server." — visible to the site owner on the Logs screen, invisible to the uploading member. Step 2: the replace path also calls the watermark stamp before storing, so a replaced file is freshly stamped under CURRENT settings — unlike an existing untouched file (MV-WMK-007), a replace is treated as new bytes entering the system.
- **UX expectation:** Step 1 is a paid "protect my media" feature silently failing for every upload on an affected host, with the only trace being a wp-admin Logs entry the owner has to go looking for. Genuine support-risk edge case for hosts that might lack GD.
- **Settings that change it:** none — GD availability is a hosting/environment fact, not a plugin setting.
- **Edge cases:** the sideload/CLI-import ingest paths deliberately do NOT call the watermark stamp at all — by design, since they're re-ingesting bytes already in the library, not new member uploads.

### Area: AI

#### MV-AI-001 — Select active provider and configure Google Vision
- **Edition:** Pro (Vision/Rekognition/Anthropic providers) + Free (`mvs_ai_provider` selector, `AIService` orchestration).
- **Who:** Site owner (`manage_mvs_settings`) configures; effect applies to every member's uploads once auto-toggles are on.
- **Where:** wp-admin → WPMediaVerse → Settings → AI tab. Provider selector `mvs_ai_provider` (openai/google_vision/rekognition/anthropic, default `openai`); Google section fields `mvs_pro_google_vision_key` (only shown when `mvs_ai_provider=google_vision`).
- **Setup:** A Google Cloud project with the Cloud Vision API enabled and billing on, an API key from APIs & Services → Credentials.
- **Steps:** 1) Set `mvs_ai_provider` to "Google Vision" without a key. 2) Add a valid key, save. 3) Upload an image with `mvs_ai_auto_analyze` and `mvs_ai_auto_describe`/`mvs_ai_auto_tag` on.
- **Expected:** Step 1: with an empty key the provider is never even registered by `register_ai_providers()`, so no active provider exists at all; any AI call returns `mvs_no_ai_provider` "No AI provider is configured." Step 3: description written to `ai_description` meta (top 5 labels joined), tags written to `ai_tags` (up to 15 labels, lowercased).
- **UX expectation:** There is no explicit "provider not configured" banner surfaced anywhere on the settings page itself when a provider is selected but its key is blank — the failure only shows up later as a per-media `ai_status: failed` with no rendered reason on the member-facing side. Confirmed: the admin Media list's status badge only ever shows the generic label "Failed" (a plain status-to-label switch with no reason text for any failure cause) — an owner cannot tell "no key configured" apart from any other failure from that badge alone.
- **Settings that change it:** `mvs_ai_provider` (which provider is "active" — see MV-AI-009), `mvs_pro_google_vision_key`.
- **Edge cases:** legacy stored value `google` (pre-rename) is normalized to `google_vision` on read, so an old saved choice keeps working after the provider id was renamed.

#### MV-AI-002 — Google Vision moderation and tagging behavior
- **Edition:** Pro.
- **Who:** Any member whose upload gets auto-analyzed/moderated; the owner configures.
- **Where:** Background AI pipeline (`mvs_ai_process_media` Action Scheduler job, queued from `mvs_media_uploaded` via `maybe_queue_ai()`).
- **Setup:** Google Vision active and keyed; `mvs_ai_auto_moderate` on.
- **Steps:** 1) Upload a clean image with moderation on. 2) Upload an image that would trip SAFE_SEARCH_DETECTION on adult/violence/racy/medical/spoof at LIKELY or VERY_LIKELY confidence.
- **Expected:** Step 1: `moderate_content()` returns `{safe:true, flags:[], confidence:1.0}` (or 0.0 if the API call itself failed) — stored to `ai_moderation` meta. Step 2: flagged categories collected into `flags` (only from the 5-category safe-search set), `safe:false`, `confidence:0.9`. Flags feed into Free's category-alias matching (`AIService::match_moderation_category()`) so provider-specific vocabulary ("adult", "racy") maps to the site's own enabled categories ("nudity", etc.).
- **UX expectation:** Vision's raw output (adult/violence/racy/medical/spoof) is a DIFFERENT vocabulary than the site's canonical categories (nudity/violence/hate/self-harm/drugs/spam); the alias-matching bridges them. A QA pass comparing Vision's raw flags directly against the enabled-category checkboxes will see a mismatch that is NOT a bug.
- **Settings that change it:** the Free-side moderation category checkboxes + custom terms don't change what Vision itself checks (fixed 5 safe-search categories, unlike Anthropic) — a real functional difference between providers worth calling out.
- **Edge cases:** Vision API returning zero labels for a near-blank/solid-color image (no error, just empty results).

#### MV-AI-003 — Configure and use AWS Rekognition
- **Edition:** Pro.
- **Who:** Site owner configures; effect applies per auto-toggle.
- **Where:** AI tab, section shown when `mvs_ai_provider=rekognition`: `mvs_pro_aws_access_key`, `mvs_pro_aws_secret_key`, `mvs_pro_aws_region` (select, default `us-east-1`).
- **Setup:** An IAM user with programmatic access and the `AmazonRekognitionReadOnlyAccess` policy attached.
- **Steps:** 1) Set only the access key, leave secret blank, save, trigger an AI call. 2) Set both keys correctly, upload an image.
- **Expected:** Step 1: `register_ai_providers()` only registers Rekognition when BOTH access key and secret are present — with the secret blank, Rekognition is never registered, so every AI call gets `mvs_no_ai_provider`. Step 2: `DetectLabels` (min confidence 70 for description, 60 for the wider 15-tag set) and `DetectModerationLabels` (min confidence 70) both call AWS with a hand-rolled SigV4 signature (no AWS SDK dependency).
- **UX expectation:** No rendered feedback distinguishes "key rejected by AWS" from "key simply blank"; both end in the same generic downstream failure state. Rekognition downloads the full image bytes via `wp_remote_get()` (base64-encodes for the API) rather than passing a URL — a slow/unreachable image URL manifests as a moderation/tagging failure with no AWS-specific error beyond whatever HTTP error `wp_remote_get` produced.
- **Settings that change it:** `mvs_pro_aws_access_key`, `mvs_pro_aws_secret_key`, `mvs_pro_aws_region`.
- **Edge cases:** a region mismatch surfaces as a generic HTTP-code circuit-breaker failure (MV-AI-007), not a specific "wrong region" message.

#### MV-AI-004 — Configure and use Anthropic (Claude)
- **Edition:** Pro.
- **Who:** Site owner configures.
- **Where:** AI tab, section shown when `mvs_ai_provider=anthropic`: `mvs_pro_anthropic_key`. No model dropdown since 2.6.0 — `mvs_pro_anthropic_model` is a hidden option pinned to `claude-haiku-4-5-20251001` via a sanitize whitelist (old choices from before the dropdown was removed still round-trip correctly).
- **Setup:** An Anthropic account with billing/credits, a key from console.anthropic.com/settings/keys.
- **Steps:** 1) Configure the key, upload an image with auto-describe/tag/moderate all on. 2) Upload a private/gated image (not publicly reachable by URL).
- **Expected:** Step 1: `analyze_image()` sends a single vision message asking for alt-text-style description (confidence fixed at 0.85, not model-reported); `generate_tags()` asks for a comma-separated 5-10 tag list; `moderate_content()` narrates the site's ACTUAL enabled categories + custom terms directly into the prompt and parses a JSON `{safe, flags, confidence}` reply, tolerating prose-wrapped or code-fenced JSON. Step 2: unlike Vision/Rekognition which need the URL independently fetchable BY the remote API, Anthropic's provider fetches the image bytes SERVER-SIDE first and sends base64 — works for local/private/gated media, though in practice all three go through Free's signed URL anyway.
- **UX expectation:** Anthropic is the only provider where the MODERATION categories checked are the SITE'S OWN configured categories, not a fixed vendor list — enable only "violence" and "hate" in Free's moderation settings, confirm Anthropic's prompt (and flagging) narrows to just those two, while Vision/Rekognition keep checking their own fixed vendor categories regardless.
- **Settings that change it:** `mvs_pro_anthropic_key`; Free's `mvs_ai_moderation_categories` / `mvs_ai_moderation_custom_terms` (these DO change Anthropic's behavior, unlike for Vision/Rekognition).
- **Edge cases:** image over ~3.5MB raw bytes (base64 inflation would exceed Anthropic's ~5MB budget) — every call on that image fails cleanly (null response) rather than erroring loudly; a self-signed-cert local dev site — confirmed `sslverify: false` is hardcoded unconditionally for this fetch (no environment check anywhere). This only affects the plugin's own self-fetch of a media file's `/serve` URL on the current site (the docblock: "works for local/private media... Anthropic could not reach [it] by URL"), never a third-party URL, so it's the same low-risk same-site pattern WordPress core itself uses for loopback requests — not a defect, just ungated where a `wp_get_environment_type()` check would have been tidier.

#### MV-AI-005 — Auto-analyze / auto-describe / auto-tag / auto-apply-tags toggles
- **Edition:** Free (toggles + orchestration) + Pro (whichever provider is active).
- **Who:** Site owner configures; every member's upload is affected once on.
- **Where:** AI tab: `mvs_ai_auto_analyze` (master, default off), `mvs_ai_auto_describe` (default ON, shown only when master is on), `mvs_ai_auto_tag` (default ON, shown only when master is on), `mvs_ai_auto_apply_tags` (default off, shown only when auto_tag is on).
- **Setup:** A working active provider.
- **Steps:** 1) Turn on ONLY `mvs_ai_auto_analyze` (leave describe/tag at their defaults, already on) and upload an image. 2) Turn off `mvs_ai_auto_describe` but leave `mvs_ai_auto_tag` on. 3) Turn on `mvs_ai_auto_apply_tags`.
- **Expected:** Step 1: both description AND tags generate (both default true once master is on). Step 2: only tags generate, no description. Step 3: generated tags are ALSO written into the real `mvs_tag` taxonomy via `wp_set_object_terms()` — visible on the frontend anywhere tags render, not just in admin meta.
- **UX expectation:** The field description for auto_describe/auto_tag calls them opt-out sub-toggles of the master switch (a documented past bug had an owner enabling only moderation getting 3 provider calls instead of 1) — confirm turning off describe/tag while master analyze is on actually suppresses those specific calls now.
- **Settings that change it:** all four listed above; `mvs_ai_auto_moderate` is a separate toggle on the Moderation tab (moved there in 2.6.0), not gated by `mvs_ai_auto_analyze` at all.
- **Edge cases:** `mvs_ai_auto_apply_tags` on with a provider returning zero tags — no taxonomy write happens, existing tags left untouched.

#### MV-AI-006 — Moderation categories and custom terms
- **Edition:** Free (categories UI + alias matching) + Pro (provider behavior per MV-AI-002/004).
- **Who:** Site owner.
- **Where:** Moderation tab; `mvs_ai_moderation_categories` (checkboxes: nudity/violence/hate/self-harm/drugs/spam, all on by default), `mvs_ai_moderation_custom_terms` (free-text, comma-separated).
- **Setup:** Auto-moderate on, any provider configured.
- **Steps:** 1) Uncheck all categories, save. 2) Add a custom term like "graffiti". 3) Check the provider-flag-to-category alias table (e.g. Rekognition returning "Explicit Nudity").
- **Expected:** Step 1: an empty rule falls back to ALL categories rather than "moderate nothing," specifically to prevent an accidental blank save from silently disabling all flagging. Step 2: the custom term is appended to the narrated term list used by Anthropic's prompt (and Free's own OpenAI provider) — Vision/Rekognition, using fixed vendor category sets, never see or act on custom terms at all. Step 3: "Explicit Nudity" substring-matches the `nudity` category's alias list case-insensitively.
- **UX expectation:** Step 1's fallback-to-all is a safety behavior that could look like a bug to someone deliberately trying to disable moderation by unchecking everything — that's intended (an empty rule reads as "not configured," not "configured to moderate nothing").
- **Settings that change it:** `mvs_ai_moderation_categories`, `mvs_ai_moderation_custom_terms` — meaningfully affect Anthropic and OpenAI; do NOT affect Vision/Rekognition's own checks, only how their fixed-vocabulary flags get bucketed downstream.
- **Edge cases:** a custom term containing a comma inside it breaks the comma-split parser (no escaping mechanism).

#### MV-AI-007 — Circuit breaker: trip after repeated failures, cooldown, auto-recovery
- **Edition:** Pro (Google Vision and Rekognition only — Anthropic does NOT use this trait).
- **Who:** System-level; affects every subsequent AI call for that provider site-wide during the open window.
- **Where:** Transient-based, keyed per provider id (`mvs_circuit_fails_google_vision`, `mvs_circuit_open_rekognition`, etc.).
- **Setup:** A provider configured with a key that will fail (revoked key, wrong region, network block) so calls fail consistently.
- **Steps:** 1) Trigger 5 consecutive failed calls to Google Vision or Rekognition. 2) Attempt a 6th call immediately after. 3) Wait for the 1-hour cooldown to fully elapse, then attempt a call — including with a NOW-VALID key.
- **Expected:** Step 1: on the 5th consecutive failure, the circuit "opens" — a transient is set for 3600 seconds, an error_log line is written, and the failure counter is cleared. Step 2: `call_api()` short-circuits immediately and returns null WITHOUT making any HTTP request — the provider is completely paused. Step 3: once the transient naturally expires (hard timed reopen, no half-open logic), the very next call goes through normally; any single success anywhere resets the failure counter.
- **UX expectation:** None of this is visible anywhere in wp-admin — no "provider paused" indicator, nothing; the only trace is a PHP error log line. A member/owner cannot distinguish "circuit open" from "one-off failure" without reading the server error log — a genuine observability gap.
- **Settings that change it:** none — `circuit_failure_threshold` (5) and `circuit_cooldown` (3600s) are hardcoded, no admin field.
- **Edge cases:** 4 failures followed by 1 success (counter resets, circuit never opens — only CONSECUTIVE failures count); Anthropic experiencing the same outage has NO circuit breaker at all — it retries every call with no backoff. Confirmed real: `is_circuit_open()` exists only in the Google Vision and Rekognition provider classes; Anthropic's provider class has zero circuit-breaker code and, unlike every other deliberate cross-provider asymmetry in this codebase, carries no comment explaining why — this reads as an oversight (Anthropic support added later, the pattern not carried over) rather than a decision.

#### MV-AI-008 — Missing/invalid credentials per provider
- **Edition:** Pro.
- **Who:** Site owner (misconfiguration); every member is affected by the resulting failures.
- **Where:** AI tab per-provider key fields; effect surfaces in `ai_status` meta / any AI REST/CLI surface that reads it.
- **Setup:** For each provider, either leave the key(s) blank or set an invalid value.
- **Steps:** 1) Blank Google key, selected as active, trigger a call. 2) Blank AWS secret only (access key present), selected as active, trigger a call. 3) Blank Anthropic key, selected as active, trigger a call. 4) Valid-format but revoked/wrong key for each, trigger a call.
- **Expected:** Steps 1-3: the provider is never registered at all — every AI action returns `mvs_no_ai_provider` "No AI provider is configured." Step 4: the provider IS registered and `is_available()` returns true (key non-empty), so calls proceed and fail at the HTTP layer instead — for Vision/Rekognition this feeds the circuit breaker (MV-AI-007); for Anthropic it just fails that one call with no backoff.
- **UX expectation:** "Blank key" and "wrong key" are functionally distinguishable in code but NOT distinguishable to the site owner anywhere in the UI. Confirmed: there is genuinely no "Test Connection" button for any of the three AI providers — the only such button in the entire admin is the storage drivers' one; the AI provider key fields render as plain secret inputs with nothing attached to test them.
- **Settings that change it:** the per-provider key fields themselves.
- **Edge cases:** switching `mvs_ai_provider` to a provider whose key was previously removed — same `mvs_no_ai_provider` outcome, no specific "you selected X but never configured it" messaging.

#### MV-AI-009 — Only one provider is ever active despite multiple being configured
- **Edition:** Pro + Free.
- **Who:** Site owner.
- **Where:** AI tab `mvs_ai_provider` selector — `register_ai_providers()` registers ALL providers with a present key, but `AIService::get_active_provider()` only ever RUNS the one matching the `mvs_ai_provider` option.
- **Setup:** Configure valid keys for Google Vision, Rekognition, AND Anthropic simultaneously, with `mvs_ai_provider` set to `anthropic`.
- **Steps:** 1) With all three keyed and Anthropic selected, upload an image. 2) Switch `mvs_ai_provider` to `google_vision` without touching any keys, upload again. 3) Set `mvs_ai_provider` to `rekognition`, then blank the Rekognition keys (leaving Vision/Anthropic keyed), upload again.
- **Expected:** Step 1: only Anthropic's API is called; Vision and Rekognition sit registered-but-idle. Step 2: calls now go to Vision instead — proving the switch is a pure runtime selector, not a re-save-triggered reconfiguration. Step 3: with the selected provider now unavailable and `mvs_ai_provider_fallback` at its 2.6.0 default of `false`, calls return `mvs_no_ai_provider` — no silent fallback to Vision or Anthropic even though both are available.
- **UX expectation:** This "no silent fallback" default is deliberate in 2.6.0 to stop an owner who picked Claude or Google from being surprised by an OpenAI bill instead — QA should verify there's no misleading copy suggesting "configure multiple providers for redundancy," since the default behavior is the opposite of redundancy.
- **Settings that change it:** `mvs_ai_provider`; the `mvs_ai_provider_fallback` filter (dev-only, no UI field, default false since 2.6.0).
- **Edge cases:** `mvs_ai_provider` set to a value matching NO registered provider id at all (stale/removed provider) — same as no provider configured.

#### MV-AI-010 — Monthly AI budget cap
- **Edition:** Free (enforcement) applies to all Pro-supplied providers equally.
- **Who:** Site owner sets; affects all members' uploads once the cap is hit.
- **Where:** AI tab; `mvs_ai_monthly_budget` (default $10/month, `0` = uncapped).
- **Setup:** Any active provider; set the budget very low (or accumulate enough tracked cost) to force the cap.
- **Steps:** 1) With budget at default $10 and no usage yet, trigger an AI call. 2) Accumulate enough tracked cost to exceed the cap within the current month. 3) Trigger a call after the cap is hit. 4) Wait for (or simulate) the calendar month rolling over.
- **Expected:** Step 1: proceeds normally. Step 3: every AI call returns `mvs_ai_budget_exceeded` "Monthly AI budget has been exceeded." BEFORE any provider HTTP call is made. Step 4: usage counters are stored per-month, so a new month starts the counter fresh automatically.
- **UX expectation:** Cost tracking is an ESTIMATE per call (a flat, code-defaulted per-call cost, filterable, not the actual provider-billed amount) — explicitly NOT real billing data.
- **Settings that change it:** `mvs_ai_monthly_budget` (0 disables the cap entirely).
- **Edge cases:** budget set to a negative number — treated as uncapped, same as 0 (code checks `<= 0`); the auto-triggered pipeline hits the SAME budget check as manual calls, so exceeding budget silently pauses auto-analyze/auto-moderate for the rest of the month with no owner notification beyond whatever surfaces in `ai_status`.

#### MV-AI-011 — Manual AI re-run and reject in the admin Media list
- **Edition:** Free (admin surface) + Pro (whichever provider actually runs).
- **Who:** Confirmed exact capability gate: `manage_options` OR `moderate_mvs_media` (checked inline in `handle_bulk_actions()` for both the `ai_reject` and `ai_rerun` cases).
- **Where:** wp-admin Media list bulk actions: "AI re-run", "AI reject".
- **Setup:** A media item with an existing AI result (description/tags/status).
- **Steps:** 1) Select a media item, run "AI re-run." 2) Select a media item with an AI description/tags, run "AI reject."
- **Expected:** Step 1: `ai_status` immediately flips to `processing`, then re-queued via Action Scheduler — or, if AS is unavailable, runs SYNCHRONOUSLY (the request blocks until done). Notice reads either "AI re-run queued for media #{id}. Reload this page in a moment to see the new result." (async) or "AI re-run completed for media #{id}." (sync). Step 2: `ai_description` cleared, `ai_tags` cleared, `ai_status` set to `rejected`, `ai_reviewed` timestamped — "AI result rejected for media #{id}. The AI description and tags were cleared."
- **UX expectation:** The two possible "re-run" messages directly reflect whether Action Scheduler is present — worth explicitly testing on a host without AS active, since the synchronous fallback blocks the request (a visible delay).
- **Settings that change it:** none directly — a manual override action, not gated by auto-toggles.
- **Edge cases:** "AI reject" on a media item with NO existing AI result (still clears already-empty fields, no error — idempotent); nonce/capability check happens before either handler runs — confirmed: `manage_options` OR `moderate_mvs_media`, same gate as both actions (see the "Who" field above).

#### MV-AI-012 — Auto-moderation flagging feeds the moderation queue
- **Edition:** Free (queue) + Pro (provider result).
- **Who:** Members whose uploads get auto-moderated; moderators/owner review the resulting queue.
- **Where:** wp-admin Moderation Queue; triggered by `mvs_ai_auto_moderate` during the same pipeline as describe/tag.
- **Setup:** Auto-moderate on, any provider active, moderation categories configured.
- **Steps:** 1) Upload an image that a provider flags as unsafe. 2) Check the Moderation Queue admin page.
- **Expected:** `moderate()`'s result (`{safe:false, flags:[...], confidence:...}`) is stored to `ai_moderation` meta, then passed through the `mvs_ai_moderation_result` filter (pre-decision), matched against the admin's enabled moderation categories, and — if flagged — written directly to `moderation_status = 'flagged'` on the media row (this DB write, not a hook, is what makes it show up in the queue) plus a `mvs_media_flagged` action fired for any listener. The flagged item becomes visible in the Moderation Queue for a human reviewer.
- **UX expectation:** Whatever badge/count/urgency indicator the Moderation Queue shows for AI-flagged vs member-reported items is a Free-side rendering concern — flag this boundary explicitly for whoever owns the Free moderation UI to verify.
- **Settings that change it:** `mvs_ai_auto_moderate`, the moderation category checkboxes.
- **Edge cases:** a provider returning `safe:false` with an EMPTY `flags` array. Confirmed: the queue's Flags column has an explicit empty-case branch that renders a plain "—" (em dash) instead of any pill — no crash, no missing row, just a blank-looking reason column that a moderator would need to open the item to investigate further.

### Area: IMP

#### MV-IMP-001 — WP-CLI import from rtMedia
- **Edition:** Pro
- **Who:** Server/SSH access only (WP-CLI), effectively admin-equivalent.
- **Where:** `wp mvs import-rtmedia [--batch-size=<n>] [--dry-run] [--skip-albums] [--offset=<n>]`.
- **Setup:** rtMedia plugin's DB tables present with data (no explicit "must be active" check found in the importer beyond querying its tables directly).
- **Steps:** 1) `wp mvs import-rtmedia --dry-run` first to preview counts. 2) `wp mvs import-rtmedia` for the real run. 3) Re-run to confirm idempotency.
- **Expected:** Batched fetch/import loop from `AbstractBatchImporter::run_source()`; each imported row is deduped against `mvs_media_meta` using the `rtmedia_id` meta key before import — a second full run reports everything as "Skipped (already imported)," not re-imported or duplicated.
- **UX expectation:** WP-CLI progress bar per source (label + tick per row). Final line: "Imported {N} media item(s). Skipped {N} (already imported). Errors: {N}." with `--dry-run` swapping "Imported" for "Would import" and performing zero writes (verify no album, no media row, no meta key is created in dry-run). Per-row failures print a `WP_CLI::warning()` line: "Failed to import {label}: {error message}" and continue the batch rather than aborting.
- **Settings that change it:** none (CLI-only; no admin toggle gates the importer itself, only the Free "mvs_connectors_enabled"-style toggles are unrelated to CLI importers).
- **Edge cases:** `--offset` resuming mid-batch after an interrupted run; `--skip-albums` must still import the media but never call `find_or_create_album`/`add_to_album`; a private/friends-only rtMedia album's privacy must carry over correctly through `RtMedia\AlbumPrivacy::for_row()` (this is the exact bug class the code comments call out as historically diverging between the CLI importer and the admin migration card — the CLI copy was correct, the admin copy leaked private albums as public before the 2.4.0 fix consolidating both onto `AlbumMarkerLookupTrait::create_rtmedia_album()`).

#### MV-IMP-002 — WP-CLI import from MediaPress
- **Edition:** Pro
- **Who:** Server/SSH access.
- **Where:** `wp mvs import-mediapress [--batch-size=<n>] [--dry-run] [--skip-albums] [--offset=<n>]`.
- **Setup:** MediaPress plugin data present (galleries as `mpp-gallery` posts, media rows in its own tables).
- **Steps:** same pattern as rtMedia (dry-run, run, re-run).
- **Expected:** Dedup meta key `mpp_id`; galleries map 1:1 to MVS albums via `mpp_gallery_id` marker, title/author pulled from the gallery post (`create_mediapress_album()`).
- **UX expectation:** Identical CLI progress/summary contract to rtMedia (shared base class — same exact final-line format).
- **Settings that change it:** none.
- **Edge cases:** A MediaPress gallery post that has been trashed/deleted after media was linked to it — verify the fallback (empty title -> "Imported Album", fallback author) does not error.

#### MV-IMP-003 — WP-CLI import from BuddyBoss Platform
- **Edition:** Pro
- **Who:** Server/SSH access.
- **Where:** `wp mvs import-buddyboss [--batch-size=<n>] [--dry-run] [--skip-albums] [--offset=<n>] [--source=<media|document|video|all>]` (default `all`).
- **Setup:** BuddyBoss Platform's `bp_media`, `bp_document`, and/or `bp_video` tables present (importer handles both the direct file_url/mime_type schema AND the attachment_id-based schema across BuddyBoss versions).
- **Steps:** 1) `wp mvs import-buddyboss --source=media --dry-run` to test one table in isolation. 2) Run without `--source` (or `--source=all`) for the full three-table pass.
- **Expected:** Runs `run_source()` once PER source table (media, document, video) within a single CLI invocation — counters (`imported`/`skipped`/`errors`) are NOT reset between tables (only reset once by `parse_flags()` at invocation start), so the final summary is a combined total across all three tables when `--source=all`. Dedup meta key is `bb_media_id` for media, and confirmed the same formula generates the other two: `'bb_' . $table_key . '_id'` gives `bb_document_id` for documents and `bb_video_id` for videos — one consistent pattern across all three tables, not assumed. Album links come from `bp_media_context` (context_type='album'); album creation reads `bp_media_albums` directly via `$wpdb` for title/author.
- **UX expectation:** Same CLI progress-bar-per-source and final summary line contract as the other two importers; with `--source=all`, expect to see three separate progress bars (one per table) followed by one combined summary line.
- **Settings that change it:** `--source` flag scopes which of the three BuddyBoss tables run, not a persisted setting.
- **Edge cases:** A site with BuddyBoss Platform installed but the media component disabled (`bp_media_albums` table absent) — `create_buddyboss_album()` explicitly guards with `SHOW TABLES LIKE` and falls back to an untitled album under the fallback author rather than fataling; verify this path with the table genuinely absent, not just empty.

#### MV-IMP-004 — Admin migration card: detection and visibility
- **Edition:** Pro
- **Who:** Admin only (`manage_mvs_settings`).
- **Where:** wp-admin -> WPMediaVerse -> Import (submenu slug `mvs-migration`, top-level menu `wpmediaverse`), directly reachable at `admin.php?page=mvs-migration` even when hidden from the sidebar.
- **Setup:** none required to reach the page by URL; a detected source plugin (rtMedia/MediaPress/BuddyBoss tables via `SHOW TABLES`) or an already-started migration run is required for the sidebar link itself to appear.
- **Steps:** 1) With no source plugins installed, confirm "Import" is absent from the WPMediaVerse sidebar but the URL still loads the page. 2) Install/activate rtMedia (or seed its tables) and reload any admin page — detection is cached in a transient (`mvs_pro_migration_has_source`, ~1 hour TTL per the code comment "cached for an hour"), so the sidebar link may not appear immediately. 3) Confirm the card renders once detected.
- **Expected:** Each platform card shows: label, description, icon, available/not-found message, total count, already-imported count, and Start/Resume/Pause/Reset controls (per `AbstractMigrationAdmin::info()`/state contract). A run already started (persisted `mvs_migration_state_{slug}` option non-empty) also forces the sidebar link visible even if the source plugin was since deactivated (`is_available() || !empty(get_option(state_key))`).
- **UX expectation:** "Not found" card state shows the platform's own `not_found_message()` string (per-platform, not generic) — verify each of the three platforms has a distinct, helpful message rather than the same boilerplate. Detection transient means a customer who "just installed rtMedia and sees no card" is expected behavior for up to ~1 hour, not a bug — do not file this as a defect unless it persists past the TTL or a hard refresh/option-clear.
- **Settings that change it:** none — detection is automatic and read-only.
- **Edge cases:** Bookmark/direct-URL access to `mvs-migration` when nothing is detected — page must render a clean "no source detected" state per card, not a blank/broken shell. 390px viewport for the card grid and Start/Resume/Pause/Reset button row.

#### MV-IMP-005 — Admin migration: run a batch (Start/Resume/Pause/Reset)
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Import page, per-platform card. AJAX actions `wp_ajax_mvs_migration_batch` and `wp_ajax_mvs_migration_reset`, batch size 25 (`MigrationPage::BATCH_SIZE`).
- **Setup:** A detected source with importable rows.
- **Steps:** 1) Click Start on a platform card. 2) Observe batch progress (AJAX loop, 25 rows/call). 3) Click Pause mid-run. 4) Click Resume — verify it continues from the persisted `offset`, not from 0. 5) Click Reset — verify `clear_state()` wipes the option entirely (state reverts to defaults: status idle, offset 0, totals 0).
- **Expected:** State persists in `mvs_migration_state_{slug}` option: status, offset, total, imported, skipped, errors, started_at, updated_at, plus platform extras (`bb_source`, `dry_run`, `skip_albums`). `run_batch()` returns the standard shape `{batch_imported, batch_skipped, batch_errors, next_offset, done}`.
- **UX expectation:** Resume must pick up at the exact `next_offset` from the last batch, never re-processing or skipping rows. Confirmed: Reset has its own confirm dialog — it calls the shared destructive `window.mvsConfirm()` dialog with the message "Reset progress? This cannot be undone." before doing anything. "Done" state (all rows processed) must clearly change the UI from an active progress state to a completed one, not just stop silently.
- **Settings that change it:** the `skip_albums` and `dry_run` toggles are part of the per-platform run options (`$opts` passed to `run_batch()`). Confirmed: both are real checkboxes rendered directly in the shared migration-card template (`mvs-opt-dryrun`, `mvs-opt-skipalbums`) — every platform's card gets them for free; BuddyBoss's `extra_card_html()` only adds the media/document/video source dropdown on top of these two shared controls.
- **Edge cases:** Pausing and leaving the page, then returning later — state must survive a full page reload/browser close (it's a DB option, not JS-only state). Running a second platform's migration while the first is paused/mid-run — each platform's state is a separate option key, verify no cross-platform contention.

#### MV-IMP-006 — Imported media follows the same album-assignment rule as a normal upload
- **Edition:** Pro
- **Who:** Any admin running an import (CLI or admin UI); verify from the member-facing side too if the source album had a specific owner/privacy.
- **Where:** All three importers (rtMedia/MediaPress/BuddyBoss), both CLI and admin migration-card paths — both extend `AlbumMarkerLookupTrait`.
- **Setup:** A source item that belongs to a source-platform album/gallery.
- **Steps:** 1) Import media that belongs to a source album. 2) Check the resulting MVS album's membership and privacy. 3) Re-run the import (idempotency check on the album, not just the media).
- **Expected:** As of 2.6.0 (Basecamp 10264373450), album membership is written EXCLUSIVELY through Free's `AlbumService::add_items()` — the single writer of album membership for both member uploads and imports. This means: one album per photo, the album's privacy applies to the photo, and the photo's own privacy is preserved for if it ever leaves the album. Prior to 2.6.0, the four importers each inserted the membership row directly, bypassing all three of those rules. The re-run case must confirm `add_items()` is idempotent (a photo already in the album is not duplicated).
- **UX expectation:** Import must NOT create an activity-stream post per imported photo — the code explicitly passes `array('announce' => false)` to `add_items()` for exactly this reason ("an import must not post an activity update per photo"). If activity posts appear per imported item during a bulk import, that is a regression, not expected behavior.
- **Settings that change it:** `--skip-albums` (CLI) / the equivalent card option skips album creation/assignment entirely — media imports standalone with no album.
- **Edge cases:** A source album whose privacy would normally escalate (e.g. rtMedia group-context albums escalate from public to group privacy per `create_rtmedia_album()`) — verify the escalation only happens when the derived privacy is not already stricter ("only when nothing stricter was already derived, matching how individual media are imported").

#### MV-IMP-007 — Flickr OAuth connect flow
- **Edition:** Pro
- **Who:** Any logged-in member (member-facing connectors panel), not admin-only.
- **Where:** Member dashboard "Connected Accounts" tab (`templates/partials/dashboard-connectors-panel.php`) OR REST `POST /wp-json/mvs-pro/v1/connectors/flickr/connect`.
- **Setup:** `mvs_connectors_enabled` = `1` (Settings -> Flickr import section); either site-default Flickr app key/secret configured (`mvs_pro_connector_flickr_app_key`/`_app_secret`) OR the member supplies their own custom API key/secret.
- **Steps:** 1) Ensure connectors are enabled. 2) On the dashboard, click Connect on the Flickr card. 3) Complete Flickr's own OAuth 1.0a authorization page. 4) Land back on the callback / return_url.
- **Expected:** `start_auth()` requests a Flickr request token, stores it in a 300-second transient (`mvs_flickr_req_{user_id}`) keyed with the `use_custom` flag, and returns the authorization redirect URL. `handle_callback()` completes the exchange. REST `start_connect` accepts `use_custom`, `api_key`, `api_secret`, `return_url` params.
- **UX expectation:** If a member's OAuth session takes longer than 300 seconds to complete (transient expired), the callback must fail gracefully with a clear "try connecting again" message, not a cryptic error. Confirmed: an expired/missing transient triggers exactly this — error code `flickr_callback_token_mismatch`, message "Flickr OAuth token mismatch or session expired. Please try connecting again." — not cryptic. The dashboard card shows connected username once linked (read from `mvs_connector_flickr_username` user meta) and an "auto-export" toggle state.
- **Settings that change it:** `mvs_connectors_enabled` (master gate — off means the whole panel and REST routes disappear, see MV-IMP-011). Site-default app key/secret vs. per-member custom key changes which credential set is used (`get_api_credentials()` prefers custom over default).
- **Edge cases:** Connectors toggle flipped off mid-flow (between starting and completing OAuth) — the REST route would 404 on the callback step since routes only register when the toggle is on and checked at `rest_api_init`. Member with no Flickr account attempting to authorize — Flickr's own error page, not this plugin's concern, but the return path back to WP must still work cleanly.

#### MV-IMP-008 — Flickr import (member browses and imports remote photos)
- **Edition:** Pro
- **Who:** Connected member only (`check_connected` permission callback on REST).
- **Where:** `connector-import-modal.php` (rendered in `admin_footer` on the frontend dashboard despite its `templates/admin/` path — per Coding Rule 11, still theme-overridable). REST: `GET /mvs-pro/v1/connectors/flickr/photos` (paginated, max 30/page — Flickr TOS limit), `GET .../albums`, `POST .../import` with `photo_ids` array.
- **Setup:** Flickr connected.
- **Steps:** 1) Open "Import from Flickr" modal from the dashboard. 2) Browse/filter by album. 3) Select photos. 4) Click Import.
- **Expected:** `import_item()` per selected remote_id; imported media gets tagged with `mvs_media_imported` action (`$media_id, 'flickr', $remote_id`) and the external-source badge metadata (external_source, external_id, external_url, external_synced) used by `external-source-badge.php`.
- **UX expectation:** Modal header: "Import from {connector_label}" (e.g. "Import from Flickr"), with a close button (`aria-label="Close"`). Modal is `role="dialog"` `aria-modal="true"` `aria-labelledby`. Confirmed mixed: Escape-to-close IS wired (a real `keydown` listener closes the overlay). Confirmed defect: there is NO focus trap anywhere in `connector-import.js` — despite advertising `aria-modal="true"`, nothing stops Tab from moving focus out of the dialog into the page behind it, a real accessibility gap for keyboard/screen-reader users. Progress during the run is a real running indicator ("Importing X of Y…" with an updating `aria-valuenow` bar), not silent — but per-item failure display doesn't exist; failures are only reported as one aggregate count ("N failed") in the final summary, not per photo during the run.
- **Settings that change it:** `mvs_connectors_enabled`; a member's `default_privacy` connector preference (`match|public|friends|members|private`, settable via `PUT .../prefs`) controls the privacy assigned to imported photos when not otherwise specified.
- **Edge cases:** Importing a photo already imported previously (re-import) — verify Flickr's importer dedupes the same way the batch importers do, or confirm it deliberately does not (different mechanism, since this is REST-driven, not the AbstractBatchImporter dedup path). 390px — modal must remain usable, not overflow the viewport.

#### MV-IMP-009 — Flickr export / auto-export upload direction
- **Edition:** Pro
- **Who:** Connected member.
- **Where:** REST `POST /mvs-pro/v1/connectors/flickr/export` with `media_ids` array (manual); automatic via the `auto_export` per-member preference + `mvs_media_uploaded` hook.
- **Setup:** Flickr connected; for auto-export, the member's `auto_export` connector preference set to `1` via `PUT .../prefs`.
- **Steps (manual):** 1) POST export with a list of local attachment IDs. 2) Verify each uploads to Flickr via `export_item()`.
- **Steps (auto):** 1) Enable auto-export in the connected-accounts panel. 2) Upload new media as that member. 3) Confirm it appears on Flickr without manual action.
- **Expected:** Manual export fires `mvs_media_exported` action (`$media_id, 'flickr', $photo_id`). Auto-export is queued via Action Scheduler (`as_enqueue_async_action('mvs_connector_auto_export', [...], 'mvs-connectors')` if AS is available) with a `wp_schedule_single_event` fallback — hooked in `ConnectorManager::maybe_auto_export()` on `mvs_media_uploaded` (priority 20), and processed in `Plugin.php` by checking the member is still connected before calling `export_item()`.
- **UX expectation:** Auto-export is asynchronous — a member should NOT see the upload UI block or wait on the Flickr round-trip; the export happens in the background (AS action group `mvs-connectors`). Confirmed defect: a failed auto-export is completely silent — the Action Scheduler handler calls `$connector->export_item()` and discards whatever it returns (including a `WP_Error`) with no logging, no dashboard notice, no activity post, nothing. A member never learns their photo failed to auto-export to Flickr; this is a real gap, not an assumption.
- **Settings that change it:** the member's own `auto_export` and `default_privacy` prefs (`PUT /connectors/{id}/prefs`); `mvs_connectors_enabled` master gate.
- **Edge cases:** Auto-export firing for a member who disconnects Flickr between upload and the AS job actually running — `is_connected($author_id)` is checked at execution time, so it should no-op cleanly rather than error. Uploading many files at once — verify each queues its own AS action rather than one action trying to batch all of them (per-media hook firing per upload).

#### MV-IMP-010 — Flickr delta sync (incremental)
- **Edition:** Pro
- **Who:** Connected member (manual trigger) or system (continuation).
- **Where:** REST `POST /mvs-pro/v1/connectors/flickr/sync`; continuation hook `mvs_pro_connector_delta_sync_continue` (AS action group `mvs-connectors` via `as_enqueue_async_action`, or `wp_schedule_single_event` +1 minute fallback if AS unavailable).
- **Setup:** Flickr connected, with prior imports to sync against (uses `sync_metadata()` per already-imported remote_id).
- **Steps:** 1) Trigger `/sync` for the Flickr connector. 2) If the batch is large, confirm it self-continues via the scheduled hook rather than timing out the request. 3) Confirm `process_delta_sync_batch()` resumes from `$after_media_id` on the next continuation.
- **Expected:** `delta_sync()` REST handler kicks off the first batch synchronously and schedules continuation for the rest; `process_delta_sync_batch(connector_id, user_id, after_media_id)` is the resumable unit, cursoring on media_id.
- **UX expectation:** A member triggering `/sync` should get an immediate REST response (not block on the full sync), with the remainder completing in the background — verify the initial response communicates "sync started" rather than implying full completion when only the first chunk ran.
- **Settings that change it:** none beyond being connected; this is not admin-configurable.
- **Edge cases:** Action Scheduler unavailable (fallback to `wp_schedule_single_event`) — confirm the 1-minute-delayed single event actually fires reliably in that fallback path, per the background-jobs standard (AS-first with WP-Cron fallback). Very large photo libraries — verify the cursor-based continuation doesn't stall or duplicate work across many continuation hops.

#### MV-IMP-011 — Connectors master toggle off
- **Edition:** Pro
- **Who:** Admin toggles; effect verified as any member.
- **Where:** Settings -> Flickr import section, checkbox "Turn on Flickr import" (`mvs_connectors_enabled`).
- **Setup:** none.
- **Steps:** 1) Turn the toggle off and Save. 2) As a member, visit the dashboard Connected Accounts tab. 3) Attempt any connectors REST route directly (e.g. `GET /wp-json/mvs-pro/v1/connectors`).
- **Expected:** With the toggle off, `ConnectorManager`, `ConnectorRESTController`, and the Flickr `Connector` are never instantiated/registered (registration-gated in `Plugin::init()`, checked via `get_option('mvs_connectors_enabled','0') === '1'`) — REST routes simply do not exist (404 from WordPress's router, not a 403 from a permission callback), matching the "registration is never gated behind license, but toggle-gated" pattern used elsewhere in this plugin.
- **UX expectation:** The dashboard Connected Accounts tab/panel must not render a broken or half-populated connector grid when the feature is off — confirm the tab either doesn't appear at all or shows a clean "not available" state, never a shell with dead buttons. Fields under the Flickr import section (app key/secret) are hidden via `show_when: 'mvs_connectors_enabled'` — confirm they disappear from the settings screen live when the checkbox is unchecked, not just on next page load.
- **Settings that change it:** `mvs_connectors_enabled` itself.
- **Edge cases:** A member with an existing Flickr connection (user meta already saved) when the toggle is turned off, then back on — confirm the connection state (username, auto_export pref) survives the toggle flip rather than being wiped, since nothing in the toggle logic deletes user meta.

#### MV-IMP-012 — External-source badge and dedicated dashboard panel display
- **Edition:** Pro
- **Who:** Any viewer (badge on a media single view); connected member (Sync Now button only for connected users).
- **Where:** `templates/partials/external-source-badge.php` (rendered on media detail views for imported items), `templates/partials/dashboard-connectors-panel.php` (dashboard tab).
- **Setup:** A media item imported from Flickr (has `external_source`, `external_id`, `external_url`, `external_synced` metadata).
- **Steps:** 1) View a Flickr-imported media item's single page. 2) Confirm badge text and "View original" link. 3) As the connected owner, click "Sync Now."
- **Expected:** Badge text: "Imported from {Flickr}" with a bolded platform label (platform label map includes `flickr`, `unsplash`, `500px` — the latter two are NOT implemented connectors in this codebase per the module map, so seeing those labels would only ever come from data, never a live connector; this is template-level future-proofing, not a bug). "Last synced: {N ago}" or "Never" if `external_synced` is empty. "Sync Now" button only rendered `if ($is_connected)`.
- **UX expectation:** "View original" link opens in a new tab (`target="_blank" rel="noopener"`) with an outbound-link glyph. Confirmed defect: "Sync Now" is a dead button. It renders with a class (`mvs-sync-now-btn`) and the data attributes (`data-media-id`, `data-remote-id`, `data-connector`) a click handler would need, and the backend is fully built (`GET/POST` route to `sync_metadata()` exists and works) — but there is no JavaScript anywhere in the plugin that attaches a click listener to that class. Clicking "Sync Now" does nothing at all: no loading state, no success, no failure, no network request.
- **Settings that change it:** none — purely data-driven display.
- **Edge cases:** A non-owner, non-connected viewer sees the badge with source label and "Never"/timestamp but no Sync Now button, and no way to trigger a sync — confirm this is correct (read-only display for non-owners). 390px — badge and its meta row must stack without truncating the platform name or timestamp.

---

### Area: PSH

#### MV-PSH-001 — Register a device for push (Pro route, Free's registry)
- **Edition:** Pro (route) writing into Free's table
- **Who:** Logged-in member (app client)
- **Where:** `POST /wp-json/mvs-pro/v1/push/register-device` (also `POST /wp-json/mvs/v1/me/devices` on Free directly)
- **Setup:** None beyond being logged in
- **Steps:** 1) Send `{ expo_push_token, platform }` (platform enum ios|android|web) to the Pro route. 2) Confirm a row lands in `wp_mvs_device_tokens` (Free's table), not `wp_mvs_pro_push_devices`.
- **Expected:** `{ "registered": true }`. Empty token → 400 `mvs_invalid_token` ("A push token is required."). Not logged in → 401 `mvs_unauthorized`.
- **UX expectation:** Re-registering the SAME token for the SAME user just refreshes it (upsert, `ON DUPLICATE KEY UPDATE`) — no duplicate row, no error. This is a native-app-only surface; there is no web UI screen for this in the codebase (mobile app calls it directly), so "success" is verified via the API response and the DB row, not a visible confirmation screen.
- **Settings that change it:** none.
- **Edge cases:** See MV-PSH-002 for the token-hijack refusal case.

#### MV-PSH-002 — Cross-registry behavior: token already owned by another member
- **Edition:** Pro/Free boundary
- **Who:** Two different members, one device
- **Where:** Same `/push/register-device` route → Free's `PushService::register_token()`
- **Setup:** Member A registers a token; Member B (different account, e.g. shared device) attempts to register the SAME token string
- **Steps:** 1) Register token T for user A. 2) Attempt to register token T for user B via the same or the other route.
- **Expected:** REFUSED, not moved — `register_token()` returns `false` when the token exists under a different `user_id`. Response is still `{ "registered": false }` (200, not an error status) — the app must check the boolean, not just the HTTP status.
- **UX expectation:** No error message surfaces distinctly for this case versus a generic false — the API itself gives no hint beyond `false`. Check: whether the mobile app has copy for "this device is registered to someone else" or silently retries/ignores cannot be settled from this repo (separate app codebase) — confirm directly in the app.
- **Settings that change it:** none — deliberate anti-hijack design, not configurable.
- **Edge cases:** Confirm Pro's legacy `mvs_pro_push_devices` table stays untouched/empty in 2.6.0 (emptied by Migrator v18) — writing to it would be a regression.

#### MV-PSH-003 — Push delivery via Expo on a real notification
- **Edition:** Pro (delivery) triggered by Free (dispatch signal)
- **Who:** System — any notification-generating action (follow, reaction, DM, etc.)
- **Where:** Free fires `mvs_notification_created` → `mvs_push_send`; Pro's `PushService::on_push_send()` → Action Scheduler `mvs_pro_push_send` → `deliver()` → `https://exp.host/--/api/v2/push/send`
- **Setup:** Recipient has at least one registered Expo-format token (`ExponentPushToken[...]` or `ExpoPushToken[...]`)
- **Steps:** 1) Trigger a notification for a user with a registered device (e.g., have another member follow them). 2) Confirm an Action Scheduler async action `mvs_pro_push_send` is queued, then runs. 3) Confirm the Expo API receives a batch (chunks of up to 100 messages) with `title` = site name, `body` = the same message text as the in-app notification, `data.type` in {follow, conversation, media} and a deep-link id.
- **Expected:** Message body and link are identical to the in-app notification (both built once, upstream, by Free's `NotificationService`) — push and in-app notification can never say different things.
- **UX expectation:** If Action Scheduler is unavailable, delivery falls back to running INLINE on the same request that created the notification (no silent drop) — but this means a slow Expo API call could add latency to whatever action triggered the notification; worth timing on a slow network.
- **Settings that change it:** none (no admin on/off for push delivery itself beyond app branding).
- **Edge cases:** Recipient has zero tokens → clean no-op, nothing queued. Non-Expo-format tokens present (e.g., raw FCM/APNs tokens from a different client) → filtered out by an Expo-token-format check, never sent to Expo.

#### MV-PSH-004 — Invalid/expired token handling (Expo prune)
- **Edition:** Pro
- **Who:** System
- **Where:** `PushService::prune_invalid_tokens()`, called after every Expo delivery batch
- **Setup:** A registered token that Expo will report as `DeviceNotRegistered` (e.g., an uninstalled app's stale token)
- **Steps:** 1) Trigger a push to a recipient with one valid + one stale token. 2) Inspect Expo's response ticket for the stale one.
- **Expected:** Only tokens Expo explicitly reports as `status: error, details.error: DeviceNotRegistered` are removed via `unregister_device()`; any other Expo error status (rate limit, malformed request, etc.) is left alone — the code only prunes on that exact error code, so a transient Expo hiccup never wipes a good token.
- **UX expectation:** Entirely silent/backend; member never sees an error for this — their next real notification simply stops trying that dead token.
- **Settings that change it:** none.
- **Edge cases:** `wp_remote_post` returns a `WP_Error` (network failure reaching Expo) → pruning is skipped entirely (fails safe, does not misinterpret a network error as "device gone").

#### MV-PSH-005 — Per-type mute preference suppresses Pro delivery too
- **Edition:** Free filter, Pro respects it automatically
- **Who:** Member with a muted notification type
- **Where:** `mvs_push_should_send` filter, checked in Free's `PushService::dispatch()` BEFORE `mvs_push_send` ever fires
- **Setup:** A client/extension that implements per-type mute preferences via this filter (base install has no UI for muting types, filter is a pure extension seam)
- **Steps:** 1) Hook `mvs_push_should_send` to return false for a given type. 2) Trigger that notification type for a user with valid tokens.
- **Expected:** `mvs_push_send` never fires, so Pro's `on_push_send` never runs — Pro subscribes only to `mvs_push_send`, which Free withholds when muted.
- **UX expectation:** No distinct feedback either way — purely a backend suppression test.
- **Settings that change it:** none in base install (extension point only) — flag to product/QA that there is currently no member-facing "mute this notification type" UI at all in either plugin's code found; this filter exists with nothing wired to call it.
- **Edge cases:** none beyond confirming the short-circuit.

#### MV-PSH-006 — Unregister a device (logout/uninstall)
- **Edition:** Pro route into Free's table
- **Who:** Logged-in member, own tokens only
- **Where:** `DELETE /wp-json/mvs-pro/v1/push/register-device`
- **Setup:** Member has a registered token
- **Steps:** 1) Send DELETE with the token. 2) Confirm row removed from `wp_mvs_device_tokens`.
- **Expected:** `{ "removed": true }`; scoped to `user_id + token` so a member can never delete another member's token by guessing/sending their token string.
- **UX expectation:** Silent success, no visible surface (app-only route).
- **Settings that change it:** none.
- **Edge cases:** Token belongs to someone else → `removed: false` (no information leak about whether the token exists at all).

#### MV-PSH-007 — App branding settings surfaced via /app/config
- **Edition:** Pro settings, Free's public endpoint
- **Who:** Site owner (Settings), consumed by the app client (no auth required to read)
- **Where:** `wp-admin/admin.php?page=mvs-settings#app` ("Mobile App Branding" section); `GET /wp-json/mvs/v1/app/config` (public, `permission_callback: __return_true`)
- **Setup:** None
- **Steps:** 1) Set Accent Color (hex, e.g. `#7C3AED`), upload an App Logo (media picker), upload a Login Background (media picker), toggle "Default to Dark Mode." 2) Save. 3) Call `/app/config` unauthenticated.
- **Expected:** Response's `branding` object gains `accent_color`, `logo_url`, `login_bg_url` only for fields actually set (empty accent color is omitted entirely, not sent as `""`, so the app falls back to its own default color rather than rendering an empty/invalid one). Dark-mode default and feed layout also ride this same endpoint.
- **UX expectation:** Leaving Accent Color empty must NOT break the color picker on save/reload (a native colour input cannot be empty — verify the field renders correctly empty rather than defaulting to black or erroring).
- **Settings that change it:** "Accent Color" (`mvs_app_accent_color`), "App Logo" (`mvs_app_logo_id`), "Login Background" (`mvs_app_login_bg_id`), "Default to Dark Mode" (`mvs_app_dark_mode_default`).
- **Edge cases:** Logo/background media ID pointing at a deleted attachment → `wp_get_attachment_image_url()` returns false, field silently omitted from the branding payload. 390px: N/A (native app / API surface, not a responsive web page).

### Area: LIC

#### MV-LIC-001 — Activate a license key
- **Edition:** Pro
- **Who:** Admin only (`current_user_can('manage_options')`).
- **Where:** WPMediaVerse -> Settings -> License tab (hash-anchor `#license`, sidebar item labeled "License" with a `key-round` icon, priority 100 — always last in the sidebar).
- **Setup:** A valid license key for EDD item ID 1660828, store `https://wbcomdesigns.com`.
- **Steps:** 1) Go to Settings -> License. 2) Enter the license key. 3) Click "Activate License".
- **Expected:** POSTs `edd_action=activate_license` to the store with `license`, `item_id=1660828`, `url=home_url()`, `environment=wp_get_environment_type()`. On success (`body->success` true AND `body->license === 'valid'`), stores `wpmediaverse-pro_license_key` and `wpmediaverse-pro_license` (raw SDK response object) options, and always writes `wpmediaverse-pro_license_key_allow_tracking` with `allowed: true`.
- **UX expectation:** Success notice: "License activated successfully." (WP `settings_errors` styled). Status table then shows: Status = green checkmark + "Active"; License Key = masked (`****`-middle, first/last 4 chars visible via `get_masked_key()`); Plan = tier label (starter/growth/agency/lifetime/pro, capitalized); Expires = "Never. Lifetime License" for lifetime, else a formatted date via the site's `date_format` option, else an em-dash if unknown. Deactivate button appears once active.
- **Settings that change it:** none beyond the key itself; `MVS_PRO_LICENSE_BYPASS` constant (if defined true in wp-config) makes `is_valid()`/`get_tier()` always return valid/lifetime regardless of any real key — a dev/QA-only bypass, verify it is never set on a production site being tested.
- **Edge cases:** Network failure reaching the store: "Could not reach the license server. Please try again." (distinct from a rejected key). Empty key submitted: confirmed — there is no client- or server-side check for a blank key before posting; it round-trips to the EDD store as `license: ''` and the store's `missing`/`invalid` response resolves to the same generic "Invalid license key." shown for any other bad key, not a distinct "please enter a key" prompt. 390px — form-table license key input and Activate button must not overflow.

#### MV-LIC-002 — Deactivate a license key
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> License tab, "Deactivate License" button (shown only when currently active).
- **Setup:** An active license.
- **Steps:** 1) Click "Deactivate License".
- **Expected:** POSTs `edd_action=deactivate_license` with the stored key + item_id + site url, then unconditionally deletes both `wpmediaverse-pro_license_key` and `wpmediaverse-pro_license` locally regardless of the remote response (no error branch checked on this POST) — so a deactivation always succeeds locally even if the remote call fails or times out.
- **UX expectation:** Success notice: "License deactivated." The screen reverts to the inactive-state form (key input + "Activate License").
- **Settings that change it:** none.
- **Edge cases:** Deactivating while offline/store unreachable — confirm the local state still clears (by design, per the code — this could double-consume an activation slot on the store side if the remote call silently failed, which is worth flagging as a real risk rather than a bug: the local UI will show "inactive" even if the remote side still thinks the site is active).

#### MV-LIC-003 — Expired / invalid license status and error messages
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> License tab.
- **Setup:** An expired, disabled, or otherwise invalid key.
- **Steps:** 1) Attempt to activate an expired/invalid/wrong-product key. 2) Observe the specific error message.
- **Expected:** `get_license_error_message()` maps EDD error codes to exact strings: `expired` -> "Your license has expired."; `disabled` -> "Your license has been disabled."; `missing`/`invalid` -> "Invalid license key." (both codes share this one string); `site_inactive` -> "License is not active for this site."; `item_name_mismatch` -> "This license key is not for MediaVerse Pro."; `no_activations_left` -> "No activations remaining for this license."; any unmapped code -> "An error occurred. Please try again." `is_valid()` additionally re-derives expiry client-side: even a stored `license: 'valid'` status is treated as invalid locally if `expires` is a past date-string (non-"lifetime") — so a key that expired since the last check shows Inactive on the very next page load without a fresh API call.
- **Settings that change it:** none — purely status display.
- **Edge cases:** A key that WAS valid and is stored as such, but has since passed its `expires` date with no re-check having happened — confirm the settings screen recomputes and shows Inactive/the activation form on that basis (client-side date comparison), not stale "Active" state. 390px — long error strings must wrap in the notice, not overflow.

#### MV-LIC-004 — Update channel gating (licence controls updates only)
- **Edition:** Pro
- **Who:** Admin only (verify via Plugins/Updates screen, not the License tab).
- **Where:** wp-admin -> Plugins -> Updates / Dashboard -> Updates, for WPMediaVerse Pro.
- **Setup:** License inactive/expired vs. active, same plugin version installed in both states.
- **Steps:** 1) With license inactive, check for plugin updates. 2) Activate the license. 3) Re-check for updates.
- **Expected:** Per CLAUDE.md's explicit, non-negotiable design statement: `License::is_valid()` exists SOLELY to render the Active/Inactive badge and gate the auto-update channel — an inactive/expired license blocks receiving update notifications/downloads via EDD SL, but blocks NOTHING else. This is confirmed structurally: no `License::is_valid()` call exists anywhere gating a feature, service, or REST registration in the code read across STO/IMP in this pass.
- **UX expectation:** With an inactive license, the plugin should simply not show an available-update notice (or show one the SDK's own update-checker refuses to deliver a package for) — it must NOT show any "Pro features disabled" or upsell-nag banner, since no such gate exists by design. If a nag banner is found anywhere, it contradicts the documented design and should be reported as a genuine defect, not expected behavior.
- **Settings that change it:** license activation state only.
- **Edge cases:** none beyond confirming absence of gating — this entry is fundamentally a "prove the negative" check across every Pro feature area (storage drivers, importers, connectors, and every other Pro module), not just License's own screen.

#### MV-LIC-005 — No feature functionality is blocked by license state outside Documents writes
- **Edition:** Pro
- **Who:** Admin/member, whichever role the specific feature normally requires.
- **Where:** Every Pro surface exercised in STO and IMP above (storage drivers, migration/import, Flickr connector) plus any other Pro module, with the license deliberately left inactive/expired.
- **Setup:** No active license (or `MVS_PRO_LICENSE_BYPASS` explicitly undefined/false).
- **Steps:** 1) With no active license, configure and use an S3/BunnyCDN/R2/DOSpaces driver end-to-end (upload, migrate, test connection). 2) Run a CLI import. 3) Connect and use the Flickr connector (OAuth, import, export, sync). 4) Confirm every one of these works exactly as it would with an active license.
- **Expected:** All of the above work identically regardless of license state — this is the explicit, documented exception-free rule ("Every Pro feature runs regardless of license state (no/expired/refunded)"), with the ONE stated exception being Documents module WRITES (a different area's scope, not this one).
- **UX expectation:** No feature in STO/IMP should show a license-gated notice, blocked button, or "upgrade to unlock" state anywhere. If any such gate is found on a storage driver, importer, or connector, it is a direct contradiction of the documented design (Varun, 2026-06-04) and should be raised as a high-priority defect, not accepted as intended behavior.
- **Settings that change it:** none — this is a structural absence to verify, not a setting.
- **Edge cases:** Verify specifically that switching storage drivers, running "Migrate all"/"Delete next N", and every connector REST route all remain fully functional with the license inactive — these are exactly the features most likely to be mistakenly license-gated by a future regression, per the CLAUDE.md warning against "gating a second feature behind this precedent."

#### MV-LIC-006 — License settings screen on mobile (390px)
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** Settings -> License tab at 390px viewport, both active and inactive states.
- **Setup:** none.
- **Steps:** 1) Load the License tab at 390px in both the activation-form state and the active-status-table state.
- **Expected:** The `form-table` status rows (Status/License Key/Plan/Expires) and the key-entry form must not overflow horizontally.
- **UX expectation:** Masked key display (`code` element) must not force horizontal scroll on narrow screens; Activate/Deactivate buttons remain full-width-tappable per the mobile baseline.
- **Settings that change it:** none.
- **Edge cases:** Long tier label or a long formatted expiry date string at narrow width.

---

### Area: PTL

#### MV-PTL-001 — Compete hub page + body template override
- **Edition:** Pro
- **Who:** Theme developer / site owner's developer overriding a template; effect seen by all visitors.
- **Where:** `templates/compete-hub.php` (outer page shell, located via Free's `TemplateLoader::locate()`) and `templates/compete-hub-body.php` (content, located via `LayoutManager::theme_or_plugin()`).
- **Setup:** Competitions enabled with at least one sub-feature.
- **Steps:** 1) Copy `compete-hub-body.php` into `<active-theme>/wpmediaverse/templates/compete-hub-body.php`. 2) Edit a visible string. 3) Reload `/compete/`.
- **Expected:** The theme copy is used instead of the plugin's; the plugin file is never touched. Both files carry a `@version` header comment.
- **UX expectation:** The override must produce no visual regression on a theme that hasn't customized it (the theme copy is a straight copy until edited) — a broken override should fail obviously (fatal or visibly wrong), not silently half-apply.
- **Settings that change it:** none — this is a file-system override mechanism, not a setting.
- **Edge cases:** editing the theme copy but forgetting to bump its own `@version` note is a developer-discipline issue, not something the plugin enforces on a THEME'S copy (the local-CI `@version` bump check only runs against the plugin's own shipped templates before a release).

#### MV-PTL-002 — Battles page + body template override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/battles.php` (via Free's `TemplateLoader::locate()`) and `templates/battles-body.php` (via `theme_or_plugin()`).
- **Setup:** Battles enabled.
- **Steps:** 1) Copy `battles-body.php` to the theme override path. 2) Customize markup (e.g. reorder the tab labels). 3) Reload `/media/battles/`.
- **Expected:** Theme copy renders; Interactivity API data attributes and store bindings must be preserved by the developer for vote/accept/submit actions to keep working — the plugin does not validate a theme override's markup for correctness.
- **UX expectation:** A malformed override that drops a required `data-wp-*` binding degrades that specific control to dead/non-interactive, not a fatal error — matching the plugin's general behavior for stripped bindings elsewhere.
- **Settings that change it:** none.
- **Edge cases:** overriding while `mvs_battles_enabled` is off has no effect (the route/template never loads).

#### MV-PTL-003 — Challenges page + body template override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/challenges.php` and `templates/challenges-body.php`.
- **Setup:** Challenges enabled.
- **Steps:** 1) Copy `challenges-body.php` to the theme override path. 2) Reload `/media/challenges/`.
- **Expected:** Theme copy renders identically in structure; countdown/vote/submit interactivity continues to function if the override preserves the relevant markup hooks.
- **UX expectation:** Same as MV-PTL-002 — a broken override degrades the specific control it damaged, not the whole page.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-004 — Tournaments page + body template override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/tournaments.php` and `templates/tournaments-body.php`.
- **Setup:** Tournaments enabled.
- **Steps:** 1) Copy `tournaments-body.php` to the theme override path. 2) Reload `/media/tournaments/`.
- **Expected:** Theme copy renders the bracket UI; the bracket's horizontal-scroll/fade-edge behavior depends on the CSS classes the override keeps.
- **UX expectation:** An override that drops the bracket's scroll-container class would break the documented mobile scroll-with-fade behavior — this is on the theme developer to preserve, not something the plugin re-injects.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-005 — Boost modal partial override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/partials/boost-modal.php`, loaded via `theme_or_plugin()` from `Frontend/BoostAffordance.php`.
- **Setup:** Boosts enabled and available (WB Gamification present).
- **Steps:** 1) Copy the partial into the theme override path. 2) Adjust the slider markup. 3) Open the Boost modal.
- **Expected:** Theme copy renders; the modal's dialog role/aria-modal attributes and focus-trap hooks must be preserved by the override for the accessibility behavior documented in MV-BST-001 to keep working.
- **UX expectation:** A developer removing the `role="dialog"`/`aria-modal` attributes in an override silently regresses keyboard/screen-reader behavior with no plugin-side warning — worth flagging in any override-review checklist.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-006 — Streak widget partial override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/partials/streak-widget.php`, loaded via `theme_or_plugin()` from `Core/Plugin.php`.
- **Setup:** Streaks enabled.
- **Steps:** 1) Copy the partial into the theme override path. 2) Adjust the milestone display. 3) Reload `/my-media/`.
- **Expected:** Theme copy renders on the dashboard; the "Buy Freeze" button's data attributes must be preserved for the purchase flow (MV-BST-007) to keep working.
- **UX expectation:** Same override-fidelity concern as the other interactive partials.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-007 — Connected accounts / connectors dashboard panel override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/partials/dashboard-connectors-panel.php`, loaded via `theme_or_plugin()` from `Core/Plugin.php`.
- **Setup:** Connectors enabled (`mvs_connectors_enabled`).
- **Steps:** 1) Copy the partial into the theme override path. 2) Adjust the Connect/auto-export toggle markup. 3) Reload the dashboard's Connected Accounts tab.
- **Expected:** Theme copy renders; the Connect button and auto-export toggle must keep their data attributes for the OAuth flow (MV-IMP-007) and auto-export preference (MV-IMP-009) to keep working.
- **UX expectation:** Same override-fidelity concern.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-008 — External source badge partial override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/partials/external-source-badge.php`, loaded via `theme_or_plugin()` from `Core/Plugin.php`.
- **Setup:** At least one Flickr-imported media item.
- **Steps:** 1) Copy the partial into the theme override path. 2) Adjust badge styling/copy. 3) View an imported item's single page.
- **Expected:** Theme copy renders the badge; the "Sync Now" button's `data-media-id`/`data-remote-id`/`data-connector` attributes must be preserved for the sync click-handler (MV-IMP-012) to keep working.
- **UX expectation:** Same override-fidelity concern.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-009 — Connector import modal override (frontend-rendering exception)
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/admin/connector-import-modal.php` — despite living under `templates/admin/`, this file renders on the MEMBER-facing frontend dashboard (in `admin_footer` on the frontend page), and is explicitly named as the one exception to the "templates/admin/ is never theme-overridable" rule (Coding Rule 11, CI enforced by `bin/template-version-check.sh --include templates/admin/connector-import-modal.php`).
- **Setup:** Connectors enabled.
- **Steps:** 1) Copy this file into the theme override path exactly as any frontend template. 2) Adjust the modal markup. 3) Open "Import from Flickr" on the dashboard.
- **Expected:** Theme copy renders; dialog role/aria-modal/focus-trap and the photo/album browsing markup must be preserved for MV-IMP-008's flow.
- **UX expectation:** Because this file's path looks admin-only, a developer might assume it's NOT overridable and miss it during a theme audit — worth calling out explicitly in any override documentation/checklist so it isn't overlooked as "just an admin file."
- **Settings that change it:** none.
- **Edge cases:** confirm the local-CI template-version-check script genuinely includes this file (it is explicitly passed via `--include`) even though its path would otherwise be excluded by the `templates/admin/` blanket rule.

#### MV-PTL-010 — Feed card partial override (shared across layouts)
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/partials/feed-card.php`.
- **Setup:** any layout active.
- **Steps:** 1) Copy the partial into the theme override path. 2) Adjust the card markup. 3) Reload Explore.
- **Expected:** Theme copy renders for whichever layout uses this shared partial. Confirmed: `feed-card.php` is consumed ONLY by the Instagram layout (its feed-body.php and profile.php) — Pinterest, Flickr, and Dribbble each render their own inline card markup directly in their own feed-body.php files, not through this partial. A theme override of `feed-card.php` therefore affects Instagram cards only, never the other three layouts.
- **UX expectation:** If this partial is only used by a subset of layouts, an override here silently has NO effect on the others — a theme developer expecting one override to reskin every layout's cards would be surprised; document this scope clearly.
- **Settings that change it:** none.
- **Edge cases:** confirm exact per-layout usage of this shared partial before relying on it for a full reskin.

#### MV-PTL-011 — Stories bar partial override
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/partials/stories-bar.php`.
- **Setup:** Stories enabled, Instagram layout active (for the auto-embed case) or the Stories Bar block placed manually.
- **Steps:** 1) Copy the partial into the theme override path. 2) Adjust the avatar ring/story-tile markup. 3) Reload a page showing the stories bar.
- **Expected:** Theme copy renders; the story viewer's dialog/focus-trap/keyboard behavior (MV-STY-008) depends on markup hooks the override must preserve.
- **UX expectation:** Same override-fidelity concern as the other interactive partials — a broken override here degrades the viewer experience specifically, not the whole page.
- **Settings that change it:** none.
- **Edge cases:** none beyond the general override mechanism.

#### MV-PTL-012 — Per-layout feed-body template override (Instagram/Flickr/Pinterest/Dribbble)
- **Edition:** Pro
- **Who:** Theme developer.
- **Where:** `templates/layouts/instagram/feed-body.php`, `templates/layouts/flickr/feed-body.php`, `templates/layouts/pinterest/feed-body.php`, `templates/layouts/dribbble/feed-body.php` — each loaded via `theme_or_plugin()` (Instagram directly in `InstagramLayout.php`, the other three via the shared `AbstractConnectorFeedLayout::get_template_dir()`).
- **Setup:** Each layout active in turn.
- **Steps:** 1) Copy one layout's `feed-body.php` into the theme override path (mirroring the same `templates/layouts/<name>/feed-body.php` relative path under the theme's `wpmediaverse/` folder). 2) Adjust markup for that layout only. 3) Reload Explore under that layout, then switch to a different layout and confirm it is unaffected.
- **Expected:** Only the overridden layout's markup changes; the other three layouts continue using the plugin's own templates — overrides are per-layout, not global.
- **UX expectation:** Search/sort/tag-chip parity (MV-LAY-010/011/012) must survive an override just as much as the un-overridden default — a theme customizing one layout's card markup must not accidentally drop a control the parity audit (MV-LAY-015) checks for.
- **Settings that change it:** "Layout" selects which of these four is active; overriding does not change which layout is selected.
- **Edge cases:** overriding a layout that is not currently selected has no visible effect until that layout is switched to.

#### MV-PTL-013 — `@version` bump enforcement on Pro templates
- **Edition:** Pro (developer/release process, not member-facing)
- **Who:** Pro plugin developer preparing a release; verified by local-CI, not by any UI.
- **Where:** every file listed above (all of `templates/` except `templates/admin/`, plus the one named exception `templates/admin/connector-import-modal.php`); enforced by `bin/template-version-check.sh` (local-CI stage 1.9).
- **Setup:** a pending change to any overridable template's markup, without touching its `@version` comment.
- **Steps:** 1) Edit one of the templates above without bumping its `@version` header. 2) Run the local-CI template-version-check stage (or the full pipeline). 3) Bump the version, re-run.
- **Expected:** Step 2 fails the gate — a changed-since-last-tag template without a version bump is caught before it can ship; a template changed AND correctly bumped passes.
- **UX expectation:** N/A member-visible — this exists so a site running an OLD theme override of a template can eventually notice (via the version number, in documentation or a future admin notice) that the plugin's copy has moved on and their override may be stale; it is purely a developer/maintenance safety net.
- **Settings that change it:** none — this is a release-process gate, not a runtime setting.
- **Edge cases:** a template that is only ever included via one plugin version's code path (e.g. a template that has never actually changed since it was authored) should not force a needless version bump — verify the check compares against actual content diff since the last tag, not merely "does this file exist in the templates list."

### Area: PSET

#### MV-PSET-001 — Settings screen sub-tab navigation model (hash-anchor tabs)
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** WPMediaVerse -> Settings, all tabs (`#storage`, `#connectors`, `#license`, `#webhooks`, etc.).
- **Setup:** none.
- **Steps:** 1) Navigate directly to `admin.php?page=mvs-settings#connectors` via URL. 2) Reload the page while on a non-default tab. 3) Switch tabs via the sidebar without a page reload.
- **Expected:** Per an explicit code comment discovered while reading `ProSettings.php` (`enqueue_connector_assets()`), the settings tabs are pure client-side HASH anchors — there is no server-side `$_GET['section']` distinguishing them. This was the direct cause of a real bug: connector assets were previously gated on a `$_GET['section'] === 'connectors'` check that could never be true, so the connector card CSS/JS never loaded on the live screen (measured: 0 stylesheet links, no `:has(...)` rules in the CSSOM) — since fixed to load on every settings-page view via `strpos($hook_suffix, 'mvs-settings')` instead.
- **UX expectation:** Confirm every Pro-added tab's assets (not just connectors, which was the one already fixed) load correctly regardless of which hash tab is active on page load — this class of bug (asset gated on a `$_GET` param that a hash-routed SPA-style tab UI can never satisfy) is exactly the kind of thing worth re-checking on any NEW settings section added to this page.
- **Settings that change it:** none — this is the navigation mechanism itself.
- **Edge cases:** Deep-linking to a specific hash tab from an external link/bookmark/documentation and confirming the correct tab is pre-selected on load, not defaulting to the first tab.

#### MV-PSET-002 — Sidebar section merge model (Pro fields injected into Free's tab groups)
- **Edition:** Pro
- **Who:** Admin only.
- **Where:** WPMediaVerse -> Settings sidebar, groups `storage`, `display`, `app`, `ai`, plus two Pro-only additions: `documents` (group `media`, priority 46) and `connectors` (labeled "Flickr import", group `access`, priority 75).
- **Setup:** none.
- **Steps:** 1) Confirm S3/BunnyCDN/R2/DOSpaces/Watermark sections appear inside the existing "Storage" sidebar card (not as separate Pro cards) via `mvs_settings_sections` filter merge. 2) Confirm the "Flickr import" sidebar entry appears under the "access" group, not under storage/media.
- **Expected:** Pro deliberately merges its section IDs into Free's existing sidebar groups rather than adding standalone Pro cards, per the explicit code comment: "This keeps the sidebar clean — Pro features appear within the same card." The "Documents" sidebar item uses a Lucide icon name (`file-text`) specifically because the sidebar renders `data-lucide` attributes, not Dashicons — a prior version used a Dashicon-vocabulary name (`media-document`) that silently rendered no icon at all.
- **UX expectation:** Verify every Pro-added sidebar entry actually renders an icon (spot-check "Documents" specifically, given the documented history of it rendering iconless) and that grouped items visually belong to the correct parent card, not floating as an orphaned duplicate section.
- **Settings that change it:** none — this is registration-time merge logic, not a runtime setting.
- **Edge cases:** A sidebar group that Free does not register at all in a given configuration (`isset($sections[$key])` guards each merge) — confirm Pro's fields don't silently disappear if the expected Free group key is ever renamed/missing, since the merge is defensive but silent on a miss.


---

## Part 4 - Code organization audit (long-term health)

This part is for the development team and code reviewers. A black-box tester can skip it. Run it on
every release and on any change that adds a feature. It checks the layers the plugin adds to
WordPress, one layer at a time, so a feature that works today stays maintainable.

The detailed rules live in `qa/rules/` and in each plugin's `CLAUDE.md`. This checklist is the audit
order, not a second copy of those rules. Where a rule has an automatic gate, the gate is named.
`composer ci` in each plugin runs them all.

### 4.1 Data layer (tables, options, meta)

- [ ] SQL lives only in the repositories (`includes/Repository/`), always through `$wpdb->prepare()`.
      Site-wide counts go through `AdminAggregatesService`. *Gate: 2.1 coding-rules.*
- [ ] Every `WHERE`, `ORDER BY` and `JOIN` column is indexed. Every list query has `LIMIT`/`OFFSET`
      and a matching `COUNT(*)`. There are no per-row queries inside loops.
- [ ] Data keyed by user or item lives in an `mvs_*` table. It never goes in options, and never in
      per-entity transients. *Gate: 2.1 Rule 4.*
- [ ] Schema changes bump `Migrator::CURRENT_VERSION`. Migrations can be re-run, and never loosen
      privacy or delete member content on upgrade (for example, v40 must not publish a private
      photo).
- [ ] Every table that holds user data is on the privacy eraser or the retain list. *Gate: 1.6b.*
- [ ] Every data store has all three entry points: a member UI, an admin UI and REST. An exception is
      documented in the manifest (Free Rule 18, Pro Rule 6).

### 4.2 Service layer (business rules)

- [ ] A rule lives in one service and every entry point calls it. The album privacy rule, for
      example, goes through `AlbumService` whether the change comes from the dashboard, REST, bulk
      edit or an importer.
- [ ] No sibling classes with mostly duplicate method bodies. *Gate: 1.5 duplication.*
- [ ] Methods stay under about 50 lines. Files in the Known Debt table do not grow (debt tax, Free
      Rule 15). `UploadService.php` and `MediaController::replace_file` are the open items.
- [ ] Failures return `WP_Error` or go to `LoggerService`. There is no silent `return false`. A
      refusal is never sent as a success response. *Gate: 2.1 Rule 6.*
- [ ] Default-behaviour changes ship with a filter so a site can restore the old behaviour
      (Production Rule 3). Public hooks, options, routes and templates are deprecated for 2 major
      versions before removal (Production Rules 1, 2 and 5).

### 4.3 REST layer (`mvs/v1`, the mobile app contract)

- [ ] Every controller extends `WP_REST_Controller` and defines a schema, and every route has a real
      `permission_callback`. There is no `__return_true` on a write.
- [ ] Everything a member can do in the UI can be done through REST alone, because the mobile app
      depends on it (Free Rule 18).
- [ ] A denied item gives the same status and body as a missing one.
- [ ] Every new route and hook is in the manifest (`audit/manifests/`), and manifest freshness is
      checked. *Gates: 3.1a and 3.1b.*

### 4.4 Presentation layer (templates, blocks, CSS, JS)

- [ ] HTML lives in `templates/` or in a block's `render.php`, never echoed from classes (Free
      Rule 4). There is no inline `<style>`, `<script>` or `onclick`.
- [ ] No cosmetic inline `style=""` and no hard-coded hex values in markup. *Gate: 1.7.*
- [ ] Every colour, space and radius is a `--mvs-*` token. Dark mode is handled by token overrides at
      the root, not per component. *Gate: 1.4 CSS token contract.*
- [ ] Each CSS file stays in its lane: `frontend.css`, `bp-integration.css` (scoped `#buddypress`),
      `messaging.css`, `admin.css`, or the block's own `style.css`. There are no dead selectors, and
      `!important` only with a comment (Free Rule 12).
- [ ] Responsive rules use the standard breakpoints. RTL uses logical properties
      (`margin-inline-*`). An RTL selector carries `[dir="rtl"]` once (a bulk edit once repeated it
      five times, so the rule never matched).
- [ ] Every render path that can end early shows an empty state (Free Rule 11). There are no orphan
      templates. *Gate: 1.8.*
- [ ] Every theme-overridable template carries `@version`, bumped when it changes. *Gate: 1.9.*
- [ ] All member-facing strings are translatable with the `wpmediaverse` / `wpmediaverse-pro` text
      domain.
- [ ] Icons are Lucide only on the frontend.

### 4.5 Integration layer (BuddyPress, BuddyNext, themes)

- [ ] MediaVerse never edits site menus (Free Rule 17, Pro Rule 5), and never changes another
      plugin's data or settings. When a host plugin decides something (for example Members Only), the
      host always wins, through the documented filter.
- [ ] BuddyPress code lives in `includes/Integrations/BuddyPress/` and its CSS in
      `bp-integration.css`.
- [ ] Anything a theme or host plugin might need to change has a filter or template override. It is
      never a hard-coded branch for one theme.

### 4.6 Free and Pro boundary

- [ ] Pro never imports Free concrete classes. It reaches Free only through `Plugin::free_service()`
      (with a null guard) and interfaces or constants.
      *Gate: 2.2 architecture invariants. Rule 9 known-gap count must not rise; run the script for
      the current number.*
- [ ] Every Pro feature has a toggle in `ProSettings`. A feature that is switched off leaves no
      routes, no assets and no UI (a clean 404, not a broken page).
- [ ] Free works fully with Pro absent. Pro fails cleanly (admin notice, no fatal error) with Free
      absent or outdated, whichever plugin was activated first.

### 4.7 Tests and QA assets

- [ ] Every behaviour rule has a PHPUnit test that fails when the rule is broken. For example,
      `AlbumPrivacyRuleTest` and `AlbumMigrationV40Test`. *Gate: 2.4.*
- [ ] Every release-critical feature has an executable journey (`audit/journeys/REQUIRED-COVERS.txt`).
      *Gate: 1.6.*
- [ ] `wp mvs cert` and `wp mvs-pro cert` pass. *Gate: 3.2.*
- [ ] **This catalog** has an entry for every feature, setting, screen, route and command the change
      touched.

### 4.8 Big-site readiness (every list, grid, table and data method)

Run the ten checks on every list the change touches and on the lists next to it:

- pagination
- indexes
- no per-row (N+1) queries
- counts done with `COUNT(*)`
- filter and sort
- mobile and RTL
- dark mode
- accessibility
- empty, error and loading states
- cache invalidation

Also check behaviour when two people act at once, for example an item that is already deleted or
already resolved. Prove it on a site seeded with 2,000 or more media rows and 500 or more users, not
on five rows.
