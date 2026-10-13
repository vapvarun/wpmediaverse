---
journey: documents-core-share-and-gates
plugin: wpmediaverse-pro
priority: critical
roles: [subscriber, other-member, outsider, anonymous]
covers: [core-documents]
prerequisites:
  - "Site reachable at $SITE_URL, Pro active (licence valid or MVS_PRO_LICENSE_BYPASS on a throwaway site)"
  - "dev-auto-login mu-plugin installed"
  - "Run on BOTH environments: mediaverse.local and the Apache wp-env site"
estimated_runtime_minutes: 10
---

# A member uploads a document, shares it with one person, and nobody else can open it (core path 9)

Check at BOTH levels: the code flow below, then the browser as each role.

## Code flow to confirm

- Upload: Documents drive (`templates/documents.php`, `Documents/DriveRenderer`), REST
  `POST /mvs-pro/v1/documents` (`REST/DocumentController.php`), stored under
  `uploads/wpmediaverse-documents/<random>/`, default privacy private.
- Read gate: `Documents/PermissionService::can_view()` (owner, explicit grants, space links, share
  links); preview/download `Documents/DeliveryController::can_read()`; a hidden document answers 404.
- Share: `PermissionController` grant (Can view / comment / edit); anonymous links OFF by default
  (`DocumentSettings::anon_links()`).
- Writes on an unlicensed site: `DocumentLicense::guard_rest` (403 `mvs_documents_read_only`), reads never.

## Steps

### 1. Upload and preview (owner)
- **Action**: `/my-media/documents/` → Upload a PDF (Upload as: Only me). Open it.
- **Expect**: "1 document"; the page shows the PDF viewer (PDF.js, `.mvs-pdf.is-ready`); owner sees
  Download, Share access, Edit, Delete.

### 2. Share (owner)
- **Action**: Share access → username of Member B → Can view → Share.
- **Expect**: "Access granted."; "Who has access" lists Member B with Can view and Remove.

### 3. Recipient (Member B)
- **Action**: `/my-media/documents/shared/`, open the document.
- **Expect**: listed; the viewer renders; no Edit/Delete/Share controls; download follows the
  site's download setting.

### 4. Outsiders
- **Action**: as Member C (no grant) and logged out: open the page, `GET /mvs-pro/v1/documents/{id}`,
  `/preview`, `/download`, the raw file path; as Member C try `POST /documents/bulk`
  (`{"action":"trash","items":["document:{id}"]}`) and `POST /documents/link`.
- **Expect**: page and REST 404; raw path 403; bulk refuses the item ("gone"/"not_owner") and the
  document stays; link refused; logged-out bulk 401.

### 5. Restore
- Remove the share, delete the document.
