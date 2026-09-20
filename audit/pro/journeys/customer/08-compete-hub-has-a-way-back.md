---
journey: compete-hub-has-a-way-back
plugin: wpmediaverse-pro
priority: normal
roles: [member]
covers: [compete-hub, dashboard-navigation, 10299415691]
prerequisites:
  - "Site reachable at $SITE_URL"
  - "At least one competition feature enabled (mvs_challenges_enabled, mvs_battles_enabled or mvs_tournaments_enabled = 1), so /compete/ does not 404"
  - "A theme whose primary menu does NOT contain a My Media item — the defect is invisible on a theme that happens to have one"
estimated_runtime_minutes: 3
---

# A member who walks into Compete can walk back out

**Why this journey exists**: the dashboard rail declares Compete as an off-site
section, so clicking it is a real navigation to `/compete/` rather than a panel
swap. Everything the hub then offers — View Battle, Enter Challenge, View
Entries, Register, View Bracket, Challenge Someone — goes *deeper* into Compete.
Until 2.4.2 the hub emitted no breadcrumb and no back link, so the only route
back to the member's own library was the site's navigation menu. The plugin does
not own that menu (Coding Rule 17 forbids injecting into it), and at phone width
most themes collapse it behind a hamburger. On a theme without a My Media item
the member was stranded with the browser back button. Basecamp 10299415691.

The general rule this pins: **anything that navigates away from the dashboard
pairs with a discoverable way back.** The rail head's "View profile" link is the
existing precedent.

## Setup

- Member: `$SITE_URL/?autologin=<member>`
- Dashboard page id lives in option `mvs_page_dashboard`; when unset the hub
  falls back to `/my-media/`.

## Steps

### 1. Enter the hub the way a member does
- **Action**: as the member, open `/my-media/`, then click **Compete** in the rail.
- **Expect**: a full-page navigation lands on `/compete/`, titled "Compete".

### 2. The hub offers a route back, in its own content
- **Action**: look inside the hub body — not the theme header.
- **Expect**: a visible link reading **Back to My Media** with class
  `.mvs-compete-hub__back`, rendered above the `<h1>Compete</h1>`.
- **On fail**: `templates/compete-hub-body.php` (header block).

### 3. It goes where it says
- **Action**: click it.
- **Expect**: the URL is the permalink of the page in `mvs_page_dashboard`, or
  `/my-media/` when that option is unset; the dashboard rail renders.

### 4. It survives the viewport the defect was worst at
- **Action**: repeat steps 1-3 at 390px wide.
- **Expect**: the back link is visible without opening the hamburger, and its hit
  area is at least the plugin's own `--mvs-touch-min` token (read it at runtime;
  do not retype the number — Coding Rule 22's corollary).

### 5. It does not depend on the theme's menu
- **Action**: with the site's primary menu containing no My Media item, repeat step 2.
- **Expect**: unchanged. The link is the plugin's, not the theme's.

## Pass criteria

1. `/compete/` contains a link to the dashboard inside the hub content.
2. The link's target honours `mvs_page_dashboard`, falling back to `/my-media/`.
3. It is visible and touch-sized at 390px.
4. It renders on a theme whose menu has no My Media entry.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| No back link at any width | header block not rendered | `templates/compete-hub-body.php` |
| Link present but unstyled / tiny tap target | stylesheet not enqueued or rule dropped | `assets/css/gamification.css` (`.mvs-compete-hub__back`) |
| Link goes to `/` or 404s | dashboard page option unset and fallback wrong | `templates/compete-hub-body.php` (`mvs_page_dashboard` resolution) |
| Link only visible on one theme | back link came from the theme menu, not the plugin | site's Appearance → Menus |
