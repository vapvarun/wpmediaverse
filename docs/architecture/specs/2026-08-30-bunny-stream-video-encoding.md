# Video encoding — design plan

**Status:** PLAN ONLY. Nothing implemented, and nothing to implement until the questions in §12
are answered.

**Direction chosen by the owner 2026-08-30: a Wbcom-run ENCODE-AND-RETURN service (§10b).**
Bunny Stream (§2-§9) is kept in this document as the costed alternative and as the fallback if the
service does not get built — most of the plugin-side design is identical either way, which is the
point of §0.

## 0. The constraint that decides the shape

The owner's requirement is "FFmpeg at plugin level, without security flagging". That is achievable,
but only in specific ways, and it is worth being exact because the obvious reading of it is not
possible.

**There is no way for PHP to invoke a binary without `exec()`, `proc_open()`, `shell_exec()`,
`system()` or `popen()`.** Scanners match those call sites in the shipped source — not at runtime,
not conditionally. A capability check like `if ( ffmpeg_available() )` does not help, because the
call site is still in the file being scanned. This is why 2.4.0 removed the path rather than gating
it (commit `c96415ba`).

So there are exactly three ways to have FFmpeg without the plugin carrying the flag:

| # | Where FFmpeg runs | What the plugin ships | Flagged? |
|---|---|---|---|
| 1 | **A Wbcom service** | `wp_remote_post()` to an HTTPS endpoint | No |
| 2 | **The member's browser** (`ffmpeg.wasm`) | JavaScript. No PHP exec at all | No |
| 3 | **The site owner's own mu-plugin** | nothing — they write the exec | No, it is their file |

Option 1 is the chosen direction. Option 3 already works today with zero core changes and deserves
a docs recipe (§11). Option 2 is a real possibility that is NOT in this plan and is sketched in §10c
because it fits a pattern this plugin already uses.

**What is not on the list: the plugin running FFmpeg on the customer's server.** That is the thing
that cannot be done without the flag, and no amount of gating changes it.

## 0b. The plan for the scanner problem, specifically

### Can we keep FFmpeg in the plugin and stop the flagging instead?

No, not reliably, and it is worth writing down why so the question does not get reopened every
release.

- **The set of scanners is not knowable.** Wordfence, Patchstack, Sucuri, MalCare, Solid Security,
  Jetpack Protect, plus host-level scanners at SiteGround, WP Engine and others, plus WP.org's own
  Plugin Check at review time. A fix that satisfies one says nothing about the rest.
- **Vendor false-positive processes exist but do not scale.** Wordfence and Patchstack will both
  take a false-positive report and adjust a signature. That is per-vendor, per-signature, ongoing,
  and gives no guarantee — and it does nothing about a generic "this plugin calls exec()" advisory
  or a host's own scanner.
- **Obfuscation makes it strictly worse.** `call_user_func( 'exec', … )`, a variable function name,
  or a base64'd command string are flagged HARDER by heuristics, because that is what actual
  malware looks like. It also risks removal from WP.org. Never do this.

So the plan is relocation, not persuasion. §0 has the three legitimate places FFmpeg can run.

### "Can we keep TranscodeService without using those functions?"

Asked directly, and it deserves a direct answer: **no, because that list is not a blocklist to route
around — it IS PHP's complete process-execution API.**

Verified on this stack rather than recalled:

```
exec  shell_exec  system  passthru  popen  proc_open  pcntl_exec
```

Seven functions, plus the backtick operator which is `shell_exec` in different syntax. That is the
entire surface by which PHP can start a process. There is no eighth door. Scanners did not pick six
names arbitrarily; they enumerated the API.

Everything that looks like a way round it is worse:

