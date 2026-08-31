# Fix plan: album / collection IDs collide with media IDs

**Date:** 2026-08-08
**Target version:** 2.4.0
**Owner:** Varun
**Reviewer:** _unassigned — this plan exists to be reviewed before any code is written_
**Status:** **IMPLEMENTED in the working tree — NOT released, NOT browser-tested.** Free + Pro,
target 2.4.0 paired. See "Implementation status" immediately below before reading the plan body:
several open questions in the later sections were closed during implementation and are marked there.

Basecamp cards:
- [Album post IDs collide with media IDs in mvs_media_index](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10183850886)
- [mvs_category carries two ID spaces](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10183851181)

---

## Implementation status (2026-08-08)

Everything below was implemented after this plan was written. **The plan body is kept as the
reasoning record** — where a section poses an open question that has since been decided, the
decision is noted inline. Read this table first.

| Area | State | Where |
|---|---|---|
| Album privacy/type → post meta | done | `AlbumService::get/set_privacy()`, `get/set_album_type()` (`_mvs_privacy`, `_mvs_album_type`) |
| `AlbumService::create()` stops writing the index | done | `includes/Services/AlbumService.php` |
| `AlbumController` write + read via the service | done | `includes/REST/Controller/AlbumController.php` |
| **Album-list privacy gate rewritten onto post meta** | done — **review this first** | `AlbumController::get_items()`. It is the ONLY privacy gate on that endpoint (per-item `can_view()` was deliberately removed earlier). The old gate joined `mvs_media_index` on the post ID, so once albums stopped writing rows it would have listed every private album |
| `PrivacyService` post-type authoritative; `media_type` sniff removed | done | `includes/Services/PrivacyService.php` |
| `MediaRepository` refuses `wp_posts` IDs | done | `set()` / `set_many()`, memoised, `_doing_it_wrong()` |
| Both `purge_index_record()` calls deleted | done | `PostTypes/Album.php`, `PostTypes/Collection.php` |
| Album categories removed; metaboxes closed | done | `AlbumService`, `AlbumController`, `templates/album.php`, `MediaCategory`/`MediaTag` (`meta_box_cb => false`) |
| Migrator v26 | done | `includes/Core/Migrator.php` |
| Pro: 11 album-ID writes moved off the index | done | MediaPress / rtMedia / BuddyBoss importers + migration admins |
| Pro: `AlbumMarkerLookupTrait` | done | `wpmediaverse-pro/includes/Integrations/AlbumMarkerLookupTrait.php` |
| `wp mvs diagnose_cpt_ids` (read-only) | done | `Services/CptIdCollisionService.php`, `CLI/Commands.php` |
| Regression tests | **written, never executed** | `tests/unit/CptIdCollisionTest.php` (6 tests) |
| Browser verification | **not done** | album list / single / edit / delete, desktop + 390px |

### Decisions taken during implementation

| Question in the plan | Decision |
|---|---|
| §6/§7 interim 2.4.0 patch? | **No.** Owner: no dead code. The guard it would carry was temporary by construction. Single 2.4.0 release |
| §3.4 album categories | **Removed.** Write-only; nothing read them back except the album page |
| §3 `mvs_tag` in scope? | **Yes** — core's own metabox and `/wp/v2/mvs-albums` were live album-space write paths |
| §4.6.1 album privacy cascade | **Withdrawn as a bug.** Album privacy governs album visibility only; non-cascading is coherent. Remains a UX question |
| §5 slug repair | **Report, do not regenerate.** Regenerating changes a live permalink; the original is unrecoverable either way |
| §5 colliding-album privacy | **Preserve the current effective value**, log for review. Changing behaviour during a fleet upgrade is worse than an inherited wrong value |

### Verified against a live database (not unit-tested)

- Album created at post ID 84 where photo 84 already existed → index count unchanged; the photo kept its own slug and `public` privacy. **This is the exact operation that silently corrupted a photo before the fix.**
- Repository guard blocked `set()` and `set_many()` on an album ID; genuine media writes unaffected.
- Owner sees their own private album; stranger and logged-out denied — **Basecamp 10071824547 passes without the removed workaround**.
- v26: attribute-only row migrated and removed, colliding row preserved intact, collision logged to `mvs_cpt_id_collisions`. Re-run is a no-op.

### Two defects found in my own implementation, and fixed

1. Moving Pro's import markers to post meta left `find_existing_album()` reading `mvs_media_meta` — the exact read/write split whose docblock records it caused **duplicate albums on every re-run**. Fixed with a legacy fallback so a post-upgrade re-run still finds pre-2.4.0 albums.
2. The migration deleted `group_id`, which is **not** album-only — `PrivacyService::check_group()` reads it off media rows. On a colliding ID that would have destroyed the photo's group assignment. Now neither copied nor deleted there.

### Gates

`composer ci:no-journeys` green except `2.4 wppqa baseline missing`, which is **pre-existing** (baseline deleted in `dc3e80df`). `phpstan-baseline.neon` was regenerated (line-number drift from new CLI methods); one duplication cluster re-blessed after verifying by stash that it pre-dates this work.

### Open for the reviewing team

