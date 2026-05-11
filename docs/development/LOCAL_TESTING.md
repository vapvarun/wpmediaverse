# Local Testing & Pre-Push Checklist

Every change that touches PHP, composer, or the build pipeline must pass the checks in this file before it is pushed. The checks exist because PHPCS, PHPStan, and PHPUnit being green **does not** prove the plugin activates. This page covers the gap.

**This applies to both `wpmediaverse` (Free) and `wpmediaverse-pro` — Pro had a fatal-on-activation bug on 2026-04-15 that none of the existing checks caught. These steps are what would have caught it in five seconds.**

---

## 1. Pre-push smoke test (mandatory, ~30 seconds)

Run this on every branch before `git push`. It is the single check that catches a broken `vendor/autoload.php`, missing class, or bad activation hook.

```bash
# From the WordPress install root (the directory containing wp-config.php):

rm -f wp-content/debug.log

wp plugin deactivate wpmediaverse-pro wpmediaverse
wp plugin activate wpmediaverse wpmediaverse-pro

# Must print zero lines:
grep -E "Fatal|Uncaught|deep-copy|myclabs|Class .* not found" wp-content/debug.log
```

Requires `WP_DEBUG_LOG=true` in `wp-config.php` for the log file to exist. If the grep prints anything, **do not push** — fix first.

If you don't have WP-CLI set up, deactivate and reactivate both plugins via the WordPress admin **Plugins** page. Then load at least:

- `wp-admin/admin.php?page=wpmediaverse` (Overview)
- `wp-admin/admin.php?page=mvs-settings` (Settings)
- `/explore-media/` (frontend)
- `/my-media/` (frontend, logged in)

Watch for any white-screen or error banner.

---

## 2. Fresh-clone fatal check (required before PR merge if the change touches `vendor/`, `composer.json`, `wpmediaverse.php`, or any class the autoloader references)

The smoke test in §1 runs against your already-populated working directory. It will not catch a committed `autoload_static.php` that points to files that do not exist in the repo — the exact bug that caused Pro #9788342062 on 2026-04-15.

Run this once before merging:

```bash
cd /tmp
rm -rf mvs-verify
git clone -b <your-branch> <repo-url> mvs-verify
cd mvs-verify
php -r 'require "vendor/autoload.php"; echo "autoload OK\n";'
```

If it prints anything other than `autoload OK`, you have shipped a broken autoload. **Do not merge.**

---

## 3. Hard rules for `vendor/` and composer

Follow these or you will reintroduce the 2026-04-15 fatal.

### Never run these in a dev worktree
- `composer install --no-dev` — deletes dev-dep files from `vendor/` but leaves the committed autoload intact, instantly breaking your own checkout.
- `composer dump-autoload --no-dev` — strips dev-dep class refs from `autoload_static.php`. Safe in isolation, but if you then accidentally commit the regenerated file without also rebuilding `vendor/`, you ship a fatal.

Both commands are release-time operations. The `Gruntfile.js` `dist` task handles them automatically inside a `composer-prod` → `compress:dist` → `composer-restore` sequence that never leaves your worktree in an inconsistent state.

### Never `git add vendor/` by hand
Vendor changes only flow through the release build. If `git status` shows modified files under `vendor/` after a normal workflow, something is wrong — investigate before staging. Common causes:

- Ran `composer update` — that is fine locally but the resulting autoload changes must not be committed outside a release branch
- Ran `composer install --no-dev` by mistake — run plain `composer install` to restore
- Ran `composer dump-autoload --no-dev` — run plain `composer dump-autoload` to restore

### Legitimate vendor changes go through the Gruntfile
When you bump a real prod dependency in `composer.json`:

1. `composer update <vendor/package>` (keeps dev deps installed)
2. Run the smoke test in §1
3. Commit `composer.json`, `composer.lock`, and only the specific `vendor/<new-package>/` subtree
4. Do **not** stage unrelated changes under `vendor/composer/` — if composer rewrote autoload files because of your dep bump, that's fine, but verify those files still reference files that exist in the repo before staging them

---

## 4. Dist ZIP vs. git repo — the distinction that matters

The release ZIP shipped to customers is **not** the same thing as what's in the git repo. They come from different composer states and a bug in one does not necessarily imply a bug in the other.

| | Git repo | Dist ZIP (`dist/wpmediaverse-{version}.zip`) |
|---|---|---|
| Composer state | `composer install` (dev + prod deps) | `composer install --no-dev` (prod only) |
| `vendor/` size | ~15 MB (includes phpunit, phpstan, phpcs, sebastian, etc.) | ~1 MB (edd-sl-sdk + action-scheduler only) |
| `vendor/composer/autoload_files.php` | Generated (references 5 dev-dep bootstraps) | Not generated (no dev files to load) |
| Used by | Developers working on the plugin | End users installing from store.wbcomdesigns.com |
| Built by | `composer install` | `npx grunt dist` |

**The 2026-04-15 Pro fatal was only ever in the git repo — the released `1.1.1` ZIP was fine.** A developer pulling the repo for local work hit the fatal because the committed autoload referenced dev files that were never committed. End users never saw it because the ZIP is built with `--no-dev` and does not generate `autoload_files.php` at all.

When someone reports a fatal, your first question is: **are they running a git clone or an installed ZIP?** The fix path is different.

---

## 5. Release build verification

Before cutting a release tag, build a fresh ZIP and test it end-to-end:

```bash
# In the plugin directory:
npx grunt dist

# This produces dist/wpmediaverse-{version}.zip — extract it somewhere outside the
# plugin directory and install it as a clean copy in a second WordPress install,
# OR copy it to a staging site, then activate. Must:
# - Activate clean (no fatal, no debug.log errors)
# - Pass the §1 smoke test
# - Load every page in the §1 browser checklist
```

The Gruntfile wraps the `--no-dev` composer run in a `composer-restore` step so your worktree comes back to full dev state after `grunt dist` finishes. If that restore fails for any reason, you are mid-release with a broken worktree — run `composer install` manually to recover.

---

## 6. Running full code-quality gates

The three existing checks (`phpcs`, `phpstan`, `phpunit`) stay mandatory. None of them replace the activation smoke test above — they verify code correctness, not feature correctness.

```bash
composer run phpcs     # WordPress coding standards (autofix with phpcbf)
composer run phpstan   # static analysis against phpstan-baseline.neon
./vendor/bin/phpunit   # unit tests
```

Rule of thumb: if you touched a file, run `phpcs` and `phpstan` on it. If you touched PHP at all, run the full PHPUnit suite.

**Note on PHPUnit and activation:** PHPUnit bootstraps the plugin through the WordPress test framework, which short-circuits normal activation. A plugin with a broken `vendor/autoload.php` can still produce green PHPUnit output because PHPUnit loads its own polyfills before the plugin autoload runs. This is why §1 exists.

---

## 7. Checklist summary

Copy this into your PR description:

```
- [ ] Pre-push smoke test passed (§1): deactivate/reactivate both plugins, debug.log clean
- [ ] Fresh-clone fatal check passed (§2): clone branch to /tmp, `php -r 'require vendor/autoload.php'` OK
- [ ] Did not stage any vendor/ changes outside a release commit (§3)
- [ ] `composer run phpcs` clean (or existing baseline unchanged)
- [ ] `composer run phpstan` clean (or existing baseline unchanged)
- [ ] `./vendor/bin/phpunit` green
- [ ] Browser-verified every feature touched on real pages at 1440px and 390px
```
