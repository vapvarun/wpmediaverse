# Frequently Asked Questions

Short answers to the questions site owners ask most before and after installing MediaVerse.

## Does MediaVerse need BuddyPress or BuddyNext?

No. MediaVerse works on its own: members get an Explore page, a personal media dashboard, albums, collections and direct messages without any community plugin.

When BuddyNext or BuddyPress is active, MediaVerse connects to it automatically. See [BuddyNext Integration](../buddynext/overview.md) and [BuddyPress Integration](../buddypress/overview.md).

## What can members upload?

By default: JPEG, PNG, GIF and WebP images, MP4 and WebM video, and MP3 and OGG audio. You choose the list in **MediaVerse > Settings > General > Allowed File Types**. See [General Settings](../settings/general.md).

## How large can an upload be?

As large as the **Max Upload Size** setting in **MediaVerse > Settings > General** allows. Your server's own upload limit still applies, so a value above the server limit has no effect until the host raises it.

## Does MediaVerse convert or transcode video?

No. It stores and plays the file that was uploaded and does not run FFmpeg or any other program on your server. A video saved in a format browsers cannot play needs converting before upload. MP4 (H.264 video with AAC audio) plays everywhere.

When a member opens a video their browser cannot play, they see "This video cannot play here." with a **Download it instead** button, so the file is still reachable.

## Can I stop one member from filling the server?

Yes. Set **Fair-use storage limit per member (MB)** in **MediaVerse > Settings > General**. It is 0 (no limit) by default. You can give one member a different limit on their user profile in wp-admin. A member who reaches the limit is told how much they have used and asked to delete something before uploading more.

## What happens when someone uploads the same file twice?

That depends on **Duplicate Detection** in **MediaVerse > Settings > General**:

| Choice | What the member sees |
|---|---|
| Warn (allow upload) | A warning, and the upload goes through |
| Block the upload | "This file has already been uploaded." |
| Allow (no check) | Nothing, the file is stored again |

## Who can see a photo or video?

Each item carries its own privacy level, chosen by the member who uploaded it from the levels you allow. People who are not allowed to see an item do not get its address. See [Privacy & Access Control](../features/privacy-access-control.md).

## Are media files protected from direct download?

Every stored file has a long random name that cannot be guessed, and MediaVerse only gives the address to people allowed to see the item. **Tools > Site Health** shows a "MediaVerse Media Privacy" check that tells you whether your server is set up correctly and what to change if it is not.

## Which AI providers does moderation support?

The free plugin includes OpenAI. MediaVerse Pro adds more providers. See [AI Moderation](../features/ai-moderation.md) and [AI Providers](../pro-features/ai-providers.md).

## Can I change how MediaVerse pages look?

Yes. Copy any file from the plugin's `templates/` folder into a `wpmediaverse/` folder in your theme and edit the copy. **Tools > Site Health** warns you when a copy in your theme is older than the plugin's version. See [Template Overrides](../developer-guide/template-overrides.md).

## Can I move media over from rtMedia, MediaPress or BuddyBoss?

Yes, with MediaVerse Pro. See [Migration Tools](../developer-guide/migration-tools.md).

## What is removed when I delete the plugin?

Your media, albums and messages are kept, unless you tick **Remove Data on Delete** ("Delete all MediaVerse data when the plugin is deleted.") in **MediaVerse > Settings > General** first. With that ticked, deleting the plugin removes its database tables and settings. The uploaded files and the pages MediaVerse created are left in place either way. Deactivating the plugin never removes data.

## Where do I find what is free and what is Pro?

See [Free vs Pro Comparison](../getting-started/free-vs-pro.md).