1. **`categories` is gone from album REST responses** — breaking. Grep BuddyNext and the mobile app for `album.categories`; needs a changelog entry.
2. **`composer phpcs:fix` reformatted unrelated files** (`IntegrationsPage`, `MediaListPage`, `MessagingService`, `MemberDataMap`). Review before committing.
3. **Residual write vector:** `mvs_album` registers `show_in_rest => true` with no controller override, so core's `/wp/v2/mvs-albums` still exposes both taxonomies as writable. Inert rows; closing it needs a dedicated category admin page first.
4. **Nothing can undo** an overwrite whose album was later deleted, a lost original slug, or a privacy overwrite indistinguishable from a deliberate change.

---

## TL;DR for the reviewer

`mvs_media_index.media_id` is `AUTO_INCREMENT` for media. **Albums and collections write their
`wp_posts` ID into that same primary key.** Two independent ID sequences, one column.

Consequences, all confirmed against the local database, not theorised:

1. **Silent data overwrite.** Album 77 overwrote image 77's `slug`. The image "City Skyline at
   Dusk" now has `slug = qa-dropzone-test` — its permalink is the album's.
2. **Access control inverts.** Album 77 belongs to user 1. `PrivacyService::check_access(77)`
   resolves the author as **user 11** (the image's uploader). The album owner is denied their own
   album; an unrelated member is granted it.
3. **Data loss on delete.** Deleting album 77 calls `purge_index_record(77)` and destroys the
   image's index row. File survives on disk, item vanishes from every surface.

**This has already been patched around once.** Commit `3cfff321` (2026-07-08, Basecamp 10071824547)
fixed "owner denied viewing their own private album" by adding a `media_type` discriminator, and
described the cause as *"that row's post_author is unreliable (0 or stale: 48->5 vs 10, 49->3 vs 11,
197->0 vs 4)"*. Those are not stale values. **They are three collisions on a customer site** — album
48's row belonged to media 48's owner, and so on. The July fix treated the symptom and cannot catch
the case where the colliding row has a real `media_type`, which is exactly case 2 above.

Per the development rule *"if something is done wrong, don't follow the mistake — correct course"*,
this plan removes the workaround rather than extending it.

---

## 1. Root cause, in one flow

```
AlbumService::create()                       includes/Services/AlbumService.php:53-87
  └─ wp_insert_post( ['post_type' => 'mvs_album'] )   →  $album_id  (a wp_posts ID)
  └─ $repo->set_many( $album_id, [ slug, privacy, album_type ] )
        └─ MediaRepository::set_many()       includes/Repository/MediaRepository.php:762
              └─ writes mvs_media_index WHERE media_id = $album_id
                    ├─ row exists (a real media item) → UPDATE  ← overwrite
                    └─ row absent                      → INSERT  ← explicit id into AUTO_INCREMENT
```

Both branches are wrong. The UPDATE corrupts a media row. The INSERT consumes an ID from the media
sequence and creates a row that is neither media nor album.

`PostTypes/Album.php:80` and `PostTypes/Collection.php:75` then call `purge_index_record( $post_id )`
on permanent delete, which is where the data loss happens.

### Why an index row exists at all

Albums needed somewhere to store `privacy` / `album_type`, and `MediaRepository` was the nearest
available writer. Nothing about an album requires a media-index row — **5 of the 7 albums on this
install have no index row and work fine**, which means every read path already tolerates its
absence. That is the strongest argument that removing it is safe.

---

## 2. Confirmed impact (local database, 2026-08-08)

Reference install: 80 index rows, `AUTO_INCREMENT` 157, 7 albums, 2 collections.

| Measure | Count |
|---|---|
| Album + collection posts | 9 |
| …that have an `mvs_media_index` row | **2** |
| …of those, colliding with a **real media row** | **1** |
| Clean privacy-only rows | 1 |
| Real media rows | 79 |

**A 50% collision rate among the CPT rows that exist**, on a nine-post install. The July commit
cites three more on a customer site (48, 49, 197).

### The colliding row, in full

| | album (wp_posts 77) | media (mvs_media_index 77) |
|---|---|---|
| Title | QA Dropzone Test | City Skyline at Dusk |
| Author | **1** | **11** |
| Slug | — | **`qa-dropzone-test`** ← the album's slug, written over the image's |
| Privacy | (reads as) public | public |
| media_type | — | `image` |
| file_path | — | `2026/08/e2132b3d0ae318ce.jpg` |

### Why likelihood is high, not marginal

`wp_posts` IDs are shared across posts, pages, revisions and attachments, so they climb fast.
`mvs_media_index.media_id` starts at 1 and climbs with uploads. **The two ranges overlap for the
entire life of an active library.** Any site with more media items than its lowest album post ID is
exposed.

### Diagnostic for any customer site

```sql
SELECT p.ID AS cpt_id, p.post_type, p.post_title, p.post_author AS cpt_author,
       m.media_type, m.title AS index_title, m.slug AS index_slug, m.post_author AS index_author,
       CASE WHEN m.file_path IS NOT NULL THEN 'COLLISION - real media'
            ELSE 'privacy-only row' END AS verdict
FROM wp_posts p
INNER JOIN wp_mvs_media_index m ON m.media_id = p.ID
WHERE p.post_type IN ('mvs_album','mvs_collection')
ORDER BY verdict, p.ID;
```

Any row reading `COLLISION - real media` is live corruption on that site.

---

## 3. The second bug: `mvs_category` shares `wp_term_relationships.object_id`

