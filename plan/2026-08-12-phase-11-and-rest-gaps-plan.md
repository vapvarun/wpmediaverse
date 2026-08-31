# Phase 11 (Space drives) + the two REST gaps — implementation plan

**Written 2026-08-12.** Covers the 10 cards left in the Bugs column after the 2026-08-11 bug sweep
closed 12. None of the 10 is a defect — they are planned work items filed as cards. This plan says
what to build, in what order, and what each one is actually blocked on.

Everything below was **re-verified against the tree on 2026-08-12**, not read from the plan. Where a
card's claim and the code disagree, the code wins and the difference is stated.

Source of truth for the feature remains `plan/document-library.md`. This file is the build order and
the findings that change how the build should be done; it does not restate the design.

---

## 0. The three findings that change the shape of this work

Read these before picking up any card. Each one alters a PR that would otherwise look routine.

### 0.1 `doc_listing` does not survive drive-keying (affects PR2 — G1)

`KEY doc_listing` is `(media_type, folder_id, status, created_at)`, and §12 item 2 claims "the index
**is** the drive query verbatim". That is true today because the drive query is
`media_type = document AND folder_id = %d AND status = %s ORDER BY created_at`, with
`post_author = %d` bolted on for the root case only.

Once root listing is keyed on the drive (G1), the query becomes:

```sql
WHERE media_type = 'document' AND drive_type = %s AND drive_id = %d
  AND folder_id = %d AND status = %s
ORDER BY created_at
```

`doc_listing` no longer matches left-to-right, and the two new columns land **after** the prefix, so
they cannot be used. **PR2 must add a composite index in the same migration as the columns**, or the
claim in §12 quietly stops being true and the regression only shows up on a large Space:

```
KEY drive_listing (media_type, drive_type, drive_id, folder_id, status, created_at)
```

**This is not a new discovery — it is a decision the codebase already deferred to exactly this
migration.** `MediaRepository::drive_documents()` carries a measured comment saying so:

> INDEX REALITY, measured rather than assumed. `KEY doc_listing` is `(media_type, folder_id, status,
> created_at)`, so dropping `folder_id` leaves a gap and the index can only serve its first column
> […] EXPLAIN against the 2,000-document fixture confirms MySQL picks `rank_scan` instead
> (type=ref, ~991 rows on a deep page, ~8ms). […] The clean fix is an index this table does not have
> — `(media_type, post_author, status, created_at)` — which is a schema change, **so it belongs in
> its own migration rather than riding along with a UI phase.** Until then, a very deep OFFSET on a
> drive with tens of thousands of documents is the known soft spot.

**PR2 is that migration.** The drive-keyed composite above supersedes the `post_author` version that
comment proposes — same fix, keyed on the drive the feature is moving to rather than the author it
is moving away from. Update that comment in the same commit, or the next reader trusts a soft spot
that has been closed.

**Open question for whoever builds it:** whether `doc_listing` is then dropped or kept. Keeping both
costs write throughput on the hottest table in the product; dropping it needs every remaining
`folder_id`-without-drive query found first. Decide deliberately, do not accumulate.

### 0.2 BuddyNext already has the role ladder G2 needs (affects PR3 + PR4)

`mvs_document_drive_access` is specified to return `none|read|write|own` rather than a bool. BN
already has exactly that hierarchy in `buddynext/includes/Core/PermissionService.php`:

```php
private const ROLE_HIERARCHY = array(
    'owner' => 4, 'admin' => 3, 'moderator' => 2, 'member' => 1,
);
```

plus `can()`, `can_manage_space()`, `can_own_space()`, `is_space_banned()` and `get_space_role()`.
BN also already enforces the secret-space **404, not 403** discipline in `PageRouter` (there is a
comment there saying a hidden space "must answer with a REAL 404 status header").

So **PR4 is mostly a mapping exercise, not new authorization logic** — which makes it much smaller
than the card implies, and makes it worth writing the mapping table into PR1's frozen contract so
the two sides cannot drift.

