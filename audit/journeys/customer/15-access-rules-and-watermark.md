---
journey: access-rules-and-watermark
plugin: wpmediaverse
priority: high
roles: [administrator, author, subscriber, anonymous]
covers: [access-rules-ui, watermark-generation, watermark-display, mvs-access-rules-table, mvs_generate_watermark]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "WPMediaVerse Pro active (for the watermark half)"
  - "PHP GD extension available"
  - "dev-auto-login mu-plugin installed"
estimated_runtime_minutes: 8
---

# Access rules can be created from the UI, and watermarks then work

Before 1.9.0 the `mvs_access_rules` table had no admin or frontend UI — only the
REST API could populate it — so `AccessRulesService::has_active_rules()` was
always false on real sites and the Pro watermark feature (gated on it in
`WatermarkService::get_preview_url()`) never activated. This journey proves all
three entry points create rules, and that a ruled image then generates and
displays a watermark to a locked viewer.

## Setup

- Site: `$SITE_URL`
- Owner: `admin` (autologin via `?autologin=1`)
- Fixture: one image media owned by admin (`$MEDIA_ID`, e.g. 182)
- Enable watermarking: `mysql_query "UPDATE wp_options SET option_value='1' WHERE option_name='mvs_watermark_enabled'"`
- DB clean:
  ```sql
  DELETE FROM wp_mvs_access_rules WHERE media_id = <MEDIA_ID>;
  ```

## Steps

### 1. Frontend edit modal exposes the Access Rules panel
- **Action**: `playwright_navigate $SITE_URL/media/<slug>/?autologin=1`, then `playwright_evaluate window.mvsOpenEditModal(<MEDIA_ID>)`.
- **Expect**: `.mvs-access-rules` panel with an "Add rule" button is present in the modal.
- **On fail**: `templates/partials/shared-ui-frame.php` (panel markup) or `src/blocks/shared-ui/view.js` (store).

### 2. Options load from the bounded REST endpoint
- **Action**: click `.mvs-access-rule-add`; inspect the new row's type + value selects.
- **Expect**: type options are `role`, `capability` (+ `membership` only when BuddyPress groups are active); the role select is populated from `GET /mvs/v1/access/options` (`get_editable_roles()`), never the full user/group corpus.
- **On fail**: `AccessController::get_options` / `AccessRulesService::get_builder_options`.

### 3. Saving a rule persists it (member write path)
- **Action**: set the role value to `subscriber`, click "Save changes"; then `mysql_query "SELECT rule_type, rule_value FROM wp_mvs_access_rules WHERE media_id=<MEDIA_ID>"`.
- **Expect**: exactly one row `role / subscriber`. Reopening the modal reloads that row (`GET /media/{id}/rules`).
- **On fail**: `saveEditModal()` POST in `src/blocks/shared-ui/view.js` or `AccessController::set_rules`.

### 4. Admin All Media manages the same rules (backend write path)
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=mvs-media&view=access&media_id=<MEDIA_ID>`. Add a `capability = read` rule via the form; then follow a row's "Remove" link.
- **Expect**: the current-rules table shows role/capability rows with slug→label mapping; add + remove each redirect back to `view=access` and change `wp_mvs_access_rules`. Actions gated on `manage_mvs_access` + per-action nonce.
- **On fail**: `MediaListPage::render_access` / `handle_access_rule_actions` / `templates/admin/access-rules.php`.

### 5. Watermark generates once a rule exists (Pro)
- **Action**: with a rule on `<MEDIA_ID>` and watermarking enabled, `restFetch GET /mvs/v1/media/<MEDIA_ID>` (via `window.mvsRest`).
- **Expect**: response has `watermarked: true` and a signed `preview_url` (`mvs_size=watermark`); the file `wp-content/uploads/wpmediaverse/previews/<MEDIA_ID>-preview.jpg` exists on disk.
- **On fail**: `WatermarkService::get_preview_url` gate at line ~141, or Pro `Watermarker::generate` (`mvs_generate_watermark`).

### 6. Locked viewer sees the watermarked preview (display path)
- **Action**: log out; place `<!-- wp:mvs/lock-overlay {"mediaId":<MEDIA_ID>} /-->` on a page; view it anonymously.
- **Expect**: `.mvs-lock-overlay-locked` renders `img.mvs-lock-overlay-preview__watermarked` whose `src` is the signed `mvs_size=watermark` URL, and `getComputedStyle(img).filter === 'none'` (watermark is shown UNBLURRED — it is the protection).
- **On fail**: `src/blocks/lock-overlay/render.php` (watermark branch) or `src/blocks/lock-overlay/style.css`.

### 7. Responsive check — desktop AND mobile
- **Action**: with the edit modal open on the Access Rules panel, `playwright_resize 1280 800` screenshot; `playwright_resize 390 844` screenshot.
- **Expect (390px)**: no horizontal scroll; the rule row stacks (type / value / remove full-width); "Add rule" reachable (>=40px).
- **On fail**: the `@media (max-width: 480px)` block for `.mvs-access-rule-row` in `assets/css/shared-ui-frame.css`.

### 8. Translation-readiness check
- **Action**: grep `templates/partials/shared-ui-frame.php`, `templates/admin/access-rules.php`, `includes/REST/Controller/AccessController.php`, `includes/Services/AccessRulesService.php` for visible strings; confirm `src/blocks/shared-ui/view.js` strings use `__()`.
- **Expect**: zero hardcoded user-facing literals; text domain `wpmediaverse`.
- **On fail**: the template/controller/JS emitting the unwrapped string.

## Pass criteria

ALL of the following hold:
1. The Access Rules panel + admin screen create and delete rows in `wp_mvs_access_rules` through the UI (not just REST).
2. Options come from `GET /access/options` and never enumerate users/groups.
3. A ruled image with watermarking enabled returns `watermarked: true` and writes a preview file.
4. A locked anonymous viewer sees the watermarked preview, unblurred.
5. Renders correctly at 1280x800 AND 390x844 (no horizontal scroll, actions reachable).
6. All visible strings are translation-ready with the `wpmediaverse` text domain.

## Fail diagnostics

- Watermark stays empty even with a rule → confirm Pro active + GD present; check `WatermarkService::is_enabled()` and `has_active_rules()`; a stale `_mvs_watermark_url` meta is harmless (the has_active_rules gate runs first).
- Rule saved but modal reopens empty → `GET /media/{id}/rules` shape or `openEditModal` hydration.
- Admin add/remove no-ops → `manage_mvs_access` capability missing on the actor, or nonce name mismatch.
