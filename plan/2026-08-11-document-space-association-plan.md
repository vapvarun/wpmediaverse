# Plan: Document ↔ Space association (BN Phase 11 prep)

**Date:** 2026-08-11  
**Type:** Architecture / API contract (Free + Pro + BuddyNext bridge)  
**Status:** **PLAN ONLY — not implemented.** Personal document drives (v1) are shipped; Space drives are Phase 11.  
**Consumer:** BuddyNext builds tabs/views on the REST API. MediaVerse must not invent Space UI.  
**Related:** `plan/document-library.md` §4 (drive scoping), §7 (BN seam), §11 (Space drives), §15 T1, §18  

Basecamp cards (Scope):

- [GAP: Document Space association — author vs drive vs contribute (Phase 11 / BN)](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10189637847) — G1–G6 implementation gaps
- [CONTRACT: Document Space binding must mirror BN media albums (never group_id)](https://app.basecamp.com/5798509/buckets/46336461/card_tables/cards/10189650164) — day-1 anti-pattern lock from media audit

---

## Problem

BN will render Documents tabs itself via REST. Personal drives are API-ready. **Space libraries are not.**

The confusion to kill before anyone builds Space UI:

1. **Spaces do not upload.** Real people upload — same as media into a Space album.
2. **Uploader ≠ library.** `post_author` is always the person. The drive (personal vs Space) is a separate association.
3. Today that association is complete for **personal** drives and **incomplete for Space**, especially at drive root (`folder_id = 0`).

Shipping Space tabs without closing the gaps below will mis-file root documents into the uploader’s personal drive and conflate “own the Space” with “may contribute a file.”

---

## Model (canonical — do not re-litigate)

| Layer | Storage | Meaning |
|---|---|---|
| **File (document)** | `mvs_media_index` | `media_type = document`. `post_author` = **who uploaded** (always a WP user). `folder_id` = container (0 = drive root). |
| **Folder (tree)** | `mvs_pro_folders` | `drive_type` + `drive_id` = **whose library**. `created_by` = who created the folder row. |
| **Grant** | `mvs_access_grants` | Who else may view/edit beyond privacy. |

### Personal (v1 — shipped)

| Object | Association |
|---|---|
| Folder | `drive_type = user`, `drive_id = <user_id>` |
| File in folder | `folder_id = <folder>` (folder already scopes the drive) |
| File at root | `folder_id = 0` **and** `post_author = <user_id>` |

Listing root uses author. Inside a folder, author is not required — the folder owns the drive scope.

### Space (Phase 11 — **not shipped**)

Mirror BN media / Space albums:

| Object | Association |
|---|---|
| Folder | `drive_type = space`, `drive_id = <bn_space_id>` |
| File in folder | `folder_id = <folder>` |
| File at root | `folder_id = 0` **plus an explicit Space/drive key on the document** (meta or columns — design §4: “drive meta on the document, mirroring media’s group assignment”) |
| Uploader | Still `post_author` = the member who uploaded — **never** the Space id |

**Never** set `post_author` to a Space id. That breaks GDPR purge, quotas, “my uploads,” and every author-scoped query.

---

## What media already does (pattern to copy)

BuddyNext Space albums:

- Person uploads → `post_author` = person  
- Album carries Space via `Galleries::SPACE_META` = **`_bn_space_id`** (BN Space id)  
- Create path calls MediaVerse `AlbumService::create()` **without** `group_id`, then writes `_bn_space_id`  
- Contribute check = Space membership / role (`can_contribute_to_space`), not only owner  
- Privacy clamp for private/secret Spaces after upload  

MediaVerse still has a **separate** BP path: `group_id` → album `_mvs_group_id` / media meta `group_id`, gated by `groups_is_user_member()`. That is BuddyPress groups only.

| Do | Do not |
|---|---|
| Stamp Space on a drive key / Space meta | Write Space id into `group_id` / `_mvs_group_id` |
| Keep `post_author` = uploader | Set `post_author` to a Space id |
| Privacy token `space` + BN filters | Treat BP `group` privacy as Space privacy |

Documents must follow the same split: **author vs container vs contribute.** See contract card above.

---

## Gaps (ordered)

### G1 — Space root documents have no Space binding **(P0 for Phase 11)**

Personal root listing:

```sql
folder_id = 0 AND post_author = %d
```

A document uploaded with `folder_id = 0` into a Space drive today stores **only** `post_author`. There is no `drive_type` / `drive_id` / Space meta on the row. That file would appear in the **uploader’s personal drive**, not the Space library.

**Required:** document-level drive association for Space/site (and optionally unify personal too). Options (pick one in implementation, document in schema notes):

- A) Meta on the document: `_mvs_drive_type` + `_mvs_drive_id` (mirrors album Space meta)  
- B) Columns on `mvs_media_index`: `drive_type`, `drive_id` (cleaner listings, Migrator bump)

