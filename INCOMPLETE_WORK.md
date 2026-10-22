<!-- purchased-terms-092-candidate:start -->
## Current development — 0.9.2 purchased terms, Build 20261022.2

**Implemented candidate; release acceptance pending.** Work starts from verified live `main` `8d7ccce01d3a2db599b9c9955b10e0a9a115c0d7` on `feature/0.9.2-purchased-terms`. Version 0.9.1 remains the latest completed customer delivery. No 0.9.2 merge, saved customer delivery, production payment or customer-host acceptance is claimed here.

New membership and bundle orders capture strictly validated, canonically hashed commercial terms before payment dispatch. Catalogue writers share the commerce lock. Fulfillment and delayed recovery consume the original snapshot; deleted bundle definitions do not substitute contents, and missing content or invalid new terms hold the atomic local application. Subscription display, commercial benefits, PPV discounts, sandbox renewal prices/periods and safe own-data exports use the original order item through its protected subscription binding. Checkout displays the terms and refuses a stale review before creating an order. Historical records are explicitly legacy, without automatic backfill; unfulfilled legacy memberships/bundles with insufficient original terms remain held. Current access, protection, refund, expiry and privacy rules still apply.

The storage change uses existing order-item metadata and the existing fulfillment subscription ID as its durable reverse reference on both backends; no migration or historical schema byte changes. The single-page installer and approved 13/9 runtime roots are preserved. Photo depth, separate buttons, Composer geometry, upload, preview, publishing and organized dispatch remain in the retained acceptance matrix. The separate 0.8.1 installer-only repair remains byte-identical.

Local evidence currently includes all 67 source/module suites, the focused purchased-terms file suite, and strict reproducible Fresh/Upgrade packaging. Actual packaged file HTTP checkout/recovery/billing passes at root and nested paths. Database and final changed-source results are recorded in the candidate evidence as they finish. The complete workflow, actual desktop/mobile/no-script browser evidence, reviewed artifacts, guarded merge and saved customer-file readback remain required. The local sandbox denies Chromium's required Unix sockets and foreign-owner fixture operations; no passing result is substituted for those gates.

The full recurring agreement/cycle/cancel/resume increment and approved live provider/merchant acceptance remain separate, along with configured mail/age services, actual-host #12/#13 and owner policy/retention decisions. [Candidate guide](docs/PURCHASED_TERMS.md) describes exact behavior, legacy limits and remaining acceptance.
<!-- purchased-terms-092-candidate:end -->

<!-- payment-recovery-091-release:start -->
## Current release — 0.9.1 payment recovery, Build 20261022.1

