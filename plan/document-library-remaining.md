# Document library — the nine remaining tasks, in order

> **ALL NINE ARE BUILT AND BROWSER-VERIFIED (2026-08-10).** This file is kept as
> the record of what was decided and why, because the reasoning is what a future
> change needs — not the tick-list. What is left is the release battery: the
> full unit suite, cert, combo smoke and a manifest refresh, none of which have
> run against this work. Note the suite currently cannot run at all on this
> machine (the WP test library is absent; the whole suite fails on
> `AccessRulesServiceTest`), which is itself worth fixing before a release.
>
> Three of the nine turned out to be **activation, not construction** — the
> audit called that correctly. Restore, "Shared with me" and targeted sharing
> were each fully built, guarded, and unreachable from any UI.

**Read `RESUME-document-library.md` first.** That file is the spec and the
state; this one is the build order. Both are versioned with the code.

Written after auditing the branch, not from the backlog wording. Several of
these are **less** work than they read, because the server side already exists
and only the surface is missing — and one of them exists entirely and is simply
unreachable. The audit findings are recorded per task so nobody re-derives them.

---

## The three rules this plan is built to satisfy

1. **No redo.** Tasks are ordered so no task rewrites what an earlier one wrote.
   Where two tasks touch the same function, the earlier one leaves the seam the
   later one needs. The ordering rationale is at the end.
2. **No duplicates.** Every task names what it REUSES before what it adds. Four
   shared seams are introduced across the nine; each is introduced once, by the
   first task that needs it, and named here so the others do not re-invent it.
3. **No dead code.** Two render paths are unreachable today (audit below). Task
   0 settles them before anything is built on top.

### The four shared seams

| Seam | Introduced by | Consumed by |
|---|---|---|
| `DriveRenderer::view_state()` + `drive_url()` carrying it | Task 1 | 4, 5, 7 |
| `FolderService::paths_for( int[] )` — batch path resolution | Task 3 | 4, 5, 9 |
| `Documents\DocumentUrls::signed()` — nonce'd delivery URL | Task 6 | existing `DocumentViewer` |
| `DriveActions::act_on_document()/act_on_folder()` reused per item | Task 7 | — (bulk must not re-implement guards) |

---

## Task 0 — Settle the dead render paths *(do first, ~30 min)*

**Audit finding.** `DriveRenderer::render()` switches on three roots:
`my-drive`, `shared`, `recent`. **Nothing calls it with `shared` or `recent`.**
Free's dashboard passes `'my-drive'` and the `[mvs_document]` shortcode passes
no root at all. So `render_shared()` and `render_recent()` — about 80 lines —
are unreachable, along with their empty states.

`documents_shared_with()` in Free is NOT dead: REST `/me/shared` uses it. Only
the renderer is.

**Decide, then act:**

- `render_shared()` → **keep, and activate in Task 5.** It is the band.
- `render_recent()` → **delete**, unless it is wanted as a rail section. It
  duplicates `render_my_drive()` with `any_folder => true`, and the drive's own
  sort already answers "newest first". Deleting it removes a second listing
  implementation that would otherwise need every later task applied to it too —
  Location, search, bulk, download. That is the real cost of leaving it.

**Done when:** `render_recent()` is gone (or reachable), and `grep -n "case
'recent'"` returns nothing orphaned.

---

## Task 1 — Global sort, and view state that survives navigation

**Do this first of the nine.** It is small, and it creates the seam Tasks 4, 5
and 7 all need. Building them first would mean rewriting their URL handling.

**Audit finding.** Sort is not missing — `the_controls()` already offers
date/name/size + direction, and `drive_documents()` already applies them from an
allowlist. What is missing is **persistence**: `drive_url()` builds a bare path,
so opening a folder, paging, or following a breadcrumb silently drops
`sort`, `order` and `doc_type` back to defaults. The member set a sort and the
drive quietly un-set it. Folder rows also always sort by name (`children()`
default) regardless of the chosen sort.

**Reuse:** `the_controls()`, `drive_documents()`'s allowlist, `FolderService::children()`'s
`orderby`/`order` args (already whitelisted — no new sorting code anywhere).

**Add:**
- `DriveRenderer::view_state(): array` — the current `doc_type`, `sort`, `order`,
  `show`, omitting defaults so a clean URL stays clean.