Root listing for a Space then becomes drive-scoped, not author-scoped. Author remains for “files I uploaded” and GDPR.

### G2 — Upload / move gate is `owns_drive`, not contribute **(P0)**

`DocumentIngestService` and move paths require `PermissionService::owns_drive()`.

| Drive | Today |
|---|---|
| `user` | `drive_id === user_id` |
| `space` / `site` | `mvs_document_owns_drive` filter, default **false** |

BN media lets **members contribute**; only some roles “own.” If Phase 11 only teaches `owns_drive` for every member, own/admin/share authority collapses into contribute.

**Required:** separate write authority, e.g. `mvs_document_can_write_drive` (or return a level from `mvs_document_drive_access`), used by ingest/move/create-folder. Keep `owns_drive` / `can_grant` for admin and sharing.

### G3 — List API is personal-only **(P0)**

`DocumentController::get_items()` always passes `author => current_user`. No `drive=space:123`. No `GET /drives` (design §9).

**Required:**

- Collection params: `drive_type`, `drive_id` (or `drive=space:12`)  
- Root listing uses drive key when Space/site  
- `GET /drives` — drives visible to me (personal + Spaces BN reports via filter)

### G4 — BN filter contract incomplete / renamed **(P1)**

| Design §7 | Code today |
|---|---|
| `mvs_document_drive_owners` | — |
| `mvs_document_drive_access` (permission, not bool) | — |
| `mvs_document_drive_label` | — |
| — | `mvs_document_owns_drive` (bool) |
| — | `mvs_document_can_grant` (bool) |

BuddyNext `WPMediaVerseBridge` has **no document-drive hooks**.

**Required:** freeze one contract (prefer design §7 + write level), implement in Pro, wire BN bridge, update design/code drift. Secret Spaces → **404 not 403** for non-members (design §7 / §18).

### G5 — Departing member (T1) **(P0 when Space ships, not v1)**

Design §15: purge personal docs; **reassign** Space/site docs. Blocks Phase 11 in the same release Space drives ship — not a v1 blocker.

### G6 — Privacy token `space` **(P1)**

Design: storage token `space` (not BP `group`). Confirm `PrivacyService` resolves BN Spaces (not only `groups_is_user_member`). Document REST already allows `group` as alias in design — verify implementation.

---

---

## Interaction with the 2.4.0 role gate (added 2026-08-11, after this plan was written)

The Documents settings work landed a **feature-access** gate the same day. It does not close any gap above, and it must not be mistaken for one — but it does change what ingest and the REST routes look like, so Phase B and Phase D need to know it is there.

**What it is:** `use_mvs_documents`, a capability granted to every role by default, resolved through `Plugin::user_can_use_documents( $user_id )` and overridable per user by the `mvs_user_can_use_documents` filter (the membership-tier seam BuddyNext will use for paid tiers).

**Where it landed:** `AbstractDocumentController::require_login()` (covers eight document/folder routes at once), `DocumentIngestService::handle()`, `FolderController`, `PermissionController`, `DriveRenderer::render()` and both frontend write paths.

### Three distinctions to keep straight

| Question | Answered by | Layer |
|---|---|---|
| May this member have a document library at all? | `use_mvs_documents` + `mvs_user_can_use_documents` | **Feature access** (new, 2.4.0) |
| May they write to *this* drive? | `owns_drive` today; **G2** wants a contribute level | **Drive authority** (this plan) |
| May they read *this* document? | `PermissionService::can_view()` | **Privacy** (unchanged) |

They compose in that order, and the ordering is deliberate: ingest now asks feature access **before** `owns_drive`, so when G2 replaces the second check the first is untouched. This is the layering the plan asks for — feature access is genuinely separate from contribute authority — so **G2 is unaffected and still P0**. Nothing about the 2.4.0 gate lets a Space member contribute, and it must not be cited as progress against the acceptance criterion "`owns_drive` is not used as the sole upload gate for Space."

### Four concrete notes for the phases

1. **G4 / Phase A (contract freeze):** `mvs_user_can_use_documents` already exists and is NOT one of the drive filters. Freeze it in the published contract as a separate, per-user feature-access hook so it does not get folded into `mvs_document_drive_access`. Answering the drive filters must never be a way to re-grant a member the feature their role has switched off.

2. **G3 / Phase C:** the `drive` / `drive_id` params will flow through `get_items_permissions_check()`, which now runs the feature gate first. That is correct — a member without the capability should not enumerate Space drives either — but it means a Phase C test for "non-member sees 404" has to be run by a member who HAS the capability, or it will pass for the wrong reason.

