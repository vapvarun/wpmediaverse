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

**HANDOVER WRITTEN 2026-08-14:** `docs/architecture/pro/BUDDYNEXT-DRIVE-BRIDGE.md`. All four
filters verified live (fired at `PermissionService:1320`, `DocumentController:367` and `:406`,
`AbstractDocumentController:243`), with signatures, accepted-arg counts, a reference
implementation per filter, the seven-row verification recipe, and the `space`-privacy gap stated
plainly so BN does not meet it mid-build. The four questions above are reproduced there as theirs
to answer.

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

### 20.1 Space / site drives — MOSTLY BUILT, two gaps

> **RE-VERIFIED 2026-08-14 AGAINST THE RUNNING CODE. "Nothing in §23 is built" is no longer true —
> six of the seven items below shipped during Phase 11.** The heading stayed accurate for weeks
> after it stopped being, which is how a pending list starts costing more than it saves. Each row
> states how it was checked. Gap analysis: §23; anti-patterns that must not be used: §23.7.

| | State, checked 2026-08-14 | How |
|---|---|---|
| Document→drive binding (`drive_type` / `drive_id`) | **BUILT** | Migrator v29 stamps both; live data shows 107 `user` and 1 `space` document |
| `can_write_drive` — contribute distinct from own | **BUILT** | present in `includes/`, alongside `owns_drive()` |
| `GET /drives` | **BUILT** | route registered — confirmed in the live route table |
| `?drive=space:N` on the documents list | **BUILT** | `drive` collection param + `parse_drive()` in `DocumentController` |
| T1 departing-member reassignment | **BUILT** | `UserDeletionService::reassign_team_drive_media()`, covered by `DepartingMemberTest` |
| **Privacy token `space`** | **BUILT 2026-08-14** | `PRIVACY_VALUES` is now `private, space, members, public`. The ladder resolves it by asking `drive_access()` — anything but `none` may read. Verified live against a simulated bridge: member 200, non-member 404, and all three of `read`/`write`/`own` admitted |
| **BuddyNext bridge document hooks** | **NOT BUILT — and not ours to write** | `WPMediaVerseBridge.php` still has none. BuddyNext applies these; we supply the hooks |

**So Space drives are now complete on our side — storage, permissions, privacy — and waiting only
on the bridge.** A Space document can be filed, listed, permissioned, reassigned, and made visible
*to the Space and only the Space*. Nothing further is owed to BuddyNext; handover is
`docs/architecture/pro/BUDDYNEXT-DRIVE-BRIDGE.md`.

**What building `space` actually cost, because the estimate was wrong in an instructive way.** It
read as "add a value to one constant". The constant was the smallest part: the vocabulary had SEVEN
copies, three of which are structural and cannot be collapsed —
`Sanitizers::WHITELISTS` must be a compile-time constant expression, and Free needs the list twice
without being able to call Pro. Free's `document_privacy_labels()` INTERSECTS Pro's answer against
its own copy, so adding the level to Pro alone would have left the admin editor silently offering
three options for a four-option value — and a select rendered without its current value writes the
first option on the next save. That is a privacy change made by an administrator who came to fix a
title. Two more things had to move that no one would predict from the ticket: `drive_documents()`
and `documents_by_ids()` filter on `drive_type`/`drive_id` but did not SELECT them, so a document at
a Space drive ROOT (no folder) resolved as its author's personal file; and the folder privacy
cascade ranks levels from `MediaRepository::PRIVACY_ORDER`, where an unranked level is never
tightened — a folder set to `private` would have left its `space` documents readable, silently.
`DocumentSettingsTest` now fails on any of those drifting.

### 20.2 Pending on the personal drive (not blocking release)

**Re-verified against the code on 2026-08-11**, because the build plan this list came from carried
stale statuses in both directions. What each claim was checked against is stated, so the next
reader does not have to re-derive it.

> **RE-VERIFIED 2026-08-14. Four of the five items below were stale — three are now done and one
> had the wrong numbers.** Corrected in place rather than appended, because a pending list that is
> read as current is worse than no list. What each claim was checked against is stated.

- **P1.2 call-site migration for `mvs_media_index`** — **PRO IS DONE, FREE IS NOT.**
  *Measured 2026-08-14 with the detector, not counted by hand:* **Free 32 call sites across 5
  files** (`CLI/Commands.php` 11, `Services/CloudOps.php` 8, `Services/CptIdCollisionService.php`
  6, `REST/Controller/MediaController.php` 4, `Services/StorageRepairService.php` 3). The "24
  files / 66 call sites" figure this section carried was wrong. **Both plugins are at 0** and Rule 7
  is a hard `violation()` in both, mutation-tested. Free's 32 cleared 2026-08-15 — see §24.2 item 1.
- **Scale fixture** (P3.9) — **DONE.** 30,000 documents across 25 drives, seeded in 105 s and
  removed after. §12a records every measurement and corrects §12's stale index claim. The fixture
  generator for per-format samples is committed at `wpmediaverse-pro/bin/make-document-fixtures.py`.
- **Real-customer-DB pass on Migrator v27** (P2.2) — **CLOSED WITHOUT A DUMP, and the reasoning
  matters.** Folders are new in 2.4.0, so `folder_id` (an `ALTER ADD COLUMN … DEFAULT 0`) has
  nothing to migrate. The riskier half — the quarantine, which the code itself flags as not
  naturally idempotent — is correct by construction for every possible customer state: because
  2.4.0 is unreleased, no customer database can hold a *real* document, so every
  `media_type='document'` row out there IS the pre-1.2.3 catch-all the quarantine targets. Six
  tests cover it including idempotency. **This reasoning does not transfer to a later migration** —
  once 2.4.0 ships, real documents exist in the wild.
