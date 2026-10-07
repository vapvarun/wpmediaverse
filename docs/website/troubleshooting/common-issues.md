# Common Issues

What to check when something in MediaVerse does not behave as expected. Start with the two built-in tools, then find your symptom below.

## Two places to look first

1. **Tools > Site Health.** MediaVerse adds its own checks: database tables, the upload folder, required pages, media privacy and template overrides. A failed check names the problem and the fix.
2. **Tools > MediaVerse Logs.** MediaVerse records events here as they happen. Filter by level to find errors around the time of the problem.

## An upload is refused

**Symptom:** A member picks a file and gets an error instead of the upload.

**Likely cause and fix:** the message tells you which rule stopped it.

| Message | What it means | Fix |
|---|---|---|
| "This file type is not allowed." or "This file extension is not allowed." | The format is not in your allowed list | Tick it in **MediaVerse > Settings > General > Allowed File Types** |
| "File exceeds the maximum upload size of N MB." | The file is bigger than your limit | Raise **Max Upload Size** in **MediaVerse > Settings > General**. If it is already at the server limit, ask your host to raise the PHP upload limit |
| "This file has already been uploaded." | Duplicate Detection is set to block | Change **Duplicate Detection** to "Warn (allow upload)" if members should be able to repeat a file |
| "You have used X of Y. Delete something to upload more." | The member reached the fair-use storage limit | Raise **Fair-use storage limit per member (MB)**, or give that member a higher limit on their user profile |
| "That file is empty. Try selecting it again." | The browser sent a file with no content | Select the file again. If it repeats, the file itself is damaged |
| "Failed to store the uploaded file." | The server could not write the file | See "The upload folder is not writable" below |

If one member cannot upload at all, check **Who can upload media** in **MediaVerse > Settings > General**. Members of the ticked roles can upload, and administrators always can.

## The upload folder is not writable

**Symptom:** Uploads fail with "Failed to store the uploaded file.", and Site Health shows "MediaVerse upload directory is not writable".

**Likely cause:** The web server user does not have write permission on the uploads folder.

**Fix:** Site Health prints the exact folder. Ask your host to make it writable by the web server user, then reload Site Health to confirm the check passes.

## A MediaVerse page shows "Page not found"

**Symptom:** Explore Media, My Media or a single media link returns a 404.

**Likely cause:** WordPress has not refreshed its link rules, or a required page was deleted.

**Fix:**
1. Go to **Settings > Permalinks** and click **Save Changes** without changing anything.
2. Open **Tools > Site Health**. If it shows "MediaVerse pages are missing", it lists which ones. Deactivate and reactivate MediaVerse to recreate them. This does not remove any media.

## Site Health says database tables are missing

**Symptom:** "MediaVerse database tables are missing", with a list of table names.

**Fix:** Deactivate and reactivate MediaVerse. The tables are created again and existing data is kept.

## A video will not play

**Symptom:** The member sees "This video cannot play here." with a **Download it instead** button.

**Likely cause:** The file uses a format the member's browser cannot play. MediaVerse plays the file as uploaded and does not convert it.

**Fix:** Convert the video to MP4 (H.264 video with AAC audio) and upload it again. That format plays in every current browser.

## Photos or videos load slowly on busy pages

**Symptom:** Media grids are slow, or the server runs out of PHP workers when many members browse at once.

**Likely cause:** Files are being sent through WordPress one request at a time instead of directly by the web server.

**Fix:** Open **Tools > Site Health** and read the "MediaVerse Media Privacy" check. When it reports "Media is private but sent through PHP, which is slower", it gives the reason and the rule to add to your server configuration. On nginx the rule has to be added by whoever manages the server, because nginx ignores the rule files MediaVerse writes for Apache.

## Site Health warns that media files can be downloaded by guessing their address

**Symptom:** "Some media files can be downloaded by guessing their address".

**Likely cause:** One of two things, and the check says which:
- Files saved before 2.6.1 under readable names are being given random names in the background. The warning clears by itself when that finishes.
- The site is set to keep original file names, so the address of a file can be guessed.

**Fix:** Follow the action shown in the check. On nginx, add the rule it prints to the server configuration and reload nginx.

## My theme's MediaVerse pages look outdated or miss a new feature

**Symptom:** A screen looks different from the documentation, or a new option does not appear, and Site Health shows "Your theme has outdated copies of MediaVerse templates".

**Likely cause:** Your theme replaces MediaVerse templates with its own copies, and those copies are older than the plugin's.

**Fix:** Site Health lists each outdated file with both version numbers. Copy the current file from the plugin's `templates/` folder over the theme's copy in its `wpmediaverse/` folder, then re-apply the theme's changes. See [Template Overrides](../developer-guide/template-overrides.md).

## The Setup Wizard is gone and I want to run it again

**Symptom:** There is no link to the wizard in the MediaVerse menu.

**Fix:** Open `wp-admin/admin.php?page=mvs-setup` directly. See [Setup Wizard](../getting-started/setup-wizard.md).

## Still stuck

Collect these before contacting support, so the first reply can be the answer:
1. The MediaVerse version, and the Pro version if you use it.
2. The full results of the MediaVerse checks in **Tools > Site Health**.
3. The entries from **Tools > MediaVerse Logs** around the time of the problem.
4. The exact message the member saw, and the steps that led to it.
