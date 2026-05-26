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
2. **Write code** following `docs/CODING_STANDARDS.md`. Max 500 lines per file, max 50 lines per method.
3. **Self-review** against `docs/SECURITY_CHECKLIST.md` before opening the PR. All 19 checks must pass.
4. **Run static analysis** locally: `composer run phpcs && composer run phpstan`
5. **Run unit tests** locally: `./vendor/bin/phpunit`
6. **Open a PR** targeting `develop`. Title must follow the commit message convention above.
7. **Fill in the PR description** - include: what changed, why, how to test, and any migration notes.
8. **Request review** from at least one maintainer. Security-related changes require two reviewers.
9. **Address review comments** - push additional commits to the branch (do not force-push after review starts).
10. **Squash-merge** once approved and CI is green. The squashed commit message must follow the convention. Delete the branch after merge.

**CI checks that must pass before merge**

- WPCS (PHP coding standards)
- PHPStan (static analysis, no new baseline entries)
- PHPUnit (no regressions)
- Grunt build (assets compile without error)

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
3. `readme.txt` - `Stable tag:` line and changelog entry.
4. `CHANGELOG.md` - add a new version section.

**Tagging**

```bash
git tag -a v1.3.0 -m "Release v1.3.0"
git push origin v1.3.0
```

Tags on `main` trigger the CI release pipeline (`npx grunt release`), which produces the distribution ZIP.
