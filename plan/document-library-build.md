# Document Library — build plan

**Companion to `plan/document-library.md`.** That file is the design: what, why, and the eight locked
decisions. **This file is the execution:** what to write, in what order, how it is verified, and when
it is done.

Nothing here restates the design. Where a task needs a rule, it cites the section
(`design §5.2`) rather than copying it — copies drift, and five drifting documents is the problem
this pair replaced.

## How to use this

Tasks are `P<phase>.<n>`, executed in order within a phase. Every task carries four fields:

| Field | Meaning |
|---|---|
| **Files** | Created (+) or modified (~). If a task touches a file no other task in the phase touches, it can run in parallel |
| **Do** | The change, specific enough to start without re-reading the design |
| **Test** | The automated check. `unit:` runs in the suite; `live:` is a WP-CLI/DB assertion; `journey:` is an executable journey |
| **Done** | The condition that must hold. **A task is not done until its Test passes and, for anything with a rendered surface, it has been seen in a browser at desktop and 390px** (CLAUDE.md verify-per-item rule) |

**Checkpoint after every task**: `composer ci:quick`. **Checkpoint after every phase**: `composer ci`
plus the phase's journeys. A phase does not close on a red gate.

---

## Prerequisites

These block task P1.1. Status as of 2026-08-08.

| # | Prerequisite | Status |
|---|---|---|
| **PRE-1** | **WP test library installed; suite runs** | ✅ **DONE** — 295/295 green. Install notes in commit `7f1bf92b`: symlink the socket (spaces), socket-only (no TCP grant), create the DB by hand (MySQL 8.4 GRANT), and pass `WP_TESTS_DIR`/`WP_CORE_DIR` explicitly (macOS `$TMPDIR` ≠ `/tmp`) |
| **PRE-2** | **wppqa baseline** — CI stage 2.4 fails on every push without it | ⬜ Blocks trusting the gate that Phase 1 depends on |
| **PRE-3** | **Scale fixture** — the design asserts behaviour at 2,000-document drives, 20-level trees, 30k-row subtree writes. The reference install has 75 media rows and 9 albums | ⬜ Every scale claim is unverifiable until this exists |

**PRE-3 shape.** A WP-CLI seeder (`wp mvs seed-documents --members=N --docs-per=N --depth=N`) that
builds a drive with a known structure, so the same fixture backs the scale assertions in P4, P8 and
P9 rather than three ad-hoc ones. It seeds through the real service layer, never raw SQL — a fixture
that bypasses the code under test proves nothing.

---

## Phase 1 — Query discipline

**Ships before any document row can exist** (design §8). This is what replaced the structural
guarantee, so it lands first and it is mutation-tested before it is trusted.

### P1.1 — Audit every direct `mvs_media_index` query *(discovery — sizes the rest of the phase)*

- **Files** — `+ audit/derived/media-index-callsites.json`
- **Do** — Enumerate every `FROM`/`JOIN` against `mvs_media_index` outside `MediaRepository`, in
  **Free and Pro**. For each: file, line, the query's purpose, and a verdict — `route` (move to a
  repository method), `allow` (genuinely needs raw SQL; record why), or `dead` (unreachable).
  ~50 Free + ~16 Pro files by grep, but **grep is not the answer** — several are string references,
  not queries, and at least one is a docblock. Open each.
- **Test** — `live:` the JSON parses and every entry has a verdict.
- **Done** — Every callsite classified. **The `allow` list is the CI allowlist in P1.3**, so a lazy
  verdict here weakens the gate permanently.

> This task exists because the ~66 figure is a grep, and the phase estimate depends on how many are
> `route` rather than `allow`. Do not estimate P1.2 before this lands.

### P1.2 — Route the `route` callsites through `MediaRepository`

- **Files** — `~ includes/Repository/MediaRepository.php`, plus each `route` file from P1.1
- **Do** — Add repository methods for the query shapes found. **Every list/count method takes a type
  group defaulting to `MediaTypes::MEDIA`** (design §3.2). Move each callsite onto them.
- **Test** — `unit:` a test per new repository method asserting the default excludes documents;
  full suite stays green.
- **Done** — Only `allow`-listed callsites remain outside the repository.

### P1.3 — The CI ban, with its allowlist

- **Files** — `~ bin/coding-rules-check.sh`, `+ audit/derived/media-index-allowlist.txt`
- **Do** — New rule: `FROM …mvs_media_index` outside `MediaRepository` **fails the build**, with the
  P1.1 `allow` list as the only exceptions. **Not a predicate check** — design §8 records why, and
  the evidence is `68113454`, whose leaking query already had a `media_type` predicate.
- **Test** — `live:` `bash bin/coding-rules-check.sh` exits 0 on a clean tree.
- **Done** — Rule active in the local-CI pipeline at stage 2.1.

### P1.4 — Mutation-test the gate

- **Files** — `+ tests/ci/mutation-media-index.sh`
- **Do** — Add a deliberately document-blind query to a scratch file, run the gate, assert it **fails
  and names the file**, then remove it. Wire into `composer ci` so the gate is re-proven each run.
- **Test** — `live:` the script exits 0 (meaning: the gate correctly failed on the mutant).
- **Done** — A rule nobody has watched fail is a rule nobody knows works. This is the watching.

### P1.5 — The journey

- **Files** — `+ audit/journeys/16-document-never-in-media-surface.json`
- **Do** — Upload a document; assert it appears in the drive and in **none** of: explore grid,
  media grid block, album, collection, lightbox, BP activity, `/media` list, `/me/media`.
