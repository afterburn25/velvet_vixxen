# Architecture and Product Decisions

<!-- scene-schedules-0814:start -->
## Current candidate — 0.8.14 Scheduled Composer scenes, Build 20261021.3

Continue from verified 0.8.13 `main` (`b3e310c3da8084b13dd3ddd8d5dc653f374d5969`). The recovered chat decisions and live GitHub agree that history/restore, Composer/public scale, compact footer, editable menus, depth/foreground furniture, calendar and post expiration are already delivered. Preserve those capabilities and the exact approved package roots. Every changed completed release advances the visible version; merge tested work to main and deliver complete runtime-only Upgrade/Fresh ZIPs.

The scoped continuation is [#32](https://github.com/afterburn25/velvet_vixxen/issues/32): fixed saved Hero/Footer snapshots with activation and optional end dates, explicit keep/restore-previous policy, fresh-owner and stale-state guards, cancellation, atomic claim/recovery, bounded CLI/owner execution and read-only calendar integration. Automatic changes preserve newer drafts; manual publication supersedes pending automatic work. No new database migration is required. The candidate is being verified; final CI, merge and saved-delivery evidence are not yet claimed.

The previously stale 0.8.11 next-session prompt is now historical. Resume this candidate only from its current branch and release record, or from a later verified main merge. Keep #3/#9 open and preserve actual-host #12/#13 boundaries; production mail is unverified, payments sandbox-only and 0.9 incomplete.

[Owner guide](SCENE_SCHEDULES.md) · [Release record](releases/0.8.14-scene-schedules.md)
<!-- scene-schedules-0814:end -->

<!-- editorial-calendar-0813:start -->
## Current release — 0.8.13 Publishing calendar and post expiration, Build 20261021.2

**Verified, merged and delivered.** [PR #31](https://github.com/afterburn25/velvet_vixxen/pull/31) completes [issue #30](https://github.com/afterburn25/velvet_vixxen/issues/30), the bounded calendar and post-expiration increment of broader #9. Merge completed at `2026-10-21T09:43:43Z`; literal saved-download read-back completed at `2026-10-21T09:45:50.359883+00:00`. Earlier release blocks preserve historical evidence and their then-current continuation notes.

Owners open **Marketing & Publishing → Calendar** to inspect a responsive month grid and agenda, navigate months and filter content types in the configured site timezone. Saved dates cover posts, banners, landing pages, galleries, videos, albums, tracks, playlists and email campaigns, plus retained Hero/Visual Footer publication history. Undated or invalid records remain available for review. Entries show current saved states and open the existing editors. The calendar reads saved metadata without initializing publishing storage, running add-ons, publishing content, creating analytics events, queueing recipients or sending email.

In **Posts → Edit**, optional **Expires at** ends public eligibility at that exact instant. Leave it blank for no expiration; when publication is also dated, expiration must be later. Drafts stay hidden. Site-timezone parsing rejects invalid or skipped local times and preserves unchanged saved instants. Expired posts leave public listings, direct pages and related-post suggestions while their saved content/history remain available to the active owner. History includes expiration; restoring an older version without one clears a newer bound and leaves the restored post in Draft. Existing access, revision, CSRF and current-owner protections remain on both file and MySQL backends.

All **seven required jobs and 78 configured required steps** passed in [CI run 39573078884](https://github.com/afterburn25/velvet_vixxen/actions/runs/39573078884), retaining the prior 74 steps in order. The actual checkout in every job was `0bb06c99ddde81ab701e7df3409b2152247451d0`. Source `79a8d305e58e849c7489ba557f3cf10cffc8504d`, that checkout and final merge `6faf826c0a3c6f252c876efbd2e5e8de35ee178f` share complete Git tree `0bc4dda8f6fff355009120b4f76557b319142bc2`. Downloaded runtime/browser artifacts are bound to the run and committed source. All four saved customer files were read back and match the gated artifact by literal bytes, size and SHA-256. No separate post-merge CI run or customer-host installation is implied.

Both PHP 8.2/8.3 source jobs passed **186 post-expiration assertions**, **157 calendar assertions**, **101 runtime-inventory checks** and **692 root-layout assertions**, together with the retained source suite. Actual-package calendar HTTP acceptance passed in file: **2,312 checks** over root/nested installations; mysql_php83: **2,830 checks** over root/nested installations; mysql_php82: **2,830 checks** over root/nested installations. The **4** file/MySQL root-desktop/nested-mobile calendar browser cases passed **578 browser checks**, with **5,064 accompanying HTTP checks** and **40 decoded screenshots**. Real controls cover month/type navigation, editor links, no-script Music editors, saved expiry, rejected bounds, No expiry history and restored drafts. Root reviewed the actual artifact contact sheets and representative full-size captures.

Retained acceptance passed **48 depth scene comparisons**, **4,608 depth pixel samples**, **52 Composer scale comparisons**, **24 legal-footer layouts**, **72 menu public layouts** and **88 footer-placement observations**, including **56 fitting pages without document scrolling**. The approved magenta/black design, editable menus, matching Composer/public scene scale, larger compact footer, blue new-tab Cajun Web Pro credit, bottom placement on short pages, normal long-page scrolling and independent photo depth/foreground controls remain included.

Complete Fresh and Upgrade packages contain **378 / 337 runtime entries**. Use the complete Upgrade for an existing 0.8.x installation. Approved package roots and installed configuration, storage, uploads, owner identity and keys are preserved; no new migration is added. The separate unfinished-installer repair remains byte-identical at **0.8.1 / repair Build 20261020.3**.

Keep **#3** open as the continuing release-evidence policy and broader **#9** open for the remaining marketing capabilities. Automatic Composer scene activation/expiry, deleted-item recovery, campaign-content preview/test-send/templates/tracking, additional audience predicates, referral attribution, analytics operations, missing notifications and provider bounce/complaint handling remain separate. Composer dates in this calendar are retained publication history and drafts; no scene scheduler is delivered. Actual-host **#12/#13** and production email remain unverified; payments remain sandbox-only and the separate **0.9 milestone remains incomplete**.

[Calendar guide](EDITORIAL_CALENDAR.md) · [Release record](releases/0.8.13-editorial-calendar.md) · [CI evidence](releases/0.8.13-editorial-calendar-ci.json) · [Saved-delivery evidence](releases/0.8.13-editorial-calendar-delivery.json)

### Bounded implementation decisions

- Keep the calendar GET-only and owner-only with private/no-store/noindex/no-referrer responses. Use metadata-only reads, skip ordinary Marketing/add-on initializers, and recheck current saved ownership after dependent reads and before output.
- Keep calendar types and month inputs strict. Music links reveal their correct existing editor on the server as well as with JavaScript. Never describe saved media/campaign dates or Composer history as evidence that a worker ran.
- Store optional post `expires_at` using the existing strict timezone/UTC date rules. Visibility uses an inclusive start and exclusive end. Omitted legacy fields are preserved on omitted edits; an explicit blank clears the bound. Restoring old history without expiry removes a newer expiry and forces Draft.
- Reuse existing storage, editors, routes and history safeguards. Add no migration, automatic scene scheduler, new mail sending path or package-root exception.
<!-- editorial-calendar-0813:end -->

<!-- composer-depth-0812:start -->
## 2026-10-21 — Photo depth and source-image foreground outlines (0.8.12, verified)

**Verified, merged and delivered.** [PR #29](https://github.com/afterburn25/velvet_vixxen/pull/29) addresses [issue #28](https://github.com/afterburn25/velvet_vixxen/issues/28). The final release merged on `2026-10-21T06:51:53Z`; literal saved-download read-back completed on `2026-10-21T06:55:05.291536+00:00`. Earlier release blocks remain historical records with their then-current continuation notes.

Optional photo `depth` (0–100) and `behindForeground` remain separate. Per-view `perspective` defaults to 50%/35%; `foreground` stores a matching background source and a simple, nonzero 3–64-point polygon in source-image percentages. Missing optional fields remain absent until used. Strict finite JSON-number and boolean validation runs before scene persistence and restore; numeric strings, invalid polygons and enabled source mismatches are refused. No database migration is added.

`scene-depth.js` is shared by Composer and the public renderer; `SceneDepth.php` is the server validation boundary. Scale is `1 / (1 + 3 × depth / 100)` and visible coordinates move toward the room point. Stored base geometry is preserved, and visible editing uses inverse projection. The foreground is composited as one layer before polygon clipping, avoiding separately antialiased image/overlay edges. Failed/mismatched source images hide the mask. Rear photos, the foreground, then front photos/buttons retain explicit ordering.

Photo layers now have independent **Depth in scene** and **Place behind foreground** controls. A per-device room depth point directs the photo farther into the room, while a drawn outline of the bed or furniture can cover selected photos. Hero and Visual Footer share the same projection and source-image mask geometry in Composer and on the public page. The current design, independent photos, separate buttons, device layouts, prior scale/footer/menu behavior and existing history workflow are retained.

The final [required CI run 39553690388](https://github.com/afterburn25/velvet_vixxen/actions/runs/39553690388) passed **all seven required jobs and 74 configured required steps**, retaining the prior 70 steps in order. The actual checkout in all seven jobs was `c18a80d79e9f5559bdfb2d43f25f3065dfb93c06`. Source `a52747b1b20d8530b328255af740d72821eab70f`, that checkout, and final merge `e7b6938162b15a0371baca97fbf195c14acae569` have the identical complete Git tree `c09486d5f46021d7e021ed67a8fbb3604c42b43c`. The downloaded runtime and browser artifacts are bound to that source/run; all four saved customer files were read back and match the gated artifact by literal bytes, size and SHA-256. This evidence does not claim a separate post-merge CI run.

The source suite passed in both required PHP 8.2/8.3 jobs, including **215 scene-depth assertions**, **55 runtime-inventory checks** and **692 root-layout assertions**. Actual-package depth HTTP acceptance passed in file: **1,954 checks** over root/nested installations; mysql_php83: **1,954 checks** over root/nested installations; mysql_php82: **1,954 checks** over root/nested installations. The four file/MySQL root/nested browser cases passed **3,460 assertions**, **48 editor/preview/public scene comparisons** and **4,608 RGBA pixel samples** with maximum channel error **1/255**. This includes covered/exposed/front regions and horizontal/vertical polygon boundaries (maximum edge error **0/255**), both background fits and transformed/filtered backgrounds. Real controls exercise drawing, numeric and direct point edits, invalid shape refusal, depth-aware movement, reset, undo/redo, lock/hide/copy, save/reload, preview, publish and history restore. Retained scale acceptance passed **52 scene comparisons / 24 legal-footer layouts**, alongside the required existing menus, footer-placement, application, ownership, OPcache, PHP 8.2/8.3, MySQL and package gates.

Keep #3 open as the continuing release-evidence policy. Broader #9 calendar, explicit post expiry and other marketing work remain separate. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only and the separate 0.9 milestone remains incomplete. This repository release and saved delivery do not establish installation or behavior on the affected customer host.

[0.8.12 release record](releases/0.8.12-composer-depth.md) · [CI evidence](releases/0.8.12-composer-depth-ci.json) · [saved-delivery evidence](releases/0.8.12-composer-depth-delivery.json)
<!-- composer-depth-0812:end -->

## 2026-10-20 — Owner-editable menus and public page layout (0.8.11, verified)

- Put Menus under Site & Design. Keep the two locations independent, retain untouched historical defaults, and distinguish a saved empty menu from a default reset. Keep account/cart, legal links, branding and designer credit separate.
- Support bounded ordered trees: 50 items per location, three levels, labels up to 80 characters and safe site-path/fragment/HTTP(S) destinations. A destination-free parent needs children and Same tab. Hidden parents hide their branches.
- Use ordinary links and separate submenu disclosures with semantic lists, keyboard/touch support and usable bounded scrolling. New tab uses the native target; New window requests a popup with opener isolation and native fallback. Browser policies retain final control over windows and modified clicks.
- Use existing protected settings in file/MySQL modes, with no migration. Recheck saved active ownership under the mutation lock/transaction, compare per-location revisions, and keep the save/reset plus aggregate audit atomic. Reset advances a revision tombstone. Retained failed edits never adopt a newer revision silently.
- Extend the current unpublished 0.8.11 candidate for the owner's later short-page/footer-scroll requests. Use one viewport-filling public shell with a flexible main area; keep the footer in flow and reserve only the visible player's measured height. Remove redundant nested viewport minimums and duplicated header spacing. Do not suppress necessary scrolling. The prior unpublished package is superseded and supplies only a diagnostic negative control.
- Preserve all existing release gates and add mandatory file/MySQL menu HTTP and actual-package browser acceptance. Local frozen acceptance and all seven required CI jobs/70 steps passed. Source `d2b87559d9bccb3c6a3a3ac1fa732f9dd364f84c`, actual seven-job CI checkout `ef343c9963e952c4230965c6cd37b01daa9da4bf` and final merge `6d7cfee8936a30c1b1acff47ca55c9e91d9e644b` share complete tree `27d08c36c4b160a05753ae055b70934646e990ce`. The actual menu and retained Composer artifacts were verified and visually reviewed; saved-download literal read-back completed at `2026-10-21T01:55:10.007674+00:00`. See [the release record](releases/0.8.11-menus.md). Actual-host deployment remains unverified.

## 2026-10-20 — Shared Composer/public scene geometry (0.8.10, verified)

- The owner reported that photos visible in Composer were cut off by the actual Hero. The original package reproduces a fixed 760px public height at 1680px width while Composer represents a 1440×760 canvas.
- Preserve the existing per-device design canvases and stored coordinates. Uniformly project the whole public scene into its actual width, with displayed height equal to saved height × actual width/design width. This also preserves background fitting and scales live typography, controls and effects together.
- Keep the public navigation outside the scene and stop the following paper section from overlapping the composed Hero's lower edge. Keep intended edge cropping; do not alter stored owner layouts, source images or publication state.
- Share the geometry helper between editor/public entry points and require it in package/update inventories starting at 0.8.10. Earlier inventories remain inspectable with their own identity.
- Require the focused actual-package root/nested 52-comparison matrix, the failing original-package control, retained Composer/browser gates and all seven required CI jobs. Local success does not establish merge, saved delivery or actual-host installation.
- The later owner footer request is part of the delivered 0.8.10 increment: enlarge bottom-footer type while removing fixed minimum height and reducing padding; add a clearly blue underlined Cajun Web Pro credit opening a new tab on homepage/common renderers. Preserve existing mobile link visibility and allow content-driven wrapping. Verify 24 footer layouts and eight locally fulfilled new-tab clicks in the same packaged browser step.
- A real packaged phone click exposed the fixed player covering the homepage credit. Track actual player height/visibility and reserve clearance below page content without increasing the footer element. Verify real pointer hits, new tabs and hidden-player empty-catalogue responses.
- Delivered 0.8.10 / Build 20261020.11 after all seven jobs and 66 required steps passed in run 39517748806. Source `3357cb048a25d134f1702a48113ecde9025c16ed`, actual CI checkout `1055db0049e6825bddf284e460bbdd45ce64c268` and PR #26 merge `a98847d032a50eabfbd117ade9cdce63de5c25c3` share tree `6dbfc18ffd1aded1015b5691559b6acc0870ab3c`. The actual browser artifact verifies 52/24/8/8 focused observations and 34 decoded PNGs; saved-download literal read-back passed at `2026-10-21T00:25:31.617514+00:00`. See [the release record](releases/0.8.10-composer-geometry.md).
- Foreground masking/depth controls and unrelated contact-card overflow remain separately scoped. This correction does not complete broader marketing #9 or the 0.9 milestone.

## Current continuation after completed menus, Composer depth, calendar and post expiration

The requested menus/public page layout, Composer depth/foreground and bounded 0.8.13 publishing-calendar/post-expiration increments are complete. Broader #9 remains open for automatic Composer scene schedules, deleted-item recovery and the other documented marketing capabilities. Actual-host #12/#13 and production email remain unverified, payments remain sandbox-only, and the separate 0.9 milestone remains incomplete. Keep #3 open as the ongoing release policy. Any further changed delivery needs a new visible version and its own source, CI, artifact, merge and saved-download evidence.

## 2026-10-20 — Bounded editorial history and deliberate restoration (0.8.9, verified)

- Add history to existing posts, banners and landing pages in their current marketing record/backend. Keep at most 20 earlier versions, newest first; capture the prior editorial state only when supported fields or visibility genuinely change. Creation and unchanged saves do not manufacture prior versions. Existing items begin retaining history on their first changed save after upgrade.
- Snapshot only an allowlist of editorial fields. Exclude IDs, permalinks, creation identity, arbitrary unknown fields, private account data and nested history. Preserve the current record's identity, order and unknown fields during restoration. Store capture time in UTC and the captured site timezone; retain explicit date offsets and interpret legacy local dates in that recorded timezone.
- Expose History and Review version only through the active-owner admin dispatcher, using saved-state-only reads, private/no-store/noindex/no-referrer responses and a final current-owner check after dependent reads. Public publication readers omit history. Reviewing is inert and creates no marketing events, messages, storage defaults or publication changes.
- Restore only a server-held retained version under the existing marketing mutation lock and current revision check. Revalidate historical content, current eligible public images, related-post identities and selected tiers; recheck the active owner after reference reads. Refuse stale or invalid restoration without changing current content or history.
- Force restored posts to draft and banners/pages to disabled. Review and explicit ordinary publication are separate owner actions. Preserve the replaced current editorial state as a new history entry when the restore changes it. A restore never requeues or sends campaign mail.
- Keep malformed or oversized history intact and hold ordinary editing/restoration for review. Safe withdrawal to draft/disabled remains possible without resetting damaged history. Do not claim a new snapshot for a withdrawal that could not safely capture one.
- Permanent deletion removes the item and its attached history. Deleted-item recovery, an editorial calendar and explicit post expiry remain separate work. No new database migration, backend cutover, public route or package-root exception is included.
- Delivered 0.8.9 / Build 20261020.10 after all seven jobs and 65 configured required steps passed in run 39510626159. Source `c07c02bd0b6957cba4e0c9d6722765e262e37157`, actual CI checkout `f22982b761358e12db2cacde64a0ce283ce7a6e2` and PR #25 merge `ca638ef486846d37c80606d5a012f07f23299dae` share tree `99ecb497aa99006568152c058c9b9c447625c16a`. Literal saved-download read-back passed at `2026-10-20T23:13:07.846261+00:00`; see [the release record](releases/0.8.9-editorial-history.md). Prior releases retain their historical identities and evidence. At that 0.8.9 delivery, the separate 0.8.10 correction was awaiting its own CI, merge and delivery. Its completed evidence is now recorded above; depth/masking remains pending.

## 2026-10-20 — Bounded editorial metadata and current asset references

- Keep metadata on the existing publication record in both file and MySQL marketing storage; add no schema migration or storage cutover.
- Publish only an explicit author display name. Never infer a public byline from private owner/account fields.
- Use saved IDs for existing public raster images. Validate the actual local file and current protection state, reject unsafe paths and symlinks, and omit an unavailable image without silently substituting another asset. A public image remains public even when selected for restricted content.
- Select related posts explicitly in a bounded saved order. Check source and target access/timing before returning related metadata. Selecting a draft does not publish it.
- Preserve omitted metadata for older callers, require locked revisions for explicit changes, and recheck current ownership after storage-dependent reference validation. Saved previews remain read-only, private and inert.
- Deliver this as 0.8.8 only after its exact source/package/CI/download acceptance. History/restore, calendar and post expiry remain separate next increments.


## Release versions advance with completed work (2026-10-19 America/Chicago)

- The user explicitly directed that visible release versions increase from now on as completed work ships. The rule began with delivered 0.8.2; later completed releases advance to 0.8.3, 0.8.4, or the appropriate next roadmap milestone.
- Date-based build IDs remain useful secondary diagnostics. They do not replace incrementing the release version.
- Keep application, canonical VERSION, installer stamps, upgrade compatibility and new package names consistent. Avoid hard-coded current-version copies where the canonical value can be used safely.
- Preserve prior release numbers, exact package hashes and pinned historical fixtures. Verify each new release before merging and delivering it.
- The 0.9 privacy/protection/operations scope remains a separate milestone; finishing a 0.8.x release does not claim that work is complete.

- Enforce the rule against the latest committed verified delivery record, rejecting changed same-version packages even when only their build labels change. Permit an exact byte-identical rebuild of an already delivered release.
- Keep an unchanged unfinished-installer overlay at its delivered repair version/build and hash; a new full-runtime release does not relabel that separate historical repair.

## Editorial visibility and private saved previews (0.8.7)

- Keep editorial access separate from media entitlements and newsletter audience/consent rules. Public, Members and Selected tiers apply to saved posts, banners and landing pages. Members includes active verified free accounts with a valid saved device session; selected tiers uses exact enabled plan IDs and current eligible subscriptions without an inferred hierarchy.
- An absent legacy access field remains public. Explicit malformed restrictions, unsupported policy versions, empty tier selections and unavailable selected plans hold access until deliberately repaired. Current account, device, plan and subscription state is read for each public request; malformed nonempty dates cannot grant access.
- Apply publication timing and access before listing limits, titles/excerpts, page metadata or campaign-view recording. Keep saved banner order and choose the first eligible banner. Send private/no-store headers before viewer-dependent HTML or generic denial responses.
- Use a separate active-owner saved preview inside the existing admin dispatcher. Recheck the saved owner's active role, use the authoritative backend without initializing missing marketing data, and omit publication mutations, marketing events, outbound mail, tracking and share tokens. Public owner flags do not create a bypass.
- Preserve locked publication revision/CSRF/method checks, immutable saved permalinks and unrelated fields. Recheck current owner and selected plan definitions inside the save lock. Keep safe withdrawal/deletion available when a policy needs repair. File and MySQL use the same policy and publication API; no new schema or automatic backend switch is needed.
- Advance the visible release to 0.8.7 / Build 20261020.8 and retain all existing gates. The release is not complete until the changed source, actual-package file/MySQL HTTP and desktop/mobile flows, exact GitHub tree/artifacts, merge and saved downloads have passed their recorded acceptance.

## Add-on migration recovery (0.8.3)

- Preflight the complete package migration plan and executable ownership boundary before migration SQL. New automatic migrations may add owned InnoDB objects; they may not rewrite retained data or adopt unrelated existing tables.
- MySQL DDL can commit independently. Persist exact statement progress and a separate lifecycle intent across SQL, files and registry activation. Keep prior files until activation commits and hold pending add-ons from activation/boot.
- Retry only definite recorded failures after current-owner/POST/CSRF and exact package/registry/definition validation. Unknown outcomes require review. Explicit rollback and purge can delete values; a database backup is necessary to restore deleted data.

## Grouped actions and guarded campaign delivery (2026-10-20)

- Store optional shared `scene.contentButtons` separately from per-device group geometry. Absence preserves automatic legacy Hero/Footer actions; an explicit empty list intentionally removes group buttons. The right inspector supports up to eight ordered live actions. Photo cutouts and independent button layers remain separate.
- Validate destinations before persistence and again when rendering legacy content. Keep automatic account/trailer behavior, escaped live HTML, owner-only draft preview and published history. Local links use the existing physical dispatcher and installation prefix.
- Campaign queueing freezes content and deduplicates normalized recipients. Current confirmed consent, suppression, schedule and enabled/paused state are checked again before body transmission; unsubscribe always remains available.
- Preserve protected JSON for this bounded increment. Use separate worker and short fresh-state mutation locks, atomic replacement and revision guards; do not hold a user's PHP session lock across SMTP waits. Marketing SQL storage remains explicit roadmap work.
- Treat transport acceptance, definite failure and unknown acknowledgement as distinct outcomes. Retry definite failures only through an explicit owner action with cooldown and attempt limits. Hold unknown or interrupted transmission without automatic resend; deliberate pre-transmission pauses do not spend an attempt.
- Reuse the existing installation-configured mail transport. Independent signup/delivery switches do not configure SMTP. An owner SMTP editor and production transport verification remain separate work. Customer runtime layout, private-state exclusions and sandbox-only payments stay locked.

## Organized root and central public routing correction (2026-10-19)

- The owner explicitly rejected the agent-authored physical root compatibility folders. Restore the pre-regression 13-directory fresh and nine-directory upgrade allowlists and four root files: `.htaccess`, `VERSION`, `index.php`, `route.php`.
- Keep public implementations under `public/routes`; use the physical `route.php` dispatcher and an allowlisted scalar `vvx_route` selector for generated URLs on hosts without rewrite support. Retain existing pretty-path aliases through actual server rewrite configuration.
- Check complete actual ZIP root directories independently of the packager's own allowlist. Passing action tests cannot excuse a directory regression.
- An existing .5 installation must be repaired in place: verify new runtime bytes and owner/CSRF boundaries; privately preserve exact known retired stub bytes as nonexecuting data; remove only those verified files and empty retired directories. Never move/delete custom siblings or unrelated state.

## Superseded public route compatibility decision (2026-10-19)

The first two bullets below are historical and explicitly rejected by the owner; do not implement or retain their root-file/folder exception.

- Keep protected implementations under `public/routes` and preserve existing public URLs with thin physical entry points. A host may dispatch nonexistent `.php` paths to FastCGI before rewrite rules can forward them. Every registered public page/API must be present in the runtime ZIP, including nested-installation paths and short account aliases.
- Keep `media.php` as the single additional root compatibility entry point for already-issued signed-media URLs; its implementation stays under `public/routes`. This is a narrow organized-source exception, not permission to move business logic into the root.
- Test actual extracted packages without the universal test router as well as the existing rewrite-router acceptance. Missing physical files must cause the negative controls to fail; readable application errors are distinct from a host's missing-file page.
- Parse upload and scene responses through one shared JSON helper. Preserve server validation messages, translate non-JSON/HTTP failures into useful errors, and re-enable failed controls for retry. A failed Full Page Preview must keep its new tab open with an explanation instead of immediately closing it.
- Store separate live button layers in the existing per-device scene JSON. Reuse geometry, ordering, history, hide/lock and copy controls; keep photo layers and the existing live text/button group independent. Validate button labels, colors, geometry and destinations before any scene write. Allow site-relative or HTTP(S) destinations, reject unsafe schemes and render labels as text.
- Ship a separately allowlisted Media/Composer repair for installed 0.8.1 sites, plus current fresh and upgrade distributions. Configuration, storage, uploads and all customer/private data remain outside the repair. No database migration or installer rerun is required for these controls and routing changes.

## Product identity and design

- Product: Nikki Wilder — The Velvet Vixxen
- The approved neon-pink/magenta/black direction is the design source of truth.
- No additional mockups are created unless explicitly requested.
- User/model photographs remain replaceable layers; default public artwork stays people-free.
- Navigation, forms, paywalls, players, pricing, publishing controls, and accessibility remain live HTML/JavaScript.

## Installer and hosting

- Installation occurs on a single responsive browser page with simplified, collapsible sections.
- No installation key is required.
- Owner and member passwords have a 12-character minimum.
- File checks use real write/read/rename/delete probes.
- Safe permission attempts are `0755`/`0775`; `0777` is never accepted.
- Ownership mismatches on writable runtime folders are repaired without requiring world-writable permissions.
- Installation runs versioned migrations, verifies final configuration and boot, creates a lock, and deletes or securely retires `/install`.
- Files remain organized by responsibility.

## Distribution packaging

- The public installation ZIP contains only files required to install and run the actual site.
- Repository metadata, GitHub workflows, docs, tests, SDK source, screenshots, reports, and development-only tooling do not ship in the customer installation ZIP.
- Operational billing, add-on-task, and marketing runners remain included because the installed site requires them.
- The complete engineering source, tests, documentation, and SDK are committed to this repository. Runtime packages are generated from that same source; they exclude development files and installed data.

## Media, access, and commerce

- Music, photos, and video are first-class content types.
- One Entitlement Resolver handles public, member, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted access.
- Protected originals remain outside public paths and use signed/authorized delivery.
- Raw card numbers are never stored.
- Payment providers use adapters; the bundled provider is sandbox-only.

## Add-ons and licensing

- Add-ons never patch core files and are not described as fully sandboxed.
- Every package declares identity, compatibility, permissions, dependencies, conflicts, and integrity data.
- Unsafe paths, symlinks, package bombs, checksum failures, and invalid signatures are blocked.
- Remote updates enter the ordinary permission/compatibility review and backup/rollback lifecycle.
- Update/support expiration or a temporary service outage does not intentionally disable installed local functionality.
- Private signing keys, raw license keys, and protected releases never enter public source control.

## Messaging, requests, and booking

- Private messaging is asynchronous and account-scoped; it is not represented as real-time websocket chat.
- Conversations, requests, bookings, notifications, blocks, and attachments have explicit owner/member relationships.
- Email alerts announce activity and link to the site; they do not include private message bodies.
- Communication attachments live outside public asset paths and are delivered only through authorization checks.
- Guest/member pages never expose owner-internal notes or unrelated records.
- Contact blocks stop new activity while preserving prior history.
- Content-request and booking quotes are workflow records, not automatic charges or final legal agreements.
- Saved replies are owner conveniences; sending remains an explicit owner action.

## Publishing and marketing

These are design requirements; advanced audience/expiration, scheduled scenes, attribution, retention and MySQL marketing persistence were not recovered as implemented capabilities. Current code/evidence governs completion claims.

That recovery finding and the earlier dated campaign-storage decision describe their original baselines. The bounded 0.8.6 SQL storage increment is now implemented: migration 015 creates the additive schema, and a separate reviewed owner action moves the preserved marketing snapshot. File-mode compatibility remains. [Marketing Storage](MARKETING_STORAGE.md) documents the active-backend and failure boundaries. The bounded 0.8.7 [editorial access and saved-owner preview](EDITORIAL_ACCESS.md) increment is also complete. The bounded 0.8.8 [editorial metadata](EDITORIAL_METADATA.md) and 0.8.9 [editorial history/restore](EDITORIAL_HISTORY.md) increments are complete. [The roadmap](ROADMAP.md) starts the remaining #9 work with separately scoped calendar and explicit post expiry; deleted-item recovery remains separate. SQL persistence does not implement indexed analytics or complete 0.9.

- Editorial content uses explicit draft, scheduled, published, archived, and expiration states.
- Public visibility is resolved by publication timing and audience rules rather than by obscured URLs alone.
- Newsletter consent, confirmation, unsubscribe, and suppression are distinct records and must be respected by every campaign send.
- Unsubscribe remains available even when newsletter signup is disabled.
- Email delivery uses the configured PHP-mail/SMTP abstraction; no production email provider is assumed.
- Private message bodies and protected content never appear in campaign or transactional email payloads.
- Promotional banners and campaign pages use live HTML around optional artwork rather than flattening functional content into images.
- Scheduled Visual Composer scenes use snapshots, a claim state, and idempotent task processing.
- File-store scene processing must re-read saved scene state after publication to avoid stale overwrite.
- Link-in-bio clicks and referral events are recorded without exposing private member data publicly.

## Analytics and privacy

- Analytics are first-party and same-origin by default.
- Foreign-origin collection requests are rejected.
- Analytics payloads exclude raw card data, payment credentials, protected-media contents, private-message bodies, attachments, identity records, and exact sensitive documents.
- Collection can be disabled independently from other marketing features.
- Analytics and consent data have configurable retention controls.
- CSV exports protect against spreadsheet formula injection.
- Screenshots support visual review but do not replace interactive browser acceptance.

## Repository and evidence

- Repository: `afterburn25/velvet_vixxen`.
- GitHub documents, issues, pull requests, tests, and handoff files are the continuity hub.
- A local/package milestone and a GitHub branch are separate truth boundaries.
- Do not claim source import, CI success, MySQL, Playwright, merge, tag, live payments, production email delivery, or deployed licensing without direct evidence.
- Public source never contains credentials, generated keys, raw license keys, signing private keys, customer/member/subscriber data, private conversations, booking/request records, owner notes, attachments, identity documents, runtime state, or private/paid media.

## Source recovery and progress discipline (2026-10-19)

- The latest runtime baseline was recovered from a checksum-verified 0.8.1 package; separate 0.7.4 composer fixes require a targeted merge.
- Upload the latest source, tests, and progress documentation at every completed session. Missing application source is a failed CI condition.
- Historical implementation claims are checked against actual code. The recovery record identifies unimplemented UI/store/marketing work; these remain unfinished until code and tests land.
- An installed site requires a matching configuration/JSON lock identity. A malformed or unrelated lock never proves installation.
- Recovery never downgrades a valid active configuration to an older backup. Existing configuration/identity/key evidence blocks destructive fresh reinstallation; a complete pending checkpoint can continue while preserving its original identity and keys.
- Repair backups containing unique, different, unreadable, unknown, or symlink entries cannot be deleted by the cleanup UI. Deletion requires a fully verified duplicate and explicit owner confirmation.
- Fresh-install distributions exclude active config/app.php. Upgrade distributions exclude config, storage, uploads, and install entirely.
- Source and installed-demo tests run in disposable copies, never in a real site or the active engineering checkout.

## 0.8.1 continuation decisions

- Each device stores its own background/geometry, photo layers and live HTML text/button region. Full Page Preview saves both drafts without publishing them. All public scene edges clip separately movable photos.
- The luxury library counts only valid individual image/thumbnail pairs. Generation provenance, masters and screenshots remain outside runtime packages.
- Booking field definitions use stable identifiers/revisions. Each inquiry retains the original question labels/options and answers even after later edits or deletions.
- Merchandise carts contain physical catalog lines only. Membership and PPV/rental use separate checkout intents; direct purchases do not clear the merchandise cart. Stock reservations are serialized, terminal payment events are idempotent, and stale owner edits cannot overwrite newer inventory.
- Shipping uses configured per-item charges and private order addresses. Automatic carrier labels, region-specific taxes and live payments are not implied.
- Installed database updates apply pending versioned migrations after identity/plan checks and explicit operator backup acknowledgment. Failure records are retained; destructive down SQL never runs automatically on an existing site.
- Browser asset cache identity is distinct from the 0.8.1 core/add-on compatibility version.
- Canva is a saved-link/manual export/import bridge. Functional HTML and transparent owner photos remain separate from decorative artwork.

## Installer package ownership correction (2026-10-19)

- Mandatory static directory checks must match actual packaged contents; empty design scaffolding is not a runtime prerequisite.
- PHP reads application/assets/templates; it does not require write access to those panel-owned trees. Missing required packaged directories remain failures, not empty directories manufactured by preflight.
- Automatic ownership repair remains restricted to the existing writable runtime roots. Do not copy or rotate public assets merely to create unused folders.
- Installer regressions must extract the real customer ZIP and execute PHP as a different unprivileged owner; a same-owner temporary directory is insufficient evidence of shared-hosting behavior.
- The historical folder-only correction used a runtime-only one-file overlay. The subsequent database/layout correction uses the explicit eight-file repair allowlist. It never replaces configuration, storage, uploads, database contents or installed application data.

## Existing-database installation and final activation (2026-10-19)

- The user requires installation to continue with empty or nonempty databases. Unrelated tables remain untouched; compatible application tables and migration history are reused. Conflicting known definitions/checksums receive specific preservation errors.
- Never run automatic destructive down migrations on installer failure. MySQL DDL may already be committed; retain completed schema/ledger work and stable pending configuration so a later browser request can retry.
- Existing admin records require the current active owner email/password. Do not reset the owner hash or create a replacement owner around failed authentication.
- Keep original matching security keys and installation identity from staged configuration or verified backups. If existing encrypted/HMAC license records require missing original keys, preserve the data and request restoration of that configuration.
- Seed missing defaults only. Existing settings, scene versions/drafts, plans, media, templates, provider configuration and saved records are authoritative. Recreating missing primary installation metadata uses the effective preserved URL and original installation time.
- Pending setup is not publicly installed, including the interval after its completion lock is written. Clear the pending marker only after final configuration/database/lock verification. Setup, automatic recovery and installer retirement cooperate through a per-root lock; concurrent roots also serialize access to the selected MySQL database.
- Keep the one-page form, no installation key, 12-character minimum passwords and safe permissions. Fix alignment in the actual existing interface without creating mockups or changing the approved design.
- The runtime repair includes exactly its reviewed PHP/CSS/JavaScript dependencies. Customer configuration, storage, uploads, database records, docs, tests and evidence never enter the overlay. Advance changed component ZIP timestamps and browser asset queries so deployed fixes load after extraction.
