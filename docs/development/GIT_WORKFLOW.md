# WPMediaVerse Git Workflow

Conventions for branching, commits, pull requests, and version bumping across the WPMediaVerse Free and Pro repositories.

---

## Branch Strategy

| Branch | Purpose | Direct push |
|--------|---------|-------------|
| `main` | Stable release - tagged commits only | Locked |
| `develop` | Integration branch - all feature/fix branches merge here | Maintainers only |
| `feature/<name>` | New features | Author |
| `fix/<name>` | Bug fixes | Author |
| `release/<version>` | Release preparation (version bump, changelog, final QA) | Author |

**Rules**

- All work happens in a branch cut from `develop`.
- `main` receives merges only from `release/<version>` branches, via PR.
- Direct pushes to `main` are blocked by branch protection rules.
- Delete feature/fix branches after merge to keep the branch list clean.

---

## Commit Message Convention

**Format**

```
<type>(<scope>): <subject>
```

- `type` - what kind of change (see table below).
- `scope` - the module or layer affected (see table below).
- `subject` - imperative, present tense, ≤72 characters, no trailing period.

**Types**

| Type | Use for |
|------|---------|
| `feat` | A new feature or capability |
| `fix` | A bug fix |
| `refactor` | Code change that neither adds a feature nor fixes a bug |
| `docs` | Documentation only |
| `test` | Adding or updating tests |
| `build` | Build system, Grunt, Composer, npm changes |
| `chore` | Maintenance tasks (deps update, config, CI) |

**Scopes**

`admin`, `rest`, `social`, `bp`, `messaging`, `cli`, `migration`, `video`, `analytics`, `quota`, `security`

**Examples**

```
feat(social): add emoji reaction picker to lightbox
fix(rest): return 404 instead of 500 on missing media id
refactor(admin): extract moderation tab rendering into partial
test(social): add unit tests for FavoriteService toggle logic
chore(security): bump guzzlehttp/guzzle to 7.8.1
```

---

## PR Process

1. **Cut a branch** from `develop`: `git checkout -b feature/my-feature develop`
2. **Write code** following `docs/development/CODING_STANDARDS.md`. Max 500 lines per file, max 50 lines per method.
3. **Self-review** against `docs/security/SECURITY_CHECKLIST.md` before opening the PR. All 19 checks must pass.
4. **Run the local-CI gate**: `composer ci` (or `composer ci:no-journeys` / `composer ci:quick` for tight loops). The pre-push git hook runs it for you once you have run `composer install-hooks`.
5. **Run unit tests** locally: `./vendor/bin/phpunit`
6. **Open a PR** targeting `develop`. Title must follow the commit message convention above.
7. **Fill in the PR description** - include: what changed, why, how to test, and any migration notes.
8. **Request review** from at least one maintainer. Security-related changes require two reviewers.
9. **Address review comments** - push additional commits to the branch (do not force-push after review starts).
10. **Squash-merge** once approved and CI is green. The squashed commit message must follow the convention. Delete the branch after merge.

**CI checks that must pass before merge** (GitHub Actions, `.github/workflows/tests.yml`)

- `php-lint` (every file in `includes/`, `templates/`, `src/` plus `wpmediaverse.php`)
- `wpcs` (errors block, warnings pass)
- `phpstan` (static analysis, no new baseline entries)
- `phpunit` (PHP 8.1-8.4 x WP 6.7-6.9 matrix)
- `plugin-check` (WordPress Plugin Check)

The workflow runs on `main`/`master`, release branches, and version-named branches. It does not build assets - the Grunt build is a release-time step, not a merge gate.

---

## Version Bumping

WPMediaVerse follows [Semantic Versioning](https://semver.org/) (`MAJOR.MINOR.PATCH`).

| Change type | Version bump | Example |
|-------------|-------------|---------|
| Bug fixes, security patches, copy changes | Patch: `1.2.x` | `1.2.3 → 1.2.4` |
| New features, new hooks/filters, new REST endpoints (backward-compatible) | Minor: `1.x.0` | `1.2.4 → 1.3.0` |
| Breaking changes (removed hooks, changed DB schema without migration, changed REST response shape) | Major: `x.0.0` | `1.3.0 → 2.0.0` |

**Files to update on every version bump** (done in the `release/<version>` branch)

1. `wpmediaverse.php` - `Version:` header and `MVS_VERSION` constant.
2. `wpmediaverse-pro.php` (Pro repo) - same fields.
3. `readme.txt` - `Stable tag:` line and changelog entry. This is the only changelog either plugin keeps; there is no `CHANGELOG.md`, and release history deliberately does not go in `CLAUDE.md`.
4. `package.json` - `version` field.

**Tagging**

```bash
git tag -a v1.3.0 -m "Release v1.3.0"
git push origin v1.3.0
```

Packaging is run locally, not by CI: `npx grunt release` (which is `ci-check` - it verifies the GitHub Actions run is green - followed by `dist`) produces `dist/wpmediaverse-{version}.zip`. `bin/build-release.sh` is the fuller path and additionally refuses to package without a fresh green smoke pass in `qa/.last-smoke-pass*.json`.