3. **Phase D / secret Spaces:** the new refusal is **403 `mvs_documents_unavailable`**, and the plan requires **404** for a non-member of a secret Space. These are different refusals at different layers and both are right: 403 says "this feature is not for your account", 404 says "no such drive". Do not collapse the 403 into a 404 to satisfy §18 — the feature gate fires before any Space is resolved, so it cannot leak a Space's existence.

4. **G5 / T1:** unchanged. Revoking `use_mvs_documents` hides surfaces and deletes nothing (verified: 11 documents intact across a revoke/restore cycle), so it is not a purge path and does not interact with departing-member reassignment.

---

## Non-goals

- MediaVerse Space Documents UI (BN owns tabs/views)  
- Changing personal-drive v1 behaviour for existing BN/app clients  
- Setting `post_author` to Space id  
- Admin folder list (withdrawn — see `document-library-remaining.md` Task 9)

---

## Implementation phases

### Phase A — Contract freeze (doc + tests, no Space UI)

1. Publish consumer note: author vs drive vs contribute.  
2. Lock filter names + REST params (`drive`, `/drives`, document response fields `drive` / `folder` / `author`).  
3. Align `document-library.md` §7 with actual hooks.

### Phase B — Schema + ingest (Pro + Free Migrator if columns)

1. Implement G1 document↔drive association.  
2. Ingest: when uploading into a Space folder **or** Space root, stamp drive key; keep `post_author` = uploader.  
3. G2: `can_write_drive` separate from `owns_drive`.  
4. Unit tests: personal unchanged; Space root does not appear in personal listing; member contribute can upload; non-member 404.

### Phase C — REST

1. `GET /drives`  
2. `GET /documents?drive=space:{id}` (+ folder children unchanged via `folder`)  
3. Folder create accepts `drive_type` / `drive_id` with write check  
4. App config: no change required beyond documenting Space when BN active (optional flag)

### Phase D — BuddyNext bridge

1. Answer drive list, access/write/own/grant, labels from Space membership + roles.  
2. Secret Space → 404.  
3. Decide §18 product questions (auto drive per Space? child inheritance? who may upload?) and encode in bridge only.

### Phase E — T1 reassignment + privacy clamp

1. User delete: personal docs purge; Space docs reassign or hold per product rule.  
2. Mirror media `clamp_media_to_space_privacy` for documents when Space type requires it.

---

## Acceptance criteria

- [ ] Document response always exposes: `author` (uploader), `folder`, and `drive` `{ type, id }` (or equivalent).  
- [ ] Upload into Space folder: file’s drive = that Space; `post_author` = uploader.  
- [ ] Upload into Space root: file listed under Space library, **not** under uploader’s personal root.  
- [ ] Space member with contribute role can upload; non-member cannot; owner/moderator can manage sharing per BN rules.  
- [ ] `owns_drive` is not used as the sole upload gate for Space.  
- [ ] `GET /drives` returns personal + Spaces BN exposes for the viewer.  
- [ ] Secret Space: unauthorized caller gets **404**, not 403.  
- [ ] Personal-drive API behaviour for existing clients unchanged (regression suite).  
- [ ] BN bridge implements the frozen filters; MediaVerse never queries `bn_spaces` tables.  
- [ ] T1 behaviour documented and tested before Space drives are marked shippable.

---

## Test plan

| # | Case | Expect |
|---|---|---|
| 1 | Member A personal upload root | Listed in A’s drive only |
| 2 | Member A upload into Space S folder | `author=A`, drive=S; listed in S; not in A’s personal root |
| 3 | Member A upload Space S root | Same as (2) for root |
| 4 | Member B (no membership) lists S | 404 |
| 5 | Member C (member, contribute) uploads to S | 201 |
| 6 | Member C (view-only) uploads to S | 403 |
| 7 | Departing A (Space docs) | Reassigned/held — not silently deleted with personal purge only |
| 8 | App Password, no cookie | Same matrix |

---

## Suggested PR split

1. **PR1** — Contract doc + filter rename/align (no behaviour change for personal)  
2. **PR2** — G1 schema + ingest stamp + listing  
3. **PR3** — G2 write gate + G3 REST (`/drives`, drive query)  
4. **PR4** — BN bridge + §18 decisions  
5. **PR5** — T1 + privacy clamp  

Ship Free/Pro/BN paired when Space association lands.

---

## References

- `plan/document-library.md` §4 drive scoping, §7 BN seam, §9 REST (`GET /drives`), §11, §15 T1, §18  
- `plan/RESUME-document-library.md` — personal v1 complete; Space not v1  
- Pro: `DocumentIngestService`, `PermissionService::owns_drive`, `FolderService`, `DocumentController`  
- BN: `Bridges\WPMediaVerseBridge`, `Media\MediaController` Space album + `can_contribute_to_space`  
- Free: `MediaRepository::drive_documents()` author-at-root behaviour  