Same root cause — two ID spaces in one column — in a different table.

| Writer | ID space |
|---|---|
| `Services/AlbumService.php:99` | album **post** ID |
| `REST/Controller/AlbumController.php:515` | album **post** ID |
| `REST/Controller/MediaController.php:825` | `mvs_media_index.media_id` |
| `REST/Controller/MediaController.php:977` | `mvs_media_index.media_id` |

Read back via `get_the_terms()` (`MediaController.php:826`, `AlbumController.php:729`), which is
taxonomy-scoped but **not** ID-space-scoped. Where the integers coincide it returns the union, and
re-saving either object persists the merged set to both.

### Correction to the Basecamp card

The card states `mvs_tag` is clean. **That is true of the write side only.** Measured:

| Taxonomy | Relationships | object_id is a CPT post | object_id ambiguous (both) |
|---|---|---|---|
| `mvs_category` | 60 | 1 | **1** |
| `mvs_tag` | 238 | 4 | **4** |

All `mvs_tag` writes pass media IDs, so no album has been *deliberately* tagged — but four integers
are simultaneously a media ID and an album/collection post ID. Any future album-tagging feature, or
any read of `get_the_terms( $album_id, 'mvs_tag' )`, returns that media item's tags. The write
discipline is holding the bug back; the structure is not.

**Reviewer question:** do we fix `mvs_tag` now or rely on write discipline? **Answer changed by
§3.4 — write discipline is not holding it.** Core's own Album metabox and `/wp/v2/mvs-albums` both
write album-space `mvs_tag` today, with no plugin code involved. Both taxonomies need the same
treatment.

---

### 3.4 Taxonomy map (completed 2026-08-08) — the collision is the *smaller* half of this bug

The map changes what bug #2 actually is. Collisions are real, but they are a side effect of
something more basic:

> **Album categories are write-only. Nothing in the plugin ever reads them back except the album's
> own page.**

Every browsing, filtering and archive surface resolves categories by joining
`term_relationships.object_id` to **`mvs_media_index.media_id`**:

| Surface | Join | file:line |
|---|---|---|
| Explore / profile feed | `trc.object_id = m.media_id` | `MediaRepository.php:1484, 1491` |
| `GET /media?category=` | same | `MediaController.php:416, 428, 452` |
| Media Grid block | same | `src/blocks/media-grid/render.php:76, 86` |
| Category archive `/media-category/{slug}/` | `TemplateLoader.php:726-735` swaps to `explore.php`, which resolves the slug then queries media-index space | `explore.php:79, 300-302` |
| Smart collections (`category` rule) | same | `CollectionService.php:244-245` |
| Interests cover picker | same | `InterestsController.php:202` |
| Pro layouts (Dribbble / Pinterest / Flickr) | pass through to the same media-index query | — |

Album categories are written in **post-ID space** (`AlbumService.php:99`,
`AlbumController.php:515`) and read back in exactly two places — `AlbumController.php:729` and
`templates/album.php:105`, both of which just print them on the album itself.

So: **assigning a category to an album has no effect on any listing, archive, filter, block, smart
collection or Pro layout.** `explore.php:333-337` counts albums only when no filter is active; there
is no `tax_query` anywhere in either plugin (zero hits repo-wide). The feature looks like it works
because the album page echoes the label back.

**The term count is wrong too.** `MediaCategory.php:43` reuses `MediaTag::update_term_count()`,
which joins `mvs_media_index` unconditionally (`MediaTag.php:63-95`). An album's category count is
non-zero only because the album's **own privacy stub row** happens to exist in the index with
`status='publish'` — it is counting the collision artefact, not any media.

### The `mvs_tag` contamination path I missed

Neither taxonomy sets `show_ui => false`, and nothing calls `remove_meta_box()` for `categorydiv` /
`tagsdiv-mvs_tag` (zero hits repo-wide). `mvs_album` registers with `show_in_rest => true`,
`rest_base => 'mvs-albums'` and no `rest_controller_class` override (`PostTypes/Album.php:36-45`).

Therefore **two live write paths exist today in post-ID space that no plugin code accounts for**:

1. WordPress's native Tags and Categories metaboxes on the Album edit screen.
2. Core's auto-exposed `/wp/v2/mvs-albums` endpoint, where both taxonomies are writable fields.

My §3 correction said `mvs_tag` was clean on the write side. **That is wrong** — it is clean in
*plugin* code, but core hands users two ways to write album-space `mvs_tag` rows right now.

### What this means for the fix

The reviewer should decide what album categories are *for* before deciding where they live:

- **(a) Albums do not have categories.** Remove the write paths, close the native UI
  (`show_ui => false` / `remove_meta_box()`, `show_in_rest => false` on the taxonomy for `mvs_album`),
  migrate existing album term rows away. Smallest surface, and it matches what the product actually
  does today, since nothing reads them.
- **(b) Albums have their own categories that work.** Register `mvs_album_category` +
  `mvs_album_tag`, give them a count callback joining `wp_posts`, and **add the browse surfaces that
  are currently missing** — an album archive by category, album results in the category archive.
  That is a feature, not a bug fix, and it should be scoped separately.
- **(c) One vocabulary, albums surface in media browsing.** Requires an `mvs_album_items` join in
  every category query so an album's category matches its contained media. Biggest change, and it
  alters what `/media?category=` returns for existing sites.

