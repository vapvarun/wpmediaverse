---
journey: documents-capabilities
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber]
covers: [MV-DOC-037, MV-DOC-038, MV-DOC-039, documents-capability-override]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "A subscriber-role member"
estimated_runtime_minutes: 6
---

# Two screens, one capability — and a developer filter that wins over both

**Why this journey exists**: `use_mvs_documents` is a real capability, not an option, and it's editable from two different screens (Permissions matrix and Documents settings) that must never drift apart. This journey proves both write paths land on the identical state, and that the documented per-user override filter resolves last, after both.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; subscriber `?autologin=<subscriber>`.

## Steps

### 1. Uncheck via the Permissions matrix
- **Action**: `admin.php?page=mvs-settings` Permissions tab, uncheck "Use Documents" for Subscriber, save.
- **Expect**: role loses the capability; that member now gets 403 from every document route and the tab is hidden.

### 2. The Documents settings screen reflects the same state
- **Action**: open `admin.php?page=mvs-settings-documents` "Who can use documents."
- **Expect**: Subscriber shows unticked here too — identical end-state, no drift between the two screens.

### 3. Change via Documents settings instead, Permissions tab reflects it
- **Action**: re-tick Subscriber on the Documents settings screen; recheck the Permissions tab.
- **Expect**: the Permissions tab now shows Subscriber ticked — this option is transport-only, the real state lives in the capability (`mvs_pro_documents_use_roles`).

### 4. Existing documents' reads by others are unaffected by revocation
- **Action**: with Subscriber's capability revoked, confirm other members can still read that subscriber's already-shared/public documents.
- **Expect**: unaffected — the capability only blocks the revoked member's own drive use going forward, never other people's reads of their content.

### 5. Per-user capability override filter wins over the role grant
- **Action**: grant the role capability normally; install a filter forcing the capability answer to `false` for one specific user id; that user attempts the drive.
- **Expect**: filter wins — that user is refused even though their role has the capability. Confirm the reverse also holds (filter forcing `true` for a user whose role lacks it).
- **On fail**: capability resolution order not putting the filter last.

### 6. Resolution order across all document settings is option, then filter
- **Action**: cross-check the same principle against another document setting (e.g. default privacy) with a filter installed.
- **Expect**: option first, filter last, consistently.

## Pass criteria

1. The Permissions matrix and Documents settings screen always show identical state for "Use Documents," from either write path.
2. A capability revocation blocks only that member's own drive use, never others' reads of already-visible content.
3. A per-user filter override always wins over the role-level grant, in both directions.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Permissions tab and Documents settings disagree | one of them writes an option instead of the shared capability function | `ProSettings::render_document_roles_field()` |
| Filter override ignored | capability check short-circuits before the filter runs | document capability resolver |
| Revoked member's public document becomes unreadable to others | capability gate applied to a read path instead of drive-access only | document read permission callback |