- `drive_url()` gains the view state via `add_query_arg()`. One place builds a
  drive URL; folder links, breadcrumbs and pagination inherit it for free.
- `the_controls()` carries `show` in its hidden fields (it currently carries the
  legacy `drive`/`folder` only, so applying a filter inside the trash exits the
  trash).
- Map the document sort onto `children()` for folders: `title → name`,
  `created_at → created_at`, `file_size → name` (a folder has no size; say so in
  a comment rather than inventing one).

**Guards:** allowlists stay where they are — do not re-validate in the renderer.

**Done when:** set sort → open a folder → page 2 → breadcrumb back; the sort
holds throughout. Trash + filter stays in trash. 390px checked.

---

## Task 2 — Folder header

**Why here:** it establishes the header region *before* Task 7 puts a bulk bar
in the same place. Doing it after would mean laying out that region twice.

**Reuse:** `the_breadcrumbs()` (already permission-filtered via
`PermissionService::visible_breadcrumbs()` — do not touch that logic),
`the_row_actions( 'folder', … )` for the folder's own controls, `$this->move_targets`.

**Add:** a header block rendered only when `$folder` is non-null, above the
toolbar: folder name as an `<h2>`, the existing breadcrumb trail, item count
(from `count_documents_in_folders()` + `count_children_in()` — both already
batch), and the folder's own action panel.

**Guards:**
- The header's actions are the CURRENT folder's, so the "move into itself"
  exclusion in `the_row_actions()` must still apply — it does, keyed on `$id`.
- Never render a name for an ancestor the viewer cannot see. `visible_breadcrumbs()`
  already returns `truncated`; the header must not add a second, unfiltered path.

**Done when:** inside a folder the header names it, the trail is correct for a
shared-in viewer (test as a member with a grant, not as the owner), and the
actions work from the header. 390px checked.

---

## Task 3 — Location column + batch path resolution

**Why here:** it sets the final column set that Task 7's checkbox column is
added to, and Task 4's search results are unreadable without it.

**Audit finding.** `path_slugs()` is one query per folder. Called per row it is
the N+1 the drive design exists to avoid — 25 rows, 25 queries.

**Add the seam:** `FolderService::paths_for( int[] $folder_ids ): array` —
returns `folder_id => array{slugs: string, names: string}`. One query for every
ancestor across the whole page (collect ids from the materialised `path`
column of all requested folders, `IN (…)` once, then assemble per folder in
PHP). `path_slugs()` becomes a one-folder wrapper over it so there is one
implementation, not two.

**Add the column:** shown in Recent-style listings (any-folder, search, shared)
where rows come from different places; **hidden inside a single folder**, where
every row has the same location and the column would repeat itself 25 times.

**Guards — the one that matters:**
> A shared document's location is a path through **someone else's** drive.
> Folder names carry client identities. Render the location only when the
> viewer can see those ancestors — the same rule `visible_breadcrumbs()` already
> enforces for the trail. For a shared row the viewer cannot resolve, show the
> owner's name, not a path.

**Done when:** search and shared listings say where a hit lives; inside a folder
there is no Location column; the query count per page does not grow with rows
(verify with a 1000-document fixture, not with four). 390px: Location is the
first thing to drop.

---

## Task 4 — Drive search box

**Reuse:** `SearchService::search( $query, $user_id, $args )` — full-text,
permission-filtered, with an `index` readiness payload that already distinguishes
"no results" from "not indexed yet". Do **not** write a second search.

