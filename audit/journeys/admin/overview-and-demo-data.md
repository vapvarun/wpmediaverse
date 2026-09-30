---
journey: overview-and-demo-data
plugin: wpmediaverse
priority: high
roles: [administrator, subscriber]
covers: [MV-ADM-001, MV-ADM-002, MV-ADM-003, MV-WIZ-007, MV-WIZ-008, overview-dashboard, demo-data, refusal-as-success]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "A subscriber-level test account with no MediaVerse admin caps"
estimated_runtime_minutes: 6
---

# Owner reads the Overview dashboard, imports demo content, then deletes it

**Why this journey exists**: Overview (`includes/Admin/OverviewPage.php`) is the first
screen a new owner sees, and its "Quick Start with Demo Content" card is the only
one-click way to see the plugin populated. Coding Rule 20 exists specifically because
this button used to lie: a second import while demo data already exists returned a
success envelope while doing nothing, so the JS took the success path and rendered
nothing — no error, no data, no clue. This journey walks the whole Overview screen,
then proves the refusal path is honest in both directions (import-again and
delete-when-nothing-to-delete).

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Overview: `$SITE_URL/wp-admin/admin.php?page=wpmediaverse` (`Core\Plugin::ADMIN_SLUG`, top-level menu, first item)
- Capture baseline Total Media count for the "counts are live" check.

## Steps

### 1. Admin sees live stat tiles and Quick Links
- **Action**: `playwright_navigate $SITE_URL/wp-admin/admin.php?page=wpmediaverse`.
- **Expect**: stat tiles for Total Media / Documents / Albums / Pending Review / Total
  Views / Storage Used render as `.mvs-stat-card` links to `admin.php?page=mvs-media`,
  the Documents page, `mvs-moderation`, and `mvs-stats`. Quick Links section lists All
  Media / Settings / Moderation / Stats.
- **On fail**: `includes/Admin/OverviewPage.php` (stat tile block, ~L200-230).

### 2. Frontend Pages status block and empty-library state
- **Action**: on a library with zero media, read the Recent Uploads panel.
- **Expect**: exact copy "No media uploaded yet." with an "Upload First Media" CTA
  (`admin.php?page=mvs-media`) — not a blank table. Frontend Pages block shows
  Active/Missing per page (explore/dashboard/upload) with a fix link when missing, and
  does not fatal even if a `mvs_page_*` option points at a deleted/trashed post ID.
- **On fail**: `includes/Admin/OverviewPage.php` (Recent Uploads + Frontend Pages sections).

### 3. Non-privileged role is refused, not redirected
- **Action**: log in as the subscriber test account and navigate directly to
  `admin.php?page=wpmediaverse`.
- **Expect**: a full `wp_die()` page reading "You do not have permission to access this
  page." — not a silent redirect to wp-admin home.
- **On fail**: capability check guarding `OverviewPage::render_page()`.

### 4. Import Demo Data — happy path
- **Action**: as admin, on a near-empty install click `#mvs-import-demo-btn`.
- **Expect**: button label flips to "Importing…" (`#mvs-import-demo-status` shows the
  inline status); on completion the browser redirects to the Explore page; ~12 sample
  media items, albums and reactions now exist. Re-check the Overview Total Media tile —
  it must have incremented (proves the count is live, not cached).
- **On fail**: `wp_ajax_mvs_import_demo_data` handler, `OverviewPage::ajax_import_demo_data()`.

### 5. Import Demo Data a second time — the refusal-as-success guard (REQUIRED)
- **Action**: with demo data already present, click "Import Demo Data" again (or POST
  the AJAX action directly with a valid nonce).
- **Expect**: the response is `wp_send_json_error()`, not `wp_send_json_success()` —
  Coding Rule 20. The button-side status text must show a real failure message (the
  seeder's own message, or the generic "Seeder did not return a response." if it
  terminated early), and MUST NOT silently no-op while implying success. Confirm via
  `read_network_requests` that the JSON envelope's `success` key is `false`.
- **On fail**: `includes/Admin/OverviewPage.php::ajax_import_demo_data()` (L619-636) —
  any refactor that swaps the `wp_send_json_error()` calls at L624/L633/L636 for
  `wp_send_json_success()` reintroduces the exact bug Rule 20 was written to catch.

### 6. Non-admin cannot trigger import via a crafted AJAX call
- **Action**: as the subscriber account, POST `action=mvs_import_demo_data` with a
  valid `_ajax_nonce` for that user.
- **Expect**: `wp_send_json_error( 'Permission denied.' )`, HTTP 200 with `success:false`
  in the body (WP AJAX convention) — not a 403 that lets a client mistake it for a
  network failure, and definitely not `success:true`.
- **On fail**: capability check in `ajax_import_demo_data()` (L619).

### 7. Delete Demo Data — confirm dialog gates the destructive call
- **Action**: click `#mvs-cleanup-demo-btn`; watch for the native JS confirm dialog
  before any network call fires; cancel it once to confirm zero requests were made,
  then click again and accept.
- **Expect**: confirm text "Delete all demo users and the media, albums, and
  collections they own? Your real user data will not be touched. This cannot be
  undone."; cancelling makes no server call (check `read_network_requests`); accepting
  shows "Deleting…" then removes demo users/media/albums/collections while leaving the
  admin's own real content untouched.
- **On fail**: `wp_ajax_mvs_cleanup_demo_data`, `OverviewPage::handle_cleanup_demo()`.

### 8. Delete Demo Data with nothing to delete — error styling, not a crash
- **Action**: click "Delete Demo Data" again immediately (or a second time in a row)
  once no demo data remains.
- **Expect**: response is `wp_send_json_error()` with message "No demo users found.
  Nothing to clean." rendered in the button's error/red status styling. This is a
  documented cosmetic quirk (Coding Rule 20 requires the honest error envelope; the
  catalog notes the red styling on a non-alarming message is acceptable, not a bug) —
  assert the error envelope is present, not that the styling is "wrong."
- **On fail**: `OverviewPage::handle_cleanup_demo()` (L646-661).

## Pass criteria

ALL of the following hold:

1. Stat tiles, Quick Links, Frontend Pages block and Recent Uploads render per step 1-2, with live (not stale) counts.
2. A capability-less role gets the full `wp_die()` page, not a redirect.
3. Import Demo Data succeeds once, seeding content and incrementing the Total Media tile.
4. A second Import Demo Data call returns `wp_send_json_error()` — never a success envelope for a refused request (Coding Rule 20).
5. A crafted AJAX import call from a non-admin is refused with `success:false`.
6. Delete Demo Data is gated by a real JS confirm; cancelling fires zero requests.
7. Deleting demo data with none present returns `wp_send_json_error()`, not a silent no-op.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Second import click does nothing, no error shown | Refusal returned via `wp_send_json_success()` (Rule 20 regression) | `includes/Admin/OverviewPage.php::ajax_import_demo_data()` |
| Total Media tile doesn't move after import | Stat query cached / wrong table | `includes/Admin/OverviewPage.php`, `Services/AdminAggregatesService.php` |
| Delete fires without confirm | JS confirm binding removed | `assets/js/admin/overview.js` (or equivalent enqueued script) |
| Non-admin AJAX import succeeds | Missing capability check in handler | `OverviewPage::ajax_import_demo_data()` |
| Capability-less visit redirects instead of `wp_die()` | Menu registration cap changed without a matching page-level guard | `OverviewPage::render_page()` / `add_menu_page()` |
