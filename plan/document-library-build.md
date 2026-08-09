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
| **1 — Query discipline** | P1.1 – P1.6 | 🟡 P1.1 ✅, P1.2 🟡 partial, P1.5 🟡 walked + written, P1.3/P1.4/P1.6 ⬜ |
| **2 — Schema** | P2.1 – P2.3 | 🟢 P2.1 ✅, P2.2 🟡 applied + verified (customer-DB run outstanding), P2.3 ✅ |
| **3 — Pro engine** | P3.1 – P3.9 | 🟡 **all 3 release blockers done** — P3.1 ✅ P3.2 ✅ P3.3 ✅ P3.6 ✅ P3.7 ✅ P3.8 ✅; P3.4 ingest, P3.5 delivery, P3.9 fixture ⬜ |
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

**Browser verification: P1.5 walked 2026-08-09** — desktop and 390px, Free+Pro+BuddyPress active,
against a seeded document made deliberately hard to exclude (public, published, approved, forced into
an album, matching a `privacy=public` smart collection). It found a real leak on `/media` and
`/me/media` that every unit test in the phase was green through. Everything before that date was
unit-level only.

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

### P1.5 — The journey 🟡 **RELEASE BLOCKER** (design §15) — walked, written, two halves deferred

- **Files** — `+ audit/journeys/security/07-document-never-in-media-surface.md` (**not** `16-….json`
  — journeys are markdown in role subdirectories, and the coverage gate discovers them by `covers:`
  tag, not by filename), `~ audit/journeys/REQUIRED-COVERS.txt`,
  `+ tests/unit/MediaFeedDocumentRefusalTest.php`, `~ tests/unit/MediaTypeGroupTest.php`,
  `~ includes/REST/Controller/MediaController.php`.
- **Done** — the hand-walk (2026-08-09, desktop + 390px, admin session, Free+Pro+BP active) and the
  journey written from it. **This is the first browser verification the document work has had.**
  - Explore, album, collection, My Media, admin All Media (default), BP activity: **no leak**.
  - **Album and collection are the load-bearing results.** The album was made adversarial — the seed
    document forced in through the join table, 7 join rows, **6 tiles and "6 items"**, so the filter
    holds in the count as well as the loop. The fixture's collection 15 matches on `privacy=public`
    and the document *is* public, so only the type predicate excludes it: 68 items, no document.
  - Stories verified by probe, both directions: document refused, a real image still accepted.
- **The leak the walk found** — `GET /mvs/v1/media?media_type=document` **returned the document**,
  and `/me/media` inherited it via `get_my_items()`'s delegation. Now **400 `mvs_document_route`**,
  with `mvs_media_feed_allows_documents` as the Production Rule 3 escape hatch (verified in both
  directions). See the reversal note below — this is why the phase's own tests could be green while
  the promise was false.
- **Deferred, and visible in the journey rather than dropped** — steps 14 (upload a real document,
  **P3.4**) and 15 (it appears in the drive, **P9.1**) fail by design until those land. Neither can be
  written today: every ingest path calls `reject_unsupported_mime()`, so a document cannot enter
  through the front door, and there is no drive to look in. The journey therefore **seeds** through
  `MediaRepository` (never raw SQL, never a chosen id) rather than uploading.
- **Honest limits recorded in the journey** — the BP-activity and Pro-compete steps are **vacuous
  today**: activity rows are written at ingest and a seeded row never had one, and the fixture has no
  competitions. Each is labelled load-bearing or vacuous so nobody reads a pass as evidence.
- **Test** — `unit:` 5 new cases (326 green, was 321). `journey:` still to be executed by an agent
  against a Free-only install; today's walk was combo.
- **Remaining before this closes** — run the journey Free-only, and seed one competition entry so the
  Pro compete steps stop being vacuous.

#### The media-route reversal (owner decision, 2026-08-09)

`MediaController::get_items()` carried a comment saying an explicit `?media_type=` *"is how a
document surface asks for documents"*. That was wrong on two counts the comment could not see:

1. The route applies **media** privacy (public / members / author). Document access is grants-first
   through the folder ancestor chain (design §5), so once `PermissionService` (P3.3) exists this
   route answers with the wrong permission model — an ACL bypass by construction, not a stale filter.
2. Design §5 locks *"on a document, `public` means unlisted — reachable by URL, never
   discoverable."* A feed that enumerates public documents to anonymous callers breaks exactly that,
   and this is the route the mobile app reads.

`document` **stays in the parameter's enum on purpose**: removing it would make WP answer with a
generic `rest_invalid_param` before the handler runs — telling a client nothing about where documents
actually live — and would put the escape hatch out of reach, since the value would be rejected
upstream of the filter.

**Note for P1.3/P1.4**: this route hand-builds its own `WHERE` and never touches `MediaRepository`,
so the planned CI ban would not have caught it, and P1.2's repository default did not cover it. The
predicate was right and the promise was still false. Whatever P1.3 bans, it must reach hand-built
WHERE clauses on the REST controllers, not only `FROM …mvs_media_index` string matches.

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

### P2.2 — Free Migrator v27 🟡 APPLIED + VERIFIED; real-customer-DB pass outstanding

- **Files** — `~ includes/Core/Migrator.php`, `+ tests/unit/MigratorLegacyDocumentTest.php`
- **Done** — `CURRENT_VERSION` 26 → 27. Quarantine (`document` → `legacy_document`) runs **first**,
  then `folder_id`, `KEY doc_listing (media_type, folder_id, status, created_at)`,
  `KEY type_file (media_type, file_type)`. No `search_text` — that is P8.1's side table, deliberately
  off the hottest table in the product.
- **The quarantine is guarded by an option, not just the version gate.** `mvs_legacy_documents_quarantined`
  records `{rows, at}` and short-circuits a second pass. The version gate already runs each migration
  once, but the failure mode if that ever slips is not cosmetic: **after P3.4, `media_type='document'`
  means a real member document, so a second quarantine pass would re-type every one of them and
  silently empty every drive on the site.** The test asserts that property directly, and it was also
  proven live by force-invoking `migrate_to_27()` a second time against a real document row.
- **Index-guard duplication collapsed** — v25 and v27 both needed the same `SHOW INDEX` guard, so it
  is now one `add_index_if_missing()` rather than a second copy of a guarded ALTER.
- **Test** — `unit:` 6 cases (332 green, was 326): retypes catch-all only, real media untouched,
  quarantined rows stay in `MEDIA_LIBRARY` and out of `DOCUMENTS`, second run never touches a real
  document, the run records itself, schema added idempotently with `doc_listing`'s column order
  asserted left-to-right.
- **`live:` run — done, on a populated DB, but NOT a customer database.** Applied to
  mediaverse.local (76-row index with real albums, collections, competitions and taxonomy rows —
  not a fresh install). Table backed up first. Verified after: 1 row quarantined, `folder_id` present
  defaulting to 0, both indexes present with the correct column order, `wp mvs diagnose_cpt_ids`
  reports **"No collisions on this site."**
  ⚠️ **Still outstanding: a run against a copy of a real customer database**, which is what the plan
  asked for and what would exercise volume, odd legacy rows and hosts without online DDL.
- **Self-check — passed, desktop and 390px.** wp-admin → All Media lists the quarantined PDF
  (58 items) and not the real document; the PDF still opens at its permalink (HTTP 200); no
  horizontal scroll at 390px.
- **The fixture is now the realistic post-upgrade shape** — `mvs_qa_legacy_doc_id` (quarantined, must
  stay visible) *and* `mvs_qa_seed_doc_id` (a document created after the migration, must stay
  hidden). Verified they land on **opposite sides** of the REST feed, Explore and the collection.
  That pairing is the only configuration where a surface filtering on the wrong side of the
  legacy/document line becomes visible instead of silent.

### P2.3 — Pro Migrator v11 ✅ DONE

- **Files** — `~ wpmediaverse-pro/includes/Core/Migrator.php`,
  `+ wpmediaverse-pro/tests/unit/MigratorFolderSchemaTest.php`
