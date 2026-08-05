# Document Library — plan (2026-08-05)

Status: **PLANNING. No code written. Schema not final until the open questions at the bottom are closed.**
Owner: Varun. Target: **Free 2.5.0 + Pro 2.5.0, paired** (minor — additive, Production Rule 8).

> **Correction (2026-08-05):** an earlier draft of this spec said "Free: no changes required."
> That was wrong. It assumed documents carried their own small feature set. The decision that
> documents get **the same features as media** makes a Free change unavoidable — reactions,
> favorites, views, stats, meta, and access rules all live in Free tables keyed on `media_id`.
> See "Free changes" below. The two plugins ship together.

A Drive-style document library: personal, Space, and site-wide drives with a nested folder tree,
permission grants, and a REST surface complete enough to drive a native client. Documents are a
**separate entity type** — they do not enter `mvs_media_index` and never render through a
media template, but they carry the same feature set as media (reactions, comments, favorites,
views, stats, tags, moderation, reports) via a shared `object_type` dimension.

## Decisions (2026-08-05, owner)

| Question | Decision |
|---|---|
| Ownership | **All three** — personal drives, **BuddyNext Space** drives, site-wide library |
| Consumer | **BuddyNext only.** Not BuddyPress, not BuddyBoss. MediaVerse Pro ships the *skeleton* (engine + API); BN builds the experience on it |
| Version history | **None.** Current file only |
| Link sharing | **Both logged-in and anonymous**, anonymous **off by default** per site |
| Formats | PDF + modern Office (docx/xlsx/pptx) + ODF + plain text (.md/.txt/.csv/.rtf) + **legacy Office (.doc/.xls/.ppt)** |
| Archives (.zip) | **Excluded** from v1 |

## Why a new type instead of extending media

Free deliberately blocks document uploads today — `UploadService::handle()` (line 80):

