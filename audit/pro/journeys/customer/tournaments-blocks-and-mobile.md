---
journey: tournaments-blocks-and-mobile
plugin: wpmediaverse-pro
priority: normal
roles: [administrator, anonymous]
covers: [MV-TRN-001, MV-TRN-014, MV-TRN-015, MV-TRN-017, tournaments-list-view, tournaments-blocks, mobile-390]
prerequisites:
  - "Pro active; mvs_tournaments_enabled = 1"
  - "Tournaments across registration/active/finalized states; one 8+ player active multi-round bracket"
estimated_runtime_minutes: 6
---

# Tournaments list view, both blocks, and bracket rendering all survive empty states and 390px

## Setup

- Real tournament ids for the single-embed block; a multi-round active tournament for the mobile bracket check

## Steps

### 1. List opens on the first non-empty tab; per-tab empty copy
- **Action**: log out; visit `/media/tournaments/` with tournaments in Registration/Active/Finalized; then with one tab empty.
- **Expect**: opens on the first non-empty tab (`TabPicker`). The empty tab shows "No tournaments right now. Check back soon!" (a plain bound string, not an i18n `empty*` key like Challenges — confirmed this is intentional, not missing). A failed fetch shows "Failed to load tournaments. Please refresh."
- **On fail**: `includes/Tournaments/Renderer.php` tab default / empty-state string.

### 2. `pro-tournament` block with a real id, matches the deep link
- **Action**: insert `mvs/pro-tournament` with `tournamentId` set to a real id; publish; separately deep-link `?mvs_tournament_id=<id>`.
- **Expect**: both render the identical bracket + registration/match UI.
- **On fail**: `src/blocks/pro-tournament/render.php`.

### 3. `pro-tournament` with 0/invalid id
- **Action**: set `tournamentId = 0` or a nonexistent id.
- **Expect**: clear empty/placeholder state in the editor AND on the frontend, never a fatal.

### 4. `pro-tournament` off state
- **Action**: `wp option update mvs_tournaments_enabled 0`; reload a page with the block.
- **Expect**: admin sees a notice; visitors see nothing (documented off-state convention).

### 5. `pro-tournament` + `pro-tournaments-list` on one page — no Interactivity collision
- **Action**: place both blocks referencing overlapping data on one page.
- **Expect**: no state collision between the two Interactivity API instances.

### 6. `pro-tournaments-list` populated and empty
- **Action**: insert the block with several tournaments across statuses; then on a fresh install with zero qualifying tournaments.
- **Expect**: populated shows status badges, start dates, entry CTAs; empty shows a clean empty list, not broken markup.
- **On fail**: `src/blocks/pro-tournaments-list/render.php`.

### 7. `pro-tournaments-list` off state
- **Action**: `mvs_tournaments_enabled = 0`; reload.
- **Expect**: admin-only notice in the editor; published-page visitors see an empty result, not an error.

### 8. Bracket view at 390px — auto-scroll, fade-edge, current round in view
- **Action**: open the 8+ player bracket at 390px; scroll horizontally through rounds.
- **Expect**: the current round is scrolled into view by default (visitor doesn't need to hunt for it); a fade-edge hint clearly signals more bracket to scroll; round labels stay legible/pinned.
- **On fail**: bracket visualizer CSS/JS — missing default scroll-to-current-round + fade-edge affordance.

### 9. 64-slot / 6-round bracket at 390px
- **Action**: view a large (64-slot) bracket at 390px, scroll through all 6 rounds.
- **Expect**: acceptable scroll performance, round labels remain legible; no round is clipped or hidden.

### 10. Tournament cards list at 390px
- **Action**: view `/media/tournaments/` list itself at 390px.
- **Expect**: cards stack full-width, no horizontal page-level scroll.

## Pass criteria

1. List opens on a populated tab and shows the documented per-tab empty/error copy.
2. Both blocks render real data identically to their standalone-page equivalents, with clean empty/off states, never a fatal.
3. Two Interactivity instances on one page don't corrupt each other's state.
4. The bracket view auto-scrolls to the current round and shows a fade-edge hint at 390px, including at 64-slot scale.
5. The tournament cards list itself has no horizontal page scroll at 390px.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| List always opens on an empty tab | `TabPicker` not wired for tournaments | `includes/Tournaments/Renderer.php` |
| Block fatals on id=0 or nonexistent | missing not-found guard | `src/blocks/pro-tournament/render.php` |
| Bracket doesn't scroll to current round at 390px | no default-scroll-into-view on mount | bracket visualizer JS |
| List block renders every historical tournament | missing status/date filter or limit | `src/blocks/pro-tournaments-list/render.php` |
