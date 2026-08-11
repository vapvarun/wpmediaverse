# Documents: the REST contract BuddyNext builds against

**Date:** 2026-08-11
**Type:** Contract (Free + Pro + BN bridge)
**Status:** Layer 1 shipped in 2.4.0. Layers 2–4 partly shipped; Space scoping not started.
**Supersedes nothing.** Consolidates: `2026-08-11-document-space-association-plan.md` (G1–G6), Basecamp Scope card 10189637847, Basecamp CONTRACT card 10189650164.

> **Why this document exists.** BuddyNext consumes MediaVerse through `mvs/v1` and `mvs-pro/v1` and nothing else — no shared PHP, no template overrides, no direct table reads. That means every rule below has to be *discoverable* through the API, not merely *enforced* behind it. A gate the server applies but the config does not advertise produces a client that draws a tab and then meets a refusal, which is the defect class this release already had to fix twice (`anonymous_links`, `default_privacy`).
>
> The two Basecamp cards are correct and I have not re-litigated them. What they do not yet have is a single authorization model with the refusal codes pinned down, which is what a client actually needs in order to behave well. That is the contribution here.

---

## 1. Four layers, asked in this order

The recurring confusion is treating these as one question. They are not, they fail differently, and a client must respond to each differently.

| # | Layer | Question | Decided by | Status |
|---|-------|----------|-----------|--------|
| 1 | **Feature access** | May this account have a document library at all? | `use_mvs_documents` cap → `Plugin::user_can_use_documents()` → `mvs_user_can_use_documents` | **Shipped 2.4.0** |
| 2 | **Drive authority** | May they read / write / administer *this* drive? | `owns_drive()` today; needs a write level (G2) | Personal only |
| 3 | **Privacy** | May they read *this* document? | `PermissionService::can_view()` + grants | Shipped |
| 4 | **Ownership** | Whose upload is this, for quota / GDPR / "my files"? | `post_author`, always a real person | Shipped |

**They compose, and the order is load-bearing.** Layer 1 runs before any drive is resolved, which is what lets it refuse without leaking whether a Space exists. Layer 2 must never be used to answer layer 1 (a BN bridge answering the drive filters must not be able to re-grant a member the feature their role has switched off), and layer 1 must never gate a **read** — do that and an already-public document becomes visible to a logged-out visitor and invisible to a signed-in member.

**Layer 4 is never a scoping mechanism.** `post_author` is the uploader. It is *currently* also how personal root documents are scoped (`folder_id = 0 AND post_author = N`), and that coincidence is precisely G1: it works for personal drives and silently mis-files Space root documents into the uploader's own drive. Root scoping must move to an explicit drive key; author stays for quota, GDPR and "files I uploaded".

---

## 2. Drive identity

One drive key, two parts, on the document itself:

```
drive = { type: "user" | "space" | "site", id: <int> }
```

**Frozen anti-patterns** (from CONTRACT card 10189650164, and I agree with all five):

1. Never write a Space id into `group_id` / `_mvs_group_id`. Those are BuddyPress, they die with BuddyPress, and an importer would read them as BP groups.
2. Never set `post_author` to a Space id. It breaks GDPR purge, quotas, and every author-scoped query.
3. Space root documents (`folder_id = 0`) need an explicit drive key — the same job `_bn_space_id` does on an album.
4. Privacy token `space` is not `group`. `groups_is_user_member()` must not decide Space access.
5. MediaVerse never queries `bn_*` tables. The bridge answers filters.

**Storage decision (mine, for implementation):** prefer **columns on `mvs_media_index`** (`drive_type`, `drive_id`) over post meta, with a Migrator bump. Reason: every listing this feature needs is drive-scoped, and drive-scoped listing via post meta is a join that gets slower exactly as a Space library grows — the big-site-readiness rule applies here on day one, not as a follow-up. Albums use meta because albums are `wp_posts` and their volume is small; documents live in `mvs_media_index` and their volume is not. Backfill personal drives (`user` / `post_author`) in the same migration so root listing has ONE code path rather than a personal branch and a Space branch that will drift.

---

## 3. Refusal codes

A client cannot behave well if every failure is a 403. This is the ladder, and it is the part I would most want frozen before BN starts building:

| Situation | Status | Code | What the client should do |
|---|---|---|---|
| Not signed in | 401 | `mvs_unauthorized` | Send them to sign in |
| Signed in, documents not available to this account | 403 | `mvs_documents_unavailable` | **Hide the Documents tab entirely.** Do not retry, do not offer sign-in — they are already signed in |
| Site-wide toggle off | route absent | `rest_no_route` | Hide; the feature does not exist here |
| Drive not visible to this viewer (incl. secret Space) | **404** | `mvs_drive_not_found` | Treat as "no such drive". Never reveal it exists |
| Drive visible, member may read but not write | 403 | `mvs_drive_read_only` | Show the library, hide upload / new-folder |
| Document not readable | **404** | `mvs_document_not_found` | Treat as missing — a filename can carry a client's name |
| Document readable, not editable | 403 | `mvs_document_forbidden` | Show it, hide the edit affordances |
| Type refused | 400 | `mvs_document_type_not_allowed` | Name the type in the error |
| Too large | 400 | `mvs_document_too_large` | Compare against `documents.max_size` from config first |
| Link sharing off | 403 | `mvs_link_sharing_disabled` | Hide the "anyone with the link" option — config says so too |

