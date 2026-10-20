# Nikki Wilder — The Velvet Vixxen

<!-- composer-geometry-0810:start -->
## Candidate — 0.8.10 Scene scale and compact footer, Build 20261020.11

**The requested Hero clipping correction is implemented and locally verified; source publication, required CI, merge and saved-download delivery remain pending.** The latest completed release is 0.8.9 / Build 20261020.10, merged through PR #25 and delivered. This candidate continues from authoritative main `029ce3defd149db453380b99326836e86929ddd6` on `fix/0.8.10-composer-hero-scale`.

Composer and the public Hero/Visual Footer now share the 1440/900/390-pixel desktop/tablet/mobile design canvases. Public height equals the saved design height multiplied by available width/design width. Background crop, photos, live content, standalone buttons and pixel effects scale together, preserving existing scene data and intentional crops. No resave or layout migration is required; navigation remains outside the scene. The pink live-content box remains a positioning guide, with actual copy shown by Full Page Preview. Foreground masking and new depth controls remain separately pending.

The owner's footer follow-up is included: larger bottom-footer type, 16px navigation/legal/credit text, a blue underlined Cajun Web Pro LLC link to `https://cajunwebpro.com/` that opens a new tab, and compact content-driven height with no minimum-height reservation, 8px vertical padding and 4px row gaps. Homepage and common public footers share these rules; existing responsive link visibility and scene data are preserved. The page reserves the visible fixed audio player's measured height plus 8px below its content, so the player cannot cover the footer credit; a hidden player reserves no space. The focused browser matrix also covers both footer renderers at six widths and actual new-tab navigation. The prior 1121px legal-footer overflow is now within the requested correction; unrelated 320px contact-card overflow remains separate.

The complete local source/package suite, retained Composer controls/buttons and the focused actual-package browser matrix passed. The matrix checks Hero and Footer across 13 widths at both root and nested installations: **52 rendered comparisons**, including both sides of each breakpoint, real lower-edge pixels, saved/published scenes and unchanged scene data on resize. The original 0.8.9 package fails the same aspect-ratio assertion, reproducing the reported clipping. The frozen candidate has 363 Fresh / 322 Upgrade entries, 12 changed runtime entries and 351 unchanged Fresh entries, with the approved 13/9 root directories and four root files preserved. The footer follow-up checks its own text and wrapping without attributing unrelated contact-card overflow to the scene correction.

All seven CI jobs remain required, including both PHP versions, real MySQL, foreign ownership, persistent OPcache, installed HTTP/browser suites and the added scale step. Completion still requires actual artifacts, source/CI/merge tree binding and saved-download byte verification. Use the [candidate release record](docs/releases/0.8.10-composer-geometry.md) and [Composer guide](docs/VISUAL_COMPOSER.md). Broader #9, actual-host #12/#13, production email and the separate 0.9 milestone remain open; payments remain sandbox-only. The unchanged unfinished-installer repair stays 0.8.1 / repair Build 20261020.3.
<!-- composer-geometry-0810:end -->

<!-- editorial-history-089:start -->
## Current release — 0.8.9 Editorial history and restore, Build 20261020.10