**Verified, merged and delivered.** [PR #36](https://github.com/afterburn25/velvet_vixxen/pull/36) completed the corrected source `2b66fc3c6b3409cf94086df6430ee060b24ce0ea`. All 28 required jobs passed; 293 configured steps succeeded, with two intentional member-only omissions in the age-policy lanes and no unexpected skips. Final packaging passed. The downloaded gated runtime artifact and all four independently retrieved saved customer files match literally. Saved readback completed at `2026-10-22T17:13:00Z`.

Durable payment operations and bound event/effect journals, atomic local financial and access updates, held uncertain outcomes, separate recoverable follow-ups, and **Admin → Commerce → Payment recovery** are delivered. Recovery checks the original operation without submitting another charge or refund; reads remain inert. Current-owner, recent-authentication, CSRF/revision and commerce-before-member lock protections remain required.

[CI run 39792164645](https://github.com/afterburn25/velvet_vixxen/actions/runs/39792164645) used checkout `20914e10283042cae7848faa39beea5532fc9dfe`; source, checkout and merge share tree `177cb521597517f9257ee390e04d0993d85dca9d`. Merge and live-main readback are `61cf2d5aacc180834e2280aab9bd1f23493f621f`.

The accepted effective matrix uses GitHub job records from attempts 2; their actual executions are from attempts 1, 2 on the same frozen source and workflow. GitHub may assign new retry job IDs to carried-forward successes; each record retains its original execution ID, attempt, timestamps and matching steps separately. Earlier canceled/failed attempt snapshots remain historical and are not relabeled successful. No post-merge CI result is inferred from this pull-request workflow.

**Next offline work:** authoritative recurring agreements, immutable membership/bundle purchase snapshots, stable billing-cycle identities, durable cancellation/resumption commands and an idempotent renewal reducer. Follow the recurring lifecycle plan from verified live main in a separate branch; this documentation does not implement that next increment. Advance the visible version for its next changed completed delivery.

Preserve the approved magenta/black design, resolved Photo depth and foreground behavior, physical `admin/repair-current.php`, complete Upgrade, approved Fresh/Upgrade 13/9 root directories and only `.htaccess`, `VERSION`, `index.php` and `route.php` as root files. Preserve owner identity, configuration, keys, data and uploads. Move ownership-repair copies through **System → Repair Backups → Move Root Backups to Private Storage**; delete only a freshly verified duplicate through the owner tool. The separate 0.8.1 / repair Build 20261020.3 overlay is unchanged.

Only Sandbox Gateway is registered. A selected approved merchant provider still needs its actual adapter, protocol mapping and approved live lifecycle acceptance. Configured production mail/SendGrid and age services, customer-host install/upgrade/rollback, running-build and protected-origin/CDN checks, trusted geographic headers, cron, external backup key/restore, and owner policy/retention decisions remain separate. Account closure does not prove external billing cancellation or deletion of independent backups. Keep #3 as ongoing release policy and actual-host #12/#13 open without host evidence.

[Release record](docs/releases/0.9.1-payment-recovery.md) · [Final CI evidence](docs/releases/0.9.1-payment-recovery-ci.json) · [Saved-delivery evidence](docs/releases/0.9.1-payment-recovery-delivery.json) · [Correction evidence](docs/releases/0.9.1-ci-corrections.md) · [Recurring lifecycle plan](docs/RECURRING_LIFECYCLE_PLAN.md)

Earlier blocks below preserve their historical release/candidate state and then-current remaining-work lists. This completed release and the current continuation target supersede those status statements.
<!-- payment-recovery-091-release:end -->

<!-- payment-recovery-091-candidate-history:start -->
## Historical 0.9.1 candidate and initial correction checkpoint

**Historical scope:** The pending-release and in-progress statements below describe the earlier checkpoint; final acceptance is recorded above.

**0.9.0 remains the latest completed delivery.** The separate **0.9.1 / Build 20261022.1 candidate** implements durable payment operations, strict provider/result binding, atomic financial effects, held uncertain outcomes, separate recoverable follow-ups and **Admin → Commerce → Payment recovery**. First-charge dispatch and privacy closure share the commerce lock; current owner and recent authentication are rechecked throughout refunds and recovery.

Final local acceptance binds implementation `6c06bf4f92e81b1f06b7b507c2c13e3583b0d8c6` to Fresh SHA-256 `27a4bc4b084f0bff4187744704c043d32a8f2891f3eca96da4c7c1473432754d`: file/MySQL/MariaDB source checks passed, along with 1,600 actual-package HTTP checks, 1,740 browser checks and 72 independently decoded screenshots. The candidate workflow preserves every prior gate and adds required payment lanes: 28 jobs / 295 configured step instances. Full GitHub CI, actual artifacts, merge and saved-download readback are still required. Follow the [candidate record and local evidence](docs/releases/0.9.1-payment-recovery.md) for exact source identities, counts and preserved intermediate outcomes.

Continue the separately scoped recurring agreement/cycle/command engineering while this candidate completes release acceptance. A selected processor, its real adapter and live acceptance, production mail/age services, actual-host installation/upgrade/rollback/restore/workers and owner policy decisions remain necessary for production 1.0. Existing Photo depth and ownership-repair backup protections remain unchanged.
**Continuation checkpoint, 2026-10-22:** the first PR #36 run exposed three retained-fixture mismatches (payment inventory, old package identity and missing-to-empty journal initialization). The complete local source/package aggregate and focused negative controls now pass; full corrected CI and complete cached-upgrade browser acceptance remain pending. Runtime/package bytes are unchanged. See [the correction evidence](docs/releases/0.9.1-ci-corrections.md).

<!-- payment-recovery-091-candidate-history:end -->

# Acceptance Record — Velvet Vixxen

<!-- road-to-1.0-integration:start -->
## Historical 0.9.0 release and continuation checkpoint

**Historical scope:** The following block preserves the completed 0.9.0 evidence and its then-pending 0.9.1 continuation. The accepted 0.9.1 block above and current continuation sections now govern.

**Verified, merged and delivered.** **0.9.0 / Build 20261021.4** passed the complete frozen workflow, merged through [PR #35](https://github.com/afterburn25/velvet_vixxen/pull/35), and has byte-verified saved customer downloads. The [release record](docs/releases/0.9.0-safeguards.md), [CI evidence](docs/releases/0.9.0-safeguards-ci.json) and [delivery evidence](docs/releases/0.9.0-safeguards-delivery.json) bind the exact source, packages, merge and readback. Customer-host and live-provider acceptance remain separate.

The 0.9 implementation includes administrator two-factor security and durable sessions; atomic private state and configuration; deleted editorial recovery; rendered campaign previews, test sends and branded messages; durable referral, campaign and coupon attribution; business audiences and consent-aware analytics, retention, reports, exports and funnels; optional notification workflows; authenticated mail-provider event recovery; encrypted backup and isolated restore; protected-media records and reports; age assurance, trusted geographic rules and five independently publishable policy pages; and member-owned exports with reviewed, resumable account closure. Preserve the desktop/mobile accessibility, privacy contrast, current-owner and process-interruption protections and the physical `admin/repair-current.php` path for older installations with cached PHP. The final release identity and acceptance record is [the current release evidence](PROJECT_STATUS.md); the implementation and operating guides are in [the integration record](docs/ROAD_TO_1_0.md).

The next milestone is **1.0 payment engineering and production acceptance**. Shared payment-operation journals, interruption recovery and owner recovery controls are being developed in a separate **0.9.1 / Build 20261022.1 candidate**, which requires its own complete CI, artifact, merge and saved-delivery acceptance. Payments in 0.9.0 remain sandbox-only. Selecting an approved merchant provider must be followed by implementing its real adapter and authoritative recurring lifecycle, then validating approved live outcomes. Existing mail, SendGrid feedback and Yoti age implementations still need configured accounts and approved live-service acceptance. Actual customer-host installation, upgrade, rollback, protected-origin/CDN behavior, cron, backup/restore, running-build checks and owner policy/retention decisions remain separate. Continue available engineering while those external inputs are arranged.

**Owner-confirmed resolution:** Photo depth is available after updating the installed version. Preserve that control and its foreground behavior; do not restart the missing-control investigation. Earlier release blocks retain their historical evidence and then-current “remaining” lists; this current scope supersedes those lists, without changing their original claims.
<!-- road-to-1.0-integration:end -->

<!-- scene-schedules-0814:start -->
## Current release — 0.8.14 Scheduled Composer scenes, Build 20261021.3

**Verified, merged and delivered.** [PR #33](https://github.com/afterburn25/velvet_vixxen/pull/33) completes [issue #32](https://github.com/afterburn25/velvet_vixxen/issues/32), the bounded scheduled-Composer-scenes increment of broader #9. Merge completed at `2026-10-21T17:07:02Z`; literal saved-download read-back completed at `2026-10-21T17:09:54.040414+00:00`. Earlier release blocks preserve historical evidence and their then-current continuation notes; their older “current release” labels do not override this release or a later verified main merge.

Owners open **Site & Design → Scene schedules** to schedule a fixed saved Homepage Hero or Visual Footer draft in the site timezone. Optional end dates explicitly keep the scheduled scene live or restore its preceding publication. **Schedule** in Composer first saves both drafts and opens the selected section. One open schedule per section, fresh-owner checks, stale-form guards, cancellation, durable processing claims and bounded recovery protect automatic changes. **Run due scenes now** or the command-line runner executes due work; ordinary editor/calendar reads do not. Automatic changes preserve newer drafts, manual publication supersedes pending automatic work, and the publishing calendar shows saved schedule dates and outcomes. No new database migration is required.

All **7 required jobs and 82 configured required steps** passed in [CI run 39632889974](https://github.com/afterburn25/velvet_vixxen/actions/runs/39632889974), retaining the prior 78 required steps. Source `e339a230757ad9fb7529bf0c1fc73e9f60af446a`, actual CI checkout `16a7a2567d5091d001f4247be4c09449ce281cb3` and final merge `f8c3ae2e2278049ad5480e69498013832e9063b7` share complete Git tree `4d293926da098c147bca81eaee8f47a2b2f0f89d`. Complete Fresh/Upgrade packages contain **385 / 344 runtime entries**. All four saved customer files match the gated artifact by literal bytes, size and SHA-256. Approved package roots and installed configuration, storage, uploads, owner identity and keys are preserved; the separate unfinished-installer repair remains byte-identical at **0.8.1 / repair Build 20261020.3**. These proofs do not establish an affected customer-host update or a configured hosting cron job.

Resume from live `main` and the latest verified release record, checking for a later completed release first. Preserve the delivered history/restore, Composer/public geometry, compact footer, Menus, photo depth/foreground, publishing calendar, post expiration and scene scheduling. Keep broader **#9** open for deleted-item recovery; campaign-content preview/test-send, branded templates and link/conversion/coupon attribution; additional consent-aware membership, purchase and referral audiences; missing new-content/renewal/payment-failure/abandoned-registration notices; durable registration/checkout referral attribution; analytics privacy controls, retention, reports/rollups/exports and funnels; and authenticated mail-provider bounce/complaint handling. Keep **#3** open as the continuing release-evidence policy. Actual-host **#12/#13** and production email remain unverified; payments remain sandbox-only and the separate **0.9 milestone remains incomplete**. Every changed completed delivery advances the visible version and requires its own source, CI, artifact, merge and saved-download evidence.

[Owner guide](docs/SCENE_SCHEDULES.md) · [Release record](docs/releases/0.8.14-scene-schedules.md) · [CI evidence](docs/releases/0.8.14-scene-schedules-ci.json) · [Saved-delivery evidence](docs/releases/0.8.14-scene-schedules-delivery.json)
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

[Calendar guide](docs/EDITORIAL_CALENDAR.md) · [Release record](docs/releases/0.8.13-editorial-calendar.md) · [CI evidence](docs/releases/0.8.13-editorial-calendar-ci.json) · [Saved-delivery evidence](docs/releases/0.8.13-editorial-calendar-delivery.json)
<!-- editorial-calendar-0813:end -->

<!-- composer-depth-0812:start -->
## Current release — 0.8.12 Composer depth and foreground furniture, Build 20261021.1

**Verified, merged and delivered.** [PR #29](https://github.com/afterburn25/velvet_vixxen/pull/29) addresses [issue #28](https://github.com/afterburn25/velvet_vixxen/issues/28). The final release merged on `2026-10-21T06:51:53Z`; literal saved-download read-back completed on `2026-10-21T06:55:05.291536+00:00`. Earlier release blocks remain historical records with their then-current continuation notes.

Photo layers now have independent **Depth in scene** and **Place behind foreground** controls. A per-device room depth point directs the photo farther into the room, while a drawn outline of the bed or furniture can cover selected photos. Hero and Visual Footer share the same projection and source-image mask geometry in Composer and on the public page. The current design, independent photos, separate buttons, device layouts, prior scale/footer/menu behavior and existing history workflow are retained.

The 0.8.12 implementation, required CI, actual artifact review, merge and saved-download verification are complete. Issue #28 is no longer pending release acceptance. Actual installation and owner-artwork checks on the affected host remain outside this verified repository delivery.

The final [required CI run 39553690388](https://github.com/afterburn25/velvet_vixxen/actions/runs/39553690388) passed **all seven required jobs and 74 configured required steps**, retaining the prior 70 steps in order. The actual checkout in all seven jobs was `c18a80d79e9f5559bdfb2d43f25f3065dfb93c06`. Source `a52747b1b20d8530b328255af740d72821eab70f`, that checkout, and final merge `e7b6938162b15a0371baca97fbf195c14acae569` have the identical complete Git tree `c09486d5f46021d7e021ed67a8fbb3604c42b43c`. The downloaded runtime and browser artifacts are bound to that source/run; all four saved customer files were read back and match the gated artifact by literal bytes, size and SHA-256. This evidence does not claim a separate post-merge CI run.

The source suite passed in both required PHP 8.2/8.3 jobs, including **215 scene-depth assertions**, **55 runtime-inventory checks** and **692 root-layout assertions**. Actual-package depth HTTP acceptance passed in file: **1,954 checks** over root/nested installations; mysql_php83: **1,954 checks** over root/nested installations; mysql_php82: **1,954 checks** over root/nested installations. The four file/MySQL root/nested browser cases passed **3,460 assertions**, **48 editor/preview/public scene comparisons** and **4,608 RGBA pixel samples** with maximum channel error **1/255**. This includes covered/exposed/front regions and horizontal/vertical polygon boundaries (maximum edge error **0/255**), both background fits and transformed/filtered backgrounds. Real controls exercise drawing, numeric and direct point edits, invalid shape refusal, depth-aware movement, reset, undo/redo, lock/hide/copy, save/reload, preview, publish and history restore. Retained scale acceptance passed **52 scene comparisons / 24 legal-footer layouts**, alongside the required existing menus, footer-placement, application, ownership, OPcache, PHP 8.2/8.3, MySQL and package gates.

Keep #3 open as the continuing release-evidence policy. Broader #9 calendar, explicit post expiry and other marketing work remain separate. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only and the separate 0.9 milestone remains incomplete. This repository release and saved delivery do not establish installation or behavior on the affected customer host.

[0.8.12 release record](docs/releases/0.8.12-composer-depth.md) · [CI evidence](docs/releases/0.8.12-composer-depth-ci.json) · [saved-delivery evidence](docs/releases/0.8.12-composer-depth-delivery.json)
<!-- composer-depth-0812:end -->

<!-- navigation-menus-0811:start -->
## Current release — 0.8.11 Editable menus and public page layout, Build 20261020.12

**Version 0.8.11 is verified, merged into `main` through [PR #27](https://github.com/afterburn25/velvet_vixxen/pull/27) and delivered.** Merge `6d7cfee8936a30c1b1acff47ca55c9e91d9e644b` completed at **2026-10-20 20:48:45 CDT (America/Chicago)** (`2026-10-21T01:48:45Z`). Source `d2b87559d9bccb3c6a3a3ac1fa732f9dd364f84c`, actual seven-job CI checkout `ef343c9963e952c4230965c6cd37b01daa9da4bf` and final merge `6d7cfee8936a30c1b1acff47ca55c9e91d9e644b` share complete tree `27d08c36c4b160a05753ae055b70934646e990ce`. Completion documentation changes no executable, test, workflow or package bytes.

Owners use **Site & Design → Menus** to edit Header and Footer independently. Add, edit, reorder, hide/remove, choose a parent or add submenus, then save. Each location supports 50 items and three levels. Per-link opening choices are Same tab, New tab and New window; the last requests a window subject to browser settings. Saved empty menus and deliberate default restoration are supported. Account/cart, legal links, branding and the blue Cajun Web Pro new-tab credit remain separate. Fitting public pages keep the footer at the bottom of the available viewport without unnecessary document scrolling. Longer pages retain normal scrolling, and the footer remains above a visible player with its measured height plus 8px clearance. Hidden or omitted players reserve no player-height gap; expanded footer submenus grow in normal flow.

Public menus use ordinary links, semantic nested lists and labelled disclosures, with keyboard/touch operation, Escape/focus return and bounded scrolling for long menus. Safe internal destinations retain nested-install paths. File/MySQL writes recheck current saved ownership and compare per-location revisions inside the mutation lock/transaction. Validation rejects unsafe destinations and malformed trees as a whole. Rejected submissions retain attempted edits and their original revision; deliberate reload or successful save resolves the unsaved state. No new migration is needed.

All **seven required CI jobs and 70 configured required steps** passed in [run 39524928930](https://github.com/afterburn25/velvet_vixxen/actions/runs/39524928930). The actual CI menu HTTP commands passed with these observed totals: **file 441 checks in the browser job; MySQL 506 checks in PHP 8.2 and 506 checks in PHP 8.3**. The separate final frozen local combined HTTP matrix passed **945 checks**. The downloaded menu browser artifact verifies **four file/MySQL root/nested cases**, **72 public layouts**, **28 real tab/window/native-modifier clicks**, **four script-free fallbacks** and **44 decoded PNGs**, including phone editing, retained rejected drafts, keyboard/touch submenus, 50-item menus, empty lists and default resets. Its separate footer-placement matrix verifies **88 observations**: **56 fitting pages without document scrolling**, **32 long pages with necessary scrolling**, visible/hidden/omitted players and **16 additional decoded PNGs**. The retained Composer artifact independently verifies **52 scene comparisons**, **24 footer layouts**, **eight new-tab clicks**, **eight empty-catalogue observations** and **34 decoded PNGs**. Root reviewed three menu, two footer-placement and four Composer contact sheets plus representative full-size captures. Existing source, MySQL, ownership, persistent OPcache, installer, HTTP/browser and package gates remain required and passed.

Full Fresh/Upgrade carry **0.8.11 / Build 20261020.12**, with **373/332 runtime entries**, **23 changed entries including ten new**, **350 unchanged Fresh entries**, no removals and the approved 13/9 root directories and four root files. Both ZIPs, the separate manifest and unchanged installer repair match the actual gated artifact and saved/retrieved bytes literally. Read-back completed at **2026-10-20 20:55:10 CDT (America/Chicago)** (exact UTC: `2026-10-21T01:55:10.007674+00:00`). Saved versions are Upgrade 15, Fresh 18, manifest 18 and unchanged repair 2. The separate eleven-file unfinished-installer repair remains **0.8.1 / repair Build 20261020.3**, with its original bytes and SHA-256. Installed sites use the complete Upgrade.

The requested menus and public page layout increment is complete. Dedicated Composer depth placement and foreground masking remain separately pending, along with broader #9 calendar, explicit post expiry and the other documented marketing work. Actual-host #12/#13 and production email remain unverified, payments remain sandbox-only, and the separate 0.9 milestone remains incomplete. Keep #3 open as the ongoing release policy. Any further changed delivery needs a new visible version and its own source, CI, artifact, merge and saved-download evidence. The preceding 0.8.10 Composer scale and compact-footer corrections remain included. Repository and download verification do not establish an update to the affected customer host.

[Release and exact evidence](docs/releases/0.8.11-menus.md) · [CI record](docs/releases/0.8.11-menus-ci.json) · [Delivery record](docs/releases/0.8.11-menus-delivery.json) · [Menus guide](docs/MENUS.md) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md). Older release blocks retain their historical facts and then-pending continuations.
<!-- navigation-menus-0811:end -->

<!-- composer-geometry-0810:start -->
## Historical release — 0.8.10 Scene scale and compact footer, Build 20261020.11

**Version 0.8.10 is verified, merged into `main` through [PR #26](https://github.com/afterburn25/velvet_vixxen/pull/26) and delivered.** Merge `a98847d032a50eabfbd117ade9cdce63de5c25c3` completed at **2026-10-20 19:23:10 CDT (America/Chicago)** (`2026-10-21T00:23:10Z`). Release source `3357cb048a25d134f1702a48113ecde9025c16ed`, actual seven-job CI checkout `1055db0049e6825bddf284e460bbdd45ce64c268` and the merge share the complete tree `6dbfc18ffd1aded1015b5691559b6acc0870ab3c`. Later completion documentation changes no executable, test, workflow or package bytes.

Composer and the public Hero/Visual Footer share the 1440/900/390-pixel desktop/tablet/mobile design canvases. Public height equals saved design height multiplied by available width/design width. Background crop, photos, live content, standalone buttons and pixel effects scale together, preserving saved scene data and intentional crops. Existing scenes need no resave or migration; navigation remains outside the scene. The pink live-content box remains a positioning guide, with actual copy shown by Full Page Preview.

The bottom footer has larger type, 16px navigation/legal/credit text, compact content-driven height, no minimum-height reservation, 8px vertical padding and 4px row gaps. The blue underlined Cajun Web Pro LLC credit links to `https://cajunwebpro.com/` in a new tab. Homepage/common renderers share these rules. The page reserves the visible fixed audio player's measured height plus 8px below content, keeping the credit reachable; a hidden player reserves no space.

All **seven required CI jobs and 66 configured required steps** passed in [run 39517748806](https://github.com/afterburn25/velvet_vixxen/actions/runs/39517748806), including both PHP versions, real MySQL, foreign ownership, persistent OPcache, installed HTTP/browser acceptance and the new focused scale step. The downloaded browser artifact verifies **52 scene comparisons**, **24 footer layouts**, **eight real new-tab clicks**, **eight empty-catalogue observations** and **34 decoded PNGs**. All four contact sheets and representative full-size scene/footer captures were reviewed. The retained local original-package negative control reproduces the clipping. Existing Composer controls/buttons and the other release gates remain required and passed.

Full Fresh and Upgrade carry **0.8.10 / Build 20261020.11**, with 363/322 runtime entries, 12 changed entries, 351 unchanged Fresh entries and no removals. The approved 13/9 root directories and four root files are preserved. Both full ZIPs, the separate manifest and unchanged installer repair matched the actual gated artifact literally, then matched saved/retrieved bytes at **2026-10-20 19:25:31 CDT (America/Chicago)** (exact UTC: `2026-10-21T00:25:31.617514+00:00`). Saved versions are Upgrade 14, Fresh 17, manifest 17 and unchanged repair 2. The separate eleven-file unfinished-installer repair remains **0.8.1 / repair Build 20261020.3**, with its original bytes and SHA-256. Installed sites use the complete Upgrade.

The newest user-directed increment is **0.8.11 Menus**, on `feature/0.8.11-menu-editor`: owner controls to edit, add, remove and reorder menu items, add submenus, and choose same-tab, new-tab or requested new-window navigation. It is a separate candidate in progress, not a completed or delivered release. Its own required CI, merge and saved-download evidence remain pending; 0.8.10 acceptance does not certify changed 0.8.11 bytes.

Foreground masking and new depth controls remain separately pending. Broader #9, actual-host #12/#13, production email and the separate 0.9 milestone remain open; payments remain sandbox-only. Keep #3 open as the ongoing release policy. Release verification does not establish an update to the affected customer host.

[Release and exact evidence](docs/releases/0.8.10-composer-geometry.md) · [CI record](docs/releases/0.8.10-composer-geometry-ci.json) · [Delivery record](docs/releases/0.8.10-composer-geometry-delivery.json) · [Apply and verify the Upgrade](docs/UPDATE_VERIFICATION.md) · [Composer guide](docs/VISUAL_COMPOSER.md). Older release blocks preserve their historical facts and then-pending continuations; current status is recorded here.
<!-- composer-geometry-0810:end -->

<!-- editorial-history-089:start -->
## Historical release — 0.8.9 Editorial history and restore, Build 20261020.10

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
## Historical acceptance — Build 20261020.2 released

The owner Email & SMTP slice is **verified, merged into `main` and delivered** through [PR #17](https://github.com/afterburn25/velvet_vixxen/pull/17), merge `65bea278efacd59e8ba24401cc3fe7d47300a7d4`. All seven jobs in [CI run 39361581062](https://github.com/afterburn25/velvet_vixxen/actions/runs/39361581062) passed against the identical source/checkout/merge tree. See [the scoped release record](docs/releases/0.8.1-owner-mail-settings.md).

- [x] Owner settings in **System → Email & SMTP**, protected save, password retention/clear and a saved-settings test to the current owner.
- [x] Strict runtime/installer email validation and explicit From → email-shaped SMTP username → owner fallback; sender issue **#10 closed**.
- [x] Focused evidence: 199 backend, 269 installer, 143 packaged installer and 168 owner HTTP assertions; two packaged desktop/mobile browser cases, loopback mail only.
- [x] All required PHP 8.2/8.3 source/security/package, MySQL 8, nine HTTP suites and real packaged browser gates, including foreign ownership and persistent OPcache.
- [x] Four ZIPs with Build 20261020.2 names, exact approved roots, complete runtime dependencies and an 11-file unfinished-installer repair using `repair_build_id`.
- [x] Exact source/tree/checkout, all seven successful jobs, actual main merge and gated artifact identities recorded.
- [x] All four ZIPs and the separate manifest compared literally with the gated artifact, saved and retrieved again; all five matched at **2026-10-20 03:11:47 UTC**.
- [ ] Media **#2**: caption editing and exact gallery-item/order MySQL/browser persistence, including permanent removal; then photo asset tags with SQL gallery hydration; then playlist scheduling dates and due publication.
- [ ] Addon-owned migration apply/rollback against real MySQL **#7**, followed by remaining broader marketing **#9**.
- [ ] Actual-host incidents **#12/#13**, representative shared-host certification and production email verification.

Start new implementation from live **`main`**. The complete Upgrade serves installed sites; the installer-only repair does not advance their runtime build. Preserve configuration, owner/keys, content and uploads. Payments remain sandbox-only and no 0.9.0 work is included. Historical checked items below do not close the current outstanding work.
<!-- owner-mail-settings:end -->

<!-- grouped-buttons-delivery:start -->
## Historical release — Build 20261020.1 verified, merged and delivered

**The newest executable source is on `main`.** [PR #16](https://github.com/afterburn25/velvet_vixxen/pull/16) actually merged at **2026-10-20 01:35:24 UTC**, commit `0c4d6be5516c9c777c31524a33cd30c4d337f1d7`. Its complete tree equals tested source `9fcd355c6f7207287ab42babe04c80b3f3968a62`, tree `63b758a153a721a1aabf424aead6b9f01867820f`, from `feature/0.8.1-group-buttons-campaign-delivery`. The preceding PR #15 baseline is also actually merged at `31b303e407a869728f2a0548c9214273e35e2c02`. Later documentation-only recording does not change runtime, tests, workflow or package bytes. Earlier unmerged/source-recovery statements below are historical.

**Implemented and tested:** clicking **Live Text + Buttons** opens numbered button cards in the right inspector; **+ Add button to this group** creates the requested Hero **Button 3**. Labels, safe destinations, action/style/new-tab controls, ordering/removal, undo/redo, save/reload, retained owner Preview, Publish and history restore work. Automatic account/trailer behavior remains compatible; group geometry stays separate by device and photo/standalone layers remain independent. Queued campaigns now transmit through the installation's configured mail transport with current confirmed consent/suppression, independent signup/delivery switches, scheduling, deduplication, bounded batches, responsive pause/unsubscribe, explicit failed-delivery retry and held unknown outcomes. No owner SMTP editor or production email certification is claimed.

**All seven jobs in [CI run 39352867755](https://github.com/afterburn25/velvet_vixxen/actions/runs/39352867755) completed successfully.** Each actual checkout identifies `5ccb46beadabe558b5bd4ccb907a8e6185532615`, whose complete tree equals the source above. Gates passed PHP **8.2.34/8.3.35**, JavaScript/security/ownership/cache/package checks, **MySQL 8.0.46** installer resume/migrations/upgrades/transactions, all eight HTTP suites, all 13 existing application-browser suites, new packaged grouped-button/Campaign controls and preserved real installer/update/upload/routing/Preview acceptance. New focused assertions: **139 grouped buttons**, **41 campaign source**, **54 localhost SMTP/HTTP**; the existing **127 standalone-button** assertions also pass. The final local PHP 8.3.6 source/eight-HTTP gate independently preserved all 406 frozen runtime/test/build hashes.

**The new downloads are saved and read back byte for byte against [the gated artifact](https://github.com/afterburn25/velvet_vixxen/actions/runs/39352867755/artifacts/12234860155)**, verified **2026-10-20 01:38:30 UTC**. The primary existing-site update is the complete **Build-20261020.1 Upgrade**: **287 runtime entries**, **7,482,635 bytes**, SHA-256 `720382aa2ee843d08608002c5b51462068b088c4dc84a100aea17fc60f3f1fa8`. Fresh has 328 entries; the focused Media/Composer repair has 86. Fresh/Upgrade have exactly 13/9 approved root directories and four root files; public handlers remain under `public/routes`. The unchanged eight-file installer repair is not redelivered as a new fix. Repository docs/tests/SDK/workflows/screenshots and installed private state stay outside customer ZIPs. Follow `docs/UPDATE_VERIFICATION.md`: complete Upgrade, `admin/repair.php`, exact file verification, Refresh application PHP, running Build **20261020.1**, and conditional old .5 folder cleanup.

Source-import #1 and imported/gated scopes #5/#8/#11 are closed; obsolete archive PR #4 is closed without merging. **#13/#12 remain open because the affected customer host has not been accessed or verified.** #9 still includes the owner SMTP editor, audiences/SQL/editorial/scheduling/attribution/analytics work; #2/#7/#10 retain the audited Media Center, add-on MySQL and sender-validation gaps. #3 remains the release-evidence policy. Resume from live `main` and the reconciled `docs/ROADMAP.md`; preserve the existing runtime and required gates. Payments remain sandbox-only and no 0.9.0 work is included. Detailed merge, test and delivery evidence is in `docs/releases/0.8.1-group-buttons-campaign-delivery.md` and its CI/delivery JSON records.
<!-- grouped-buttons-delivery:end -->

<!-- root-layout-repair:start -->
## Root-layout regression — Build 20261019.5 rejected; Build 20261019.6 verified and delivered

The owner rejected the delivered build because it restored the old route folders at the website root. Actual ZIP comparison confirms **25 extra root directories and 46 forwarding PHP files** (45 inside those directories plus `media.php`). The regression was introduced in `723205b7bddad06d4f603b539aea1a777bdd2ae5` and retained in Build 20261019.5. Earlier passing tests verified that implementation but failed to enforce the user's approved directory requirement. Build .5 is rejected; the historical records below do not make it an acceptable organized-site release. Agent-authored exceptions for those root folders/files are superseded.

**Build 20261019.6 restores the executable pre-regression layout.** Fresh ZIP directories are exactly `addons`, `admin`, `app`, `config`, `database`, `images`, `includes`, `install`, `public`, `storage`, `templates`, `uploads`, `vendor`; Upgrade excludes `config`, `install`, `storage`, `uploads`. Root files are exactly `.htaccess`, `VERSION`, `index.php`, `route.php`. Public implementations remain under `public/routes`, operational commands under `app/Console`. Generated public/API URLs use the existing physical dispatcher at root and subfolder installation paths, with query parameters, authentication, CSRF, entitlements and signed media preserved. Independent package gates reject the actual .5 root maps.

The newest executable source is **`e58f9edd708fd42dee0e6c898170e67eb4a01a53`**, tree **`6fb51ba5e39dca476989d4ae73fb61790a2da50d`**, on `feature/0.8.1-site-ux-hero-library`. **All seven jobs in [CI run 39328060458](https://github.com/afterburn25/velvet_vixxen/actions/runs/39328060458) completed successfully.** Every checkout identifies PR test merge `893d61819f387f71e0ebb4de105a9bee3bd33168`, whose complete repository tree equals that source tree. Final documentation updates do not change runtime, tests, workflows or package bytes. This is not a merged PR.

Final gates passed PHP **8.2.34/8.3.35**, JavaScript/security/ownership/package checks, MySQL **8.0.46** migration apply/verify/rollback (14 migrations / 82 tables), existing-database installation (922 assertions per PHP version), installed update preservation, all seven HTTP suites and all 13 application-browser suites. The actual .5-to-.6 retained-browser/cache upgrade verifies the owner refresh, exact forwarding-file retirement, preservation of custom items, and real uploads, Add Button, retained Full Page Preview and publication. Root/nested routes and desktop/mobile layouts pass. The 30-background library, full/thumbnail image decoding, 90 public device renders, Booking Builder, About/admin/auth/navigation and merchandise/direct PPV regressions also pass. Scoped local Apache/FPM results and earlier intermediate runs remain separately recorded.

The complete **Build-20261019.6 Upgrade** is the primary package for an existing installation. [The gated runtime artifact](https://github.com/afterburn25/velvet_vixxen/actions/runs/39328060458/artifacts/12226463391) was downloaded: all four ZIPs and its separate manifest match the frozen build literally. The four corrected customer downloads were saved and retrieved again; **every saved byte matches the gated artifact**, verified **2026-10-19 22:36:25 UTC**. The unchanged eight-file installer-only repair was not redelivered as a new root-layout fix. Customer ZIPs remain runtime-only.

To apply: extract the complete Upgrade into the existing website folder, sign in as the existing owner and open **`admin/repair.php`** directly. Verify all files, click **Refresh application PHP**, confirm running build **20261019.6**, then **Restore organized folders**. Only exact known old forwarding files are retired, with nonexecuting reversible archives; only empty old folders are removed. Custom/modified files, configuration, keys, accounts, database state and uploads are preserved. See `docs/UPDATE_VERIFICATION.md`, `docs/releases/0.8.1-root-layout-ci.json` and `docs/releases/0.8.1-root-layout-delivery.json`.

**The affected customer host has not been accessed or verified.** Source, CI and saved downloads do not establish that its update has been applied. Issue #13 stays open, PR #15 remains unmerged, payments stay sandbox-only and no 0.9.0 work is included. Earlier release blocks below are historical, not the current source or installation instructions.
<!-- root-layout-repair:end -->

<!-- installed-build-followup:start -->
## Installed-update repair — Build 20261019.5 verified and delivered

**The owner’s affected installation remains unresolved and has not been accessed.** Source, CI and download verification do not establish that the update is active on that host. The affected URL is still unavailable. Issue #13 remains open, PR #15 is unmerged, payments remain sandbox-only, and no 0.9.0 work is included.

The newest executable source is on `feature/0.8.1-site-ux-hero-library`: **`2bc178013c9c528f45a5d0447624f3cbaf4395f2`**, tree **`1b576e805d17b259a24e0d648e0568b54dc0bc37`**. The new runtime shows a distinct build ID, supplies a complete 324-file shared-runtime inventory, and adds active-owner/CSRF-protected **System → Update Status** / `admin/update.php`. It diagnoses missing/mixed files and refreshes only this site’s verified PHP. The previous upload, physical-route, Add Button, Preview and Publish implementations remain included. Existing configuration, keys, account records, database state and uploaded content are preserved.

**All seven jobs in [PR run 39315788377](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377) completed successfully.** Every actual checkout log identifies GitHub’s test merge `99396b2277d947a5f2cc47ef0e181d1c048891cd`. Its entire repository tree is identical to the source tree above, verified by both Git and the commit API; this is not a merged PR. The separate direct-push run remained pending at the 20:31:19 UTC audit and is not claimed as the completed release gate.

The final gates passed PHP **8.2.34/8.3.35** source/JavaScript/security/package suites; **MySQL 8.0.46** on both versions with 922 extracted-installer assertions per version, 32 installed-update checks, 12 booking transaction/snapshot checks and migration apply/verify/rollback; installer ownership/write and persistent-cache regressions; all seven HTTP and thirteen application-browser suites; actual packaged upload/routing/cleanup/Preview/Publish/button checks; and runtime-only archive exclusions/reproducibility. Browser execution used Playwright 1.62.0 / Chromium 151.0.7922.34, including 30 backgrounds, all full/thumbnail decodes, 90 public device renders, Booking Builder, About/admin/auth/navigation and merchandise/direct PPV.

The new mandatory historical-update test reconstructs the exact prior delivered ZIP from pinned `e5b04daa`, warms old PHP, and keeps the same browser and server through actual Upgrade extraction. Both cached root/subfolder cases reproduce matching files on disk while the old Composer still runs and Preview/Publish emit no requests. The real owner refresh then passes Add Button, retained preview, persisted publication, public Booking navigation, both image upload forms and exact image readback/decode. The disabled-cache control, owner/member/demoted-owner/CSRF/method boundaries, mixed-file refusal, six desktop/mobile status layouts and private-state/unrelated-cache preservation also pass. Local final-runtime acceptance independently passed on PHP 8.3.6 / Chromium 155.0.8059.84.

**The complete Upgrade is the primary download for an installed 0.8.1 site with an unknown earlier build.** `Nikki-Wilder-Velvet-Vixxen-0.8.1-Build-20261019.5-Upgrade-from-0.8.x.zip` contains 325 runtime entries, occupies 7,474,055 bytes and has SHA-256 `080e91477917171cf3bbc4438101507ac9bf9d597db61727797a82aa738b027d`. The full Fresh ZIP, 72-file focused repair and separate manifest were also updated. The eight-file installer-only repair is unchanged and was not redelivered as a new Composer fix.

[Runtime artifact 12219288424](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377/artifacts/12219288424) was downloaded and compared literally: all four ZIPs and the manifest match the frozen source-built files. The four new downloads were saved with their existing identities/version guards and retrieved again; every saved byte matches the gated artifact, verified **2026-10-19 20:30:12 UTC**. [Browser evidence 12219640705](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377/artifacts/12219640705) remains outside the customer ZIPs.

See `docs/UPDATE_VERIFICATION.md`, `docs/releases/0.8.1-installed-build-repair.json`, `docs/releases/0.8.1-installed-build-ci.json` and `docs/releases/0.8.1-installed-update-acceptance.json`. The separate Apache/FPM audit of the preceding .4 runtime and the earlier failed/intermediate test attempts remain recorded with their actual scopes. Preserve the unresolved hosted report until the owner’s served build and real actions are checked.
<!-- installed-build-followup:end -->

<!-- media-composer-repair:start -->
## Historical Media, Composer buttons and public route repair — 2026-10-19

The newest executable source is on `feature/0.8.1-site-ux-hero-library`. Runtime repairs were uploaded in commit `723205b7bddad06d4f603b539aea1a777bdd2ae5`; the final execution-verified test followup is `db759342e401617c9933c13b81a147ad9f8fd515`, tree `c62be80d9d4a8250905bdb86a1b4f3ea1676c4e7`, with identical runtime bytes. All seven jobs in [GitHub run 39304801698](https://github.com/afterburn25/velvet_vixxen/actions/runs/39304801698) are **completed / success**, with every checkout matching that exact source. The downloaded gated runtime artifact and its manifest are byte-identical to the saved customer downloads. Earlier installer and feature records below are historical evidence.

The actual application now has **46 physical public entry points covering all 87 registered URLs**, including upload/scene APIs, account aliases, booking, checkout, content pages and existing signed-media URLs. Their protected implementations remain under `public/routes`. This removes the missing-file dependency on rewrites that caused the reported upload JSON errors and failed scene saves. Upload/save/publish/restore handle non-JSON failures readably. Full Page Preview retains a useful error tab instead of closing it if either draft save fails.

The requested **Add Button** control adds separate live link layers with editable text, destinations, styles/colors, text size, corner radius and new-tab behavior. Buttons share the existing direct drag/free resize, numeric, keyboard, snapping, ordering, hide/lock, duplicate/delete, history and device layout controls. The public Hero/Footer renders keyboard-accessible links. Photo cutouts and the existing live text/button group remain independent. The server validates button data before writing; no database migration is needed.

The routing audit also repaired old cleanup behavior that would remove the new entry points. Current forwarding files survive; obsolete controllers and unknown sibling files move to a protected private archive with their bytes preserved. Member login/Requests/logout destinations and marketing post links, stored local calls to action, banners and analytics requests now retain a nested installation's base path. Existing external HTTP(S) destinations remain usable.

**Local verification has passed:** full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite; 127 button assertions; 90 installation-URL assertions; 371 repair-backup assertions; 566 public-route/path assertions; all seven existing HTTP suites; real packaged uploads at root/desktop and nested/mobile; and all 87 public routes before/after real owner cleanup at both installation paths. Negative controls reproduce missing entry points, the JSON parser error, the disappearing-preview cause, unsafe unvalidated buttons and the old cleanup removing 40 active entry points. All 13 application-browser suites also passed, including 30 distinct backgrounds, all 60 full/thumbnail image decodes, 90 published desktop/tablet/mobile renders and the 12-route public layout audit. Four actual packaged root/nested × desktop/mobile cases passed preview failure recovery, owner-only Hero/Footer drafts, publication/history, persistent live buttons, real Booking links, marketing posts/banners/campaigns and accepted analytics events. GitHub also passed PHP 8.2.34/8.3.35 source/package suites, MySQL 8.0.46 on both PHP versions, 922 extracted-installer assertions per version, 32 real upgrade checks, 12 booking transaction/snapshot checks, migration apply/verify/rollback, the real installer browser flow, all packaged acceptance cases, seven HTTP suites and thirteen application-browser suites. Safety, ownership, persistent PHP cache, archive exclusions and reproducibility remain mandatory and passed.

The runtime-only installed-site repair contains **67 files: 60 PHP, five JavaScript and two CSS**. The fresh and upgrade ZIPs contain matching runtime bytes. Configuration, storage, uploads, customer records, docs/tests/workflows/screenshots and installer state are excluded from the small repair. The earlier eight-file Installer Database Repair remains separate and byte-identical. The installed-site repair, complete fresh-install and upgrade downloads, and separate manifest were saved on 2026-10-19 after comparison with [runtime artifact 12214187647](https://github.com/afterburn25/velvet_vixxen/actions/runs/39304801698/artifacts/12214187647). [Browser evidence](https://github.com/afterburn25/velvet_vixxen/actions/runs/39304801698/artifacts/12212794646) stays outside every customer ZIP. Source/tests and progress are uploaded on the feature branch; issue #13 and PR #15 track this exact repair. See `docs/MEDIA_COMPOSER_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-media-composer-repair.json`. No deployment, merge or 0.9.0 work is claimed.
<!-- media-composer-repair:end -->

## Historical installer continuation with existing databases — 2026-10-19

The newest executable source remains on `feature/0.8.1-site-ux-hero-library`. This continuation removes the blanket rejection of databases containing Velvet Vixxen tables. The actual installer now handles empty databases, unrelated existing tables, compatible partial schemas, complete schemas and missing migration history. It validates existing definitions and owner credentials, applies only missing work, and preserves existing records, settings, scenes, owner password hashes, installation identity and recoverable security keys. Historical migration bytes are unchanged.

A failed attempt keeps completed schema work and its pending configuration for retry. Pending configuration is not publicly installed until final verification succeeds, including a process interruption after the lock was written. Automatic recovery and installer retirement share the setup guard and cannot replace a pending checkpoint or move the installer while it is running. Existing completed installations remain protected; incompatible schema or missing original encryption keys produce specific preservation errors, never an instruction to erase the database.

The owner's additional layout correction removes the extra Folders rule/inset, aligns Server/Folders headings and check cards, and aligns Password/Confirm Password fields despite the strength meter. Completion clears the spinner and page scroll lock. Installer CSS/JavaScript have their own updated cache query. The runtime repair contains **eight files: six PHP files, one stylesheet and one JavaScript file**, including the earlier folder correction. The fresh and upgrade packages are rebuilt; the earlier one-file Folder Repair ZIP does not contain this database/layout continuation.

Local and GitHub verification are complete: the full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite passed; the actual packaged installer passed 922 assertions on MySQL 8.0.46; recovery passed 134 assertions; and real desktop/mobile browser installation, retry, layout and completion tests passed. The new SQL parser cases and original failing layout are retained or reproduced as meaningful regressions. Executable source, tests and workflow are uploaded as [`e5b04daa132521be69c943151f8112c7cd2017e8`](https://github.com/afterburn25/velvet_vixxen/commit/e5b04daa132521be69c943151f8112c7cd2017e8), tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`. All seven required jobs passed in [GitHub run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907), whose official conclusion is **completed / success**. This includes PHP 8.2.34/8.3.35 source suites, 922 actual-package MySQL installer assertions on each version, real installer desktop/mobile acceptance, seven HTTP suites, twelve application-browser suites and gated packaging. The downloaded GitHub ZIPs and manifest are byte-identical to the saved customer downloads. Matching repair, fresh-install, upgrade and separate checksum-manifest downloads were saved on 2026-10-19. See `docs/INSTALLER_DATABASE_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json` (repository-relative paths). Earlier folder/cache evidence remains historical in the test report and its release record. No 0.9.0 work or production deployment is included.

### Historical installer repair acceptance — 2026-10-19

- [x] Reuse compatible empty, unrelated, partial, complete and ledgerless databases.
- [x] Preserve existing owner, records, settings, scenes, keys and installation identity.
- [x] Keep failed work resumable; prevent recovery/retirement races and premature public activation.
- [x] Implement the requested Server/Folders and password-field alignment corrections.
- [x] Complete actual browser layout and retained SQL regression evidence.
- [x] Verify the latest PHP 8.2/8.3 GitHub source/MySQL/HTTP/browser/package gates.
- [x] Upload the current source/tests/progress and save matching repair/fresh/upgrade downloads.

**Captured:** 2026-10-19  
**Repository:** `afterburn25/velvet_vixxen`  
**Handoff branch:** `handoff/0.8.1-incomplete-site-ux`
**Implementation branch:** `feature/0.8.1-site-ux-hero-library`  
**Baseline documentation branch:** `feature/0.8.0-marketing-publishing-analytics`

This file preserves the earlier 0.8.1 acceptance checklist and the current continuation at the top. After verified 0.9.0 delivery, preserve the completed 0.8 features and integrated 0.9 safeguards, marketing, privacy and operations, then continue the separately scoped payment and live-acceptance work; the original handoff and old branch names below are historical. Verify executable source, behavior, required tests and exact package evidence before marking additional work complete.

## Earlier verified checkpoint — before the installer correction

The **0.8.1 user-facing engineering continuation is implemented and verified** on `feature/0.8.1-site-ux-hero-library`. Runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c); complete GitHub CI: [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), **all six jobs passed**. PHP 8.2.34/8.3.35, JavaScript syntax, MySQL 8.0.46, installer/ownership/security, seven HTTP suites, twelve real Chromium suites and gated runtime packaging all passed. The library has 30 individual scenes and 30 thumbnails, with 90 successful published renders across desktop/tablet/mobile.

This is the verified sandbox development milestone. Payments remain sandbox-only, actual owner content must be supplied by the owner, and production hosting/live-service certification is not claimed. PR #15 contains the feature branch; inspect its current merge state separately. No 0.9.0 implementation is included.

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

## Truth boundary

- At the original handoff, GitHub documented a packaged `0.8.0 — Marketing, Publishing & Analytics` milestone without its complete engineering source. The implementation branch now contains checksum-verified recovered 0.8.1 source and actual subsequent feature work; the historical claim is not retroactively certified.
- The 0.8.0 branch records runtime artifact metadata, including SHA-256 `ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993`, but the runtime ZIP itself is not committed to source control.
- Inspect live `main` and the latest verified release record before coding. `main` contains the authoritative integrated application; historical documentation-only and archive branches are not continuation targets.
- Customer installation ZIPs must remain runtime-only. Repository docs, tests, SDK source, screenshots, GitHub workflows, development schema snapshots, and build tooling stay outside the customer ZIP.
- Production credentials, member/customer records, private messages, booking/request data, identity documents, payment secrets, raw license keys, signing private keys, runtime logs, sessions, and private/paid media must never be committed.

## Priority 0 — Recover and verify the real executable baseline

- [x] Inspect every live branch and identify the newest branch that actually contains executable application source rather than documentation only.
- [x] Search the 0.8.0 source/artifact inputs; reject the recovered 0.8.0 ZIP as a substitute for its mismatched historical checksum, and select the newer checksum-verified 0.8.1 runtime as documented in `docs/SOURCE_RECOVERY.md`.
- [x] If the 0.8.0 engineering source is unavailable, start from the last verified executable source/package and reapply later changes deliberately; do not invent missing code from status documents.
- [x] Recover and execute the existing source, installer, HTTP, security, runtime-package and real browser suites; record discovered failures and baseline fixes separately from new feature tests.
- [x] Import the complete clean engineering source into GitHub on a feature branch, while keeping the public installation ZIP runtime-only.
- [x] Record the recovered source commit, baseline package SHA-256, migration/table counts and observed tests in the status/handoff/test report. Final feature package checksums are recorded in `RELEASE_MANIFEST.txt` and `docs/TEST_REPORT.md`.

## Priority 1 — Visual Composer direct manipulation and scene realism

The user wants the Visual Composer to behave like a real canvas editor.

- [x] Allow direct drag movement of model/photo layers anywhere on the canvas.
- [x] Allow layers to move well beyond every canvas edge, including negative X/Y positions and positions below the Hero, so lower legs or other portions can be clipped to create a convincing floor/foreground illusion.
- [x] Clip everything outside the live Hero/Footer viewport on the public site.
- [x] Allow image resizing from extremely small to extremely large. Preserve aspect ratio by default and provide precise numeric controls.
- [x] Keep resize handles reachable even when a layer is partly off-canvas.
- [x] Allow direct movement and resizing of the live text/button group as one canvas object.
- [x] Preserve separate Desktop, Tablet, and Mobile positions/sizes for backgrounds, model layers, and the live text/button group.
- [x] Support background panning, focal point, zoom, brightness, contrast, saturation, overlay, and blur.
- [x] Preserve undo/redo, layer ordering, lock/hide, duplicate/delete, snapping, alignment guides, and keyboard/numeric movement.
- [x] Verify that model layers remain separately editable and are not flattened into Canva artwork.
- [x] Dedicated furniture/foreground outline editing is verified, merged and delivered in 0.8.12. The source-image outline covers selected independent photos without baking a cutout into the room background; completed edits use the existing history workflow.
- [x] Composer/public Hero and Visual Footer scale, larger compact footer text, the blue new-tab Cajun Web Pro credit and visible-player clearance are verified and delivered in 0.8.10. All seven jobs/66 steps passed, with actual-artifact 52 scene comparisons, 24 footer layouts, eight new-tab clicks, eight empty-catalogue observations and 34 decoded PNGs.
- [x] Owner Header/Footer menu editing, item creation/removal/reordering, submenus and same-tab/new-tab/new-window choices are verified, merged and delivered in 0.8.11. All seven jobs/70 steps, actual menu and retained Composer artifacts, source/checkout/merge equality and literal saved read-back passed.
- [x] The carried-forward request to move a cutout farther back on a bed/in the room is delivered in 0.8.12 through per-photo Depth in scene and independent Place behind foreground controls, with per-device room direction and source-image furniture masking.

### Broken Visual Composer media

- [x] Fix all asset URL resolution rules for bundled `/images/...` assets versus uploaded `/uploads/images/...`, `/uploads/models/...`, `/uploads/thumbnails/...`, and related media locations.
- [x] Media Tray must show image-compatible assets only.
- [x] Missing files must show a controlled placeholder and useful status, never a permanent broken-image icon.
- [x] Add automated coverage that loads every Media Tray thumbnail and fails on 404s or invalid image responses.

## Priority 2 — Premium Hero Background Library

The user requested many individual, upscale, people-free scene backgrounds that transparent model photos can interact with naturally. These must be standalone full-width images, not collage sheets.

### Required scene categories

- [x] Multiple luxury bedrooms and romantic suites
- [x] Upscale kitchens
- [x] Elegant living rooms and penthouses
- [x] Game rooms with pool tables
- [x] Private home bars and cocktail lounges
- [x] Home theaters
- [x] Luxury bathrooms, spas, and saunas
- [x] Walk-in closets and dressing rooms
- [x] Music/recording studio
- [x] Wine cellar
- [x] Mansion foyer or staircase
- [x] Rooftop lounge
- [x] Garden patio and fire-pit lounge
- [x] Infinity pool and waterfall pool
- [x] Beach and private cabana
- [x] Outdoor/pool bar
- [x] Sunset terrace
- [x] Yacht deck
- [x] Private jet cabin
- [x] Mountain lodge/retreat

### Scene requirements

- No people, mannequins, embedded model silhouettes, text, logos, labels, watermarks, or collage borders.
- Fancy, polished, photorealistic, and upscale; avoid plain or generic stock-room appearance.
- Wide Hero composition with believable floor plane, perspective, lighting, and open placement zones.
- Preserve enough negative space for live website text and buttons.
- Include foreground objects/edges where appropriate so off-canvas clipping and later occlusion can create believable interaction.
- Provide optimized runtime versions and separate thumbnails while preserving high-quality masters outside the public runtime ZIP when appropriate.

### Generated assets from the prior active workspace

Historical filenames below were searched for but could not be recovered as usable durable assets. Thirty individual replacement/expanded scenes now exist in `images/hero/` with manifest, thumbnails and `artwork/hero/prompts.json` provenance. These old names are retained for traceability only:

- `glamorous_magenta_penthouse_retreat.png`
- `midnight_magenta_penthouse_kitchen.png`
- `sunset_penthouse_with_city_views.png`
- `neon_luxe_penthouse_billiards_lounge.png`
- `opulent_magenta_skyline_cocktail_lounge.png`
- `luxury_tropical_infinity_pool_at_sunset.png`
- `sunset_cabana_by_the_sea.png`
- `luxury_sunset_oceanfront_bar.png`
- `luxury_rooftop_lounge_at_night.png`
- `luxe_pink_and_gold_marble_spa.png`

Prior Adobe concept URLs also lacked durable assets. The current 30 real individual files cover all requested categories, were visually reviewed, and passed Chromium image decoding plus 90 public scene renders. No collage or screenshot is counted as a selectable scene.

### Library integration

- [x] Create searchable/filterable background categories in the Visual Composer.
- [x] Show a thumbnail, scene title, orientation, and recommended model placement zone.
- [x] Clicking a scene should preview it immediately without publishing.
- [x] Save Draft, Full Page Preview, Publish, version history, and rollback must work.
- [x] Verify every background in desktop, tablet, and mobile modes.

## Priority 3 — Admin-configurable Booking Form Builder

- [x] Owner can create, edit, delete, enable/disable, and reorder fields.
- [x] Every field can be required or optional.
- [x] Supported types: short text, paragraph/textarea, email, phone, dropdown/select, single-choice radio group, and multi-choice checkboxes.
- [x] Radio options must be linked so only one option can be selected.
- [x] Checkboxes must support multiple selections and required minimum-selection rules where configured.
- [x] Email fields must validate realistic address format on both client and server and display a clear inline error.
- [x] Phone fields must accept digits and format live while typing. Example: `3374508598` becomes `(337) 450-8598` before the user leaves the field.
- [x] Phone validation must reject incomplete/invalid numbers on both client and server.
- [x] Store submitted values safely and expose them in the existing booking administration workflow.
- [x] Preserve existing booking records when the form definition later changes.
- [x] Add CSRF, authorization, escaping, upload, and spam/rate-limit coverage.

## Priority 4 — Public navigation, authentication pages, and About Me

- [x] Main public navigation is sticky on every public page and does not cover page anchors or mobile controls.
- [x] Remove `Contact` from the main navigation; Booking is the public inquiry/contact path.
- [x] Add `About Me` immediately after Booking in the navigation.
- [x] About Me is a dedicated public page, not a homepage anchor.
- [x] About Me left sidebar contains owner-editable label/value statistics such as height, measurements, hair, eyes, location, availability, and any custom rows. Empty values remain hidden.
- [x] Main About Me body contains the owner-editable biography and optional CTA/media.
- [x] Add a full About Me editor in the appropriate admin category.
- [x] Login, Join, Create Account, password recovery, and related auth pages must use a clean standalone page layout with sticky nav, centered form in the body, and footer at the bottom—never a narrow form pinned to the left side.
- [x] Verify desktop/tablet/mobile layouts, keyboard controls and accessible roles/names used by real-browser tests. A manual assistive-technology certification is not claimed.

## Priority 5 — Reorganize administration navigation

- [x] Group all owner pages into logical collapsible categories, such as Dashboard, Site & Design, Content & Media, Members & Communications, Commerce, Marketing, Extensions, and System.
- [x] Persist expanded/collapsed category state across refreshes and returns.
- [x] Preserve the active page and active-page highlight after refresh.
- [x] Do not reset the user to the dashboard when reloading an admin page.
- [x] Expand the active category by default while preserving an explicit owner choice to collapse it.
- [x] Ensure mobile admin navigation remains usable.
- [x] Place Booking Form Builder under Members & Communications, About Me under Site & Design, and Merchandise under Commerce.

## Priority 6 — Merchandise store and PPV purchase separation

- [x] Build a real merchandise catalog with products, images, descriptions, prices, variants/sizes/options, stock/status, shipping requirement, cart, checkout, and order administration.
- [x] Subscription plans may be promoted from the Store but should use the membership/subscription checkout flow rather than behave like physical merchandise.
- [x] PPV photo galleries and PPV videos must not appear as normal cart merchandise.
- [x] PPV gallery/video pages must use a direct `Buy Now` or rental checkout flow tied to the selected content.
- [x] Keep purchased PPV content in My Library and preserve existing entitlement, refund, and chargeback behavior.
- [x] Test cart contents so physical merchandise cannot accidentally mix with an incompatible PPV entitlement flow unless a deliberate mixed-order design is implemented later.
- [x] The bundled payment provider remains sandbox-only. Do not claim live payment readiness.

## Priority 7 — Canva workflow clarity

Current Canva behavior should be treated as a manual bridge unless a real OAuth/API integration is verified.

- [x] Document clearly that `Edit in Canva` is a saved-link shortcut, not live synchronization.
- [x] Document the export/import workflow for Hero, Footer, gallery covers, and video posters.
- [x] Keep navigation, text, buttons, paywalls, forms, and media players as live HTML/JavaScript.
- [x] Prefer Canva for backgrounds/decorative artwork and the Visual Composer for separately movable transparent model layers.
- [x] Do not claim Canva OAuth, automatic export, or automatic sync until credentials and end-to-end tests exist.

## Required acceptance tests before calling 0.8.1 complete

- [x] Direct drag, resize, off-canvas movement, lower-leg clipping, background pan, and text/button group movement in a real browser.
- [x] No broken images in the Visual Composer or public Hero/Footer.
- [x] At least 30 distinct standalone background scenes available and selectable.
- [x] Booking field creation, reordering, required/optional behavior, dropdown, radio, checkbox, email validation, and live phone formatting.
- [x] Sticky navigation on all public routes.
- [x] Centered Login/Join/Create Account pages.
- [x] About Me public page and owner editor.
- [x] Admin collapsible state and active page preserved after refresh.
- [x] Merchandise cart/checkout works while PPV uses direct Buy Now.
- [x] Existing subscriptions, entitlements, refunds, protected media, messaging, booking, add-ons, installer, and marketing features have regression coverage.
- [x] PHP 8.2/8.3 source suite, JavaScript syntax, disposable MySQL 8 migration apply/verify/rollback, HTTP acceptance, repository-safety scan, desktop/mobile Playwright, and runtime-only package checks are green.
- [x] Customer ZIP starts uninstalled and excludes GitHub/docs/tests/SDK/build artifacts and all runtime/private data.

## Current continuation target

The current completed release is **0.9.1 / Build 20261022.1**, **Verified, merged and delivered**. Preserve its payment journals/recovery and every delivered 0.8/0.9 capability. Historical incomplete lists above do not reopen implemented work.

- [x] Accept the corrected frozen source and complete 28-job / 295-configured-step workflow, retaining the earlier failed checkpoint and all required gates.
- [x] Compare the actual gated packages, source/checkout/merge tree, independent live-main readback and all four saved customer files literally.
- [x] Deliver durable bound payment operations/events/effects, held uncertain outcomes, separate recoverable follow-ups and current-owner recovery controls.
- [ ] Implement the separately scoped [recurring lifecycle and immutable terms plan](docs/RECURRING_LIFECYCLE_PLAN.md): accepted membership/bundle snapshots, authoritative agreement/cycle identities, durable cancellation/resumption commands and idempotent renewal effects.
- [ ] Obtain an approved merchant provider/account, implement its real adapter and verify approved, declined, duplicate, renewal, cancellation, refund and chargeback outcomes. Only Sandbox Gateway is currently registered.
- [ ] Configure production mail/age integrations and approved recipients/methods; verify real delivery, unsubscribe/suppression and age-provider sessions.
- [ ] Verify the actual customer host: preserved installation/upgrade, rollback, running build, private storage/origin/CDN denial, trusted country headers, cron, external backup key and isolated restore; keep actual-host #12/#13 open without evidence.
- [ ] Record owner-approved public policies, retention reasons/review dates and any external recurring-cancellation responsibilities before privacy closure.

Photo depth is resolved after the owner update. Keep #3 as ongoing release policy and reconcile #9/#34 against accepted scope before changing issue state. A changed completed delivery advances its visible version; source, CI, saved delivery and customer-host acceptance remain distinct.

## Earlier continuation final verification

The full checklist above is verified by [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477) on [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c). Failed intermediate runs and their corrections are retained in `docs/TEST_REPORT.md`. Current schema: 14 migrations / 82 tables. The owner supplies real photographs and personal/catalog content; those inputs and live production-service certification are not fabricated completion claims.
