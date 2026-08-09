# Document Library — build plan

**Companion to `plan/document-library.md`.** That file is the design: what, why, and the eight locked
decisions. **This file is the execution:** what to write, in what order, how it is verified, and when
it is done.

Nothing here restates the design. Where a task needs a rule, it cites the section
(`design §5.2`) rather than copying it — copies drift, and five drifting documents is the problem
this pair replaced.

**Target release: 2.4.0.** Not 2.3.3. Phases 2+ change schema (Free Migrator v27, Pro v11) and
Production Rule 4 forbids that in a patch. 2.3.2 is the last released version; 2.4.0 is the single
development branch (owner decision, 2026-08-09).

## How to use this

Tasks are `P<phase>.<n>`, executed in order within a phase. Every task carries five fields:

| Field | Meaning |
|---|---|
| **Files** | Created (+) or modified (~). If a task touches a file no other task in the phase touches, it can run in parallel |
| **Do** | The change, specific enough to start without re-reading the design |
| **Test** | The automated check. `unit:` runs in the suite; `live:` is a WP-CLI/DB assertion; `journey:` is an executable journey |
| **Self-check** | **What to open in a browser, and what must be true.** Desktop **and 390px**. Where a task has no rendered surface this says so explicitly and names what stands in for it — an honest "none, and here is why" beats an invented step |
| **Done** | The condition that must hold. **A task is not done until its Test passes and its Self-check has been seen** (CLAUDE.md verify-per-item rule) |

**Checkpoint after every task**: `composer ci:quick`. **Checkpoint after every phase**: `composer ci`
plus the phase's journeys. A phase does not close on a red gate.

**Auto-login for every self-check**: `?autologin=1` for admin, `?autologin=<user>` for a member.
Never fill a login form by hand.

---

## Status at a glance — 2026-08-09

| Phase | Tasks | State |
|---|---|---|
| **Prerequisites** | PRE-1, PRE-2 | ✅ both done |
| **1 — Query discipline** | P1.1 – P1.6 | 🟡 P1.1 ✅, P1.2 🟡 partial, P1.3–P1.6 ⬜ |
| **2 — Schema** | P2.1 – P2.3 | 🟡 P2.1 ✅, P2.2–P2.3 ⬜ |
| **3 — Pro engine** | P3.1 – P3.9 | ⬜ |
| **4 — REST + app contract** | P4.1 – P4.5 | ⬜ |
| **5 — Viewers** | P5.1 – P5.5 | ⬜ |
| **6 — Admin** | P6.1 – P6.3 | ⬜ |
| **7 — Parity verification** | P7.1 – P7.5 | ⬜ builds nothing, proves things |
| **8 — Extraction + search** | P8.1 – P8.4 | ⬜ |
| **9 — Frontend** | P9.1 – P9.7 | ⬜ **first member-visible release** |
| **10 — Interlinking** | P10.1 – P10.3 | ⬜ |
| **11 — Space drives** | P11.1 – P11.4 | ⬜ follow-on, not v1 |

**Nothing member-visible ships before Phase 9.** Phases 1–8 leave the feature reachable by API and
admin only — the half-cooked state Coding Rule 18 forbids.

**Browser verification to date: none.** Everything green so far is unit-level. **P1.5 is where that
changes**, which is one reason it is a release blocker.

---

## Prerequisites

| # | Prerequisite | Status |
|---|---|---|
| **PRE-1** | **WP test library installed; suite runs** | ✅ **DONE** — 321/321 green. Install notes in commit `7f1bf92b`: symlink the socket (spaces), socket-only (no TCP grant), create the DB by hand (MySQL 8.4 GRANT), and pass `WP_TESTS_DIR`/`WP_CORE_DIR` explicitly (macOS `$TMPDIR` ≠ `/tmp`) |
| **PRE-2** | **wppqa baseline** — CI stage 2.4 fails on every push without it | ✅ **DONE** — `audit/runs/2026-08-08-wppqa-baseline-SUMMARY.md`, CI green |
| **PRE-3** | **Scale fixture** | ➡️ **Moved to P3.9** — a document seeder cannot exist before documents do |

**PRE-3 correction.** As written this was wrong. The rule it must obey — **seed through the real
service layer, never raw SQL** — is exactly what makes it impossible to build early, and that rule is
not negotiable: a fixture that bypasses the code under test proves nothing, and raw seeding at a
chosen ID is what destroyed four media rows on the reference install.

