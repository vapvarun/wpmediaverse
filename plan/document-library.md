# Document Library — the plan

**Status:** personal drives **BUILT AND SHIPPED IN 2.4.0** (not yet released). Space drives not started.
**Shipped in:** Free + Pro **2.4.0**, paired.
**Consumer:** BuddyNext, at the REST level only. MediaVerse's own UI is the standalone fallback.
**QA:** `qa/runbooks/DOCUMENTS-QA.md` — the one checklist to hand a tester.
**Visual companions:** `plan/document-library-visual.html`, `plan/single-document-view.html`.

> **This is the single source of truth for the document library.** It absorbed five working
> documents in August 2026, and on 2026-08-11 it absorbed six more: the build plan (P1–P11), the
> remaining-UI task list, the owner-settings design, the session handover, the Space-association
> gap analysis, and the BuddyNext REST contract. Their content is in §19–§22; the deliberation that
> produced them is in `git log`. **If you are reviewing or testing this feature, read only this
> file** — and `qa/runbooks/DOCUMENTS-QA.md` if you are testing it.
>
> **Section numbers §1–§18 are load-bearing.** Code comments, journeys and Basecamp cards cite them
> by number ("design §5", "§14 P8.4", "§7 BN seam"). Do not renumber them. New material goes at the
> end.

## START HERE — where this actually stands

| | State |
|---|---|
| Personal drives (folders, upload, share, trash, search, bulk move) | **Shipped 2.4.0** |
| Owner settings (7 controls) + per-role gate | **Shipped 2.4.0** — see §21 |
| Admin Documents screen + single editor | **Shipped 2.4.0** |
| REST for personal drives, and `/app/config` discovery | **Shipped 2.4.0** — see §22 |
| **Space / site drives** | **Not started.** §23 is the gap analysis; nothing in it is built |
| Departing-member reassignment (T1) | Not started. Blocks Space, not personal |

**Release state:** both plugins committed on branch `2.4.0`, local CI green, Free cert 69/0/0, Pro
cert 57/0/0, combo browser smoke SHIP with fresh-install and upgrade walked in Docker from the
2.3.2 release ZIPs. The release gate (`qa/.last-smoke-pass.json`) is green. Not tagged, not pushed.

**Hard dependency, satisfied:** the album/collection ID-collision fix
(`plan/2026-08-08-cpt-id-collision-fix-plan.md`) shipped in 2.3.3 on this branch. This design puts
documents into `mvs_media_index`, and that fix is what made the ID space safe to share.

### Team review, 2026-08-08 — adopted

An independent review of this plan found **two real schema defects**, both now fixed above:

1. **`UNIQUE KEY name_in_parent` was wrong.** Keyed on `(parent_id, name)` alone, every drive root
   shares `parent_id = 0`, so two members creating "Invoices" at their own root would collide and
   the second insert would fail. Now `(drive_type, drive_id, parent_id, name(150))`.
2. **The index the query named did not exist.** "Shared with me" was written against
   `KEY grantee_user`, carried over from the abandoned `mvs_pro_doc_permissions` design, so the
   surface's only query had nothing behind it. The index now added — and referenced everywhere else
   in this plan — is **`KEY grantee (grantee_type, user_id, grantee_role(60))`**.

Also adopted: the five items in §15 are now stated as **release blockers** rather than
recommendations; a **v1 cut** is stated in §14 (personal drives first, Space drives as the
follow-on); and §14 now warns that `search_text` lands in the schema six phases before extraction
fills it, so the UI must not imply search works at first enable.

The review confirmed the core as written — documents as a `media_type`, `folder_id` separate from
`album_id`, the virtual root, killing the MIME catch-all, the query choke point before any document
row, no Office Online viewers, `token_hash DEFAULT NULL`, a new dashboard tab registry, the
`/app/config` documents block, and Application-Password-only auth.

### Scale review, 2026-08-08 — adopted

A second review judged the plan against the fleet standard — 10k–100k-member sites, shared hosting,
multi-year table growth — with each factual claim checked against the running code rather than read.
Verdict: the architecture holds at scale; **one schema change and three write-path guards were
required**, all applied above:

1. **`search_text` + FULLTEXT moved off `mvs_media_index` into a side table**
   (`mvs_document_search`, §2). The hottest table in the product must not carry an FTS index it
   maintains on every write, a long-lock `ALTER` on fleet-sized tables, or a `longtext` that
   degrades buffer-pool density for every media query — costs paid by all rows for a feature used
   by a minority of them. One JOIN on the search query alone. The one-ID-space rule is untouched:
   the side table is an index keyed on `media_id`, not a second identity.
2. **Subtree writes are batched above 5,000 rows** — rename/move (§4) and the privacy cascade (§5)
   — through Action Scheduler, the pattern extraction already uses. The cascade flips the folder's
   own privacy first, synchronously, so the sweep window fails closed. A 30k-document Space drive
   must never run a 30k-row transaction inside a web request.
3. **Depth default cut from 20 to 12** (§4). `KEY subtree` prefixes `path` at 150 bytes; a depth-20
   path with 8-digit ids is ~180 chars — past the prefix, silently degrading subtree queries to
   drive-wide scans. Depth 12 keeps the worst case inside the index. The `name_in_parent` byte math
   was also corrected (660, not 658; utf8mb4 prefixes carry 2 length bytes — conclusion unchanged),
   and folder names are trimmed, NFC-normalized and validation-capped at 150 chars so the unique
   index prefix always equals the whole value.
4. **The D3 sweep queries by value range, not by bare `meta_key`** (§17), capped per run — a
   meta-key-only scan is the classic large-postmeta trap. And D2 gains the "what's using my 5 GB"
   answer as an on-demand `GROUP BY`, no counter, because that is the first support question a
   quota site asks after launch (§17).

**Both items the scale review recorded rather than changed have now been applied**, because both
were right:

5. **The §8 CI rule now BANS direct `FROM mvs_media_index` outside `MediaRepository`** with an
   explicit allowlist, instead of merely requiring a `media_type` predicate — and it is
   mutation-tested before it is trusted. The evidence settles it: the trashed-media leak
   (`68113454`, Basecamp 10180901914) was a hand-built feed query whose `WHERE` **already contained
   a `media_type` predicate** (`media_type != ''`) and was missing `status = 'publish'`. A
   predicate-checking rule passes that query clean. It would have bought false confidence in the one
   gate that replaced a structural guarantee. Detail in §8.
6. **D4 is pinned by a test** asserting the link-redemption route never joins
   `CommunityPrivacyGate`'s exempt set while the gate is armed — the sibling BuddyNext gate shipped
   exactly that hole for its PWA and payment routes, and "just exempt the new route" is the change a
   future fix reaches for. Detail in §17 D4.

---

## 1. What this is

A Drive-style document library: personal, Space and site-wide drives with a nested folder tree,
permission grants, and a REST surface complete enough to drive a native client.

### Owner decisions

| Question | Decision |
|---|---|
| Ownership | Personal drives, BuddyNext **Space** drives, site-wide library |
| Consumer | **BuddyNext only.** Not BuddyPress, not BuddyBoss |
| Entity model | **Documents are one more `media_type` in `mvs_media_index`** — not a separate entity |
| New tables | **Two** — `mvs_pro_folders`, plus `mvs_document_search` (scale review: the FULLTEXT index lives beside the hot table, not on it). Everything else reuses what exists |
| Version history | **None.** Current file only |
| Link sharing | Logged-in **and** anonymous; anonymous **off by default** per site |
| Formats | PDF, modern Office, ODF, plain text, legacy Office. **`.zip` excluded** |

### Why documents are a media type, not a separate entity

An earlier draft gave documents their own tables and generalized six Free engagement tables with an
`object_type` column. That was reversed. The separation bought one thing — a structural guarantee
that a document could never appear in a media grid — and paid for it with **two ID spaces**: nine
tables needing a new column, four unique keys widened, two PRIMARY KEY rebuilds on 50+ live sites, a
parallel comment scope, a parallel taxonomy, parallel REST controllers for every social action, and
a quota system that could not see documents.

Removing the second ID space removes all of it. Reactions, comments, favourites, tags, reports,
moderation, activity, notifications, stats, GDPR export/erase, quota, the admin list, `/me/media`
and **the existing `pdf-viewer` block** all work unchanged, because a document *is* an
`mvs_media_index` row.

What replaces the structural guarantee is **query discipline at one choke point, enforced by CI**
(§8). That trades an unrecoverable, silent, migration-time risk for a visible, patchable,
render-time one — the right direction on a production fleet.

---

## 2. Data model

```
wp_mvs_media_index                     (existing — one row per library item)
  media_id      PK AUTO_INCREMENT      ← never written explicitly
  media_type    'image'|'video'|'audio'|'document'   ← the discriminator
  folder_id     ──────────────┐        ← NEW column, documents only
  album_id                    │        ← existing, media only, untouched
  post_author, privacy, status, moderation_status,
  file_type, file_size, file_hash, slug, view_count,
  created_at, updated_at
                              │
wp_mvs_document_search        │        (NEW — search index, documents only)
  media_id      PK ◄──────────┤        one row per document, FULLTEXT lives
  search_text, updated_at     │        here, never on the hot index table
                              │
wp_mvs_pro_folders            │        (NEW — 1 of 2 new tables)
  folder_id     PK ◄──────────┘
  parent_id     ──┐ self-referencing, 0 = drive root (virtual)
  folder_id     ◄─┘
  drive_type, drive_id, name, slug, path, depth,
  privacy, status, created_by, created_at, updated_at, trashed_at

wp_mvs_access_grants                   (existing — extended)
  target_type   'media' | 'folder'     ← NEW discriminator
  media_id      → media_id  OR  folder_id
  grantee_type  'user'|'role'|'link', grantee_role, token_hash, permission  ← NEW
  user_id, expires_at, revoked_at, source (existing)
```

Two relationships, both single-column and indexed. A document points at one folder; a folder points
at one parent. No junction table, no meta join, no recursive query on any read path.

### Schema delta

**Free Migrator v27** — additive, `information_schema`-guarded, no backfill:

```sql
UPDATE {p}mvs_media_index SET media_type='legacy_document' WHERE media_type='document';  -- first
ALTER TABLE {p}mvs_media_index ADD COLUMN folder_id bigint(20) unsigned NOT NULL DEFAULT 0;
ALTER TABLE {p}mvs_media_index ADD KEY doc_listing (media_type, folder_id, status, created_at);
ALTER TABLE {p}mvs_media_index ADD KEY type_file  (media_type, file_type);

-- Search lives in a SIDE TABLE, not on the index (scale review, 2026-08-08).
-- An earlier draft put search_text longtext + FULLTEXT on mvs_media_index
-- itself. That lands the cost on the hottest table in the product: every
-- InnoDB write to it would maintain an FTS index, the ALTER is a long lock
-- (or an online-DDL disk-double) on a 500k-row fleet site, and a longtext in
-- the row pushes toward off-page storage — degrading buffer-pool density for
-- EVERY media query, when documents will be a small minority of rows. A side
-- table keyed on media_id costs one JOIN on the search query alone and
-- nothing anywhere else. This is an index, not an entity — the one-ID-space
-- rule is about identity, and media_id stays the only identity here.
CREATE TABLE {p}mvs_document_search (
  media_id    bigint(20) unsigned NOT NULL,
  search_text longtext NOT NULL,
  updated_at  datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (media_id),
  FULLTEXT KEY media_content_ft (search_text)
) {charset_collate};
```

**Pro Migrator v11:**

```sql
CREATE TABLE {p}mvs_pro_folders (
  folder_id  bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  drive_type varchar(10)  NOT NULL DEFAULT 'user',   -- user | space | site
  drive_id   bigint(20) unsigned NOT NULL DEFAULT 0,
  parent_id  bigint(20) unsigned NOT NULL DEFAULT 0, -- 0 = drive root
  name       varchar(255) NOT NULL,
  slug       varchar(255) NOT NULL DEFAULT '',
  path       varchar(255) NOT NULL DEFAULT '/',      -- '/12/48/'
  depth      smallint(5) unsigned NOT NULL DEFAULT 0,
  privacy    varchar(20)  NOT NULL DEFAULT 'private',
  status     varchar(20)  NOT NULL DEFAULT 'active', -- active | trashed
  created_by bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at datetime DEFAULT NULL,
  trashed_at datetime DEFAULT NULL,
  PRIMARY KEY  (folder_id),
  KEY drive    (drive_type, drive_id, parent_id, status),
  KEY parent   (parent_id, status),
  KEY subtree  (drive_type, drive_id, path(150)),
  UNIQUE KEY name_in_parent (drive_type, drive_id, parent_id, name(150))
) {charset_collate};

ALTER TABLE {p}mvs_access_grants ADD COLUMN target_type  varchar(10) NOT NULL DEFAULT 'media';
ALTER TABLE {p}mvs_access_grants ADD COLUMN grantee_type varchar(10) NOT NULL DEFAULT 'user';
ALTER TABLE {p}mvs_access_grants ADD COLUMN grantee_role varchar(60) NOT NULL DEFAULT '';
ALTER TABLE {p}mvs_access_grants ADD COLUMN permission   varchar(10) NOT NULL DEFAULT 'view';
ALTER TABLE {p}mvs_access_grants ADD COLUMN token_hash   varchar(64) DEFAULT NULL;   -- NULL, not ''
ALTER TABLE {p}mvs_access_grants ADD UNIQUE KEY token_hash (token_hash);
ALTER TABLE {p}mvs_access_grants ADD KEY target (target_type, media_id);
ALTER TABLE {p}mvs_access_grants ADD KEY grantee (grantee_type, user_id, grantee_role(60));
```

