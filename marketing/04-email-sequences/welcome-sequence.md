# WPMediaVerse Welcome Email Sequence

Five-email onboarding sequence for new free users. Triggered from plugin activation or WordPress.org download confirmation.

**Goal:** Get users from "installed" to "first upload live in the feed" within the first two emails, then help them explore the features most likely to make the plugin sticky. By email 5, they should understand what Pro adds and have a reason to consider it.

**Timing:**
- Email 1: Immediately on activation / day 1
- Email 2: Day 2
- Email 3: Day 5
- Email 4: Day 9
- Email 5: Day 14

**Sender name:** WPMediaVerse Team (or use first name of founder if solo)
**Reply-to:** A monitored inbox. These users should be able to reply and get a real response.

---

## Email 1 — Welcome and First Steps

**Subject:** You installed WPMediaVerse — here is where to start

---

Hi [First Name],

Thanks for installing WPMediaVerse. Let us help you get the first photo live on your site in the next 10 minutes.

**Step 1: Make sure your media page exists**

WPMediaVerse creates a few pages during setup — a media feed, a personal dashboard (/my-media/), and a user profile page (/media/@username/). If you skipped the setup wizard, head to WPMediaVerse > Settings and run it now.

**Step 2: Upload your first photo**

Go to your site's front-end, navigate to /my-media/, and you will see the upload button. Add a photo, add a caption if you like, and hit publish. That is it — your first media item is live.

**Step 3: Check your BuddyPress integration (if you use BP)**

If your site runs BuddyPress, go to WPMediaVerse > Settings > BuddyPress. Make sure the integration is enabled. Your uploads will automatically appear in the activity feed and in a new media tab on member profiles.

That is the quick start. Once you have a photo up, you will have a feel for how the feed works.

If anything did not go as expected — a page did not create, the uploader did not appear, or you got an error — reply to this email. We will sort it out.

The WPMediaVerse Team

---

**P.S.** The most common setup issue: the /my-media/ page showing a blank screen. Usually means the page got created but the WPMediaVerse shortcode is missing. Reply and we will send you the fix.

---

## Email 2 — AI Moderation: What It Does and How to Configure It

**Subject:** The moderation feature most people do not realise they have

---

Hi [First Name],

Quick check-in on day two.

By now you should have your first photo in the feed. If you ran into any trouble getting there, reply here and we will help.

Today I want to tell you about one of the more important features in the free version: AI moderation.

**What it does**

Every time a member uploads a photo, WPMediaVerse runs a content check before the photo goes into the feed. If the photo is flagged, it goes into a moderation queue for you to review. If it passes, it publishes automatically.

Most uploads will pass without you touching anything. The ones that might cause problems get a second look before they are public.

**Why this matters for community admins**

Without moderation, you are the last line of defence — and that means you need to either review every upload manually, or deal with problems after they are already public. Neither is great.

With AI moderation, you review a small subset of uploads (the flagged ones) instead of all of them. Your time goes to the edge cases, not the routine uploads.

**How to configure it**

Go to WPMediaVerse > Settings > Moderation. You can:
- Set sensitivity level (how strictly the AI flags content)
- Choose whether flagged content goes to the queue or is auto-rejected
- Add trusted user roles whose uploads skip moderation entirely
- Review and action flagged content from the same screen

If you are running a photography community for adults where more mature artistic content is acceptable, lower the sensitivity. If you are running a school or family community, increase it.

**The review queue**

Flagged uploads appear in WPMediaVerse > Moderation > Review Queue in your admin. You can approve, reject, or mark as reviewed. Simple.

Any questions about moderation settings? Reply here.

The WPMediaVerse Team

---

## Email 3 — Albums, the Follow System, and Making the Feed More Social

**Subject:** Three features that make members come back

---

Hi [First Name],

A media platform is only as useful as the engagement it creates. Today I want to walk you through three features that help members stay connected to each other's work.

**Albums**

Members can organise their uploads into albums — a portfolio of their travel photography, their best street shots from 2025, work from a specific project. Albums make it easy for other members to browse someone's work without scrolling through a feed.

To create one: from the /my-media/ dashboard, select "Create Album", give it a name, and add photos from existing uploads or upload new ones directly.

Albums also work well for admins who want to curate content — you can create club albums or community collections and invite members to add to them.

**Follows**

Members can follow each other the same way they would on any photo platform. When someone you follow uploads a new photo, it surfaces in your feed. This creates a personal, relevant feed instead of a chronological dump of everything.

Encourage your most active members to follow each other early on. It makes the feed feel alive even when total community size is small.

**Reactions and comments**

