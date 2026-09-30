# Setup Wizard

After you activate MediaVerse, a short wizard guides you through the only settings you need to make your first upload possible. The whole thing takes about two minutes.

![Setup wizard welcome screen](../images/admin-overview.png)

## Step 1: Welcome

A quick overview of what MediaVerse does - uploads and albums, the social layer (reactions, comments, favorites, follows), AI moderation and privacy controls, and optional BuddyPress integration. Click **Let's Get Started** to continue, or **Skip setup** to jump straight to the dashboard.

## Step 2: Display

Configure how media appears on your site.

![Setup wizard display step showing items-per-page and layout options](../images/admin-settings-display.png)

| Option | Choices | Default |
|--------|---------|---------|
| Items Per Page | 12, 24, 48 | 12 |
| Default Layout | Grid (square crops), Justified rows (original proportions), List (one row per item) | Grid |

Grid columns are not part of the wizard. You can set them, and change the options above, any time under **MediaVerse > Settings > Display**.

The frontend pages (Explore Media, My Media, Upload Media) are created automatically when you activate the plugin, so the wizard no longer has a step for them. The **Frontend Pages** panel on the MediaVerse Overview screen shows whether each one is in place.

## Step 3: Done

The wizard marks setup as complete (the `mvs_setup_complete` option) and takes you to the **MediaVerse Overview** screen.

From here you can:
- Upload your first media file
- Configure AI moderation settings
- Set up BuddyPress integration (if BuddyPress is active)

## Role Capabilities

The wizard itself does not configure roles. On activation, MediaVerse adds default media capabilities to the Administrator, Editor, Author, Contributor, and Subscriber roles. You can review and change these at any time in **MediaVerse > Settings**.

## Skipping the Wizard

You can skip the wizard at any step using the **Skip setup** link, which takes you to the dashboard. Once setup is marked complete, the wizard no longer runs automatically on subsequent visits.
