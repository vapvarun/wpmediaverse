# Document library — resume here

**Last session: 2026-08-09.** Read this, then `plan/document-library-build.md` (the task list with
per-task browser Self-checks) and `plan/document-library.md` (the design + 8 locked decisions).
The **display contract** is the UX artifact: <https://claude.ai/code/artifact/70f57ecc-48e5-477c-8f8a-7ae19a81e521>

---

## State

| | |
|---|---|
| Branch | **`2.4.0`** on BOTH repos, pushed |
| Last released | 2.3.2. **2.3.3 does not exist** — it was renamed to 2.4.0 because Phase 2 changes schema and Production Rule 4 forbids that in a patch |
| Version constants | still `2.3.2` — they bump at release, not now |
| Free tests | **332 green** (321 + 5 from P1.5, + 6 from P2.2's `MigratorLegacyDocumentTest`) |
| Free Migrator | **v27** (was 26). `mvs_db_version` on the reference install is 27 |
| Pro tests | **221: 40 errors / 43 failures — the SAME PRE-EXISTING set** (was 215/40/43; +6 from P2.3's `MigratorFolderSchemaTest`, all passing). Verified originally by stashing everything and re-running against HEAD. Basecamp 10184313297. Do not mistake this for something you broke |
| Pro Migrator | **v11** (was 10). `mvs_pro_db_version` on the reference install is 11 |
| local-CI | green on both |

## Environment (these took a while to work out — don't rediscover them)

```bash
# Tests — the env vars are REQUIRED on macOS ($TMPDIR ≠ /tmp)
WP_TESTS_DIR=/tmp/wordpress-tests-lib WP_CORE_DIR=/tmp/wordpress ./vendor/bin/phpunit

composer ci:no-journeys     # the gate; run before every commit
```

- Site: **http://mediaverse.local** — auto-login with `?autologin=1`, never fill the login form.
- WP-CLI: use the **`mcp-local-wp` `wp_cli` tool**, not a bare `wp` (a bare `wp` hits the wrong DB).
  For anything with quotes, write a PHP file and use `eval-file` — inline `eval` quoting will fight you.
- **Seeded fixtures on the site — TWO now, and the pair is the point.** Always read the options,
  never hard-code an id:
  - `mvs_qa_legacy_doc_id` (**158**) — the pre-1.2.3 catch-all row, quarantined to `legacy_document`
    by v27. **MUST stay visible** in every media surface; if it disappears, the upgrade is deleting
    content from members' libraries.
  - `mvs_qa_seed_doc_id` (**159**) — a real `document`, created after v27. **MUST stay hidden** from
    every media surface.
  Verified landing on opposite sides of the REST feed, Explore and the collection. One fixture alone
  can only prove half of this; the pair is what makes a wrong-side filter visible instead of silent.
  **The original (157) and 18 other QA media rows were deleted on 2026-08-09 at 08:12 UTC**, cause
  not established — full evidence in the journey's "Fixture incident" section. Re-seeded through
  `MediaRepository`. If the option ever points at a missing row, **re-seed before running anything**:
  with no document on the site every absence check passes and proves nothing.
  Side finding worth its own card: `delete_cascade()` writes nothing to `mvs_error_log`, so the
  plugin can erase a member's whole library with no trace in its own log.

## The verification method that works

**Stash-and-compare.** For every relocation: capture the behaviour, `git stash`, re-run against HEAD,
`git stash pop`, re-run. Byte-identical or it is not a relocation. This caught nothing in #1/#2/#4
*because* it was applied — it is the reason those are trustworthy.

Then the browser at desktop **and 390px**, and make it **adversarial** where you can: for #1 I forced
the seeded document into an album through the join table so the page had something to leak (7 join
rows, 6 rendered).

---

## Done

- **P2.1** — catch-all removed. `get_media_type()` returns `''`, never `'document'`, for unknown MIME.
  There were **three** copies of the guard coupling, not two: `handle()`, `replace_file()` and
  `StorageRepairService`. Both ingest paths now call one `reject_unsupported_mime()`.
- **P1.2 #1 AlbumService** — four copies of one join → `album_items()` / `count_album_items()`.
- **P1.2 #2 CollectionService** — 188 → 112 lines, no SQL left. Repository gained `authors_in`,
  `privacy_in`, `mime_like_in`, `tax_terms`, `until`.
- **P1.2 #4 Stats + suggestions** — `user_media_totals()`, `top_author_ids()`,
  `authors_with_term_taxonomy_ids()`.
- **P1.2 #5 (part)** — InstagramLayout relocated; new `privacy => 'explore'` mode. Compete join deleted.
- Dedup: Pro connector layouts → `AbstractConnectorFeedLayout`; six album creators → the trait.
- Manifest deltas written for all of it (targeted, hand-written — **never** generator output).
  Correction 2026-08-09: **`mvs_media_type_for_mime` was missing** — P2.1 shipped without its delta.
  Both it and `mvs_media_feed_allows_documents` are now in `manifest.hooks.json` (223 → 225 unique).
- **#9 / P1.5 — walked in the browser and written.** `audit/journeys/security/07-document-never-in-media-surface.md`
  (markdown in a role subdirectory, not the `16-….json` the plan named — the coverage gate discovers
  journeys by `covers:` tag, so the filename was free). Tag added to `REQUIRED-COVERS.txt`.
  **The walk found a real leak**, see below. Absence half is done; the upload and drive halves are
  explicit failing steps until P3.4 and P9.1.

## The leak P1.5 found — and why nothing else caught it

`GET /mvs/v1/media?media_type=document` **served the document**, and `/me/media` inherited it through
`get_my_items()`'s delegation. Now **400 `mvs_document_route`**, with escape hatch
`mvs_media_feed_allows_documents` (Production Rule 3; verified armed and disarmed).

Three reasons it survived Phase 1 with every gate green:

1. **The route hand-builds its own `WHERE`** and never touches `MediaRepository`, so P1.2's
   repository default did not cover it — and **the planned P1.3 CI ban would not have caught it
   either**, since it greps for `FROM …mvs_media_index`. Whatever P1.3 bans must reach hand-built
   WHERE clauses on REST controllers.
2. **A unit test asserted the leak was correct.** `MediaTypeGroupTest::test_explicit_media_type_param_can_request_documents`
   pinned it as intended behaviour, so the suite defended it. It is now reversed in place, with the
   reasoning, so the reversal is visible where the old promise was.
3. **The code comment argued for it** — *"that is how a document surface asks for documents."*
   Plausible, and wrong: the route applies media privacy, not the grants-first document ladder
   (design §5), and design §5 also locks *public = unlisted, never discoverable*. A comment is not a
   contract; the design is.

`document` stays in the parameter's enum deliberately — dropping it would return a generic
`rest_invalid_param` before the handler runs, and would put the escape hatch out of reach.

## Next, in order — DOCUMENT FUNCTIONALITY FIRST (owner, 2026-08-09)

The remaining Phase-1 work is architectural placement, not leak prevention. All 47 ROUTE sites
already carry positive type predicates, and a real document row was browser-verified to reach no
media surface. The guarantee holds; it is simply not machine-enforced yet. So build the feature.

1. **#9 — the journey.** 🟡 **Absence half done** (walked 2026-08-09, desktop + 390px). Two small
   tails left before it closes, neither blocking: **run it Free-only** (today's walk was combo), and
   **seed one competition entry** so the Pro compete steps stop being vacuous. Keeping it first paid
   for itself — it found the `/media` leak on its first run.
2. **#11 P2.2 — Free Migrator v27.** 🟡 **Applied and verified** on the reference install (populated,
   not fresh; table backed up first): 1 row quarantined, `folder_id` + both indexes present with the
   right column order, `diagnose_cpt_ids` clean, All Media self-check passed at desktop and 390px.
   6 unit tests. **Outstanding: the run against a copy of a REAL CUSTOMER DB** — that is what the
   plan asked for and it needs a dump nobody has handed over yet.
   The quarantine is guarded by option `mvs_legacy_documents_quarantined`, not only the version gate:
   after P3.4 a second pass would re-type every real document and **silently empty every drive on the
   site**. Proven by force-invoking the migration twice against a live document row.
3. **#12 P2.3 — Pro Migrator v11.** ✅ **DONE.** `mvs_pro_folders` + the 5 grant columns and 3 indexes.
   6 unit tests (Pro suite 221, still the documented pre-existing 40/43 — my 6 pass, nothing
   regressed). Pro cycled twice with **zero** duplicate-key errors, `mvs_pro_db_version` = 11.
   **The `token_hash` trap is demonstrated, not just avoided**: a scratch-table counterfactual on the
   live MySQL gives `Duplicate entry '' for key 'token_hash'` with `DEFAULT ''` and succeeds with
   `DEFAULT NULL`. Keep that receipt — the failure only appears on a table that already has rows, so
   every empty-table test passes while the upgrade breaks for exactly the paying customers.
   Buyer check PASS (buyer + owner can view, stranger cannot) after **two malformed attempts**:
   a grant with no `mvs_access_rules` row is inert by design, and `rule_type` `'purchase'` does not
   exist (`role|capability|membership|code`). Both looked like "the migration broke paid access".
4. **Phase 3 — the engine.** DocumentTypes (no default branch), FolderService (depth 12, batched
   subtree writes), PermissionService (2 queries/page), document ingest, delivery, storage resolver,
   Site Health. Then P3.9, the scale fixture, which gates P4/P8/P9.
5. **Phase 9 — the UI**, against the display contract in the artifact. #13–#17.
6. **#18 QA checklist** — only once #13–#17 are complete. Blocked in the graph so it cannot start early.

**Deferred, not dropped** (finish after the feature works): #3 feed move (Option A, decided),
#5 leaderboard, #6, #7 CI ban, #8 mutation test, #10 aggregates.

**Stories are RESOLVED, not open** (owner, 2026-08-09): *"story is never about document."* A story is
a media feature by definition, so Stories is not a document surface and never will be. Its query
stays in Pro as a documented ALLOW — relocating it is architectural tidying with no leak risk, and
moving it into Free's repository would only teach Free what a story is.

The guard in `StoryService::create()` stays and matters: it is what ENFORCES "never a document"
rather than assuming it. `POST /media/{id}/story` previously accepted any media id with no type
check anywhere in the flow, so the rule was true only by luck.

## Things that bit, so they don't bite again

1. **A grep is not evidence.** Four false negatives so far: the `~66 files` figure counted filename
   mentions; `FROM …mvs_media_index` reported **zero** Pro sites (Pro assigns the table to a variable);
   two multi-line call patterns returned nothing.
2. **`audit/derived/media-index-callsites.json` is a starting point, not an inventory.** It MISSED
   `MediaRepository::query()` — the highest-impact site in the phase — and StatsPage's CSV export,
   and over-counted MediaListPage. Verify with `grep -rl mvs_media_index` across both plugins.
3. **`term_id` ≠ `term_taxonomy_id`.** I nearly shipped a collection bug matching one against the
   other; they are equal only by coincidence. Hence `tax_terms` carries taxonomy + term ids together,
   and `authors_with_term_taxonomy_ids()` names its column.
4. **Join placeholders precede WHERE placeholders** in assembled params. Getting it backwards
   misaligns every value after the first join param.
5. **Never seed at a chosen ID.** `$wpdb->insert()` with a fixed `media_id` destroyed four real rows
   once. Always go through the repository so AUTO_INCREMENT assigns it.
6. **Duplication is a bug waiting to happen.** Three shipped bugs this branch came from two copies of
   one behaviour drifting: the `AlbumMarkerLookupTrait` fatal, the rtMedia admin-migration privacy
   leak, and the `replace_file` guard bypass.
7. **A green suite can be defending the bug.** The `/media` document leak had a passing unit test
   asserting it was correct. Tests pin whatever was believed when they were written — when a walk and
   a test disagree, check which one the *design* backs before assuming the test is right.
8. **An absence assertion is worth exactly what its fixture makes non-vacuous.** Half the surfaces in
   the P1.5 list would have passed on an empty fixture while leaking on a real site. Make the check
   hard to pass (public + approved + forced into an album + matching a `privacy=public` collection),
   and **label the steps that are still vacuous** rather than banking them as evidence.

## Open with the owner

- **rtMedia repair pass** (Basecamp 10184326656) — sites already migrated via the admin UI have
  public albums that should be private, and nothing distinguishes that from a genuinely public
  source album. Owners cannot detect it themselves.
- **`StatsService` has no status filter** — trashed items count toward member totals. Preserved
  deliberately; fixing it changes a number members already see.

## Not yet created, on purpose

**No QA or RFT cards.** Owner instruction: QA must not be handed partial code. Nothing
member-visible exists before Phase 9. Task **#18** is blocked in the graph behind #13–#17 so it
cannot start early, and it is to be built from each task's Self-check field.
