# Nikki Wilder — The Velvet Vixxen

<!-- newsletter-audiences-084:start -->
## Current work — 0.8.4 Newsletter audiences, Build 20261020.5

The next numbered release advances the visible version to **0.8.4** from verified, merged and delivered **0.8.3**. Integration starts at authoritative `main` commit `65c5657b19a24fc1477c207bfeab3345049d13ac` on `feature/0.8.4-audience-release`. Final GitHub CI, merge and saved-download equality remain pending for this changed tree.

Owners can save revision-checked newsletter audiences using exact signup sources and confirmation-date bounds, preview currently eligible recipients and select an audience when drafting a campaign. Preview is optional; queueing reevaluates current eligibility and rejects a supplied stale preview. The first queue freezes audience identity/rules, message content and recipient identities; explicit later requeue appends newly eligible matches using those frozen rules. Current consent and suppression remain enforced immediately before delivery.

The version-integrated source suite, all ten existing installed HTTP suites and actual packaged audience HTTP/desktop/mobile acceptance passed. The new audience source test passed 149 assertions; the extracted customer ZIP passed 44 owner HTTP assertions plus root-desktop and nested-mobile browser flows. Tests use disabled mail and send zero external messages. All seven GitHub jobs must still pass against this exact integrated tree; previous releases certify their own source only.

Full Fresh and Upgrade use **0.8.4 / Build 20261020.5**, preserving the exact approved 13/9 directories and four root files. Changed runtime entries advance from the exact prior Fresh archive; unchanged entries retain their historical timestamps. The unchanged eleven-file unfinished-installer repair retains **0.8.1 / Build 20261020.3**, its delivered hash and saved identity. The permanent release gate rejects changed packages under an already delivered visible version, including build-only relabeling.

Audience storage remains the existing protected file store, including when the main application uses MySQL. Signup source is a recorded filter value, not a trusted membership, purchase or referral attribute. Preview does not queue or send mail. Queueing does not certify configured production mail or inbox delivery. Continue 0.8.5 publication-window editing after this release. Broader #9 remains open for marketing SQL persistence, editorial/scene scheduling, referral attribution, analytics and provider hooks. Actual-host #12/#13 and production email remain unverified; payments remain sandbox-only. No separate 0.9 milestone completion is claimed.

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

The current Build 20261020.2 installer-only repair contains 11 runtime files, including the earlier folder/database correction and all changed installer sender dependencies. Its `repair_build_id` identifies the overlay without changing the installed runtime build. [Current verification](docs/TEST_REPORT.md) and [the current release record](docs/releases/0.8.1-owner-mail-settings.md) record completed exact-source CI, merge and byte-verified delivery. The [original eight-file evidence](docs/releases/0.8.1-installer-database-repair.json) and older one-file folder repair are historical.

## Current integration: 0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise

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

The payment adapter is sandbox-only. Marketing uses a protected file store, consent-aware queues and guarded dispatch through the configured mail transport; broader segmentation/SQL, editorial/calendar, scheduling, attribution, retention and bounce work remains incomplete. Production email-provider delivery and representative shared-hosting installation/upgrade certification remain unverified; local and CI fixtures do not establish deployment on the affected customer's host. The application is not labelled a production-certified 1.0 release.

Never commit installed configuration, credentials, private customer/member/booking data, private messages, identity documents, generated keys, or private/paid media.