> **Migration trap:** `token_hash` must be `DEFAULT NULL`. With `DEFAULT ''` every existing grant
> row takes the same value and `ADD UNIQUE KEY` fails on the duplicates. MySQL exempts NULL from
> UNIQUE. This would fail the migration on every site that has ever sold media access.

**Totals: 2 new tables (`mvs_pro_folders`, `mvs_document_search`), 1 new column on `mvs_media_index` (`folder_id`), 2 new indexes on it, 5 columns + 3 indexes on `mvs_access_grants`.** The FULLTEXT index lives on the search side table only.
No key rewrites, no PRIMARY KEY rebuilds, no backfills, no `wp_posts` rows.

### Why `folder_id` and not a reuse of `album_id`

A document lives in exactly one container, so `album_id` looked reusable. It is not: `album_id`
holds `wp_posts` IDs and folder IDs come from `mvs_pro_folders`, so one column would carry two
independent auto-increment sequences disambiguated only by remembering to check `media_type`. That
is precisely the defect the 2.4.0 fix removes. One extra `ADD COLUMN` keeps the relationship
unambiguous.

---

## 3. Identification — explicit, never inferred

**MediaVerse is a media plugin first. Document support is additive and must be asked for
explicitly.** No code path may reach a document type by elimination.

### The catch-all is deleted

`UploadService::get_media_type()` currently ends `return 'document';` — anything unrecognised
becomes a document — and the 1.2.3 upload guard *depends* on that fallback to know what to reject.
Both change together:

```php
return '';   // unknown — named nothing, inferred nothing
…
if ( '' === $this->get_media_type( $mime ) ) { … reject … }
```

Strictly safer: an unrecognised MIME is currently *labelled* a document then rejected by that label;
afterwards it is rejected *because it is unrecognised*. Escape hatch per Production Rule 3:
`apply_filters( 'mvs_media_type_for_mime', $type, $mime )`.

`'document'` is then produced by exactly one function: `DocumentTypes::resolve( string $mime, string
$extension ): ?string`, which returns a **named** type or `null` and has no default branch.

| `doc_type` | MIME | Extensions |
|---|---|---|
| `pdf` | `application/pdf` | `.pdf` |
| `word` | `application/msword`, `…wordprocessingml.document` | `.doc`, `.docx` |
| `excel` | `application/vnd.ms-excel`, `…spreadsheetml.sheet` | `.xls`, `.xlsx` |
| `powerpoint` | `application/vnd.ms-powerpoint`, `…presentationml.presentation` | `.ppt`, `.pptx` |
| `odf_text` / `odf_sheet` / `odf_presentation` | the three `vnd.oasis.opendocument.*` | `.odt`, `.ods`, `.odp` |
| `text` / `markdown` / `csv` | `text/plain`, `text/markdown`, `text/csv` | `.txt`, `.md`, `.csv` |
| `rtf` | `application/rtf`, `text/rtf` | `.rtf` |

**Two sniffing traps.** OOXML and ODF are ZIP containers — `finfo` returns `application/zip`. Accept
it **only** when the extension is in the OOXML/ODF set **and** the archive contains the expected
marker (`[Content_Types].xml` for OOXML, a `mimetype` entry for ODF); a bare `.zip` fails because
`.zip` is not in the extension map. And `.md`/`.csv` sniff as `text/plain` — the extension separates
them, which is why resolution takes both arguments and trusts neither alone.

**The REST upload takes an explicit `doc_type`** and rejects with `400` when the caller's declared
type disagrees with the resolved type. Never a silent correction.

`doc_type` is **not a stored column** — `file_type` already holds the validated MIME, and
`DocumentTypes::group_for_mime()` maps it to a display group. `KEY type_file` makes "every PDF in
this drive" an index range scan. Grouping an already-admitted file is not inference.

### Grouping — positive inclusion, never exclusion

The reference install already holds a row with an empty `media_type`. So:

```php
final class MediaTypes {
    public const MEDIA     = array( 'image', 'video', 'audio' );
    public const DOCUMENTS = array( 'document' );
    public const ALL       = array( 'image', 'video', 'audio', 'document' );
}
```

`WHERE media_type IN (…)`, never `!= 'document'`. Untyped rows belong to neither library — an
untyped row is a data defect, not content.

### Legacy rows

A site that ran pre-1.2.3 with PDF uploads enabled may hold `media_type='document'` rows classified
by the old catch-all. The migration re-types them to `legacy_document` **before** the feature can
create anything, so they leave media surfaces and never enter a drive, while staying readable at
their permalink. Check any site with
`SELECT COUNT(*) FROM …mvs_media_index WHERE media_type='document'`.

---

## 4. Folders

### Why a custom table, not a CPT or a taxonomy

- **Not a taxonomy.** `wp_terms` / `wp_term_taxonomy` have no column for an owner, a timestamp or a
  status. The folder view sorts by *modified*, shows *owner*, and folders trash — as termmeta those
  become `ORDER BY` on an unindexed `meta_value` and a filter every query must remember.
- **Not a CPT.** `wp_posts` on a BuddyPress/BuddyNext community is tiny — **65 rows on the reference
  install, 42 of them BP email templates** — because community data lives in `bp_*` and `mvs_*`
  tables. At 10,000 members folders would be ~99% of that table, and the three postmeta rows each
  folder needs would take `wp_postmeta` from 13 rows to tens of thousands with a join on every
  listing. A folder as a post also wastes 15 of 23 columns, including three empty `longtext`s.
- **A custom table** gives 14 purposeful columns, indexed drive scoping, a database-enforced
  duplicate-name guard, and a materialized `path` — which matters because WordPress still supports
  MySQL 5.7, where `WITH RECURSIVE` does not exist.

**No folder-meta table.** A folder's twelve attributes are fixed and nearly all filtered or sorted
on. At 10k folders that is 10k rows against ~120k, and an unindexed lookup per listing — the
`wp_postmeta` problem rebuilt under a plugin prefix.

### The root is virtual

`folder_id = 0` on a document and `parent_id = 0` on a folder both mean "at the drive root". **There
is no root row.** A member who never creates a folder produces **zero** rows, so folder count scales
with deliberate folder creation, not member count. "My Documents" is a display string
(`mvs_document_root_label`), not a record — which removes lazy creation, an ID cache, a delete guard
and a race rule, four mechanisms that existed only to represent an absence.

### Storage map

| Concern | Mechanism |
|---|---|
| Nesting | `parent_id` + `path` materialized as `/12/48/` |
| Drive ownership | `drive_type` + `drive_id`, indexed with `parent_id` |
| Privacy / owner / dates / trash | columns |
| Breadcrumbs | parsed from `path` — **zero queries** |
| Children | `KEY drive` |
| Subtree (delete, move, count) | `KEY subtree` → one `LIKE '/12/48/%'` |
| Name collision in one parent | `UNIQUE KEY name_in_parent (drive_type, drive_id, parent_id, name(150))` — **enforced by the database**, race-proof. The drive columns are not optional: every drive root uses `parent_id = 0`, so keying on `(parent_id, name)` alone would make two members creating "Invoices" at their own root collide. `name(150)` keeps the key under the 767-byte InnoDB COMPACT limit (42 + 8 + 8 + 602 = 660; utf8mb4 varchar prefixes carry 2 length bytes) so it holds on hosts that have not moved to DYNAMIC row format. Names are **trimmed and NFC-normalized before insert, and validation caps them at 150 chars** so the index prefix always equals the whole value — otherwise two 200-char names sharing a 150-char prefix collide falsely, and the member is told "name already exists" about a name that does not |
| Depth cap | `depth` + filter `mvs_document_max_depth` (**default 12**, was 20 — scale review). `KEY subtree` prefixes `path` at 150 bytes; with 8-digit folder ids a depth-20 path is ~180 chars, PAST the prefix, so deep trees silently degrade to scanning within the drive. Depth 12 keeps the worst case ≈110 chars, inside the index. Raising the filter past 16 is on the site owner, and the consequence belongs in the filter's docblock |
| Rename / move | one `UPDATE` rewriting the subtree `path` prefix. **Batched above 5,000 rows** (scale review): on a Space drive with 30k documents this is otherwise a 30k-row transaction inside a web request. Above the threshold the subtree rewrite runs through Action Scheduler — the same pattern extraction already uses — with the folder marked `status='moving'` so a half-applied rename is visible rather than silent |
| Routing | Pro page + rewrite, following `Frontend\GamificationTemplateLoader` |

### Drive scoping at the root

| Drive | Mechanism | Cost |
|---|---|---|
| **Personal** (v1) | `mvs_media_index.post_author` for root documents; `drive_type`/`drive_id` columns for folders | indexed both sides, no join |
| **Space / site** (later phase) | drive meta on the document, mirroring media's group assignment | one meta read |

The hot query — a personal drive root listing — is one index range scan:

```sql
SELECT … FROM {p}mvs_media_index
 WHERE media_type='document' AND folder_id=0 AND post_author=%d AND status='publish'
 ORDER BY created_at DESC LIMIT %d OFFSET %d
```

Inside a folder it drops `post_author` entirely, because the folder already scoped the drive —
`KEY doc_listing` used left-to-right, flat at any drive size.

### Counts, and mixing folders with files

**Counts are DIRECT children, not recursive.** Recursive counts in a list view either N+1 the page
or need a denormalized counter corrected on six events, and a counter that drifts is worse than no
counter. Two queries per page:

```sql
SELECT folder_id, COUNT(*) FROM {p}mvs_media_index
 WHERE media_type='document' AND status='publish' AND folder_id IN (…) GROUP BY folder_id;

SELECT parent_id, COUNT(*) FROM {p}mvs_pro_folders
 WHERE status='active' AND parent_id IN (…) GROUP BY parent_id;
```

