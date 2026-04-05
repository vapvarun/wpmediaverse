# WPMediaVerse v1.2.0 Release Checklist

## Pre-Release: Branch Cleanup

### Free Plugin (`wpmediaverse`)
- [ ] Merge `refactor/settings-split` branch into `main`
  ```bash
  git checkout main
  git merge refactor/settings-split
  git branch -d refactor/settings-split
  ```
- [ ] Verify clean merge — no conflicts
- [ ] Confirm all commits are on `main`

### Pro Plugin (`wpmediaverse-pro`)
- [ ] Already on `main` — no merge needed

---

## Pre-Release: Code Quality

### Free Plugin
- [ ] Run WPCS: `composer run phpcs` — fix any violations
- [ ] Run PHPStan: `composer run phpstan` — regenerate baseline if needed
- [ ] PHP Lint all files: `find includes/ -name "*.php" -exec php -l {} \;`
- [ ] Verify zero stale references:
  - `grep -r "MediaMeta" includes/ templates/ src/ --include="*.php"` → zero
  - `grep -r "BuddyPressIntegration" includes/ --include="*.php"` → zero
  - `grep -r "Admin\\\\SettingsPage[^\\\\]" includes/ --include="*.php"` → zero

### Pro Plugin
- [ ] Run WPCS: `composer run phpcs`
- [ ] Run PHPStan: `composer run phpstan`
- [ ] PHP Lint: `find includes/ -name "*.php" -exec php -l {} \;`
- [ ] Verify zero stale references:
  - `grep -r "MediaMeta" includes/ --include="*.php"` → zero

---

## Pre-Release: Version Bump

### Free Plugin — Update version to 1.2.0 in:
- [ ] `wpmediaverse.php` — Plugin header `Version: 1.2.0`
- [ ] `wpmediaverse.php` — `define('MVS_VERSION', '1.2.0')`
- [ ] `readme.txt` — `Stable tag: 1.2.0`
- [ ] `readme.txt` — Add changelog entry
- [ ] `package.json` — `"version": "1.2.0"`

### Pro Plugin — Update version to 1.2.0 in:
- [ ] `wpmediaverse-pro.php` — Plugin header `Version: 1.2.0`
- [ ] `wpmediaverse-pro.php` — `define('MVS_PRO_VERSION', '1.2.0')`
- [ ] `readme.txt` — `Stable tag: 1.2.0`
- [ ] `package.json` — `"version": "1.2.0"`

---

## Pre-Release: Build

### Free Plugin
- [ ] Build assets: `npx grunt dist`
- [ ] Verify dist/ ZIP is created
- [ ] Verify dist/ excludes dev files (node_modules, tests, docs, .git)

### Pro Plugin
- [ ] Build assets: `npx grunt dist`
- [ ] Verify dist/ ZIP is created

---

## Pre-Release: Changelog

### Free Plugin — v1.2.0 Changelog
```
= 1.2.0 =
* Refactor: Extracted MediaRepository as central data access layer (29 methods, replaces scattered $wpdb queries)
* Refactor: Split BuddyPressIntegration (2,811 lines) into 8 focused classes under Integrations/BuddyPress/
* Refactor: Split SettingsPage (2,401 lines) into 5 focused classes under Admin/Settings/
* Fix: Comments now display newest first
* Fix: Restored Import Migration submenu visibility
* Docs: Added CLAUDE.md, ARCHITECTURE.md, CODING_STANDARDS.md, CONTRIBUTING.md, REFACTORING_ROADMAP.md, EXTENSION_GUIDE.md, SECURITY_CHECKLIST.md, GIT_WORKFLOW.md
```

### Pro Plugin — v1.2.0 Changelog
```
= 1.2.0 =
* Refactor: Migrated all MediaMeta references to MediaRepository (25 files)
* Docs: Added CLAUDE.md with extension patterns, module map, and boundaries
* Docs: Added ARCHITECTURE.md with Pro lifecycle, schema, REST map, hooks, competition system
```

---

## Testing: Backend (Admin)

### Free Plugin
- [ ] Overview page — stats cards render (media count, albums, views, storage)
- [ ] All Media page — list loads, filters work, pagination works
- [ ] Settings > General — all fields render, save works
- [ ] Settings > Display — fields render, save works
- [ ] Settings > AI & Moderation — fields render, save works
- [ ] Settings > Permissions — role/capability matrix renders, save works
- [ ] Settings > Webhooks — webhook field renders
- [ ] Stats page — overview tab + video analytics tab render
- [ ] Moderation page — queue renders, user reports tab renders
- [ ] Logs page — log entries render

### Pro Plugin
- [ ] Import Migration page — 3 platform cards render (rtMedia, MediaPress, BuddyBoss)
- [ ] Competitions dashboard — stat cards render
- [ ] Quota & Credits page — renders
- [ ] Theme Library page — renders

---

## Testing: Frontend

- [ ] Explore page — media grid loads, tag filters work, search works
- [ ] Single media page — image/video renders, reactions load, comments load
- [ ] My Media page — tabs render, upload dropzone works, media grid loads
- [ ] Albums — album grid renders, single album view works
- [ ] Collections — collection page renders

---

## Testing: BuddyPress Integration

- [ ] User profile > Media tab — media grid + upload button render
- [ ] User profile > Albums sub-tab — album grid renders
- [ ] Group > Media tab — group media renders (if groups active)
- [ ] Upload media — BP activity created in activity stream
- [ ] React to media — BP notification sent to media owner
- [ ] Comment on media — BP notification sent to media owner
- [ ] Activity stream — media thumbnails display correctly

---

## Testing: Pro Features

- [ ] Upload quota enforcement — respects package limits
- [ ] Storage driver — S3/BunnyCDN settings render (if configured)
- [ ] Video transcoding — transcode controls render
- [ ] Captions — caption controls render
- [ ] Competitions — challenges/battles/tournaments create and display

---

## Post-Release

- [ ] Tag release: `git tag v1.2.0 && git push origin v1.2.0`
- [ ] Update CLAUDE.md version number in both plugins
- [ ] Update CLAUDE.md "Recent Changes" table
- [ ] Update docs/REFACTORING_ROADMAP.md — mark P1 items as DONE