- **Test** — `journey:` passes on Free-only and Free+Pro.
- **Done** — The regression net for §8 exists. **Without it the query discipline has no proof.**

---

## Phase 2 — Schema

### P2.1 — `MediaTypes` + the catch-all removal

- **Files** — `+ includes/Core/MediaTypes.php`, `~ includes/Services/UploadService.php`
- **Do** — The `MEDIA` / `DOCUMENTS` / `ALL` constants (design §3.2). Change
  `get_media_type()`'s fallback from `'document'` to `''`, and the 1.2.3 guard from a name test to an
  unknown test — **both in the same edit**, because the guard depends on the fallback. Ship
  `mvs_media_type_for_mime` as the Production Rule 3 escape hatch.
- **Test** — `unit:` unknown MIME → `''` and is rejected; every media MIME still resolves;
  the filter can override.
- **Done** — No code path produces `'document'` by elimination.

### P2.2 — Free Migrator v27

- **Files** — `~ includes/Core/Migrator.php`
- **Do** — Legacy quarantine, `folder_id`, `KEY doc_listing`, `KEY type_file` (design §2). **Not**
  `search_text` — that is P8, on its own table.
- **Test** — `unit:` a migration test asserting quarantine is idempotent and re-running is a no-op.
- **Done** — v27 applied; `wp mvs diagnose_cpt_ids` still clean.

### P2.3 — Pro Migrator v11

- **Files** — `~ wpmediaverse-pro/includes/Core/Migrator.php`
- **Do** — `mvs_pro_folders` (design §2, note the drive-scoped unique key and `name(150)`), and the
  five `mvs_access_grants` columns + three indexes. **`token_hash` is `DEFAULT NULL`** — with
  `DEFAULT ''` the `ADD UNIQUE KEY` fails on every site that has ever sold media access.
- **Test** — `unit:` the UNIQUE add succeeds against a table pre-seeded with grant rows; two
  same-named folders at different drive roots both insert.
- **Done** — v11 applied on a site with existing grants.

---

## Phase 3 — Pro engine

Sized after P1.1 lands. Task shape, in dependency order:

| Task | Files | Gist |
|---|---|---|
| **P3.1** | `+ Services/DocumentTypes.php` | `resolve()` returns a named type or `null`, no default branch. OOXML/ODF zip-marker check both directions |
| **P3.2** | `+ Documents/FolderService.php` | CRUD, move, depth cap 12, `path` maintenance, **subtree writes batched above 5,000 rows** (design §4) |
| **P3.3** | `+ Documents/PermissionService.php` | Batched resolution, 2 queries/page. Grant authority per **D1** |
| **P3.4** | `~ Services/UploadService.php` | Explicit document ingest; declared `doc_type` verified against resolved, 400 on mismatch; privacy forced `private` |
| **P3.5** | `+ Documents/DeliveryController.php` | `/download` and `/preview`, headers per design §6 |
| **P3.6** | `+ Documents/StorageResolver.php` | Separate private bucket per **D8**; refuse a bucket equal to the media bucket |
| **P3.7** | `~ Core/HealthCheckService.php` | Site Health: document bucket is not public-read |

### P3.8 — Team-drive correctness *(the release blockers that are code)*

- **T2** privacy cascade — folder's own privacy flips **first, synchronously**, then contents batch
  (design §5). Confirmation dialog before a bulk change.
- **D3** replace-undo — superseded file kept 30 days under `_mvs_replaced_from`, swept by cron
  **querying by value range, never bare `meta_key`**.
- **D5** rate limiting on redemption, upload, download and tier-2 preview.
- **T1 is Phase 11**, not here — a personal-only v1 has nothing to reassign.

---

## Phases 4–11

Detailed as each approaches. Planning six phases ahead of implementation produces documents that go
stale before they are read — the failure this pair of files exists to correct. What is fixed now:
the **order** (design §14), the **release blockers** (design §15), and the **v1 cut** — personal
drives ship first, Space drives follow.

| Phase | Gate before it starts |
|---|---|
| 4 REST + app contract | P3 green, Application-Password-only proven |
| 5 Viewers | P3.5 delivery live |
| 6 Admin | — |
| 7 Parity verification | **Proves** social/GDPR/quota/moderation already work; builds nothing |
| 8 Extraction + search | `mvs_document_search` side table; async via Action Scheduler |
| 9 Frontend | Earliest Rule-18-compliant release |
| 10 Interlinking | Fix the `mvs_dashboard_tabs` collision first (design §10) |
| 11 Space drives | **T1 and D2's per-drive question become blockers here** |

---

## Standing rules for every task

1. **Verify per item, not per phase.** Anything with a rendered surface is seen in a browser at
   desktop and 390px before its task closes.
2. **Never seed a fixture with a raw `$wpdb->insert()` at a chosen ID.** That is what destroyed four
   media rows on the reference install: the ID was already occupied, the insert silently failed, and
   the cleanup deleted rows it had not created. Seed through the service layer.
3. **A grep is not evidence.** Three times this session a single grep gave a false negative on code
   that was plainly present. Confirm by reading the file.
4. **Report the tier that was run.** Per-fix: pre-commit hook, the tests covering the area, browser
   check. Per-release: the full battery. Say which ran.
5. **When a test fails, first ask whether the test is wrong.** Two of the first seven failures here
   were bad tests, and one of those failures *was the new guard working correctly*.
6. **If a task needs a decision the design does not answer, stop and ask** — framed as what a site
   owner and a member expect, not what is convenient to build.