**Recommendation: (a) for this fix**, with (b) raised as a product question. It is the only option
that is purely corrective — it removes a path that already does nothing, closes two unguarded write
vectors, and eliminates the ID-space overlap without inventing behaviour. Doing (b) or (c) inside a
bug-fix PR would be shipping a feature under a bug's name.

**Reviewer question:** is anyone actually using album categories? If a customer has assigned them
expecting them to filter, (a) removes a label they can see. Worth checking the support history
before choosing.

## 3.5 Call-site map (completed 2026-08-08) — three corrections to this plan

The map is now complete across Free **and** Pro. Three things I had wrong or under-scoped:

### C1 — The write surface is **7 call sites, not 1**, and 6 of them are in Pro

`AlbumService::create()` is not the only place that does `wp_insert_post()` then
`$repo->set( $album_id, … )`. Every importer duplicates the pattern independently, without going
through `AlbumService`:

| Call site | Keys written |
|---|---|
| `wpmediaverse/includes/Services/AlbumService.php:80-87, 92-93` | `slug`, `privacy`, `album_type`, `group_id` |
| `wpmediaverse/includes/REST/Controller/AlbumController.php:505` | `privacy` |
| `wpmediaverse-pro/includes/Integrations/MediaPress/Importer.php:391` | `mpp_gallery_id` |
| `wpmediaverse-pro/includes/Integrations/MediaPress/MigrationAdmin.php:293` | `mpp_gallery_id` |
| `wpmediaverse-pro/includes/Integrations/RtMedia/Importer.php:759, 765, 768, 770` | `rtmedia_album_id`, `privacy`, `group_id` |
| `wpmediaverse-pro/includes/Integrations/RtMedia/MigrationAdmin.php:271` | `rtmedia_album_id` |
| `wpmediaverse-pro/includes/Integrations/BuddyBoss/Importer.php:503` + `MigrationAdmin.php:431` | `bb_album_id` |

**Consequence: Pro must ship paired with Free.** A migration importing thousands of albums from
rtMedia or BuddyBoss is the highest-volume producer of colliding rows there is — and it is exactly
the path a customer runs once, on a site that already has media. The `MediaRepository` guard (§4.2)
would start refusing these writes, so Pro has to move to the new accessor in the same release or
imports silently lose album privacy.

### C2 — Collections never write an index row, which makes their purge **pure harm**

`CollectionService`, `CollectionController` and `CollectionMetaBox` write only `wp_postmeta`
(`_mvs_collection_type`, `_mvs_collection_rules`). Nothing anywhere puts a collection post ID into
`mvs_media_index`. My §4.1 claim that collections mirror albums is **wrong on the write side.**

But that makes `PostTypes/Collection.php:75` worse, not better:

> A collection has no index row of its own. So when `purge_index_record( $collection_id )` finds a
> row, **that row is always a real media item.** Deleting a collection whose post ID happens to match
> a media ID destroys that media row — 100% of the time it fires on a match, with no upside ever.

It is defensive code guarding against a state that cannot occur, and its only possible effect is data
loss. For collections the fix is not a guard — it is **deleting the call**.

### C3 — `mvs_media_meta` is a second colliding table

Only `slug` and `privacy` are real `mvs_media_index` columns. `album_type`, `group_id`,
`mpp_gallery_id`, `rtmedia_album_id` and `bb_album_id` are **not** — `MediaRepository::set()` falls
them through to `mvs_media_meta`, whose PK is `(media_id, meta_key)`.

So an album also writes meta rows keyed by its post ID into the media meta store. A colliding album
and media item share a meta namespace: `album_type` lands on the media item, and any media meta key
the album happens to share is overwritten. The migration (§5) must cover `mvs_media_meta`, not just
`mvs_media_index`.

### Also worth the reviewer's attention

- **`AlbumController::get_items():301-325`** filters the album *listing* with
  `LEFT JOIN mvs_media_index mvidx ON mvidx.media_id = wp_posts.ID` plus
  `explore_privacy_clause('mvidx')`. A colliding album is therefore listed according to **the media
  item's** privacy. And since 5 of 7 albums here have no index row at all, the LEFT JOIN yields NULL
  privacy for them — album-list privacy is already inconsistent before any collision.
- **`ActivitySyncIntegration:205`** batch-reads album rows via `get_batch()` to decide BP activity
  visibility, so a collision also mis-hides activity.
- **`GroupTabIntegration:200`** reads `group_id` off the album row — but `_mvs_group_id` post meta is
  **already dual-written** (`AlbumService.php:94`). That confirms §4.1: `group_id` is a read-path
  change only, no data movement needed.
