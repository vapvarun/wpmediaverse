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
| Free tests | **321 green** |
| Pro tests | **215: 40 errors / 43 failures — PRE-EXISTING**, verified by stashing everything and re-running against HEAD. Basecamp 10184313297. Do not mistake this for something you broke |
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
- **Seeded fixture on the site:** one row `QA Seed Document (delete me)`, `media_id=157`, id also in
  option `mvs_qa_seed_doc_id`. It is the ONLY document on the site, so it is what makes every
  exclusion check non-vacuous. **Keep it until P1.5 lands.**

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

## Next, in order — DOCUMENT FUNCTIONALITY FIRST (owner, 2026-08-09)

The remaining Phase-1 work is architectural placement, not leak prevention. All 47 ROUTE sites
already carry positive type predicates, and a real document row was browser-verified to reach no
media surface. The guarantee holds; it is simply not machine-enforced yet. So build the feature.

1. **#9 — the journey.** Kept ahead of the queue despite the reprioritisation: it is the regression
   net every later phase is checked against, and its value only falls the more that gets built on
   top of it unproven. Release blocker. Unblocked now.
2. **#11 P2.2 — Free Migrator v27.** legacy quarantine, `folder_id`, `KEY doc_listing`,
   `KEY type_file`. Run against a COPY OF A REAL SITE DB, not a fresh install. Idempotent.
3. **#12 P2.3 — Pro Migrator v11.** `mvs_pro_folders` + 5 grant columns. **`token_hash` DEFAULT
   NULL** or the UNIQUE add fails on every site that has ever sold media access.
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
