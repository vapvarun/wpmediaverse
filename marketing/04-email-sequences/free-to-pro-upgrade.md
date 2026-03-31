# WPMediaVerse Free-to-Pro Upgrade Email Sequence

Four-email campaign targeting free users who have been active for at least 30 days but have not upgraded. Goal is to move the right people to Pro — not to pressure everyone.

**Trigger conditions (recommended):**
- User has had the free version active for 30+ days
- User has at least 10 media items uploaded (engaged, not abandoned installs)
- User has not purchased Pro

**Timing:**
- Email 1: Day 30 of free use (or campaign start day)
- Email 2: Day 37 (one week later)
- Email 3: Day 44
- Email 4: Day 51

**Stop sequence when:** User purchases Pro.

**Sender:** Same sender name as the welcome sequence. Consistency matters.

---

## Email 1 — Check In + Soft Upgrade Intro

**Subject:** How is WPMediaVerse working for you?

---

Hi [First Name],

It has been about a month since you installed WPMediaVerse. I wanted to check in and ask how things are going — and whether there is anything about the plugin that is not working the way you expected.

A few questions that help us understand:

- Is your community uploading actively, or has adoption been slow?
- Is moderation handling the volume you expected?
- Is there a feature you wish existed that does not?

Reply to this email if anything comes to mind. We read every response and it shapes what we build next.

**One thing worth knowing**

If you have been running into any of these situations, there are Pro features that address them directly:

- Members want to do photo competitions but you are managing it manually
- Storage is starting to fill up and you want to move files to S3 or BunnyCDN
- Members upload video and you want proper transcoding, not just raw file storage
- You run a membership site and want to give different tiers different storage limits

If any of those match your situation, the Pro page is at [link]. It includes a full feature breakdown and pricing.

If the free version is covering everything you need — great. That is what it is there for.

The WPMediaVerse Team

---

## Email 2 — Specific Feature: Competitions

**Subject:** Running photo competitions without a spreadsheet

---

Hi [First Name],

I want to tell you about the Pro feature we hear about most from photography communities and clubs.

Right now, if you want to run a photo competition on your community site, you are probably doing it manually. A form, a shared folder, a voting post, a manual count. It works, but it is a lot of effort and the engagement is lower than it should be — members can not easily browse entries, and there is no live leaderboard to build anticipation.

WPMediaVerse Pro has three competition formats built in:

**Photo Challenges**
Set a theme and a submission window. Members upload their entries directly to the challenge. Entries appear on a live leaderboard as they come in. When the window closes, the top photos are already ranked.

Works well for monthly themed competitions, seasonal events, club challenges, or anything with a fixed theme and deadline.

**Photo Battles**
Two photos face off head-to-head with community voting. Simple format, fast to set up, generates strong engagement because members have a clear thing to vote on.

**Tournaments**
Bracket-style competitions across your whole community. Members progress through rounds. Winning a round earns 150 points. Winning the tournament earns 500. Top performers get a badge on their profile.

All three formats connect to the gamification engine — members earn points for participating, not just for winning. That keeps more members engaged across the whole competition, not just the ones who expect to win.

**What this looks like in practice**

A photography club running a monthly challenge: admin creates a challenge for April with the theme "Urban Landscapes". Members have two weeks to submit. The leaderboard is live the whole time. At the end, the top three get their results badge and the winner gets featured on the front page.

No spreadsheet, no manual vote counting, no DMs asking who won.

Pro is [price] per year for a single site. If you run competitions as part of your community, the engagement improvement tends to be immediate.

[Upgrade to Pro — link]

Interested but have questions? Reply here.

The WPMediaVerse Team

---

## Email 3 — Specific Feature: Cloud Storage + Video

**Subject:** If your media library is growing fast, read this

---

Hi [First Name],

This email is for you if either of these is true:

1. Your community uploads a lot of photos and your hosting storage is filling up
2. Members upload videos and you want proper playback, not just file downloads

**The storage problem**

When you are running a community site with active uploaders, media files are the fastest way to blow through your hosting storage allocation. A single member who uploads 200 photos at 2–3MB each adds 400–600MB to your server. With 50 active uploaders, you are looking at 20–30GB of media storage just from a moderately active community.

WPMediaVerse Pro connects to Amazon S3 and BunnyCDN. When you configure cloud storage, uploaded files go directly to the cloud provider — not your server. Your server handles the logic (the database records, the page rendering, the API), but the files live in cloud storage where space is cheap and delivery is faster for members everywhere.

Setup takes about 10 minutes: create an S3 bucket or a BunnyCDN storage zone, enter the credentials in WPMediaVerse > Settings > Cloud Storage, and choose whether to migrate existing files or just start routing new uploads to the cloud.

**The video problem**

If members upload MP4s right now, they get stored and played back as-is. That works for small files but breaks down quickly — large files buffer, mobile playback is unreliable, and there is no way to add chapters or captions.

WPMediaVerse Pro transcodes uploaded videos in the background (it does not block the upload or the page — it happens asynchronously). Transcoded videos are served as HLS, which means adaptive quality — the video plays at the best quality the viewer's connection can handle. Members on mobile on a slow connection get a lower quality stream; members on fibre get the full quality.

Chapter markers and closed captions are also supported — useful for tutorial content, educational videos, or anything longer than a couple of minutes.

**Pro pricing**

Single site: [price]/year
Developer (5 sites): [price]/year
Agency (unlimited): [price]/year

All plans include 12 months of updates and priority support.

[Upgrade to Pro — link]

Any questions about the cloud storage setup before upgrading? Reply here and I will walk you through it.

The WPMediaVerse Team

---

## Email 4 — Final Email: Direct and Low Pressure

**Subject:** Last note about WPMediaVerse Pro

---

Hi [First Name],

This is the last email I will send you about upgrading to Pro — I do not want to be the plugin that keeps nudging you indefinitely.

Here is the straightforward summary of what Pro adds on top of what you are already using:

- Photo Battles, Challenges, and Tournaments (competition engine)
- Four layout modes: Instagram, Flickr, Pinterest, Dribbble
- Cloud storage routing to S3 or BunnyCDN
- Video transcoding with HLS, chapter markers, and captions
- Per-user storage quotas with MemberPress and WooCommerce integration
- Advanced per-item privacy controls
- Priority support

If none of those match a real need on your site right now, the free version is the right version for you. It is not crippled — it is a complete media platform for communities that do not need the Pro additions.

If one or more of those is a real pain point, Pro is [price]/year for a single site with a 14-day refund if it does not work out.

[Upgrade to Pro — link]

And if there is a specific reason you have not upgraded — price, a feature that is missing, something that is not working right — reply here. I read these emails and it helps us build a better product.

Thanks for using WPMediaVerse.

[Name]
WPMediaVerse Team