---

## Phase 1 — Query discipline

**Ships before any document row can exist** (design §8). This is what replaced the structural
guarantee, so it lands first and it is mutation-tested before it is trusted.

### P1.1 — Audit every direct `mvs_media_index` query ✅ DONE

`audit/derived/media-index-callsites.json` — **98 sites: 47 ROUTE (28 HIGH), 48 ALLOW, 3 DEAD.**

**The single most important finding.** `MediaController.php:572` — the primary `/media` feed count,
read by the web app *and* the mobile app — constrained with `media_type != ''`. That is an
**exclusion**, and it passes `'document'` straight through. Same shape as the query that caused the
trashed-media leak (`68113454`), and it is why design §3.2's **positive inclusion, never exclusion**
rule is not stylistic, and why P1.3 **bans** the query rather than checking its predicate — a
predicate rule would have passed that line.

**Two judgement calls preserved:**

- `AdminAggregatesService` (4 sites) is **ALLOW, not ROUTE** — it *is* the Coding Rule 16 canonical
  aggregate service, so routing it away would break the rule it exists to serve. **Its counts still
  include documents. The fix belongs inside it, as a parameter — see P1.6, which is still open.**
- `CacheService::get_user_stats()` / `::get_moderation_counts()` were **DEAD** — unreachable
  duplicates, one *looser* than the original (no `status` filter). Resolved as `@deprecated`
  delegates rather than deletions: they are public methods on a container-registered service, and
  Production Rule 1 forbids removal in the release that deprecates them. Removal in 4.0.

> ⚠️ **The artifact is a starting point, not an inventory.** It MISSED `MediaRepository::query()` —
> the highest-impact site in the phase, where only one caller passed the old exclusion flag — and
> StatsPage's CSV export. It over-counted MediaListPage by treating single-row primary-key
> operations as query sites. **Verify with `grep -rl mvs_media_index` across both plugins.**
>
> **Three grep failures so far.** (1) The original ~66 figure counted filename mentions, not queries.
> (2) `FROM …mvs_media_index` reported **zero** Pro sites — Pro assigns the table to a variable and
> queries that. (3) Two multi-line call patterns returned nothing. Any audit here must trace
> variable assignments, which is exactly the shape the P1.3 ban must also catch.

### P1.2 — Route the `route` callsites through `MediaRepository` 🟡 PARTIAL

- **Done** — all 47 ROUTE sites carry a positive type predicate (28 HIGH + 19 LOW), all 3 DEAD
  handled, and the default moved into `MediaRepository::query()` so every listing funnelling through
  it inherits `MediaTypes::MEDIA_LIBRARY`. **A document cannot leak into a media surface.**
- **NOT done** — the stated Done condition is *"only `allow`-listed callsites remain outside the
  repository"*. **59 files still hold raw `mvs_media_index` SQL** (43 Free, 16 Pro). The predicate
  was applied in place; the queries were not relocated.
- **Remaining** — move the 47 ROUTE query shapes onto repository methods, each taking a type group
  defaulting to `MediaTypes::MEDIA_LIBRARY` (design §3.2).
- **Test** — `unit:` a test per new repository method asserting the default excludes documents.
- **Self-check** — this is a refactor, so the test is that **nothing changes**. Open Explore, a
  member profile, an album, and wp-admin → All Media, at desktop and 390px, before and after.
  Same items, same order, same counts. A relocation that changes what renders is a bug.
- **Done** — `grep -rlE "(FROM|JOIN)[^;]*mvs_media_index"` outside `MediaRepository` returns only
  allow-listed files. **This gates P1.3.**

### P1.3 — The CI ban, with its allowlist ⬜ BLOCKED BY P1.2

- **Files** — `~ bin/coding-rules-check.sh`, `+ audit/derived/media-index-allowlist.txt`
- **Do** — New rule: `FROM …mvs_media_index` outside `MediaRepository` **fails the build**, with the
  P1.1 `allow` list as the only exceptions. **Not a predicate check** — design §8 records why.
  Must trace variable assignments, per the grep failures above.
- **Test** — `live:` `bash bin/coding-rules-check.sh` exits 0 on a clean tree.
- **Self-check** — none; a CI rule has no rendered surface. **P1.4 is its verification**, which is
  why P1.4 is not optional.