- **Only `PrivacyService::check_access()` disambiguates at all.** Every other reader
  (`add_items`, `prepare_album_response`, `ActivitySyncIntegration`, Pro's `PrivacyUIService`)
  reads `$repo->get( $album_id, … )` trusting the caller knows the ID is an album. There is no
  row-level marker to check.

## 3.6 REPRIORITISED — the write is the active harm, not the delete (2026-08-08)

An accidental live reproduction changed the priority order of this whole plan. Recording it because
it is the strongest evidence in the document and it applies to **every one of the 100+ sites running
this plugin.**

### What happened

A verification script created a throwaway album on the reference install:

```
album post id = 82
index row immediately after wp_insert_post: {"media_id":"82","media_type":"image",
    "title":"Creative Studio Portrait","slug":"creative-studio-portrait","privacy":"public"}

index row after set_many():                {"media_id":"82","media_type":"image",
    "title":"Creative Studio Portrait","slug":"probe-album","privacy":"private"}
```

**A real photo already occupied index row 82 before anything was written.** One ordinary
`AlbumService`-shaped write then overwrote its `slug` and `privacy`. The photo is now unreachable at
its own permalink and its privacy was silently changed by an unrelated album.

The collision landed on **the very next post ID**, on a nine-album install. The 1-in-2 rate reported
in §2 is if anything an understatement.

### Why this changes the plan

The plan staged the **guarded purge first**, on the reasoning that delete-time data loss was the
worst outcome. That is wrong for a live fleet:

| | Delete-time loss | **Write-time overwrite** |
|---|---|---|
| Trigger | someone permanently deletes an album | **someone creates an album** |
| Frequency | rare, deliberate | **routine, every album, every site** |
| Visible? | item vanishes — eventually noticed | **silent — the photo still renders, just at the wrong slug with the wrong privacy** |
| Happening now? | only on delete | **yes, continuously, on every mature site** |

**Creating an album is the dangerous operation, not deleting one.** On any site where album post IDs
have caught up with media IDs — which is every site with more media than its lowest album post ID —
each new album is a chance to silently re-slug and re-privacy a member's photo.

A privacy overwrite is the serious half. `set_many()` writes the album's privacy over the media
row's, so a **public** photo can become **private** (it disappears from the member's own grid) or a
**private** photo can become **public** (it is exposed). Neither produces an error.

### Revised priority

1. **P0 — stop the write.** Albums must stop writing into `mvs_media_index` at all. This is what is
   actively corrupting live data.
2. **P0 — stop the delete.** The guard in §4.3. Already the smaller of the two.
3. **P1 — detect.** `wp mvs diagnose_cpt_ids` so an owner can see their own damage before upgrading.
4. **P1 — migrate and repair** what is recoverable.

### The staging consequence

§7 had B (post-meta accessors) and C (repository guard) as separate stages. **They must ship
together.** A repository that refuses album writes, shipped before albums have somewhere else to
write, means album privacy silently stops persisting — trading a corruption bug for a
data-loss-on-save bug. One release, both halves, or neither.

### What cannot be detected on a live site

Worth stating plainly for the reviewer, because it bounds what any repair can promise:

- **An overwrite whose album was later deleted is invisible.** The media row keeps the album's slug
  and privacy with nothing left to compare against. `diagnose_cpt_ids` can only report *current*
  collisions.
- **The original slug is not recoverable** from the database in any case.
- **A privacy overwrite cannot be distinguished from a deliberate privacy change.** If a member's
  photo went private because an album landed on it, there is no record that it was ever public.

So the honest customer message is: this fix stops it happening again and reports what is still
detectable — it cannot undo what has already been silently changed.

## 4. Proposed fix

### 4.0 The invariant (owner, 2026-08-08) — this is the whole fix in one rule

> **`mvs_media_index` holds media. One row per media item. An album ID must never appear in
> `media_id`.**

| Column | Means | ID space |
|---|---|---|
| `media_id` | this media item's own ID | media sequence — **never an album** |
| `album_id` | which album it belongs to | `wp_posts`; many media → one album |
| `privacy` | **this media item's** privacy | — |

An album is a `wp_posts` row that media *point at* — a reference target, not a row in the media
table. And **album privacy controls how the album is visible**, which is a different question from
who may view any given photo inside it. Two values, two homes.

The bug in one line: **album privacy was written into `mvs_media_index.privacy` at
`media_id = <album post ID>`.** That does two wrong things at once — it puts an album ID into the
media ID space, where it collides with a real photo; and it stores an album's visibility in a column
that means media visibility.

**This shrinks the fix considerably.** If the album row was never legitimate, it is not data to
migrate — it is data that should not exist:

| Row class | Previously planned | Under the invariant |
|---|---|---|
| Albums with **no** index row (5 of 7 here) | — | **nothing to do.** They already work, which proves every read path tolerates the absence |
| Attribute-only rows | migrate all attributes to post meta | **delete as junk**, after lifting `privacy` onto the album post. `album_type`/`group_id` already have post-meta homes or are trivially re-derivable |
| Colliding rows | migrate, preserve, flag | **not album data at all** — they are photos wearing an album's privacy. Nothing to migrate; the album's real privacy was destroyed at write time and is unrecoverable |

The repository guard (§4.2) also follows directly from the invariant rather than needing its own
justification: reject any `$media_id` that resolves to a `wp_posts` row.

### 4.1 Albums and collections stop using `mvs_media_index`

Move album/collection attributes to post meta on their own CPT, where they always belonged.

| Attribute | Today | Proposed |
|---|---|---|
| `privacy` | `mvs_media_index.privacy` | post meta `_mvs_privacy` |
| `album_type` | `mvs_media_index` / meta | post meta `_mvs_album_type` |
| `slug` | `mvs_media_index.slug` | `wp_posts.post_name` (already exists and is already unique per type) |
| `group_id` | index + `_mvs_group_id` meta | `_mvs_group_id` meta (**already written today**, so this is a read-path change only) |