**Add:**
- A `q` view-state key (Task 1's seam) and a search input in `the_controls()`.
- `SearchService::search()` gains `author` and `folder_id` scoping args so the
  drive can ask "mine only" / "in this folder and below". Scope in the SQL
  candidate query, not by filtering results after paging — filtering after paging
  makes the total lie.
- Results render through `the_rows()` with the Location column on (Task 3).

**Guards:**
- Below `MIN_TERM`, say so; do not render an empty state that reads as "you have
  nothing".
- When `readiness['ready']` is false, say "still indexing" — the service already
  returns this and the drive must not swallow it.
- In-drive search must not become a way to enumerate other members' documents:
  scope to the drive author, and let the existing global search stay the
  cross-drive surface.

**Done when:** search inside a folder, at drive root, and with an unindexed
document present; each says something true. 390px checked.

---

## Task 5 — "Shared with me" as its own band

**Audit finding.** This is **activation, not construction.** `render_shared()`
is written, has its own empty state, and is unreachable (Task 0).

**Add:**
- Call it as a second band inside `render_my_drive()`, at the drive root only —
  inside a folder it would be a section about somewhere else.
- Render it only when it has rows OR when the member has ever been granted
  something; an always-present empty band is furniture. (Cheap check: the
  existing count from `documents_shared_with()`'s `total`.)
- A heading per band: *My documents* / *Shared with me*.

**Reuse:** `the_rows()` with `$trashed = false`; Location column from Task 3
with the leak guard; permission prefetch already runs per band.

**Guards:**
- Rows here are **not** the viewer's. `the_rows()` already gates the action panel
  on `can_edit()` — verify a view-only grant renders no panel at all.
- Owner column must show the owner's name, never "You".

**Done when:** member A shares a document with member B by grant; B sees it in
the band, cannot rename or trash it, and can open it. Verified as B, not as
admin.

---

## Task 6 — Download from the row

**Smallest of the nine.** Do it whenever; it blocks nothing.

**Audit finding.** `GET mvs-pro/v1/documents/{id}/download` exists and is
guarded by `can_read()`. `DocumentViewer::signed_url()` already builds the
nonce'd URL — but it is `private`, so a second caller would copy it. That copy
is the dead-code/dup risk here.

**Add the seam:** promote it to `Documents\DocumentUrls::signed( int $media_id,
string $route ): string` (or a small trait next to `AssetRegistrationTrait`).
`DocumentViewer` calls the seam; the drive row calls the same seam. **One
implementation of "how a delivery URL is signed."**

**Add:** a Download item in the row action panel — for every row the viewer can
*read*, not only those they can edit. Note the asymmetry: the panel is currently
rendered only when `can_edit()`, so a view-only row has no panel at all. Either
render a read-only panel containing Download, or place Download outside the
panel. **Prefer outside** — it is the one action a viewer always has, and
burying it behind a menu they otherwise cannot use is worse.

**Guards:** the download URL carries a `wp_rest` nonce, so it is per-session and
must not be cached in a page that is served to more than one member.

**Done when:** a view-only grantee can download from the row; a trashed row
offers no download (delivery already refuses non-publish — confirm the UI does
not offer a link that 404s).

---

## Task 7 — Multi-select + bulk move

**Why late:** it adds a column to a row layout that Tasks 3 and 6 have finished
changing, and a toolbar to a header region Task 2 has finished laying out.

**The rule this task is the exception to, stated so it is not re-litigated:**
> Actions belong to the object — *except* where the action genuinely applies to
> a set. Bulk sits **alongside** the per-row panels and never replaces them.

**Reuse — this is the whole design:** `DriveActions::act_on_document()` and
`act_on_folder()` already prove ownership, refuse media ids, refuse foreign
folders, and return outcome keys. **Bulk loops over them.** Do not write a
second guard path; a bulk endpoint with its own checks is how the two drift.

**Add:**
- A checkbox per row (`name="mvs_ids[]"`, value `document:123` / `folder:45` so
  one field carries both kinds), a select-all in the head row.
- One `<form>` wrapping the list, with a bulk bar that appears only when
  something is selected (progressive enhancement — with JS off the bar is
  always visible and still works).
- `DriveActions::handle()` gains a bulk branch: same nonce, iterate ids, call the
  existing per-item methods, aggregate outcomes into a count — "4 moved, 1
  refused: a folder cannot be moved inside itself".
- A cap (say 100 per submit) with an honest message when exceeded, not a silent
  truncation.

**Guards:**
- Every item is guarded individually. A partial success is reported as partial.
- Mixed selections (folders + documents) must both route correctly — that is why
  the value carries the type.
- Multi-actor: an id that vanished between render and submit returns `gone` and
  is counted, not fatal.

**Done when:** select 3 documents + 1 folder, move to a folder, get an accurate
report; include one item that must be refused and confirm the message says which
and why. 390px: the bar must not cover the last row.

---

## Task 8 — Share to a person or role

**Audit finding — this is a UI task, not a backend one.** The permission ladder
already resolves **user grants, role grants and folder-target grants**
(`PermissionService::prefetch()` reads `grantee_type user|role` and
`target_type media|folder`). The REST surface already has:

- `GET /documents/{id}/permissions` — list grants
- `POST /documents/{id}/permissions` — create, `grantee_type ∈ {user, role}`,
  `permission ∈ LEVELS`, optional `expires_at`
- `POST /documents/{id}/permissions/link` — the link grant that already works
- `DELETE /permissions/{grant_id}` — revoke

So targeted sharing is **built, guarded and unreachable from any UI**. Same
shape as restore was. Do not add endpoints; add the panel.

**Add:** a Share panel on the document row and the document page —
who currently has access (from the list route), add a person (user search) or a
role (from `wp_roles()`), permission select, optional expiry, revoke per row,
and the existing link control alongside.

**Gaps to close while there (check before assuming they exist):**
- Folder-target grants are resolvable but the create route is media-scoped
  (`/documents/{id}/permissions`). Sharing a whole folder needs a folder route —
  the ladder will already honour it. Confirm, then add the route only if absent.
- A user search endpoint for the picker: reuse Free's existing user surface if
  one exists rather than adding a Pro-only one.

**Guards:** `can_manage_sharing()` answers on drive ownership — a grantee with
`edit` deliberately cannot re-share. Keep that; do not "fix" it.

**Done when:** owner grants view to member B and edit to a role; B sees it in the
Shared band (Task 5) with no edit controls; a role holder gets edit; revoke
removes access immediately. Verified as each actor.

---

## Task 9 — Admin: folder management and privacy

**Why last:** it is the largest, and it consumes every seam above.

**Audit finding.** There is **no admin surface for documents or folders at all**
— `includes/Admin/` has no document page. Entry-point rule 18 wants frontend,
backend and API for every data store; folders have frontend and API only. This
is the rule's open item, and it is the one a site owner feels when a member
leaves the company and their folders need reassigning.

**Reuse:** `FolderController` is already full CRUD (`GET/POST /folders`,
`GET/PATCH/DELETE /folders/{id}`, `POST /folders/{id}/restore`), and
`FolderService` owns every rule. The admin page is a client of both — **no
folder logic in the admin layer.**

**Add:** an admin page under the WPMediaVerse menu (Pro admin page pattern,
section cards, no standalone menu) listing folders across drives with owner,
item count, privacy, status; filter by owner and status; rename, move, privacy,
trash, restore; and the big-site checklist applied from day one — pagination,
`COUNT(*)`, indexed sort columns, no per-row queries (use `paths_for()` from
Task 3).

**Guards:**
- An admin acting on a member's folder still goes through `FolderService`, so
  the privacy cascade and depth rules hold.
- `manage_mvs_documents` / `manage_options`, checked inline next to the nonce on
  every destructive action.

**Done when:** an owner can find and fix a member's folder without touching the
database, on a 500-folder fixture, and the privacy cascade is observed from the
admin path exactly as from the frontend.

---

## Why this order

- **1 before 4, 5, 7** — they all build URLs; Task 1 makes one place do it.
- **2 before 7** — both own the header region; lay it out once.
- **3 before 4, 5** — both listings are unreadable without Location, and both
  would otherwise each grow their own path lookup.
- **3 and 6 before 7** — the checkbox column is added to a settled column set.
- **0 before everything** — so no task is applied to a listing nobody can reach.
- **9 last** — it consumes the seams and is the only one that can be cut without
  stranding another task.

Tasks 6 and 8 are independent and can be taken out of order if someone is
working in parallel. 1, 2, 3 are the critical path.

## Per-task verification, non-negotiable

Every task above ends in the browser, **as `journey-member`, not as admin** —
admin passes guards a member does not, and a walk that runs as admin proves
nothing. The auto-login mu-plugin now switches users rather than bailing, so
`?autologin=journey-member` is enough. Every UI change is checked at 390px in
the same pass, not in a later one.

The release battery (full unit suite, cert, combo smoke, manifest refresh) is
owed **once at the end of the nine**, not per task — running it per card costs
more than it catches, which is how people start skipping gates.