- **Done** — Rule active at local-CI stage 2.1, with an allowlist small enough to mean something.
  An allowlist of 59 files is not a rule.

### P1.4 — Mutation-test the gate ⬜

- **Files** — `+ tests/ci/mutation-media-index.sh`
- **Do** — Add a deliberately document-blind query to a scratch file, run the gate, assert it **fails
  and names the file**, then remove it. Wire into `composer ci` so the gate is re-proven each run.
- **Test** — `live:` the script exits 0 (meaning the gate correctly failed on the mutant).
- **Self-check** — none. This IS the check.
- **Done** — A rule nobody has watched fail is a rule nobody knows works. This is the watching.

### P1.5 — The journey ⬜ **RELEASE BLOCKER** (design §15)

- **Files** — `+ audit/journeys/16-document-never-in-media-surface.json`
- **Do** — Upload a document; assert it appears in the drive and in **none** of: explore grid, media
  grid block, album, collection, lightbox, BP activity, `/media`, `/me/media`, Instagram layout,
  leaderboard, challenges, stories, tournaments.
- **Test** — `journey:` passes on Free-only and Free+Pro.
- **Self-check** — **walk it by hand before automating.** Log in as a member, upload a document, then
  open every surface in that list at desktop and 390px. This is the first browser verification the
  document work gets; do not let the journey script be the first time a human sees it.
- **Done** — The regression net for §8 exists. **Without it the query discipline has no proof.**

### P1.6 — `AdminAggregatesService` counts documents separately ⬜ *(new — from the P1.1 judgement call)*

- **Files** — `~ includes/Services/AdminAggregatesService.php`
- **Do** — Its 4 sites stay (Coding Rule 16 makes it the canonical aggregate owner), but its counts
  currently fold documents into media totals. Add a type-group parameter defaulting to
  `MediaTypes::MEDIA_LIBRARY`, and a document count alongside.
- **Test** — `unit:` the media total excludes documents; the document total counts them.
- **Self-check** — **wp-admin → WPMediaVerse overview**, desktop and 390px: the media and document
  totals must sum to the row count in the index. A dashboard that double-counts is worse than one
  that undercounts, because it looks right.
- **Done** — No site-wide aggregate silently mixes the two libraries. Pairs with **P6.3**.

---

## Phase 2 — Schema

### P2.1 — `MediaTypes` + the catch-all removal ✅ DONE

- `Core\MediaTypes` ships the vocabulary and `in_clause()` (always a positive `IN`, `1 = 0` on empty).
- `get_media_type()` returns `''` for unknown, never `'document'`. The 1.2.3 guard changed from a
  NAME test to an UNKNOWN test **in the same edit**, as the plan required.
- **Three** copies of that coupling existed, not the two anticipated — `handle()`,
  `MediaController::replace_file()` and `StorageRepairService`. Both ingest paths now call one
  public `reject_unsupported_mime()`, so the change could not land half-applied.
- `mvs_media_type_for_mime` is the Production Rule 3 escape hatch; it cannot return a type outside
  `MediaTypes::ALL`.
- **Carried forward:** `RtMedia\Importer` maps unknown rtMedia types to `'document'` by elimination —
  the same catch-all shape, one plugin over. **Fold into P3.1.**

### P2.2 — Free Migrator v27 ⬜

- **Files** — `~ includes/Core/Migrator.php`
- **Do** — Legacy quarantine (`media_type='document'` → `'legacy_document'`), `folder_id`,
  `KEY doc_listing`, `KEY type_file` (design §2). **Not** `search_text` — that is P8, on its own table.
- **Test** — `unit:` quarantine is idempotent and re-running is a no-op. `live:` against a copy of a
  real site database, not a fresh install.
- **Self-check** — **wp-admin → All Media before and after**, desktop and 390px. A pre-1.2.3 PDF must
  still be listed and still open at its permalink. `MediaTypes::MEDIA_LIBRARY` promises exactly
  that, and this migration is the one thing that could break the promise.
- **Done** — v27 applied; `wp mvs diagnose_cpt_ids` still clean.

### P2.3 — Pro Migrator v11 ⬜

- **Files** — `~ wpmediaverse-pro/includes/Core/Migrator.php`
- **Do** — `mvs_pro_folders` (design §2 — drive-scoped unique key, `name(150)`), and the five
  `mvs_access_grants` columns + three indexes. **`token_hash` is `DEFAULT NULL`** — with `DEFAULT ''`
  the `ADD UNIQUE KEY` fails on every site that has ever sold media access.
