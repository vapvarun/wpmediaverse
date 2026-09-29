---
journey: challenges-blocks
plugin: wpmediaverse-pro
priority: normal
roles: [administrator, anonymous]
covers: [MV-CHL-013, MV-CHL-014, challenges-blocks]
prerequisites:
  - "Pro active; mvs_challenges_enabled = 1"
  - "At least one challenge id to embed"
estimated_runtime_minutes: 4
---

# `pro-challenge` and `pro-challenges-list` blocks render, empty, and off states correctly

## Setup

- Admin `?autologin=admin`; a real challenge id `<CID>`

## Steps

### 1. `pro-challenge` with an unset id
- **Action**: insert `mvs/pro-challenge` with `challengeId = 0` (default); view in the editor.
- **Expect**: editor-side placeholder/empty state, not a fatal or blank front-end gap.
- **On fail**: `src/blocks/pro-challenge/render.php`.

### 2. `pro-challenge` with a real id
- **Action**: set `challengeId = <CID>`, publish, view frontend.
- **Expect**: same single-challenge detail experience as `/media/challenges/?mvs_challenge_id=<CID>`.

### 3. `pro-challenge` with a nonexistent id
- **Action**: set `challengeId` to an id that doesn't exist.
- **Expect**: clean empty/not-found state on the frontend, no fatal.

### 4. `pro-challenge` toggled off after publish
- **Action**: publish a page with the block pointing at `<CID>`; set `mvs_challenges_enabled = 0`; reload the existing page.
- **Expect**: admin sees an admin-only notice, visitors see nothing — the previously-published page must not error.
- **On fail**: `src/blocks/pro-challenge/render.php` — off-state notice not capability-scoped.

### 5. `pro-challenges-list` populated and empty
- **Action**: insert `mvs/pro-challenges-list` with multiple challenges across statuses; then on a fresh install with zero active/upcoming challenges.
- **Expect**: populated shows theme, deadline, entry count per challenge (same data source as `/media/challenges/`); empty shows a clear message, not an empty `<div>`.
- **On fail**: `src/blocks/pro-challenges-list/render.php`.

### 6. `pro-challenges-list` at scale
- **Action**: with a large historical challenge count, load the block.
- **Expect**: does not unboundedly render every historical challenge — confirm a limit/pagination exists.

### 7. `pro-challenges-list` off state
- **Action**: `mvs_challenges_enabled = 0`; reload.
- **Expect**: admin-only notice for admins, nothing for visitors.

### 8. 390px block rendering
- **Action**: view both blocks at 390px.
- **Expect**: no horizontal overflow, cards stack full-width.

## Pass criteria

1. Both blocks show a clean editor/frontend empty and off state, never a fatal.
2. `pro-challenge` renders identically to the deep-linked detail page for a real id.
3. `pro-challenges-list` never unboundedly renders every historical challenge.
4. Both blocks render usably at 390px.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Block fatals on a deleted/nonexistent challenge id | missing not-found guard | `src/blocks/pro-challenge/render.php` |
| List block renders thousands of rows | no limit applied to the query | `src/blocks/pro-challenges-list/render.php` |
| Off-state notice shown to a regular visitor | notice not gated on `manage_options` | either block's `render.php` |
