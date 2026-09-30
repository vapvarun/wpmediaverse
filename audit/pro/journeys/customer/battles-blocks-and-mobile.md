---
journey: battles-blocks-and-mobile
plugin: wpmediaverse-pro
priority: normal
roles: [administrator, anonymous]
covers: [MV-BAT-012, MV-BAT-013, MV-BAT-014, battles-blocks, mobile-390]
prerequisites:
  - "Pro active; mvs_battles_enabled = 1"
  - "At least one existing battle"
estimated_runtime_minutes: 6
---

# The single-battle and active-battles blocks render and vote correctly; the Battles page survives 390px

## Setup

- Admin `?autologin=admin`; an existing battle id `<BID>`

## Steps

### 1. `mvs/pro-battle` with no battle chosen
- **Action**: insert the block on a page with `battleId = 0` (default); view as admin, then as a logged-out visitor.
- **Expect**: admin sees "Pick a battle in the block sidebar to see it here."; a non-admin visitor sees nothing (no notice, no broken shell).
- **On fail**: `src/blocks/pro-battle/render.php`.

### 2. `mvs/pro-battle` with a real id
- **Action**: set `battleId = <BID>`, publish, view frontend.
- **Expect**: same single-battle view as the deep-linked `/media/battles/?mvs_battle_id=<BID>` page.

### 3. `mvs/pro-battle` with a deleted battle id
- **Action**: set `battleId` to an id that no longer exists.
- **Expect**: graceful empty/not-found state, no PHP notice.

### 4. `mvs/pro-battle` feature-off state
- **Action**: `wp option update mvs_battles_enabled 0`; reload the page with the block.
- **Expect**: admin-only notice for admins; empty string for everyone else.

### 5. `mvs/pro-battles-active` with zero active battles
- **Action**: `wp option update mvs_battles_enabled 1`; insert the block on a fresh page with zero active battles.
- **Expect**: clean empty state, not a broken loop.

### 6. `mvs/pro-battles-active` populated, voting works from the block
- **Action**: with active battles present, vote from within the block as a third-party member.
- **Expect**: identical toasts/behavior to the standalone Battles page vote flow (same renderer path).

### 7. Two Interactivity instances on one page don't collide
- **Action**: place the `mvs/pro-battles-active` block on a page that ALSO contains the full `/media/battles/` content (e.g. via a shortcode/embed, if reachable) or two copies of the block.
- **Expect**: no state collision between the two Interactivity API instances — voting in one does not corrupt the other's displayed state.

### 8. Mobile 390px
- **Action**: at 390px, load `/media/battles/` and open a battle card.
- **Expect**: no horizontal scroll; the challenger/opponent side-by-side matchup either stacks or is preserved responsively; tap targets (vote, accept/decline, submit) are >= 44px.

### 9. Opponent search at narrow width
- **Action**: at 390px, open "Challenge Someone" and type into the opponent search.
- **Expect**: results dropdown doesn't overflow the viewport width; long display names wrap rather than break the layout.

## Pass criteria

1. Both blocks show clean editor/frontend empty and off states — never a fatal or broken shell.
2. A block renders a real battle identically to the deep-linked page, including voting behavior.
3. Two block instances on one page don't share/corrupt Interactivity state.
4. `/media/battles/` and its cards are usable at 390px with no horizontal scroll and >=44px tap targets.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Block shows a fatal on a deleted battle id | missing not-found guard | `src/blocks/pro-battle/render.php` |
| Off-state notice visible to a regular visitor | notice not scoped to `manage_options` | `src/blocks/pro-battle/render.php` / `pro-battles-active/render.php` |
| Voting from the block behaves differently than the page | block uses a divergent renderer/JS path | `src/blocks/pro-battles-active/view.js` |
| Horizontal scroll at 390px on a battle card | missing `@media` breakpoint | Pro battles stylesheet |
