# Setup Wizard

After activation, WPMediaVerse redirects you to a 3-step setup wizard. You can also access it at any time via **Media > Setup** in your admin menu.

[screenshot: Setup wizard welcome screen showing step indicator]

## Step 1: Permissions

Configure which user roles can upload and manage media.

[screenshot: Setup wizard permissions step with role checkboxes]

By default, the wizard grants upload access to Subscribers, Authors, Editors, and Administrators. You can restrict or expand this per role.

Each role maps to a specific set of capabilities:

| Role | Upload | Edit Own | Delete Own | Moderate | Manage Settings |
|------|--------|----------|------------|----------|-----------------|
| Administrator | Yes | Yes | Yes | Yes | Yes |
| Editor | Yes | Yes | Yes | Yes | No |
| Author | Yes | Yes | Yes | No | No |
| Contributor | No | No | No | No | No |
| Subscriber | Yes | Yes | Yes | No | No |

You can change role capabilities at any time in **Media > Settings > Permissions**.

## Step 2: Display

Configure how media appears on your site.

[screenshot: Setup wizard display step showing grid column and items-per-page dropdowns]

| Option | Choices | Default |
|--------|---------|---------|
| Grid Columns | 2, 3, 4 | 3 |
| Items Per Page | 12, 24, 48 | 12 |
| Thumbnail Style | Square (cropped), Original proportions | Square |

## Step 3: Done

The wizard marks setup as complete and redirects you to the **Media Overview** dashboard.

[screenshot: Setup wizard completion screen with link to media overview]

From here you can:
- Upload your first media file
- Configure AI moderation settings
- Set up BuddyPress integration (if BuddyPress is active)

## Skipping the Wizard

If you close the wizard without completing it, you can return to it via **Media > Setup**. The wizard will not run automatically on subsequent visits once step 1 is saved.
