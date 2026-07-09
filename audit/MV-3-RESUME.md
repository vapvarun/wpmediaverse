# MV-3 — Album-create fix (RESUME SPEC)

Basecamp card: **10069383195** (Bugs column, project 46336461). Branch: **2.0.0**.
Everything needed to finish this in a fresh session is here — no re-derivation required.

## Verdict already established (do NOT redo)
- **Data model is CORRECT — no backend change.** Albums are a CPT `mvs_album`, created via `wp_insert_post` with `post_author` and **no name-uniqueness check** (`includes/Services/AlbumService.php:49-61`). Same album name (e.g. "Birthday") works for every member. Proven live: user 1 → post 181, user 20 → post 182, both succeeded. Album names are free-form personal labels; never make them unique.
- The false **"Please enter an album name"** is a **frontend** bug, not a naming/collision issue.

## Root cause (frontend) — two duplicate album-create paths
1. **Upload modal** (Interactivity): `src/blocks/shared-ui/view.js:938` — validates `state.uploadModalAlbumTitle.trim()`, then POSTs `albums` `{title, description, privacy}`. Interactivity input binding is FINE (verified with real keystrokes on tag/title fields this session) → this path most likely works.
2. **BP activity composer** (vanilla JS): `assets/js/bp-activity-media.js:556` — validates `i.value.trim()` where `i = document.getElementById('mvs-bp-album-title')` is **captured once at init**. If the composer re-renders, `i` is a stale ref reading empty → **the likely offender**. POSTs `h + 'albums'` `{title, description, group_id?}`.

## The fix (two parts)
1. **Stale-ref:** in `bp-activity-media.js` album-create click handler, read the input value at **click-time** (`document.getElementById('mvs-bp-album-title').value.trim()`), not the init-captured `i`.
2. **Consolidate (kill the dup):** extract ONE shared helper — `createAlbum(name, { description, privacy, groupId })` — that does: validate name (single `"Please enter an album name."` string), `window.mvsRest.restFetch(restUrl + 'albums', {method:'POST', body})`, return `{ok, data, error}`. Both surfaces call it. Since the two live in different bundles, expose the helper on a shared global (e.g. `window.mvsRest.createAlbum` or a small `window.mvsAlbums`), or at minimum share the validation+POST shape so the message + create logic live in one place.
3. **Rebuild:** `view.js` is source → run the block build (`npx grunt build` or the project's JS build) so `build/blocks/**` reflects the change. Do NOT hand-edit `build/`.

## Reproduce first (verify the break before fixing)
- **BP composer:** `/activity-2/` (BP activity dir on this sandbox). The composer's "Post in" + album button needs the user to belong to a group — "QA Replication Group" (id 2) exists for user 1. Open composer → album-create → type a name → check for the false error.
- **Upload modal:** `/my-media/` → open upload (the `+` FAB) → album mode → type a name.

## Browser-automation gotcha (learned this session — saves hours)
- **Interactivity API handlers only fire on TRUSTED events.** Synthetic `dispatchEvent(new Event('input'))` / `new KeyboardEvent('keydown', {key:'Enter'})` do NOT trigger the store — the tag chip won't commit, inputs won't bind.
- **Use real Playwright input:** `browser_type` (real fill) + `browser_press_key('Enter')`. That is how the tag/title fields were successfully verified. Use a clean browser context (`?autologin=1` on mediaverse.local).

## Verify the fix end-to-end
1. Repro the false error is GONE on both surfaces (real keys).
2. Create an album named "Birthday" as user 1 AND user 20 → both succeed (DB: two `mvs_album` posts, distinct `post_author`).
3. Confirm only ONE validate+POST path remains (grep: no two independent `Please enter an album name` create blocks).
4. `php -l` clean; WPCS clean; duplication-gate stays green.
5. Commit to `2.0.0` with `[10069383195]`; comment the card + move to Ready for Testing.

## Local env quick-ref
- WP-CLI: `"$LOCAL_PHP" "$WPCLI" --path="/Users/varundubey/Local Sites/mediaverse/app/public"` (php at Local lightning-services php-8.2.27, wp-cli.phar at Local.app extraResources).
- Basecamp (MCP was offline; API works): token at `~/.mcp-servers/basecamp-mcp-server/.basecamp-token-cache.json` (`.accessToken`); create/comment via `curl` to `3.basecampapi.com/5798509/...`; Bugs col `9667036607`, RFT col `9637173253`.