- **~39 pre-existing Pro unit failures** — **GONE, and a CI gate now runs the suites.** *Measured
  2026-08-14:* Pro 571 tests / 2,604 assertions / 0 failures. The claim "No CI gate runs phpunit in
  either plugin" is also no longer true: stage 2.4 of `bin/local-ci.sh` runs the full suite in
  both.

**Claimed pending by the old build plan, but actually DONE** — recorded so nobody rebuilds them:

- **CI Rule 7 — ban on direct `FROM mvs_media_index` outside `MediaRepository` (P1.3/P1.4).**
  *Verified done 2026-08-11 (corrected §20.2 same day — an earlier pass of this section claimed
  the rule did not exist; it does):* both plugins' `bin/coding-rules-check.sh` carry Rule 7, with
  detector `bin/lib/detect-media-index-leaks.py` and mutation test `bin/mutation-test-rule7.sh`.
  A hard `violation()` in both plugins since 2026-08-15; the migration is complete and the open
  call-site list is empty. Full allowlist with reasons: §24.2 item 1.
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

`PRIVACY_VALUES` is `private | space | members | public`, tightest first. Until 2026-08-11 four
places disagreed: the constant declared `private|members|unlisted` while `DriveActions` (twice) and
the drive's row dropdown wrote `private|members|public`. Nothing ever wrote `unlisted`; `public` was
written and undeclared. `DocumentController` was validating against MEDIA's vocabulary and so
accepted levels no document ladder can honour. One runtime list now, plus `privacy_labels()` and
`is_valid_privacy()`, read by every consumer.

`space` was added 2026-08-14 and means **everyone who can read the drive this document sits on** —
resolved by asking `drive_access()`, never by a membership list of ours. It is storable on any
drive; on a personal drive it resolves exactly as `private`, with no branch implementing that
(a personal drive answers `none` to everyone but its owner, who has already returned `edit`).

Two distinctions that are easy to collapse and should not be:

- **`privacy_labels()` is every level a document may HOLD; `privacy_choices()` is what to OFFER.**
  The member's drive renders only personal drives, so it does not offer `space` — a control that
  resolves to "just me" is a control that does nothing. The admin editor DOES offer it, deliberately:
  that screen edits documents across every drive including Space ones, and is not drive-scoped.
- **`privacy_choices()` always keeps the current value**, even when it would otherwise be filtered
  out. Without that, a document moved off a Space drive would show a select missing its own value,
  and the next save of any other field would rewrite its privacy.

Three copies of the list survive for structural reasons (`Sanitizers::WHITELISTS` needs a
compile-time constant; Free needs it twice and cannot call Pro). They are held together by
`DocumentSettingsTest::test_every_copy_of_the_vocabulary_agrees()`, not by comments.

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

1. **CI ban on direct `mvs_media_index` queries (P1.3/P1.4) — COMPLETE 2026-08-15.**
   Rule 7 is a hard `violation()` in both plugins and every tracked call site is migrated. A new
   direct query now fails `bin/coding-rules-check.sh` with exit 1 and blocks the push; verified by
   planting one and watching it fail, then removing it and watching it pass.

   **Free's 32, and where they went.** `CLI/Commands.php` (11), `Services/CloudOps.php` (8),
   `Services/CptIdCollisionService.php` (6), `REST/Controller/MediaController.php` (4),
   `Services/StorageRepairService.php` (3). The migration was NOT 32 new repository methods —
   most were the same walk with different columns, so `query()` / `query_count()` gained four
   predicates (`id_after` keyset cursor, `media_ids`, `status_in`, `has_file`, plus `privacy_not_in`
   and `file_types_in`) and eleven call sites became argument lists. What genuinely did not fit got
   named methods: `media_ids_missing_meta()` (an anti-join — absence of a meta row is a different
   query, not another predicate), `count_public_cloud_candidates()` and `feed_page()`.

   **Two new homes, each for a stated reason.** `Repository/MediaIntegrityRepository.php` holds the
   nine integrity/repair queries — a sibling of `MediaRepository`, not part of it, because that class
   is already 4,900 lines and because these reads must bypass the row cache (an audit that reads its
   subject through a cache is an audit of the cache). `CloudOps::counts_by_service()` moved to
   `AdminAggregatesService::media_counts_by_service()`, where coding Rule 16 puts site-wide
   COUNT+SUM aggregates; the old name stays as a delegate because Pro calls it (Production Rule 2).

   **One call site keeps its SQL fragments and that is deliberate.** `MediaController`'s feed
   assembles `$where`/`$params` and hands them to the **public filter** `mvs_feed_query_args`,
   documented since 1.1.0. Those fragments ARE the published contract, so re-expressing the feed as
   named arguments would break every integration using it. The fragments stay with the caller; the
   execution — table name, stats join, trending formula, pagination, prepare — moved to
   `MediaRepository::feed_page()`.

   **Three real defects surfaced by doing this, none of them the point of the exercise:**
   - `CloudOps::migrate_one()` wrote `file_url` with a raw `$wpdb->update`, leaving the row cache
     holding the pre-migration URL for the rest of the request. Now goes through `set()`, which
     invalidates.
   - `CloudOps::count_candidates()` restated the predicate of `query_public_cloud_candidates()` by
     hand under a comment saying it "must match exactly". They had already drifted once. Now one
     method.
   - `CLI moderation-stats` carried a byte-identical copy of `get_moderation_counts()`, so the CLI
     and the admin moderation screen could have reported different backlogs to the same person.

   **And one defect nearly introduced, caught by diffing live counts rather than by any test:**
   writing `MediaTypes::ALL` to mean "no type filter" in the storage walks. It lists image, video,
   audio and document — not `legacy_document`, which exists on real rows with real files. Had it
   shipped, `relocalize-private` would have skipped legacy documents, leaving a private file
   readable on a public bucket while reporting success. The storage walks pass `media_types => null`
   (an explicit no-predicate mode added for exactly this), and
   `MediaQueryWalkArgsTest::test_a_storage_walk_sees_legacy_document_rows()` pins it.

   **Known gap left open on purpose:** the four storage commands still load their whole result set
   when `--limit` is absent, as they always have. `id_after` now makes cursor-paging them a small
   change, but it is a behaviour change to four commands and does not belong in a mechanical routing
   commit. `generate-video-thumbnails` WAS converted, because its loop was simple enough that
   leaving it unbounded next to the new cursor would have been the odd one out.

   Allowlist, each entry with a reason rather than a bare path: `Repository/MediaRepository.php`,
   `Repository/MediaRepositoryInterface.php` (docblock-only), `Repository/MediaIntegrityRepository.php`
   (the repository layer; reads deliberately bypass the cache), `Core/Migrator.php` (schema DDL runs
   before the repository's assumptions are safe), `Services/AdminAggregatesService.php` (the Rule-16
   sanctioned aggregate home).

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

