---
journey: blocks-upload-and-dashboard-shortcodes
plugin: wpmediaverse
priority: normal
roles: [subscriber, anonymous]
covers: [MV-BLK-006, MV-BLK-009, MV-BLK-011, MV-BLK-013, MV-BLK-014, media-upload-block, dashboard-shortcode, profile-edit-shortcode, usage-history-shortcode, lock-overlay-legacy]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "dev-auto-login mu-plugin installed"
  - "A normal page to embed each shortcode on"
  - "Member A has made at least one upload (for the usage ledger)"
estimated_runtime_minutes: 6
---

# The inline upload block, dashboard/profile-edit/usage-history shortcodes on an arbitrary page, and the deprecated lock-overlay shortcode

## Setup

- Member A (`?autologin=<memberA>`), a page for the Upload block, a separate unmapped page for `[mvs_dashboard]`, a page with `[mvs_profile_edit]`, a page with `[mvs_usage_history]`, and an old page containing a bare `[mvs_lock_overlay]` left over from a pre-2.6.0 install.

## Steps

### 1. Media Upload block / `[mvs_upload]` (MV-BLK-006)
- **Action**: insert "Media Upload" on a normal page; view logged out.
- **Expect**: a clear "log in to upload" gate matching the FAB's own logged-out behavior — not a broken/disabled form.
- **Action**: log in as an uploader; use the embedded form to upload a file, same as the FAB modal.
- **Expect**: identical behavior to MV-UPL-002 (title/description/tags, progress state, success).
- **Action**: toggle `showPrivacy`/`show_privacy` off.
- **Expect**: the privacy field disappears from THIS embedded form too (still subject to `mvs_allow_user_privacy` when shown).
- **Action**: view the block/shortcode on Free-only (no Pro).
- **Expect**: the plain form renders with no gap or placeholder where Pro's quota widget (`mvs_before_upload_form`) would go.

### 2. `[mvs_dashboard]` shortcode (MV-BLK-009)
- **Action**: insert `[mvs_dashboard]` on an UNMAPPED normal page; view logged out.
- **Expect**: the dashboard's own login gate wraps it (same as the mapped page's gate).
- **Action**: log in; view the same page.
- **Expect**: renders the exact same rail/content (`templates/partials/dashboard-content.php`) as the mapped My Media page.
- **Action**: switch tabs within this shortcode instance (Media → Albums → Collections).
- **Expect (report the actual behavior)**: confirm whether tab-switching works via client-side JS state even without the mapped page's dedicated rewrite rules, or whether a sub-section deep-link (`/my-media/<slug>/`) fails to resolve correctly here — this distinction is explicitly called out as needing browser verification, not assumption.

### 3. `[mvs_profile_edit]` shortcode (MV-BLK-011)
- **Action**: insert `[mvs_profile_edit]` on a normal page; view logged out.
- **Expect**: a PLAIN text prompt "Please log in to edit your profile." — confirm it does NOT use the fancier icon+button login-gate styling seen elsewhere (`[mvs_dashboard]`, the FAB). This inconsistency is a known, already-identified presentation gap (Part 2.2) — report it as confirmed, don't treat it as a new finding.
- **Action**: log in as Member A; use the form.
- **Expect**: the same editable fields as MV-PRF-011, working identically on this arbitrary page.

### 4. `[mvs_usage_history]` shortcode (MV-BLK-013)
- **Action**: insert `[mvs_usage_history limit="20"]` on a normal page; view logged out.
- **Expect**: renders LITERALLY NOTHING — not even a login prompt. This total silence is this shortcode's deliberate, designed behavior (contrast directly with `[mvs_profile_edit]`'s text prompt in step 3) — do not flag it as an inconsistency without checking the code, which we have: it is intentional.
- **Action**: log in as Member A (who has at least one upload); view the page.
- **Expect**: Member A's own append-only usage ledger renders, capped at 20 rows.

### 5. `[mvs_lock_overlay]` — deprecated, silent (MV-BLK-014)
- **Action**: view the old page containing `[mvs_lock_overlay]` in its content.
- **Expect**: renders NOTHING — no raw shortcode text, no overlay markup, no PHP error/warning. This is deliberate: media-locking rules were removed in 2.6.0 and the shortcode stays registered purely to avoid old content showing broken literal shortcode text.

## Pass criteria

ALL of the following hold:
1. The Media Upload block/shortcode behaves identically to the FAB modal, with a proper logged-out gate and a working `showPrivacy` toggle.
2. `[mvs_dashboard]` on an unmapped page renders the same rail/content behind the same login gate; the deep-link behavior on an unmapped page is checked and reported honestly.
3. `[mvs_profile_edit]`'s plain-text logged-out prompt (vs. other surfaces' styled gate) is confirmed as a real, already-known inconsistency, and the logged-in form works.
4. `[mvs_usage_history]` is completely silent when logged out (by design) and shows the capped ledger when logged in.
5. `[mvs_lock_overlay]` renders nothing at all, with no error.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Upload block shows the privacy field with `show_privacy="false"` | shortcode attribute not wired to the same field-visibility logic as the FAB modal | `src/blocks/media-upload/render.php` (or shortcode handler) |
| `[mvs_dashboard]` tab-switch fully reloads the page on an unmapped page | client-side router assuming the mapped page's rewrite rules exist | `src/blocks/dashboard-view/view.js` |
| `[mvs_usage_history]` shows something when logged out | the deliberate silent-return guard removed | `includes/Shortcodes/Shortcodes.php` (usage history handler) |
| `[mvs_lock_overlay]` prints the raw shortcode tag | shortcode no longer registered, so WP echoes it literally | `includes/Shortcodes/Shortcodes.php` (registration list) |
