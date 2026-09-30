---
journey: documents-folders
plugin: wpmediaverse-pro
priority: normal
roles: [subscriber]
covers: [MV-DOC-010, MV-DOC-011, MV-DOC-012, documents-folders]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on; licence active (or admin exemption)"
  - "A member with use_mvs_documents and write access to their drive"
estimated_runtime_minutes: 5
---

# Creating, renaming, and moving folders — each refusal names itself

**Why this journey exists**: folder operations have several distinct refusal cases (empty name, duplicate name, invalid characters, six kinds of bad move) that must each surface a specific message — a drag-and-drop UI that silently snaps back with no explanation is a defect in its own right.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>`.

## Steps

### 1. Create a folder
- **Action**: `POST /wp-json/mvs-pro/v1/folders` with a name.
- **Expect**: 201, "Folder created."; name normalized, max 150 chars, max nesting depth 12.

### 2. Empty name and too-long name are refused with specific messages
- **Action**: create with an empty name; then a name over the length ceiling.
- **Expect**: "A folder needs a name." for empty; a specific length-refusal message for the other — never a generic "could not create folder."

### 3. Duplicate name in the same parent is refused
- **Action**: create a second folder with the same name in the same parent.
- **Expect**: "A folder with that name is already here."

### 4. Nesting beyond max depth is refused
- **Action**: nest folders until depth 12 is reached, then attempt one more level.
- **Expect**: refused with a specific message, not a silent no-op.

### 5. Rename: success and duplicate-name refusal
- **Action**: `PUT/PATCH /wp-json/mvs-pro/v1/folders/{id}` with a new name; then rename a second folder to a name already used in the same parent.
- **Expect**: "Renamed." on success; the duplicate attempt shows the specific duplicate-name error inline, not a generic failure.

### 6. Rename with invalid characters
- **Action**: attempt a rename containing invalid characters.
- **Expect**: refused with a specific message.

### 7. Move/nest — the six distinct refusal cases
- **Action**: attempt each of: moving a folder into itself; into its own descendant; into a folder on a different drive; into a trashed parent; exceeding max depth; and moving into a parent that no longer exists (deleted concurrently).
- **Expect**: each produces its own distinct, comprehensible refusal message — none of the six collapse into a generic failure or a silent snap-back.
- **On fail**: `PUT/PATCH /folders/{id}` move validation.

### 8. A successful move
- **Action**: move a folder to a valid destination on the same drive, within depth.
- **Expect**: "Moved."

## Pass criteria

1. Create/rename/move each succeed with the documented confirmation text.
2. Every refusal case (empty name, too-long, duplicate, invalid chars, all six move refusals, max-depth) is independently reproducible with its own specific message.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Any refusal shows a generic "could not create/move folder" | specific-message branch collapsed to a catch-all | folder REST controller validation |
| Drag-and-drop UI silently snaps back with no message | frontend not surfacing the API's specific error text | folder move JS handler |
| Duplicate name accepted | uniqueness check scoped wrong (site-wide instead of per-parent) | folder create/rename validation |
