# The document REST contract — for BuddyNext

**Status: FROZEN as of 2026-08-12 (PR1).** Verified against BuddyNext **1.1.5** (`71511f48`) and
WPMediaVerse Free + Pro **2.4.0**.

BuddyNext consumes `mvs/v1` and `mvs-pro/v1` and nothing else — no shared PHP, no template
overrides, no direct table reads. So everything BN needs has to be **discoverable through the API**,
not merely enforced behind it. This document is the part BN can rely on not moving.

The freeze lives in code as well as here: `WPMediaVersePro\Documents\DriveContract`, enforced by
`bin/coding-rules-check.sh` **Rule 8** — a refusal code emitted by any document surface and not
declared in the contract fails the build. A frozen list that nothing asserts drifts the first time
somebody adds a route in a hurry.

---

## 1. Four layers, asked in this order

| # | Layer | Question | Decided by | State |
|---|---|---|---|---|
| 1 | Feature access | May this account have a library at all? | `use_mvs_documents` → `mvs_user_can_use_documents` | **shipped** |
| 2 | Drive authority | May they read / write / administer THIS drive? | `mvs_document_drive_access` | **PR3** |
| 3 | Privacy | May they read THIS document? | `PermissionService::can_view()` + grants | **shipped** |
| 4 | Ownership | Whose upload is this, for quota and GDPR? | `post_author`, always a real person | **shipped** |

**The order is load-bearing.** Layer 1 runs before any drive is resolved, which is what lets it
refuse without leaking whether a Space exists. Layer 2 must never answer layer 1 — a bridge
answering the drive filters must not be able to re-grant a member the feature their role switched
off.

**Layer 4 is never a scoping mechanism.** `post_author` is the uploader, always a WP user, never a
Space id.

---

## 2. Refusal codes — frozen

A client cannot behave well if every failure is a 403.

| Situation | Status | Code | What the client should do |
|---|---|---|---|
| Not signed in | 401 | `mvs_unauthorized` | send to sign-in |
| Documents not available to this account | 403 | `mvs_documents_unavailable` | **hide the tab.** Do not retry, do not offer sign-in |
| Site toggle off | route absent | `rest_no_route` | hide |
| Drive not visible (incl. secret Space) | **404** | `mvs_drive_not_found` | treat as no such drive |
| Drive visible, contents not readable | 403 | `mvs_drive_forbidden` | offer the way in (join / request), not the library |
| Drive visible and readable, not writable | 403 | `mvs_drive_read_only` | show the library, hide upload |
| Document not readable | **404** | `mvs_document_not_found` | treat as missing |
| Document readable, not editable | 403 | `mvs_document_forbidden` | show it, hide edit |
| Type refused | 400 | `mvs_document_type_not_allowed` | name the type (`data.doc_type`) |
| Too large | 400 | `mvs_document_too_large` | check `documents.max_size` first, refuse locally |
| Link sharing off | 403 | `mvs_link_sharing_disabled` | hide the option |
| Scanner rejected the file | 400 | `mvs_document_scan_failed` | not a type problem — do not suggest another format |

**403 vs 404 is not cosmetic.** 403 means "exists, not for you"; 404 means "you may not know whether
it exists". Feature access is 403 because the account's own permission is not a secret from the
account. A secret Space is 404 because its existence *is* the secret.

Anything not in this table: treat as "refused, show the message" rather than branching on it. The
full declared set is `DriveContract::DECLARED`.

### Two of these are not emitted yet — deliberately

`mvs_drive_not_found` and `mvs_drive_read_only` arrive with PR3. They are frozen **ahead** of the
code that raises them because BN cannot write the "hide upload, keep the library" branch after the
fact without a second coordinated release. Build against them now; they will start appearing.

### Known gaps this freeze records rather than hides

Two things in the shipped code do not match this table yet. Both are PR3's to fix, and both are
written down so BN is not surprised:

1. **`mvs_forbidden` (403) is emitted for "That is not your drive"** in `FolderController` on folder
   create and folder write. Under this contract that situation is `mvs_drive_read_only` (403) when
   the drive is visible, or `mvs_drive_not_found` (404) when it is not. **As it stands it would leak
   a secret Space's existence** the moment Spaces ship, because a generic 403 confirms the drive is
   there. PR3 must split it.
2. **`mvs_grant_user_unknown` carries 400 in `PermissionService` and 404 in `PermissionController`**
   — the same code with two statuses depending on which path produced it. A client branching on the
   code sees inconsistent statuses. Not frozen in the table above for that reason; pick one in PR3.

---

## 3. Drive-authority filters — frozen names

Answered by the BuddyNext bridge (PR4). Kept **separate** from the feature-access filters
(`mvs_documents_enabled`, `mvs_user_can_use_documents`) — see the layering rule above.