- **Test** — `unit:` the UNIQUE add succeeds against a table pre-seeded with grant rows; two
  same-named folders at different drive roots both insert.
- **Self-check** — no surface yet, so: **activate and deactivate Pro twice**, confirm no
  duplicate-key error in `debug.log`, and open a purchased item **as the buyer** to confirm existing
  paid access still resolves. A migration that breaks live grants is the failure mode here.
- **Done** — v11 applied on a site with existing grants.

---

## Phase 3 — Pro engine

| Task | Files | Gist |
|---|---|---|
| **P3.1** | `+ Services/DocumentTypes.php` | `resolve()` returns a named type or `null`, **no default branch**. OOXML/ODF zip-marker check **both directions**. Folds in the rtMedia catch-all carried from P2.1 |
| **P3.2** | `+ Documents/FolderService.php` | CRUD, move, depth cap **12**, `path` maintenance, **subtree writes batched above 5,000 rows** (design §4) |
| **P3.3** | `+ Documents/PermissionService.php` | Batched resolution, **2 queries per page**. Grant authority per **D1** |
| **P3.4** | `~ Services/UploadService.php` | Explicit document ingest; declared `doc_type` verified against resolved, **400 on mismatch, never a silent fix**; privacy forced `private` |
| **P3.5** | `+ Documents/DeliveryController.php` | `/download` and `/preview`, headers per design §6 |
| **P3.6** | `+ Documents/StorageResolver.php` | Separate private bucket per **D8**; refuse a bucket equal to the media bucket. **Release blocker** with P3.7 |
| **P3.7** | `~ Core/HealthCheckService.php` | Site Health: document bucket is not public-read. **Release blocker** |

**Self-check for P3.1–P3.7** — no member UI yet, so:
1. **wp-admin → Tools → Site Health** shows the P3.7 check, desktop and 390px.
2. **Network panel** on `/download` and `/preview`: correct `Content-Type` and `Content-Disposition`.
3. **Fetch a stored file's direct URL in the browser — must be 403/404 on both Apache and nginx.**
   That is a release blocker and it is a browser check, not a config review.

### P3.8 — Team-drive correctness *(the release blockers that are code)* ⬜

- **T2** privacy cascade — the folder's own privacy flips **first, synchronously**, then contents
  batch (design §5). Confirmation dialog before a bulk change. **Release blocker.**
- **D3** replace-undo — superseded file kept 30 days under `_mvs_replaced_from`, swept by cron
  **querying by value range, never bare `meta_key`**.
- **D5** rate limiting on redemption, upload, download and tier-2 preview.
- **T1 is Phase 11**, not here — a personal-only v1 has nothing to reassign.
- **Self-check** — tighten a folder holding 3 documents to private; confirm in the browser that the
  folder flips immediately and the contents follow, and that **loosening does not cascade back**.
  Confirmation dialog appears, is keyboard-dismissible, and reads correctly at 390px.

### P3.9 — Scale fixture ⬜ *(was PRE-3)*

- **Files** — `+ wpmediaverse-pro/includes/CLI/` seeder
- **Do** — `wp mvs seed-documents --members=N --docs-per=N --depth=N`, building a known structure.
  **Through the service layer, never raw SQL, never a chosen ID** (standing rule 2).
- **Test** — `live:` 2,000 documents across 12 levels. `SELECT COUNT(*) FROM mvs_pro_folders` is **0**
  on a 1,000-member fixture where nobody created a folder (design §16 — the lazy-root assertion).
- **Self-check** — with the fixture loaded, open the drive at desktop and 390px: listing paginates,
  no horizontal page scroll, query count flat across pages.
- **Done** — **Gates P4, P8 and P9.**

---

## Phase 4 — REST + app contract

| Task | Do | Self-check |
|---|---|---|
| **P4.1** | Folder CRUD routes on `mvs-pro/v1` (design §9) | Network panel: answers with an **Application Password alone** — no cookie, no nonce |
| **P4.2** | Document list/get/update/delete, honest `X-WP-Total` / `X-WP-TotalPages` | Paginate past page 1; the header total must equal what the UI renders |
| **P4.3** | Share/grant routes; grant authority per **D1** | As a non-owner, attempt a grant → 403 |
| **P4.4** | `/app/config` additions + ETags | Re-request with `If-None-Match` → **304** in the network panel |
| **P4.5** | `/me/shared` — documents shared *with* the viewer | Two member sessions side by side; each sees only its own grants |