**Version 0.8.9 is verified, merged into `main` through [PR #25](https://github.com/afterburn25/velvet_vixxen/pull/25) and delivered.** Merge `ca638ef486846d37c80606d5a012f07f23299dae` completed at **2026-10-20 18:11:46 CDT (America/Chicago)** (`2026-10-20T23:11:46Z`). Release source `c07c02bd0b6957cba4e0c9d6722765e262e37157`, actual seven-job CI checkout `f22982b761358e12db2cacde64a0ce283ce7a6e2` and the merge share the complete tree `99ecb497aa99006568152c058c9b9c447625c16a`. Later completion documentation changes no executable, test, workflow or package bytes.

Existing posts, banners and landing pages now have private owner History, Review version and deliberate restore controls. Each retains its newest 20 earlier editorial states; restoration preserves current identity and always leaves a post draft or a banner/page disabled. Current references, revision and active ownership are rechecked. Read-only history does not initialize storage or record visits or email. Deletion removes attached history; calendar, explicit post expiry and deleted-item recovery remain separate.

All **seven required CI jobs and 65 configured required steps** passed in [run 39510626159](https://github.com/afterburn25/velvet_vixxen/actions/runs/39510626159). History source acceptance passed 192 assertions on PHP 8.2.34 and 8.3.35, with retained metadata 173, access 412, publication windows 134 and Marketing Storage 41. Both real-MySQL jobs passed 2292 history HTTP checks per root/nested case, alongside retained database gates. The four actual-package file/MySQL desktop/mobile history cases passed: each file case had 2028 HTTP and 404 browser checks; each MySQL case had 2253 HTTP and 428 browser checks. All 40 history screenshots decoded; a contact sheet and representative full-size captures were reviewed. No JavaScript errors or SMTP activity were observed. Existing source, security, ownership, persistent-OPcache, installer, ten-suite HTTP and browser gates remain required and passed.

Full Fresh and Upgrade carry **0.8.9 / Build 20261020.10**, with 362/321 runtime entries, the exact approved 13/9 root directories and four root files. Thirteen changed runtime entries advance timestamps; 349 unchanged Fresh entries retain theirs. Both full ZIPs, the separate manifest and unchanged installer repair matched the gated artifact literally, then matched saved/retrieved bytes at **2026-10-20 18:13:07 CDT (America/Chicago)** (exact UTC: `2026-10-20T23:13:07.846261+00:00`). Saved versions are Upgrade 13, Fresh 16, manifest 16 and unchanged repair 2. The eleven-file unfinished-installer repair remains **0.8.1 / repair Build 20261020.3**, with its original bytes and SHA-256. Installed sites use the complete Upgrade.

The bounded 0.8.9 history/restore increment is complete. The next user-directed fix is **0.8.10 Composer/public Hero and Footer geometry**: its local 52 scene/device/path comparisons passed, while its required CI, merge and delivery remain pending. The requested depth-placement and foreground-masking controls remain a separate pending increment. Broader #9 stays open for calendar, explicit post expiry, scheduled scenes, referral attribution, analytics operations, campaign preview/test-send/templates/tracking, missing notifications, additional audience predicates and provider hooks. Keep #3 open as the ongoing release policy. Actual-host #12/#13 and production email remain unverified, payments sandbox-only and the separate 0.9 milestone incomplete.

[Release and exact evidence](docs/releases/0.8.9-editorial-history.md) · [CI record](docs/releases/0.8.9-editorial-history-ci.json) · [Delivery record](docs/releases/0.8.9-editorial-history-delivery.json) · [Owner guide](docs/EDITORIAL_HISTORY.md). Every changed completed release advances its visible version; build IDs remain secondary and exact byte-identical rebuilds remain permitted. Older records below are historical.
<!-- editorial-history-089:end -->

<!-- editorial-metadata-088:start -->
## Historical release — 0.8.8 Editorial metadata, Build 20261020.9

**Version 0.8.8 is verified, merged into `main` through [PR #24](https://github.com/afterburn25/velvet_vixxen/pull/24) and delivered.** Merge `606f313a2607e5d22dbe79c2a5c893a90b261cec` completed at **2026-10-20 15:59:29 CDT (America/Chicago)** (`2026-10-20T20:59:29Z`). All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39492640091) passed. Release source `0fb8be539368802f0f8d423da5d51def69120734`, actual CI checkout `595f50f14d719cc774be373751f5760819842460` and the merge share the complete tree `8cb1e2e55b10fd64c071d5a6b7576ac86f642f8b`. Later documentation recording changes no executable, test, workflow or package bytes.

Posts gain a deliberately public author display name, comma-separated tags, an existing public featured image and alternative text, search title/description and up to six manually ordered related posts. Landing pages gain the image and search fields; banners keep their existing controls. Existing categories, IDs, permalinks, order, publication timing and Public/Members/Selected tiers access remain in place. Related content is filtered by source and target access before metadata disclosure. Saved-owner previews resolve metadata before their final active-owner check, keep related links inert and produce no marketing events or email.

All seven required CI jobs and all 62 explicitly required steps passed. The metadata source suite passed 173 assertions on PHP 8.2 and 8.3, alongside retained editorial access 412, publication windows 134 and Marketing Storage 41. Both real-MySQL jobs passed 1308 metadata HTTP checks at each root/nested path and retained 1557 editorial access checks per path, 401 marketing SQL checks and 971 packaged database-installer checks. Four actual-package file/MySQL desktop/mobile metadata browser cases passed with 20 decoded screenshots, fitting geometry, no JavaScript errors and no SMTP activity. The MySQL browser cases include four real blocked media reads followed by owner demotion/deactivation and refused preview/save requests. All existing source, security, ownership, persistent-OPcache, installer, ten-suite HTTP and browser gates remain required and passed. Exact observed runtime versions, checkout logs, actual artifacts and the local-summary recovery limitation are in the linked release/CI records.

Full Fresh and Upgrade carry **0.8.8 / Build 20261020.9** with 360/319 runtime entries, the exact approved 13/9 root directories and four root files. Nineteen changed runtime entries advance their extraction timestamps; 341 unchanged Fresh entries retain their preceding timestamps. Both full ZIPs, the separate manifest and unchanged installer repair matched the gated artifact literally, then matched saved/retrieved bytes at **2026-10-20 16:01:46 CDT (America/Chicago)** (`2026-10-20 21:01:46 UTC`). The eleven-file unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3**, 60,065 bytes, with its original SHA-256 and saved version 2. Installed sites use the complete Upgrade. Every changed completed release advances the visible version; build IDs remain secondary, and the delivery-aware gate permits exact rebuilds.

The bounded 0.8.8 editorial metadata increment of #9 is complete. The broader #9 roadmap remains open, beginning with separately scoped editorial history/restore, calendar and post expiry. Scheduled Composer scenes, durable referral attribution, analytics operations, campaign message preview/test-send, templates and tracking, missing notification workflows, additional audience predicates and provider hooks remain open. File and MySQL storage retain the same business rules without a new migration; indexed reporting and large-dataset performance are not claimed. Issue #3 remains open as the ongoing version and release-evidence policy. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox. The separate 0.9 milestone is not complete.

[Release and exact evidence](docs/releases/0.8.8-editorial-metadata.md) · [Editorial metadata guide](docs/EDITORIAL_METADATA.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- editorial-metadata-088:end -->

<!-- editorial-access-087:start -->
## Historical release — 0.8.7 Editorial access and owner previews, Build 20261020.8

**Version 0.8.7 is verified, merged into `main` through [PR #23](https://github.com/afterburn25/velvet_vixxen/pull/23) and delivered.** Merge `aa119809f0e7e7bd093ce12d07ae60383f1a451e` completed at **2026-10-20T17:57:08Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39466086276) passed. Release source `d89dbe8419c6362c8e676751e0d42facdd98f498`, actual CI checkout `b597edf820b514c951acebac810934b7388ae259` and the merge share the complete tree `c4d65acff90d5c3ca0f393f3c3fb28ac2295c75c`. Later documentation recording changes no executable, test, workflow or package bytes.

Owners can choose Public, Members or Selected tiers for saved posts, banners and landing pages. Members includes active verified free accounts; selected tiers require an eligible subscription to an exact enabled plan. Saved member, device, plan and subscription state is checked on each request, with UTC SQL subscription bounds and database-derived verification instants interpreted independently of site-local publication schedules. Timing still applies, malformed restrictions hold access, and absent legacy policies retain public behavior. Filtering precedes limits and private field disclosure. A separate saved-owner preview rechecks current ownership after stored content and tier reads, uses private caching, makes promotion links inert and records no marketing events or email.

The new source suite passed 412 assertions on PHP 8.2 and 8.3. Both real-MySQL jobs executed the packaged editorial HTTP matrix at root and nested paths (1557 checks per case), including actual blocked tier-catalogue requests followed by owner demotion or deactivation. File and MySQL desktop/mobile browser flows exercised real access edits, all three saved previews and guest/free/tier member views in America/Chicago. The tests check recently verified accounts, subscription boundaries, generic denial, private caching, unchanged queues/consent and zero SMTP messages. The retained 401-check MySQL marketing suite, 971-check packaged database installer, 141-check-per-case Marketing Storage flows and all existing source, security, ownership, persistent-OPcache, MySQL, ten-suite HTTP and browser gates passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.7 / Build 20261020.8** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 18:00:09 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The bounded 0.8.7 editorial access and saved-owner preview increment of #9 is complete, following saved audiences, publication editing and SQL marketing persistence. Editorial metadata, history/restore, calendar and post expiry remain next. Scheduled Composer scenes, durable referral attribution, analytics operations, campaign message preview/test-send, templates and tracking, missing notification workflows, additional audience predicates and provider hooks remain open. File and MySQL storage preserve the same business rules; indexed reporting and large-dataset performance are not claimed. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](docs/releases/0.8.7-editorial-access.md) · [Editorial access guide](docs/EDITORIAL_ACCESS.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- editorial-access-087:end -->

<!-- marketing-sql-086:start -->
## Historical release — 0.8.6 SQL marketing persistence, Build 20261020.7

**Version 0.8.6 is verified, merged into `main` through [PR #22](https://github.com/afterburn25/velvet_vixxen/pull/22) and delivered.** Merge `2a2bf4468c52304e08e246030d075527ab5972e3` completed at **2026-10-20T15:41:54Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39446912796) passed. Release source `e4640e84a2c5417954f936f86ee993112c67bb37`, actual CI checkout `9eace0ec2802a8ea47ddba75748521f8bbfb5168` and the merge share the complete tree `8deae90664202b37199283ec0121169291771349`. Later documentation recording changes no executable, test, workflow or package bytes.

Marketing now supports per-record MySQL persistence with a separate System → Marketing Storage owner page. Migration 015 creates two additive tables and leaves the file backend active. The current active owner previews aggregate counts, then explicitly moves the complete snapshot. The move preserves identifiers, unknown fields, entry keys/order, consent, unsubscribe tokens, suppressions, saved audiences, frozen messages, queued recipients and delivery outcomes. Transactional authority, durable local intent and database-wide data/delivery locks protect cutover and cross-root workers. The original file remains unchanged, and the move queues or sends no email. Active SQL failures hold reads and writes without reviving the archived file.

The new storage source suite passed 41 checks in each PHP job. Both real-MySQL jobs passed 401 actual-package checks covering preservation, rollback, commit-disconnect, four-writer concurrency, cross-root campaign exclusion and loopback SMTP behavior, alongside 971 existing-database installer assertions per job. The actual Fresh package passed 141 owner HTTP checks in each root-desktop/nested-mobile case, plus real browser database update, stale review, owner move and subsequent SQL-backed Marketing save. The complete existing source, security, ownership, persistent-OPcache, MySQL, installer, ten-suite HTTP, Media Center, add-on recovery, audience, publication, private-reply and browser gates remain required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.6 / Build 20261020.7** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 15:45:08 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The 0.8.6 SQL marketing persistence increment of #9 is complete, following 0.8.4 saved audiences and 0.8.5 publication editing. Broader editorial access/preview/metadata/history/calendar/post expiry, scene schedules, referral attribution, analytics operations, campaign templates/tracking, missing notification workflows and provider hooks remain open. File-mode sites retain file storage; indexed reporting and large-dataset performance are not claimed. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](docs/releases/0.8.6-marketing-sql.md) · [Marketing Storage guide](docs/MARKETING_STORAGE.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- marketing-sql-086:end -->

<!-- publication-windows-085:start -->
## Historical release — 0.8.5 Publication-window editing, Build 20261020.6

**Version 0.8.5 is verified, merged into `main` through [PR #21](https://github.com/afterburn25/velvet_vixxen/pull/21) and delivered.** Merge `9f782d4769ef31c73f21134ec2cb6ab7e29d8bd9` completed at **2026-10-20T13:33:24Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39378794728) passed. Release source `3368ffc41e63ded8c790ad1f8e4156349a4bc4ed`, actual CI checkout `32b876eed4746c383eeb001e808c8a418d30dff9` and the merge share the complete tree `1bfae06c9576f765c6f6a32d90cfd316b33e57ee`. Later documentation recording changes no executable, test, workflow or package bytes.

Owners can reopen and edit saved posts, banners and landing pages, withdraw publication, disable promotions and delete posts while preserving IDs, existing permalinks, stored order and unrelated marketing data. Locked revision checks include the site timezone. Strict date parsing rejects impossible dates and invalid windows, holds malformed legacy dates out of public visibility and preserves unchanged stored instants. Banner precedence is visible in the owner list. Publication reads do not mutate campaign queues. Private-reply email alerts contain a generic notice without the private subject or message body; the full reply remains on the site.

The new source acceptance passed 134 assertions on both PHP versions. The actual extracted customer package passed 105 owner/public HTTP assertions and root-desktop/nested-mobile browser acceptance. The mandatory private-reply regression exercises actual owner POSTs and captures loopback SMTP at root and nested paths, preserving on-site replies while excluding private content from member/guest-booking emails and keeping internal notes out of email. Saved-audience source/HTTP/browser acceptance and every existing source, security, MySQL, ownership, persistent-OPcache, installer, ten-suite HTTP, add-on recovery, Media Center and browser gate remain required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.5 / Build 20261020.6** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 13:39:23 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The saved-audience and bounded publication-editing increments of #9 are complete. Marketing SQL persistence, broader editorial/scene scheduling, referral attribution and analytics remain separate open work. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](docs/releases/0.8.5-publication-windows.md) · [Publication-window guide](docs/PUBLICATION_WINDOWS.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- publication-windows-085:end -->

<!-- newsletter-audiences-084:start -->
## Historical release — 0.8.4 Newsletter audiences, Build 20261020.5

**Version 0.8.4 is verified, merged into `main` through [PR #20](https://github.com/afterburn25/velvet_vixxen/pull/20) and delivered.** Merge `2afd71b9f4516d8c8d8200b4c1dcaf767f0cc5ea` completed at **2026-10-20T05:58:19Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39376462679) passed. Release source `b7a7559b7fe44c97d8604af24d56539bb009098b`, actual CI checkout `13b08ae2d8f8d9aa55b693cfb978abf9e6a12f70` and the merge share the complete tree `c2bd3b95d6e55f61254507e122ccc69cb9fd804f`. Later documentation recording changes no executable, test, workflow or package bytes.

Owners can save revision-checked newsletter audiences using exact signup sources and confirmation-date bounds, inspect matching recipients and select an audience for a campaign. Queueing reevaluates current eligibility, rejects a supplied stale preview and freezes audience rules, message content and recipient identities. Explicit requeue uses the frozen rules; delivery still rechecks current consent and suppression. Preview is optional and sends no email. Audience storage remains the existing protected file store.

The new source acceptance passed 149 assertions on both PHP versions. The actual extracted customer package passed 44 owner HTTP assertions and root-desktop/nested-mobile browser acceptance. Every existing source, security, MySQL, ownership, persistent-OPcache, installer, ten-suite HTTP, add-on recovery, Media Center and browser gate remains required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.4 / Build 20261020.5** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 05:59:26 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The saved-audience increment of #9 is complete. Continue 0.8.5 publication-window editing; broader #9 remains open. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](docs/releases/0.8.4-newsletter-audiences.md) · [Newsletter audience guide](docs/NEWSLETTER_AUDIENCES.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- newsletter-audiences-084:end -->

<!-- addon-recovery-083:start -->
## Historical release — 0.8.3 Add-on migration recovery, Build 20261020.4

**Version 0.8.3 is verified, merged into `main` through [PR #19](https://github.com/afterburn25/velvet_vixxen/pull/19) and delivered.** Merge `dcb229532ce55e4783b477d51bc3ffbe3f7fcfa7` completed at **2026-10-20T05:39:13Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39374591561) passed. Release source `9a21c2eb4e2032c6e81afb03092003918a63b272`, actual CI checkout `fe32a113e4465540c6a8b11730ace07240661880` and the merge share the complete tree `526c8f22fa1c79873b4d3106c48c2280c6c5755d`. Later documentation recording changes no executable, test, workflow or package bytes.

Add-on migration plans, checksums, permissions and table ownership are checked before migration SQL. Exact statement progress and independent lifecycle intent keep interrupted operations held. Previous package files remain available through registry activation. The current active owner can retry only definite recorded recovery steps after package and registry revalidation; unknown SQL/file outcomes remain held. Explicit down SQL and purge may delete data and require a database backup for restoration.

Both PHP versions passed the actual packaged MySQL lifecycle/HTTP recovery gate; the browser variant passed 197 lifecycle assertions, 34 owner HTTP assertions and 33 checks on each desktop/mobile recovery flow. All ten existing HTTP suites and every existing source, security, ownership, persistent-OPcache, installer, Media Center and browser gate remain required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.3 / Build 20261020.4** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 05:40:00 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. Add-on migration/recovery issue #7 is complete. Continue 0.8.4 saved newsletter audiences and 0.8.5 publication-window editing under broader #9. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](docs/releases/0.8.3-addon-recovery.md) · [Add-on recovery guide](docs/ADDON_RECOVERY.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- addon-recovery-083:end -->

<!-- media-center-082:start -->
## Historical release — 0.8.2 Media Center, Build 20261020.3

**Version 0.8.2 is verified, merged into `main` through [PR #18](https://github.com/afterburn25/velvet_vixxen/pull/18) and delivered.** The merge is `e1dc2069ab62b9399a968dfd86602a5c30b9fcaf` at **2026-10-20T04:58:45Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39370771018) passed. Release source `91d5cee9d7625d4f0eaf111f5923695ae681fa58`, actual CI checkout `7cd686fad6a49cd794d7e566854faf28d414cb98` and the merge share the complete tree `e2e4a338593c8e78cd86d433bed262ee743fcb02`. Later documentation recording changes no executable, test, workflow or package bytes.

Gallery membership, order, captions and shared tags now persist exactly, including removals and mobile ordering. Playlist order and scheduling dates survive edits; stale forms are rejected. Bounded due publication preserves access and dates, and public playlists retain entitlement checks. Ordinary media edits preserve existing membership-tier rules. The full source/security/ownership/OPcache/package matrix, ten HTTP suites and required browser acceptance passed, with 116 file, 108 real-MySQL and 198 Media Center HTTP assertions. All four actual Fresh-package Media Center cases passed with file storage/MySQL at root desktop and nested mobile; the existing packaged database installer passed 957 assertions on each PHP version.

The three ZIPs and separate manifest matched GitHub's gated artifact literally, then matched the saved/retrieved files at **2026-10-20 04:59:42 UTC**. Full Fresh and Upgrade carry **0.8.2 / Build 20261020.3**, with the approved 13/9 root directories and four root files. The separate unfinished-installer repair targets incomplete **0.8.1** setup and does not advance its runtime build. Installed sites use the complete Upgrade. The former focused Media/Composer overlay is retired.

The user-directed version rule remains permanent: advance the visible version for every completed release and keep build IDs secondary. Media issue **#2 is complete**. Continue addon-owned MySQL migration/recovery **#7** as **0.8.3**, then newsletter audience controls within **#9** as **0.8.4**, each with its own gates and delivery evidence. Broader marketing, actual-host **#12/#13** and production email remain unverified; payments stay sandbox-only. The separate 0.9 milestone is not claimed.

[Release and exact evidence](docs/releases/0.8.2-media-center.md) · [Media Center owner guide](docs/MEDIA_CENTER.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older records below remain historical.
<!-- media-center-082:end -->

<!-- owner-mail-settings:start -->
## Historical release — owner Email & SMTP, Build 20261020.2

The owner can configure a shared email connection from **System → Email & SMTP**, retain or clear a saved password, and test the saved settings with one message to the current owner. Saving sends nothing. Runtime and installer sender validation reject unsafe headers and use a valid explicit From, then an email-shaped SMTP username, then the owner fallback. Read [the owner guide](docs/EMAIL_SETTINGS.md) and [release evidence](docs/releases/0.8.1-owner-mail-settings.md).

**Verified, merged into `main` through [PR #17](https://github.com/afterburn25/velvet_vixxen/pull/17), and delivered.** All seven jobs in [CI run 39361581062](https://github.com/afterburn25/velvet_vixxen/actions/runs/39361581062) passed against the identical release source/checkout/merge tree. All four ZIPs and the separate manifest were saved and retrieved again with exact byte equality to the gated artifact at **2026-10-20 03:11:47 UTC**. [CI](docs/releases/0.8.1-owner-mail-settings-ci.json) and [delivery](docs/releases/0.8.1-owner-mail-settings-delivery.json) records include the exact identities and hashes.

Compatibility stays **0.8.1**. All four ZIP names include Build 20261020.2 and preserve the approved roots. Complete Upgrade is primary for installed sites; the 11-file installer-only repair uses `repair_build_id` without advancing their runtime build. Sender issue **#10 is closed**. Next is media **#2**, then **#7** and remaining **#9**. Actual-host **#12/#13** and production email remain unverified; payments remain sandbox-only. See [Update Verification](docs/UPDATE_VERIFICATION.md) and [the roadmap](docs/ROADMAP.md).
<!-- owner-mail-settings:end -->

## Historical verified release — Build 20261020.1

**Build 20261020.1 was merged into `main` through [PR #16](https://github.com/afterburn25/velvet_vixxen/pull/16).** The requested third button inside Live Text + Buttons and bounded campaign delivery are implemented. All seven [required CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39352867755) passed, and the saved customer downloads match the gated artifact byte for byte. Read [the owner workflow and evidence](docs/releases/0.8.1-group-buttons-campaign-delivery.md). This evidence certifies that earlier build; it does not replace the new build's required gates.

## Historical organized-root repair — Build 20261019.6

**Build 20261019.5 is rejected:** its routing repair reintroduced 25 root folders and 46 forwarding PHP files contrary to the approved layout. Build **20261019.6** restores the exact original 13 Fresh / nine Upgrade root directories and four root files. Generated page/API URLs use the physical `route.php`; protected implementations remain under `public/routes`. The new owner page, `admin/repair.php`, verifies the uploaded runtime, refreshes this site's PHP and retires only exact known old forwarding files and empty folders while preserving custom items and installed data.

The correction is uploaded in [source commit e58f9edd](https://github.com/afterburn25/velvet_vixxen/commit/e58f9edd708fd42dee0e6c898170e67eb4a01a53). All seven jobs in [the final CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39328060458) passed against the identical complete source tree, including PHP 8.2/8.3, MySQL 8, actual rejected-package cleanup and desktop/mobile acceptance. The gated packages and all four saved replacement downloads were compared literally and match; saved readback was verified 2026-10-19 22:36:25 UTC. Read [the update instructions](docs/UPDATE_VERIFICATION.md) and [current test/delivery status](docs/TEST_REPORT.md). Earlier repair-run results are historical and did not enforce the approved folder requirement.

The repair retains working image uploads, Full Page Preview, publication and separately editable live button layers. The previous installer database/layout correction below remains included in the full distribution. The affected customer host has not been accessed or verified.

## 0.8.1 installer database and layout repair

For the already-uploaded unfinished installer, use [Installer Database Repair](docs/INSTALLER_DATABASE_REPAIR.md). Setup now preserves and continues compatible existing databases, including previous attempts with partial tables or missing migration history. Existing owners authenticate with their current login; missing setup work completes without resetting their data, keys, scenes or settings. Server/Folders checks and the owner password fields are aligned, and completed setup clears its loading state.

The unchanged unfinished-installer repair remains **0.8.1 / repair Build 20261020.3** and contains 11 runtime files, including the database/folder and strict sender corrections. Its `repair_build_id` does not change an installed runtime build. Use [Installer Database Repair](docs/INSTALLER_DATABASE_REPAIR.md) for unfinished 0.8.1 setup and the complete current **0.8.9 / Build 20261020.10 Upgrade** for installed sites. The current release record supplies exact delivery evidence; the original eight-file and earlier sender-release records remain historical.

## Historical 0.8.1 foundation — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise

This tree now contains the executable application, database migrations, public assets, recovered engineering tests, package builder, and required GitHub Actions checks. It was recovered from the checksum-verified **0.8.1** fresh-install package, then reconciled with the separate **0.7.4** Visual Composer fixes. Application source is no longer deferred to a future import.

The continuation adds the expanded Visual Composer, 30 standalone luxury scenes, Booking Form Builder, About Me and owner editor, shared sticky public navigation, centered account pages, persistent admin categories, merchandise/stock/shipping/fulfillment, direct PPV checkout, and safe installed database updates. The repair also preserves installation identity and backups and produces reproducible fresh-install/upgrade distributions. The recovered baseline's engineering gate passed on runtime commit `ca52793` in [GitHub run 39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477); that historical result does not certify later repairs. Current source, execution, artifacts and fresh/upgrade package hashes are recorded in [`docs/TEST_REPORT.md`](docs/TEST_REPORT.md) and [`RELEASE_MANIFEST.txt`](RELEASE_MANIFEST.txt).

Historical package/handoff claims were not treated as proof of implementation. [`docs/SOURCE_RECOVERY.md`](docs/SOURCE_RECOVERY.md) records provenance and features that remain unfinished in the recovered code.

## Start here

- [`PROJECT_STATUS.md`](PROJECT_STATUS.md): implemented work and remaining limitations.
- [`SESSION_HANDOFF.md`](SESSION_HANDOFF.md): continuation point, branch, commands, and outstanding work.
- [`docs/ROADMAP.md`](docs/ROADMAP.md): evidence-based roadmap.
- [`docs/DECISIONS.md`](docs/DECISIONS.md): product and architecture requirements.
- [`docs/TESTING.md`](docs/TESTING.md): executable validation commands.
- [`docs/EMAIL_SETTINGS.md`](docs/EMAIL_SETTINGS.md): owner email connection, sender, password and test workflow.
- [`docs/VISUAL_COMPOSER.md`](docs/VISUAL_COMPOSER.md), [`docs/BOOKING_BUILDER.md`](docs/BOOKING_BUILDER.md), [`docs/MERCHANDISE.md`](docs/MERCHANDISE.md): owner workflows.
- [`docs/UPGRADING.md`](docs/UPGRADING.md): update existing installations safely.
- [`docs/CANVA_WORKFLOW.md`](docs/CANVA_WORKFLOW.md): manual artwork export/import.
- [`AGENTS.md`](AGENTS.md): source-control and handoff requirements.

## Develop and verify

```bash
bash scripts/test.sh
bash scripts/test_http.sh
```

Tests run in disposable copies. Never generate a demonstration installation in a real site or the working source tree. Database and browser commands are described in the testing guide.

Build customer packages from the same source:

```bash
python3 scripts/package.py --output artifacts/runtime
```

Fresh-install ZIPs include the installer and blank example configuration. They never contain an installed `config/app.php`. Upgrade ZIPs preserve configuration, installation locks, storage, uploads, sessions, private media, and keys, and exclude `/install`. Use the package named for the intended operation.

Customer ZIPs contain runtime files only. Development documentation, tests, SDK source, and GitHub workflows remain in this repository.

## Operating limits

The payment adapter is sandbox-only. Marketing supports protected file storage or per-record MySQL storage after the explicit owner-reviewed move in 0.8.6, with consent-aware queues and guarded dispatch through the configured mail transport. Editorial access and private saved-owner previews are delivered in 0.8.7; post tags/bylines/related selections and post/landing-page featured images and SEO controls are delivered in 0.8.8. Private editorial history/review and deliberate draft/disabled restoration are delivered in 0.8.9. Broader #9 remains open, starting with separately scoped calendar and explicit post expiry; additional audience criteria, scene scheduling, attribution, analytics operations/retention and provider bounce handling also remain incomplete. SQL storage still reconstructs a full snapshot and does not establish large-dataset reporting performance. Production email-provider delivery and representative shared-hosting installation/upgrade certification remain unverified; local and CI fixtures do not establish deployment on the affected customer's host. The application is not labelled a production-certified 1.0 release.

Never commit installed configuration, credentials, private customer/member/booking data, private messages, identity documents, generated keys, or private/paid media.