- **Done** — `CURRENT_VERSION` 10 → 11. `mvs_pro_folders` with the drive-scoped
  `UNIQUE KEY name_in_parent (drive_type, drive_id, parent_id, name(150))` plus `drive`, `parent` and
  `subtree` keys; the five `mvs_access_grants` columns and three indexes.
- **The `token_hash` trap is closed AND demonstrated, not just avoided.** A scratch-table
  counterfactual proved both branches on the live MySQL:
  - `DEFAULT ''` → **`Duplicate entry '' for key 'token_hash'`**, migration fails.
  - `DEFAULT NULL` → succeeds. MySQL exempts NULL from UNIQUE.

  It is worth keeping that receipt: the failure only appears on a table that already has rows, so
  every empty-table test in the world passes while the upgrade breaks on exactly the customers who
  pay for the feature.
- **Grant extension is defensive about ownership** — `mvs_access_grants` is Free's table, so
  `extend_access_grants()` returns early if it is absent rather than creating it. Pro creating a
  table Free owns is the v2 messaging mistake that emitted a duplicate-key warning on every
  activation; that is not repeated here.
- **Test** — `unit:` 6 cases. The trap test **pre-seeds grant rows before migrating**, because
  against an empty table it would pass either way and prove nothing. Also: existing grants keep
  today's semantics with no backfill (`media`/`user`/`view`), same folder name at two different drive
  roots both insert, a duplicate name in the same parent is rejected, indexes present and re-running
  is safe, and a new folder defaults to **private** (a safety property, not a preference — a
  public default would expose contents before the owner chose anything).
  Pro suite: **221 tests, 40 errors / 43 failures — unchanged from the documented pre-existing
  215/40/43 baseline**, so all 6 new cases pass and nothing regressed.
- **`live:` — applied, then the grant half re-tested properly.** The migration first ran against an
  EMPTY `mvs_access_grants`, which does not exercise the trap at all; the grant half was therefore
  rolled back, real grants seeded, and re-run — UNIQUE key added cleanly, all rows preserved.
- **Self-check — passed.** Pro deactivated + reactivated **twice**: `mvs_pro_db_version` = 11, and
  **zero** non-noise entries in `debug.log` across both cycles (no duplicate-key error).
- **Buyer check — PASS, after two malformed attempts worth recording.**
  1. Grants seeded with no row in `mvs_access_rules` — grants are only consulted for media that HAS a
     rule, so they were inert **by design**. *A grant without a rule is not a purchase.*
  2. `rule_type` `'purchase'`, which does not exist — `RULE_TYPES` is `role|capability|membership|code`
     and `add_rule()` returns `false` for anything else, so again no rule.

  With a real `code` rule + grant: buyer `can_view` YES, owner YES, **stranger NO**. Paid access
  resolves unchanged under the v11 schema. Both false alarms are recorded because each *looked* like
  "the migration broke paid access" and neither was.
- **Site left as found** — fixture grants and rules removed, media 64 restored to `public`.

---

## Phase 3 — Pro engine

| Task | Files | Gist |
|---|---|---|
| **P3.1 ✅** | `+ wpmediaverse/includes/Core/DocumentTypes.php` | **DONE.** See below |
| **P3.2 ✅** | `+ wpmediaverse-pro/includes/Documents/FolderService.php` | **DONE.** See below |
| **P3.3** | `+ Documents/PermissionService.php` | Batched resolution, **2 queries per page**. Grant authority per **D1** |
| **P3.4** | `~ Services/UploadService.php` | Explicit document ingest; declared `doc_type` verified against resolved, **400 on mismatch, never a silent fix**; privacy forced `private` |
| **P3.5** | `+ Documents/DeliveryController.php` | `/download` and `/preview`, headers per design §6 |
| **P3.6** | `+ Documents/StorageResolver.php` | Separate private bucket per **D8**; refuse a bucket equal to the media bucket. **Release blocker** with P3.7 |
| **P3.7** | `~ Core/HealthCheckService.php` | Site Health: document bucket is not public-read. **Release blocker** |

### P3.1 — `DocumentTypes` ✅ DONE