Recursive counts stay available via `path LIKE` but only **on demand** — delete confirmation ("this
will trash 240 files"), folder properties — never in list rendering.

**Counts carry the same permission predicate as the listing.** A folder reading "12 items" to
someone who can see 3 leaks the existence of 9 files.

**Pagination across two tables.** Folders sort above files; the offset maths is the easy thing to
get wrong:

```
folder_total    = COUNT(*) of visible sub-folders
folders_on_page = mvs_pro_folders   LIMIT per_page OFFSET offset
file_offset     = MAX( 0, offset - folder_total )
files_on_page   = mvs_media_index   LIMIT (per_page - count(folders_on_page)) OFFSET file_offset
X-WP-Total      = folder_total + file_total
```

15 sub-folders, 500 files, 50 per page → page 1 is all 15 folders + 35 files; page 2 is files 36–85.
Sorting by *size* puts folders in an odd position — they sort by name in that mode and the size
column reads "—" rather than showing a number that needs a recursive computation.

---

## 5. Access

### Privacy is the default; grants are the override

This is where documents genuinely diverge from media, and the same column means something different.

| | Media | Documents |
|---|---|---|
| `privacy` | **the whole story** — public media is browsable in Explore | the **default answer** absent a grant |
| Grants | rare (paid access rules) | **the primary mechanism** — this is a collaboration product |
| "Public" means | discoverable — feeds, grids, Explore | discoverable **in the document listing only** |
| Default | `public` (column default) | `private`, set explicitly in the service |

> ### ⚠️ REVISED BY THE OWNER, 2026-08-09 — read this before the paragraph below
>
> This section originally read: *"public on a document means unlisted, not published"* — reachable
> by URL, never in any feed. **That is no longer the rule.** Documents have their own listing at
> `/explore-document`, and a public document appears there.
>
> The distinction that was collapsed: *"never in a MEDIA feed"* and *"never in ANY feed"* were being
> treated as one statement. Only the first was ever the guarantee. A document still appears in no
> media grid, Explore, album, collection or activity row — every Phase 1 predicate holds unchanged —
> but it is listed among documents, as a row with a type chip.
>
> What forced it: with no document listing there was nowhere honest for a single document's back
> link to point, and quarantined `legacy_document` rows had no surface that could render them at all
> (a media grid drew them as broken tiles).
>
> **Built:** `[mvs_documents]`, `MediaRepository::public_documents()`, page option
> `mvs_page_explore_documents`, and a type-aware back link in `TemplateHelpers::get_parent_route()`.

**So "public" on a document means listed among DOCUMENTS, never among media.** A member marking a
contract public gets a URL anyone can open and a row in the document listing — they do **not** get it
posted to the community media feed (Part C still forbids a document in any media grid). The share
modal still says *"Anyone with the link can view"* rather than "Public", because a link grant and a
public privacy level are different things.

Privacy vocabulary matches media — `public`, `members` (alias `loggedin`), `friends`, `space`,
`private`, `custom`; `dm` does not apply. **Media's `group` becomes `space`**, because
`PrivacyService::check_group()` resolves through `groups_is_user_member()` and denies when
BuddyPress is absent — and BN Spaces are not BP groups. The REST layer accepts `group` as an input
alias; storage is always the canonical token.

### Resolution ladder — two queries per page

1. **Drive owner or admin.** The Space answer is resolved **once per request per drive** and cached
   — the filter must never be called per row.
2. **Explicit grant** — document grant, then nearest folder ancestor. One query for the whole page.
3. **Privacy level.** Ancestor chain parsed from `path`, zero queries.
4. **Deny.** Most specific wins; highest permission among equals.

### Three surfaces, not one tree

A folder shared with you lives in **someone else's drive** — its parent chain ends at their root, so
grafting it into yours would mean one item with two parents in two ownership domains.

| Surface | Resolved from |
|---|---|
| **My Drive** | `drive_type='user'`, `drive_id=<me>` |
| **Shared with me** | computed from `mvs_access_grants` over the new `KEY grantee` — no row, nothing can be created in it |
| **Space drives** | `mvs_document_drive_owners`, one per Space you are an `active` member of |

**Topmost-grant collapsing:** granted both `/Contracts` and `/Contracts/2026`, the surface shows one
entry — otherwise the same files appear twice and people think they have duplicates. A path-prefix
comparison in PHP, no extra query.

> **Breadcrumbs are a leak vector.** Open a shared folder and you are inside the owner's tree. A
> full breadcrumb shows every ancestor **name** above your grant point —
> `Clients / AcmeCorp-litigation / Contracts` when you were given only *Contracts*. Folder names
> carry client identities and project codenames.
>
> **The breadcrumb starts at the highest ancestor you hold a grant on.** Everything above renders as
> one non-navigable root crumb, never as names — in templates, in API payloads, everywhere.

### Tightening a folder must cascade

Privacy is resolved with `more_restrictive()` at move time and written to the row, deliberately, so
tightening a folder later cannot silently *re-expose* files. That blocks the dangerous direction but
breaks the safe one:

> A member drops ten public documents into `/Working`, realises the folder should be private and
> switches it. **The ten stay public**, and nothing says so.

Fix: one indexed `UPDATE` over the subtree via `path`. **Tightening cascades; loosening does not** —
an explicit `private` on a file outranks its container. With a confirmation, because it is a bulk
permission change: *"Make this folder private? 47 documents inside will also become private."*

Same batching rule as rename/move (scale review, 2026-08-08): above 5,000 affected rows the cascade
runs through Action Scheduler rather than inside the request. The order matters for this one —
**the folder's own privacy flips first, synchronously**, then the contents follow in batches, so the
window during the sweep fails CLOSED (folder already private) rather than open.

### Sharing a folder vs a file

| | Folder grant | Document grant |
|---|---|---|
| Row | `target_type='folder'`, `media_id`=`folder_id` | `target_type='media'` |
| Scope | whole subtree, **including items added later** | that one file |
| In "Shared with me" | the folder, navigable | the file, flat |
| Interaction | a document grant **survives** revocation of the folder grant above it | — |

That last row matters: revoking a folder share does not silently strip individual file shares made
inside it. The share modal shows both.

---

## 6. Storage, delivery and viewers

**Location (local, the default):** `uploads/wpmediaverse-documents/<random-per-install-segment>/`,
protected by `.htaccess`, `web.config` and an `index.php` guard. The random segment matters because
nginx ignores `.htaccess`.

**Location (cloud, opt-in):** a **separate private bucket** from media — see D8. The media bucket
must be public-read for direct CDN URLs to work, and a document bucket must not be, so one bucket
cannot serve both.

**Two endpoints, different rules:**

| Endpoint | Disposition | Types |
|---|---|---|
| `GET /documents/{id}/download` | `attachment`, always | every type |
| `GET /documents/{id}/preview` | `inline` | **PDF only** — plus server-rendered HTML for tier 2, which is not the file |

```
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; sandbox
X-Frame-Options: SAMEORIGIN          (preview only — framed by our own viewer)
Cache-Control: private, no-store
```

### Viewer tiers

| Tier | Formats | Mechanism | Cost |
|---|---|---|---|
| 1 | `.pdf` | existing `pdf-viewer` block → signed URL → browser's native viewer | **0** — the block takes `mediaId` and mints via `SignedUrlService`, both of which work on a document |
| 2 | `.md`, `.txt`, `.csv` | PHP renders **sanitized HTML**; the browser never receives the original bytes | 0 |
| 3 | `.docx`, `.xlsx` | fetched as `ArrayBuffer`, parsed **in JS**, sanitized before insertion | ~150 KB / ~400 KB, **lazy on Preview click only** |
| 4 | legacy `.doc`/`.xls`/`.ppt`, `.pptx`, ODF, `.rtf` | **metadata card + Download.** No broken viewer, no apology text | 0 |

Tier 2 guards: Markdown via a sanitizing renderer with raw HTML never honoured; `.txt` refuses above
1 MB; `.csv` caps at 500 rows with an honest footer. **Markdown is the single highest-risk item
here** — it is the easiest place to introduce stored XSS.

Tier 3 output must pass through client-side sanitization (DOMPurify, ~20 KB — not counted in the
figures above) because a `.docx` can carry embedded HTML.

**Explicitly rejected: Office Online / Google Docs viewers.** Both require the file to be publicly
reachable on the internet, which destroys the permission model, and both ship customer documents to
a third party. A self-hosted library that silently uploads private files to Microsoft to draw them
is not a feature, it is an incident.

**Rejected formats:** HTML, SVG, XML (stored-XSS vectors from your own origin) and `.zip` (out of
v1). Legacy `.doc`/`.xls`/`.ppt` are accepted as **opaque bytes only** — never parsed, never
previewed, handed back byte-identical.

### Display and embedding — every type, every surface

The tiers say *how* a file is turned into something viewable. This says *where* that happens, and
what each type looks like on each surface. Four surfaces carry documents.

| `doc_type` | Row in the drive | Single view | **Embed block / shortcode** | Activity feed |
|---|---|---|---|---|
| `pdf` | icon + name + size + modified | native browser viewer in a sandboxed iframe | **inline viewer**, height configurable, click-to-load above the size ceiling | card: icon, name, size, Open |
| `markdown` `text` `csv` | same | server-rendered sanitized HTML | **inline rendered HTML**, subject to the per-page cap below | card + first-line excerpt |
| `word` `excel` | same | client-parsed on Preview click | **click-to-load only** — never auto-parsed | card, no preview |
| `powerpoint` `odf_*` `rtf` legacy `.doc/.xls/.ppt` | same | metadata card + Download | metadata card + Download | card, no preview |

**Icons, not thumbnails.** Documents have no thumbnail pipeline and are not getting one in v1: a
PDF first-page poster needs Imagick with Ghostscript, which is absent on much of shared hosting.
Every surface uses a type icon. If a poster is wanted later, follow the video-poster precedent —
capability-detect, generate when possible, fall back to the icon, and never block the upload on it.

### The four rules embedding adds

Embedding is not "the single view, smaller". It puts documents on pages the author does not fully
control, and each of these is a way that goes wrong:

**1. The privacy gate runs in the embed, on every render.** A private document embedded in a public
post shows the lock and the login CTA (`.mvs-media-gate`), never the content. The embed resolves
permission for **the viewer**, not the author — an author embedding something they can see must not
publish it to everyone. This is the single most likely way to leak a document, because the author
sees it working.

**2. Tier 3 is click-to-load, always.** Ten `.docx` embeds on one page would otherwise pull ~1.5 MB
of parser before anyone asks to read anything. The embed renders the metadata card with a Preview
button; the bundle loads on click, once per page regardless of how many embeds want it.

**3. Server-rendered tiers are capped per page.** Each tier-2 embed is a file read plus a parse on
every uncached page load. Cap at **5 rendered embeds per page** (filterable); beyond that the embed
degrades to the metadata card with a Preview link. Rendered output is cached against the document's
`updated_at` so a repeat view is a cache hit, not a re-parse.

**4. A deleted or trashed document degrades, it does not fatal.** The embed renders "This document
is no longer available" — Coding Rule 11, and the same for a document whose privacy tightened after
the post was published.

### Blocks and shortcodes

| | Purpose |
|---|---|
| `document-embed` block | One document, any type. Attributes: `documentId`, `height`, `showToolbar`, `forceDownloadOnly`. Delegates PDFs to the existing `pdf-viewer` rendering path rather than duplicating it |
| `document-list` block | A folder or a filtered set as rows. Attributes: `folderId`, `docType`, `limit`, `showSize`, `showModified` |
| `[mvs_document id="123"]` | Shortcode parity for classic editors and page builders |
| `[mvs_documents folder="48"]` | List parity |

**No oEmbed, and no auto-embed on paste.** Pasting a document permalink into the editor leaves a
plain link. WordPress's internal-link embed would render a preview using the *author's* permission
at save time and cache it — exactly the leak rule 1 exists to prevent. An explicit block is the only
way in.

### Cloud storage

`StorageService::get_driver_for_privacy()` sends only **public** media to cloud, so documents
(default `private`) would never use it. Resolution: separate *where it is stored* from *how it is
delivered*.

| Document privacy | Stored | Delivered |
|---|---|---|
| `public` | cloud (if configured) | direct CDN URL |
| everything else | **cloud (if configured)** | **gated stream by default** (D7). Presigned short-TTL URL only when the owner opts in; where a driver cannot sign, always streamed. `url()` is never called |

Signing goes in a **separate optional interface** (`SignedDeliveryInterface`) that drivers opt into —
adding an abstract method to the published `StorageDriverInterface` would fatal every third-party
driver on upgrade.

**The trade presigned URLs carry:** permission is evaluated once at mint, so a revoked user's
unexpired URL still works. Mitigations: 300 s default TTL (`mvs_document_signed_url_ttl`), minted
per request, **never logged**, and a site setting to force streaming for owners who want
per-request revocation over CDN performance.

**Bucket visibility is the operational trap.** On cloud the equivalent of the local deny-rules is a
private bucket — site-owner configuration MediaVerse does not control. A **Site Health check** must
verify the document bucket is not public-read, following the `wpmediaverse_video_posters` precedent.
This is the difference between "documents are private" being true and being merely intended.

### No versioning

Per owner decision. The one consequence designed around: a same-name upload creates a **new row**
("Invoices (2).pdf") rather than overwriting; replacement is an explicit, confirmed action. That
gives no-versioning semantics without a silent overwrite. Adding real versioning later needs a
Migrator bump and therefore a minor release.

---

## 7. The BuddyNext seam

BN owns Spaces in `bn_spaces` / `bn_space_members`. **MediaVerse must never query them.**
`drive_type = 'space'` is an opaque token; every question goes through filters BN answers from its
`Bridges\WPMediaVerseBridge`:

```php
apply_filters( 'mvs_document_drive_owners', [], $user_id );
apply_filters( 'mvs_document_drive_access', false, $drive_type, $drive_id, $user_id );
apply_filters( 'mvs_document_drive_label',  '',  $drive_type, $drive_id );
```

The contract must express what BN's model implies: Spaces are typed `open|private|secret` (a
**secret** Space's drive must 404, never 403); membership carries a status (only `active` is a
member) and a role (the natural source of the permission level); Spaces are hierarchical, so
child-Space inheritance is BN's decision through the same filter.

**Frontend-presence policy.** `Core\Plugin` already stands MediaVerse's UI down when
`mvs_buddynext_active` is true. Documents follow: when BN is active MediaVerse renders no document
frontend and BN builds its own on the REST API. **MediaVerse's templates are the standalone
fallback; the REST surface is the product.**

---

## 8. Query discipline — what replaces the structural guarantee

Documents and media share a table, so *"a document never renders in a media grid"* becomes a query
guarantee. Three mechanisms hold it, and they ship **before any document row can exist**:

1. **One choke point.** `MediaRepository` list/count methods take a type group, defaulting to
   `MediaTypes::MEDIA`. The document library asks for `MediaTypes::DOCUMENTS` explicitly.
2. **A one-pass audit.** `mvs_media_index` is named directly in **~50 Free files and ~16 Pro files**
   outside `MediaRepository` — nine templates and block `render.php` files, plus Pro's
   `InstagramLayout`, `LeaderboardService`, `ChallengeService`, `StoryService`, `TournamentService`.
3. **A CI gate that BANS the query, not one that inspects it.** New rule in
   `bin/coding-rules-check.sh`: `FROM …mvs_media_index` outside `MediaRepository` **fails the
   build**, full stop, with an explicit allowlist for the call sites that genuinely need raw SQL.

   > **Why banning, not "require a `media_type` predicate"** (scale review, 2026-08-08, and the
   > evidence is three commits back in this repo). The trashed-media leak — `68113454`, Basecamp
   > 10180901914 — was a hand-built feed query that returned items the owner had trashed, with a
   > working signed URL, to the feed **and the mobile app**. Its `WHERE` was:
   >
   > ```php
   > $where = array( 'moderation_status = %s', "media_type != ''" );   // status = 'publish' missing
   > ```
   >
   > **It had a `media_type` predicate.** A predicate-checking rule passes it clean. The fault was a
   > *different* missing clause, and no realistic static rule enumerates every clause a correct
   > query needs. A predicate rule would have bought false confidence — the worst outcome for a gate,
   > because it is trusted.
   >
   > Note also what that predicate was: `media_type != ''`, an exclusion. Under the document library
   > it would have returned documents into the media feed, which is the failure this whole section
   > exists to prevent. The rule that catches that is not a better predicate — it is not letting the
   > query be written outside the repository at all.

4. **The gate is mutation-tested before it is trusted.** Add a deliberately document-blind query to a
   branch, confirm CI fails **and names the file**, then remove it. A rule nobody has watched fail is
   a rule nobody knows works — and this one is load-bearing, because it is what replaced a structural
   guarantee.

Plus one executable journey: upload a document, assert it appears in the drive and in **no** media
grid, explore feed, album, collection, lightbox or BP activity stream.

---

## 9. REST surface — `mvs-pro/v1`

