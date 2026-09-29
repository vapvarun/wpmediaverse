---
journey: blocks-documents-and-member-photos
plugin: wpmediaverse
priority: normal
roles: [subscriber, anonymous]
covers: [MV-BLK-007, MV-BLK-008, MV-BLK-010, MV-BLK-012, member-photos-block, pdf-viewer-block, collection-shortcode, documents-shortcode]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A member with public photos; a BuddyPress member profile page and a post authored by a member (for auto-resolve testing)"
  - "A public PDF document; the documents master switch on for most of this journey"
  - "A public collection and a members-only collection"
estimated_runtime_minutes: 7
---

# Member Photos auto-resolves its target, the PDF Viewer has 5 distinct empty states, and the collection/documents shortcodes gate correctly

## Setup

- `$DOC_ID`: a public PDF document (documents master switch on — see customer/49 for how to enable it without Pro).
- `$COLLECTION_PUBLIC` / `$COLLECTION_MEMBERS`: one public, one members-only collection.

## Steps

### 1. Member Photos block — auto-resolve order (MV-BLK-007)
- **Action**: place "Member Photos" with `user_id`/`userId` UNSET on (a) a BuddyPress member's profile page, (b) a single post authored by a member, (c) the logged-in member's own dashboard/widget context.
- **Expect**: resolves in order: explicit attribute (none here) → BuddyPress displayed member → post author → current logged-in user → empty state. Confirm each context resolves to the expected member.
- **Action**: set an explicit `user_id` attribute.
- **Expect**: wins outright regardless of context.
- **Action**: place the block on a generic page with no BP context, no post-author context, viewed by a logged-out Visitor.
- **Expect**: a distinct, honest "no user resolved" empty state — not a silent blank block.
- **Action**: toggle `show_header`/`showHeader` and `actions`/`showActions` independently.
- **Expect**: each toggles only its own piece (member header vs. follow/message action row) without affecting the other.

### 2. PDF Viewer block — all 5 empty states (MV-BLK-008)
- **Action**: embed "PDF Viewer" with a VALID public `$DOC_ID`.
- **Expect**: an iframe embed of the browser's native PDF viewer with a `#view=FitH` fragment, lazy-loaded, respecting the same privacy rules as any other media.
- **Action**: embed with NO id.
- **Expect**: distinct empty state 1 ("no id").
- **Action**: embed with a non-existent id.
- **Expect**: distinct empty state 2 ("not found").
- **Action**: embed a media item that is NOT a document (e.g. an image id).
- **Expect**: distinct empty state 3 ("wrong type").
- **Action**: embed a document the current viewer cannot see (privacy-denied).
- **Expect**: distinct empty state 4 ("privacy fail").
- **Action**: embed a document whose underlying file is missing on disk.
- **Expect**: distinct empty state 5 ("missing asset"). Confirm all 5 read differently from each other, not the same generic message.
- **Action**: set `height="50"` and `height="9999"`.
- **Expect**: clamped to the 200-1400 range regardless of the passed value.
- **Action**: turn the documents master switch off; reload the valid-id embed.
- **Expect**: the document does not render anywhere, including here.

### 3. `[mvs_collection]` shortcode (MV-BLK-010)
- **Action**: embed `[mvs_collection id="$COLLECTION_PUBLIC"]` on an arbitrary page.
- **Expect**: renders the collection's resolved items in a simple grid.
- **Action**: embed `[mvs_collection id="$COLLECTION_MEMBERS"]`, view logged out.
- **Expect**: "Collection not found." — the SAME message as a genuinely non-existent id (no enumeration leak distinguishing "doesn't exist" from "exists but you can't see it").
- **Action**: embed with `id="0"` or omit `id` entirely.
- **Expect**: the instructive placeholder ("Please provide a collection ID: …").
- **Action**: view that placeholder as a logged-out, non-editing Visitor.
- **Expect (report, don't assume)**: check whether the placeholder text is visible to an end visitor too (a minor content leak of internal syntax) or only to someone editing the page — flag if visitors see it.

### 4. `[mvs_documents]` shortcode (MV-BLK-012)
- **Action**: embed `[mvs_documents per_page="20"]` with the documents switch ON and `$DOC_ID` public.
- **Expect**: a public document LISTING (rows, not tiles) with type chip/size/author/date and pagination.
- **Action**: turn the documents master switch OFF; reload as an editor (`manage_options`) and separately as a logged-out visitor.
- **Expect**: the editor sees an explanatory "documents are switched off" notice; the plain visitor sees NOTHING at all (no notice, no broken UI) — this split by role is deliberate, don't report the visitor's silence as a bug.
- **Action**: with the switch back on, try the `folder` attribute on Free-only (no Pro).
- **Expect**: editor sees "Folder listings need MediaVerse Pro."; a visitor sees nothing.
- **Action**: visit the shortcode's page with `?drive=my-drive` appended.
- **Expect**: 302-redirects to the dashboard's Documents section if one exists; if the shortcode's page and the dashboard page happen to be the SAME page, confirm no redirect loop occurs.

## Pass criteria

ALL of the following hold:
1. Member Photos resolves in the documented order and shows a distinct "no user resolved" state only when truly nothing can be resolved; header/actions toggle independently.
2. PDF Viewer shows all 5 distinct empty states correctly and clamps `height` to 200-1400; the documents master switch gates rendering here too.
3. `[mvs_collection]` denies a members-only collection with the SAME message as a non-existent id; the missing-id placeholder's visitor-visibility is checked and reported.
4. `[mvs_documents]` shows the editor-vs-visitor message split correctly for both the master-switch-off and Pro-only-`folder` cases; the `?drive=` redirect works without looping.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Member Photos silently renders blank instead of the "no user resolved" state | fallback empty-state branch missing at the end of the resolve chain | `src/blocks/member-photos/render.php` |
| PDF Viewer shows the SAME message for two different failure cases | empty-state branches collapsed/merged | `src/blocks/pdf-viewer/render.php` |
| A members-only collection shows "no such collection ID" text distinct from the not-found case | enumeration leak — privacy-denied and not-found must share one message | `includes/Shortcodes/Shortcodes.php` (collection shortcode) |
| `[mvs_documents]` shows a notice to a logged-out visitor when the switch is off | role check (`manage_options`) missing before rendering the explanatory notice | `includes/Shortcodes/Shortcodes.php` (documents shortcode) |
| `?drive=` redirect loops when shortcode page == dashboard page | redirect target equality not checked before issuing the 302 | wherever the `?drive=` redirect is implemented |