**Phase gate** — **every action drivable with an Application Password alone** (design §16). Prove it
in the network panel, not by reading the code.

---

## Phase 5 — Viewers

| Task | Do | Self-check |
|---|---|---|
| **P5.1** | Tier 1 — native (images, PDF) | Open each, desktop + 390px |
| **P5.2** | Tier 2 — Office via lazy bundle | **Network panel: the bundle is ABSENT from the main page load** (design §16 — verified in the panel, not the build config) |
| **P5.3** | Tier 3 — text, Markdown, CSV | **Markdown XSS**: an `.md` with `<script>`, `<img onerror>` and a `javascript:` link renders inert. **CSV with 50,000 rows** renders the first 500 with an honest footer and does not hang |
| **P5.4** | Tier 4 — no preview → card + download | **Corrupt a `.docx`; the card renders and download still works.** Preview failure must never block download |
| **P5.5** | `/preview` type gate | `/preview` **refuses every non-PDF raw type** |

All five verified at **390px**. A viewer that only works on desktop is not done.

---

## Phase 6 — Admin

| Task | Do | Self-check |
|---|---|---|
| **P6.1** | Documents admin screen (Rule 18 backend entry point) | Desktop + 390px; **empty, loading and error states all present** (Coding Rule 11) |
| **P6.2** | Row actions + bulk actions | Nonce + capability on every destructive action; confirm a logged-out POST is refused |
| **P6.3** | **Documents card on the Stats page** | Owed since P1.2 — "Total Media" now excludes documents, so their count must appear somewhere. Confirm media + documents sum to the index row count. Pairs with **P1.6** |

---

## Phase 7 — Parity verification *(builds nothing; proves things)*

| Task | Prove | Self-check |
|---|---|---|
| **P7.1** | Reactions / comments / favourites work on a document | Exercise each in the browser |
| **P7.2** | GDPR export + erase include documents | Run both; the file appears in the export and is gone after erase |
| **P7.3** | Quota counts documents against the uploader (**D2**) | Upload a document; the member's quota widget moves |
| **P7.4** | Moderation queue shows documents | A held document appears and can be approved |
| **P7.5** | Permission matrix — role × drive type × grant type | Owner, shared-user, shared-role, link, anonymous, non-member, logged-out. **Seven browser sessions, not a code read** |

---

## Phase 8 — Extraction + search

| Task | Do | Self-check |
|---|---|---|
| **P8.1** | `mvs_document_search` side table (Pro Migrator bump) | Activate/deactivate twice, no duplicate-key error |
| **P8.2** | Async extraction via Action Scheduler, following `StorageRepairService`'s cursor pattern | The queue drains and does not re-enqueue forever |
| **P8.3** | Search endpoint + FULLTEXT query | Search a phrase inside a `.docx`; confirm the hit |
| **P8.4** | **Honest pre-extraction state** | Before extraction has run, the search box is **absent or says "indexing"**. A search that silently returns nothing reads as broken (design §14) |

---

## Phase 9 — Frontend ⭐ **first member-visible release**