New `AlbumService::get_privacy( int $post_id ): string` / `set_privacy()` as the single accessor,
with a **read fallback**: post meta first, then the legacy index row, then `'public'`. The fallback
keeps sites working between upgrade and migration completion, and is removed one major later
(Production Rule 1).

**Collections need none of this** — they already store everything in `wp_postmeta` (see C2). Their
only change is deleting the `purge_index_record()` call.

### 4.2 `MediaRepository` refuses non-media IDs

The repository is the choke point that made this possible, so the guard belongs there.

```php
// MediaRepository::set() / set_many() / insert()
if ( get_post_type( $media_id ) ) {
    _doing_it_wrong( __METHOD__,
        'mvs_media_index is keyed on media IDs. Album and collection attributes belong in post meta.',
        '2.4.0' );
    return;   // refuse, do not corrupt
}
```

**Reviewer question:** `get_post_type()` on every write is one extra query on the upload hot path.
Alternatives: gate it behind `WP_DEBUG`, or check only when the caller passes a suspicious ID.
Recommendation: run it unconditionally in 2.4.0, measure, and downgrade to a debug-only assertion in
2.5.0 if it shows up in profiling. Correctness first, then optimise with evidence.

### 4.3 Delete stops purging

`PostTypes/Album.php:80` and `PostTypes/Collection.php:75` currently purge unconditionally. After
4.1 there is no album row to purge, so the call is removed. Until the migration has run everywhere,
it is replaced by a **guarded** purge that only deletes a row which is genuinely a leftover
privacy-only row:

```php
// Only purge a legacy privacy-only row. Never touch a row carrying real media.
if ( $repo->exists( $post_id ) && '' === (string) $repo->get( $post_id, 'media_type' ) ) {
    $repo->purge_index_record( $post_id );
}
```

That single condition stops the data loss on its own, and is the smallest shippable piece of this
plan (see "Staging" below).

### 4.4 `PrivacyService` drops the `media_type` workaround

`check_access()` (`includes/Services/PrivacyService.php:130-155`) currently guesses whether an ID is
media or a CPT by inspecting `media_type`. Once albums are not in the index, the question is
answered by `get_post_type()` directly, which is authoritative. The July workaround and its comment
come out.

`PrivacyService::effective_privacy()` (`:89-99`) reads `$repo->get( $album_id, 'privacy' )` — it
routes through the new accessor instead.

### 4.5 Taxonomies get separate vocabularies for CPTs

Register `mvs_album_category` (and `mvs_album_tag` if we take the recommendation above) for the
`mvs_album` / `mvs_collection` post types. Albums move to it; media keeps `mvs_category` /
`mvs_tag`.

`wp_term_relationships` has `PRIMARY KEY (object_id, term_taxonomy_id)`, and `term_taxonomy_id` is
allocated per taxonomy — so a different taxonomy makes album rows and media rows genuinely different
rows that no taxonomy-scoped query can cross. `MediaCategory::update_term_count()` needs a sibling
callback joining `wp_posts` instead of `mvs_media_index`, or album counts read zero.

---

## 4.6 Scale: an album holds a lot of media, and two paths do not cope

Raised by the owner, 2026-08-08: *"an album may have lots of media for sure."* Correct, and checking
it surfaced a third defect plus a constraint on this fix.

### 4.6.1 Changing an album's privacy does not reach its media (**downgraded — likely intended**)

> **Correction, 2026-08-08.** This section originally called the behaviour a privacy leak and put it
> in the same class as the document-library folder cascade. **That was wrong.** The owner has
> confirmed the semantics: *album privacy controls how the album is visible*, and media privacy
> controls how the media is visible — two independent questions.
>
> Under those semantics a private album containing public photos is **coherent**: the album page is
> gated, and the photos are individually public and legitimately appear in Explore. Folders in the
> document-library design own their contents' privacy; albums do not, so the comparison does not
> hold.
>
> The add-time clamp is not an inheritance rule either. `AlbumService::add_items():296-320` documents
> it as a targeted fix for media uploaded *through the album page* arriving with no privacy field
> and defaulting to public — a safety default at one entry point, not a semantic coupling.
>
> **So this is a product question, not a defect**, and it should not be filed as a bug. The
> observation below is kept because the asymmetry is still worth a deliberate decision.

**Reviewer question:** when an owner switches an album from public to private, should the items
inside be offered a bulk privacy change? Not automatic — the items are independently owned — but a
prompt ("also make the 300 photos in this album private?") would close the gap between what an owner
expects and what the semantics actually guarantee. Doing nothing is defensible; doing it silently is
not.

`AlbumController.php:505` changes album privacy with a bare write:

```php
$repo->set( $album_id, 'privacy', sanitize_text_field( $privacy ) );
```

`AlbumService::update()` contains **no mention of privacy at all** — verified, zero matches. So:

> A member creates a public album, adds 300 photos, then switches the album to private.
> **All 300 photos stay public.** The album page is gated; every photo inside is still reachable at
> its own URL, in the explore grid, and through `/media`.

Privacy is clamped **only at add time** (`AlbumService::add_items():321-338`, via
`more_restrictive()`), so the value is a snapshot. Tightening the container afterwards does nothing.

This is the same defect the document-library audit found for folders (T5 there), in shipped code,
for albums. **It is arguably a third Basecamp card** — it is a privacy leak, it is independent of
the ID collision, and bundling it here would hide it.