**403 vs 404 is not cosmetic.** 403 means "exists, not for you"; 404 means "you may not know whether it exists". Feature access is a 403 because the account's own permission is not a secret from the account. A secret Space is a 404 because its existence is the secret. These two must not be collapsed into each other — and the layer ordering guarantees the feature-access 403 fires before any Space is resolved, so it cannot leak one.

---

## 4. Filter contract

Two families, deliberately separate. Conflating them is how a bridge accidentally becomes an authorization bypass.

**Feature access (Free, shipped 2.4.0)**

| Filter | Signature | Purpose |
|---|---|---|
| `mvs_documents_enabled` | `bool` | Site-wide. Pro answers with the master option |
| `mvs_user_can_use_documents` | `bool $can, int $user_id` | **Per user.** The membership-tier seam. Runs last; widens or narrows |

**Drive authority (Pro + BN bridge, to freeze)**

| Filter | Signature | Purpose |
|---|---|---|
| `mvs_document_drives_for_user` | `array $drives, int $user_id` | BN lists the Spaces this viewer may see. Feeds `GET /drives` |
| `mvs_document_drive_access` | `string $level, string $type, int $id, int $user_id` | `none` \| `read` \| `write` \| `own`. **Returns a level, not a bool** — G2 exists because a bool cannot say "may contribute" |
| `mvs_document_drive_label` | `string $label, string $type, int $id` | Display name; MV must not read `bn_*` to get it |

Existing `mvs_document_owns_drive` and `mvs_document_can_grant` become derived (`own`) rather than primary, kept for ≥2 majors per Production Rule 2.

**Rule for the bridge:** answering `mvs_document_drive_access` with `write` must never grant a user whose `mvs_user_can_use_documents` is false. Layer 1 is checked first and is not appealable from layer 2.

---

## 5. REST shape

**`GET /mvs-pro/v1/drives`** — the drives this viewer can see. The one call BN needs to render its tab set.

```json
[ { "type": "user",  "id": 22, "label": "My drive", "access": "own",   "counts": { "documents": 11 } },
  { "type": "space", "id": 7,  "label": "Design",   "access": "write", "counts": { "documents": 40 } } ]
```

`access` is the level from §4, so the client hides the upload button without a second round trip.

**`GET /mvs-pro/v1/documents?drive=space:7&folder=0`** — drive-scoped, not author-scoped. Omitting `drive` keeps today's personal behaviour, which is what protects existing app clients.

**Document response** always carries all three of the identities in §1, because a client that has to infer any of them will infer wrong:

```json
{ "id": 812, "author": { "id": 22 }, "drive": { "type": "space", "id": 7 }, "folder": { "id": 0 } }
```

**`GET /mvs/v1/app/config` → `documents`** — the discovery surface. As of 2.4.0 it reports `enabled` **per user**, plus `max_size`, `allowed_types`, `allowed_mimes`, `default_privacy`, `anonymous_links`, `preview_tiers`, `max_folder_depth`, `search`. The standing rule: **every value here is asked of the resolver that enforces it.** Three of them were hardcoded or derived from the wrong source and all three were fixed in this release; that is not a coincidence to be repeated.

---

## 6. What is already true (2.4.0)

Worth stating so Phase 11 does not rebuild it:

- Layer 1 exists end to end, enforced at `AbstractDocumentController::require_login()` (eight routes), `DocumentIngestService::handle()` (covers the CLI seeder too), folders, sharing, the drive renderer and both frontend write paths.
- `use_mvs_documents` is granted to every role including plugin-registered ones, and an owner's revocation survives a version bump.
- `/app/config`'s `documents.enabled` is per user.
- Reads are deliberately ungated by layer 1.
- Document privacy has one vocabulary (`private|members|public`) and the REST validator uses it rather than media's.

Not started: drive columns, `can_write_drive`, `GET /drives`, `drive` query param, BN bridge hooks, T1 reassignment.

---

## 7. Sequencing

Unchanged from the Scope card's PR split, with one amendment: **do §3 (refusal codes) and §4 (filter names) in PR1, before any schema work.** They are the only parts BN cannot work around later, because a client's error handling and tab logic get written against them on day one and every change after that is a coordinated release.

| PR | Content | Blocks BN? |
|---|---|---|
| 1 | Refusal codes + filter names frozen; consumer note published | **yes — do first** |
| 2 | `drive_type`/`drive_id` columns + Migrator + ingest stamp + root listing | yes |
| 3 | `can_write_drive`, `GET /drives`, `drive` query param | yes |
| 4 | BN bridge answers the filters | — |
| 5 | T1 reassignment + Space privacy clamp | before Space is shippable |