| Task | Do | Self-check |
|---|---|---|
| **P9.0** | **`/explore-documents` archive page** — the documents counterpart to `/explore-media`, so the two libraries never share a page (owner, 2026-08-09). New `mvs_page_documents` option + page in `Activator::create_pages()` (slug `explore-documents`, title "Explore Documents"), new `[mvs_documents]` shortcode, new `templates/explore-documents.php`. **Detach `_wp_auto_add_pages_to_menu` around the insert exactly as the existing three pages do** (Coding Rule 17 — activation must never edit the site's menus) | Open `/explore-documents` and `/explore-media` side by side, desktop + 390px: neither lists the other's items. Confirm **Appearance → Menus is unchanged** after activation |
| **P9.1** | Drive view — three virtual roots (My Drive / Shared with me / Recent) | Desktop + 390px |
| **P9.2** | Folder navigation + **breadcrumbs** | **RELEASE BLOCKER:** a member granted `/Contracts` must never see an ancestor folder name in **any** response. Folder names carry client identities and project codenames — this is an information leak, not a display bug. Check the JSON, not just the rendered crumb |
| **P9.3** | Single-document view | Desktop + 390px |
| **P9.4** | Upload + replace overlay | Progress shown; cancel mid-flight leaves **no orphan row** |
| **P9.5** | Share modal | Keyboard reachable, focus trapped, ESC closes |
| **P9.6** | Empty / loading / error states on every async surface | All three, on each surface (Coding Rule 11) |
| **P9.7** | Big-site pass | With the P3.9 fixture: 2,000 documents paginate, no horizontal scroll at 390px, counts via `COUNT(*)` not `count(list_all())`, filter + sort present |

---

### Open question on P9.0 — what does `/explore-documents` list?

The separation is settled; **the audience is not**, and the two readings are materially different
builds:

- **(a) Viewer-scoped archive** — the documents this viewer can see: their own, shared with them,
  plus any deliberately made public. Works on every site from day one.
- **(b) Public archive**, the true mirror of `/explore-media`. But **D8 / P3.4 force uploaded
  documents to `private`**, so on a default install this page renders empty until members
  deliberately publish documents. An empty "Explore Documents" page reads as broken.

**Assumption if unanswered: (a).** It satisfies the stated goal — documents never mixed with media —
without shipping a surface that is empty by construction. If (b) is wanted, the privacy default in
P3.4 has to be revisited with it, because the two decisions contradict each other.

---

## Phase 10 — Interlinking

| Task | Do | Self-check |
|---|---|---|
| **P10.1** | **Fix the `mvs_dashboard_tabs` collision first** (design §10) | Both tabs render; neither displaces the other |
| **P10.2** | Attach a document to an album / activity | It does **not** appear as a media tile |
| **P10.3** | Blocks + shortcodes (design §6) | Insert each block; editor preview and frontend match |

---

## Phase 11 — Space drives *(follow-on, not v1)*

| Task | Do | Self-check |
|---|---|---|
| **P11.1** | Drive resolver for `drive_type='space'` | Space drive lists correctly for a member and 404s for a non-member |
| **P11.2** | **T1 — departing member: purge personal, REASSIGN space/site.** Phase blocker | Seed both drive types from one uploader, delete the user, confirm **the team keeps its files** |
| **P11.3** | BuddyNext bridge (design §7) | — |
| **P11.4** | **D2's per-drive quota question** becomes live | — |

---

## Release blockers — the five that gate v1 (design §15)

| | Blocker | Task | State |
|---|---|---|---|
| **Journey** | A document appears in the drive and in no media surface | **P1.5** | ⬜ |
| **T2** | Tightening a folder cascades; loosening does not | **P3.8** | ⬜ |
| **Storage privacy** | Local deny rules **and** a Site Health check that the bucket is not public-read | **P3.6 + P3.7** | ⬜ |
| **Breadcrumb** | Shared view starts at the highest granted ancestor | **P9.2** | ⬜ |
| **T1** | Departing member reassignment | **P11.2** | ⬜ blocks Phase 11, **not v1** — a personal-only v1 has nothing to reassign |

---

## Standing rules for every task

1. **Verify per item, not per phase.** Anything with a rendered surface is seen in a browser at
   desktop and 390px before its task closes. Batching verification to the end of a phase is how a
   phase closes with three broken screens in it.
2. **Never seed a fixture with a raw `$wpdb->insert()` at a chosen ID.** On a populated table that ID
   is usually taken, the insert fails silently, and cleanup then deletes a row it never created.
   That is how four real media rows were destroyed on the reference install.
3. **A grep is not evidence.** Three false negatives so far — a table name assigned to a variable
   before use, and two multi-line call patterns. Open the file.
4. **The local site is a playground; the code flow is the truth.** A conclusion drawn from what the
   reference database happens to contain is not a conclusion. Every promise this plugin makes to
   50+ live sites lives in the code, not in the fixture.
5. **A refusal is never a success response** (Coding Rule 20). A guard that declines returns an
   error so the UI can say why.
6. **Production Rules are not negotiable.** No removal without deprecation, no rename without an
   alias, no default change without a filter, no schema change in a patch.
7. **Duplication is a bug waiting to happen, not a style issue.** Three shipped bugs in this branch
   came from two copies of one behaviour drifting apart: the `AlbumMarkerLookupTrait` fatal, the
   rtMedia admin-migration privacy leak, and the `replace_file` guard bypass. When a task finds a
   second copy, the task includes collapsing it.
