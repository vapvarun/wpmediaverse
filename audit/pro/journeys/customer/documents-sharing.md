---
journey: documents-sharing
plugin: wpmediaverse-pro
priority: high
roles: [administrator, subscriber, anonymous]
covers: [MV-DOC-023, MV-DOC-024, MV-DOC-025, MV-DOC-026, MV-DOC-027, documents-sharing, documents-anon-links]
prerequisites:
  - "Both plugins active; mvs_pro_documents_enabled on"
  - "Two members (owner + grantee); mvs_pro_documents_anon_links toggled on for the anon-link steps"
estimated_runtime_minutes: 8
---

# Sharing with a person always works; sharing with a role never does; revoke survives an unlicensed site; anon links re-check on every redemption

**Why this journey exists**: `wpmediaverse-pro/admin/03-documents-settings-and-role-gate.md` §12 covers minting and redeeming an anon link under the toggle; this journey covers the person-to-person share lifecycle (create, revoke, the removed role-share path) plus the toggle-flip-closes-an-already-issued-link case that §12 doesn't reach.

## Setup

- Site: `$SITE_URL`; owner `?autologin=<owner>`, grantee `?autologin=<grantee>`.

## Steps

### 1. Share with a named member
- **Action**: `POST /wp-json/mvs-pro/v1/documents/{id}/permissions` with `grantee_type=user`, a resolvable member, and a permission level (view/comment/edit).
- **Expect**: grant created; "Shared. They can reach it from 'Shared with me'."; grantee sees it at `GET /wp-json/mvs-pro/v1/me/shared`.

### 2. Share refusals: unknown member, self-share, empty field
- **Action**: attempt sharing with an unresolvable name; with the document's own owner; with an empty share field.
- **Expect**: "unknown member" specific message; "They already own this document."; "Type the member you want to share with." — each distinct, never a generic failure.

### 3. Revoking a share works even on an unlicensed site
- **Action**: deactivate the Pro licence; as the owner, `DELETE /wp-json/mvs-pro/v1/permissions/{grant_id}`.
- **Expect**: succeeds — revoke is the one write exempt from the licence gate. "Access withdrawn." The "Who has access" panel's Remove control stays active even when every other write is disabled.
- **On fail**: revoke route missing its licence-exemption entry.

### 4. Revoking an already-gone grant
- **Action**: revoke the same grant a second time.
- **Expect**: "That share no longer exists.", not an error trap.

### 5. Sharing with a role is refused unconditionally, even for admins
- **Action**: as admin, attempt `grantee_type=role`.
- **Expect**: 403, "Sharing with a whole role is no longer supported. Share with a person, or set the document's privacy." No exemption for anyone. The share panel's role option must be removed from the UI entirely, not merely disabled.

### 6. A legacy pre-removal role grant still works
- **Action**: (if a legacy role-grant fixture exists) confirm a document with an old role grant still opens for members of that role, even though creating a new one is blocked.
- **Expect**: legacy grants are NOT retroactively revoked; "Who has access" still shows them correctly.

### 7. Mint a permission share link while the anon-link setting is ON
- **Action**: with `mvs_pro_documents_anon_links` on, `POST /documents/{id}/permissions/link` at a chosen permission level.
- **Expect**: a token-bearing URL opens the document at that level without signing in.

### 8. Link creation is refused, and hidden, when the setting is OFF
- **Action**: turn `mvs_pro_documents_anon_links` off; attempt to create a link.
- **Expect**: "Anonymous links are off on this site." The "create link" control must be hidden entirely in this state, not offered and then refused.

### 9. An already-issued link closes the moment the setting flips off
- **Action**: with the setting ON, mint a link and open it logged out — confirm it works. Turn the setting OFF site-wide. Reopen the SAME already-issued link logged out.
- **Expect**: step 1 succeeds; step 3 is refused — re-checked on every redemption, not only at minting. The grant row itself stays intact, so turning the setting back ON restores the link without re-sharing.
- **On fail**: link redemption re-checks the site-wide toggle every time, per the design in `security/08-anonymous-document-reads-follow-the-owner-switch.md`.

### 10. A closed link shows a clean state, and redemption fails closed under rate limiting
- **Action**: with the setting OFF, open a closed link. Then, with the setting ON, hammer a valid link past its rate limit (30/min by IP).
- **Expect**: a closed link shows an honest "this link is no longer available" state, never a broken page. Hammering locks the link out rather than leaving it open under ambiguity.

## Pass criteria

1. Named-member sharing succeeds and each refusal case shows its own specific message.
2. Revoke works unlicensed and is idempotent against an already-gone grant.
3. Role sharing is refused unconditionally; legacy role grants keep working; the UI never offers the removed control.
4. Anon links are refused and hidden when off, mint and open correctly when on, and an already-issued link is re-checked on every redemption — not just at mint time.
5. Rate-limited redemption fails closed.

## Fail diagnostics

| Symptom | Likely cause | File to inspect |
|---|---|---|
| Revoke fails unlicensed | licence-exemption list doesn't include the permissions-delete route | licence gate around write routes |
| Role share succeeds for admin | admin exemption applied to a route that has none | permissions create handler |
| Closed anon link still opens | redemption checks the toggle only at mint time, not on each open | anon-link redemption handler |
| Rate limit doesn't lock out | rate-limit check missing/fails open on ambiguity | anon-link redemption rate limiter |
