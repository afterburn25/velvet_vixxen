# Testing — Velvet Vixxen

<!-- editorial-metadata-088:start -->
## Current work — 0.8.8 Editorial metadata, Build 20261020.9

The current integration advances the visible version from delivered **0.8.7** to **0.8.8**, starting at authoritative `main` `f918db21b031b3fe43baa6619b2a16d1a62b5f0d` on `feature/0.8.8-editorial-metadata`. Implementation, local acceptance, exact-tree GitHub CI, merge and saved-download verification are in progress. No completed release is claimed for the changed tree.

Posts gain a deliberately public author display name, comma-separated tags, an existing public featured image and alternative text, search title/description and up to six manually ordered related posts. Landing pages gain the image and search fields; banners keep their existing controls. Existing categories, IDs, permalinks, order, publication timing and Public/Members/Selected tiers access remain in place. Related content is filtered by source and target access before metadata disclosure. Saved-owner previews resolve metadata before their final active-owner check, keep related links inert and produce no marketing events or email.

Required acceptance covers field validation and preservation, current public image eligibility, related-content timing/access, generic denial without search metadata, private saved previews, stale revisions/CSRF/current-owner checks including real storage waits, unchanged frozen marketing records, and actual-package file/MySQL root/nested desktop/mobile flows. Every existing source, security, foreign-ownership, persistent-OPcache, MySQL, installer, HTTP, browser and package gate remains mandatory.

Full Fresh/Upgrade will carry **0.8.8 / Build 20261020.9**, preserving the exact approved 13/9 root directories and four root files. Changed runtime entries advance their archive timestamps; unchanged entries retain the verified 0.8.7 timestamps. The unchanged eleven-file unfinished-installer repair retains **0.8.1 / repair Build 20261020.3**, its original hash and saved identity. Each changed completed release advances the visible version.

The broader #9 roadmap remains open for editorial history/restore, calendar and post expiry; scheduled Composer scenes; durable referral attribution; analytics operations; campaign message preview/test-send, templates and tracking; missing notification workflows; additional audience predicates; and provider hooks. File and MySQL storage retain the same business rules without a new migration. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only. The separate 0.9 milestone is not complete.