---

## 25. True document previews — LibreOffice → PDF → PDF.js

**Decision, 2026-08-14 (Varun):** go with LibreOffice + PDF.js. This section is the
plan; nothing here is built.

**SCOPE: document types only.** Images, video and audio are already covered by
MediaVerse's own media pipeline and are untouched by everything below. Video appears
here once, as the PATTERN to copy — `PosterService` solves "optional binary on an
unknown host" and that answer is reused rather than reinvented. No media surface,
setting or table changes.

### 25.1 The problem, stated exactly

What ships today is two mechanisms, and only one of them is an embed. Measured
2026-08-14 on a live site, one real file of every declared type through
`GET /mvs-pro/v1/documents/{id}/preview`:

| Type | Mechanism | Embed? |
|---|---|---|
| `pdf` | `<iframe src="{signed url}">` | **yes** — the real document |
| `word` `excel` `powerpoint` `odf ×3` `rtf` | server unzips XML, strips tags, returns HTML | **no — extraction.** The words, no layout, no images |
| `text` `markdown` `csv` | server reads the file, returns HTML | n/a — these *are* text |
| anything else (`.zip`, unknown) | download card | n/a — **not a document.** `resolve()` returns null and the upload is refused, so no such row exists to preview. This line describes the tier's default, not a supported type |

Calling the middle row a preview oversells it: a member opening a contract sees its
sentences in a plain column, not the contract. **`ext-zip` is a parsing prerequisite,
not an embed mechanism** — an early framing error in this work, corrected here.

**No browser can render a `.docx`.** There is no native embed for Office or ODF;
point an `<iframe>` at one and it downloads. PDF is the sole exception. So "embed
every type the same way" has exactly one meaning: **turn every type into a PDF, and
render that PDF ourselves.**

### 25.2 Why the market answer is closed to us

The two leading plugins split it the same way:

| Plugin | PDF | Office |
|---|---|---|
| [Document Embedder](https://wordpress.org/plugins/document-emberdder/) | **PDF.js, locally** | Google Viewer |
| [Embed Any Document](https://wordpress.org/plugins/embed-any-document/) | Google / Office Online | Google / Office Online |

Embed Any Document's own FAQ: *"the viewers do not support locally hosted files, and
your document has to be available online for the viewers to access"* — capped at 8 MB
(Google) / 10 MB (Microsoft), because the file is uploaded to their service on every
view.

Those plugins embed **public page content**. Ours are **private member files** behind
`DocumentUrls::signed()`. Publishing a document to satisfy a third-party viewer hands
it to anyone with the link and to the viewer's operator. Incompatible with the drive
— not a preference being declined.

**But their PDF choice is worth copying:** PDF.js locally, which is exactly what
removes our browser-dependent branch at no privacy cost.

### 25.3 Target architecture

```
upload ──► [pdf?] ──yes──────────────────────────────► store original
                │
                no
                ▼
        LibreOffice --convert-to pdf   (Action Scheduler, background)
                ▼
        rendition stored beside the original
                ▼
        PDF.js renders it, fed by our own gated endpoint
```

One viewer for every type. The owner's mental model becomes one sentence: *we render
a PDF of your document and show it ourselves.* Adding an accepted format later becomes
a conversion question, never a rendering question.

`text` / `markdown` / `csv` keep their existing HTML rendering — converting a text
file to PDF to display it would be worse in every respect. That is a deliberate
exception, not an oversight.

### 25.4 The dependency, and the precedent for it

LibreOffice must be on the server. **It is not installed on the dev host** (checked
2026-08-14) and is absent from most shared hosting.

This plugin has solved this exact shape once already, for video posters:

| Concern | Existing answer to copy |
|---|---|
| Find the binary | `PosterService::resolve_ffmpeg_binary()` — first-match-wins over 4 paths |
| Let a host override it | `mvs_ffmpeg_binary` filter |
| Ask before using | `PosterService::is_ffmpeg_available()` |
| Tell the owner | Site Health test `wpmediaverse_video_posters` |

Mirror it exactly: `resolve_soffice_binary()`, `mvs_pro_soffice_binary`,
`is_conversion_available()`, Site Health test `wpmediaverse_document_renditions`.

**Invoke with `proc_open` and an ARRAY command**, as `PosterService::run_ffmpeg_extract()`
does — arguments reach `execve()` directly with no shell, so a filename containing shell
metacharacters is harmless. Do NOT copy `TranscodeService`'s `escapeshellarg()` string
form; the array form is strictly safer and this input is member-supplied.

### 25.5 LibreOffice specifics that will bite

1. **It needs a writable user profile.** Without one it silently fails or hangs on a
   web host. Pass `-env:UserInstallation=file:///<writable>/mvs-lo-profile` pointing
   inside the uploads dir, one per site.
2. **It is not safely concurrent** with a shared profile. Two conversions at once
   corrupt each other. One-at-a-time via a lock, or a profile per job.
3. **It must be told not to be interactive:** `--headless --norestore --invisible
   --nolockcheck --nodefault`.
4. **Macros are an attack surface.** LibreOffice parsing untrusted member uploads is
   the single largest new risk in this plan. Run with macro execution disabled, a hard
   timeout, and treat a non-zero exit as "unsupported", never as a retry loop.
5. **A hard timeout is mandatory.** A malformed file can hang `soffice` forever;
   `proc_open` + a deadline + `proc_terminate()`.
6. **First run is slow** (profile creation). The queue absorbs it; a synchronous
   request must never trigger a conversion.

### 25.6 Rendition lifecycle

Treated as a derived artifact, exactly like a video poster.

| Event | Behaviour |
|---|---|
| Upload | Queue a render job. Document is usable immediately; preview says "preparing". |
| Replace (`/documents/{id}/replace`) | Invalidate the rendition, re-queue. `ReplacedFileSweep` is the precedent for safely unlinking the stale one. |
| Delete | Hook `mvs_media_deleted`, same as `ExtractionService::forget()`. |
| Backfill | A WP-CLI command over existing documents, batched and resumable — never a big-bang on activation. 30k documents is a real number here (§12a). |

**State, mirroring extraction's `pending` / `indexed` / `unsupported`:**
`pending` → `ready` → `failed` / `unsupported`. The UI must be able to say which,
because "preparing your preview" and "this file cannot be previewed" are different
sentences and a member can tell.

### 25.7 PDF.js

- **Where it goes: `assets/js/vendor/`**, not `libs/`. `libs/` is the php-scoper path
  for PHP packages; JS vendor already has a precedent in Free
  (`assets/js/vendor/lucide.min.js`, explicitly un-ignored in `.gitignore`).
- Ships `pdf.min.mjs` + `pdf.worker.min.mjs`. The worker is a separate file and its
  URL must be set explicitly — the most common integration failure.
- Enqueue **only** on document surfaces, never site-wide. It is ~1 MB.
- **Range requests: `DeliveryController` does not support them.** It `readfile()`s with
  a `Content-Length` and no `Accept-Ranges`. PDF.js will fall back to fetching the whole
  file before first paint. Acceptable to start; add `206` handling if large PDFs feel
  slow. **Do not claim progressive loading until the endpoint supports it.**
- Same-origin fetch through the existing signed URL, so privacy is unchanged and no
  new gate is needed.
- **Fallback chain:** PDF.js → native `<iframe>` (today's behaviour) if JS is
  unavailable → download button. Nothing regresses if the viewer fails to load.

### 25.8 Owner surface

- One setting: **render document previews** (on/off). Conversion costs CPU and storage;
  an owner on a small host must be able to decline it.
- Site Health states which of the two worlds they are in, in words they can act on:
  what is missing, what it costs them, what to ask their host for.
- The Documents admin screen shows rendition state per document, and offers a re-render
  for a `failed` one.

### 25.9 Two states, both honest

| LibreOffice present | absent |
|---|---|
| Every type embeds identically at full fidelity | Office types fall back to extracted text — exactly today's behaviour |
| | PDFs still embed, so nothing regresses |

Two states is still a simple flow. What makes it trustworthy is that the owner is never
left guessing which one they are in.

### 25.10 Costs to accept before starting

- **Storage roughly doubles for Office documents.** A PDF rendition per file.
- **Conversion is background work** on the queue extraction already uses.
- **LibreOffice parses untrusted input** — see §25.5 item 4. This is the risk to review
  hardest.
- **Fidelity is LibreOffice's**, not Microsoft's. A heavily-styled .docx may differ
  slightly. Still incomparably closer than extracted text.

### 25.11 Build order

1. `RenditionService` — binary detection, availability probe, Site Health. No conversion
   yet. Provable on a host with and without the binary.
2. Conversion + storage + state machine, behind the setting, off by default.
   **DONE 2026-08-15.** `RenditionService` gained `pending → ready → failed →
   unsupported`, storage beside the original at 0640, and containment on the
   rendition path (same rule as `readable_path()`, asserted independently —
   a security check with two implementations has one that is out of date).
3. Queue integration and the lifecycle hooks (replace, delete). **DONE 2026-08-15.**
   `mvs_document_uploaded` queues, `mvs_document_replaced` invalidates and
   re-queues, `mvs_media_deleted` forgets. All three hooks were confirmed to
   actually fire before being relied on.

   **The converter is a SEAM, not a hard dependency on LibreOffice.**
   `mvs_pro_document_rendition` lets a host produce the PDF by any means —
   modelled on Free's `mvs_optimize_image`, which is the same shape for the same
   reason. That matters commercially, not just for testing: LibreOffice is absent
   from most shared hosting, and without the seam those sites have no route to
   this feature at all. With it, a cloud converter is a plugin away.

   **Verified twice over**: the whole pipeline through the seam with a stub, and
   then a REAL `.docx` → LibreOffice → PDF → PDF.js end to end, rendering the
   document's actual text laid out on a page rather than the extracted-text
   column it showed before. 3.3s for a two-paragraph file on LibreOffice 26.2.

   **One bug this caught in itself:** `doc_type` is DERIVED from the mime via
   `group_for_mime()`, not stored — the first version read it as meta, got an
   empty string, and quietly queued nothing at all. It looked plausible, wrote no
   error, and rendered nothing. Found only by running it.
4. PDF.js viewer with the fallback chain, on the PDF path only — verifiable before any
   conversion exists, because PDFs already are PDFs. **DONE 2026-08-15.**
   `assets/js/document-pdf.js` + `assets/js/vendor/pdf.{min,worker.min}.mjs`
   (pdfjs-dist 6.2.108, Apache-2.0). All three rungs verified in a browser against a
   real 5-page PDF: PDF.js draws to canvas (3 pages eagerly, the rest on scroll via
   IntersectionObserver); a broken library url swaps in the native iframe; `<noscript>`
   carries the same iframe at the same signed url. No horizontal page scroll at 390px,
   44px download target, zero console errors. `PdfViewerMarkupTest` pins the contract
   the script reads, and the zip was built and opened to confirm the vendored files
   actually ship (the packaging failure this plugin has had before).

   **Two corrections to §25.7 from doing it.** The real payload is **1.7 MB**, not
   "~1 MB" — 455 KB library plus 1.26 MB worker; it is imported on demand by a 8.5 KB
   booter, so only a page actually showing a PDF pays it, but the estimate was wrong.
   And the fixtures were rotten: **every pre-existing PDF on the reference install had
   no file on disk**, so the first browser run fell back for a reason that had nothing
   to do with the code. A fresh sample was ingested and its id kept in option
   `mvs_pdfjs_sample_id` so the next run does not repeat that.
5. Point converted types at the same viewer. **DONE 2026-08-15.** All seven
   convertible types verified end to end against REAL documents — docx, xlsx,
   pptx, odt, ods, odp, rtf — each ingested, converted by LibreOffice 26.2 and
   rendered by PDF.js. 7/7, roughly 1.6s each. A spreadsheet renders as a
   spreadsheet: aligned columns, header row, sheet name.
6. WP-CLI backfill, batched. **DONE 2026-08-15.** `wp mvs render-documents`
   with `--limit`, `--batch`, `--dry-run`, `--force`. Never on activation: 30k
   documents at ~1.6s each is over twelve hours of CPU, and an activation hook
   that did that would take a site down with no explanation.
7. Admin state column + re-render. **DONE 2026-08-15.** The document admin panel
   states which of the four the document is in, and the `failed` case names the
   remedy (`--force`) because that is the one state an owner can act on.

**Three defects this stretch, all found by running things rather than reading
them:**

- **The fixture generator produces files LibreOffice cannot open.**
  `make-document-fixtures.py` satisfies the admission checks and nothing more;
  the first step-5 run reported six of seven types failing to convert, and the
  cause was the fixtures, not the converter. Its docblock had claimed "these
  fixtures are valid so a refusal here means a real regression" — too strong,
  now corrected, with instructions to generate conversion fixtures using
  LibreOffice itself.
- **The backfill's dry run never terminated.** Resumability rested on rows
  leaving the work list as they were processed, which is exactly what
  `--dry-run` does not do; the same ids came back every pass and it repeated two
  documents until `--limit` cut it off. `media_ids_missing_meta()` gained an
  `after_id` keyset cursor. Found by running the command twice.
- **`forget()` resurrected meta on delete.** It is hooked to `mvs_media_deleted`,
  by which point `delete_cascade()` has removed every meta row — so writing the
  cleared state back created two orphan rows per deleted document, forever.
  Caught by Free's `test_delete_all`, two plugins away from the cause.

Legacy `.doc` / `.xls` / `.ppt` are the biggest single win in the list — they go from
NO preview at all to full fidelity (§25.13) — so a fixture of all three belongs in the
verification for step 5, not just the modern formats. Testing only `.docx` is how the
hole stayed invisible.

Each step is independently shippable and independently verifiable. Step 4 delivers
value on its own even if conversion is never enabled — it fixes the browser-dependent
branch for PDFs, which is the one inconsistency measured today.

### 25.12 Not doing, and why

**One JS library per format** (Mammoth for Word, SheetJS for spreadsheets, nothing
decent for PowerPoint). Four renderers means four failure modes, four things to update,
and an owner who cannot answer "what happens when a member uploads a .docx?" without
qualifications. One conversion path and one viewer is the simpler flow, and simplicity
here is the feature.

### 25.13 Coverage: every accepted type, and what changes

**No new types are added.** `DocumentTypes` already accepts **11 named types across
14 extensions and 15 MIME types**, and all 11 are on by default. §25 changes how they
are PRESENTED, not what is accepted.

| Ext | Named type | Today | After §25 |
|---|---|---|---|
| `.pdf` | `pdf` | Embedded — browser's own viewer | Embedded — **PDF.js**, same everywhere |
| `.docx` | `word` | Extracted text | **Full fidelity** |
| `.doc` | `word` | **Refused at upload, or accepted-and-unpreviewable — depends on the host's libmagic. See below** | **Full fidelity** |
| `.xlsx` | `excel` | Extracted cells | **Full fidelity** |
| `.xls` | `excel` | **Same as `.doc`** | **Full fidelity** |
| `.pptx` | `powerpoint` | Extracted slide text | **Full fidelity** |
| `.ppt` | `powerpoint` | **Same as `.doc`** | **Full fidelity** |
| `.odt` | `odf_text` | Extracted text | **Full fidelity** |
| `.ods` | `odf_sheet` | Extracted cells | **Full fidelity** |
| `.odp` | `odf_presentation` | Extracted slide text | **Full fidelity** |
| `.rtf` | `rtf` | Extracted text | **Full fidelity** |
| `.txt` | `text` | Rendered — IS the document | Unchanged |
| `.md` | `markdown` | Rendered markdown (`<h2>`, lists) | Unchanged |
| `.csv` | `csv` | Rendered table | Unchanged |

#### The hole this audit found: legacy binary Office formats

**CORRECTED 2026-08-14 after testing with real files.** The first version of this
section said legacy formats are "accepted at upload but cannot be previewed". That was
half right, and the wrong half matters.

They are in `BY_MIME` and `BY_EXTENSION`, map to `word` / `excel` / `powerpoint`, and
all three are in the default `allowed_types` — so on paper they are accepted. But
ingest resolves the type from the SNIFFED MIME, and libmagic reports an OLE2 compound
file as `application/CDFV2`, which is in no map. Measured:

```
doc   sniffed=application/CDFV2   REFUSED: mvs_document_type_unsupported
xls   sniffed=application/CDFV2   REFUSED: mvs_document_type_unsupported
ppt   sniffed=application/CDFV2   REFUSED: mvs_document_type_unsupported
```

**The limit of that evidence, stated plainly:** the sample was a synthetic OLE2 header
with no internal streams, and libmagic classifies a *real* `.doc` by looking at those
streams — modern versions often answer `application/msword`, which IS in the map and
WOULD be accepted. So the two possible behaviours are:

| If libmagic says | Then |
|---|---|
| `application/CDFV2` | refused at upload — the member is told the type is unsupported |
| `application/msword` etc. | accepted, then unpreviewable — the case below |

**Which one a site gets depends on its libmagic version, and that is itself the
problem**: the same file behaves differently on two hosts, and neither outcome is
stated anywhere. Settling it needs a genuine `.doc` saved by Word, which was not
available here — do not treat either row as confirmed without one.

The preview hole is real whenever the file does get in: `OfficePreviewRenderer` is an
**OOXML/ODF reader**: it opens a ZIP container and reads XML. A legacy `.doc` is an
OLE2 compound file, not a ZIP, so there is nothing for it to read.

Measured 2026-08-14 by handing the renderer non-ZIP bytes typed as each:

```
legacy-as-word         html = ''   note = "No readable text was found in this file…"
legacy-as-excel        html = ''   same
legacy-as-powerpoint   html = ''   same
```

Two things follow, and they are different severities:

1. **These three extensions cannot be previewed at all today.** Not "lower fidelity" —
   nothing. A member uploading a `.doc` gets a note where a preview should be.
2. **The note misattributes the cause.** It says the file "may hold only images, or it
   may not have saved" — describing an empty document. The truth is that we cannot read
   the format. The member is told something wrong about their own file, and would go
   looking for a problem that is not there.

The graceful degradation is right — a note beats an empty panel, which is what
`OfficePreviewRenderer`'s own docblock says it exists to avoid. Only the wording is
wrong.

**Both are fixed by §25**, because LibreOffice reads legacy binaries natively. Until
it lands, item 2 is worth a one-line copy fix on its own: say "this format cannot be
previewed" when the type is legacy, rather than blaming the file.

#### Verified with purpose-built samples, 2026-08-14

A file of every extension was authored (valid OOXML with `[Content_Types].xml`, valid
ODF with an uncompressed `mimetype` entry, plus text/markdown/csv/rtf/pdf), ingested
through `DocumentIngestService` as a member, and opened on its own page. **11 of 14
ingested and all 11 previewed with their real content** — the authored text came
through, slide order held, markdown rendered as markdown. The 3 that did not are the
legacy formats above.

This is worth keeping as a fixture generator rather than a one-off: testing only the
modern formats is what hid the legacy hole in the first place.

#### The download card, and the host that reaches it (2026-08-15)

Tracing whether archives ever show a card turned up the one situation where the card IS
reached by a real, supported document: **a host without PHP's zip extension**. Six of the
eleven types — `.docx`, `.xlsx`, `.pptx`, `.odt`, `.ods`, `.odp` — are ZIP containers, so
without ext-zip they cannot be opened even to read their text.

`OfficePreviewRenderer::handles()` already declined them correctly there (an empty panel
would be worse than a card). What it did not do was say so:

- The card read **"This file type has no preview."** That is false — those types preview on
  every host that has the extension. A member reads it as the product not supporting their
  file; an owner has nothing to act on.
- **Site Health said nothing at all** about ext-zip, so the one person who could fix it had
  no signal.

Both fixed. The card now distinguishes "no preview exists for this type" from "not on this
server yet", via `OfficePreviewRenderer::blocked_only_by_missing_zip()`, and a new
`mvs_document_zip` Site Health test names the six formats, says what still works (PDF, text,
markdown, CSV, and downloads), gives the cause and the remedy. RECOMMENDED, never critical —
nothing is broken, and this screen's one critical test is document privacy.

**This path had never executed anywhere.** Every dev machine and CI runner has ext-zip, which
is exactly how it came to say something untrue unnoticed. `ZipEntryReader::available()` now
consults `mvs_pro_zip_reader_available`, which can only ever narrow (the `class_exists` check
runs first, so a filter cannot force zip on and turn a card into a fatal). That makes the
degraded path testable and browser-checkable; `MissingZipDegradationTest` covers it, and it
was verified in a browser with the extension simulated away, at 390px, then restored.

One test in that file **skips** rather than passing: the "filter can never force zip on"
invariant cannot be distinguished from its own absence on a host that has the extension —
written as a plain assertion it stayed green with the guard deleted. A skip that says why
beats a green test that cannot fail.

#### Types deliberately NOT added

`.zip` resolves to nothing by design (a bare archive is not a document, and admitting
it would let any ZIP through the container check). **Re-verified 2026-08-15** against a
real zip: `resolve('sample.zip')` returns null, and the same archive renamed `.docx` is
also refused because it carries no OOXML marker. "List the archive's contents instead of
a download card" was raised as a follow-up and is void — there is no archive document to
list, and admitting one to build the feature would reopen the container check.

What that investigation DID find is recorded below: on a host without ext-zip the card is
reachable for six real types, and it was telling members something false. Adding formats LibreOffice could
also convert — `.odg`, `.pages`, `.key`, `.wpd` — is cheap AFTER §25, because
conversion becomes the only question. It is deliberately not in scope: get the 11 that
are already accepted presenting correctly first.

---

## 26. Licensing — Documents is the one gated feature (shipped 2026-08-19)

**Decision, 2026-08-15 (Varun).** Pro's EDD licence is updates-only everywhere else and that rule
stands (`wpmediaverse-pro/CLAUDE.md` §7). Documents is the exception, and it is actionable ONLY
because Documents is unreleased: 2.4.0 is version-bumped and changelogged but never tagged, so there
is no installed base and nothing is taken from anybody. **The window closes when v2.4.0 tags** —
after that, gating needs a grandfather flag and a migration. Competitions was considered and
rejected: lightly used, so gating recovers ~nothing against the risk of a policy reversal on 50+
live sites.

### What was built

`Documents\DocumentLicense` — one authority, two enforcement points, three predicate caps.

| | Where | What it does |
|---|---|---|
| Authority | `DocumentLicense::can_write()` | `License::is_valid()`, memoised per request, plus the admin exemption |
| REST | `rest_request_before_callbacks` | Refuses any non-GET/HEAD/OPTIONS on `/documents`, `/folders`, `/permissions`, `/drives` |
| Front end | `DriveActions::handle()`, `DriveRenderer::handle_new_folder()` | The two `template_redirect` write handlers, refused with the `read_only` notice |
| UI honesty | `can_write_drive()`, `can_edit()`, `can_grant()` | So no control is drawn that the write path would refuse |

**The REST guard matches on METHOD, not on route names, and that is load-bearing.**
`POST /documents/bulk` carries the read-shaped `get_items_permissions_check` — a gate written
per-callback would have left the one route that moves a hundred documents at a time wide open.

### What is deliberately NOT gated

Each of these was a decision, not an omission:

- **Reads.** Listing, opening, searching, downloading, previewing. A member must not lose access to
  their own files because the site owner did not renew — they are not the one who did not renew.
- **Registration.** Un-registering a route turns a refusal a client can read into a 404 it cannot.
- **Revoking a share** (`DELETE /permissions/<id>`, and the `unshare` form action). You may always
  take access away; you may not always hand it out. Gating revoke would leave a document shared with
  exactly the person its owner is trying to cut off. A gate that can trap a document in somebody
  else's hands is a safety failure, not a commercial lever.
- **Whoever administers documents** (`manage_options` / `manage_mvs_documents`). The owner is the
  person who can renew; blocking their cleanup produces a support ticket, not a sale.
- **Storage drivers, watermarking, AI providers, quota adapters.** They run for media too.

### The refusal

`mvs_documents_read_only`, 403, added to `DriveContract::FROZEN`. **It does not name the licence**:
the instruction to a client is identical either way — show the library, hide every write control —
and a member's app has no business reading the site owner's billing state. The owner is told
plainly, in an admin notice confined to the document screens. BuddyNext gets an additive contract
row and can branch on it whenever; nothing breaks until it does.

### Two things the build got wrong first, recorded because both were nearly invisible

1. **The gate was almost pushed down into `drive_access()`** — one line instead of three, and it
   reads as the tidier choice. It would have shipped a disaster: `owns_drive()` derives from
   `drive_access()`, and `DriveRenderer` refuses to LIST a folder whose drive the viewer does not
   own. Capping the shared ladder turns "read-only" into "your files are gone" for the owner of
   every drive on the site. Pinned by `test_the_read_ladder_is_untouched`.
2. **The first test of the write routes failed for the wrong reason.** Core validates a route's
   required params BEFORE any callback, so a bare `POST /folders` answers 400
   `rest_missing_callback_param` with the gate never consulted — and a looser assertion ("not 2xx")
   would have PASSED while proving nothing. Same shape as the `anonymous-cannot-modify` journey
   defect found on 2026-08-15. The data provider now supplies required params.

### Test-suite consequence, which is worth knowing before the next document change

Licence state used to be irrelevant to the Pro suite; it is not any more. 38 document tests failed
the moment the predicates closed, because the test site had no licence. **`tests/bootstrap.php` now
activates one** — a paying customer's site is licensed, so that is the default fixture — and
`DocumentLicenseTest` lapses it per test and puts it back. Not `MVS_PRO_LICENSE_BYPASS`: that
constant short-circuits `is_valid()` wholesale and would make the licence untestable in the one file
that has to test it.

### Verified

Full Pro suite **637 tests / 3128 assertions / 0 failures**; Rules 1-8 pass (Rule 8 covers the new
code); WPCS and template-style clean. Browser-verified on `mediaverse.local` as a member with 73
documents, at 1280px and 390px, in dark mode: the toolbar drops Upload and New folder and keeps
Trash, document rows fall back to a working Download link, folder rows and bulk tick-boxes
disappear, the empty state stops inviting an upload, and a form left open across a lapse is refused
with the notice and creates nothing. Activating the licence restores every control with no
migration.

**Open, deliberately:** the admin Documents screens are not gated (owner tooling), and the
`space` privacy level is unaffected.

---

## 27. The index question §0.1 deferred — answered by measuring, 2026-08-19

The phase-11 plan left one decision open: *"whether `doc_listing` is then dropped or kept. Keeping
both costs write throughput on the hottest table in the product; dropping it needs every remaining
`folder_id`-without-drive query found first. Decide deliberately, do not accumulate."*

**Answer: keep `doc_listing`. It is not a duplicate and cannot be dropped.** A folder listing
carries no drive predicate — the folder already scoped the drive — so it is
`media_type, folder_id, status, created_at`, which is `doc_listing` verbatim. `drive_listing` has
`drive_type`/`drive_id` at positions 2 and 3, so a folder listing cannot use it past `media_type`.
The two serve the two shapes of the same query.

### But the measurement found two defects on the way to that answer

**1. `drive_listing` was not the index the migration says it built.** On a real database it was
`(media_type, folder_id, status, created_at)` — a byte-identical duplicate of `doc_listing`, paying
write cost on the hottest table for nothing. `Migrator::add_index_if_missing()` tested the index
NAME and never its columns, and **dropping a column does not drop the composite index it belongs
to — MySQL rewrites the index down to the surviving columns.** So:

- v29 builds `drive_listing` over six columns.
- Anything drops `drive_type`/`drive_id` — the 2026-08-15 upgrade rehearsal did exactly this on
  purpose, and a restored dump from a mixed version or a host tool does it by accident.
- The columns come back, v29 re-runs, sees the name, reports success, and the six-column index is
  never rebuilt.

The site is then permanently missing an index its own code comments promise it has, with nothing to
indicate it. **Fixed:** the guard now compares the actual column list and rebuilds on mismatch.
Pinned by two tests — one degrades an index and asserts it is rebuilt, one asserts a correct index
is left alone (rebuilding a correct index on every run is an expensive no-op on a large table).

**2. Even correctly built, `drive_listing` served nothing, because of an `OR`.** The root query read
`( ( drive_id = %d AND drive_type = %s ) OR ( drive_id = 0 AND post_author = %d ) )` — the second
branch covering rows v29's bounded backfill had not stamped yet. An OR cannot satisfy positions 2
and 3 of a composite index, so the optimiser SAW `drive_listing` in `possible_keys` and refused it.

**Fixed:** the legacy branch is emitted only while the backfill is still running. Its cursor option
holds `-1` once a pass finds nothing left to do, and **absent reads as "still running"**, which is
the safe answer for the never-migrated and half-migrated cases alike — reading absent as "done"
would scope every listing by a column nothing has written and make every drive look empty. Rows the
backfill skips (`post_author <= 0`) are not lost: the legacy branch could never match them either,
since it needs a real author, and an ownerless row belongs to no personal drive.

### Measured, 30,000 documents, one page at OFFSET 1000

| | before | after |
|---|---|---|
| index chosen | `doc_listing` | **`drive_listing`** |
| rows examined | 8,032 | **234** |
| filtered | 1.38% | **100%** |
| extra | Using where; Backward index scan | Backward index scan |

That closes the deep-OFFSET soft spot `drive_documents()` has carried a comment about since the
feature was written. **The remaining one is `any_folder`** (the Recent view), which drops
`folder_id` and so reads only drive_listing's first three columns — indexed, and a much smaller
problem than before, because the drive scope is now applied by the index rather than by a
post-filter over the whole document table.

**Lesson worth carrying:** both defects were invisible from the code. The migration reported
success, the tests passed, and the comments asserted the index was correct. Reading `SHOW INDEX` and
`EXPLAIN` on a real database with a real fixture is what found them — the same class of finding as
the QA runs that keep turning up "a step that passes without testing anything".
