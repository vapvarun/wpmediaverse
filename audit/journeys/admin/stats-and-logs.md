---
journey: stats-and-logs
plugin: wpmediaverse
priority: normal
roles: [administrator, subscriber]
covers: [MV-ADM-017, MV-ADM-018, stats-page, log-viewer]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "Media with recorded views/reactions/comments/shares; AI usage this month; a triggered error condition (e.g. a bad AI key call) for a log entry"
estimated_runtime_minutes: 6
---

# Owner reads the Stats dashboard and exports CSV, then filters and clears the Log Viewer

**Why this journey exists**: Stats (`includes/Admin/StatsPage.php`) and the Log Viewer
(`includes/Admin/LogViewerPage.php`) are the two "what's actually happening on my site"
screens for an owner who has no direct DB access. The Stats CSV export must never
silently disagree with the on-screen table (both use
`AdminAggregatesService::top_media_by_views()`), and the Log Viewer's IP/User columns
are sensitive enough that this journey also confirms nothing else on the site exposes
that data.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Stats: `$SITE_URL/wp-admin/admin.php?page=mvs-stats`
- Log Viewer: reachable via MediaVerse (Tools/MediaVerse Logs); a legacy URL redirects
  here.

## Steps

### 1. Stats page — date range switching
- **Action**: open Stats; switch date range Today / This Week / This Month / All Time.
- **Expect**: Top Media by Views table updates per range (different top items or
  different view counts as the window narrows/widens).
- **On fail**: `includes/Admin/StatsPage.php` (~L221, `top_media_by_views( $since, 10 )`).

### 2. Empty state before any views exist
- **Action**: on a range with zero recorded views.
- **Expect**: "No Stats Yet" / "Views will appear once users start browsing media."

### 3. CSV export matches the on-screen table exactly
- **Action**: click "Export CSV" (nonce `mvs_export_stats_csv`); open the downloaded
  file.
- **Expect**: filename is date-stamped `wpmediaverse-stats-YYYY-MM-DD.csv`; columns ID,
  Title, Views, Reactions, Comments, Shares; the row set/order matches the on-screen
  Top Media table for the same range, because both call
  `AdminAggregatesService::top_media_by_views()` — they cannot disagree by
  construction. Confirm the row count is capped at 100 even if more media exists
  (`StatsPage.php` L75, `top_media_by_views( '', 100, ... )`) — this is intentional,
  not a bug, and the screen does NOT display any note near the Export CSV link stating
  the cap (also intentional per the catalog; do not fail the journey over the missing
  note, only confirm the cap itself is real).
- **On fail**: `StatsPage.php::export_csv()`, `Services/AdminAggregatesService.php`.

### 4. Export requires nonce + capability, fails closed
- **Action**: as the subscriber test account, request the export URL directly (with
  and without a valid nonce for their own session).
- **Expect**: refused in both cases (nonce check at `StatsPage.php` L59-63 AND the
  capability check) — never a silently-empty CSV returned to an unauthorized request.
- **On fail**: `StatsPage.php::maybe_export_csv()` capability/nonce ordering.

### 5. AI Usage panel
- **Action**: view the AI Usage panel (API Calls, Successful, Failed, Cost, Budget when
  a budget is set).
- **Expect**: numbers reflect this month's actual AI activity; when a budget is set, a
  percentage-used figure renders (`StatsPage.php` ~L349).

### 6. Log Viewer — filter, expand, paginate
- **Action**: open the Log Viewer; filter by Level; filter by Context; click "Reset" to
  clear both filters; expand a row's Details `<summary>`; page through results (50/page,
  `paginate_links()`).
- **Expect**: filters narrow the table correctly; Reset clears both; Details expands
  inline without navigating away.
- **On fail**: `includes/Admin/LogViewerPage.php`.

### 7. Empty states
- **Action**: view the Log Viewer with zero entries (fresh install), and again right
  after clearing all logs.
- **Expect**: "No Log Entries" / "No log entries found." pre-clear; "All logs have been
  cleared." immediately post-clear — these are two distinct empty-state messages for
  two distinct causes.

### 8. Clear All Logs is confirm-gated and scope-honest
- **Action**: apply a Level/Context filter first, THEN click "Clear All Logs"
  (`mvs_clear_logs_nonce`/action `mvs_clear_logs`).
- **Expect**: JS confirm "Are you sure you want to clear all logs?" fires before the
  request; accepting it clears ALL logs — not just the currently filtered subset —
  matching the button's stated scope exactly (a filtered view showing 3 rows must not
  leave the other 500 untouched).
- **On fail**: `LogViewerPage.php::handle_clear_logs()` — check it doesn't apply the
  active filter's WHERE clause to the DELETE.

### 9. IP/User columns are confined to this one capability-gated screen
- **Action**: confirm the Log Viewer's IP and User columns are visible; then confirm no
  REST route exposes the log table (`GET /wp-json/mvs/v1/...` — there is no logs
  endpoint at all).
- **Expect**: this sensitive data is visible ONLY on this `manage_options`-gated admin
  screen, never through the API or any other surface.
- **On fail**: any new REST controller that reads `mvs_error_log` — should not exist per
  the catalog; if found, it's a regression against documented behavior.

## Pass criteria

ALL of the following hold:

1. Stats date-range switching changes the Top Media table; the empty state shows before any views exist.
2. CSV export always matches the on-screen table for the same range, is capped at 100 rows, and is refused (never silently empty) for an unauthorized/nonce-less request.
3. AI Usage panel numbers are live and the budget percentage renders when a budget is set.
4. Log Viewer filter/reset/pagination/expand work; both empty-state messages ("no entries" vs "cleared") are distinct and correct.
5. Clear All Logs is gated by a JS confirm and clears ALL logs regardless of an active filter.
6. Log IP/User data is exposed only on this admin screen — no REST route reads the log table.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| CSV rows don't match the on-screen table | A second aggregation query drifted from `AdminAggregatesService` | `includes/Admin/StatsPage.php` |
| Export succeeds for an unauthorized request | Nonce or capability check missing/wrong order | `StatsPage.php::maybe_export_csv()` |
| Clear All Logs only deletes the filtered rows | Active filter's WHERE clause reused in the DELETE | `includes/Admin/LogViewerPage.php` |
| Clear fires without confirm | JS confirm binding removed | Log Viewer admin JS |
