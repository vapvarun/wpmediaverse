# Local Testing & Pre-Push Checklist

Every change that touches PHP, composer, or the build pipeline must pass the checks in this file before it is pushed. The checks exist because PHPCS, PHPStan, and PHPUnit being green **does not** prove the plugin activates. This page covers the gap.

**This applies to both `wpmediaverse` (Free) and `wpmediaverse-pro` - Pro had a fatal-on-activation bug on 2026-04-15 that none of the existing checks caught. These steps are what would have caught it in five seconds.**

---

## 1. Pre-push smoke test (mandatory, ~30 seconds)

Run this on every branch before `git push`. It is the single check that catches a class the autoloader can't resolve, a missing `libs/` require, or a bad activation hook.

```bash
# From the WordPress install root (the directory containing wp-config.php):

rm -f wp-content/debug.log

wp plugin deactivate wpmediaverse-pro wpmediaverse
wp plugin activate wpmediaverse wpmediaverse-pro

# Must print zero lines:
grep -E "Fatal|Uncaught|deep-copy|myclabs|Class .* not found" wp-content/debug.log
```

Requires `WP_DEBUG_LOG=true` in `wp-config.php` for the log file to exist. If the grep prints anything, **do not push** - fix first.

If you don't have WP-CLI set up, deactivate and reactivate both plugins via the WordPress admin **Plugins** page. Then load at least:

- `wp-admin/admin.php?page=wpmediaverse` (Overview)
- `wp-admin/admin.php?page=mvs-settings` (Settings)
- `/explore-media/` (frontend)
- `/my-media/` (frontend, logged in)

Watch for any white-screen or error banner.

---

## 2. Fresh-clone fatal check (required before PR merge if the change touches `wpmediaverse.php`, `libs/`, or any class the autoloader references)

The smoke test in §1 runs against your already-populated working directory. It will not catch a class the plugin requires at boot but never committed - the shape of bug that caused Pro #9788342062 on 2026-04-15.

Run this once before merging:

```bash
cd /tmp
rm -rf mvs-verify
git clone -b <your-branch> <repo-url> mvs-verify
cd mvs-verify
php -l wpmediaverse.php
find includes/ templates/ src/ -name '*.php' -exec php -l {} +
```

Then symlink or copy that clone into a WordPress install and run the §1 activation smoke test against it. A clean clone has **no `vendor/` at all** (see §3), so anything that only boots with dev dependencies present is a fatal waiting to ship. **Do not merge** if activation errors.

---

## 3. Hard rules for `vendor/`, `libs/`, and composer

**The runtime never loads Composer.** This is the single most important fact about this plugin's boot path, and it is what the 2026-04-15 class of fatal was fixed by:

- `wpmediaverse.php` registers a **hand-written PSR-4 `spl_autoload_register`** (`WPMediaVerse\` → `includes/`). There is no `require 'vendor/autoload.php'`.
- Bundled runtime dependencies (Action Scheduler, the EDD SL SDK) are **committed under `libs/`** and `require_once`d directly from `wpmediaverse.php`. See `libs/README.md`.
- `/vendor/` is **gitignored** and excluded from the release ZIP (`/vendor` in `.distignore`). It holds dev and build tooling only - phpunit, phpstan, phpcs.

### Consequences

- **Never commit anything under `vendor/`.** It is ignored; if `git status` shows it, something is misconfigured. `composer install` repopulates it on any machine.
- **Don't run `composer install --no-dev` in a dev worktree.** Since `vendor/` no longer ships, there is nothing to gain: it just leaves you unable to run phpunit/phpcs/phpstan until you `composer install` again. (`Gruntfile.js` still defines `composer-prod` / `composer-restore` tasks from the old layout, but the `dist` chain - `clean:dist` → `build` → `copy:dist` → `compress:dist` → `dist-summary` - no longer calls them.)
- **A new runtime dependency goes in `libs/`, not `vendor/`.** If it is needed at runtime on a customer site it must be committed and required explicitly, because the release ZIP has no autoloader for `vendor/`.

---

## 4. Dist ZIP vs. git repo - the distinction that matters

The release ZIP shipped to customers is **not** the same thing as what's in the git repo.

| | Git repo | Dist ZIP (`dist/wpmediaverse-{version}.zip`) |
|---|---|---|
| `vendor/` | Present after `composer install`, gitignored, dev+build tooling | **Absent** - excluded by `.distignore` / the Gruntfile copy task |
| Runtime deps | `libs/` (committed) | `libs/` (shipped) |
| Autoloader | Hand-written in `wpmediaverse.php` | Same hand-written autoloader |
| `docs/`, `qa/`, `audit/`, `plan/`, `tests/`, `bin/` | Present | Excluded |
| Used by | Developers working on the plugin | End users installing from store.wbcomdesigns.com |
| Built by | `composer install` | `npx grunt dist` or `bin/build-release.sh` |

**The 2026-04-15 Pro fatal was a Composer-autoload problem**: a committed `autoload_static.php` referenced dev-dependency files that were never committed, so a fresh clone died on boot. Moving the runtime dependencies to `libs/` and dropping the Composer autoloader from the boot path removed that whole failure mode - the two build paths can no longer disagree about whether the ZIP contains an autoloader.

When someone reports a fatal, your first question is still: **are they running a git clone or an installed ZIP?** The fix path is different.

---

## 5. Release build verification

Before cutting a release tag, build a fresh ZIP and test it end-to-end:

```bash
# In the plugin directory:
npx grunt dist

# This produces dist/wpmediaverse-{version}.zip - extract it somewhere outside the
# plugin directory and install it as a clean copy in a second WordPress install,
# OR copy it to a staging site, then activate. Must:
# - Activate clean (no fatal, no debug.log errors)
# - Pass the §1 smoke test
# - Load every page in the §1 browser checklist
```

`grunt dist` does not touch Composer state - it cleans `dist/`, builds assets, copies the shipping file set (no `vendor/`, no `docs/`, no `tests/`, no `bin/`), and compresses. Your dev worktree is unchanged when it finishes.

---

## 6. Running full code-quality gates

The three existing checks (`phpcs`, `phpstan`, `phpunit`) stay mandatory. None of them replace the activation smoke test above - they verify code correctness, not feature correctness.

```bash
composer run phpcs     # WordPress coding standards (autofix with phpcbf)
composer run phpstan   # static analysis against phpstan-baseline.neon
./vendor/bin/phpunit   # unit tests
```

Rule of thumb: if you touched a file, run `phpcs` and `phpstan` on it. If you touched PHP at all, run the full PHPUnit suite.

**Note on PHPUnit and activation:** PHPUnit bootstraps the plugin through the WordPress test framework, which short-circuits normal activation, and it runs with `vendor/` fully populated - a state no customer install is ever in. Green PHPUnit output therefore proves nothing about whether the plugin activates from the shipped ZIP. This is why §1 exists.

---

## 7. Checklist summary

Copy this into your PR description:

```
- [ ] Pre-push smoke test passed (§1): deactivate/reactivate both plugins, debug.log clean
- [ ] Fresh-clone fatal check passed (§2): clone branch to /tmp, lint clean, activates with no `vendor/` present
- [ ] No `vendor/` changes staged - it is gitignored; runtime deps belong in `libs/` (§3)
- [ ] `composer run phpcs` clean (or existing baseline unchanged)
- [ ] `composer run phpstan` clean (or existing baseline unchanged)
- [ ] `./vendor/bin/phpunit` green
- [ ] Browser-verified every feature touched on real pages at 1440px and 390px
```
