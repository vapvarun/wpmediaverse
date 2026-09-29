---
journey: documents-maintenance
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-DOC-033, MV-DOC-034, MV-DOC-035, MV-DOC-036, documents-orphans, documents-allowed-types, documents-max-size]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "At least one truly orphaned document file on disk, untouched over 1 hour, with no index/meta row referencing it"
  - "WP-CLI available"
estimated_runtime_minutes: 8
---

# Orphan reclaim never touches a fresh file; allowed-types and max-size settings never advertise more than the server will actually accept

**Why this journey exists**: `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` §9-§10 prove the settings fields exist and change behaviour; this journey proves the orphan-reclaim maintenance tool (settings-screen card AND WP-CLI, which have no REST route by design) and goes one level deeper on the allowed-types absent-vs-empty distinction and the max-size server clamp.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.

## Steps

### 1. Orphan check (dry run) reports without deleting
- **Action**: Settings > Documents > "Orphaned files" card > "Check for orphaned files."
- **Expect**: reports a count + sample; deletes nothing. Delete button only appears once a check found orphans — never available speculatively.

### 2. Delete re-checks orphan status at the moment of deletion
- **Action**: click Delete.
- **Expect**: POST-only; success message "Deleted N orphaned document file(s) (<size>)."; a partial failure names the count that couldn't be deleted plus "More remain: check again to continue." if capped.

### 3. A file created less than an hour ago is never reclaimed
- **Action**: create a fresh orphan-shaped file (no index row) less than an hour old; run the check.
- **Expect**: not reported/deleted — protects a file mid-upload or mid-scan.

### 4. WP-CLI reclaim: dry-run, scoped, and confirmed
- **Action**: `wp mvs-pro documents reclaim-orphans --dry-run`; then `--scope=<segment>/2025/03`; then `--yes` to actually delete.
- **Expect**: always reports before asking; a `--scope` resolving OUTSIDE the documents tree is refused, not silently ignored; output never claims a deletion that didn't happen or vice versa.

### 5. `--batch` override is respected and still bounded
- **Action**: run with a custom `--batch=<n>`.
- **Expect**: respected, still bounded per DB round trip.

### 6. Allowed-types: absent, restricted, and deliberately empty
- **Action**: (a) leave "Allowed types" untouched — upload every supported type, all succeed. (b) restrict to one type, attempt an excluded type. (c) save with every checkbox unchecked (empty array), attempt any upload.
- **Expect**: (a) absent reads as "every type allowed." (b) only the selected type succeeds. (c) an empty array is a distinct, deliberately saveable "accept nothing" state — every upload refused, and this must NOT be read as "not configured." The screen must make clear this is deliberate, not an accidentally-cleared field.
- **On fail**: `mvs_pro_documents_allowed_types` reader confusing absent with empty.

### 7. Content-based type checking survives a renamed extension
- **Action**: rename a disallowed file to a fake allowed extension; attempt upload.
- **Expect**: still caught by content-based type checks, independent of the allowed-types setting.

### 8. Max-size clamps to the real server ceiling
- **Action**: set the configured max size ABOVE the server's real upload limit; check the app config's advertised max size; attempt an upload sized between the server limit and the configured (higher) value.
- **Expect**: advertised and enforced limit is clamped to the server's real ceiling — never advertises a number the server would reject. Leaving the option at 0 means "follow the server limit" exactly.
- **On fail**: max-size setting reader not clamping against `upload_max_filesize`/`post_max_size`.

## Pass criteria

1. Orphan check/delete on the settings screen and via WP-CLI never reclaim a file under 1 hour old, and Delete always re-verifies orphan status at deletion time.
2. WP-CLI `--dry-run`/`--scope`/`--batch`/`--yes` behave exactly as documented, with truthful output.
3. Allowed-types correctly distinguishes absent (all allowed) from an explicit empty array (nothing allowed), and content-based checks still catch a renamed extension.
4. Max-size never advertises or enforces a number above the server's real ceiling.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A file under 1 hour old gets reclaimed | age guard missing/wrong comparison | orphan-reclaim age check |
| CLI reports a deletion count that doesn't match reality | `--dry-run` short-circuit not gating the actual unlink call | `DocumentOrphansPanel.php` / CLI reclaim command |
| Empty allowed-types array reads as "not configured" (allows everything) | absent-vs-empty not distinguished in the option reader | Documents settings allowed-types resolver |
| Upload succeeds above the real server limit | max-size setting not clamped against server ceiling | Documents settings max-size resolver / `/app/config` |