- **Placed in FREE, not Pro**, despite the document library being a Pro feature. The ingest path is
  Free's `UploadService` and **Free must never depend on Pro** (Coding Rule 10 runs one way), so the
  vocabulary sits beside `Core\MediaTypes` for the same reason that does. Pro owns the engine and
  calls in freely. The build plan's `Services/DocumentTypes.php` was ambiguous about which plugin;
  this is the resolution and the reason.
- Both sniffing traps handled **in both directions**: a zip is admitted only when the extension names
  a container format AND the archive carries that format's marker (extension-only admits a renamed
  `.zip`; marker-only does not know what to look for). ODF verifies the **contents** of the `mimetype`
  entry, so an `.odt` declaring itself a spreadsheet is refused. `.md`/`.csv` are separated by
  extension, but only within the text family, so a binary extension cannot let a text file claim to
  be something else. A MIME/extension disagreement returns `null` rather than picking a side.
- **rtMedia catch-all folded in** (carried from P2.1). Its `document`/`other` buckets mapped to
  `media_type='document'` by elimination — worse there than in `UploadService`, because after this
  release `document` means "a real document in a drive", so an rtMedia `.psd` would have imported
  straight into one. The file now decides; what `DocumentTypes` declines becomes `legacy_document`,
  so imports stay whole without claiming they are documents.
- **Test** — 15 cases, 118 assertions, weighted to the negative property. A suite that only checked
  the happy path would pass against a class that ended `return 'pdf';`.

### P3.2 — `FolderService` ✅ DONE

- CRUD, rename, move, trash, listing, `count_children()`, breadcrumbs. Depth cap **12** with the
  filter's docblock explaining the cost of raising it (`KEY subtree` indexes 150 bytes of `path`;
  a depth-20 path runs ~180 chars, so deep trees silently stop using the index).
- **`MAX_NAME_LENGTH` equals the UNIQUE prefix (150) deliberately** — longer names sharing a 150-char
  prefix would collide in the index and tell a member a name is taken when it is not. Names are
  trimmed, whitespace-collapsed and NFC-normalized so two visually identical names cannot coexist.
- **The invariants that corrupt a tree silently, each tested**: move-into-self, **move into own
  descendant** (the cycle that detaches a subtree and makes every folder in it unreachable),
  cross-drive nesting on create *and* move (without it a member nests into another drive and inherits
  its grants), depth measured for the **whole subtree** on a move (checking only the moved folder lets
  its children land past the cap), and trash cascading so no child surfaces at a root it does not
  belong to.
- Paths are built from **ids, not names**, so a rename touches one row at any subtree size and
  ancestors parse out with zero queries. Breadcrumbs re-order in PHP — MySQL returns `IN ()` in
  whatever order it likes, and a breadcrumb in the wrong order is worse than none.
- Subtree rewrites above **5,000 rows** go async with the folder left `status='moving'`.
- **Duplicate names are rejected by the DATABASE** (check-then-insert is a race every double-click
  wins) — and the wpdb error is **suppressed** around the insert and the rename, because that
  rejection is an expected answer, not a fault. Found while writing the tests: without it, a member
  typing an existing name on a site with `WP_DEBUG_DISPLAY` on gets a raw SQL dump in the page.
- **Test** — 23 cases. Pro suite 244, still the documented pre-existing 40/43.

### P3.3 — `PermissionService` ✅ DONE

- The ladder in **two queries per page**, asserted over 25 documents, with every lookup after the
  prefetch costing zero. Writing that test found the viewer's role lookup as a third query; it is
  per-request rather than per-row, and a listing always renders for the **current** viewer (already
  loaded by WordPress), so it now costs nothing in the case that actually happens.
- A document grant is checked **before** any folder grant, so it survives revocation of the folder
  share above it. Nearest folder ancestor wins over a stronger grant further up.
- **D1 enforced**: `edit` does not confer sharing. Space drives default **closed** — an unanswered
  filter denies rather than opening sharing to every member of a space.