| Idea | Why not |
|---|---|
| `php-ffmpeg` composer library | It is a wrapper AROUND `proc_open`. The call lives in `vendor/`, which we ship and which gets scanned. Same flag, now harder to find. |
| Backticks `` `ffmpeg …` `` | `shell_exec` with different punctuation. Flagged, and less greppable for us. |
| Write a shell script, let system cron run it | Drop-a-file-then-execute is the canonical malware persistence pattern. Flagged HARDER than a plain `exec`. |
| Imagick / GD | Imagick reads video FRAMES through delegates — useful for a poster, useless for an H.264 rendition. GD is images only. Neither can transcode. |
| A PECL ffmpeg extension | Abandoned; not installable on any host a customer will have. |
| Obfuscate the call | Dynamic invocation is what malware looks like. Flagged harder, and risks WP.org removal. |

### What DOES work, if the encoder must stay on the customer's server

**A separate, optional add-on plugin.** Not a way round the flag — a way to scope who ever sees it.

- `wpmediaverse` and `wpmediaverse-pro` — the plugins every customer installs — stay at zero, and
  Rule 8 keeps them there.
- A third plugin, e.g. `wpmediaverse-encoder`, carries the recovered `TranscodeService` and its
  `proc_open`. It is the only thing a scanner can flag, and only for the customers who chose to
  install it, having read what it does.
- Rule 8 covers the two shipped plugins by design. A dedicated encoder add-on is exactly where the
  exception belongs — visible, opt-in, and isolated.

Honest about what this does and does not buy:

- It DOES stop the flag reaching the whole install base, which is the actual damage: an owner who
  never asked for transcoding gets a red warning about a plugin they trusted.
- It does NOT make the add-on itself unflagged. Whoever installs it may still see a warning, and
  the plugin description has to say so plainly rather than hoping they do not.
- WP.org may not accept the add-on. It would likely be distributed from the store, like the Pro
  plugins.

That is the whole decision, stated plainly: **the exec cannot be hidden, only located.** Either it
sits on a Wbcom server (§10b, nobody's scanner sees it), or it sits in an add-on the customer
opted into (their scanner sees it, and they knew).

### Current state: already clean, and measured

Audited 2026-08-30 across both plugins' `includes/`:

| Pattern | Count |
|---|---|
| `exec` / `shell_exec` / `proc_open` / `system` / `passthru` / `popen` | **0** |
| `eval`, `gzinflate`, `str_rot13`, `assert(`, `create_function`, `preg_replace /e` | **0** |
| dynamic `call_user_func('…')`, variable-variables, `extract()` | **0** |
| `base64_decode` | **1** — `Connectors/OAuthHelper.php:236`, decoding an OAuth state parameter. Legitimate, `$strict = true`, already carries a phpcs justification. |

Outbound hosts are all named API endpoints (OpenAI, Anthropic, AWS, Google, Flickr, Expo push, the
Wbcom store). Nothing arbitrary, nothing user-supplied.

**There is nothing left in either plugin for a scanner to match.** The problem is not open; the risk
is regression.

### So the plan is three parts, and two are done

1. **Remove the trigger.** Done in 2.4.0.
2. **Make it unable to return.** Done 2026-08-30 — `bin/coding-rules-check.sh` Rule 8 in BOTH
   plugins fails the build on any exec-family call, mutation-tested by planting a `proc_open` and
   confirming exit 1. This is the part that turns a decision someone remembers into a property the
   build enforces, which is what "stable" has to mean when you cannot see the customer's scanner.
3. **Give the capability back somewhere else.** The rest of this document.

### If a customer still reports a flag

Ask which scanner and for the exact file and line. Then: `bash bin/coding-rules-check.sh` proves
the shipped source is clean, and `git log` shows when the path was removed. If the finding is real
it is a Rule 8 escape and a build gate failed, which is a bug in the gate worth fixing immediately.
If it is a stale signature matching an old version, that is a vendor false-positive report with a
concrete diff to point at.

## 1. What this is for

A member uploads a video. It gets encoded into multiple qualities. The player then uses the
encoded URLs instead of the original file. That is the whole feature in one sentence, and the
plan below is mostly about the four places that sentence hides a decision.

MediaVerse removed FFmpeg in 2.4.0 for a reason that has not changed: shipping `exec()` /
`proc_open()` gets the plugin flagged by security scanners as a possible backdoor, regardless of
whether any customer's server can run it (commit `c96415ba`, Basecamp 10232926505). A cloud
encoder is an HTTP call. The scanner objection does not apply to it.

## 2. Why Bunny, specifically

- **Standard encoding is free.** Not per-minute. Mux and Cloudflare Stream both bill per encoded
  minute; Bunny includes standard transcoding in the storage price. Premium encoding is opt-in at
  $0.025/min (low res) to $0.150/min (4K). Stream storage is $0.01/GB/month.
- We already ship a BunnyCDN storage driver, so it is one vendor and one invoice for the site owner.
- Its **iframe player removes the hardest piece of work** — see §6.

**Correction to an earlier claim of mine:** this does NOT reuse the customer's existing BunnyCDN
credentials. Stream needs a Video Library ID and a Stream API key, which are different from a
Storage Zone name and password. Same account and same bill; different keys and a different API.

Source: https://bunny.net/docs/stream/pricing (checked 2026-08-30 — re-check before committing to
pricing in any customer-facing copy).