> Hard guard: PDF / document uploads are not supported (owner decision, Basecamp #9962125462).
> […] The read/display side (pdf-viewer block, `'document'` media_type, `/serve` content-type
> whitelist) is intentionally left intact so historical PDF media keeps rendering.

That guard **stays**. Two reasons not to revive the existing path:

1. `UploadService::get_media_type()` returns `'document'` as a **catch-all fallback** — anything
   that is not `image/`, `video/`, or `audio/` becomes a "document". That is the inverse of an
   allowlist, and an allowlist is the entire security model here.
2. Reviving it puts documents back into `mvs_media_index`, which is exactly what this feature
   is meant to avoid.

**MediaVerse is a media plugin first. Document support is a new, additive capability, and it
must be asked for explicitly — never inferred.** No code path in this feature may reach a
document type by elimination. There is no `return 'document'` default branch anywhere in it: a
file resolves to a *named* document type or it is rejected. See the next section.

Documents therefore get their own tables, their own services, their own REST controllers, and
their own templates. What gets **reused** is infrastructure, not data model:

| Reused from Free | How |
|---|---|
| `StorageService::get_local_driver()` | Path-based `store()`/`delete()`/`exists()`/`get_full_path()` on `StorageDriverInterface` — not media-keyed, safe to call for documents |
| HMAC signing approach | Pattern copied from `SignedUrlService`; **not** the class itself — its whole API is `int $media_id`-keyed |
| Pro page-route pattern | `Frontend\GamificationTemplateLoader` — `PAGES` map + version-tagged auto-flush (`mvs_pro_rewrite_version`) |
| Pro REST pattern | `Collections\CollectionItemsController` — namespace `mvs-pro/v1`, `auth_check()` permission callbacks |
| Pro migration pattern | `Core\Migrator` — `mvs_pro_db_version`, `dbDelta`, `mvs_pro_*` table prefix |
| Design tokens | `var(--mvs-*)`, dark mode, RTL via `grunt rtlcss` |

Pro boundary (Coding Rule 10) holds throughout: no `use WPMediaVerse\…`, everything through
`Plugin::free_service('key')`.

## Manifest check (2026-08-05)

Run against `audit/manifests/` (Free, v2.3.0, 220 hooks / 115 REST / 22 tables) and
`audit/pro/manifests/` (Pro) before any symbol in this spec was fixed. Findings:

**Clean — no collisions.** All eleven proposed hooks (`mvs_document_*`, `mvs_folder_created`,
`mvs_document_drive_*`) are unused. No table named `*doc*`, `*folder*`, or `*share*` exists in
either plugin. No `mvs-pro/v1` route collides with `/documents`, `/folders`, `/drives`.
`mvs_pro_documents` / `mvs_pro_folders` match the `mvs_pro_*` prefix that
`mvs_pro_collection_items` and `mvs_pro_push_devices` established for post-1.9.0 tables.

**Finding 1 — hook naming must follow the existing convention.** Free already ships
`mvs_allowed_file_types` and `mvs_dm_allowed_file_types`. An earlier draft of this spec proposed
`mvs_document_allowed_types`, dropping the `_file_` segment for no reason. **Corrected to
`mvs_document_allowed_file_types`**, so all three filters read as one family.

**Finding 2 — `group` is already taken in this API, and it does not mean what you'd assume.**
`/mvs-pro/v1/groups` is **group DM conversations** (`Groups\GroupController`, "Exposes group-DM
management as a Pro feature over the free messaging engine"). It is not BuddyPress groups and
not Spaces. Had documents used `group` for Space ownership, `mvs-pro/v1` would carry two
unrelated meanings of the same word. This is independent confirmation of the `space` naming
decided above — now backed by the manifest, not just Coding Rule 13.

**Finding 3 — "share" already means something else.** `/mvs/v1/media/{id}/share` +
`Social\ShareService` is *social sharing* — posting to a platform. Naming document collaboration
"shares" would give one word two meanings in one product. **The table is
`mvs_pro_doc_permissions` and the route is `/documents/{id}/permissions`**, which is also what
the Google Drive API itself calls this concept.

**Finding 4 — `mvs_access_rules` / `mvs_access_grants` overlap, but should NOT be reused.**
This is the one worth arguing, because the manifest-first rule says reuse before adding:

```
mvs_access_rules:  media_id, rule_type, rule_value, price, currency
mvs_access_grants: media_id, user_id, granted_at, expires_at, revoked_at, source
```

The overlap is real but shallow — user + expiry + revoke, about four columns. The divergence is
structural: access rules carry **`price` and `currency`**, because that pair is a *monetization
entitlement* system ("did this user buy access to this paid media"). Document permissions are a
*collaboration* system: permission **levels** (view/comment/edit), **role** and **link-token**
grantees, and **folder** targets that inherit down a subtree. Access grants are binary and
media-only, with no concept of any of those.

Merging them would make one table serve two unrelated lifecycles — a paid-entitlement ledger and
a permission matrix — which is how tables end up with half their columns null. They stay
separate, and this paragraph exists so a future reader does not "clean up the duplication."

`mvs_access_rules`/`_grants` still get `object_type` in the Free migration, so paid documents
remain possible later without another schema change.

## Free changes — generalize the engagement tables with `object_type`

Documents get **the same feature set as media**: reactions, comments, favorites, views, stats,
tags, categories, moderation, reports, access rules. Duplicating those tables in Pro would mean
~8 near-identical tables and services — the duplication this project explicitly rejects.

Instead, generalize, following the precedent the codebase already set: `mvs_bp_activity_media`
gained an `object_type` column so `Media\ObjectMediaLinkage` could serve "headless consumers
(e.g. BuddyNext) […] on their own objects (`bn_post`, `bn_space`)". Same move, wider.

**Audit of what actually needs to change** — smaller than it first looks:

| Store | Current key | Change needed |
|---|---|---|
| `mvs_reactions` | `media_id` | **add `object_type`** |
| `mvs_favorites` | `media_id` | **add `object_type`** |
| `mvs_media_views` | `media_id` | **add `object_type`** |
| `mvs_media_stats` | `media_id` (PK) | **add `object_type`** |
| `mvs_media_meta` | `media_id` (PK) | **add `object_type`** |
| `mvs_access_rules` / `_grants` | `media_id` | **add `object_type`** |
| `mvs_reports` | `target_type`, `target_id` | **none — already polymorphic.** Documents use `target_type='document'` |
| Comments | WP core `wp_comments` + `comment_type` | **none** — register a second `comment_type`, reuse `CommentService` |
| Tags / categories | WP taxonomies | **none** — register the existing taxonomies for the document type |
| `mvs_bp_activity_media` | `object_type` | **none — already done in 1.6.0** |

So the Free migration touches **six** tables, not fifteen.

**The critical detail is the unique constraints, not the columns.** `mvs_reactions` and
`mvs_favorites` both carry `UNIQUE KEY media_user (media_id, user_id)`, and `mvs_media_stats` /
`mvs_media_meta` use `media_id` in the PRIMARY KEY. Document ids and media ids are independent
auto-increment sequences, so **document #7 and media #7 both exist**. Without widening those
keys, a user favoriting document #7 would collide with their favorite of media #7 and one write
would silently vanish. Every such key becomes `(object_type, media_id, user_id)` — this is the
part of the migration that must not be got wrong.

**Backwards compatibility.** `object_type` is `varchar(20) NOT NULL DEFAULT 'media'`, and the
migration backfills every existing row to `'media'`. Existing queries that don't mention
`object_type` keep returning media rows only once the services add the predicate; the column
default means no write path breaks mid-deploy. No public identifier is renamed (Production
Rule 2), no default behaviour changes for existing installs (Rule 3), and it is a schema change
in a **minor** release (Rule 4).

Services (`ReactionService`, `FavoriteService`, `StatsService`, `AccessRulesService`) take an
`$object_type = 'media'` default parameter, so every existing caller is unchanged.

## Document type resolution — explicit, never by elimination

`Services\DocumentTypes::resolve( string $sniffed_mime, string $extension ): ?string` returns a
**named** type or `null`. `null` means reject. The function has no default branch — the last
statement is `return null`, not `return 'document'`.

| `doc_type` | Accepted MIME | Extensions |
|---|---|---|
| `pdf` | `application/pdf` | `.pdf` |
| `word` | `application/msword`, `…wordprocessingml.document` | `.doc`, `.docx` |
| `excel` | `application/vnd.ms-excel`, `…spreadsheetml.sheet` | `.xls`, `.xlsx` |
| `powerpoint` | `application/vnd.ms-powerpoint`, `…presentationml.presentation` | `.ppt`, `.pptx` |
| `odf_text` | `application/vnd.oasis.opendocument.text` | `.odt` |
| `odf_sheet` | `application/vnd.oasis.opendocument.spreadsheet` | `.ods` |
| `odf_presentation` | `application/vnd.oasis.opendocument.presentation` | `.odp` |
| `text` | `text/plain` | `.txt` |
| `markdown` | `text/markdown`, `text/x-markdown`, `text/plain` | `.md` |
| `csv` | `text/csv`, `text/plain` | `.csv` |
| `rtf` | `application/rtf`, `text/rtf` | `.rtf` |

**Two sniffing traps that will bite at implementation time:**

1. **OOXML and ODF are ZIP containers.** `finfo` returns `application/zip` (sometimes
   `application/octet-stream`) for `.docx`, `.xlsx`, `.pptx`, `.odt`, `.ods`, `.odp` on many
   systems. MIME alone cannot identify them. Rule: accept `application/zip` **only** when the
   extension is in the OOXML/ODF set **and** the archive's central directory contains the
   expected marker — `[Content_Types].xml` for OOXML, a `mimetype` entry for ODF. A bare `.zip`
   still fails, because `.zip` is not in the extension map at all.
2. **`.md` and `.csv` sniff as `text/plain`.** MIME cannot distinguish them from `.txt`. The
   extension is what separates them, which is exactly why resolution takes **both** arguments
   and why neither alone is trusted.

Consequences that follow from "explicit, never inferred":

- **`doc_type` is a stored column**, not something recomputed on read. It is the primary
  filter/sort discriminator for a 2000-row drive (big-site checklist item 5), so it needs to be
  indexed, not derived.
- **The REST upload takes an explicit `doc_type` argument** and the server rejects the upload
  when the caller's declared type disagrees with what the file actually resolves to. The client
  states intent; the server verifies it. A mismatch is a `400`, not a silent correction — a
  file that claims to be `markdown` and resolves to `word` is either a bug or an attack, and
  either way the caller needs to know.
- **Media type resolution is untouched.** Nothing in this feature edits
  `UploadService::get_media_type()` or the media allowlist. The two type systems never meet.

## Privacy — same vocabulary as media, one deliberate divergence

Documents carry a `privacy` column with the **same levels as `mvs_media_index.privacy`**, so a
client (BN, the app) has one privacy vocabulary across both content types.

| Level | Media behaviour | Document behaviour |
|---|---|---|
| `public` | anyone | identical |
| `members` (alias `loggedin`) | any logged-in user | identical |
| `friends` | `check_friends( author, user )` | identical |
| `space` | media calls this **`group`** — see below | space members only, via the BN seam |
| `private` | owner + admin only | identical |
| `custom` | explicit user id list | identical |
| `dm` | conversation participants | **not applicable** — documents are not DM attachments in v1 |

**The one divergence: media's `group` becomes `space`.** `PrivacyService::check_group()` resolves
through `groups_is_user_member()` and explicitly falls back to *deny* when BuddyPress is absent:

```php
if ( ! function_exists( 'groups_is_user_member' ) ) {
    // BuddyPress not active — fall back to private.
    return false;
}
```

This feature is **BuddyNext-only**, and BN Spaces are not BuddyPress groups. Reusing the token
`group` would mean either (a) calling `groups_is_user_member()`, which returns false on every
BN-only site, making the level permanently dead, or (b) naming a thing `group` while it resolves
Space membership — a direct violation of Coding Rule 13 ("names don't lie"). So documents use
`space`, resolved through `mvs_document_can_view` / `mvs_document_drive_access`, which BN
answers from `bn_space_members`.

For client convenience the REST layer **accepts `group` as an input alias** for `space` (and
`loggedin` for `members`, matching media), so code that shares a privacy picker between media
and documents keeps working. Storage is always the canonical token.

**Default privacy is `private`, not `public`.** `mvs_media_index.privacy` defaults to `'public'`,
which is right for a media community — the point is to be seen. A document library is the
opposite: people put contracts, invoices, and IDs in Drive. Defaulting a document store to
public would be a data-leak generator. This divergence is deliberate and is the one place the
document type intentionally does not copy media's default. Site owners can change it via
`mvs_pro_documents_default_privacy`.

### `space` parameter vs `privacy` parameter — orthogonal

These are two different questions and must not be collapsed, exactly as media keeps `group_id`
(meta) separate from `privacy` (column):

- **Which drive does it live in?** → `owner_type` + `owner_id`. When `owner_type = 'space'`,
  `owner_id` **is** the `bn_spaces.id`. That is the space parameter.
- **Who can see it?** → `privacy`.

The two combine freely. A document can live in a Space drive and be `public` (anyone with the
link sees it), `space` (members only), or `private` (uploader only, inside a shared Space). A
document in a *personal* drive can be `space`-scoped by being shared to that Space through
`mvs_pro_doc_permissions`.

### Resolution order

Privacy is the **default** answer; an explicit share **overrides** it — the same shape as media
layering access rules on top of privacy.

1. Owner of the drive, or drive-admin (`mvs_manage_documents` / Space `owner`|`moderator`) → allow
2. Explicit share grant — document grant, then nearest folder ancestor → allow at granted permission
3. `privacy` level check → allow / deny
4. Default deny

So `private` + explicit share to Bob = Bob sees it. That is what people expect from Drive, and
it is why privacy alone cannot be the whole model.

**Folders carry `privacy` too**, and moving a document into a folder applies
`PrivacyService::more_restrictive( $folder_privacy, $doc_privacy )` — the exact semantic
`AlbumService::add_items()` already uses when media joins an album. Adding to a stricter
container tightens the item; it never loosens it. The resolved value is **written to the row**,
not recomputed on read, so a later change to the folder's privacy cannot silently re-expose
files already inside it.

## Schema — Pro Migrator v11

Production Rule 4 forbids schema changes in patch releases, and Rule 2 forbids renaming a
column without an alias. **So the polymorphic owner columns go in now**, even though the group
and site drive UI ships in a later phase. Getting `owner_type` wrong in v11 costs a minor
release to fix.

### `mvs_pro_folders`

```
folder_id    bigint unsigned AI PK
owner_type   varchar(10)  NOT NULL DEFAULT 'user'   -- user | space | site
owner_id     bigint unsigned NOT NULL DEFAULT 0     -- user_id | bn_spaces.id | 0 for site
parent_id    bigint unsigned NOT NULL DEFAULT 0     -- 0 = drive root
name         varchar(255) NOT NULL
path         varchar(255) NOT NULL DEFAULT '/'      -- materialized ancestors: '/12/48/'
depth        smallint unsigned NOT NULL DEFAULT 0
created_by   bigint unsigned NOT NULL
created_at   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at   datetime DEFAULT NULL
PRIMARY KEY (folder_id)
KEY drive        (owner_type, owner_id, parent_id)
KEY subtree      (owner_type, owner_id, path(150))
UNIQUE KEY name_in_parent (parent_id, name(191))
```

**A folder is an album for documents, plus hierarchy.** It mirrors `AlbumService` feature-for-
feature — create/update, cover, item add/remove, reorder, item count, privacy — and adds the one
thing albums do not have: nesting. `PostTypes\Album` registers `mvs_album` **without**
`'hierarchical' => true`, so albums are flat; a Drive-shaped library needs
`/Work/2026/Invoices/`, so folders carry `parent_id`. Albums are not changed by this feature.

The other deliberate difference from albums: media can belong to **many** albums
(`AlbumService::albums_for_media()` returns a list, backed by `mvs_album_items`). A document
lives in **exactly one** folder, because that is what a filesystem means. Multi-placement is
what shares are for.

**Adjacency (`parent_id`) + materialized `path`, deliberately not recursive CTEs.** WordPress
still supports MySQL 5.7, where `WITH RECURSIVE` does not exist. The `path` column makes
"everything under folder X" a single indexed `LIKE '/12/48/%'` and gives breadcrumbs with zero
extra queries — the ancestor chain is parsed from the row already in hand. A rename or move
rewrites one subtree prefix in a single `UPDATE`. `depth` is capped (default 20, filterable) so
`path` stays inside varchar(255).

`UNIQUE KEY name_in_parent` prevents two folders with the same name in one parent — the
concurrency guard for two clients creating "Invoices" simultaneously.

### `mvs_pro_documents`

```
document_id    bigint unsigned AI PK
owner_type     varchar(10)  NOT NULL DEFAULT 'user'
owner_id       bigint unsigned NOT NULL DEFAULT 0
folder_id      bigint unsigned NOT NULL DEFAULT 0
uploaded_by    bigint unsigned NOT NULL
name           varchar(255) NOT NULL
slug           varchar(255) NOT NULL
description    text
doc_type       varchar(20)  NOT NULL              -- named type; NO default, NO fallback value
mime           varchar(100) NOT NULL DEFAULT ''
extension      varchar(20)  NOT NULL DEFAULT ''
file_size      bigint unsigned NOT NULL DEFAULT 0
file_hash      varchar(64)  NOT NULL DEFAULT ''
storage_path   text NOT NULL                        -- rel path inside the protected dir
storage_driver varchar(20)  NOT NULL DEFAULT 'local'
status         varchar(20)  NOT NULL DEFAULT 'active'  -- active | trashed
moderation_status varchar(20) NOT NULL DEFAULT 'approved'
privacy        varchar(20)  NOT NULL DEFAULT 'private'  -- diverges from media's 'public' on purpose
trashed_at     datetime DEFAULT NULL
search_text    longtext                              -- extracted at upload; FULLTEXT indexed
scan_status    varchar(20)  NOT NULL DEFAULT 'skipped' -- skipped | clean | flagged
view_count     bigint unsigned NOT NULL DEFAULT 0
download_count bigint unsigned NOT NULL DEFAULT 0
created_at     datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
updated_at     datetime DEFAULT NULL
PRIMARY KEY (document_id)
UNIQUE KEY slug (slug)
KEY listing   (owner_type, owner_id, folder_id, status, created_at)
KEY by_type   (owner_type, owner_id, doc_type, status)
KEY uploader  (uploaded_by, created_at)
KEY file_hash (file_hash)
KEY trash     (status, trashed_at)
KEY moderation (moderation_status, created_at)
FULLTEXT KEY search (name, description, search_text)
```

`search_text` holds text extracted at upload — the thing that makes a document library searchable
rather than a folder on a server. Media has no equivalent because its content is pixels. The
FULLTEXT index follows the same move 1.3.0 made for media search in the 100k-readiness work.
Legacy binary formats extract nothing and are findable by filename only; the UI says so rather
than silently returning zero results.

`scan_status` records the outcome of the `mvs_document_scan_file` seam. MediaVerse ships no
scanner — it ships the hook, so a site owner can wire ClamAV or an API and reject on `WP_Error`.
Default `'skipped'` is honest: no scanner configured means no scan happened.

`KEY listing` is the folder-view query verbatim — drive, folder, non-trashed, newest first.
`KEY by_type` backs "show me every PDF in this drive", which is the filter people actually reach
for once a drive has a few hundred files.

`doc_type` deliberately has **no column default**. A row cannot be written without a resolved
type, so a future code path that forgets to set it fails loudly at insert instead of quietly
storing an untyped document.

### `mvs_pro_doc_permissions`

```
permission_id bigint unsigned AI PK
target_type  varchar(10) NOT NULL                  -- document | folder
target_id    bigint unsigned NOT NULL
grantee_type varchar(10) NOT NULL                  -- user | role | link
grantee_id   bigint unsigned NOT NULL DEFAULT 0    -- user_id; 0 for role/link
grantee_role varchar(60) NOT NULL DEFAULT ''
token_hash   varchar(64) NOT NULL DEFAULT ''       -- SHA-256 of the link token
permission   varchar(10) NOT NULL DEFAULT 'view'   -- view | comment | edit
allow_anon   tinyint(1)  NOT NULL DEFAULT 0
created_by   bigint unsigned NOT NULL
expires_at   datetime DEFAULT NULL
created_at   datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
PRIMARY KEY (permission_id)
UNIQUE KEY token_hash   (token_hash)
KEY target       (target_type, target_id)
KEY grantee_user (grantee_type, grantee_id)
KEY expires      (expires_at)
```

**Link tokens are stored hashed, never in plaintext.** The raw token is shown to the sharer
once, at mint time, and never again — a database leak then yields no working links. Lookup is
exact-match on `token_hash`, so hashing costs nothing.

Sharing a **folder** grants the whole subtree. Sharing a **document** grants one file. Both live
in one table so permission resolution is a single query.

## The BuddyNext seam — why MediaVerse must not know what a Space is

BuddyNext owns Spaces in its **own tables** — `bn_spaces`, `bn_space_members` — not BuddyPress
groups. MediaVerse must therefore **never query them directly**. MediaVerse Pro has to keep
working on a site where BuddyNext is not installed, and a direct `bn_*` read is a hard
dependency on a plugin that may be absent, deactivated, or a different version.

Instead: `owner_type = 'space'` is an **opaque token to MediaVerse**. It stores the id, and
delegates every question about it to a filter that BuddyNext answers from its
`Bridges\WPMediaVerseBridge`:

```php
// MediaVerse asks; BuddyNext answers. Default: no space drives exist.
apply_filters( 'mvs_document_drive_owners', [], $user_id );        // drives this user can see
apply_filters( 'mvs_document_drive_access', false, $owner_type, $owner_id, $user_id );
apply_filters( 'mvs_document_drive_label',  '',  $owner_type, $owner_id );
```

This is the pattern BN already uses against MediaVerse — it hooks `mvs_rest_can_access`,
`mvs_rest_require_auth`, `mvs_buddynext_active` and consumes media through
`Media\WPMediaVerseBridge` + `Media\ObjectMediaLink`. Documents follow the same seam rather
than inventing a new coupling style.

**What BN's Space model implies for the resolver** (BN's problem to answer, but the contract
must be able to express it):

- Spaces are **typed** `open | private | secret`. A **secret** space's drive must not leak its
  existence — an unauthorized caller gets 404, never 403.
- Membership carries a **status** `active | pending | invited | banned`. Only `active` is a
  member. A `pending` or `banned` user must not reach the drive.
- Membership carries a **role** `owner | moderator | member`, which is the natural source of
  the document permission (`edit` vs `view`).
- Spaces are **hierarchical** (`bn_spaces.parent_id`). Whether a child Space inherits its
  parent's drive is a BuddyNext decision, expressible through the same filter.

**Frontend-presence policy.** `Core\Plugin` already stands MediaVerse's own UI down when
`mvs_buddynext_active` is true — BN owns `/messages/`, `/activity/`, `/members/` and the media
UX on its own surfaces. The document UI follows that same policy: when BN is active, MediaVerse
renders no document frontend and BN builds its own on the REST API. **MediaVerse's own document
templates are the standalone fallback; the REST surface is the actual product.** That is what
"MediaVerse prepares the skeleton so BN can use it" means in code.

## Permission resolution — the N+1 trap

A folder listing of 200 documents must not become 200 permission queries. Resolution is
batched, and this is a hard design constraint, not an optimization:

1. **Owner fast path.** `owner_type`/`owner_id` matches the caller, or `mvs_document_drive_access`
   returns true for a Space drive, or the caller holds `mvs_manage_documents` for the site drive
   → full access, done. The Space answer is resolved **once per request per drive** and cached on
   the service — the filter must never be called per row, or BN's membership lookup becomes the
   N+1 this section exists to prevent.
2. **Ancestor chain, zero queries.** Parsed from the folder row's `path` (`/12/48/` → `[12, 48]`).
3. **One share query** for the whole page: shares where
   `(target_type='folder' AND target_id IN (ancestors + self)) OR (target_type='document' AND target_id IN (page doc ids))`,
   narrowed to the caller's user id and role slugs, `expires_at` null-or-future.
4. **Resolve in PHP.** Most specific wins: document grant > nearest folder grant > drive
   ownership. Highest permission wins among equals.

Two queries per page regardless of page size.

## Storage and delivery — the real risk surface

Serving user-uploaded files from the site's own origin is where this feature can hurt people.
The tree and the sharing model are ordinary CRUD; delivery is not.

**Location.** `uploads/wpmediaverse-documents/<random-per-install-segment>/`, protected by
`.htaccess` (`Deny from all`), `web.config`, and an `index.php` guard. The random segment
matters because nginx ignores `.htaccess` — the deny rule is documented for nginx installs, but
the unguessable path is what protects a misconfigured server. **No direct URL is ever emitted**,
for any document, at any privacy level.

**Delivery** is a gated Pro endpoint that streams the file after resolving permission on every
request — never a signed URL treated as a bearer token. Free learned this the hard way in
1.4.0 ("non-public `/serve` re-checks `can_view` per request — closes the signed-URL-as-bearer-token gap").

> **Superseded 2026-08-05 by `plan/2026-08-05-document-library-plan.md` Part A.** This section
> originally said "always `attachment`, never inline". Correct as a security default, wrong as a
> product — a library where every file is a download link is a filing cabinet. The replacement is
> **two endpoints**: `/download` is `attachment` always, `/preview` is `inline` for **PDF only**.
> Tier-2 formats (`.md`/`.txt`/`.csv`) are server-rendered to sanitized HTML, so the raw file is
> never served at all; tier-3 (`.docx`/`.xlsx`) is fetched as bytes and parsed in JS, so the
> browser never navigates to it. Inline exposure is therefore exactly one format wide.

Response headers:

```
# /documents/{id}/download — every type, no exceptions
Content-Disposition: attachment; filename="…"

# /documents/{id}/preview — PDF only
Content-Disposition: inline; filename="…"
X-Frame-Options: SAMEORIGIN                       ← framed by our own viewer

# both
X-Content-Type-Options: nosniff
Content-Security-Policy: default-src 'none'; sandbox
Cache-Control: private, no-store
```

**Format handling.** The allowlist is explicit MIME + extension pairs, and both must agree with
the sniffed type — extension alone is not trusted.

| Group | Handling |
|---|---|
| PDF | Inline preview via the existing `pdf-viewer` block; download forced on `/download` |
| docx/xlsx | Parsed client-side (mammoth.js / SheetJS, lazy-loaded); raw bytes never navigated to |
| pptx, ODF | No preview — metadata card + download (see plan Part A tier 4) |
| .md, .txt, .csv | Server-rendered to sanitized HTML. **Markdown is never echoed as HTML** without a sanitizer — that is stored XSS. Parsedown with `setSafeMode(true)` **and** `setMarkupEscaped(true)` |
| .rtf | No preview — metadata card + download |
| .doc/.xls/.ppt (legacy) | Accepted as **opaque bytes only**. Never parsed, never previewed. These are macro-capable formats; the plugin's job is to hand them back byte-identical, not to understand them |
| HTML, SVG, XML, .zip | **Rejected.** HTML/SVG/XML are stored-XSS vectors when served from your own origin; .zip is out of v1 scope by decision |

## No versioning — and the data-loss edge that comes with it

Per the owner decision there is no version history. The one consequence worth designing around:
**re-uploading a file with an existing name must not silently destroy the original.**

Mitigation that costs nothing and needs no version table — mirror what Drive actually does by
default: a same-name upload creates a **new document row** ("Invoices (2).pdf") rather than
overwriting. Replacement becomes an explicit, confirmed action on an existing document. The
member gets no-versioning semantics without a silent overwrite.

Adding real versioning later means a Migrator bump and therefore a **minor** release
(Production Rule 4). That is the known cost of this decision, recorded here so it is not a
surprise later.

## REST surface — `mvs-pro/v1`

Rule 18 makes this mandatory, not optional: *"the API entry point is MANDATORY for
MediaVerse, never an exception: a native mobile APP is planned, so every member-facing feature
must be fully drivable through REST alone."* This feature is API-first by design — the web UI
is one client among several.

```
GET    /drives                                 drives visible to me (mine, my groups, site)
GET    /folders?drive=user:12&parent=0         folder children
POST   /folders                                create
PATCH  /folders/{id}                           rename / move
DELETE /folders/{id}                           trash (subtree)
GET    /documents?folder=48&page=1&per_page=50 paginated listing
POST   /documents                              upload (multipart; doc_type REQUIRED)
GET    /documents/{id}                         metadata
PATCH  /documents/{id}                         rename / move / describe
DELETE /documents/{id}                         trash
GET    /documents/{id}/download                gated stream
GET    /documents/{id}/permissions             list grants
POST   /documents/{id}/permissions             grant to user or role
DELETE /permissions/{id}                       revoke
POST   /documents/{id}/permissions/link        mint link token (raw token returned ONCE)
GET    /documents/search?q=…&drive=…           cross-drive search
```

Every list route returns honest `X-WP-Total` / `X-WP-TotalPages` from a dedicated `COUNT(*)`,
never `count()` over a fetched result set (big-site checklist item 4). Every controller extends
`WP_REST_Controller` with a real `get_item_schema()` (Coding Rule 6). Auth must work outside
the cookie/nonce browser context — Application Passwords, as the mobile contract requires.

Pro registers its prefix with Free's `mvs_rest_gated_route_prefixes` filter so private-community
mode covers `mvs-pro/v1` too.

## Complete flow map — what already exists vs what is genuinely new

Traced end-to-end against the media flow. **Most of this is already built.** The column that
matters is the last one.

### Upload flow

| Step | Media today | Documents | New? |
|---|---|---|---|
| Entry point | `.mvs-fab` in `templates/partials/shared-ui-frame.php`, `data-wp-context='{"uploadMode":"photo"}'` | same FAB, `uploadMode` already a first-class concept | **reuse** |
| Modal | `shared-ui-frame.php` modal + `mvs-modal-dropzone` | same modal | **reuse** |
| File picker | `state.uploadAccept` getter — *"Auto-detect flow: accept every supported type; the picked file(s) determine the mode"* | extend the returned list with document MIMEs when the feature is on | **~2 lines** |
| Type routing | `actions.detectMode()` — 6 lines: `video/` → video, `audio/` → audio, else photo/gallery | add a document branch **before** the photo fallback | **~3 lines** |
| Previews | `generatePreviews()` already emits `{uid, src, name, type, isAudio, isOther}` and *"renders filename + an icon for non-image types"* | documents are exactly the `isOther` case that already works | **reuse** |
| Drag/drop | `assets/js/frontend/dropzone.js` — type-agnostic | unchanged | **reuse** |
| Transport | `POST /mvs/v1/media` | `POST /mvs-pro/v1/documents` | **new controller** |
| Validation | `UploadService::handle()` | `DocumentService::store()` + `DocumentTypes::resolve()` | **new** |
| Storage | `StorageService` → `LocalDriver::store()` | same driver, protected dir | **reuse** |
| Index write | `mvs_media_index` | `mvs_pro_documents` | **new table** |

**Auto-assign resolves cleanly against the "never infer" rule** — they operate at different
layers, and conflating them is the mistake to avoid:

- **The router may infer.** One upload button, sniff the file, send it to the media store or the
  document store. This is `detectMode()` doing what it already does.
- **The type resolver may not.** Once routed to documents, `DocumentTypes::resolve()` must return
  a *named* type or reject. There is still no "everything else is a document" branch.

The router's unknown case is **reject**, not "assume document". A `.exe` matches no media type
and no document type, so it fails — it does not fall through into the document store.

### Single view

`templates/media-single.php` has a clear anatomy that the document single view mirrors
section-for-section:

| `media-single.php` | `document-single.php` | New? |
|---|---|---|
| `TemplateHelpers::site_header()` / `site_footer()` | identical | **reuse** |
| `.mvs-media-header-row` — author avatar, display name | identical | **reuse** |
| `.mvs-media-gate` — lock glyph + login CTA when privacy denies | identical | **reuse** |
| `.mvs-media-image` / `.mvs-media-video` / `.mvs-media-audio` | `.mvs-doc-preview` — type icon, filename, size, download button; **PDFs embed the existing `pdf-viewer` block** | **one new partial** |
| `.mvs-media-description` | identical | **reuse** |
| `.mvs-social-wrapper` / `.mvs-social-bar` — reactions, favorite | identical, `object_type='document'` | **reuse** |
| `.mvs-inline-edit` — rename / privacy / description | identical, plus "move to folder" | **reuse + 1 field** |

**The `pdf-viewer` block already exists and already does the right thing** — its own description
says it *"respects the same privacy + access rules as other media."* It survived the 1.2.3
document-upload removal precisely because the read side was left intact. It is the PDF preview,
not something to rebuild.

### Folder view

| `explore.php` / `album.php` | `folder.php` | New? |
|---|---|---|
| `MediaRepository::prefetch()` before the loop (the 1.7.0 N+1 fix, 170 → 6 queries) | `DocumentRepository::prefetch()` — **same pattern, mandatory** | **new, copy the pattern** |
| `AccessRulesService::prefetch_active_rules()` | batched permission resolve (2 queries/page) | **new, copy the pattern** |
| `media-grid` block — thumbnail tiles | `document-list` — **rows, not tiles**: icon, name, size, modified, owner | **new block** |
| `load-more.js` | unchanged | **reuse** |
| Sort/filter chips on explore | filter by `doc_type`, sort by name/size/date | **reuse pattern** |
| — | breadcrumb trail from the materialized `path` | **new partial** |

Files are rows, not tiles. A grid of identical PDF icons carries no information; name, size, and
modified date are what people scan. This is the one place the document UI deliberately does not
copy media's layout.

## Frontend — no shared markup with media

A Pro-owned page and rewrite following `GamificationTemplateLoader`; page id in
`mvs_pro_page_documents`. Templates live in a **new** `templates/documents/` directory:

```
templates/documents/documents.php        shell
templates/documents/documents-body.php   body
templates/documents/partials/…           breadcrumb, folder-row, file-row, share-modal, empty-state
```

Splitting shell from body matches the existing `compete-hub.php` / `compete-hub-body.php` pair.
**Zero partials are shared with `explore.php`, `album.php`, `collection.php`, or
`media-single.php`**, so a document can never be rendered by a media grid, and a media item can
never appear in the document list.

**This is separation of rendering, not separation of product.** Documents share navigation and
social surfaces with media — dashboard tab, profile nav item, unified search, one activity feed,
one upload button. The precedent is already in the codebase: Free's `dashboard-view/view.js`
carries `isChallengesTab` / `isBattlesTab` / `isTournamentsTab` for **Pro** features, and nobody
experiences gamification as a separate plugin. Full interlink surface in
`plan/2026-08-05-document-library-plan.md` Part C. New `assets/css/documents.css` under CSS file ownership
(Coding Rule 12); no inline cosmetic styles (Rule 19); every `return` in a render path pairs
with a visible empty state (Rule 11).

## Admin — third entry point

Rule 18 requires the site owner to see and manage this, not just members. A **Documents**
submenu under the plugin: list across all drives, filter by drive / owner / type / status, sort
by size and date, storage totals, trash purge, orphan-file cleanup. Admin HTML in
`templates/admin/` only (Coding Rule 4). Site-wide aggregates go through
`AdminAggregatesService`, never raw `SUM`/`COUNT` (Coding Rule 16).

## Big-site readiness (checklist, applied)

| # | Item | How this design satisfies it |
|---|---|---|
| 1 | Pagination | `LIMIT`/`OFFSET` + `COUNT(*)` on every list route and admin table |
| 2 | Indexes | `KEY listing` is the folder query verbatim; `KEY subtree` backs the path prefix scan |
| 3 | N+1 | Permission resolution is 2 queries/page regardless of page size (see above) |
| 4 | `COUNT(*)` | Dedicated count methods; never `count()` over a result set |
| 5 | Filter + sort | Drive, folder, type, status, owner; sort by name/size/date |
| 6 | Mobile + RTL | Token spacing, `margin-inline-*`, rows stack under 480px, verified at 390px |
| 7 | Dark mode | Tokens only, no raw hex |
| 8 | A11y | Semantic list/table markup, ARIA labels on icon-only buttons, keyboard-reachable tree |
| 9 | Empty / error / loading | Every async surface, per Rule 11 |
| 10 | Caching | Folder-children and share-resolution cached per request; invalidated on write |
| 11 | Concurrency | `UNIQUE KEY name_in_parent`; already-deleted / already-moved handled gracefully |

## Settings, hooks, capabilities

**Settings** — `mvs_pro_documents_enabled` (default `'0'`, feature-flagged like the gamification
modules), `mvs_pro_documents_allowed_types`, `mvs_pro_documents_max_size`,
`mvs_pro_documents_anon_links` (default `'0'`), `mvs_pro_documents_link_ttl`.

**Hooks** (Coding Rule 5, `mvs_` prefix) — actions `mvs_document_uploaded`,
`mvs_document_deleted`, `mvs_document_permission_granted`, `mvs_folder_created`; filters
`mvs_document_allowed_file_types`, `mvs_document_max_size`, `mvs_document_can_view`,
`mvs_document_max_depth`.

**Capabilities** — `mvs_manage_documents` (site drive + admin screens); member access resolves
through drive ownership and shares.

**Quota** — documents count toward Pro's existing quota module. A drive that ignores quota is a
storage-abuse hole.

## Phasing

| Phase | Scope |
|---|---|
| **0** | **Free**: `object_type` on the six engagement tables + widened unique keys + backfill to `'media'`; services take `$object_type = 'media'`. Ships alone, changes nothing observable, de-risks the rest |
| **1** | **Pro** Migrator v11 (three tables, polymorphic owner from day one), services, full REST surface, the `mvs_document_drive_*` filter seam, WP-CLI seeding. Personal drives only. No UI |
| **2** | Standalone frontend drive UI (stands down under `mvs_buddynext_active`) + admin screen. Personal drives usable end-to-end |
| **3** | Space drives + site-wide library — schema already supports them; this is resolver logic + BN's bridge implementation |
| **4** | Feature parity switched on — reactions, comments, favorites, views/stats, tags, moderation, reports against `object_type='document'` |
| **5** | Search, trash/restore, quota integration, notifications on share |

Phase 1 ships nothing member-visible, which is a deliberate Rule 18 exception for one phase
only: the tables and API land together, and Phase 2 completes the three entry points before any
release is tagged. **No release goes out with Phase 1 alone.**

## Verification (must pass before "done")

- Permission matrix probed per role × drive type × share type — owner, shared-user, shared-role,
  link, anonymous, non-member, logged-out
- Direct-URL fetch of a stored file returns 403/404 on **both** Apache and nginx
- Every rejected format actually rejected (HTML, SVG, XML, .zip, extension/MIME mismatch)
- `DocumentTypes::resolve()` returns `null` — not a type — for every unlisted input, asserted by
  a unit test that feeds it the media MIMEs (`image/jpeg`, `video/mp4`, `audio/mpeg`) and junk
- A `.docx` renamed to `.zip` is rejected; a real `.zip` renamed to `.docx` is rejected (the
  OOXML marker check, both directions)
- Declared `doc_type` disagreeing with the resolved type returns `400`, never a silent fix
- Legacy `.doc`/`.xls` round-trip byte-identical
- 2000-document folder + 20-level tree: listing stays paginated, query count flat
- 390px browser verification of every screen (non-negotiable, per-item)
- Free's media surfaces unchanged — no document appears in any media grid
- **Id-collision proof**: with document #7 and media #7 both existing, one user favorites,
  reacts to, views, and sets meta on *both*. All twelve rows survive independently. This is the
  single highest-risk defect in the Free migration and it must have its own regression test
- Existing installs upgrade cleanly: every pre-migration row reads back as `object_type='media'`,
  and every media surface behaves identically before and after

## Open questions

1. **Space drives** — does every Space get a drive automatically, or does a Space owner enable
   it? Do `secret` Spaces get drives at all? Does a child Space inherit its parent's drive?
   (All answerable inside BN's bridge; MediaVerse only needs to know the contract can express it.)
2. **Space roles → permissions** — is `moderator` an `edit` grant? Can a plain `member` upload,
   or only read?
3. **Site-wide library** — who can upload: admins only, or any member with a capability?
4. **Quota** — do documents share the media quota pool, or get their own allowance?
5. **Anonymous links** — the site setting defaults off; should there also be a per-drive or
   per-user cap on link TTL?
