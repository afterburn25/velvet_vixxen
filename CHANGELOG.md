## 0.8.6 — SQL marketing persistence, Build 20261020.7 (verified, merged and delivered)

The SQL marketing increment adds per-record MySQL persistence and a separate System → Marketing Storage owner page. Migration 015 creates two additive tables; it does not import records or change the active backend. The current active owner reviews aggregate counts, then explicitly moves the preserved marketing snapshot. Transactions, a source-bound review, installation authority, a local SQL-required intent and database-wide data/delivery locks protect the transition. Existing identifiers, tokens, consent, suppressions, saved audiences, frozen messages, queued recipients and delivery outcomes retain their meaning. The original file remains unchanged after activation.

All seven [GitHub CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39446912796) passed; [PR #22](https://github.com/afterburn25/velvet_vixxen/pull/22) merged at 2026-10-20T15:41:54Z. Customer ZIPs and the manifest match the gated artifact and saved/retrieved bytes at 2026-10-20 15:45:08 UTC.

## 0.8.5 — Publication-window editing, Build 20261020.6 (verified, merged and delivered)

Owners can reopen and edit saved posts, banners and landing pages, withdraw publication, disable promotions and delete posts while retaining stable IDs, existing permalinks, list order and unrelated marketing records. Locked revision checks include the site timezone. Strict date parsing rejects impossible dates, skipped daylight-saving times and invalid windows, preserves unchanged stored instants and holds malformed legacy dates out of public visibility. The banner list distinguishes the shown banner from later active banners waiting behind it. Private-reply email alerts now use generic notices without the private conversation subject or message body; the full reply remains on the site.

The version-integrated source suite, all ten existing installed HTTP suites and actual packaged publication HTTP/desktop/mobile acceptance passed. The new publication source test passed 134 assertions; the extracted customer ZIP passed 105 owner/public HTTP assertions plus root-desktop and nested-mobile browser flows. Saved-audience acceptance is preserved. A separate actual-package owner-POST regression captures loopback SMTP and verifies generic private-reply notices for member and guest-booking paths, retained on-site content and no email for internal notes. Tests send zero external messages. All seven [GitHub CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39378794728) passed; [PR #21](https://github.com/afterburn25/velvet_vixxen/pull/21) merged at 2026-10-20T13:33:24Z. Customer ZIPs and the manifest match the gated artifact and saved/retrieved bytes at 2026-10-20 13:39:23 UTC. See [the numbered release record](docs/releases/0.8.5-publication-windows.md).

## 0.8.4 — Newsletter audiences, Build 20261020.5 (verified, merged and delivered)

Owners can save revision-checked newsletter audiences using exact signup sources and confirmation-date bounds, preview currently eligible recipients and select an audience when drafting a campaign. Preview is optional; queueing reevaluates current eligibility and rejects a supplied stale preview. The first queue freezes audience identity/rules, message content and recipient identities; explicit later requeue appends newly eligible matches using those frozen rules. Current consent and suppression remain enforced immediately before delivery.

The version-integrated source suite, all ten existing installed HTTP suites and actual packaged audience HTTP/desktop/mobile acceptance passed. The new audience source test passed 149 assertions; the extracted customer ZIP passed 44 owner HTTP assertions plus root-desktop and nested-mobile browser flows. Tests use disabled mail and send zero external messages. All seven [GitHub CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39376462679) passed; [PR #20](https://github.com/afterburn25/velvet_vixxen/pull/20) merged at 2026-10-20T05:58:19Z. Customer ZIPs and the manifest match the gated artifact and saved/retrieved bytes at 2026-10-20 05:59:26 UTC. See [the numbered release record](docs/releases/0.8.4-newsletter-audiences.md).

## 0.8.3 — Add-on migration recovery, Build 20261020.4 (verified, merged and delivered)

- Preflight the whole migration batch and owned-table boundary; compensate only completed additive changes.
- Align package files, migration ledger and selected version on update/rollback, with independent durable recovery across SQL, file and registry boundaries.
- Add an active-owner/POST/CSRF-protected retry for definite recorded failures; hold uncertain outcomes for review.
- Add actual packaged MySQL, HTTP and desktop/mobile recovery acceptance while preserving every existing release gate.
- Enforce a visible-version increase for changed releases and preserve the unchanged installer repair's identity and exact bytes.

See [the 0.8.3 release record](docs/releases/0.8.3-addon-recovery.md). Local full source, ten HTTP suites and packaged MySQL/desktop/mobile recovery passed. All seven [GitHub CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39374591561) passed; [PR #19](https://github.com/afterburn25/velvet_vixxen/pull/19) merged at 2026-10-20T05:39:13Z. Customer ZIPs and the manifest match the gated artifact and saved/retrieved bytes at 2026-10-20 05:40:00 UTC. Down SQL and purge can delete data and require a database backup to restore it. Broader #9 and actual-host #12/#13 remain open.

## 0.8.2 — Media Center, Build 20261020.3 (verified, merged and delivered)

- Advance visible release numbers with each completed release; use canonical version metadata throughout the runtime and installer.
- Edit gallery captions and shared photo tags; save exactly the retained photographs and their order, with mobile controls and undo before saving.
- Preserve playlist playback order and scheduling dates; reject stale gallery/playlist forms without overwriting newer changes.
- Apply related gallery/item/tag writes atomically in file storage and MySQL, and prevent common file-store writers from silently replacing concurrent changes.
- Publish bounded due media through an active-owner/CSRF-protected action or explicit hosting CLI; preserve access/date rules and idempotency.
- Enforce playlist access before offering its public queue, with each track's existing access/preview rules.
- Preserve approved roots, verify changed upload timestamps against the exact previous Fresh archive, and use complete 0.8.2 Fresh/Upgrade packages. Keep the unfinished 0.8.1 installer repair explicitly targeted; retire the former focused Media/Composer overlay for new releases.

Local source, ten HTTP suites, real MySQL and all four packaged browser cases passed. All seven [GitHub CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39370771018) passed on the identical source/checkout/merge tree; [PR #18](https://github.com/afterburn25/velvet_vixxen/pull/18) merged at 2026-10-20T04:58:45Z. The three ZIPs and manifest matched the gated artifact and saved/retrieved bytes at 2026-10-20 04:59:42 UTC. See [the 0.8.2 release record](docs/releases/0.8.2-media-center.md). After Media Center #2, continue addon-owned MySQL migration acceptance #7 and remaining marketing #9.

## 0.8.1 — Build 20261020.2 owner Email & SMTP (2026-10-20)

Verified, merged into `main` through [PR #17](https://github.com/afterburn25/velvet_vixxen/pull/17) at **2026-10-20 03:10:04 UTC**, commit `65bea278efacd59e8ba24401cc3fe7d47300a7d4`, and delivered. All seven jobs in [CI run 39361581062](https://github.com/afterburn25/velvet_vixxen/actions/runs/39361581062) passed against the complete tree shared by release source `20384c93235749a6ae13b2686c34a37af5fec9bd`, actual checkout `77062d02579e93c573e8c7955a5362220199ac59` and the merge. All four ZIPs and the separate manifest were saved and retrieved again with exact equality to the gated artifact at **2026-10-20 03:11:47 UTC**. See [the release record](docs/releases/0.8.1-owner-mail-settings.md) and its CI/delivery JSON evidence.

- Add **System → Email & SMTP** in the existing owner dashboard, with Disabled/SMTP/PHP mail, sender details, saved transport status and related feature controls.
- Preserve blank passwords, provide explicit clearing, reject stale configuration forms, and keep saved credentials out of HTML and audit metadata. Save changes without sending email.
- Add a one-use, rate-limited test to the current owner using the saved revision, with fresh authorization/configuration checks before SMTP DATA and honest accepted/rejected/unknown results. Clear prior results when settings change.
- Validate runtime and installer addresses before normalization; retain ordinary pasted Gmail/plus-address forms, reject unsafe headers and use explicit From → email-shaped SMTP username → owner fallback.
- Keep existing protected plaintext configuration compatibility and mail-only locked merges; no encrypted-credential migration is claimed.
- Name all four ZIPs with Build 20261020.2, preserve exact approved roots and add all runtime dependencies. Expand the unfinished-installer repair to 11 files, including all four changed sender files; its `repair_build_id` does not advance an installed site's build.
- Record 199 backend, 269 installer validation, 143 packaged installer and 168 owner HTTP assertions, plus two packaged desktop/mobile owner browser cases using only loopback SMTP. Required PHP 8.2/8.3, MySQL 8, nine HTTP suites, existing browser, security/ownership/OPcache and reproducible-package gates passed. Final CI browser: PHP 8.3.35, Playwright 1.62.0, Chromium 151.0.7922.34; the earlier local Chromium 155 archive remains intermediate evidence.

Sender issue #10 is closed; broader #9 remains open. Next is media #2: caption editing and exact gallery-item/order MySQL/browser persistence, then asset tags with SQL hydration, then playlist dates and due publication. Follow with #7 and remaining #9. Actual-host #12/#13 stay open; production mail remains unverified and payments remain sandbox-only. Previous release entries below retain their historical evidence.

## 0.8.1 — Build 20261020.1 grouped buttons and campaign delivery (2026-10-20)

Merged into main through PR #16 (`0c4d6be5516c9c777c31524a33cd30c4d337f1d7`), with all seven jobs in CI run `39352867755` successful and saved downloads verified byte for byte against its gated artifact. Adds editable third/additional buttons inside the existing Live Text + Buttons inspector, preserving automatic account/trailer actions, shared labels/links and independent device geometry/history. Connects queued campaigns to the existing configured mail transport with consent/suppression checks, queue deduplication, bounded claims, responsive owner pause/unsubscribe, explicit failure retries and unknown-outcome holds. Adds independent newsletter/delivery switches and actual packaged UI/localhost SMTP regressions. Exact approved root folders and runtime-only packages are preserved.

PR #15's verified baseline is now actually merged into main. Source-import #1 and imported/gated engineering #5/#8/#11 are closed; old archive PR #4 is superseded. The actual customer host and production mail remain unverified; payments stay sandbox-only; no 0.9.0 work is included. See the current release record and roadmap for evidence and remaining gaps.

## 0.8.1 — Build 20261019.6 organized root correction (2026-10-19)

- Reject Build 20261019.5 for reintroducing 25 root folders and 46 forwarding files against the approved layout.
- Restore the exact original Fresh/Upgrade root allowlists and route generated page/API URLs through the single physical dispatcher with protected implementations under `public/routes`.
- Add an owner-only repair entry that bypasses cached .5 update code, verifies the current runtime and retires only exact known forwarding files. Preserve custom siblings and all installed data; retain reversible nonexecuting archive records.
- Disable the former broad root-archive action/API. Existing repair-backup inspection, missing-file recovery and verified duplicate deletion remain available.
- Add actual rejected-ZIP upgrade/cleanup/browser tests and independent source/package root-directory guards. All seven final [CI jobs](https://github.com/afterburn25/velvet_vixxen/actions/runs/39328060458) passed against the complete tree of source `e58f9edd708fd42dee0e6c898170e67eb4a01a53`. The downloaded CI artifact, frozen build and all four saved replacement downloads match literally; see the root-layout CI/delivery records.

## 0.8.1 — Installed-build verification and PHP cache refresh (Build 20261019.5, rejected layout)

- Reopen the owner's unchanged-installation report; retain prior CI/download evidence without treating it as hosted deployment proof.
- Show a distinct build ID in the owner interface and Fresh/Upgrade/Media repair filenames. Keep core compatibility version 0.8.1.
- Generate a complete shared-runtime hash inventory and add owner-only Update Status with missing/mixed-file reporting.
- Refresh only verified application PHP after owner/CSRF checks, including hosts that retain old compiled PHP after extraction. Preserve existing account, configuration, keys, database and uploaded content.
- Exercise the actual historical installed package, retained browser session and persistent PHP cache; record real Apache/FPM missing-entrypoint checks separately.
- All seven jobs in [PR run 39315788377](https://github.com/afterburn25/velvet_vixxen/actions/runs/39315788377) passed against the identical complete source tree of commit `2bc178013c9c528f45a5d0447624f3cbaf4395f2`. The gated archives and four saved replacement downloads were compared literally and match. See `docs/TEST_REPORT.md`. The owner's affected host remains unaccessed and unresolved.

## 0.8.1 — Media, Composer buttons and public route repair (2026-10-19)

- Restore physical public page/API entry points while retaining the protected implementation under `public/routes`, including account aliases and existing signed-media URLs.
- Repair Library and Composer upload paths, parse non-JSON host errors readably, and preserve authenticated/CSRF-protected handler behavior.
- Keep Full Page Preview open with an actionable error if saving fails; use the repaired scene API for Save Draft, Publish and Restore.
- Add independent live button layers with labels, destinations, colors/styles, device-specific geometry and existing layer/history controls. Validate button data before saving.
- Preserve the new route entry points during legacy cleanup; privately archive obsolete controllers and unknown sibling files without losing their bytes.
- Keep member login returns, marketing links, banners, post links and campaign events inside root or subfolder installations as configured.
- Extend actual-package no-rewrite routing/upload/action acceptance and add server/browser button tests. Build a runtime-only Media/Composer repair alongside fresh/upgrade packages.
- All seven jobs in [run 39304801698](https://github.com/afterburn25/velvet_vixxen/actions/runs/39304801698) passed on source `db759342e401617c9933c13b81a147ad9f8fd515`; runtime repair/fresh/upgrade downloads and the separate manifest were saved after literal comparison with the gated artifact. Exact evidence is in `docs/TEST_REPORT.md`.

## 0.8.1 — Existing-database installer and alignment repair (2026-10-19)

- Continue compatible empty, unrelated, partial, complete and ledgerless databases using selective schema reconciliation without editing historical migration checksums.
- Verify the existing owner login and preserve owner hashes, rows, settings, scenes, installation identity and original security keys; add only missing defaults and permissions.
- Keep completed DDL/configuration available after failure, serialize setup/recovery/retirement, and activate pending setup only after final verification.
- Remove the extra Folders rule/inset; align Server/Folders checks and Password/Confirm Password inputs. Refresh installer asset cache identity and clear loading/spinner/scroll-lock state after success.
- Build an eight-file runtime repair overlay plus updated fresh/upgrade ZIPs. Add mandatory actual-package MySQL tests on PHP 8.2/8.3 and desktop/mobile HTTP/browser installer acceptance; retain ownership/OPcache and all prior feature gates.
- Exact executed evidence and current delivery status are in `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json`.

## 0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise (2026-10-19)

## 0.8.1 — Timestamp-aware installer folder repair (2026-10-19)

- Advance only the corrected installer service's ZIP timestamp in fresh and one-file repair distributions so a timestamp-preserving extraction reloads the changed service in PHP OPcache.
- Add a mandatory persistent HTTP/OPcache regression with a demonstrated same-timestamp negative control and real repair-ZIP positive control.
- Preserve identical corrected PHP bytes, safe modes, deterministic archives and the unchanged standard upgrade package.


## 0.8.1 installer folder correction — 2026-10-19

- Remove unused empty image/font/icon/template/seeds scaffolding from mandatory installer readiness.
- Read-only packaged directories require presence/readability, without PHP write access or permission changes. Real missing application directories still block installation.
- Add a fresh-ZIP regression under different panel/PHP ownership and a one-file repair distribution for unfinished installs. New verification is recorded in `docs/TEST_REPORT.md`.

- Recover the checksum-verified 0.8.1 executable runtime into the repository and restore engineering tests/SDK.
- Reconcile the separate 0.7.4 Visual Composer fixes with the newer router/recovery source.
- Validate matching installation identities and prevent reinstall over retained configuration or keys.
- Preserve active configuration and unique repair-backup data; add focused recovery regressions.
- Build reproducible runtime-only fresh-install and state-preserving upgrade packages.
- Require real source, database, HTTP, and browser checks instead of a passing missing-source skip.
- Add direct Composer controls, 0.5–400% resizing, reachable handles, all-edge clipping/lower-edge crop, live text/button geometry and independent device backgrounds/layouts.
- Bundle 30 standalone luxury backgrounds and thumbnails with a searchable validated library, draft previews and publication rollback.
- Add Booking Form Builder with typed field management, client/server validation, live phone formatting and immutable inquiry snapshots.
- Add dedicated About Me/editor, shared sticky public navigation, centered account/recovery layouts and persistent grouped administration.
- Add merchandise products/images/variants/stock/shipping, merchandise-only cart, private delivery details and owner fulfillment/tracking. Protect stock from stale editor saves.
- Separate membership and direct PPV/rental checkout from merchandise; preserve My Library and refund/chargeback revocation.
- Add reviewed owner/CLI database updates for existing sites, with locks and no destructive automatic rollback on failed upgrade DDL.
- Document and display Canva's manual saved-link/export/import workflow with separate cutouts and live functional content.
- Centralize asset cache identity without changing the 0.8.1 compatibility version; render nonimage Media Library assets as file cards.
- Preserve selected Composer resize/move nodes and keyboard focus through image loads and canvas resizing; correct About page margins and empty sidebar layout.
- Save Admin category changes before navigation, preserve explicit collapsed active categories, and cover immediate refresh and keyboard activation.
- Verify PHP 8.2/8.3, MySQL 8, all seven HTTP and twelve Chromium suites, repository safety and gated reproducible runtime packages in GitHub run 39213140477; record exact source/artifact hashes and upload the completed progress evidence.

# Changelog

## 0.8.0 — Marketing, Publishing & Analytics

**Historical package-era claims:** this section records the earlier handoff and is not a certification of the recovered implementation. `docs/SOURCE_RECOVERY.md` identifies missing audiences/expiration, scheduled-scene automation, attribution/retention and MySQL marketing persistence. The verified baseline schema has 78 tables, not 75. Current results are in `docs/TEST_REPORT.md`.

### Added

- Blog, personal-update, and announcement publishing
- Draft, scheduled, published, archived, and expiring editorial states
- Public, registered-member, and subscriber visibility
- Categories, tags, SEO metadata, featured images, and featured placement
- Public Blog/Updates index and post pages
- Newsletter single- and double-opt-in flows
- Signed confirmation and unsubscribe tokens
- Subscriber tags, segmentation, consent, suppression, campaign queues, and delivery-state tracking
- Timed promotional banners and campaign landing pages
- Scheduled Hero/Footer Visual Composer scenes
- Lock-aware and idempotent marketing task runner
- Link-in-bio page and click tracking
- Referral clicks, order attribution, and attributed-revenue foundations
- Same-origin first-party analytics and foreign-origin rejection
- Marketing and Analytics administration
- Posts, newsletter, referrals, and analytics CSV exports
- Analytics and consent-record retention controls
- Twelfth migration, bringing the declared schema to 75 tables

### Changed

- Homepage now supports active promotional banners, latest posts, and newsletter signup
- Header/footer navigation now exposes Updates and Links
- Registration and checkout preserve referral attribution
- Built-in root image assets and uploaded image assets now resolve through the correct URL paths
- Disabled marketing modules return 404, while unsubscribe remains available for safety and compliance
- Public installation ZIP retains only runtime files and now includes `scripts/run_marketing.php`

### Fixed

- Scheduled file-store scene publication no longer overwrites a newly published scene with stale store data
- MySQL scheduled-scene processing atomically claims `scheduled -> processing` before publication
- Built-in post and campaign artwork no longer incorrectly resolves under `/uploads/images/`

### Testing

- 128 runtime PHP files passed syntax validation
- 7 public JavaScript files passed syntax validation
- Migration manifest passed: 12 migrations / 75 tables
- Marketing file-store, Marketing HTTP, full-site HTTP smoke, and installer smoke suites passed
- Extracted runtime ZIP passed integrity, checksum, clean-state, exclusions, and security checks

### Limitations

- Live local MySQL DDL execution remains CI-owned because PDO MySQL/MySQL/MariaDB was unavailable
- Full interactive Playwright acceptance remains CI or representative-hosting work
- Payment processing remains sandbox-only

## 0.7.3 — Installer Verification & Simplified Setup Hotfix

- Fixed stale OPcache/path-cache behavior during final configuration verification
- Added exact configuration byte/hash verification and one-time verification loading
- Simplified the installer to Database, Owner and Website, and Install sections
- Collapsed detailed server/folder diagnostics unless attention is required

## 0.7.2 — Installer Email Validation Hotfix

- Accepted normalized valid email addresses containing pasted whitespace or common invisible Unicode characters
- Stopped validating hidden sender/SMTP fields when outgoing email is configured later
- Defaulted support and sender email to the owner email

## 0.7.1 — Ownership-Safe Installer Hotfix

- Added ownership-safe replacement of panel-owned runtime directories that PHP could read but not write
- Preserved existing contents while creating PHP-owned writable replacements
- Added all missing upload, storage, add-on, backup, cache, log, session, and temporary directories
- Removed development-only docs/tests folder checks from the customer installer
- Added ownership-safe `/install` retirement without `0777`

## 0.7.0 — Messaging, Booking & Member Requests

- Added private member-to-owner message threads with protected attachments
- Added content-request intake, budgets, quotes, statuses, and owner/member replies
- Added public and signed-in booking/appearance inquiry workflows
- Added notifications, saved replies, communication blocks, owner queues, and settings
- Added eleventh migration / 64 declared tables
- Adopted the runtime-only public installation ZIP policy

## 0.6.1 — Commercial Add-on Licensing & Remote Updates

- Added commercial license products, issuance, activation, site limits, domain binding, encrypted local keys, offline grace, Ed25519-signed responses, release channels, protected downloads, remote update review, and rollback
- Added tenth migration / 55 declared tables

## 0.6.0 — Add-on Framework

- Added validated add-on ZIP inspection, permissions, compatibility, dependencies, conflicts, checksums, signatures, migrations, backups, Safe Mode, lifecycle management, routes, widgets, events, filters, services, settings, and scheduled tasks
- Added ninth migration / 48 declared tables

## 0.5.0 — Subscriptions & PPV Commerce

- Added Store, cart, sandbox checkout, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, billing, refunds, signed webhooks, and commerce administration
- Added eighth migration / 44 declared tables

## 0.4.0 — Members & Entitlements

- Added registration, verification, login/recovery, device sessions, dashboard, My Library, plans, subscriptions, direct entitlements, unified access resolution, and synchronized media state
- Added seventh migration / 38 declared tables

## 0.3.0 — Media Center

- Added persistent music player, albums/playlists, photo galleries/lightbox, video library/player, protected media, scheduling, and media administration

## 0.2.2 — One-Page Browser Installer

- Replaced the wizard with one responsive installer page while preserving no install key, 12-character minimum passwords, safe permissions, migrations, rollback, lock, and cleanup

## 0.2.0–0.2.1 — Design, Composer, Canva & Installer Foundation

- Added the approved homepage, Hero/Footer composer, Canva templates/import, owner login, age gate, browser installer, organized folders, and migration foundation
