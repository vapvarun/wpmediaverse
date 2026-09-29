---
journey: documents-gdpr-and-profile
plugin: wpmediaverse-pro
priority: normal
roles: [administrator, subscriber, anonymous]
covers: [MV-DOC-040, MV-DOC-041, documents-gdpr, documents-profile-tab]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "A member with at least one document at each of private/members/public privacy"
  - "BuddyPress active for the profile-tab step"
estimated_runtime_minutes: 6
---

# Documents are exported/erased as media rows, and a profile's Documents tab never disagrees with its own count

**Why this journey exists**: no Pro-specific exporter/eraser exists for documents by design — they ride Free's "Media Items" export group. A count-vs-listing mismatch on the profile tab, or a document escaping/omitted from GDPR tooling, are the two failure modes this checks.

## Setup

- Site: `$SITE_URL`; admin `?autologin=admin`; member `?autologin=<member>` with documents at each privacy level.

## Steps

### 1. Export includes the document, unfiltered by privacy
- **Action**: Tools > Export Personal Data for the member's email.
- **Expect**: the document's title/description/etc. appear in the "Media Items" export group — no separate "Documents" label exists, and this is by design, not a bug. The export discloses everything the person authored regardless of visibility.

### 2. Erase removes the row and the physical file
- **Action**: Tools > Erase Personal Data for the same member.
- **Expect**: the document row is removed through the same media-wide erase path; the physical file on disk is also removed, not just the database row.

### 3. Profile Documents sub-tab, viewed as the owner
- **Action**: BuddyPress member profile, "Documents" sub-tab, viewed as the owner.
- **Expect**: sees all own documents regardless of privacy; the displayed count matches the actual rendered list exactly.

### 4. Profile Documents sub-tab, viewed as another member
- **Action**: view the same profile as an unrelated member.
- **Expect**: sees only what's shared/members/public — never the private ones; count and list still agree.

### 5. Profile Documents sub-tab, viewed logged out
- **Action**: view the same profile logged out.
- **Expect**: sees only public documents; count and list still agree.

### 6. Master toggle off hides the tab entirely
- **Action**: turn `mvs_pro_documents_enabled` off; reload any of the three profile views above.
- **Expect**: the Documents sub-tab is absent, not present-but-empty.

## Pass criteria

1. Export/erase both operate on documents through the shared "Media Items" media path, with no distinct Documents label — export is unfiltered by privacy, erase removes the on-disk file too.
2. The profile tab's count and its rendered list never disagree, for any of owner/other-member/logged-out.
3. The master toggle removes the tab entirely.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Document survives an erase request | document rows not covered by the media-wide erase query | privacy erase map for `mvs_media_index` |
| Physical file remains after erase | erase path doesn't hook the file-orphan cleanup used by permanent delete | erase-triggered file cleanup |
| Profile tab count disagrees with visible rows | count query and listing query use different privacy filters | BuddyPress profile Documents tab renderer |
