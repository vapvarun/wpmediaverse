# Document Library — plan

**Status:** PLANNING. No code written.
**Target:** Free + Pro **2.5.0**, paired (minor — additive, Production Rule 8).
**Consumer:** BuddyNext. MediaVerse's own UI is the standalone fallback.
**Visual summary:** `plan/document-library-visual.html` (open in a browser).

> **This is the single source of truth.** It replaces five earlier working documents — the
> 2026-08-05 spec and plan, the gap audit, the v2 implementation plan, and the Google Drive parity
> audit. Their conclusions are folded in below; the deliberation that produced them is in
> `git log`. If you are reviewing this feature, read only this file.

**Hard dependency:** the album/collection ID-collision fix
(`plan/2026-08-08-cpt-id-collision-fix-plan.md`, on the 2.4.0 branch) **must ship first or alongside.** This
design puts documents into `mvs_media_index`, and that fix is what makes the ID space safe to share.

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