## 3. THE key finding: Stream is not a storage driver

`StorageDriverInterface` is a path-addressed file store:

```php
store( string $source_path, string $dest_path ): bool;
url( string $path ): string;
exists( string $path ): bool;
download( string $path, string $local_dest ): bool;
```

Bunny Stream does not fit any of those:

| Interface assumes | Stream actually does |
|---|---|
| you choose the destination path | it returns a **GUID** it chose |
| `url($path)` is derivable and immediate | playback URL is library + GUID, and **not valid until encoding finishes** |
| `exists()` answers "is the file there" | the file is there but **unplayable** for seconds to minutes |
| `download()` returns the bytes | the original may not be retrievable at all |

**Do not implement Stream as a storage driver.** Forcing it through that interface makes `exists()`
lie and `url()` return something that 404s for the first minute of every upload's life. It is a
separate integration that happens to be about video, and it is registered on its own seam.

This is the single most expensive thing to get wrong, because it is discovered late — the driver
shape looks right until the first real upload.

## 4. The flow

```
member uploads .mp4
        │
        ▼
UploadService::handle()  ── stores the original exactly as today (unchanged)
        │
        ▼
do_action( 'mvs_media_uploaded', $media_id, $data, $user_id, $media_type )
        │
        └─► Pro: StreamIngest listener
                 ├─ is this a video? is Stream configured? is the licence live?  → else return
                 ├─ create video in the Library (POST), get GUID
                 ├─ upload the bytes (PUT)
                 ├─ media_meta: mvs_stream_guid = <guid>, mvs_stream_state = 'encoding'
                 └─ return (member's request ends here — no waiting)

        ... Bunny encodes, seconds to minutes ...

Bunny webhook  ──►  /mvs-pro/v1/stream/webhook
                    ├─ verify signature
                    ├─ media_meta: mvs_stream_state = 'ready'
                    └─ CacheService invalidate for that media

playback: MediaUrl / the player asks
          mvs_stream_state === 'ready'  ? Bunny iframe embed
                                        : the original file (today's behaviour)
```

**The original is always kept and always stored as it is today.** Stream is additive. That single
choice makes every failure mode in §9 recoverable, because there is always something to fall back
to. It costs storage; that is the price of the feature not being able to lose a member's video.

## 5. Where the URL swap happens

Two existing filters already do this, so no new seam is needed for playback:

- `mvs_public_local_file_url` / `mvs_public_cloud_file_url` (SignedUrlService:901, 908)

Pro hooks them, returns the Stream playback URL when `mvs_stream_state === 'ready'`, and returns
the value unchanged otherwise. Nothing in Free changes.

## 6. The player — and how the hard part disappears

A raw HLS manifest in `<video src>` plays **only in Safari**. Chrome, Firefox and Edge do not
decode HLS natively, so shipping a `.m3u8` without hls.js would give most members a broken player.

Bunny provides an **iframe embed player**, which sidesteps MSE entirely: for a ready video we
render an `<iframe>` instead of a `<video>`. No hls.js, no player rewrite.

