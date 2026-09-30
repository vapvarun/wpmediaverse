---
journey: documents-admin-screen-and-health
plugin: wpmediaverse-pro
priority: normal
roles: [administrator]
covers: [MV-DOC-042, MV-DOC-043, documents-admin-list, documents-site-health]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "Documents in various states: trashed, shared, various privacy"
estimated_runtime_minutes: 5
---

# Pro's admin panels feel native inside Free's screen; Site Health tells the truth about storage privacy

**Why this journey exists**: the admin document list/single-view shell is Free's, with Pro panels layered in — a seam that can drift into a second, competing UI if not careful. Separately, the Site Health check is the one place an owner learns whether their host is quietly serving document files over HTTP.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`.

## Steps

### 1. Admin document list shows Pro's extra row actions
- **Action**: `admin.php?page=mvs-documents`.
- **Expect**: Pro's extra row actions (folder path, sharing state) appear seamlessly inside Free's list shell, not as a second competing UI. No admin FOLDER list exists — this is a documented, intentional exception to the three-entry-points rule.

### 2. Trashed rows get the correct action set
- **Action**: view a trashed document's row.
- **Expect**: offers restore, not the write actions that don't apply to a trashed row.

### 3. Single view shows Pro's admin panels
- **Action**: open a document with `&view=single&id=<id>`.
- **Expect**: Pro's panels (permissions, folder, extraction status) render natively alongside Free's shell.

### 4. Site Health passes on a healthy install
- **Action**: Tools > Site Health > Status, locate the "Document storage is private" test.
- **Expect**: passes, confirming the storage directory denies direct HTTP access — verified via an actual loopback HTTP fetch of a canary file, not just a config assumption.

### 5. Site Health fails with an actionable label on a misconfigured host
- **Action**: (if reproducible) misconfigure the documents directory as web-accessible; re-run the test.
- **Expect**: fails with one of several SPECIFIC labels an admin without deep technical knowledge can act on (e.g. "documents can be downloaded by anyone with the link") — never a generic "storage misconfigured."

### 6. A host blocking loopback requests reports "unchecked," never a false pass
- **Action**: (if reproducible) block loopback HTTP requests at the host level; re-run the test.
- **Expect**: reports "unchecked" rather than falsely claiming protection.

### 7. Result is cached and only re-probed on demand
- **Action**: re-visit Site Health without changing anything.
- **Expect**: cached result, not re-probed on every page load; re-probes on demand/after a relevant settings change.

## Pass criteria

1. Pro's admin list/single-view panels feel native, with the trashed-row action set correctly restricted.
2. Site Health accurately reports pass/fail/unchecked for storage privacy, with specific and actionable failure labels, never a false pass on a blocked-loopback host.
3. The health-check result is cached, not re-probed every load.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Trashed row still offers write actions | row-action set not branching on trashed status | admin document list row-action renderer |
| Site Health falsely passes on a misconfigured host | canary-file loopback fetch not actually performed | `HealthCheckService`/Site Health test callback |
| Site Health falsely passes when loopback is blocked | failure to distinguish "blocked" from "passed" | Site Health test callback error handling |