| Filter | Returns | Job |
|---|---|---|
| `mvs_document_drives_for_user` | array of drive descriptors | which drives this viewer can see |
| `mvs_document_drive_access` | `none` \| `read` \| `write` \| `own` | authority over ONE drive |
| `mvs_document_drive_label` | string | human label for breadcrumbs and pickers |

**`mvs_document_drive_access` returns a level, not a bool.** A bool cannot express "may contribute
but does not own", which is exactly the gap that makes Space uploads impossible today.

`mvs_document_owns_drive` and `mvs_document_can_grant` **become derived** from the access level and
are kept for at least two majors (Production Rule 2). Two independent sources for one question is
how a bridge ends up granting write through one filter and denying it through the other.

---

## 4. Which BuddyNext function answers what

Verified against BN 1.1.5. Naming these here is the point of PR1 — it stops PR4 re-deriving
visibility and disagreeing with BN's own router.

| Contract question | BN function |
|---|---|
| Does this drive exist for this viewer? (the 404 decision) | `Spaces\SpaceVisibility::can_view_space()` |
| May they see what is inside? (the read decision) | `Spaces\SpaceVisibility::can_view_content()` |
| What is their role in this space? | `Spaces\SpaceMemberService::get_role()` |
| Roles across many spaces, without N queries | `Spaces\SpaceMemberService::prime_viewer_roles()` |
| May they administer sharing? | `Core\PermissionService::can_manage_space()` |

**Answer from `SpaceVisibility`, never from `bn_spaces.type` directly.** BN's own router comments say
the decision "comes from the canonical resolver, so this route and the REST contract agree", and
hidden-ness is registry-driven (`SpaceTypeRegistry::is_hidden_from_non_members()`) rather than
hardcoded to `secret` — so a site with a custom space type gets the right answer for free.

### The mapping

The membership vocabulary, as actually stored:

```
bn_spaces.type          ENUM('open','private','secret')
bn_space_members.role   ENUM('owner','moderator','member')     -- note: no 'admin'
bn_space_members.status ENUM('active','pending','invited','banned')
```

| BN state | `mvs_document_drive_access` | Refusal, if any |
|---|---|---|
| role `owner` | `own` | — |
| role `moderator` | `write` — `own` only where `can_manage_space()` is the sharing authority | — |
| role `member`, status active | **`write` or `read` — BN's call per space** | — |
| status `pending` / `invited` | `none` | as non-member below |
| status `banned` | `none` | as non-member below |
| non-member, `can_view_content()` true (open space) | `read` | `mvs_drive_read_only` on write |
| non-member, `can_view_content()` false but space visible (private space) | `none` | **`mvs_drive_forbidden` 403** |
| non-member, `can_view_space()` false (secret space) | `none` | **`mvs_drive_not_found` 404** |

`SpaceMemberService::get_role()` already filters `status = 'active'` and returns null otherwise, so
pending / invited / banned collapse to "no role" without the bridge testing status separately.

### Verified against seeded BN data, 2026-08-12

`wp buddynext demo seed` on BN 1.1.5 produced 14 spaces — 11 open, 3 private, 1 secret — and BN's
own resolver was run over every one of them:

```
                     can_view_space()          can_view_content()
                     anon  outsider  insider   anon  outsider  insider
open    (11 spaces)  ✓     ✓         ✓         ✓     ✓         ✓
private ( 3 spaces)  ✓     ✓         ✓         ✗     ✗         ✓
secret  ( 1 space )  ✗     ✗         ✓         ✗     ✗         ✓
```

**That middle row is why `mvs_drive_forbidden` exists.** A private space is visible — BN shows its
name and a join button — while its contents are not readable by a non-member. §22's original table
had only "not visible → 404" and "read-only → 403", and neither fits.

The same run confirms `get_role()` returns `null` for a non-member and the stored role for an active
one, and that the demo community uses only `owner` and `member` — no `admin`, as the schema says.

**One decision is still open:** what a plain `member` maps to. That is BN's call, per space, and it
is the only row in this table that is not already determined by code on one side or the other.

---

## 5. `/app/config` is the discovery surface

`GET /mvs/v1/app/config` → `documents` reports `enabled` (**per user**), `max_size`,
`allowed_types`, `allowed_mimes`, `default_privacy`, `anonymous_links`, `preview_tiers`,
`max_folder_depth`, `search`.

**Every value is asked of the resolver that enforces it.** Config that disagrees with enforcement
produces a client that draws a control and then meets a refusal — five separate instances of that
were fixed in 2.4.0, the most recent being `preview_tiers`, which had gone stale and was
under-advertising 7 of 11 document types.

**The app must never hardcode any of this.** A client carrying its own copy of the format list, the
size ceiling or the preview tiers disagrees with the server the moment an owner changes a setting.

---

## 6. What is NOT frozen yet

Everything under `plan/document-library.md` §23 that PR2–PR5 build: the drive columns, `GET /drives`,
the `?drive=` parameter, and departing-member reassignment. This document covers the contract BN
writes its error handling and tab logic against — not the routes those will arrive on.
