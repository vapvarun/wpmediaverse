---
journey: site-health-checks
plugin: wpmediaverse
priority: high
roles: [administrator]
covers: [MV-HLT-001, MV-HLT-002, MV-HLT-003, MV-HLT-004, MV-HLT-005, site-health-checks]
prerequisites:
  - "Site reachable at $SITE_URL over real HTTP (loopback requests must work, for MV-HLT-004)"
  - "Auto-login mu-plugin available (?autologin=1)"
  - "A disposable install (or a way to safely drop a table / chmod a directory / trash a page) for the fail-case walkthroughs — never do these on a live site"
  - "SSH/WP-CLI access to the filesystem"
estimated_runtime_minutes: 12
---

# Tools > Site Health shows 5 MediaVerse-specific rows, each with an honest pass/fail/inconclusive answer

**Why this journey exists**: `HealthCheckService` registers 5 custom tests on WordPress's own Site Health screen via `site_status_tests`. A site owner who has never opened `qa/` or the plugin's admin pages will still see these — they are the plugin's only proactive self-diagnosis surface. A test that always shows green regardless of the real state (or, just as bad, cries wolf when the state is actually fine) trains the owner to ignore Site Health entirely. This journey walks all 5, forcing each into its pass AND fail state on a disposable copy, and for MV-HLT-004 also its third, honest "inconclusive" state.

## Setup

- Site: `$SITE_URL`
- Admin: `?autologin=1`
- Location: wp-admin > Tools > Site Health > Status tab.
- A disposable install (same plugin version) where a table can be dropped, a directory chmod'd, and a page trashed without consequence.

## Steps

### 1. MediaVerse Database Tables (MV-HLT-001)
- **Action**: On the main site, load Site Health Status and locate "MediaVerse Database Tables". On the disposable install, drop one `mvs_*` table (e.g. `DROP TABLE wp_mvs_favorites;`) and reload Site Health there.
- **Expect**: main site shows green — "MediaVerse database tables are present." The disposable install shows red/critical — "MediaVerse database tables are missing." naming the specific missing table(s), with the remedy "Try deactivating and reactivating the plugin to recreate tables." The test runs against `Migrator::tables()` as its single source of truth, so a table added in a future release needs no edit to this test to be covered.

### 2. MediaVerse Upload Directory (MV-HLT-002)
- **Action**: On the main site, confirm the pass state. On the disposable install, `chmod 000` (or otherwise make non-writable) the `wp-content/uploads/wpmediaverse` directory, then reload Site Health.
- **Expect**: main site — "MediaVerse upload directory is writable." Disposable install — "MediaVerse upload directory is not writable" naming the actual path (`/wp-content/uploads/wpmediaverse` or equivalent), so a site owner or host can act without guessing. Confirm separately that a directory that exists but is empty (0 files) still passes — writability is being tested, not "has content."

### 3. MediaVerse Required Pages (MV-HLT-003)
- **Action**: On the main site, confirm the pass state. On the disposable install, trash the page behind `mvs_page_explore` OR `mvs_page_dashboard`, then reload Site Health.
- **Expect**: main site — "All required pages exist and are published." (reads MV-SET-034's two REQUIRED options: Explore and My Media only — Upload and Explore Documents are optional and surfaced on the Overview page-status block instead, per MV-ADM-001, not here). Disposable install — "MediaVerse pages are missing" with the same "deactivate and reactivate" remedy. Separately confirm the known gap: edit a required page's content to remove its shortcode WITHOUT changing its publish status, reload Site Health, and confirm it still shows green — this test checks only `post_status`, never whether the shortcode survives, so a shortcode-deleted page is a real breakage this specific test will NOT catch (documented limitation, not a new bug to file here).

### 4. MediaVerse Media Privacy (MV-HLT-004)
- **Action**: On the main site (Apache or LiteSpeed with working loopback requests), confirm the pass state. Then, if an nginx host is available, confirm its distinct fail state. Then simulate blocked loopback requests (firewall rule blocking the site from reaching itself) and confirm the third, distinct state.
- **Expect**: three states, never collapsed into two:
  1. **Pass** (Apache/IIS, working deny rules): "Stored media cannot be downloaded by guessing its address. Every request goes through a permission check."
  2. **Fail** (nginx, where the plugin's Apache-only deny rules do nothing): "Media files can be downloaded by anyone with the link" — with the literal nginx config block to paste, not just a generic warning.
  3. **Inconclusive** (loopback blocked): "Media privacy could not be confirmed" — this must NEVER be reported as a false pass ("safe") or a false fail ("leaking"); "could not be confirmed" is the only honest answer when the probe itself cannot run.
  Confirm the canary file `probe_public_access()` writes is never a dotfile — a dotfile canary would make itself invisible to some server configs and produce a false pass on exactly the hosts most likely to hide it.

### 5. MediaVerse Template Overrides (MV-HLT-005)
- **Action**: On a theme with no MediaVerse template overrides, confirm the pass state. Copy a current-version plugin template into the active theme's `wpmediaverse/` folder unmodified, confirm it still passes. Then revert that theme copy to an old version (strip its `@version` header, or set it to an older version string than the plugin's current file) and reload.
- **Expect**: no-override and matching-version cases both show green — "Your theme's MediaVerse templates are up to date" ("Your theme does not override any MediaVerse template, or every copy it has matches the current version."). The aged copy shows red — "Your theme has outdated copies of MediaVerse templates" naming the SPECIFIC stale file(s) by name (never a vague "some templates are outdated"), phrased either "your copy has no version (made before 2.6.0), current is %s" or "your copy is version %1$s, current is %2$s", with the remedy: copy the current file from the plugin's templates folder over the theme's copy, then re-apply the theme's changes. See `audit/journeys/admin/13-template-overrides.md` for the underlying override mechanism this test polices.

## Pass criteria

ALL hold:
1. All 5 tests show green on an unmodified, healthy install.
2. Each test's fail state is genuinely reachable (not permanently green regardless of state) and names the specific broken thing (table, path, page, file) rather than a generic warning.
3. Every fail message pairs with an actionable remedy string, matching the catalog's exact copy.
4. MV-HLT-004 produces all three distinct states (pass/fail/inconclusive) under their respective conditions, never collapsing two into one.
5. MV-HLT-001 covers a table added after this journey was written, without needing this journey edited (single-source-of-truth via `Migrator::tables()`).

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Tables test stays green with a table missing | Test doesn't actually query `SHOW TABLES` / hardcodes an old table list instead of reading `Migrator::tables()` | `includes/Services/HealthCheckService.php::test_tables()` |
| Upload directory test doesn't name the path | Path not interpolated into the fail label | `includes/Services/HealthCheckService.php::test_uploads()` |
| Pages test flags a page whose shortcode was deleted | Not a bug — documented limitation (post_status only) | N/A — confirm the journey step notes this correctly, don't "fix" the test |
| Media Privacy always reports pass, even on nginx | `probe_public_access()` doesn't detect the actual server / doesn't fetch the canary over real HTTP | `includes/Services/HealthCheckService.php::test_media_privacy()`, `probe_public_access()` |
| Media Privacy reports pass/fail instead of inconclusive when loopback is blocked | Missing distinct "could not confirm" branch on a failed loopback request | `includes/Services/HealthCheckService.php::probe_public_access()` |
| Template Overrides test never flags a genuinely stale copy | Version comparison inverted, or `mvs_template_roots` not walked for Pro's templates too | `includes/Services/HealthCheckService.php::test_template_overrides()` |