The cost is real and should be stated to the owner rather than discovered: inside an iframe we lose
our own player chrome, which means **chapters and resume playback (Pro features that exist today)
stop being ours to draw**. Bunny has its own chapter and resume support; they are not the same
implementation and not driven by our REST routes.

Options, in order of preference:

1. **Iframe for Stream-encoded video, our `<video>` for everything else.** Cheapest. Accept that
   chapters/resume come from Bunny for those items and audit whether our `VideoController` and
   `ResumeService` should be hidden for them rather than showing controls that do nothing.
2. **hls.js + our own `<video>`.** Keeps chapters, resume, retention heatmaps and captions exactly
   as they are. Adds a JS dependency and MSE edge cases. More work, no feature loss.

Option 1 is the fast path; option 2 is the one that does not quietly remove two Pro features. **This
is an owner decision, not a technical one** — see §12.

## 7. OPEN DECISION — privacy

`StorageService::get_driver_for_privacy()` sends only **public** media to cloud storage. Private and
restricted media never leave local disk, deliberately (1.4.0).

Bunny Stream does not fit that rule cleanly. The questions:

- Does a **private** video go to Stream at all? If no, private videos never get encoding, and the
  feature silently does nothing for them — which must be said in the settings screen, not learned.
- If yes, Stream needs token authentication and we are trusting a third party with private member
  media, which is a different promise from the one the privacy architecture currently makes.
- **What happens when a member flips a Stream-encoded video from public to private?** The asset is
  already in Bunny. Do we delete it from Stream and fall back to the local original (losing the
  encode, and the encode cost), or leave it and rely on token auth?

**Recommendation: phase one is public video only.** It matches the existing cloud rule exactly, needs
no new trust decision, and a privacy flip to private falls back to the local original — which we
still have, per §4. Document the limitation plainly in the settings screen.

## 8. OPEN DECISION — where a member's videos live

Videos in a Video Library, everything else in the Storage Zone. One account, two destinations.

A site owner will ask why their videos are not in the storage zone they are paying for. The answer
belongs in the settings screen, next to the toggle. This is a support-load decision more than a
technical one.

## 9. Failure modes, each needing a defined answer

| What happens | Plan |
|---|---|
| Encoding fails at Bunny | `mvs_stream_state = 'failed'`; player uses the original. Member sees a working video, owner sees the failure in the log. Never a broken player. |
| Webhook never arrives | State stays `encoding` forever and the video plays from the original. A reconcile sweep (Action Scheduler, already bundled) polls anything `encoding` older than N minutes. |
| Bunny is down at upload time | Upload SUCCEEDS and the original is stored. Stream ingest is a listener, not a gate — an encoder outage must never block a member's upload. |
| Member trashes the video | `mvs_media_trashed` (added 2.4.0) → delete the Stream asset. Restore re-uploads, or keeps the original. |
| Media permanently deleted | `mvs_media_deleted` → delete the Stream asset. Orphaned remote assets are billable. |
| Licence lapses | Existing encoded videos keep playing. No new encodes. Same shape as the Documents licence gate. |
| Credentials wrong | Fail the ingest, log it, surface it in the settings screen. Do not retry forever against a 401. |

## 10. Settings

Mirrors the existing 5-provider storage pattern, so it is a shape the codebase and its owners
already know:

- Enable Stream encoding (off by default — it costs money and changes where videos live)
- Video Library ID
- Stream API key (through `render_secret_input()`, masked like every other credential)
- Premium encoding toggle, off, with the per-minute cost stated
- Read-only status line: "N videos encoded, N encoding, N failed"

## 10b. OPTION B — a Wbcom-run encoding service (FFmpeg in Docker)

Raised by the owner. Instead of sending video to Bunny, Wbcom runs the encoder: the plugin POSTs
the file to a Wbcom service, FFmpeg runs in a container, renditions come back.

**The plugin side is nearly identical to Option A** — same `mvs_media_uploaded` listener, same
async pending state, same webhook, same URL swap through the existing filters. Only the API client
differs. That matters for the decision: the integration should be written against a small provider
interface so the choice is not load-bearing, and can even be made after the plugin work starts.

### What it solves that Bunny does not