[Release and exact evidence](releases/0.8.8-editorial-metadata.md) · [Editorial metadata guide](EDITORIAL_METADATA.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- editorial-metadata-088:end -->

<!-- editorial-access-087:start -->
## Historical release — 0.8.7 Editorial access and owner previews, Build 20261020.8

**Version 0.8.7 is verified, merged into `main` through [PR #23](https://github.com/afterburn25/velvet_vixxen/pull/23) and delivered.** Merge `aa119809f0e7e7bd093ce12d07ae60383f1a451e` completed at **2026-10-20T17:57:08Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39466086276) passed. Release source `d89dbe8419c6362c8e676751e0d42facdd98f498`, actual CI checkout `b597edf820b514c951acebac810934b7388ae259` and the merge share the complete tree `c4d65acff90d5c3ca0f393f3c3fb28ac2295c75c`. Later documentation recording changes no executable, test, workflow or package bytes.

Owners can choose Public, Members or Selected tiers for saved posts, banners and landing pages. Members includes active verified free accounts; selected tiers require an eligible subscription to an exact enabled plan. Saved member, device, plan and subscription state is checked on each request, with UTC SQL subscription bounds and database-derived verification instants interpreted independently of site-local publication schedules. Timing still applies, malformed restrictions hold access, and absent legacy policies retain public behavior. Filtering precedes limits and private field disclosure. A separate saved-owner preview rechecks current ownership after stored content and tier reads, uses private caching, makes promotion links inert and records no marketing events or email.

The new source suite passed 412 assertions on PHP 8.2 and 8.3. Both real-MySQL jobs executed the packaged editorial HTTP matrix at root and nested paths (1557 checks per case), including actual blocked tier-catalogue requests followed by owner demotion or deactivation. File and MySQL desktop/mobile browser flows exercised real access edits, all three saved previews and guest/free/tier member views in America/Chicago. The tests check recently verified accounts, subscription boundaries, generic denial, private caching, unchanged queues/consent and zero SMTP messages. The retained 401-check MySQL marketing suite, 971-check packaged database installer, 141-check-per-case Marketing Storage flows and all existing source, security, ownership, persistent-OPcache, MySQL, ten-suite HTTP and browser gates passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.7 / Build 20261020.8** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 18:00:09 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The bounded 0.8.7 editorial access and saved-owner preview increment of #9 is complete, following saved audiences, publication editing and SQL marketing persistence. Editorial metadata, history/restore, calendar and post expiry remain next. Scheduled Composer scenes, durable referral attribution, analytics operations, campaign message preview/test-send, templates and tracking, missing notification workflows, additional audience predicates and provider hooks remain open. File and MySQL storage preserve the same business rules; indexed reporting and large-dataset performance are not claimed. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](releases/0.8.7-editorial-access.md) · [Editorial access guide](EDITORIAL_ACCESS.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- editorial-access-087:end -->

<!-- marketing-sql-086:start -->
## Historical release — 0.8.6 SQL marketing persistence, Build 20261020.7

**Version 0.8.6 is verified, merged into `main` through [PR #22](https://github.com/afterburn25/velvet_vixxen/pull/22) and delivered.** Merge `2a2bf4468c52304e08e246030d075527ab5972e3` completed at **2026-10-20T15:41:54Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39446912796) passed. Release source `e4640e84a2c5417954f936f86ee993112c67bb37`, actual CI checkout `9eace0ec2802a8ea47ddba75748521f8bbfb5168` and the merge share the complete tree `8deae90664202b37199283ec0121169291771349`. Later documentation recording changes no executable, test, workflow or package bytes.

Marketing now supports per-record MySQL persistence with a separate System → Marketing Storage owner page. Migration 015 creates two additive tables and leaves the file backend active. The current active owner previews aggregate counts, then explicitly moves the complete snapshot. The move preserves identifiers, unknown fields, entry keys/order, consent, unsubscribe tokens, suppressions, saved audiences, frozen messages, queued recipients and delivery outcomes. Transactional authority, durable local intent and database-wide data/delivery locks protect cutover and cross-root workers. The original file remains unchanged, and the move queues or sends no email. Active SQL failures hold reads and writes without reviving the archived file.

The new storage source suite passed 41 checks in each PHP job. Both real-MySQL jobs passed 401 actual-package checks covering preservation, rollback, commit-disconnect, four-writer concurrency, cross-root campaign exclusion and loopback SMTP behavior, alongside 971 existing-database installer assertions per job. The actual Fresh package passed 141 owner HTTP checks in each root-desktop/nested-mobile case, plus real browser database update, stale review, owner move and subsequent SQL-backed Marketing save. The complete existing source, security, ownership, persistent-OPcache, MySQL, installer, ten-suite HTTP, Media Center, add-on recovery, audience, publication, private-reply and browser gates remain required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.6 / Build 20261020.7** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 15:45:08 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The 0.8.6 SQL marketing persistence increment of #9 is complete, following 0.8.4 saved audiences and 0.8.5 publication editing. Broader editorial access/preview/metadata/history/calendar/post expiry, scene schedules, referral attribution, analytics operations, campaign templates/tracking, missing notification workflows and provider hooks remain open. File-mode sites retain file storage; indexed reporting and large-dataset performance are not claimed. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](releases/0.8.6-marketing-sql.md) · [Marketing Storage guide](MARKETING_STORAGE.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- marketing-sql-086:end -->

<!-- publication-windows-085:start -->
## Historical release — 0.8.5 Publication-window editing, Build 20261020.6

**Version 0.8.5 is verified, merged into `main` through [PR #21](https://github.com/afterburn25/velvet_vixxen/pull/21) and delivered.** Merge `9f782d4769ef31c73f21134ec2cb6ab7e29d8bd9` completed at **2026-10-20T13:33:24Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39378794728) passed. Release source `3368ffc41e63ded8c790ad1f8e4156349a4bc4ed`, actual CI checkout `32b876eed4746c383eeb001e808c8a418d30dff9` and the merge share the complete tree `1bfae06c9576f765c6f6a32d90cfd316b33e57ee`. Later documentation recording changes no executable, test, workflow or package bytes.

Owners can reopen and edit saved posts, banners and landing pages, withdraw publication, disable promotions and delete posts while preserving IDs, existing permalinks, stored order and unrelated marketing data. Locked revision checks include the site timezone. Strict date parsing rejects impossible dates and invalid windows, holds malformed legacy dates out of public visibility and preserves unchanged stored instants. Banner precedence is visible in the owner list. Publication reads do not mutate campaign queues. Private-reply email alerts contain a generic notice without the private subject or message body; the full reply remains on the site.

The new source acceptance passed 134 assertions on both PHP versions. The actual extracted customer package passed 105 owner/public HTTP assertions and root-desktop/nested-mobile browser acceptance. The mandatory private-reply regression exercises actual owner POSTs and captures loopback SMTP at root and nested paths, preserving on-site replies while excluding private content from member/guest-booking emails and keeping internal notes out of email. Saved-audience source/HTTP/browser acceptance and every existing source, security, MySQL, ownership, persistent-OPcache, installer, ten-suite HTTP, add-on recovery, Media Center and browser gate remain required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.5 / Build 20261020.6** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 13:39:23 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The saved-audience and bounded publication-editing increments of #9 are complete. Marketing SQL persistence, broader editorial/scene scheduling, referral attribution and analytics remain separate open work. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](releases/0.8.5-publication-windows.md) · [Publication-window guide](PUBLICATION_WINDOWS.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- publication-windows-085:end -->

<!-- newsletter-audiences-084:start -->
## Historical release — 0.8.4 Newsletter audiences, Build 20261020.5

**Version 0.8.4 is verified, merged into `main` through [PR #20](https://github.com/afterburn25/velvet_vixxen/pull/20) and delivered.** Merge `2afd71b9f4516d8c8d8200b4c1dcaf767f0cc5ea` completed at **2026-10-20T05:58:19Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39376462679) passed. Release source `b7a7559b7fe44c97d8604af24d56539bb009098b`, actual CI checkout `13b08ae2d8f8d9aa55b693cfb978abf9e6a12f70` and the merge share the complete tree `c2bd3b95d6e55f61254507e122ccc69cb9fd804f`. Later documentation recording changes no executable, test, workflow or package bytes.

Owners can save revision-checked newsletter audiences using exact signup sources and confirmation-date bounds, inspect matching recipients and select an audience for a campaign. Queueing reevaluates current eligibility, rejects a supplied stale preview and freezes audience rules, message content and recipient identities. Explicit requeue uses the frozen rules; delivery still rechecks current consent and suppression. Preview is optional and sends no email. Audience storage remains the existing protected file store.

The new source acceptance passed 149 assertions on both PHP versions. The actual extracted customer package passed 44 owner HTTP assertions and root-desktop/nested-mobile browser acceptance. Every existing source, security, MySQL, ownership, persistent-OPcache, installer, ten-suite HTTP, add-on recovery, Media Center and browser gate remains required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.4 / Build 20261020.5** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 05:59:26 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. The saved-audience increment of #9 is complete. Continue 0.8.5 publication-window editing; broader #9 remains open. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](releases/0.8.4-newsletter-audiences.md) · [Newsletter audience guide](NEWSLETTER_AUDIENCES.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- newsletter-audiences-084:end -->

<!-- addon-recovery-083:start -->
## Historical release — 0.8.3 Add-on migration recovery, Build 20261020.4

**Version 0.8.3 is verified, merged into `main` through [PR #19](https://github.com/afterburn25/velvet_vixxen/pull/19) and delivered.** Merge `dcb229532ce55e4783b477d51bc3ffbe3f7fcfa7` completed at **2026-10-20T05:39:13Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39374591561) passed. Release source `9a21c2eb4e2032c6e81afb03092003918a63b272`, actual CI checkout `fe32a113e4465540c6a8b11730ace07240661880` and the merge share the complete tree `526c8f22fa1c79873b4d3106c48c2280c6c5755d`. Later documentation recording changes no executable, test, workflow or package bytes.

Add-on migration plans, checksums, permissions and table ownership are checked before migration SQL. Exact statement progress and independent lifecycle intent keep interrupted operations held. Previous package files remain available through registry activation. The current active owner can retry only definite recorded recovery steps after package and registry revalidation; unknown SQL/file outcomes remain held. Explicit down SQL and purge may delete data and require a database backup for restoration.

Both PHP versions passed the actual packaged MySQL lifecycle/HTTP recovery gate; the browser variant passed 197 lifecycle assertions, 34 owner HTTP assertions and 33 checks on each desktop/mobile recovery flow. All ten existing HTTP suites and every existing source, security, ownership, persistent-OPcache, installer, Media Center and browser gate remain required and passed. Exact observed runtime versions, checkout logs and artifact identities are in the linked CI record.

Full Fresh and Upgrade carry **0.8.3 / Build 20261020.4** and preserve the exact approved 13/9 root directories and four root files. Both ZIPs and the separate manifest matched the gated artifact literally, then matched the saved/retrieved files at **2026-10-20 05:40:00 UTC**. The unchanged unfinished-installer overlay remains **0.8.1 / repair Build 20261020.3** with its original saved identity and SHA-256; it was also retrieved and compared literally, without being relabeled or replaced. Installed sites use the complete Upgrade.

The user-directed version rule is permanent: every changed completed release advances the visible version; build IDs remain secondary. The delivery-aware gate rejects changed same-version packages and permits exact rebuilds. Add-on migration/recovery issue #7 is complete. Continue 0.8.4 saved newsletter audiences and 0.8.5 publication-window editing under broader #9. Actual customer-host #12/#13 and production email remain unverified; payments remain sandbox-only. Add-on PHP is not a complete sandbox, and no separate 0.9 milestone completion is claimed.

[Release and exact evidence](releases/0.8.3-addon-recovery.md) · [Add-on recovery guide](ADDON_RECOVERY.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older release records below remain historical.
<!-- addon-recovery-083:end -->

<!-- media-center-082:start -->
## Historical release — 0.8.2 Media Center, Build 20261020.3

**Version 0.8.2 is verified, merged into `main` through [PR #18](https://github.com/afterburn25/velvet_vixxen/pull/18) and delivered.** The merge is `e1dc2069ab62b9399a968dfd86602a5c30b9fcaf` at **2026-10-20T04:58:45Z**. All seven jobs in [the required CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39370771018) passed. Release source `91d5cee9d7625d4f0eaf111f5923695ae681fa58`, actual CI checkout `7cd686fad6a49cd794d7e566854faf28d414cb98` and the merge share the complete tree `e2e4a338593c8e78cd86d433bed262ee743fcb02`. Later documentation recording changes no executable, test, workflow or package bytes.

Gallery membership, order, captions and shared tags now persist exactly, including removals and mobile ordering. Playlist order and scheduling dates survive edits; stale forms are rejected. Bounded due publication preserves access and dates, and public playlists retain entitlement checks. Ordinary media edits preserve existing membership-tier rules. The full source/security/ownership/OPcache/package matrix, ten HTTP suites and required browser acceptance passed, with 116 file, 108 real-MySQL and 198 Media Center HTTP assertions. All four actual Fresh-package Media Center cases passed with file storage/MySQL at root desktop and nested mobile; the existing packaged database installer passed 957 assertions on each PHP version.

The three ZIPs and separate manifest matched GitHub's gated artifact literally, then matched the saved/retrieved files at **2026-10-20 04:59:42 UTC**. Full Fresh and Upgrade carry **0.8.2 / Build 20261020.3**, with the approved 13/9 root directories and four root files. The separate unfinished-installer repair targets incomplete **0.8.1** setup and does not advance its runtime build. Installed sites use the complete Upgrade. The former focused Media/Composer overlay is retired.

The user-directed version rule remains permanent: advance the visible version for every completed release and keep build IDs secondary. Media issue **#2 is complete**. Continue addon-owned MySQL migration/recovery **#7** as **0.8.3**, then newsletter audience controls within **#9** as **0.8.4**, each with its own gates and delivery evidence. Broader marketing, actual-host **#12/#13** and production email remain unverified; payments stay sandbox-only. The separate 0.9 milestone is not claimed.

[Release and exact evidence](releases/0.8.2-media-center.md) · [Media Center owner guide](MEDIA_CENTER.md) · [Apply and verify the Upgrade](UPDATE_VERIFICATION.md). Older records below remain historical.
<!-- media-center-082:end -->

## Build 20261020.1 grouped buttons and campaign delivery

**Executed release evidence:** all seven [CI jobs in run 39352867755](https://github.com/afterburn25/velvet_vixxen/actions/runs/39352867755) passed against the complete source tree of `9fcd355c6f7207287ab42babe04c80b3f3968a62`; PR #16 is actually merged and saved downloads match the gated bytes. See the grouped-buttons CI/delivery records and current `docs/TEST_REPORT.md`. The earlier root-layout and installed-update blocks below are historical evidence, not the current branch or merge state.

The required source suite now includes `tests/Feature/scene_group_buttons.php` (139 assertions) and `tests/Feature/campaign_delivery.php` (41 assertions including concurrent storage writers). The source matrix explicitly enables DOM for the actual public-HTML regression; the application adds no runtime extension requirement.

`bash scripts/test_http.sh` now runs eight suites, including `tests/Http/campaign_delivery_http.py`. That new suite uses only reserved synthetic recipients and a server bound to 127.0.0.1. It tests real configured SMTP and HTTP actions, nested unsubscribe, schedules, independent switches, duplicate exclusion, retries, uncertain acknowledgement and concurrent unsubscribe/pause. Two PHP workers share the owner's real session so a held session lock cannot make the pause test pass incorrectly.

Required packaged browser commands run directly, in addition to all thirteen existing application-browser suites:

```bash
python tests/Browser/composer_group_buttons_playwright.py
python tests/Browser/campaign_delivery_playwright.py
```

Each builds/extracts the actual customer ZIP and serves physical files with no fallback router. Group acceptance clicks the actual canvas group and Button 3 inspector, edits real controls, manipulates all device layouts, retains Preview, publishes/restores, follows twelve public Booking links and two new-tab destinations, and rejects unsafe URLs. Campaign acceptance uses the real desktop/mobile owner form and queue controls, refresh idempotence and independent route switches; email stays disabled in that browser suite. Both retain actual screenshots and sanitized fixture evidence outside customer runtime ZIPs.

All previous PHP 8.2/8.3, MySQL 8, foreign ownership, persistent PHP cache, installer/update, route, image, Booking Builder, admin-state, merchandise/PPV, secret and package gates remain required. Runtime artifact creation depends on those gates. A push and its PR now share a concurrency group scoped by workflow, source repository and branch, so a redundant run does not occupy a second full matrix; forks and main remain separate. See [GitHub's concurrency syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax#concurrency) and [context definitions](https://docs.github.com/en/actions/reference/workflows-and-actions/contexts).

The current local and GitHub results belong in `docs/releases/0.8.1-group-buttons-campaign-delivery.md` and `docs/TEST_REPORT.md`. Prior runs remain historical and must not be substituted for the new checkout's release gates.

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

Tests are mandatory release gates. Use PHP 8.2 or 8.3 with PDO MySQL, mbstring, fileinfo, OpenSSL, GD, Zip and sodium; Node for JavaScript syntax; Python with requests, BeautifulSoup and Playwright for HTTP/browser suites.

## Source and packaging

```bash
bash scripts/test.sh
python3 scripts/package.py --output artifacts/runtime
```

The source harness checks repository safety, copies clean runtime to a marked disposable directory, and runs source/installer/unit/feature/package regressions there. Never seed an installed demo in the working source tree or a real site. `scripts/clean_runtime.sh` is guarded and must not be used as a general site cleanup command.

## HTTP and browser

```bash
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
VELVET_BROWSER_TESTS=1 VELVET_TEST_PORT=8767 bash scripts/test_http.sh
```

The harness first execs its own disposable snapshot, starts and stops a real PHP server, and resets fixtures between suites. The aggregate runs 7 HTTP suites or 13 actual browser suites, including separate Composer buttons and 30 library scenes rendered at three viewport widths. `VELVET_TEST_SUITE` selects one exact test path. `VELVET_SCREENSHOT_DIR` stores actual browser evidence outside customer ZIPs. Set `VELVET_CHROMIUM` only when selecting an existing compatible browser. Browser interaction tests must run, not just compile.

## MySQL 8 migration gate

Provide `VELVET_TEST_DB_HOST`, `VELVET_TEST_DB_PORT`, `VELVET_TEST_DB_NAME`, `VELVET_TEST_DB_USER` and `VELVET_TEST_DB_PASSWORD` through environment configuration for a disposable test database, then run:

```bash
php tests/Database/migrations_mysql.php
```

The test rejects unsafe/non-empty databases, verifies a real prior 12→14 installed-site update with preserved configuration/keys/bookings/uploads, applies all migrations, checks 82 declared tables, repeats apply to verify idempotency, exercises merchandise/booking transactions, rolls back, and verifies no tables remain. Failed installed-site updates never invoke destructive down migrations; rollback in this test is limited to its disposable database. MariaDB results must be labelled MariaDB; they do not substitute for the MySQL 8 CI gate.

## Required final evidence

Record actual commands, versions, results, feature coverage and GitHub run URLs in `TEST_REPORT.md`. New user-facing 0.8.1 behavior has the explicit checklist in `../INCOMPLETE_WORK.md`. A configured but unexecuted CI job is pending. Customer ZIPs contain runtime only and start uninstalled; source, docs, workflows, SDK, tests, screenshots and all real site data stay outside them.

## Packaged installer ownership regression

`python3 tests/Installer/packaged_preflight.py` builds the actual fresh runtime ZIP, extracts it into a disposable fixture and runs PHP as an unprivileged user against separately owned static trees. The test requires Linux with root or passwordless sudo for that fixture; it must fail clearly if real separate ownership cannot be tested. The source matrix runs this gate on PHP 8.2 and 8.3. No production hosting paths or data are used.

## Timestamp-preserving installer repair

`tests/Installer/packaged_opcache.py` is a mandatory source-suite gate. It requires PHP OPcache and `unzip`, runs an isolated persistent localhost PHP process, demonstrates a stale same-timestamp negative control, and extracts the actual allowlisted runtime repair ZIP to verify corrected execution without a PHP restart or explicit cache invalidation. The test fails if OPcache/caching/timestamp preservation is ineffective. `scripts/package.py` keeps the repaired service component timestamp deterministic and later than the prior package; advance it for subsequent unfinished-installer service corrections. Fresh, upgrade and repair timestamps match for each shared dependency. The current database/layout repair includes six PHP files, one stylesheet and one JavaScript file; private/runtime/development exclusions remain mandatory.

## Existing-database installer and real browser continuation

Use an explicitly named disposable test database and the same `VELVET_TEST_DB_*` environment variables as the migration gate:

```bash
php tests/Installer/existing_database_mysql.php
python3 tests/Browser/installer_existing_database_playwright.py
```

Both suites build and extract the actual fresh customer ZIP into isolated marked directories. They reject a nonempty initial fixture database so fixture cleanup cannot target existing user tables. This test-harness safety check is separate from production installer behavior: the suites create partial/full/customized application databases and prove installation continues without deleting their contents.

The PHP suite covers empty/unrelated/core-only/full/ledgerless schemas, interrupted ALTER/default work, retained migration metadata, existing account authentication, row/hash/key/configuration/upload preservation, missing-key/schema/history conflicts, held filesystem/MySQL guards, and retries after interrupted finalization. CI runs it against real MySQL 8 on both PHP 8.2 and PHP 8.3, after the existing migration apply/verify/rollback gate has restored its disposable schema to empty.

The browser suite uses the real installer HTTP page at desktop/mobile widths and an isolated MySQL service. It covers actual AJAX connection/install, CSRF and 11-character rejection, acceptance of a 12-character owner password, wrong-owner refusal, post-final-config failure followed by ordinary HTTP-bootstrap retry, preserved records, cleanup and final owner login. Expanded technical details verify Server/Folders geometry and the missing redundant rule before/after Run Checks; password fields remain aligned and completion clears its spinner/scroll lock. PNGs/logs remain engineering evidence outside every customer ZIP.

The existing seven HTTP and all application browser suites remain mandatory. Do not substitute a copied HTML form, a simulated DB response, a same-owner filesystem, a stopped/restarted cache process or a passing skip for these release gates.

## Packaged public routes, uploads and Composer actions

```bash
python3 tests/Browser/packaged_media_upload_playwright.py
python3 tests/Http/public_routes_packaged.py
python3 tests/Browser/composer_actions_playwright.py
```

These suites extract the actual runtime ZIP into disposable sites and start PHP without any router script, at both a domain root and a nested installation path. No synthetic fallback may make a missing public entry point pass. The upload suite removes the physical dispatcher and protected upload handler as negative controls, reproduces the host's literal `File not found.` response separately, and checks successful uploads, thumbnails, image decoding, retries, authentication and CSRF. The actions suite clicks the real Full Page Preview and Publish controls and follows real public button destinations at root/nested paths on desktop/mobile. It also clicks owner Posts View, default marketing links, banners and campaign destinations; verifies persistent banner dismissal; and requires exactly one new accepted campaign event with the correct installation path plus a real server POST/200 entry. Chromium can discard keepalive request/response instrumentation during navigation, so actual stored-event and server evidence determine beacon acceptance.

The source aggregate additionally runs the shared JSON response helper's Node regression, server-side button validation/persistence and public route/handler parity checks. The package gate requires the physical `route.php`, organized `public/routes` handlers and shared route map, exact original 13 Fresh / nine Upgrade root-directory allowlists, and only `.htaccess`, `VERSION`, `index.php`, `route.php` as root files. It rejects the actual Build .5 root maps instead of requiring the rejected forwarding files. The focused Media/Composer repair must include every changed dependency across its three pinned historical customer baselines. The upgrade overlay preserves configuration, storage, uploads and installed site state. New changed components receive later deterministic ZIP timestamps and a new browser asset identity. Tests, screenshots, fixture data and logs remain outside every customer ZIP.
