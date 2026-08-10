# Resume — 2.4.0 Document Library

**Read this first. It is the handover for the next session.**

Free `wpmediaverse` + Pro `wpmediaverse-pro`, both on branch `2.4.0`.
Last commits: Free `45b55160`, Pro `c9e66bc`, plus the rail CSS on top.

**Companion artifact** (visual UX spec, screens and layouts):
<https://claude.ai/code/artifact/3620f81c-eaea-4d17-9f0e-56e178ec56e2>

The written spec below is the authoritative one — it carries the guards, the
URL scheme and the reasoning, and it is versioned with the code. The artifact
is the picture of it. If they disagree, this file wins; update the artifact to
match rather than the other way round.

---

## Where it stands

Documents are **Pro-only** and gated behind `mvs_documents_enabled`
(Free's default is `false`; Pro flips it with `__return_true`).

**Verified working, as a real member — not as admin.** Admin passes guards a
member does not, so the walk ran as a subscriber with `upload_mvs_media`:

```
 1. Documents section offered        PASS
 2. Create a folder                  PASS   (duplicate name correctly refused on re-run)
 3. Upload into that folder          PASS
 4. It landed IN the folder          PASS
 5. Private by default               PASS
 6. Shows in the drive               PASS
 7. Rename                           PASS
 8. Move to drive root               PASS
 9. Make public                      PASS
10. Full-text search finds it        PASS   index=ready
11. Trash removes it from the drive  PASS
12. Member can SEE their trash       FAIL   ← the one real gap
```

Script: `scratchpad/journey.php` (session scratchpad — re-create if gone; run
through the **`mcp-local-wp` `wp_cli` tool**, never bare `wp`, which hits a
different database on Local).

Free 371 tests green. Both CI gates green. CSS token contract clean.

---

## Done since: trash + restore, and the profile in the rail

**Trash is built and browser-verified as `journey-member`** (Free `b6fee7fb`,
Pro `1d09817`). `?show=trash` on the drive — a query arg, not a path, because
`/documents/trash/` would shadow a folder somebody named "Trash". Drive-wide.
Trashed names render as text, not links; the Items column is dropped rather
than filled with a count that would be wrong; folders list subtree ROOTS only,
because `restore()` refuses a folder whose parent is still trashed. Restoring a
document into a still-trashed folder is refused the same way, with a message
saying which folder to restore first. Walked in the browser: trash → restore →
back on the drive, and the refusal path fires and reads correctly.

**Two counts fixed on the way.** `count_documents()` was site-wide and
status-blind: the rail read "Documents 6" beside "Media 0" for a member who
owned three, and a trashed document went on being counted. Author + status
scoping added; the unscoped default stays for the extraction health check.

**The profile is in the rail** (Free `792d59e8`). The card above the sections
is gone (~110px back on every visit). `Edit profile` is a real section at
`/my-media/profile/`; `View profile` is a link in the rail head, deliberately
NOT a section, because it navigates away and every other item does not.

**Two structural fixes underneath that, both worth knowing:**

1. Section URLs are built from the registry now, not a literal list of seven
   slugs. Declaring a section through the documented filter used to produce a
   rail item pointing at a 404.
2. **The rail was losing to the plugin's own `!important`,** not to the theme.
   The armour from the horizontal-tab era pinned `border-bottom`,
   `border-radius: 0` and `background: none`, so every rule the rail wrote for
   itself lost. That is the real cause of the "three items render as red
   underlined links" symptom recorded below. Diagnosed by computed style —
   `display: flex` from a rule applied while `border: 0` from the SAME rule did
   not, which can only mean something later and at least as heavy.

   **The lesson for the sweep below:** before adding declarations to beat a
   theme, check whether the plugin is beating itself. Grep `!important` in the
   surface first.

The auto-login mu-plugin now SWITCHES users instead of bailing when someone is
already signed in. Bailing is what let a whole QA walk run as admin.

---

## Then: the plugin owns its classes (Varun, 2026-08-09)

The recurring failure all session was **the theme winning**: buttons painted
red, "Move to trash" unreadable on a red field, `.mvs-btn-primary` existing only
inside `#buddypress`, and — most recently — three rail items rendering as red
underlined links while the other three rendered correctly.

That last one is the diagnostic: *inconsistency across sibling items is the
signature of inherited styling rather than declared styling.* The cause was rail
appearance being declared inside a `min-width` media query, so outside it the
theme's `a` rules and the horizontal tab's `border-bottom` both applied.

Fixed for the rail (unconditional block at the end of `frontend.css`, which also
makes counts pills instead of bare digits after the label). **Not fixed
anywhere else.** The sweep to do:

> Every plugin-owned control declares `background`, `color`, `border`,
> `text-decoration` and `box-shadow` explicitly, rather than assuming a default.
> No plugin surface may depend on a property it never declares.

Do it as **one deliberate pass**, not one selector at a time when a screenshot
catches it. Candidates: `.mvs-btn` / `.mvs-btn-primary` outside `#buddypress`,
the drive row actions, the document toolbar, the admin documents screen.

---

## Then: uniform panel anatomy

`DashboardSections` governs the **rail and availability** — not panel interiors.
Media and Favourites are grids with their own toolbars; Albums and Collections
have no search and no sort. The user asked for every tab to look uniform
("best to make each tab design at same time"). Not started.

---

## The document library is feature-complete (2026-08-10)

All nine remaining tasks are built and browser-verified as `journey-member`,
desktop and 390px, plus a dark-mode pass. See
`plan/document-library-remaining.md` for what each one decided and why.

**What the browser found that CI never would**, in order — every one of these
passed every gate: creating a folder always landed on a 404; the Location
column left folder rows a cell short so every column after it shifted; a
shared document was listed for the grantee and answered 403 when opened;
`$mvs_dash_drive` gated the documents panel and was never assigned in any
revision; the notice map had been guessing error codes, so the two refusals
members hit most had always read "that change could not be made"; and the
actions menu opened downward off the end of the list once Share made it tall.

**What is left before this can ship:** the release battery. Full unit suite,
cert, combo smoke, manifest + docs refresh — none have run. The suite cannot
currently run here at all (WP test library absent), which is the first thing to
fix.

---

## Also open, in rough priority

- **The rail strip does not scroll the active item into view on a direct URL
  load.** `init()` already does this on click and on hash; landing on
  `/my-media/profile/` at 390px leaves the active item off-screen to the right.
  Small, and it makes the narrow rail honest about where you are.
- Share to a person or role; document-page action set; folder header;
  multi-select + bulk move; drive search box; Location column; global sort;
  download from the row.
- Admin-side folder management and privacy editing.
- Schema gap recorded, not applied: index `(media_type, post_author, status,
  created_at)` for Recent deep pagination.
- Phase 1 leftovers: P1.3 CI ban, P1.4 mutation test.

---

## What to keep in mind

**The method that found ~20 real defects this session:** walk it in a browser or
over HTTP before automating, and make fixtures adversarial. Every CI gate was
green the whole time — **none** of those defects were CI-detectable. Notable
catches: uploads silently ignoring the target folder (`folder_id` vs `folder`),
a test that passed against a broken guard because it bypassed the repository
cache, and a false-positive release blocker caused by the auto-login mu-plugin
bailing out when already logged in (I was testing as admin without realising).

**The architecture invariant that keeps biting:** Pro never imports Free
concrete classes and never queries Free tables raw. Two A4 violations happened
this session; both are now marked with an inline `// allowed-direct:` comment,
which the checker filters by line.

**Section registry semantics** — three absences, all distinct from "empty":
`available` (component not installed), `enabled` (owner switched it off),
`capability` (this member may not). A section with nothing in it **stays** and
shows zero. `null` from a count means "does not count itself"; `0` means it
counted. And **hidden is not guarded** — the registry decides what to *draw*;
every panel and endpoint still checks its own capability.

**Standing constraints:** no co-author attribution in commits; `mcp-local-wp`
`wp_cli` not bare `wp`; Playwright MCP tools, never Playwright scripts; never
seed fixtures with `$wpdb->insert()` at a chosen `media_id`; verify every UI
change at 390px; never write artifacts to `$HOME`.

---

# Document UX spec

The design settled on during this session. Recorded here because it lived in
artifacts and screenshots, and those do not survive a new session.

Two rules shaped all of it, both from Varun, both non-obvious enough to restate:

> **Actions belong to the object.** Keep single-document and folder actions on
> the row itself rather than compacting everything into one toolbar. Member
> usability is the first priority — a member should not have to select a thing
> and then travel somewhere else to act on it.

> **Show what exists, not what is possible.** Filter chips are built from the
> types actually present (`document_type_counts()`), never the full vocabulary.
> Eleven chips of which nine return zero is worse than three that all work.

---

## Two screens, and only two

### 1. Global — `/explore-document/`

All **public** documents on the site. The equivalent of Explore for media, and
it deliberately mirrors it: same search field, same chip row, same grid rhythm,
so a member who has used Explore already knows this page.

- `.mvs-explore-search` — full-text search across extracted content
- `.mvs-tag-cloud` — type chips **built from what is present**
- No drive tabs. This screen is not about whose document it is.

Built. Templates: `templates/documents.php`.

### 2. Mine — the member's drive

Inside My Media, not a separate destination. Two sections on **one** screen —
*My Documents* and *Documents shared with me* — because, in Varun's words,
"so people do not have to go anywhere."

Upload lives on this screen too. The original bounce was that the documents tab
showed a list but sent you elsewhere to add to it; a list you cannot add to is
half a feature.

Built: drive, folders, upload-in-place, per-row actions.
Not built: the *shared with me* section as a distinct band, trash.

---

## URLs — pretty, not fragments

`?drive=my-drive&folder=69#documents` was the thing to kill. Fragments were
called out specifically: **"# link will not work as it will always confuse."**
A fragment cannot be resolved server-side, cannot be paginated, and cannot be
linked to reliably.

Rewrites in `Core\TemplateLoader`:

```
^<slug>/documents/page/([0-9]+)/?$
^<slug>/documents/(.+?)/page/([0-9]+)/?$
^<slug>/documents/(.+?)/?$
^<slug>/documents/?$
^<slug>/(media|albums|favorites|collections|challenges|battles|tournaments)/?$
```

Query vars: `mvs_doc_view`, `mvs_doc_path`, `mvs_doc_page`, `mvs_section`.

Folder paths are **slug paths**, resolved through `FolderService::find_by_path()`
against the materialised path (`/12/48/`). Slugs are unique **per parent**, not
per drive — two folders may both hold a `contracts` child.

`DriveActions::redirect()` strips `drive`, `folder` and `doc_page` on the way
back so a write never re-introduces the legacy query string into a pretty URL.

Built.

---

## Navigation — a vertical rail

Eight sections across the top was too many; the rail gives room to group and
reads better as the list grows. Rendered from `DashboardSections::grouped()`.

- Group headings are **earned**: a heading appears over two or more items, so
  "Compete" above a single item called "Compete" cannot happen.
- **Compete is one item** pointing at `/compete/`, not three hash tabs.
- Counts are pills at the far end, tabular figures, inverted when active.
- Below 860px the headings are hidden and the rail becomes a scroller.

Built. Still open: moving the profile block (avatar, name, View/Edit Profile)
into the rail to reclaim the vertical space it currently costs — deferred
because it carries an inline edit form.

---

## The drive itself

**Row anatomy**, left to right: type badge (`DIR`, `MD`, `PDF`…), name, items
or size, modified, owner, actions.

- A folder row shows **item count**; a document row shows **size**. Same column,
  different question, because "1 item" and "41 B" answer what each thing is.
- Owner reads **You** for your own, not your display name.
- The action control is per row. Panels must not be clipped — the drive uses
  rounded first/last rows instead of `overflow: hidden` on the container, which
  was what cut the first panel off.

**Actions** (all through `DriveActions`, one handler, one nonce, one ownership
proof): rename, move, privacy, trash, restore — for **both** documents and
folders. Plain POST with post/redirect/get, matching the server-rendered page
they live on; a refresh never repeats a write.

Outcomes come back through `mvs_done` and render as a notice.
`WP_Error` codes keep **their** message — "a folder called Contracts is already
here" is actionable; "something went wrong" is not.

**Guards worth not re-deriving:**

- `can_edit`, never `can_view` — a document shared to *read* must not be
  renamable, movable out of the owner's folder, or binnable.
- A media id must never be writable through the document drive; it would be a
  second, unguarded way to edit a photo.
- Moving *into* someone else's folder is refused — it files a document where
  its owner cannot reach it.
- Folder privacy **cascades** to contents. A folder that says private while its
  contents stay public is the bug that cascade prevents.
- Trash **withdraws from delivery** — `can_read()` refuses non-publish, so a
  trashed document stops being served even on a live share link.

Built.

---

## Still to design and build

- ~~Trash view + restore~~ — **built and verified.** See the top of this file.
- **Shared with me** as its own band on the drive screen.
- **Share to a person or role** — link sharing works; targeted sharing does not.
- **Folder header** — the current folder's name, path breadcrumb and its own
  actions, at the top of a folder view.
- **Multi-select + bulk move.** This is the one place a compacted toolbar is
  right, because the action genuinely applies to a set. It does not replace the
  per-row actions; it sits alongside them.
- **Drive search box** — global search exists, in-drive search does not.
- **Location column**, so a search result says where it lives.
- **Global sort** across the drive.
- **Download from the row**, without opening the document first.
- **Admin**: folder management and privacy editing. Entry-point rule 18 wants
  all three surfaces — frontend, backend, API — and admin is the thin one.

---

## Things already fixed, so they are not re-found

- Uploads ignored the target folder: the client sent `folder_id`, the endpoint
  read `folder`. Found only by uploading into a folder and looking.
- Chips showed all eleven possible types, nine returning zero.
- The single-document page rendered nothing — no viewer was wired.
- Share links were completely dead until `permission_from_link()` existed.
- `get_permalink()` was called on an index id rather than a post id.
- Quota was accounted but not enforced on the document ingest path.
- Markdown containing HTML was refused, then stored as `text/plain`.
- The document type dispatch trusted the claimed MIME; it now dispatches on
  extension, because `wp_check_filetype_and_ext()` returns an extension-derived
  MIME for non-images and an archive marker check was unreachable behind it.