- **The revenue is Wbcom's.** With Bunny, the customer pays Bunny. This is a service you can price,
  bundle with a Pro tier, or meter through the credits/quota system you already ship.
- FFmpeg runs on **your** container, so the scanner objection is gone and the customer's host is
  irrelevant — the same two problems Bunny solves, solved without a third party in the middle.
- Full control of the quality ladder, output format and roadmap. No vendor deprecation risk.

### What it costs, honestly

You become a video infrastructure provider. That is an ops commitment, not a feature:

| Concern | Why it bites |
|---|---|
| **Egress, not CPU** | Encoding CPU is cheap. Moving video is where the money goes. This is the single biggest cost driver and it is easy to under-model. |
| Burst load | Fifty sites uploading 2 GB files in the same hour. Needs a real queue and autoscaling, or it is a 30-minute wait and a support ticket. |
| Uptime | An outage now stops encoding for every customer at once. Option A's outage is Bunny's problem and their SLA. |
| Data + liability | Members' private media transits and rests on your servers. GDPR posture, data residency, retention, DMCA, abuse. Bunny already carries this. |
| Support | "My video is stuck" becomes your ticket, not the customer's host's. |

### THE CHOSEN SHAPE: encode-and-return

**Do not host the video. Encode it and hand it back.** Selected by the owner 2026-08-30.

The service accepts a file, returns renditions, and the customer stores them in the storage they
already configured — local, S3, R2, Bunny Storage, DigitalOcean. Wbcom never serves playback.

That single choice removes egress at scale (you pay to receive and return once, not to serve every
view), removes the CDN, removes long-term storage of customer media, and shrinks the data-protection
surface to transient processing. It also fits the existing architecture exactly, because the storage
drivers and `MediaVariantWriter` already know how to place derived files.

It is worse than Bunny in one respect worth naming: no adaptive bitrate. You would hand back a small
set of MP4 renditions and let the player pick one, rather than a real HLS ladder. For most community
video that is fine and it is what the old FFmpeg path did anyway. It is not "adaptive streaming",
and the copy must not say so.

### Recommendation

Not either/or, and not now. **Write the plugin against a provider interface, ship one provider
first.** Bunny is the lower-risk first provider precisely because none of the ops above is yours;
the Wbcom service is the better business if the volume justifies running it, and encode-and-return
is the version worth costing first.

What would decide it: expected videos/month across the install base, and average file size. Neither
is known today, and guessing them is how infrastructure gets built for load that never arrives — or
falls over on load nobody modelled.

## 10c. Not planned, but worth knowing exists: encode in the browser

`ffmpeg.wasm` runs FFmpeg in the member's browser. The plugin would ship JavaScript and no PHP
exec, so it satisfies §0 with no server and no per-minute cost to anyone.

**This plugin already does client-side media work**, so it is not a foreign idea here: a video with
no embedded cover atom gets its poster from a client-captured canvas frame. The pattern exists.

Why it is not the plan: the WASM payload is tens of megabytes, encoding is far slower than native,
and it is memory-hungry on exactly the devices most members upload from. A phone encoding a 4K clip
will heat up, take minutes, and may simply fail — and it fails on the member's device, in front of
them, rather than quietly on a server.

Where it could earn its place later: a **pre-upload downscale** — cap a 4K phone video to 1080p
before it is uploaded at all. That saves the member's bandwidth, the site's storage and the encode
cost, and it fails safe (if WASM is unavailable, upload the original as today). That is a smaller,
better-shaped feature than full transcoding and worth its own plan if it is ever wanted.

## 10d. The encoder already exists — recover it, do not rewrite it

**This capability was built, shipped and promised to customers.** It was removed for the scanner
problem alone, not because it did not work. The implementation is recoverable from git:

```
wpmediaverse-pro  commit 3ef1f30^   includes/Video/TranscodeService.php     1361 lines
                                    includes/Video/TranscodeController.php   458 lines
```

What is in there and still valid, because none of it is about WHERE ffmpeg runs:

- **The rendition ladder is already decided and shipped** — which answers the open question in §12:

  | preset | height | crf | audio |
  |---|---|---|---|
  | 720p | 720 | 23 | 128k |
  | 480p | 480 | 25 | 96k |
  | 360p | 360 | 27 | 80k |

  These are also the exact resolutions the live marketing promises, so recovering them keeps the
  promise rather than inventing a new one that makes the copy wrong a second time.

- Action Scheduler queueing (`AS_HOOK`), concurrency capping (`MAX_CONCURRENT = 4`), stale-lock
  handling, and a working-directory cleanup sweep.
- The async status model — `_mvs_transcodes` and `_mvs_transcode_status` meta — which is the same
  shape §4 sketches for the pending state. That sketch can be replaced with what already worked.
- `scale='min(1280,iw)':-2`, the never-upscale guard, which is the kind of detail that is
  rediscovered painfully rather than reasoned out.

**What has to change is one thing: where the binary runs.** The command construction, the ladder,
the queue, the locking and the status model move to the encode-and-return service. The plugin keeps
the queueing-and-status half and swaps `proc_open` for `wp_remote_post`.

That reframes the estimate in §13 considerably. This is a port, not a build.

### Rule 8 does not block this

`bin/coding-rules-check.sh` Rule 8 (added 2026-08-30) fails the build on any exec-family call in
either plugin. That is deliberate and it does NOT conflict with this plan — the recovered code goes
into the SERVICE, which is not scanned by a customer's security plugin because it does not ship to
them. If Rule 8 ever blocks this work, the code is being put back in the wrong place.

## 11. What is deliberately NOT in this plan

- Server-side FFmpeg. Already possible today for a capable site with zero core changes: hook
  `mvs_media_uploaded`, transcode in their own mu-plugin, filter the URL. Their code, their scanner
  surface. Worth a docs recipe; not worth core support.
- Live streaming / RTMP. Roadmap item, different feature (MASTER_PLAN.md:102).
- Migrating existing videos. A backfill CLI command is a follow-up once the ingest path is proven.
- DRM, per-video watermarking through Bunny.

## 12. What I need from the owner before building

0. **ANSWERED 2026-08-30 — a Wbcom encode-and-return service (§10b).** The remaining questions
   below still apply, and two new ones come with this choice:
   - **Capacity.** Expected videos/month across the install base and average file size. Still
     unknown, still needed — it decides the queue and the instance sizing, and guessing it is how
     infrastructure gets built for load that never arrives or falls over on load nobody modelled.
   - **The rendition ladder — ANSWERED, see §10d.** 720p/480p/360p at crf 23/25/27 already
     shipped in `TranscodeService::PRESETS` and are the resolutions the live marketing promises.
     Recover them rather than inventing new ones. What remains open is only who picks which
     rendition plays, since without adaptive streaming the player chooses once.
1. **§6 — iframe or hls.js?** Iframe is much cheaper but Bunny's player draws the chapters and
   resume, not ours. Which matters more: shipping speed, or keeping those two Pro features on our
   own implementation?
2. **§7 — public-only for phase one?** Recommended, and it needs saying out loud because it means
   private videos get no encoding at all.
3. **Free or Pro?** Assumed Pro throughout (it is a paid third-party service), gated like Documents.
4. Confirm Bunny Stream pricing independently before it appears in any customer-facing copy. I read
   it from their docs once, on one date.

## 13. Rough shape of the work, once those are answered

| Piece | Size |
|---|---|
| Settings + credentials | small — existing pattern |
| StreamIngest listener on `mvs_media_uploaded` | small |
| Bunny Stream API client (create, upload, delete, status) | medium |
| Webhook route + signature verification | medium |
| Reconcile sweep for missed webhooks | small — Action Scheduler is bundled |
| Playback swap via existing URL filters | small |
| Player branch (iframe) | small · (hls.js) medium |
| Trash / delete / licence-lapse handling | small — hooks exist |
| Journeys + smoke rows + REQUIRED-COVERS entry | small, and not optional |

The API client and the webhook are the real work. Everything else rides seams that already exist,
which is the argument for doing it this way rather than as a storage driver.