```
GET    /drives                                  drives visible to me
GET    /folders?drive=user:12&parent=0          folder children
POST   /folders                                 create
PATCH  /folders/{id}                            rename / move
DELETE /folders/{id}                            trash (subtree)
POST   /folders/{id}/restore                    untrash
GET    /documents?folder=48&page=1&per_page=50  paginated listing
POST   /documents                               upload (multipart; doc_type REQUIRED)
GET    /documents/{id}                          metadata
PATCH  /documents/{id}                          rename / move / describe
DELETE /documents/{id}                          trash
POST   /documents/{id}/restore                  untrash
POST   /documents/{id}/replace                  explicit replace
POST   /documents/bulk                          bulk move / trash / download
GET    /documents/{id}/download                 gated stream (attachment)
GET    /documents/{id}/preview                  gated preview (inline PDF, or tier-2 HTML as JSON)
GET    /documents/{id}/permissions              list grants
POST   /documents/{id}/permissions              grant to user or role
POST   /documents/{id}/permissions/link         mint link token (raw token returned ONCE)
DELETE /permissions/{id}                        revoke
GET    /documents/search?q=…&drive=…            cross-drive full-text search
GET    /me/shared                               items others have shared with me
```

**Social actions need no new routes.** Reactions, comments, favourites, stats, reports and tags run
through Free's existing `/media/{id}/…` family, because a document *is* an `mvs_media_index` row.
`/me/media` returns documents with a type filter. This is the single largest saving from the
media-type model.

Every list route returns honest `X-WP-Total` / `X-WP-TotalPages` from a dedicated `COUNT(*)`. Every
controller extends `WP_REST_Controller` with a real `get_item_schema()`. Auth must work outside the
cookie/nonce browser context — Application Passwords only.

`/app/config` gains a `documents` block (enabled flag, allowed types, effective max size, preview
tiers, anonymous-link policy) so **the app never hardcodes the format list**. Max size =
`min( mvs_pro_documents_max_size, wp_max_upload_size() )`, and that effective number is what the app
reports — so it rejects an oversized file locally instead of uploading into a `413`.

Pro registers its prefix with `mvs_rest_gated_route_prefixes` so private-community mode covers
`mvs-pro/v1`.

---

## 10. Frontend, admin and interlinking

**Reuses `media-single.php`** for the document single view — header row, privacy gate, description,
social bar and inline edit are identical; only the preview panel differs.

**The drive view is rows, not tiles** — icon, name, size, modified, owner. A grid of identical PDF
icons carries no information. Folders sort above files; the chosen sort applies within each group.

Every screen states its empty, loading and error paths (Coding Rule 11). Notably: an empty shared
drive a member cannot write to shows **no upload button** — a control they cannot use is a support
ticket waiting to happen; and a filtered-empty state ("No PDFs in this folder" + Clear filter) is
distinct from a genuinely empty folder, because conflating them makes people think their files
vanished.

New `assets/css/documents.css` under CSS file ownership (Rule 12), no inline cosmetic styles
(Rule 19). Mobile verified at 390px, 40px tap targets.

**Admin** — a Documents screen: list across all drives, filter by drive / owner / type / status,
sort by size and date, storage totals via `AdminAggregatesService` (never raw `SUM`/`COUNT`), trash
purge, orphan cleanup. Largely `MediaListPage` with a type-group filter. Admin HTML in
`templates/admin/` only.

**Interlinking — one product, not two plugins.**

| Never mix | Always interlink |
|---|---|
| A document never renders as a tile in a media grid | Documents get a tab in the same dashboard |
| A document never enters the media lightbox | One search box returns both, grouped |
| No media template is edited | One activity feed, one notification stream |
| Folder view is rows; media grid stays tiles | Same reactions, favourites, comments UI |

The precedent exists: Free's `dashboard-view/view.js` already carries `isChallengesTab` /
`isBattlesTab` for **Pro** features, and nobody experiences gamification as a separate plugin.

> **Fix first:** the dashboard tab seam. `mvs_dashboard_tabs` already exists as
> `do_action()` (`templates/partials/dashboard-content.php:461`) with two Pro subscribers that echo
> markup and return nothing. WordPress keeps actions and filters in one registry, so calling
> `apply_filters()` on that name would print connector markup mid-filter and assign `null` to the
> tab array. Use a new name (`mvs_dashboard_tab_registry`).

---

## 11. Settings, hooks, capabilities

**Settings** — `mvs_pro_documents_enabled` (default `'0'`), `_allowed_types`, `_max_size`,
`_default_privacy`, `_anon_links` (default `'0'`), `_link_ttl`.

**Hooks** — actions `mvs_document_uploaded`, `mvs_document_deleted`,
`mvs_document_permission_granted`, `mvs_folder_created`, `mvs_folder_deleted`; filters
`mvs_document_allowed_file_types`, `mvs_document_max_size`, `mvs_document_can_view`,
`mvs_document_max_depth`, `mvs_document_scan_file`, `mvs_document_signed_url_ttl`,
`mvs_document_root_label`, `mvs_media_type_for_mime`.

**Capabilities** — `manage_mvs_documents` (site drive + admin screens; named to match the registered family — `manage_mvs_settings`, `manage_mvs_access` — manifest check 2026-08-08, an earlier draft said `mvs_manage_documents` which matches nothing); member access resolves
through drive ownership and grants.

**Virus scanning** — MediaVerse ships **no scanner**, but must ship the seam:
`mvs_document_scan_file` returning `WP_Error` to reject, with the outcome in `scan_status`. Shipping
a document library with no scan hook is the gap a security reviewer finds first.

**Metadata stripping** — more important for documents than photos. `.docx`/`.xlsx` embed author
names, company, tracked changes and deleted comments; PDFs carry author and editing history. A
member uploading an invoice can leak their accountant's name. Default on, with the caveat stated in
the setting: stripping rewrites the file, so the stored copy is not byte-identical.

---

## 12. Big-site readiness

| # | Item | How this design satisfies it |
|---|---|---|
| 1 | Pagination | `LIMIT`/`OFFSET` + `COUNT(*)` on every list route |
| 2 | Indexes | `KEY doc_listing` **is** the drive query verbatim; `KEY subtree` backs the prefix scan |
| 3 | N+1 | permission resolution 2 queries/page; counts 1 `GROUP BY` per page |
| 4 | `COUNT(*)` | dedicated count methods, never `count()` over a result set |
| 5 | Filter + sort | drive, folder, type (`KEY type_file`), status, owner |
| 6 | Mobile + RTL | tokens, `margin-inline-*`, rows stack under 480px, verified at 390px |
| 7 | Dark mode | tokens only, no raw hex |
| 8 | A11y | semantic markup, ARIA on icon-only buttons, keyboard-reachable tree |
| 9 | Empty / error / loading | every async surface |
| 10 | Caching | folder children and grant resolution cached per request, invalidated on write |
| 11 | Concurrency | `UNIQUE KEY name_in_parent`; already-deleted / already-moved handled gracefully |

### 12a. Measured, 2026-08-13 (P3.9)

The table above was reasoned, not measured, and said so. It has now been measured
against a **30,000-document fixture** across 25 drives, seeded with
`wp mvs seed-documents --members=25 --docs-per=1200 --depth=8` (105 s) and removed
with `--cleanup`. Everything below is a number taken off a live site, not an
estimate.

| Claim | Measured | Verdict |
|---|---|---|
| 1. Pagination | `drive_documents()` page 1: **2 queries, 6.6 ms**. Page 40 (deep offset): **2 queries, 6.9 ms** | **HOLDS** — flat with offset, which is the property that matters |
| 3. N+1 | `prefetch()` over a 20-row page: **1 query, 0.7 ms**. Then `can_view()` ×20: **0 queries, 0 ms** | **BETTER THAN CLAIMED** — the claim says 2 queries/page; it is 1 |
| 4. `COUNT(*)` | `count_documents()`: **1 query, 1 ms** over 30k rows | **HOLDS** |
| Rename/move batched above 5,000 | Move of a **5,100-folder** subtree: web request **13.9 ms / 11 queries**, folder left wearing `moving`, **1** Action Scheduler action queued, **0 of 5,100** children rewritten in-request. The deferred rewrite: **5,100 rows in 125.3 ms as ONE `UPDATE`**, status back to `active` | **HOLDS** — 125 ms of work moved out of the request |
| 2. Indexes | See correction below | **CLAIM IS STALE** |

**Correction to claim 2.** `KEY doc_listing` is **not** the drive query verbatim any
more, and is not the index the query uses. Both exist:

```
doc_listing    (media_type, folder_id, status, created_at)
drive_listing  (media_type, drive_type, drive_id, folder_id, status, created_at)
```

The drive query filters `drive_type` and `drive_id` — columns v29 added *after* this
claim was written — so the optimizer picks `drive_listing` (`type=ref`, 180 rows
examined out of 30,108, backward index scan). `doc_listing` is not a prefix of
`drive_listing`, so it is **not** automatically redundant: it still serves
drive-agnostic listings. Whether it still earns its place is a separate question
this measurement does not answer.

**Search: the pdf fixture could not measure it, so the seeder gained `--format=text`.**
`mvs_pro_document_search` held **104 rows against 30,108 seeded documents**, because
the seeder wrote PDFs and PDFs are deliberately not extracted (`ExtractionService`: a
PDF's text may be compressed, subsetted or outlined, and indexing plausible garbage
returns a confident wrong answer — Basecamp 10194833916). A pdf-only fixture writes
ZERO search rows, so it can never measure search.

`wp mvs seed-documents --format=text` now seeds extractable prose. Measured with it:

| | Measured |
|---|---|
| Extraction is **asynchronous** | 200 text documents queued **200** `mvs_pro_extract_document` Action Scheduler actions and wrote **no** search rows until the queue drained. A freshly seeded fixture has an empty search index until then — worth knowing before concluding search is broken |
| Draining the queue | 200 actions in **0.7 s**, `status = indexed`, ~1,345 extracted chars each |
| FULLTEXT query | **2.5–7.1 ms** for 20 hits, `EXPLAIN` confirms `media_content_ft`, `type=fulltext` |
| Through the REST route | `GET /documents/search?q=supplier` → 200 in **12.6 ms**, results scoped to what the caller may see |

One correction to my own first attempt, recorded because it is the trap: the column
is `search_text`, not `content`, and the doc type for a `.txt` file is `text`, not
`txt`. Passing `txt` refuses every row with `mvs_document_type_mismatch` — the ingest
guard doing exactly its job, and how the mistake was caught.

Claims 6–9 (mobile/RTL, dark mode, a11y, empty states) are UI properties. A query
harness cannot speak to them and this measurement does not pretend to; they need the
390px browser pass.

**Also confirmed:** the batching path depends on `function_exists( 'as_enqueue_async_action' )`.
Action Scheduler was a declared-but-never-loaded dependency in Free until 2026-08-13
(Basecamp 10194740839) — so on a Free-only site that guard was false and the move fell
through to the synchronous branch. That branch is one `UPDATE`, not a walk, and it
clears the `moving` flag itself, so the fallback is correct rather than broken.

---

## 13. Measured against Google Drive for a team

> **Read this as a checklist of collaboration behaviours users will expect, not as a product to
> clone.** Google Drive is a hosted service with a billing entity, an org directory and an admin
> console. This is a WordPress plugin: the site owner owns the disk, membership plugins own the
> tiers, and anything that would need a subsystem WordPress does not have gets a filter instead.
> Where a Drive behaviour does not map — team storage metering being the clearest case — it is
> **not adopted**, and the reason is recorded rather than the gap being left open.

Parity is wide — nested folders, per-file **and** per-folder grants with inheritance, link sharing
with expiry and revocation, full-text search inside files, trash/restore, comments, @mentions, bulk
ops, download tracking, a mobile client. **Ahead of Drive:** duplicate detection on `file_hash`, the
virus-scan seam, metadata stripping (Drive does not strip), and self-hosting — no third-party
processor, which for a team handling contracts is the entire reason not to use Drive.

**Five gaps would hurt a real team. One loses data.**

| # | Gap | What happens | Fix |
|---|---|---|---|
| T1 | **A departing member takes the team's files** | Files belong to the uploader and the `deleted_user` cascade purges them. Someone uploads 200 files to a Space drive, deletes their account, the drive loses all 200. **This is exactly why Google built Shared Drives** | A branch in the cascade: purge personal-drive documents, reassign team-drive ones. No schema |
| T2 | **Tightening a folder doesn't tighten its contents** | Folder goes private, the public documents inside stay public, silently | One indexed `UPDATE` (§5) |
| T3 | **Who may share is undefined** | The natural-looking choice — "edit implies share" — lets any editor mint an anonymous link to a contract | Drive owner / drive-admin only for v1. A fourth `manage` level fits the existing column later |
| T4 | **Space drives have no storage ceiling** | Google meters shared-drive storage against the org | **Not adopted — D2.** Google is a hosted service with a billing entity per workspace; a WordPress site owner owns the disk. Every upload counts against the uploading member's existing quota, which is what the membership adapters already meter. A per-Space cap is a filter, not a subsystem |
| T5 | **"Shared with me" surface** | A file shared from a private drive is unreachable if the notification is missed | Designed — §5. No schema |

**Deliberate divergences.** No version history (owner decision — but for teams this is the
most-used recovery path, so consider keeping the superseded file 30 days as an admin-restorable
undo: meta plus a cron sweep, no schema). One folder per file, no shortcuts. Fewer preview formats
— which is a feature, given why Office Online was rejected.

---

## 14. Build order

