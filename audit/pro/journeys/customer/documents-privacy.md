---
journey: documents-privacy
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber, anonymous]
covers: [MV-DOC-019, MV-DOC-020, MV-DOC-021, MV-DOC-022, documents-privacy, documents-space-privacy]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "One run with BuddyNext inactive, one with it active and a Space available"
estimated_runtime_minutes: 7
---

# private/members/public behave exactly as labelled; Space privacy needs BuddyNext and only means something on a Space drive

**Why this journey exists**: `wpmediaverse-pro/audit/journeys/admin/03-documents-settings-and-role-gate.md` §11 covers the default-privacy dropdown itself; this journey covers what each level actually DOES once a document carries it, and the BuddyNext-dependency edge that the settings journey doesn't reach.

## Setup

- Site: `$SITE_URL`; member `?autologin=<member>`.

## Steps

### 1. Default privacy applies to new uploads
- **Action**: set "New documents start as" to `members`; upload a document without specifying `privacy`.
- **Expect**: lands at `members`. Default when never touched is `private`.

### 2. `private` visibility
- **Action**: set a document to `private`; view as owner, as an unrelated member, and logged out.
- **Expect**: only owner (and admins/grantees) can view; others get the standard denied response.

### 3. `members` visibility
- **Action**: set to `members`; view as any signed-in member with document access, and logged out.
- **Expect**: any signed-in member with access can view; logged-out cannot.

### 4. `public` visibility
- **Action**: set to `public`; view logged out.
- **Expect**: a logged-out visitor can view.

### 5. The capability never gates a read
- **Action**: as a member WITHOUT `use_mvs_documents`, and as a logged-out visitor, open an already-`public` document.
- **Expect**: both can still open it — the capability only gates having-a-drive, never reading something already shared/public.

### 6. Space privacy without BuddyNext — refused, three surfaces
- **Action**: with BuddyNext NOT active: (a) PATCH a document's privacy to `space`; (b) attempt the same on a folder; (c) open the default-privacy dropdown on the settings screen.
- **Expect**: (a)/(b) 400, "Space privacy needs BuddyNext, which is not active on this site."; upload with `privacy=space` in this state silently falls back to the site default rather than failing the upload; (c) the dropdown simply does not offer `space` as an option.
- **On fail**: BuddyNext-presence check in the privacy validator.

### 7. A pre-existing `space` value survives BuddyNext's removal
- **Action**: with a document already holding `space` privacy from when BuddyNext was active, deactivate BuddyNext, then re-read the document's privacy without writing to it.
- **Expect**: the stored value is preserved; only NEW writes are refused.

### 8. Space privacy with BuddyNext active
- **Action**: activate BuddyNext; on a Space drive, set a document to `space` privacy.
- **Expect**: visible to that space's members via the bridge filter.

### 9. Space privacy on a personal drive resolves to owner-only
- **Action**: attempt to set `space` on a document that lives on a personal (user) drive, not a Space drive.
- **Expect**: resolves to the owner alone — this is why the picker hides `space` on personal drives; confirm the picker does hide it there.

### 10. An OPEN space's bridge answers "read" even to non-members, by design
- **Action**: on an OPEN space (not invite-only), open a `space`-privacy document as a non-member.
- **Expect**: readable — the alternative (refusing) would let any signed-in visitor open what should be a private file in a closed space, so this is deliberate for open spaces specifically.

## Pass criteria

1. Each of private/members/public behaves exactly as its label promises — no level is more or less restrictive than described.
2. The capability gates having-a-drive only, never a read of an already-visible document.
3. `space` privacy is refused with the BuddyNext-naming message on all three surfaces when BuddyNext is inactive, silently falls back on upload, and the dropdown omits it.
4. A pre-existing `space` value is preserved across BuddyNext's removal; only new writes are refused.
5. `space` privacy works via the bridge on a Space drive and resolves to owner-only on a personal drive.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| A capped-out member can't read an already-public document | capability check applied to a read path | document read permission callback |
| `space` PATCH succeeds without BuddyNext | BuddyNext-presence check missing | privacy validator |
| Settings dropdown still offers `space` without BuddyNext | dropdown options not conditioned on BuddyNext presence | Documents settings screen |
| Pre-existing `space` value errors after BuddyNext removed | privacy read path throws instead of falling back to `private` | privacy resolver |