- **The breadcrumb blocker was folded in here** (owner decision) rather than left to P9.2.
  `visible_breadcrumbs()` starts at the highest granted ancestor and collapses everything above into
  one un-named crumb. It lives beside the resolution because **by Phase 9 the raw chain is already
  flowing through the Phase 4 REST layer** — the leak would ship through the API long before a
  template rendered it. Fails closed: arriving by privacy rather than grant shows no ancestor names,
  and a grant on the folder you are standing in still hides its parents.
- **Test** — 22 cases.

### P3.6 + P3.7 — storage privacy ✅ DONE — **and the browser Self-check FAILED first**

- **Step 3 of the Self-check below is what earned this task.** Fetching a stored document over HTTP
  on the reference install returned **200 with the file's contents**, while `guard_status()` reported
  `protected: true`. The host is nginx, and **nginx ignores `.htaccess` entirely** — exactly what
  design §6 warned about. A file-presence check calls that healthy, so the guards were never the
  thing worth asserting.
- **So the check now asks the server.** `probe_public_access()` writes a canary, requests it over
  HTTP, and reports what came back. A directory that answers 200 is public whatever is written in it.
  A blocked loopback reports **unchecked**, not protected — claiming protection nobody verified is
  the failure this replaces, so Site Health says "recommended", never "good".
- Site Health goes **critical** with the exact nginx `location` block to paste. Verified end to end:
  200/critical → apply the rule → **403/good**, with media and REST unaffected.
- **D8** enforced at save time (a document bucket equal to the media bucket is refused,
  case-insensitively) *and* re-checked independently by Site Health, because a value can reach the
  option through WP-CLI, a migration or a database edit without passing the form.
- Guards are written into the segment directory **and its parent** — a browsable parent lists the
  segment, handing out the one secret the segment exists to keep. Both run on **every load**, since
  guards get removed by migrations and sync tools long after activation.
- **390px Self-check also failed and is fixed**: the nginx snippet is a `<pre>`, `<pre>` does not
  wrap, and its longest line pushed the whole Site Health page to 493px against a 390px viewport. It
  now scrolls inside its own box via a class in Pro's admin stylesheet (Coding Rule 19, not inline),
  enqueued on `site-health.php` — which Pro's normal screen guard skips because it is a core screen.
- **Test** — 21 cases.

### P3.8 — T2 privacy cascade ✅ DONE

- Tightening cascades to documents and subfolders; **loosening does not**. The folder's own privacy
  flips **first and synchronously**, so the window during a large sweep fails **closed**. A test
  asserts the ordering by reading the folder's stored privacy at the moment the document UPDATE runs.
- The cascade cannot escape its subtree and never touches media — tested with a photo deliberately
  given the same `folder_id`.
- `count_privacy_cascade()` backs the confirmation copy and is asserted to equal what actually moves.
  A dialog promising 47 and moving 51 is worse than no dialog. **The dialog itself is Phase 9** — the
  number it needs exists now.
- Above 5,000 documents it goes async and re-enqueues until drained. **Both** Action Scheduler hooks
  are registered on every load, not only when a folder changes: AS runs them in a later request, and
  an unregistered hook leaves documents public inside a private folder — the exact T2 failure.
- **Found while testing, and it would have been a silent feature-wide failure:** `folder_id` was
  never added to `MediaRepository::$index_columns`. Migrator v27 added the *column*, but the
  repository did not know it was one, so `set( $id, 'folder_id', … )` wrote to `mvs_media_meta`. The
  column would have stayed 0 for every document ever uploaded, `KEY doc_listing` would have matched
  nothing, and every drive listing and cascade would have found no documents — with no error
  anywhere. Five cascade tests failed on it.
- **Self-check — run live** on a real folder with three real documents: folder and all three flip to
  private together; loosening the folder back to public leaves all three private; a stranger still
  cannot view them; fixture cleaned up. **The browser half needs the folder UI and belongs to Phase
  9** — recorded rather than invented.
- **Test** — 11 cases.

**Self-check for P3.1–P3.7** — no member UI yet, so:
1. **wp-admin → Tools → Site Health** shows the P3.7 check, desktop and 390px. ✅ **done** — appears
   in the critical section, renders correctly at both viewports after the `<pre>` fix.