| # | Phase | Effort |
|---|---|---|
| 0 | ~~Decisions~~ **LOCKED — see §17.** No code | — |
| 1 | Query discipline: choke point, ~66-file audit, CI rule, journey — **before any document row exists** | ~2 d |
| 2 | Schema (Free v27, Pro v11) + `MediaTypes` + `get_media_type()` allowlist + legacy quarantine | ~1.5 d |
| 3 | Pro engine: `DocumentTypes`, upload path, `mvs_pro_folders` + `FolderService`, `PermissionService`, delivery, cloud + presigned + Site Health, WP-CLI seed | ~7 d |
| 3b | Team-drive correctness: deletion-cascade branch (T1), privacy cascade (T2), grant authority (T3), trash retention | ~2 d |
| 4 | REST + app contract, `/app/config`, ETags, Application-Password verification, `/me/shared` | ~3.5 d |
| 5 | Viewers — tiers 2/3/4 (tier 1 free) | ~4 d |
| 6 | Admin screen | ~1.5 d |
| 7 | Parity **verification** — social/GDPR/quota/moderation already work; prove it | ~1 d |
| 8 | Text extraction (async, via Action Scheduler following `StorageRepairService`) + search | ~3 d |
| 9 | Frontend: drive, single view, overlay, share modal, three virtual roots | ~5 d |
| 10 | Interlinking | ~2 d |
| 11 | Space drives | ~2 d |
| | **Total** | **~34.5 d** |

**Phase 1 ships before any document row exists** — the media-grid guarantee is a query guarantee
now, so the choke point and CI rule land while there is nothing to leak.

**Phase 0 is closed.** All eight decisions are locked in §17 (2026-08-08). They changed what
`PermissionService` and the upload path do, which is why they had to be settled before Phase 3
rather than during it.

**Nothing member-visible ships before Phase 9.** Phases 1–8 leave the feature reachable by API and
admin only — the half-cooked state Coding Rule 18 forbids.

### The v1 cut

~34.5 d is a large surface to hold open. **Ship personal drives first:** phases 0–10 minus Space
drives — personal drive, sharing, REST, viewers, search, frontend, interlinking. **Phase 11 (Space
drives) is the follow-on.** The schema carries `drive_type` / `drive_id` from day one, so nothing
has to be rebuilt; only the resolver and BN's bridge land later. That also defers the two
team-drive questions (T1 reassignment, T4 quota) to the phase that actually needs them.

**`search_text` lands in the schema at phase 2; extraction lands at phase 8.** Between those, the
column exists and is empty. Do not let the UI imply search works at first enable — the search box
should be absent, or say "indexing" until extraction has run. A search that silently returns
nothing reads as broken.

---

## 15. Release blockers

Team review, 2026-08-08: these are **release blockers, not polish**. A build that ships without any
one of them is not shippable, regardless of what else is done.

| | Blocker | Why it is not optional |
|---|---|---|
| **T1** | Departing member: purge personal-drive documents, **reassign** Space/site-drive ones | Otherwise a member leaving takes the team's files with them. Silent, permanent, triggered by a routine event. **Scoped to Phase 11** — see below |
| **T2** | Tightening a folder's privacy cascades to its contents; loosening does not | Otherwise a folder set to private leaves its documents public, with nothing to indicate it |
| **Journey** | One executable journey: a document appears in the drive and in **no** media surface — grid, explore, album, collection, lightbox, activity | This is what replaced the structural guarantee. Without it the query discipline has no regression net |
| **Breadcrumb** | Shared view starts at the highest granted ancestor; owner folder names above the grant point are never emitted | Folder names carry client identities and project codenames. This is an information leak, not a display bug |
| **Storage privacy** | Local deny rules **and** a Site Health check that the cloud bucket is not public-read | The difference between "documents are private" being true and being merely intended |

**T1 blocks Phase 11, not v1.** Under the v1 cut (§14) there are no Space or site drives, so there is
nothing to reassign — a departing member's documents are all personal, and purging them is the
existing media-cascade behaviour. T1 becomes a blocker the moment team drives ship, and must land in
the same phase they do. The other four block v1.

---

## 16. Verification (blocking)

- **No explicit `media_id` is ever inserted**; creating a folder writes **zero** `mvs_media_index` rows
- **The `media_type=''` row stays out of both libraries**; no list query uses `media_type != …`
- **No document in any media surface** — explore grid, album, collection, lightbox, BP activity,
  `/media`, `/me/media`, Instagram layout, leaderboard, challenges, stories, tournaments
- **Zero folder rows** on a 1,000-member fixture where nobody created a folder
- **`name_in_parent` holds under concurrency** — two simultaneous "Invoices" produce one row
- **`token_hash` UNIQUE add succeeds** on a table with existing grant rows
- **`DocumentTypes::resolve()` returns `null`** for every media MIME and for junk
- **`.docx` renamed `.zip` rejected; `.zip` renamed `.docx` rejected** — both directions
- Declared `doc_type` disagreeing with the resolved type → `400`, never a silent fix
- Legacy `.doc`/`.xls` round-trip byte-identical
- **Markdown XSS**: an `.md` containing `<script>`, `<img onerror>` and a `javascript:` link renders inert
- **CSV with 50,000 rows** renders the first 500 with an honest footer, does not hang
- **`/preview` refuses every non-PDF raw type**
- **Lazy bundles absent from the main page load** — verified in the network panel, not the build config
- **Preview failure never blocks download** — corrupt a `.docx`, confirm card + working download
- **Breadcrumbs truncate at the grant point** — a member granted `/Contracts` never sees an ancestor name in any response
- **A departing member does not take the team's files** (T1) — seed both drive types from one uploader, delete the user
- **Tightening cascades; loosening does not** (T2)
- **Direct-URL fetch of a stored file returns 403/404 on both Apache and nginx**
- Permission matrix per role × drive type × grant type — owner, shared-user, shared-role, link, anonymous, non-member, logged-out
- **Every action drivable with an Application Password alone** — no cookies, no nonce
- 2000-document drive + 20-level tree: listing paginated, query count flat
- **390px browser verification of every screen**, per item, not batched

---

## 17. Phase 0 decisions — LOCKED 2026-08-08

Owner sign-off. These were the five that change `PermissionService` or the upload path, so deciding
them mid-build would mean rewriting Phase 3. They are decisions now, not recommendations — build
against them.

### D1 — Only the drive owner or a drive-admin may grant access

`view | comment | edit` say what a grantee can do with the item. **None of them confer the right to
hand access to someone else.** Granting, and minting a link, require drive ownership,
`manage_mvs_documents`, or a Space role BN resolves as owner/moderator.

*Why:* the natural-looking alternative — "edit implies share" — means any editor can mint an
anonymous link to a contract. On a Space drive with dozens of members that is one bad day away from
an incident. Google separates these roles for the same reason, and most Workspace admins turn
editor-sharing off.

*Implementation:* one check in the permission callback for `POST /documents/{id}/permissions` and
`…/permissions/link`. A fourth `manage` level fits the existing `permission varchar(10)` column
later without a migration — **widening is additive; narrowing takes something away from people who
had it**, so start narrow.

### D2 — No separate drive quota. Every upload counts against the uploader

**Corrected 2026-08-08.** An earlier draft proposed a drive-level allowance keyed on
`(drive_type, drive_id)`, possibly with its own table. That was SaaS thinking — metering a workspace
tier — and it does not belong in a WordPress plugin.

`QuotaService` is per-user **by design**: it exists so a site owner can tie upload allowance to a
membership level, which is exactly what the MemberPress / PMPro / WooCommerce adapters in
`Integrations/` do. A Space is not a billing entity. There is no WordPress concept a drive-level
allowance would map to, and inventing one means a new table for a limit nobody asked for.

**One pool, all content types.** Owner, 2026-08-08: *"it does not matter if it's media, video or
documents — all update the same current member quota."*

A document upload increments the same `_mvs_storage_used` user meta an image or a video does, on the
same member, whichever drive it lands in. There is no document pool, no drive pool, and no new
counter.

**No `document_count`.** `QuotaService::get_usage()` also tracks `image_count` / `video_count` /
`audio_count`, and `deduct_credit()` is keyed by media type. Documents deliberately consume
**storage only** — adding a fourth counter would mean a schema change to `mvs_quota_packages` for a
per-type limit nobody has asked for, and the storage ceiling already caps abuse. If per-document
limits are ever wanted, add the column then.

The practical effect is what a member already expects: one number, one bar. The profile storage line
reads "2.1 GB of 5 GB used" across everything they have uploaded, because that is how a quota is
actually experienced. Nothing new to store, nothing new to configure, and the MemberPress / PMPro /
WooCommerce adapters keep working untouched.

One consequence to design for rather than discover (scale review, 2026-08-08): with no
`document_count`, the storage bar cannot answer *"what is using my 5 GB"* by type — and on any site
with a quota, that is the first support question after documents launch. The answer is an on-demand
`SELECT media_type, SUM(file_size) … GROUP BY media_type` behind the bar's "what's using this?"
expander, indexed by `KEY type_file`, computed when clicked and never stored. No counter, no schema —
the decision above stands; the breakdown is a query, not a ledger.

*The abuse hole this closes anyway:* every upload is charged to a real person at the moment it
happens. **T1's** reassignment only moves *ownership of the file* when a member is deleted — long after
the storage was accounted for — so it opens no gap.

*If a site owner does want to cap a particular Space,* that is a filter
(`mvs_document_upload_allowed`, returning `WP_Error`), not a schema feature. Ship the seam, not the
subsystem.

### D3 — Replace keeps the superseded file for 30 days, admin-restorable

No version history stands. But "replace" is currently the only irreversible action in the product,
and *"the edit was wrong, give me yesterday's file"* is the case teams hit most.

On `POST /documents/{id}/replace`, the superseded file stays on disk under a `_mvs_replaced_from`
meta key with a timestamp, swept by cron after 30 days (filterable). An admin can restore it. **This
is an undo, not versioning** — one step back, no history UI, no version table, no schema.

The sweep queries `mvs_media_meta` **by `meta_key` with a value-range condition on the stored
timestamp**, not by scanning for the key alone (scale review, 2026-08-08): a bare meta_key lookup is
a full-meta-table scan pattern on a site with millions of meta rows. The timestamp is stored as the
meta value's leading sortable component (`YYYYMMDDHHMMSS|<path>`) precisely so the sweep's WHERE can
bound it, and each sweep run caps at 500 deletions — the same bounded-cron shape
`StorageRepairService` uses.

*Why:* it removes the only unrecoverable member action for the cost of a meta key and a cron sweep.
Adding real versioning later still needs a Migrator bump and a minor release; this does not.

### D4 — Anonymous links are disabled whenever private-community mode is armed

`CommunityPrivacyGate` 401s the whole namespace when `mvs_rest_require_auth` is on, and BuddyNext
arms it. So on a private BN community — the only stated consumer — an anonymous link cannot work
regardless of what the setting says.

**When the gate is armed, the anonymous option is absent from the share modal**, not
present-and-broken, and the site setting reads as unavailable with the reason stated. No exemption
is punched through the gate.

*Why:* exempting the redemption route would open the one hole through a gate whose entire purpose is
"no unauthenticated reads". A control that is visibly unavailable with a reason beats one that
silently fails.

**Pinned by a test, because "just exempt the new route" is what a future fix reaches for.** When the
redemption route lands, a test asserts it is **not** in `mvs_rest_gated_route_prefixes`' exempt set
while `mvs_rest_require_auth` is armed. This is not hypothetical: the sibling BuddyNext gate shipped
exactly that hole for its PWA and payment routes. The guarantee here rests entirely on the gate
staying closed, so the thing that would quietly open it is the thing to pin.

### D5 — Rate limiting on link redemption ships with anonymous links, or anonymous links do not ship

`RateLimiter` exists in Free and has **zero call sites in Pro**. Anonymous link redemption is an
unauthenticated exact-match lookup on `token_hash` — hashing protects a database leak, and does
nothing against guessing.

**`RateLimiter::check()` on the redemption route is a hard prerequisite for D4's feature.** Also
metered: upload, download, and tier-2 preview (which runs server-side parsing per request and is
cheap to spam).

### D6 — Documents reuse `mvs_tag` and `mvs_category`

With one ID space there is no correctness argument for separate vocabularies, and one vocabulary
makes unified search (§10) simpler. Revisit only if a shared tag cloud proves noisy in practice.

### D7 — Gated streaming is the default; presigned CDN delivery is opt-in

**Corrected 2026-08-08.** An earlier draft made presigned CDN URLs the default and counted downloads
at mint time, calling the metric "URLs issued, not bytes delivered". That is a hosted-service
answer. A WordPress plugin has to work on shared hosting with no cloud configured at all.

**Default: the gated endpoint streams the file**, exactly as `/serve` already does for private
media. It needs no cloud configuration, re-checks permission on every request, and records an honest
download in `mvs_media_views` (`event_type='download'`, a value the column already carries).

**Presigned CDN delivery is opt-in**, for owners who have cloud storage configured and want the
bandwidth off their origin. Turning it on carries a documented trade, stated in the setting itself:
permission is evaluated once at mint rather than per request, and downloads served from the edge are
not counted.

*Why this way round:* the default should be the one that works everywhere and tells the truth. An
owner who opts into CDN delivery has made an informed trade; an owner on shared hosting should never
have to discover that their download numbers are approximate because of a default they did not
choose.

### D8 — Documents get their own private bucket, and cloud for documents is opt-in

**They cannot share the media bucket. This is a technical constraint, not a preference.**