**Reviewer question:** file separately and fix here anyway (they touch the same methods), or fix
separately? Recommendation: file separately, fix in this PR, cross-reference both cards — the code
is the same three lines either way and splitting the work would mean touching `AlbumService` twice.

### 4.6.2 The clamp loop is an N+1

`add_items()` runs per media item:

```php
foreach ( $media_ids as $mid ) {
    $repo->set( $mid, 'album_id', $album_id );      // 1 write
    $media_privacy = $repo->get( $mid, 'privacy' ); // 1 read
    …
    $repo->set( $mid, 'privacy', $effective );      // 1 conditional write
}
```

Up to **three queries per item** in one request. Adding 500 photos to a private album is ~1,500
queries; the big-site checklist calls for 2,000+ rows to work on day one.

**Batch it** — the operation is set-shaped, not row-shaped:

```sql
UPDATE {p}mvs_media_index SET album_id = %d WHERE media_id IN (…);

-- one statement per privacy level that needs tightening, not one per item
UPDATE {p}mvs_media_index SET privacy = %s
 WHERE media_id IN (…) AND privacy IN (…levels looser than the album's…);
```

Two statements regardless of item count. `mvs_media_privacy_clamped_by_album` still fires per
affected item — the hook is a published contract (Production Rule 1) — but from the rows the batch
actually changed, read back once.

### 4.6.3 What this means for the fix in §4.1

