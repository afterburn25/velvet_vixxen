# Acceptance Record — Velvet Vixxen

<!-- editorial-metadata-088:start -->
## Current work — 0.8.8 Editorial metadata, Build 20261020.9

The current integration advances the visible version from delivered **0.8.7** to **0.8.8**, starting at authoritative `main` `f918db21b031b3fe43baa6619b2a16d1a62b5f0d` on `feature/0.8.8-editorial-metadata`. Implementation, local acceptance, exact-tree GitHub CI, merge and saved-download verification are in progress. No completed release is claimed for the changed tree.

Posts gain a deliberately public author display name, comma-separated tags, an existing public featured image and alternative text, search title/description and up to six manually ordered related posts. Landing pages gain the image and search fields; banners keep their existing controls. Existing categories, IDs, permalinks, order, publication timing and Public/Members/Selected tiers access remain in place. Related content is filtered by source and target access before metadata disclosure. Saved-owner previews resolve metadata before their final active-owner check, keep related links inert and produce no marketing events or email.

Required acceptance covers field validation and preservation, current public image eligibility, related-content timing/access, generic denial without search metadata, private saved previews, stale revisions/CSRF/current-owner checks including real storage waits, unchanged frozen marketing records, and actual-package file/MySQL root/nested desktop/mobile flows. Every existing source, security, foreign-ownership, persistent-OPcache, MySQL, installer, HTTP, browser and package gate remains mandatory.

Full Fresh/Upgrade will carry **0.8.8 / Build 20261020.9**, preserving the exact approved 13/9 root directories and four root files. Changed runtime entries advance their archive timestamps; unchanged entries retain the verified 0.8.7 timestamps. The unchanged eleven-file unfinished-installer repair retains **0.8.1 / repair Build 20261020.3**, its original hash and saved identity. Each changed completed release advances the visible version.

The broader #9 roadmap remains open for editorial history/restore, calendar and post expiry; scheduled Composer scenes; durable referral attribution; analytics operations; campaign message preview/test-send, templates and tracking; missing notification workflows; additional audience predicates; and provider hooks. File and MySQL storage retain the same business rules without a new migration. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only. The separate 0.9 milestone is not complete.

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

This file preserves the earlier 0.8.1 acceptance checklist and the current continuation at the top. After verified 0.8.6 delivery, continue broader marketing #9 from live `main`; the original handoff and old branch names below are historical. Verify executable source, behavior, required tests and exact package evidence before marking additional work complete.

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
- Optional future enhancement: dedicated furniture/foreground masks. Current separate photo ordering and all-edge clipping are implemented; no cutout is baked into the room. A specialized mask editor is not claimed as part of this continuation.

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

Start a scoped branch from live `main` using the verified 0.8.7 release record. Preserve the completed Media Center, add-on recovery, bounded newsletter audiences, publication-window editors, SQL marketing persistence and Public/Members/Selected tiers editorial access with private saved-owner previews. The next open #9 group is editorial tags/authorship/featured-image/SEO/related-content controls, followed by separately scoped version history/restore, calendar and post expiry. Continue the remaining concrete capabilities identified in `docs/ROADMAP.md`; keep #12/#13 open for actual-host verification. Advance the visible version for each changed completed release. The separate 0.9 milestone requires its own implementation and acceptance.

## Earlier continuation final verification

The full checklist above is verified by [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477) on [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c). Failed intermediate runs and their corrections are retained in `docs/TEST_REPORT.md`. Current schema: 14 migrations / 82 tables. The owner supplies real photographs and personal/catalog content; those inputs and live production-service certification are not fabricated completion claims.