Public media is served from cloud by **direct CDN URL** (1.4.0). For an unsigned URL to resolve, the
bucket or zone must be **publicly readable**. Documents must be **privately** readable — every
delivery goes through the gated endpoint or a short-lived signed URL (D7).

Those are contradictory bucket-level policies. Put documents in the media bucket and every document
is world-readable, protected only by an unguessable path — strictly weaker than the local driver,
which has `Deny from all` **and** a random segment. The plan already refuses to rely on obscurity
alone for local storage; it cannot quietly accept it for cloud.

| | Media bucket | Document bucket |
|---|---|---|
| Public read | **required** — direct CDN URLs | **must be off** |
| Delivery | unsigned CDN URL for public items | gated stream, or opt-in presigned |
| Site Health assertion | n/a | **must not be public-read** |

### Configuration shape

Reuse the configured provider and credentials — most owners run one. Add only the target:

- `mvs_pro_documents_cloud_enabled` — default **off**
- `mvs_pro_documents_bucket` — bucket / storage-zone name, required when enabled
- **Refuse to save a value equal to the media bucket.** That single validation prevents the exact
  misconfiguration this decision exists to avoid, and it is cheaper than detecting it later.

**Default is local.** With cloud disabled, documents stay on local disk behind the existing deny
rules — which works on shared hosting with no cloud account at all. Cloud is a deliberate opt-in,
and enabling it requires naming a second bucket, so the owner cannot arrive there by accident.

### What a second bucket costs

| Provider | Extra bucket / zone | Note |
|---|---|---|
| Cloudflare R2 | **free** | Buckets are free; private by default, which is the wanted posture |
| AWS S3 | **free** | Billed on storage + requests only |
| BunnyCDN | **free** | Storage Zones cost nothing; simply do not attach a Pull Zone |
| DigitalOcean Spaces | **~$5/month** | Spaces are billed per Space — the one provider where this is a real line item |

So on three of four supported providers a second bucket is free, and the fourth should be told
plainly in the setting description rather than discovering it on an invoice.

### Sizing

Documents are small next to media. A 500-member community with heavy document use — say 40 files
per active member at an average 800 KB (PDFs and Office files; scanned PDFs skew higher) — is
**~16 GB**. Against R2 or S3 pricing that is single-digit dollars a month; against a Space it fits
inside the base $5. Storage cost is not the deciding factor here. **Bucket access policy is.**

---

## 18. Still delegated to BuddyNext

Not blocking. MediaVerse holds an opaque drive id and asks; it does not need the answers to build.

1. Does every Space get a drive automatically, or does a Space owner enable it?
2. Do `secret` Spaces get drives at all? (If so, an unauthorized caller gets **404, never 403**.)
3. Does a child Space inherit its parent's drive?
4. Does `moderator` imply `edit`? May a plain `member` upload, or only read?

MediaVerse's only obligation is that the filter contract can express whatever BN decides — which is
why `mvs_document_drive_access` takes `($drive_type, $drive_id, $user_id)` and returns a permission
rather than a boolean.

---

# Part II — absorbed 2026-08-11

Everything below folded in six separate documents. §1–§18 above are the design and are unchanged.

---

## 19. What shipped in 2.4.0

Personal document drives, complete. Absorbed from `document-library-build.md` (phases P1–P11) and
`document-library-remaining.md` (tasks 0–10), both of which carried stale status tables — the work
below is what is actually in the code, verified by cert and browser walk.

### The engine

| Piece | Where |
|---|---|
| `DocumentTypes` — 11 named types, resolved from MIME + extension + content sniff, no catch-all | Free `Core/DocumentTypes.php` |
| `FolderService` — create, rename, move, trash, restore; `paths_for()` resolves a page of paths in two queries whatever the row count | Pro `Documents/FolderService.php` |
| `PermissionService` — `can_view/can_edit/can_share/owns_drive/grant/revoke/grants_for/drive_of` | Pro `Documents/PermissionService.php` |
| `DocumentIngestService` — one ingest path, used by REST and by the CLI seeder | Pro `Documents/DocumentIngestService.php` |
| `ExtractionService` — text extraction into `mvs_pro_document_search`, a row for EVERY outcome including `unsupported` | Pro `Documents/ExtractionService.php` |
| Storage guard — `.htaccess` + nginx rule + a canary that ASKS THE SERVER over HTTP | Pro `Documents/` |
| Free Migrator v27 (`folder_id` on `mvs_media_index`), Pro v11 (`mvs_pro_folders`), Pro v12 (`mvs_pro_document_search`) | both Migrators |

### The member's drive

Folders with upload in place; per-row rename, move, privacy, download, trash and restore; a
"Shared with me" band plus its full view; targeted sharing to a member or a role with revoke;
in-drive search across the extracted text; multi-select bulk move; a Location column; a folder
header carrying the folder's own actions; and filter/sort/view state that survives opening a
folder, paging and following a breadcrumb.

Three of these were **activation, not construction** — restore, the shared band and targeted
sharing were each already written, guarded and unreachable from any UI. Expect that pattern
elsewhere.

### Admin

A Documents list screen with search, type and privacy filters and working sort; row actions
Edit / View on site / Download / Trash / Delete permanently; and a single view/edit screen at
`?page=mvs-documents&view=single&media_id=N` writing through the SAME `set_many()` +
`generate_unique_slug()` + `wp_set_object_terms()` the REST path uses, so the screen cannot drift
from the API. Its guards: `is_document()` on both the view and the save, an empty title refused
rather than stored, and **the slug is never regenerated from the title** — a member fixing a typo
would otherwise break every link they had shared.

**Task 9, an admin folder LIST, was built and then WITHDRAWN (2026-08-10).** Members reuse folder
names, so a site-wide list is a page of "Contracts", "Contracts", "Contracts". The owner's real
questions — where does this document live, who can see it — are answered on the member's own drive
and in the document's row. A deliberate exception to entry-point rule 18, recorded at the
registration site so an audit does not re-add it. Folder management stays reachable through the
REST folder routes.

### Release blockers from §15, all closed

D5 rate limiting; the D4 pinning test (the link-redemption route never joins
`CommunityPrivacyGate`'s exempt set while the gate is armed); honest cloud-storage reporting; the
`mvs_document_scan_file` seam; metadata stripping on ingest; and the P1.5 containment journey
(`audit/journeys/security/07-document-never-in-media-surface.md`), which is a release blocker in
its own right after a quarantined PDF was screenshotted rendering as a broken tile in Explore.

---

## 20. What is PENDING

Read this section before planning any further document work. Ordered by what blocks what.

### 20.1 Space / site drives — ENTIRELY PENDING

**Nothing in §23 is built.** Personal drives are v1 and shipped; Space drives are the whole of
Phase 11 and have not been started. The gap analysis is §23; the anti-patterns that must not be
used are §23.7. Summary of what does not exist:

| | State |
|---|---|
| Document→drive binding (`drive_type` / `drive_id` on the document) | not built — a Space-root upload would file into the uploader's PERSONAL drive |
| `can_write_drive` — contribute distinct from own | not built — `owns_drive()` returns `false` by default for `space`/`site`, and a bool cannot express "may contribute" |
| `GET /drives` | route does not exist |
| `?drive=space:N` on the documents list | param does not exist — the list is author-scoped only |
| BuddyNext bridge document hooks | `WPMediaVerseBridge.php` has none |
| Privacy token `space` | not wired; `group` remains BuddyPress-only |
| T1 departing-member reassignment | not built. Blocks Space being shippable, not personal |

### 20.2 Pending on the personal drive (not blocking release)

**Re-verified against the code on 2026-08-11**, because the build plan this list came from carried
stale statuses in both directions. What each claim was checked against is stated, so the next
reader does not have to re-derive it.

- **P1.2 call-site migration for `mvs_media_index`** — Rule 7 is landed (see DONE list below) but
  still runs as `known_gap()`, not a hard `violation()`. Remaining open sites (re-verified
  2026-08-11 in §24.2 item 1): Free 24 files / 66 call sites, Pro 4 files / 6 call sites. Until
  those clear and Rule 7 is promoted to `violation()`, §8's structural guarantee is visible in CI
  but non-blocking.
- **Scale fixture** (P3.9) — a seeded 30k-document drive. Every big-site claim in §12 is reasoned,
  not measured.
- **Real-customer-DB pass on Migrator v27** (P2.2). Applied and verified on dev data only.
- **~39 pre-existing Pro unit failures** from cross-test pollution in the gamification suites.
  *Verified not ours:* `BoostServiceTest` run alone reproduces the identical
  `DeliveryControllerTest` failures, and every affected suite passes in isolation. No CI gate runs
  phpunit in either plugin.

**Claimed pending by the old build plan, but actually DONE** — recorded so nobody rebuilds them:

- **CI Rule 7 — ban on direct `FROM mvs_media_index` outside `MediaRepository` (P1.3/P1.4).**
  *Verified done 2026-08-11 (corrected §20.2 same day — an earlier pass of this section claimed
  the rule did not exist; it does):* both plugins' `bin/coding-rules-check.sh` carry Rule 7, with
  detector `bin/lib/detect-media-index-leaks.py` and mutation test `bin/mutation-test-rule7.sh`.
  Currently `known_gap()` (tracked, non-blocking). What remains pending is only the P1.2 migration
  listed above, then promote to `violation()`. Full allowlist + open call-site lists: §24.2 item 1.
- **P1.6 `AdminAggregatesService` counts documents separately.** *Verified done:*
  `total_documents()` exists with its own `admin_total_documents` cache key, and its docblock names
  the bug it fixed (a site whose members had uploaded 400 documents saw them added to "Total Media"
  on a screen where no document was reachable).
- **Task 10, the admin single-document view.** The remaining-UI plan said "NOT yet built"; it
  shipped, with guards — see §19.

### 20.2b No dead or duplicated document code

*Verified 2026-08-11:* all 27 classes in `wpmediaverse-pro/includes/Documents/` are referenced from
at least one other file, and the five with a single reference each resolve to a real call site
(`AdminDocumentPanels`, `DocumentShortcodes` and `HealthCheck` are constructed in
`Core/Plugin.php`; `MetadataStripper` is called from `DocumentIngestService`; `SpreadsheetPreview`
from `OfficePreviewRenderer`). Nothing in the namespace is orphaned.

This is worth re-checking after any document work, because three features in this release —
restore, the shared band and targeted sharing — turned out to be **already written, guarded, and
unreachable from any UI**. Code existing is not the same as code being wired, and the failure mode
here is building a second copy of something that is already there.

### 20.3 Pending in QA

- Firefox and Safari desktop, and Safari iOS at 390px — the tooling is Chromium-only.
- AI, S3 and Whisper smoke rows — external credentials.
- An **active tournament** fixture. Only a finalized one exists, so active-tournament rows have
  never been walked. Not seeded, per the no-self-seeding guardrail.

---

## 21. Owner settings and the role gate — shipped

Absorbed from `document-settings.md`, corrected to what was built. That document said "planned, not
built" and described one toggle; seven controls shipped.

### The controls

| Control | Absent reads as | Notes |
|---|---|---|
| Enable Documents | **ON** | Registration-gated: `Plugin::init()` decides at load whether to call `init_document_surfaces()` |
| Who can use documents | every role | **A capability, not an option** — see below |
| Maximum document size | `0` = follow the server | MB, clamped to `wp_max_upload_size()`. Deliberately independent of the media limit |
| Allowed file types | absent = every type | absent and EMPTY differ: empty is a saveable "accept nothing". Intersected with `DocumentTypes::ALL` at read AND write |
| New documents start as | `private` | Validated against `PRIVACY_VALUES` |
| Anonymous share links | **OFF** | Checked on redemption as well as minting, so switching off closes links already issued |
| Search inside documents | **ON** | Memoised across five guard sites per request |

**Resolution order is the contract: option first, filter LAST.** A site already scripting
`mvs_document_max_size` and friends keeps winning over the screen — otherwise upgrading into the
settings silently reconfigures their site.

The original directive ("controls a normal site does not change belong in filters") was read too
literally and produced a screen with one checkbox on it. The directive is about controls nobody
touches, not about decisions an owner genuinely makes and would otherwise have to write PHP to
express. Six were the second kind.

**Still filters, deliberately:** `mvs_document_max_depth` and `mvs_document_strip_metadata`. Both
have exactly one sensible value, and the non-default of the second is a downgrade nobody should
reach by clicking.

### `use_mvs_documents`

A capability, because the same permission is grantable from Settings → Permissions and two stores
for one permission is two screens that will eventually disagree. It answers "may this member have a
document library at all" — distinct from `upload_mvs_media` (may they upload) and from privacy (may
they read this file).

- **Granted to every role by default**, including roles BuddyPress and WooCommerce register, via
  `MediaCapabilities::get_base_member_caps()`. It is introduced on an already-shipped feature; a
  default-denied capability would empty every existing member's drive on update.
- Both screens write it through `MediaCapabilities::apply_role_caps()`, which also records the
  choice so `add_caps()` on a version bump cannot undo a revocation.
- `mvs_user_can_use_documents( $can, $user_id )` is the per-user seam — the one a membership plugin
  uses to put documents behind a paid tier without touching WordPress roles. Runs last, so it
  widens as readily as it narrows.
- **It never gates a READ.** A logged-out visitor and a capped-out member must both still be able
  to open an already-public document; that is `PermissionService::can_view()`'s decision. Gating
  reads would make a public document visible to a visitor and invisible to a member.

### The privacy vocabulary, corrected

