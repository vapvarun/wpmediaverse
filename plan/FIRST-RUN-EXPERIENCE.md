# WPMediaVerse — First-Run Experience Plan

> What a new user sees from install to "wow this is professional".
> Must feel like Dribbble/Flickr/Instagram — not like a random WP plugin.

---

## 1. Demo Data (The Showcase)

### Demo Users (5 users)
Create 5 realistic users with avatars, bios, display names:

| User | Role | Display Name | Bio |
|------|------|-------------|-----|
| 1 | Admin (existing) | Site admin | — |
| 2 | Author | Mina Aoki | Tokyo-based street photographer |
| 3 | Author | Oliver Brooks | Landscape and nature photographer |
| 4 | Author | Priya Sharma | Food and travel photographer |
| 5 | Author | Liam O'Connor | Architecture and urban photography |
| 6 | Author | Emma Williams | Portrait and fashion photographer |

### Demo Media (50 images using stacked approach)
Use 15-20 base images from `assets/demo-images/`, assign to different users with different metadata to create 50 media items:

| Category | Count | Users |
|----------|-------|-------|
| Nature/Landscape | 10 | Oliver, Mina |
| Architecture/Urban | 8 | Liam, Oliver |
| Portraits/People | 8 | Emma, Priya |
| Food/Culinary | 8 | Priya, Emma |
| Travel/Culture | 8 | Mina, Priya |
| Technology/Abstract | 8 | Liam, Oliver |

Each media item gets:
- Title + description
- Tags (3-5 per item)
- Category
- Privacy (mostly public, 2-3 members-only)
- Randomized view counts (10-500)
- Randomized reaction counts (1-50)

### Demo Social Data
- 50 reactions (likes across media from different users)
- 20 comments (realistic, 20+ chars each)
- 30 favorites/bookmarks
- 15 follows (users following each other)
- 10 views per media (average)

### Demo Albums (5)
| Album | User | Items |
|-------|------|-------|
| Mountain Escapes | Oliver | 6 landscape photos |
| Tokyo Streets | Mina | 5 street photos |
| Food Stories | Priya | 5 food photos |
| Urban Geometry | Liam | 5 architecture photos |
| Portrait Series | Emma | 5 portrait photos |

### Demo Collections (3)
- "Editor's Picks" — smart collection, top reactions
- "Nature & Landscape" — tag-based
- "Trending This Week" — view-based

### Demo Competitions (Pro)
| Type | Name | Status |
|------|------|--------|
| Challenge | Golden Hour Photography | Active (accepting entries, 5 submitted) |
| Challenge | Street Photography Week | Voting (8 entries, some votes) |
| Battle | Landscape vs Portrait | Voting (both submitted, 10 votes) |
| Tournament | Spring Photo Tournament | Registration (4 of 8 registered) |

### Demo Activity Feed
- 20 activity items (uploads, likes, comments, follows)

---

## 2. Default Settings (Best First Experience)

Every option must have a sensible default so the plugin works immediately:

| Setting | Default | Why |
|---------|---------|-----|
| Max Upload Size | 100 MB | Generous for photos |
| Allowed File Types | JPEG, PNG, GIF, WebP, MP4, WebM, MP3, OGG | All common media |
| Default Privacy | Public | Most open experience |
| Duplicate Detection | Warn (allow upload) | Don't block new users |
| Strip EXIF | Yes | Privacy by default |
| Dashboard Page | Auto-created "My Media" | Ready immediately |
| Explore Page | Auto-created "Explore Media" | Ready immediately |
| Upload Page | Auto-created "Upload Media" | Ready immediately |

### Pro Defaults
| Setting | Default | Why |
|---------|---------|-----|
| Battles enabled | Yes | Show the feature |
| Challenges enabled | Yes | Show the feature |
| Tournaments enabled | Yes | Show the feature |
| Boosts enabled | Yes | Show the feature |
| Boost cost/100 | 50 points | Reasonable default |
| Boost max impressions | 5000 | Reasonable cap |
| Boost expiry | 7 days | One week |

---

## 3. Settings UX — Tooltips & Descriptions

Every setting field needs:
- **Label** — clear, short
- **Description** — one line explaining what it does
- **Tooltip** (optional) — for complex settings, a `?` icon with hover explanation

### Example:
```
Max Upload Size    [ 100 ] MB
                   Maximum file size per upload. Server limit: 300 MB.

Default Privacy    [ Public ▾ ]
                   New uploads default to this privacy level. Users can change per upload.
                   ⓘ Public = visible to everyone. Members Only = logged-in users. Private = only the uploader.
```

### Settings that NEED tooltips:
- Duplicate Detection — explain the 3 modes
- Strip EXIF — explain what EXIF is and why remove it
- Custom MIME types — advanced users only
- Watermark settings — position/opacity explanation
- AI Moderation — what it checks, cost implications
- Boost pricing — how points translate to visibility

---

## 4. Setup Wizard (existing — needs audit)

The setup wizard at `admin.php?page=mvs-setup` already exists with 5 steps:
1. Welcome
2. Pages (auto-create Explore/Upload/Dashboard pages)
3. Permissions
4. Display
5. Done

### Improvements needed:
- [ ] Step 2: Show preview of what each page looks like
- [ ] Step 3: Visual role-capability matrix (not just checkboxes)
- [ ] Step 4: Theme/mode selection (Instagram/Flickr/Pinterest layout)
- [ ] Step 5: "Import Demo Data" button + "Go to Explore" link
- [ ] Progress indicator with step labels visible

---

## 5. Walkthrough / Onboarding

After setup wizard completes, show contextual tooltips on first visit:

### Overview Page (first visit)
- Tooltip on "Add Media" button: "Upload your first photo or video"
- Tooltip on "Settings" button: "Customize upload limits, privacy, and permissions"
- Tooltip on stat cards: "These show your media library at a glance"

### Explore Page (first frontend visit)
- Welcome banner: "Welcome to your media community! Start exploring."

### Upload Page (first visit)
- Helper text: "Drag and drop or click to upload. Supports images, videos, and audio."

### Implementation
Use WP's built-in pointer tooltips (`wp-pointer`) for admin, CSS tooltips for frontend.
Store `_mvs_onboarding_step` user meta to track which tips have been shown.

---

## 6. Cleanup Process

### One-Click Cleanup (Overview page)
- "Delete Demo Data" button (red, with confirmation)
- Deletes: all media, albums, collections, demo users (except admin), all custom table data
- Resets: demo_seeded flag, stats counters
- Does NOT delete: settings, pages, capabilities

### WP-CLI
```bash
wp eval-file wp-content/plugins/wpmediaverse/cleanup-demo-data.php
```

---

## Implementation Priority

| Priority | What | Effort |
|----------|------|--------|
| 1 | Fix seeder to create 50 items with stacked images | Medium |
| 2 | Add demo users (5) with avatars | Small |
| 3 | Add tooltips/descriptions to all settings fields | Medium |
| 4 | Add competition demo data | Done |
| 5 | Cleanup script | Done |
| 6 | Audit setup wizard | Medium |
| 7 | Walkthrough tooltips | Large (defer to v1.1) |

---

## Questions for User

1. Should demo users be deletable via cleanup, or keep them?
2. Do we have 50 stock images, or should we reuse 15 with different crops/metadata?
3. Should the walkthrough be in v1.0 or deferred?
4. Any specific settings that need different defaults?