**Corrected against a clone of the active branch (BN 1.1.5, `71511f48`) on 2026-08-12.** An earlier
draft of this table listed `admin` as a space role. It is not one: `ROLE_HIERARCHY` carries four
levels, but the membership table stores only three.

```
bn_spaces.type          ENUM('open','private','secret')                  -- visibility axis
bn_space_members.role   ENUM('owner','moderator','member')               -- NO 'admin'
bn_space_members.status ENUM('active','pending','invited','banned')
```

| BN state | `mvs_document_drive_access` |
|---|---|
| role `owner` | `own` |
| role `moderator` | `write` — `own` only where `can_manage_space()` is the sharing authority |
| role `member`, status active | `write` or `read` — **BN's call per space**, not MediaVerse's |
| status `pending` / `invited` | `none` — not a member yet |
| status `banned` | `none` |
| non-member, space visible | `read` or `none`, per `can_view_content()` |
| non-member, space hidden | `none`, and the route answers **404** |

Three BN facts make this cheaper than it looks, and all three are things PR1 should name so PR4 has
nothing left to decide:

- **`SpaceMemberService::get_role()` already filters `status = 'active'`** and returns null
  otherwise, so pending / invited / banned collapse to "no role" without the bridge testing status
  separately.
- **`SpaceVisibility` is the canonical resolver and already answers all three questions the contract
  asks** — `can_view_space()` (may they know it exists → the 404 decision), `can_view_roster()`, and
  `can_view_content()` (may they see what is inside → the drive's read decision). BN's own router
  comments say the decision "comes from the canonical resolver, so this route and the REST contract
  agree" — so the bridge must answer from `SpaceVisibility`, not re-derive visibility from
  `type`. Hidden-ness is registry-driven
  (`SpaceTypeRegistry::is_hidden_from_non_members()`), not hardcoded to `secret`, so a site with a
  custom space type still gets the right answer for free.
- **`SpaceMemberService::prime_viewer_roles( $user_id, $role_by_space )`** exists, so
  `mvs_document_drives_for_user` can resolve a viewer's roles across many spaces without one query
  per space.

The `member` row is the one genuine decision left. Everything else falls out of what BN already
computes.

### 0.3 The bulk route must reuse, not re-implement (affects A2)

`DriveActions::act_on_many()` already exists and is well built: every item goes through
`act_on_document()` / `act_on_folder()`, and its docblock says why in as many words —

> THE WHOLE DESIGN IS THAT THERE IS NO SECOND GUARD. […] A bulk handler with its own checks is
> exactly how the two drift, and the bulk one is the one nobody reviews.

Those methods are **private and coupled to `$_POST`**. A REST bulk route that calls them is
impossible today and a REST bulk route that re-implements them walks straight into the failure that
docblock warns about.

So A2 is not "add a route". It is: **extract the per-item action into a service both callers use**,
then put a thin REST controller and the existing drive form on top of it. That extraction is the
whole risk in the card, and it is also what makes A1 (replace) cheap afterwards.

The same reasoning names the trap on A1: Free's `MediaController::replace_file()` is a 247-line
method sitting in the **Known Debt table** precisely because it orchestrates its own ingest instead
of calling `UploadService::handle()`. Document replace must go through `DocumentIngestService`, not
grow a second copy of that shell.

---

## 1. What is actually in the Bugs column

Verified 2026-08-12. All ten claims hold.

| Card | Item | Verified state |
|---|---|---|
| 10192556448 | `POST /documents/{id}/replace` | absent — 0 route registrations |
| 10192556611 | `POST /documents/bulk` | absent — bulk exists as a drive form only |
| 10192556721 | PR1 freeze codes + filter names | not started; **blocks BN** |
| 10192556872 | PR2 drive columns (G1) | absent — no `drive_type` / `drive_id` on `mvs_media_index` |
| 10192557006 | PR3 drive access + `/drives` (G2/G3) | absent — no `/drives` route, no `drive` param |
| 10192557180 | PR4 BN bridge (G4) | re-checked on **BN 1.1.5**: `WPMediaVerseBridge.php` is 1,283 lines, **0** `mvs_document*` hooks, and nothing under `includes/Bridges/` mentions documents at all |
| 10192557271 | PR5 T1 + privacy token (G5/G6) | not started |
| 10192557407 | Rule 7 promotion (P1.2) | **Free 24 files / 66 sites, Pro 4 files / 6 sites** — matches the card exactly |
| 10192557558 | Scale fixture (P3.9) | not started |
| 10192557770 | Migrator v27 real-DB pass (P2.2) | dev data only |

---

## 2. Recommended order

Three tracks. **Track A and Track C do not depend on Phase 11 and can start immediately.** Track B
is strictly sequential and PR1 gates the rest.

```
NOW          Track A (REST gaps)      A2 bulk extraction ──► A1 replace
             Track C (debt)           C1 Rule 7 ─┬─► C3 real-DB pass
                                                 └─► C2 scale fixture
THEN         Track B (Phase 11)       PR1 ──► PR2 ──► PR3 ──► PR4 ──► PR5
                                      contract  schema  API    bridge  T1
```

**Why A2 before A1.** A2 forces the per-item action out of `DriveActions` into a service. A1 then
has somewhere to live and a permission ladder to reuse. Doing A1 first means writing the replace
guard twice.

**Why C1 early.** Rule 7 is `known_gap()` today, so it is visible but non-blocking. Every PR in
Track B adds `mvs_media_index` queries — PR2 changes its schema. Landing the call-site migration
*before* PR2 means the new drive-scoped reads are written through `MediaRepository` from the start
rather than being migrated a second time.

**PR1 first inside Track B, and it is contract-only.** It is the only part BN cannot work around
later: their error handling and tab logic get written against these codes on day one, and every
change after is a coordinated two-plugin release.

---

## 3. Track A — the two REST gaps

### A2 · `POST /documents/bulk` (card 10192556611)

**Shape:** extraction first, route second.

1. Extract `act_on_document()` / `act_on_folder()` out of `DriveActions` into a
   `DocumentActionService` (Pro, `includes/Documents/`), taking explicit arguments instead of
   reading `$_POST`. `DriveActions` becomes a caller. No behaviour change — this step should be
   provably inert.
2. Add `POST /mvs-pro/v1/documents/bulk` calling the same service per item.
3. Honest per-item results. The drive form reports first-refusal-plus-count because a member reads
   one sentence; **an API client should get the full per-item array** — same information, shaped for
   a different consumer.
4. Keep `BULK_MAX`. Report it as a 400 rather than truncating, matching the form's `too_many`.

**Acceptance:** same permission checks as single-item move/trash (by construction, since it is the
same code path); per-item refusal reasons; `X-WP-Total` semantics unchanged; the drive form's
behaviour byte-identical before and after the extraction.

**Watch for:** the extraction is the risky half. Land it as its own commit with the drive form
re-verified in a browser, then add the route.

### A1 · `POST /documents/{id}/replace` (card 10192556448, decision D3)

D3 is already specified in detail and does not need re-deciding:

- superseded file stays on disk under `_mvs_replaced_from`, admin-restorable
- swept by cron after 30 days, filterable
- meta value is `YYYYMMDDHHMMSS|<path>` **so the sweep's WHERE can bound it** — a bare `meta_key`
  lookup is a full-meta-table scan on a site with millions of meta rows
- each sweep run caps at 500 deletions, same bounded shape as `StorageRepairService`
- this is an undo, not versioning: one step back, no history UI, no version table, no schema

**Shape:** route → permission ladder identical to edit → `DocumentIngestService` for the new bytes →
stamp `_mvs_replaced_from` → return the updated document. Do **not** grow a second ingest
orchestrator (see §0.3).

**Acceptance:** route exists; permission ladder same as edit; superseded file recoverable by an
admin; cron sweep bounded and range-queried; personal-drive behaviour otherwise unchanged.

**Open:** the admin restore surface. D3 says "admin-restorable" without saying where. Cheapest
honest answer is a row action on the existing Documents admin screen, reusing the single-document
view added in 2.4.0 — worth confirming before building a new screen.

---

## 4. Track B — Phase 11, Space drives

### PR1 · Freeze the contract (card 10192556721) — **ships first, blocks BN**

No schema, no behaviour. Publish, as a document BN can build against:

- the §22 refusal table (10 rows) as frozen
- the filter names: `mvs_document_drives_for_user`, `mvs_document_drive_access`,
  `mvs_document_drive_label`
- `mvs_document_owns_drive` and `mvs_document_can_grant` become **derived**, kept ≥2 majors per
  Production Rule 2
- **the BN role → access-level mapping table from §0.2**, so both sides encode the same answer
- the layering rule: layer 2 must never answer layer 1, or a bridge answering drive filters becomes
  a way to re-grant a member the feature their role switched off
- **which BN function answers each filter** (§0.2): `SpaceVisibility::can_view_space()` for the
  exists / 404 decision, `can_view_content()` for read, `SpaceMemberService::get_role()` for the
  role, `prime_viewer_roles()` for the batched listing. Naming them in the contract is what stops
  PR4 re-deriving visibility from `bn_spaces.type` and disagreeing with BN's own router.

**Acceptance:** BN can write error handling and tab logic against a document that will not move.

**Cheap and worth it:** ship the refusal codes as a PHP constant map in Pro in the same PR, even
with nothing consuming it yet. A frozen list that exists only in Markdown drifts.

### PR2 · Drive columns + Migrator (card 10192556872 — G1)

**The P0.** A document uploaded to a Space root today stores only `post_author`, so it would appear
in the **uploader's personal drive**, not the Space library.

- add `drive_type` + `drive_id` to `mvs_media_index`, **columns not post meta** — every listing this
  feature needs is drive-scoped, and drive-scoped listing through post meta is a join that degrades
  exactly as a Space library grows
- **add `KEY drive_listing` in the same migration** (see §0.1) and update the `drive_documents()`
  comment that reasons about `doc_listing`
- ingest stamps the drive
- **backfill personal drives in the same migration**, so root listing has ONE code path rather than
  a personal branch and a Space branch that will drift
- Migrator bump; minor release only (Production Rule 4)

**Anti-patterns, frozen (§23.3):** never write a BN `space_id` into media meta `group_id` or album
`_mvs_group_id`; never set `post_author` to a Space id.

**Watch for:** the backfill runs `UPDATE` over every document row on the site. Bound it the way the
other large migrations are bounded, and measure it against the C2 fixture rather than guessing.

### PR3 · Drive access + list API (card 10192557006 — G2, G3)

- **G2:** replace bool `owns_drive()` as the sole write gate with `mvs_document_drive_access` →
  `none|read|write|own`, consumed by ingest, move and folder-create. `owns_drive` / `can_grant` stay
  for admin and sharing.
- **G3:** `GET /drives`, plus `?drive=space:N` (and `site`) on the documents list; root listing keyed
  on the drive rather than the author.

**Note this interacts with a fix that just shipped.** The 2026-08-11 folder-leak fix
(`PermissionService::folder_grant_applies()`) made `GET /folders/{id}` refuse every non-owner,
because nothing currently writes `target_type = 'folder'` grants. PR3 is where non-owners legitimately
gain folder access — so **PR3 must re-verify that route with a Space member**, or the drive will look
correctly scoped and folders will 404 inside it.

### PR4 · BN bridge (card 10192557180 — G4)

Implement the frozen filters in Pro; wire the bridge. Per §0.2 this is mapping BN's existing role
hierarchy, not new authorization.

**Non-negotiable:** a non-member of a secret Space gets **404, not 403**. And per §23.4, the test for
this must be run by a member who **has** `use_mvs_documents` — otherwise it passes for the wrong
reason (the feature gate fires first and returns 403, and nobody notices the 404 was never exercised).

**Frozen:** MediaVerse never queries `bn_*` tables. The bridge answers filters.

### PR5 · T1 + privacy token (card 10192557271 — G5, G6)

- **G5 / T1:** on user delete — purge personal-drive documents, **reassign** Space/site-drive ones.
  §15 calls this a release blocker: "otherwise a member leaving takes the team's files with them.
  Silent, permanent, triggered by a routine event." It blocks Space being shippable, not v1.
- **G6:** confirm `PrivacyService` resolves BN Spaces rather than only `groups_is_user_member()`.
  `group` stays BuddyPress-only.

**Reassign to whom** is not settled in the plan. Space owner is the obvious default; it needs an
explicit decision before this is built, and it should be filterable.

---

## 5. Track C — debt and verification

### C1 · Rule 7 → `violation()` (card 10192557407)

Counts verified 2026-08-12 — Free **24 files / 66 sites**, Pro **4 files / 6 sites**. Concentration
makes this tractable: the top four Free files are a third of the work.

| Free file | sites |
|---|---|
| `includes/CLI/Commands.php` | 11 |
| `includes/Services/CloudOps.php` | 8 |
| `includes/REST/Controller/MediaController.php` | 7 |
| `includes/Services/CptIdCollisionService.php` | 6 |
| `includes/Admin/StatsPage.php` | 6 |
| *(19 more files)* | 28 |

Pro: `Stories/StoryService.php` (2), `Leaderboard/LeaderboardService.php` (2),
`Integrations/RtMedia/MigrationAdmin.php` (1), `Documents/SearchService.php` (1).

**Shape:** migrate file by file through `MediaRepository`, shrinking the allowlist as you go; promote
to `violation()` only when the list is empty. `bin/mutation-test-rule7.sh` already exists to prove
the rule before it is trusted — run it at promotion.

**Do this before PR2** (see §2).

### Progress, 2026-08-12 — 66 → 32 in Free

Migrated: StatsPage (6), TemplateLoader + Activator (3), the social services and MediaTag (4), the
compliance paths and hash lookup (4), the BuddyPress integrations and three render blocks (7),
ModerationService (2), MediaListPage (5), and three of MediaController's (3). Every one proved
equivalent against live data before the swap.

**Three things the next person should not have to rediscover.**

**1. The generic listing helpers are a trap for admin and compliance surfaces.** Twice, the obvious
reuse would have silently dropped rows:

```
GDPR export       query_by_author()   1 of 58 rows    (privacy-filtered)
Moderation queue  query_count()      64 of 161 rows   (media_types defaults to MEDIA_LIBRARY)
```

Both would have succeeded quietly. That is why `author_media_ids()`,
`author_media_export_rows()`, `moderation_queue()` and `moderation_counts()` exist as separate,
explicitly-unfiltered methods rather than reusing `query()`. Anything admin-facing or
compliance-facing should get the same treatment.

**2. Blocks register from `build/`, not `src/`.** The detector only scans `src/`, so editing a
block's `render.php` and not rebuilding reports success while the running site stays on the old
code. Run `npx grunt build` as part of any block migration.

**3. `MediaController`'s feed query CANNOT be migrated as things stand — this is the finding.**
Its four remaining sites are the REST feed's own query, and the blocker is not complexity:

```php
$feed_args = apply_filters( 'mvs_feed_query_args', array(
    'where'  => $where,     // raw SQL fragments
    'params' => $params,
    …
), $request );
```

`mvs_feed_query_args` is a SHIPPED PUBLIC FILTER that lets third parties inject raw WHERE fragments
and bound params into this query. To move it into `query()`, `query()` would have to accept raw SQL
from a filter — which turns the repository method into a passthrough and defeats the rule it is
supposed to serve. On top of that the query carries `MATCH…AGAINST` with a LIKE fallback, a follows
subquery, a blocked-authors `NOT IN`, the non-cover-group exclusion and a media_group meta subquery.

**Do not migrate it by extending `query()`.** The options are, in order of preference:

- **Make the detector's allowlist line-level.** It is file-level today
  (`DEFAULT_ALLOWLIST` in `bin/lib/detect-media-index-leaks.py`), so allowlisting `MediaController.php`
  would blanket-cover 1,100 lines and hide the next leak somebody adds there. Line-level entries with
  a reason each would let these four be pinned precisely and let the rule reach `violation()` honestly.
- Or leave them tracked in `known_gap()`, which is what that state is for, and accept that Rule 7
  stays non-blocking until the feed's extension point is redesigned.

**Deliberately not attempted here:** `CLI/Commands` (11), `CloudOps` (8) and `StorageRepairService`
(3) — the storage-migration and cloud-transfer paths. They can be edited but not verified without a
bucket and credentials, and this is the area that shipped two customer-visible bugs in 2.3.1
(`migrate-storage` leaving variants behind, WebP 403s behind a CDN). `CptIdCollisionService` (6) is a
diagnostic whose whole job is reading raw index rows and is probably an allowlist entry rather than a
migration.

### C2 · Scale fixture (card 10192557558)

Seed a 30k-document drive and **measure** the §12 claims rather than reasoning about them —
specifically batched subtree rename above 5k, listing, and search. Two immediate consumers: PR2's
backfill cost, and the `drive_listing` index decision in §0.1.

**A 2,000-document fixture already exists** — `drive_documents()`'s index comment cites EXPLAIN
results against it. So this is scaling an existing fixture by 15x, not building one from nothing,
and the existing EXPLAIN numbers give a baseline to compare against. The specific claim to re-measure
at 30k is the one that comment flags as unproven: **a very deep OFFSET on a drive with tens of
thousands of documents.**

Per CLAUDE.md's big-site rule this is baseline, not follow-up. It is listed last only because it has
no dependants blocking on it — not because it is optional.

### C3 · Migrator v27 real-customer-DB pass (card 10192557770)

v27 quarantines pre-1.2.3 catch-all rows as `legacy_document`, adds `folder_id`, and adds
`doc_listing`. It has been applied and verified **on dev data only**. Needs one pass against a real
customer dump before the migration is treated as battle-tested — and it is worth doing before PR2
adds a second migration on the same table.

---

## 6. Risks

| Risk | Where | Mitigation |
|---|---|---|
| `doc_listing` silently stops covering the drive query | PR2 | add `drive_listing` in the same migration; §0.1 |
| Bulk REST re-implements the guard and drifts | A2 | extract the service first, as its own inert commit |
| Replace grows a second ingest orchestrator | A1 | route through `DocumentIngestService`; do not copy `replace_file()` |
| Secret-Space 404 test passes for the wrong reason | PR4 | run it as a member who holds `use_mvs_documents` (§23.4) |
| Folders 404 inside a correctly-scoped Space drive | PR3 | re-verify `GET /folders/{id}` with a Space member after the 2026-08-11 leak fix |
| Backfill `UPDATE` over every document row on a large site | PR2 | bound it; measure against C2 |
| Contract changes after BN builds against it | PR1 | freeze first, ship the codes as a PHP constant map |

---

## 7. What this plan does not decide

Left open deliberately, each needing an owner's call before the relevant PR:

1. **Whether `doc_listing` is dropped** once `drive_listing` exists (§0.1).
2. **What a plain Space `member` maps to** — `write` or `read` (§0.2). BN's call, per space.
3. **Who Space documents reassign to** on member departure (PR5).
4. **Where admin restore lives** for a replaced document (A1).
5. **Whether bulk stays drive-form-only** — card 10192556611 explicitly allows "documented decision
   that bulk stays drive-form-only and §9 is updated" as an acceptable outcome. Building it is
   recommended (Rule 18: a native app is planned), but declining it is a legitimate answer that must
   then be written into §9 rather than left as a silent gap.