`PRIVACY_VALUES` is `private | members | public`. Until 2026-08-11 four places disagreed: the
constant declared `private|members|unlisted` while `DriveActions` (twice) and the drive's row
dropdown wrote `private|members|public`. Nothing ever wrote `unlisted`; `public` was written and
undeclared. `DocumentController` was validating against MEDIA's vocabulary and so accepted levels no
document ladder can honour. One list now, plus `privacy_labels()` and `is_valid_privacy()`, read by
every consumer.

---

## 22. The BuddyNext REST contract

Absorbed from `2026-08-11-document-rest-contract.md`. BuddyNext consumes `mvs/v1` and `mvs-pro/v1`
and nothing else — no shared PHP, no template overrides, no direct table reads. So every rule has to
be **discoverable** through the API, not merely enforced behind it.

### Four layers, asked in this order

| # | Layer | Question | Decided by | State |
|---|---|---|---|---|
| 1 | Feature access | May this account have a library at all? | `use_mvs_documents` → `Plugin::user_can_use_documents()` → `mvs_user_can_use_documents` | **shipped** |
| 2 | Drive authority | May they read / write / administer THIS drive? | `owns_drive()`; needs a write level (§23 G2) | personal only |
| 3 | Privacy | May they read THIS document? | `PermissionService::can_view()` + grants | shipped |
| 4 | Ownership | Whose upload is this, for quota / GDPR? | `post_author`, always a real person | shipped |

The order is load-bearing. Layer 1 runs before any drive is resolved, which is what lets it refuse
without leaking whether a Space exists. Layer 2 must never answer layer 1 — a bridge answering the
drive filters must not be able to re-grant a member the feature their role switched off.

**Layer 4 is never a scoping mechanism.** `post_author` is the uploader. It is *currently* also how
personal root documents are scoped (`folder_id = 0 AND post_author = N`), and that coincidence is
exactly §23 G1.

### Refusal codes

A client cannot behave well if every failure is a 403. Freeze this before BN builds against it.

| Situation | Status | Code | Client should |
|---|---|---|---|
| Not signed in | 401 | `mvs_unauthorized` | send to sign-in |
| Signed in, documents not available to this account | 403 | `mvs_documents_unavailable` | **hide the tab.** Do not retry, do not offer sign-in |
| Site toggle off | route absent | `rest_no_route` | hide |
| Drive not visible (incl. secret Space) | **404** | `mvs_drive_not_found` | treat as no such drive |
| Drive visible, read-only | 403 | `mvs_drive_read_only` | show library, hide upload |
| Document not readable | **404** | `mvs_document_not_found` | treat as missing |
| Document readable, not editable | 403 | `mvs_document_forbidden` | show, hide edit |
| Type refused | 400 | `mvs_document_type_not_allowed` | name the type |
| Too large | 400 | `mvs_document_too_large` | check `documents.max_size` first |
| Link sharing off | 403 | `mvs_link_sharing_disabled` | hide the option |

**403 vs 404 is not cosmetic.** 403 means "exists, not for you"; 404 means "you may not know
whether it exists". Feature access is 403 because the account's own permission is not a secret from
the account. A secret Space is 404 because its existence is the secret.

### Filter families — keep them separate

Feature access (Free, shipped): `mvs_documents_enabled`, `mvs_user_can_use_documents`.

Drive authority (Pro + bridge, **to freeze**): `mvs_document_drives_for_user`,
`mvs_document_drive_access` (returns `none|read|write|own`, **not** a bool),
`mvs_document_drive_label`. Existing `mvs_document_owns_drive` and `mvs_document_can_grant` become
derived, kept ≥2 majors per Production Rule 2.

### `/app/config` is the discovery surface

`GET /mvs/v1/app/config` → `documents` reports `enabled` (**per user**), `max_size`,
`allowed_types`, `allowed_mimes`, `default_privacy`, `anonymous_links`, `preview_tiers`,
`max_folder_depth`, `search`. **Every value is asked of the resolver that enforces it.** Four were
hardcoded or read from the wrong source and all four were fixed in 2.4.0 — config that disagrees
with enforcement produces a client that draws a control and then meets a refusal.

---

## 23. Space / site drives — the gap analysis (NOTHING HERE IS BUILT)

Absorbed from `2026-08-11-document-space-association-plan.md` and Basecamp cards 10189637847
(Scope) and 10189650164 (Contract). **Personal drives are v1 and shipped. This entire section is
Phase 11 and has not been started.** Every claim below was re-verified against the code on
2026-08-11.

BuddyNext will render Space Documents tabs from the REST API. Shipping those tabs before the gaps
below are closed would mis-file every Space root document into the uploader's personal drive, and
would conflate "owns the Space" with "may contribute a file".

### 23.1 The model

| Layer | Storage | Meaning |
|---|---|---|
| File | `mvs_media_index` | `media_type = document`. `post_author` = **who uploaded**, always a WP user. `folder_id` = container, 0 = drive root |
| Folder | `mvs_pro_folders` | `drive_type` + `drive_id` = **whose library**. `created_by` = who made the row |
| Grant | `mvs_access_grants` | who else may view/edit beyond privacy |

**Personal (shipped):** folder is `drive_type = user`, `drive_id = <user_id>`. A file in a folder is
scoped by the folder. A file at root is `folder_id = 0 AND post_author = <user_id>`.

**Space (not built):** folder would be `drive_type = space`, `drive_id = <bn_space_id>`. A file at
root needs an **explicit drive key on the document** — the same job `_bn_space_id` does on a
BuddyNext album. The uploader stays `post_author`, always.

### 23.2 The gaps

**G1 — Space root documents have no Space binding (P0).** Personal root listing is
`folder_id = 0 AND post_author = %d`. A document uploaded to a Space root today stores only
`post_author`, so it would appear in the **uploader's personal drive**, not the Space library.
Needs a document-level drive association. **Recommendation: columns on `mvs_media_index`
(`drive_type`, `drive_id`) with a Migrator bump, not post meta** — every listing this feature needs
is drive-scoped, and drive-scoped listing through post meta is a join that degrades exactly as a
Space library grows. Albums use meta because albums are `wp_posts` and few; documents live in
`mvs_media_index` and are not. Backfill personal drives in the same migration so root listing has
ONE code path rather than a personal branch and a Space branch that will drift.

**G2 — the write gate is `owns_drive`, not contribute (P0).** *Verified:*
`PermissionService::owns_drive()` returns `$drive_id === $user_id` for `user`, and for anything else
falls through to `mvs_document_owns_drive`, **default false**. A bool cannot express "may
contribute but does not own". Needs a separate write level — `mvs_document_drive_access` returning
`none|read|write|own` — used by ingest, move and folder-create, with `owns_drive` / `can_grant`
kept for admin and sharing.

**G3 — the list API is personal-only (P0).** *Verified:* there is no `/drives` route and no `drive`
parameter anywhere in `REST/DocumentController.php`. Needs `GET /drives` and
`?drive=space:N`, with root listing keyed on the drive rather than the author.

**G4 — the BN filter contract is not frozen or implemented (P1).** *Verified:*
`buddynext/includes/Bridges/WPMediaVerseBridge.php` contains **no document-drive hooks at all**.
Freeze the names in §22, implement in Pro, wire the bridge. A non-member of a secret Space gets
**404, not 403**.

**G5 — departing member (T1) (P0 when Space ships).** §15: purge personal documents, **reassign**
Space documents. Not a v1 blocker; blocks Space being shippable.

**G6 — privacy token `space` (P1).** Confirm `PrivacyService` resolves BN Spaces rather than only
`groups_is_user_member()`. `group` stays BuddyPress-only.

### 23.3 Anti-patterns — frozen, do not do these

From the contract card, and all five are right:

1. **Never** write a BN `space_id` into media meta `group_id` or album `_mvs_group_id`. Those are
   BuddyPress, they die with BuddyPress, and an importer would read them as BP groups.
2. **Never** set `post_author` to a Space id. It breaks GDPR purge, quotas, "my uploads" and every
   author-scoped query.
3. Space root documents need an explicit drive key — the album-meta equivalent, not a reused field.
4. Privacy `group` stays BuddyPress-only; Spaces use `space` plus the BN filters.
5. MediaVerse never queries `bn_*` tables. The bridge answers filters.

### 23.4 How today's role gate interacts

The 2.4.0 gate is **feature access** (layer 1 in §22) and closes none of G1–G6. It is asked BEFORE
`owns_drive`, so when G2 replaces that check the first is untouched — the layering §22 asks for,
arriving one layer early. Three consequences for whoever builds this:

- `mvs_user_can_use_documents` is **not** a drive filter. Freeze it separately, or answering
  `mvs_document_drive_access` becomes a way to re-grant a member the feature their role switched off.
- The new refusal is **403 `mvs_documents_unavailable`**; a secret Space must be **404**. Both are
  right at different layers, and the feature gate fires before any Space is resolved, so it cannot
  leak a Space's existence. Do not collapse one into the other.
- A Phase C test for "non-member sees 404" must be run by a member who HAS the capability, or it
  passes for the wrong reason.

### 23.5 Sequencing

**PR1 is contract-only and must go first** — refusal codes (§22) and filter names, no schema. They
are the only part BN cannot work around later, because their error handling and tab logic get
written against them on day one and every change after is a coordinated release.

| PR | Content | Blocks BN? |
|---|---|---|
| 1 | Refusal codes + filter names frozen; consumer note published | **yes, first** |
| 2 | `drive_type`/`drive_id` columns + Migrator + ingest stamp + root listing (G1) | yes |
| 3 | `can_write_drive` (G2), `GET /drives` + `drive` param (G3) | yes |
| 4 | BN bridge answers the filters (G4) | — |
| 5 | T1 reassignment + Space privacy clamp (G5, G6) | before Space is shippable |

### 23.6 Acceptance criteria

- Document responses expose `author` (uploader), `folder`, and `drive` `{type, id}`.
- Upload into a Space folder: the file's drive is that Space; `post_author` is the uploader.
- Upload into a Space root: listed under the Space library, **not** the uploader's personal root.
- A Space member with a contribute role can upload; a non-member cannot; owner/moderator manage
  sharing per BN rules.
- `owns_drive` is **not** the sole upload gate for Space.
- `GET /drives` returns personal drives plus the Spaces BN exposes for that viewer.
- Secret Space: unauthorized caller gets **404**.
- Personal-drive behaviour for existing clients is unchanged — regression suite proves it.
- Creating a Space document leaves `_mvs_group_id` and media `group_id` empty; the drive key holds
  the space id.

### 23.7 Non-goals

MediaVerse Space Documents UI (BN owns tabs and views); changing personal-drive v1 behaviour for
existing BN and app clients; setting `post_author` to a Space id; an admin folder list (withdrawn,
see §19).

---

## 24. Implementation plan — re-verified against the running code, 2026-08-11

Every pending item below was re-checked against the code on disk on this date, not read off an
earlier claim, precisely because §20.2 already caught two cases where an older plan carried a stale
"pending" status on work that had actually shipped. This section is the record of that recheck.
Read it before starting any of this work — it exists so the next person does not re-derive it.

### 24.1 What re-verification changed

- **§20.2's "~40 Free files" was itself a naive substring count and overstated the real number by
  roughly 3x. Corrected twice in one afternoon (2026-08-11), worth recording precisely because this
  is exactly the "what's real" confusion this section exists to stop:**
  1. A first re-check (`grep -rl mvs_media_index ... | wc -l`) got **50** Free / **21** Pro — still a
     naive substring count, just a more current one. Any docblock mentioning the table name in prose
     inflates this number; it is not a violation count.
  2. Building the actual CI gate (item 1 below) with a precise detector — matching real `$wpdb->`
     query calls, not bare mentions — cut that to **12 Free files / 0 Pro files**. Zero Pro hits
     turned out to be wrong too: the detector only matched the table name *inside* a `$wpdb->` call's
     parens, and missed the equally common `$index = $wpdb->prefix . 'mvs_media_index'; ...
     {$index}` shape — exactly what `Pro\Leaderboard\LeaderboardService.php` does, with its own
     `phpcs:ignore` comment showing the direct query was a known, deliberate choice, not an
     oversight.
  3. Revising the detector to match the table-name *construction* itself (inline or via a variable),
     not only calls it appears directly inside, landed on the number now mutation-tested and wired
     into both plugins' `bin/coding-rules-check.sh` as Rule 7: **Free 24 files / 66 call sites, Pro 4
     files / 6 call sites.** See item 1 below — this is DONE, not planned, as of 2026-08-11.