Moving album privacy to post meta makes the **read** cheaper, not dearer: `get_post_meta()` is
served from WordPress's object cache after one primed read, whereas `$repo->get( $album_id,
'privacy' )` hits `mvs_media_index` per call. `add_items()` reads album privacy once per batch
either way, so §4.1 is neutral-to-positive here.

The genuine scale constraint is on the **migration** (§5), which must not walk media rows one at a
time — see the batching note there.

## 5. Migration — Migrator v26

Runs once, idempotent, ordered:

1. **Copy** every album/collection index row's `privacy` and `album_type` into post meta, unless
   meta already exists (never overwrite a newer value).
2. **Delete** only the rows that are *not* colliding — `file_path IS NULL AND media_type = ''`.
   These are pure privacy-only rows and safe to remove.
3. **Leave colliding rows in place** and record them in `mvs_error_log`. Their media data is real
   and must not be touched; their album data was already lost when the overwrite happened.
4. **Report** the collision list so a site owner can be told which items need a manual look.
5. **Taxonomies:** for each album/collection post with `mvs_category` terms, add the equivalent
   `mvs_album_category` terms. Delete the old relationship **only** when the object_id is not also a
   media row; where it is ambiguous, keep both and log.

### What the migration cannot repair

- **Overwritten slugs.** Image 77's original slug is gone; the row now holds the album's. The
  migration can detect the condition (index slug equals the CPT's `post_name`) and flag it, but it
  cannot recover the original value. **Reviewer question:** regenerate a slug from the media title,
  or leave it and just report? Regenerating changes a live permalink; leaving it means the media and
  the album share a slug in a column with a UNIQUE key.
- **Contaminated category assignments.** Where an object_id is both an album and a media item, there
  is no way to know which terms were meant for which. Both keep the terms; the site owner is told.

---

## 6. Why not a patch release

Production Rule 4: schema changes require a Migrator bump and a minor release minimum. This carries
a Migrator bump, a behaviour change on album privacy storage, and a new `_doing_it_wrong()` on a
public repository method. Content is minor-shaped; the branch is 2.4.0 (single active development branch) and the release number is decided at tag time.

An interim patch was considered and **rejected** — see §7. The guard it would have carried was
temporary by construction, and shipping code to 100+ sites with a known expiry date is worse than
the window it would have closed.

---

## 7. Staging — one release, no interim patch

**Owner decision, 2026-08-08: no dead code.** The earlier plan proposed a 2.4.0 patch carrying a
*guarded* purge in `Album::on_before_delete()`. That guard existed only to tolerate legacy rows and
would have been deleted again in 2.4.0 — ~15 lines shipped to 100+ sites with a known expiry date.
Dropped.

Everything ships together on the 2.4.0 development branch, Free and Pro paired:

| Piece | Detail |
|---|---|
| Migrator v26 | Lift `privacy` onto the album post, delete legacy attribute-only rows, preserve + report colliding rows (§5) |
| Albums stop writing the index | `AlbumService`, `AlbumController`, **6 Pro importer call-sites** (§3.5 C1) move to the post-meta accessor |
| `MediaRepository` refuses `wp_posts` IDs | The invariant enforced at the choke point (§4.0, §4.2) |
| Purge calls **deleted** | Both `Album` and `Collection` — with albums no longer creating rows, there is nothing to purge (§4.3) |
| `PrivacyService` workaround removed | The `media_type` discriminator from `3cfff321` is no longer needed (§4.4) |
| Taxonomy decision applied | Per §3.4 |
| `wp mvs diagnose_cpt_ids` | Read-only auditing; permanent, not throwaway |

**Ordering inside the release matters.** The migration must run before the repository guard can
refuse anything, and albums must have post meta to write to before the guard lands — otherwise album
privacy silently stops persisting (§3.6). One release, correct internal order, or neither half.

### What this decision costs, stated honestly

The three harms are not equally reversible:

| Harm | Reversible? |
|---|---|
| Album delete destroys a photo's index row | **No.** File survives on disk; title, author, privacy and slug are gone |
| Album create overwrites a photo's slug + privacy | Row survives, values are wrong |
| Media added to a colliding album gets the wrong privacy | Correctable once the cause is fixed |

Shipping nothing before 2.4.0 means the **irreversible** one stays live for the whole development
window. That is the accepted trade. Two things reduce it:

1. **`diagnose_cpt_ids` can be circulated ahead of the release** — it is read-only and touches
   nothing, so it can go to support as a snippet or an mu-plugin without a version bump. An owner
   who runs it knows which albums not to delete.
2. **Support guidance in the meantime:** do not permanently delete any album listed under
   "DATA LOSS RISK". Trashing is safe — `before_delete_post` only fires on permanent deletion.

## 8. Verification gates

- [ ] `composer ci` green (lint, WPCS, PHPStan, coding rules, settings contract, manifest)
- [ ] New unit test: creating an album writes **zero** rows to `mvs_media_index`
- [ ] New unit test: `MediaRepository::set()` refuses a `wp_posts` ID and does not write
- [ ] New unit test: album privacy round-trips through post meta; legacy index row still reads via fallback
- [ ] **Regression test for the July bug** — owner opens their own private album and is allowed; a
      non-member is denied. This is the case `3cfff321` fixed and it must not re-break
- [ ] **Collision regression test** — seed album post N and media row N with different owners; assert
      the album resolves to the album's owner, the media to the media's owner, and deleting the album
      leaves the media row intact
- [ ] Migration test: privacy-only rows removed, colliding rows preserved and logged, idempotent on
      a second run
- [ ] Taxonomy test: album categories land on the new taxonomy; media categories unchanged; counts
      correct on both
- [ ] Browser: album single view (owner, member, logged-out), album edit, album delete, media single
      view, explore grid, collection single view — at desktop and 390px
- [ ] Run the §2 diagnostic before and after on a seeded copy of a real customer database
- [ ] Journeys pass on Free-only and Free+Pro
- [ ] Pro: `composer arch-checks`; confirm no Pro code writes album IDs through `MediaRepository`

---

## 9. Risk register

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| A site's album privacy silently resets to public during migration | low | **high** | Copy-then-delete, never delete-then-copy; skip when meta already exists; migration test asserts values survive |
| **Pro importers write album IDs into the index (6 sites)** | **confirmed** | **high** | Pro ships paired; importers move to the accessor in the same release (§3.5 C1) |
| A Free-only upgrade leaves Pro importers writing colliding rows | medium | high | Version guard: the Free `MediaRepository` refusal logs rather than fatals, and Pro's minimum-Free-version check disables the importer if unpaired |
| The `get_post_type()` guard costs measurable time on bulk upload | medium | low | Measure on a 500-file bulk upload; downgrade to debug-only if it shows |
| Removing the `media_type` workaround re-opens Basecamp 10071824547 | low | high | That exact scenario is a required regression test |
| A theme or mu-plugin reads `mvs_media_index` for album privacy | low | medium | Read fallback stays for two majors; document in the changelog |
| Migration times out on a large site | low | medium | Batch by CPT post ID with a cursor, following `StorageRepairService`; never walk an album's media one row at a time (§4.6) |
| Batching the clamp loop changes when `mvs_media_privacy_clamped_by_album` fires | medium | medium | Fire per affected row, read back from the batch result; assert the hook count in a test |
| Colliding rows leave a duplicate slug under a UNIQUE key | **confirmed** | medium | Open question in §5 — needs a decision |

---

## 10. Open items blocking implementation

1. ~~Complete call-site map~~ **DONE** — see §3.5. Outcome: 7 write sites (6 in Pro),
   `mvs_media_meta` also affected, collections clean on the write side. **Pro must ship paired.**
2. **Slug repair policy** (§5) — regenerate or report?
3. ~~`mvs_tag` in scope~~ **YES** — §3.4 found two live core write paths (native metabox,
   `/wp/v2/mvs-albums`). Not optional.
3b. **What are album categories for** (§3.4) — remove (a, recommended), make them work (b), or
   unify (c)? Check support history for customers relying on them.
4. ~~Stage A as a 2.4.0 patch~~ **RESOLVED — no.** Owner: no dead code. Single 2.4.0 release (§7).
5. **Guard cost** (§4.2) — unconditional or debug-only?
6. ~~Album privacy cascade~~ **WITHDRAWN as a bug** (§4.6.1) — album privacy governs album
   visibility only, so non-cascading is coherent. Remains open as a **UX** question: offer a bulk
   privacy prompt when an album is made private?
7. **Orphan `album_id` references** — 3 media rows point at `album_id = 28`, which has no `wp_posts`
   row. Separate defect; worth its own look.

---

## What this plan does NOT do

- Does not change how **media** privacy, slugs or categories work
- Does not alter the `mvs_album` / `mvs_collection` CPT registrations beyond adding a taxonomy
- Does not touch the document-library work (`plan/2026-08-08-document-library-v2-implementation-plan.md`),
  though that plan depends on this one being fixed — it is the same defect class, and the document
  library is explicitly forbidden from writing explicit `media_id` values for this reason
- Does not attempt to recover data already lost to an overwrite