2. **Network panel** on `/download` and `/preview`: correct `Content-Type` and `Content-Disposition`.
   ⬜ **belongs to P3.5**, which is not built yet.
3. **Fetch a stored file's direct URL in the browser — must be 403/404 on both Apache and nginx.**
   That is a release blocker and it is a browser check, not a config review.
   ✅ **done, and it FAILED on the first run** — see P3.6/P3.7 above. Now 403 on nginx.

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
| **P9.0** | **The documents page + `/documents/` route** — so documents are reachable at their own stable URL and never share a page with media (owner, 2026-08-09). New `mvs_page_documents` option + page in `Activator::create_pages()` and a `[mvs_documents]` shortcode rendering the P9.1 drive. **Detach `_wp_auto_add_pages_to_menu` around the insert exactly as the existing three pages do** (Coding Rule 17 — activation must never edit the site's menus). **Not a public archive** — see the resolution below | Open the documents page and `/explore-media` side by side, desktop + 390px: neither lists the other's items. Confirm **Appearance → Menus is unchanged** after activation |
| **P9.1** | Drive view — three virtual roots (My Drive / Shared with me / Recent) | Desktop + 390px |
| **P9.2** | Folder navigation + **breadcrumbs** | **RELEASE BLOCKER:** a member granted `/Contracts` must never see an ancestor folder name in **any** response. Folder names carry client identities and project codenames — this is an information leak, not a display bug. Check the JSON, not just the rendered crumb |
| **P9.3** | Single-document view | Desktop + 390px |
| **P9.4** | Upload + replace overlay | Progress shown; cancel mid-flight leaves **no orphan row** |
| **P9.5** | Share modal | Keyboard reachable, focus trapped, ESC closes |
| **P9.6** | Empty / loading / error states on every async surface | All three, on each surface (Coding Rule 11) |
| **P9.7** | Big-site pass | With the P3.9 fixture: 2,000 documents paginate, no horizontal scroll at 390px, counts via `COUNT(*)` not `count(list_all())`, filter + sort present |

---

### P9.0 resolved against the locked UX — it is `/documents/`, and it is not a feed

The separation the owner asked for (2026-08-09) is already the design. The
[UX artifact](https://claude.ai/code/artifact/70f57ecc-48e5-477c-8f8a-7ae19a81e521) locks two rules
that decide the shape, and a literal mirror of `/explore-media` would break both:

> **"On a document, public means unlisted — reachable by URL, never in a feed."**
>
> **"Files are rows, not tiles — a grid of identical PDF icons carries no information."**

So there is no public documents *feed* to build. The documents counterpart to `/explore-media` is the
**drive**, viewer-scoped, at `/documents/` with the three roots in P9.1 — My Drive, Shared with me,
Space drives. That satisfies the requirement in full (documents are never listed beside media, and
they have a page of their own) without shipping a surface that is empty by construction on a default
install, since **D8 / P3.4 force uploaded documents to `private`**.

**Therefore P9.0 is a page + route task, not a new archive:** register the `/documents/` path and its
page so the drive is reachable at a stable URL, and keep `explore-documents` as an alias only if the
owner wants that wording in the menu. Everything it lists comes from P9.1.

**The UX artifact is the display contract.** Rows not tiles; folders sort above files; direct-child
counts via one `GROUP BY` per page, never recursive; icon chips per type; breadcrumb truncated at the
grant point; single view reuses `media-single.php` with only the preview panel differing; the share
modal never says "Public". Build P9.x against it, not against this file's summaries of it.

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
| **Journey** | A document appears in the drive and in no media surface | **P1.5** | 🟡 absence half done + walked; presence half deferred to P3.4/P9.1 |
| **T2** | Tightening a folder cascades; loosening does not | **P3.8** | ✅ verified live |
| **Storage privacy** | Local deny rules **and** a Site Health check that the bucket is not public-read | **P3.6 + P3.7** | ✅ browser-verified 403 on nginx after the first run FAILED at 200 |
| **Breadcrumb** | Shared view starts at the highest granted ancestor | **P3.3** (moved from P9.2) | ✅ enforced in the permission layer so it cannot leak through Phase 4's API |
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
