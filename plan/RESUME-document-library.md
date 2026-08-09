# Resume — 2.4.0 Document Library

**Read this first. It is the handover for the next session.**

Free `wpmediaverse` + Pro `wpmediaverse-pro`, both on branch `2.4.0`.
Last commits: Free `45b55160`, Pro `c9e66bc`, plus the rail CSS on top.

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

## Start here: trash + restore

**The only gap that loses member data.** `DriveActions` already implements
`restore` for documents *and* folders, correctly guarded. Nothing in the UI can
reach it — so trash is a one-way door and a trashed document is simply gone
from every surface the member has.

The write path exists and is proven. What is missing is a listing filtered to
`status = 'trash'` with a restore control per row, and a way into it from the
drive. Small job, high value. Do it first.

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

## Also open, in rough priority

- **Profile block into the left rail** (user's space-saving idea) — deferred
  because it carries an inline edit form, so it is surgery, not a move.
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