- **All six §23.2 gaps (G1-G6) confirmed still open**, each checked against the actual file, not
  inferred from the design doc:
  - G1: `grep -n "mvs_media_index\|drive_type\|drive_id" includes/Core/Migrator.php` shows
    `drive_type`/`drive_id` exist only on `mvs_pro_folders` (added when folders shipped); no such
    columns were ever added to `mvs_media_index`. A Space-root document still has nothing but
    `post_author` to say whose library it is in.
  - G2: `PermissionService::owns_drive()` (`wpmediaverse-pro/includes/Documents/PermissionService.php:1079`)
    confirmed unchanged — `'user' === $drive_type` path is a straight identity check, everything
    else falls through to `apply_filters( 'mvs_document_owns_drive', false, ... )`. That filter name
    is real and live; nothing is wired to answer it yet, so it always resolves `false` for any
    non-personal drive today. §23's proposed replacement (`none|read|write|own`) does not exist.
  - G3: `wpmediaverse-pro/includes/REST/DocumentController.php` registers exactly six routes —
    `/documents`, `/documents/search`, `/documents/{id}`, `/documents/upload`,
    `/documents/{id}/restore`, `/me/shared`. No `/drives` route, no `drive` query param on any of
    them. Confirmed by reading the actual `register_rest_route()` calls, not by absence of a grep
    hit on a guessed path.
  - G4: the live BuddyNext bridge — `/Users/vapvarun/Local Sites/buddynext/app/public/wp-content/
    plugins/buddynext/includes/Bridges/WPMediaVerseBridge.php` (1,257 lines, confirmed the active
    copy by mtime against the `member-blog` site's older copy of the same plugin) — has zero
    document or drive hooks. The only "document" hit in the file is an unrelated docblock word; the
    only "drive" hit is `document.addEventListener`, a DOM API name, not a filter. **This file is
    reachable from this same machine** (a different Local site, but on disk and readable) — it is
    not blocked on getting access to another repo, only on a decision about whether to touch it
    before BuddyNext's own team has seen the frozen contract.
  - G5: no reassignment/departing-member code anywhere in `wpmediaverse-pro/includes/`.
  - G6: no `'space'` privacy token anywhere in either `PermissionService.php` or
    `Services/PrivacyService.php` (Free or Pro). `group` remains the only non-`user` token wired.
- **Item 6, the "~39 pre-existing Pro test failures" claim, could NOT be re-verified this pass.**
  `phpunit` 9.6.34 and `/tmp/wordpress-tests-lib` are both present, but running it through this
  shell's PHP picks up a mismatched Xdebug/opcache/imagick build (API-version errors) rather than
  Local's own PHP binary that `wp_cli` uses. The number in §20.2 should be treated as **unconfirmed,
  not false** — it needs a run through Local's actual PHP, not a claim to correct blindly.

### 24.2 Plan A — personal-drive debt (Tier 2), independent of everything else

These four can proceed in any order, do not touch Space drives, and do not require BuddyNext. None
are release blockers for 2.4.0, but none should wait indefinitely either — they are the difference
between "the structural guarantee in §8 is enforced" and "the structural guarantee in §8 is a
convention someone has to remember."

1. **CI ban on direct `mvs_media_index` queries (P1.3/P1.4) — DONE 2026-08-11, migration not started.**
   Both plugins gained Rule 7 in `bin/coding-rules-check.sh`, backed by a shared detector
   (`bin/lib/detect-media-index-leaks.py`, one copy per plugin so each `bin/` stays self-contained)
   and a mutation test (`bin/mutation-test-rule7.sh`) that proves three things: a real violation is
   caught, an allowlisted file is not, and a bare comment mentioning the table name is not a false
   positive. Allowlist, each entry with a reason, not a bare path: `Repository/MediaRepository.php`,
   `Repository/MediaRepositoryInterface.php` (docblock-only), `Core/Migrator.php` (schema DDL runs
   before the repository's assumptions are safe), `Services/AdminAggregatesService.php` (the Rule-3
   sanctioned aggregate home — routing its own queries through the repository would only relocate the
   SQL). **Rule 7 is currently `known_gap()`, not `violation()` — it does not fail the script or block
   a push.** Making it a hard gate today, with 66 Free + 6 Pro call sites still open, would fail every
   future `git push` on unrelated work through the pre-push hook until all of them were migrated in
   one sweep — the exact "no incremental patches vs. don't break the build for everyone" tension this
   plan needs to resolve, not create. **Promote it to `violation()` the moment both lists are empty**,
   so a 67th or 7th call site can never sneak back in unnoticed.

   Confirmed real violation list (2026-08-11, mutation-tested detector):
   - **Free — 24 files, 66 call sites:** `Admin/MediaListPage.php`, `Admin/StatsPage.php`,
     `CLI/Commands.php`, `Core/Activator.php`, `Core/TemplateLoader.php`,
     `Integrations/BuddyPress/ActivityContentIntegration.php`,
     `Integrations/BuddyPress/ActivitySyncIntegration.php`,
     `Integrations/BuddyPress/GroupTabIntegration.php`, `REST/Controller/MediaController.php`,
     `REST/Controller/TagController.php`, `Services/CloudOps.php`,
     `Services/CptIdCollisionService.php`, `Services/GDPRService.php`,
     `Services/ModerationService.php`, `Services/StorageRepairService.php`,
     `Services/UploadService.php`, `Services/UserDeletionService.php`, `Social/ActivityService.php`,
     `Social/FavoriteService.php`, `Social/ReactionService.php`, `Taxonomies/MediaTag.php`,
     `src/blocks/album-viewer/render.php`, `src/blocks/media-grid/render.php`,
     `src/blocks/media-stats/render.php`.
   - **Pro — 4 files, 6 call sites:** `Leaderboard/LeaderboardService.php` (2),
     `Integrations/RtMedia/MigrationAdmin.php` (1), `Stories/StoryService.php` (2),
     `Documents/SearchService.php` (1). `MediaRepository` already exposes methods that look like a
     direct fit for several of these (`get_by_slug`, `find_by_meta`, `query_by_author`,
     `count_by_meta`) — some of this 30-file list may be a call-site bug (repository method exists,
     nobody used it) rather than a missing-capability gap. Check before adding a new method.

   Run `bash bin/coding-rules-check.sh` in either plugin to see the current live list (it will not
   match the numbers above verbatim forever — that's the point of it being a script, not a snapshot).
   Migrate incrementally through `MediaRepository` — this is exactly the kind of multi-file mechanical
   change Coding Rule 15 (debt tax) says to do without growing any Known-Debt file in the process.
2. **30k-document scale fixture (P3.9) — THE TOOL ALREADY EXISTS, only the measured run is pending.**
   §20.2 listed this as fully pending; that was stale. `wp mvs seed-documents` (Pro,
   `includes/CLI/SeedDocuments.php`) has existed since this feature shipped: seeds through
   `DocumentIngestService`, never raw SQL, marks every row with `_mvs_seeded_fixture` so `--cleanup`
   removes only what it created, and asserts the lazy-root invariant (`--depth=0` produces zero
   folder rows) as part of its own run. `--members=<n> --docs-per=<n> --depth=<n>` composes to any
   target count.

   **Verified 2026-08-11, small scale only (30 attempted, 10 succeeded via the CLI command; a
   manual 15-call reproduction through the same service calls succeeded 15/15).** The 20 refusals
   were not reproducible outside the live command and are the likely signature of a **race with a
   concurrently-running process on the same site** — a background QA agent was walking
   `qa/runbooks/DOCUMENTS-QA.md` Sections 3/4 at the same time, which deliberately toggles
   `mvs_pro_documents_enabled` and the `use_mvs_documents` capability off and back on as part of its
   own assertions. **Do not run the 30k-scale seed while any process is exercising the master toggle
   or role gate on the same site** — both the seed run's success count and the QA walk's
   before/after document-count assertions would corrupt each other. Diagnostic pollution (16 rows
   created outside the marker system while investigating) was cleaned up manually; the marked
   smoke-test batch was removed via `wp mvs seed-documents --cleanup`. Site confirmed back at
   baseline.

   **Still pending:** the actual 30k run (needs ~30x the members or docs-per used above; estimate the
   per-document cost from a mid-size dry run first — each iteration does real file I/O + a full
   `DocumentIngestService::handle()` pass, not a bare insert, so 30,000 will take real wall-clock
   time and should run via a backgroundable process, not a single foreground CLI call with a hard
   timeout), and re-running the §12 big-site claims against the result, correcting any that don't
   hold instead of leaving them "reasoned, not measured."
3. **Real-customer-DB dry run of Migrator v27.** Needs a sanitized copy of an actual customer DB
   (or the closest available proxy) — this is an operational task, not a code change, and belongs on
   whoever owns that access, not something to fabricate a substitute for.
4. **Isolate the Pro gamification test failures — DONE 2026-08-11, real root cause found and
   partially fixed; the "cross-test pollution" diagnosis was wrong.** Re-ran through Local's actual
   PHP binary (`.../lightning-services/php-8.2.29+0/.../php`, not the system `phpunit` — that one
   picks up a mismatched Xdebug/opcache build and never got a clean run). The honest count, run
   2026-08-11: **530 tests, 12 errors, 27 failures = 39 problems** — the "~39" total was actually
   about right, but the *diagnosis* was not.

   **What was actually wrong:** `BoostServiceTest::test_create_boost_returns_positive_id` alone (no
   other suite in the process) failed with `mvs_boost_gamification_unavailable` — `Plugin::
   points_backend_available()` correctly reports false because the separate wb-gamification plugin
   isn't installed in this test environment, and `BoostService::create()` correctly refuses every
   boost with a 503 when that's true. That is CORRECT PRODUCTION BEHAVIOUR for a site without
   wb-gamification, not a bug — the test suite had simply never stubbed the points backend, so every
   boost-creation test hit the real refusal path instead of the logic it meant to test.
   `DeliveryControllerTest` run alongside `BoostServiceTest` shows **zero** interaction either
   direction — same pass/fail either way. **There was no cross-suite pollution to find between these
   two.** (A different, real pollution DOES show up only in the full 530-test run — see below.)

   **Fix applied:** `tests/PointsBackendStub.php` (new) defines `wb_gam_get_user_points()` and
   `\WBGam\Engine\PointsEngine::debit()` as an in-memory stub with a configurable balance, installed
   once from `tests/bootstrap.php`. One real bug surfaced building it and is worth recording:
   **a bare `function foo() {}` written inside a method body, in a file under `namespace
   WPMediaVersePro\Tests;`, silently binds to `WPMediaVersePro\Tests\foo` — not the global function
   the product code actually checks with `function_exists('wb_gam_get_user_points')`.** Confirmed by
   instrumenting the exact line: the function "declared without error" and `function_exists()` still
   returned false. Fixed by declaring both the function and the `PointsEngine` class via `eval()`
   with their own explicit `namespace { ... }` block — the only way one file can declare something in
   a *different* namespace (here: global) than the one it's written in.

   **Result:** `BoostServiceTest` alone went from 16 problems (3 errors, 13 failures) to 14 (3 errors,
   11 failures) — real, verified progress, not full resolution. **Two things remain open, found while
   fixing this one, not yet fixed:**
   - A separate, apparently pre-existing bug in `BoostService::create()`'s `$wpdb->insert()` — at
     least `test_create_boost_sets_correct_points` and both `test_format_boost_*` tests show the
     insert succeeding for the FIRST boost-creation test in the file but a subsequent `get_row()` by
     the returned id finding nothing, which point at either a genuine insert failure
     (`$wpdb->insert_id` returning falsy) or a state-leak between tests despite the existing
     `tear_down()` truncating `mvs_boosts` — not yet root-caused.
   - **`ChallengeServiceTest` (13 problems) and `BattleServiceTest` (8 problems) were NOT named in
     the original "~39" diagnosis at all**, but account for 21 of the 39 in the honest full-suite
     count. Both plausibly hit the same missing-gamification-stub class of failure (challenge voting
     and battle resolution both award XP through the same wb-gamification integration), but this was
     not verified individual-test-by-test — flagging rather than claiming a fix that wasn't checked.

   **Next step for whoever picks this up:** confirm the insert/get_row mismatch first (it blocks the
   simplest test in the file), then check whether `ChallengeServiceTest`/`BattleServiceTest` need the
   same `PointsBackendStub` wired into their `set_up()`, or a `CompetePointsBridge`-specific stub of
   their own.

### 24.3 Plan B — Space/site drives (Tier 3), sequenced, PR1 has no blocking dependency

The five-PR sequence from §23.5 stands. What changes here is being explicit about which PRs need a
decision from outside this repo and which do not:

- **PR1 (contract-only — refusal codes + filter names, no schema, no code)** needs no BuddyNext
  access to draft — it is a naming exercise against this plan's own §22/§23 vocabulary. It DOES need
  a decision before it's final: whether the frozen names get reviewed by whoever owns
  `buddynext`'s bridge before MediaVerse commits to them, since PR1's entire purpose is "BuddyNext
  can code against this and it won't move." Drafting it is safe; declaring it final without that
  review defeats the point of freezing it first.
- **PR2 (`drive_type`/`drive_id` columns + Migrator bump + backfill)** is self-contained inside this
  plugin pair. Production Rule 4 applies directly: schema change needs a Migrator version bump and
  is a minor-release item, not a patch. Production Rule 8 (minor releases are additive) is satisfied
  — this adds columns, defaults every existing personal-drive row to its current implicit binding in
  the same migration (so root listing becomes one code path, per §23's own recommendation), and
  removes nothing.
- **PR3 (`can_write_drive` + `GET /drives` + `?drive=space:N`)** is also self-contained — it is new
  REST surface and a new permission resolution, not a rename of anything existing, so Production
  Rule 2 (never rename without an alias) doesn't apply and there's nothing here to break existing
  BuddyNext callers (they don't call `/drives` yet because it doesn't exist yet).
- **PR4 (BuddyNext bridge hooks)** is the one that crosses the repo boundary for real — editing
  `buddynext/includes/Bridges/WPMediaVerseBridge.php` is possible from this same machine, but it is
  a different plugin with its own release cadence and (presumably) its own owner/reviewer who has
  not seen this plan. This is the PR to hold for an explicit go-ahead, not PR1-PR3.
- **PR5 (T1 reassignment + `space` privacy token)** depends on PR2-PR4 existing to reassign *into*,
  so it is naturally last regardless of any external decision.

**Net: PR1's draft, PR2 and PR3 can start without anyone outside this repo weighing in. PR1's
sign-off and PR4 are the two places this plan needs an answer from outside this session before
proceeding.**