Reactions (the equivalent of likes) and comments work on every media item. Members who post get a notification when someone reacts to or comments on their work. That notification loop is what makes people keep coming back.

Direct messages work the same way — members can DM each other about each other's photos directly from the media page.

**One practical suggestion**

If you have existing members, send them a note letting them know about the media section and what they can do there. The first few active members set the tone. Get 5–10 people uploading and engaging in the first week, and the community dynamic tends to build from there.

Anything not working the way you expected? Reply here.

The WPMediaVerse Team

---

## Email 4 — BuddyPress Integration Deep-Dive (Skip If Not Using BP)

**Subject:** Getting the most out of WPMediaVerse + BuddyPress

**Segmentation note:** Only send this email to users who have BuddyPress active on their site (detectable via plugin activation). If you cannot segment, change the subject to "Getting the most out of your media section" and lead with the non-BP content below.

---

Hi [First Name],

If your site runs BuddyPress, WPMediaVerse is designed to work with it — not replace it. Here is a quick tour of what the integration covers and what you should check.

**What the BuddyPress integration does**

- Media uploads automatically post to the BuddyPress activity stream ("Sandra uploaded a new photo")
- Member profile pages get a media tab showing their uploads in a grid
- Group pages get a media section — members of a group can share photos specifically within that group
- Lightbox (the photo viewer that opens when you click a photo) works inline with BP activity posts — members do not have to leave the feed to view a photo

**What to check in your settings**

Go to WPMediaVerse > Settings > BuddyPress.

Make sure "Post uploads to activity stream" is enabled. This is on by default, but double-check.

If you use BP Groups, enable "Group media sections". This adds a media tab to each group page.

If you want to control what appears on member profile tabs (and in what order), the BP profile tab position can be configured from the same settings panel.

**A note on activity feed integration**

When a member uploads multiple photos in a single session, WPMediaVerse batches them into a single activity post (up to 6 photos per post) rather than creating one post per photo. This keeps the feed clean when someone uploads a batch of shots from a day out.

**The lightbox**

The lightbox that opens when a member clicks a photo in the activity feed — reactions, comments, navigation between photos — all works directly from the lightbox without reloading the page. Members who have been using basic BP media will notice the difference immediately.

Anything not connecting properly between WPMediaVerse and BuddyPress? Reply here or go to WPMediaVerse > Tools > BP Sync to run a diagnostic.

The WPMediaVerse Team

---

## Email 5 — What Pro Adds (and Whether It Is Right for You)

**Subject:** Is WPMediaVerse Pro worth it for your community?

**Timing:** Day 14

---

Hi [First Name],

You have had WPMediaVerse installed for two weeks now. I want to give you an honest look at what Pro adds, so you can decide whether it is worth it for your specific situation.

**The free version is genuinely complete.** Uploads, albums, the Instagram-style feed, reactions, comments, DMs, follows, AI moderation, and BuddyPress integration — those are all free. If that covers what your community needs, you do not need Pro.

Here is when Pro makes sense:

**You want to run photo competitions.**
Photo Battles (head-to-head voting), Challenges (theme + deadline + leaderboard), and full Tournament brackets are Pro features. If you run a photography club, an art community, or any site where competition is part of the culture — Pro pays for itself in member engagement alone.

**Your storage costs are getting out of hand.**
If you have an active community uploading photos regularly, local storage fills up fast. Pro connects directly to Amazon S3 or BunnyCDN. Your uploads go straight to the cloud. Your server handles the logic, not the files.

**You need more from video than a play button.**
Members uploading MP4s is a free-version feature; chapter markers, resume playback, auto-captions, and retention analytics are Pro. If your community produces video content — tutorials, competition entries, vlogs — the difference is significant.

**You have a membership site with tiered storage.**
If you run memberships with different tiers (MemberPress or WooCommerce), Pro lets you assign storage quotas per tier. 1GB on the free plan, 10GB on the paid plan. It connects directly to your existing membership setup.

**You want gallery and portfolio layout modes.**
Pro adds three layout modes beyond the Instagram feed: a Flickr-style photo grid, a Pinterest-style masonry layout, and a Dribbble-style portfolio card view. Different audiences present their work differently — Pro gives you the choice.

**Pro pricing:**

[Single site] — $X/year
[Developer, 5 sites] — $X/year
[Agency, unlimited sites] — $X/year

All plans include 12 months of updates and priority support.

If you are unsure whether Pro is the right call for your site, reply to this email and tell me what you are trying to do. I will give you a straight answer — including "the free version is enough for you" if that is the honest answer.

The WPMediaVerse Team

---

**P.S.** We offer a 14-day refund if Pro does not work out for your setup. No awkward questions.
