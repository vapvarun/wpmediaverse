# Document Library

> **Requires WPMediaVerse Pro** - This feature is available exclusively in the Pro version.


Give every member a private drive for their files. Folders, sharing, search, trash and a viewer that shows a contract as a contract - not as its words in a plain column.

## What Users See

Each member gets a **Documents** section on their My Media page: their own drive, with folders they create and name, an upload button, and a list showing name, size, when it changed and who owns it.

Opening a document uses the right view for its type. A PDF opens inline in a built-in viewer (pdf.js), laid out the way the file really looks. Plain text, Markdown and CSV render as formatted HTML. Word, Excel, PowerPoint, OpenDocument and RTF files show a download card - MediaVerse embeds what the browser can render and hands you the file for the rest, rather than converting one format into another.

Beside their own drive members get:

- **Shared with me** - documents other members have given them access to, with a Location column saying where each one lives
- **Trash** - a place things go, and come back from. Removing a document is not permanent by accident
- **Search inside the drive** - across the text of the documents, not just their titles

## What You Can Do

- Create folders and nest them, rename them, move them, and move documents between them
- Select several documents and folders and move them in one go
- Set privacy per document: only me, members, this space, or anyone with the link
- Set privacy on a folder and have everything inside it follow
- Share a document with a specific member or with a whole role, choose whether they can view or edit, and withdraw that access later
- Create a link that opens without signing in, when the site allows it
- Download any document straight from its row

## Folder Privacy

A folder's privacy cascades to what is inside it, and **it only ever tightens**. Setting a folder to Only me makes its documents private. Setting it back to Members does not re-open the documents inside - a document you deliberately made private stays private when the folder around it is loosened.

On a large folder the cascade runs in the background rather than in the request, so a folder holding tens of thousands of documents does not stall the page. The folder's own privacy changes first and immediately, so the window while the rest catches up fails closed rather than open.

## Accepted File Types

Documents are a separate library from media, with their own accepted types and its own size limit. Out of the box: PDF, Word, Excel, PowerPoint, the OpenDocument equivalents, RTF, plain text, Markdown and CSV.

A file is identified by what it actually is, never by its extension. A `.docx` renamed to `.zip` is refused, and so is a `.zip` renamed to `.docx`.

## Document Previews

Opening a document uses the right tier for its type. MediaVerse embeds what the browser can show and hands you the file for everything else - it never converts one format into another.

- **PDF** opens inline in a built-in viewer (pdf.js), page by page.
- **Text, Markdown and CSV** render as server-generated HTML.
- **Word, Excel, PowerPoint, OpenDocument and RTF** show a download card - a titled row with the file's type and size and a Download button.

Downloads always work, whatever the type.

## For Site Owners

Settings live at **MediaVerse > Settings > Documents**:

| Setting | What it does |
|---------|--------------|
| Who can use documents | Which roles get a document library at all. Every role has it to begin with, including roles other plugins add |
| Maximum size | Upload ceiling in MB. `0` follows the server's own limit |
| Accepted types | Which document types this site takes |
| Default privacy | What a newly uploaded document starts as |
| Anonymous links | Whether share links can open without signing in |
| Index for search | Whether uploads are text-extracted so drive search can find them |

Turning documents off for a role **hides the surfaces without deleting a single file**. Turning it back on brings every drive back exactly as it was.

The admin screen at **MediaVerse > Documents** lists every document on the site, with Edit, View on site, Trash and Delete permanently, plus a panel showing the extracted text, where the document sits on its owner's drive, and who can open it.

## Licensing

Documents is the one feature that needs an active Pro licence, and only for **changes**.

On a site whose licence has lapsed:

- Every document stays where it is. Members can open, download, search and share what they already have
- Adding, renaming, moving, binning and sharing are paused, and the controls for them are hidden rather than shown-and-refused
- Withdrawing access someone already has keeps working, so a member is never stuck with a document shared to the wrong person
- Site administrators keep full access, so the owner can still tidy up

Activating the licence restores everything immediately. Nothing is migrated, converted or lost.

Every other Pro feature is unaffected by licence state - the licence buys updates.

## REST API Endpoints for Documents

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/mvs-pro/v1/documents` | List documents on a drive |
| `POST` | `/mvs-pro/v1/documents/upload` | Upload a document |
| `GET` | `/mvs-pro/v1/documents/search` | Search inside a drive |
| `GET` | `/mvs-pro/v1/documents/{id}` | Get one document |
| `POST` | `/mvs-pro/v1/documents/{id}` | Update title, privacy, folder |
| `DELETE` | `/mvs-pro/v1/documents/{id}` | Trash a document |
| `POST` | `/mvs-pro/v1/documents/{id}/restore` | Restore from trash |
| `POST` | `/mvs-pro/v1/documents/{id}/replace` | Replace the file, keeping the document |
| `POST` | `/mvs-pro/v1/documents/bulk` | Move, trash or restore a selection |
| `GET` | `/mvs-pro/v1/documents/{id}/download` | Download the file |
| `GET` | `/mvs-pro/v1/documents/{id}/preview` | Render the document |
| `GET` | `/mvs-pro/v1/me/shared` | Documents shared with me |
| `GET` | `/mvs-pro/v1/drives` | Drives this member can reach |
| `GET`/`POST` | `/mvs-pro/v1/folders` | List or create folders |
| `POST`/`DELETE` | `/mvs-pro/v1/folders/{id}` | Rename, move or trash a folder |
| `GET`/`POST` | `/mvs-pro/v1/documents/{id}/permissions` | Read or grant access |
| `POST` | `/mvs-pro/v1/documents/{id}/permissions/link` | Mint a share link |
| `DELETE` | `/mvs-pro/v1/permissions/{id}` | Withdraw access |

Every route works with an Application Password alone - no cookies, no nonce - so a mobile app can drive the whole feature.

## Hooks and Filters

| Hook | Type | What it decides |
|------|------|-----------------|
| `mvs_user_can_use_documents` | filter | Whether one user gets a document library, whatever their role. The seam for membership tiers |
| `mvs_document_max_size` | filter | Upload ceiling, overriding the setting |
| `mvs_document_allowed_types` | filter | Accepted types, overriding the setting |
| `mvs_document_default_privacy` | filter | What a new document starts as |
| `mvs_document_anon_links` | filter | Whether anonymous share links may be minted |
| `mvs_document_row_actions` | filter | Add row actions to the admin document list |
| `mvs_document_admin_panels` | filter | Add panels to the admin document editor |
| `mvs_document_uploaded` | action | Fires after a document is stored |

A setting always wins over the screen it came from: if your site already sets one of these filters, it keeps winning after you upgrade, so upgrading into the settings screen never silently reconfigures a site.
