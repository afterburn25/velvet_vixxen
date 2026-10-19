# Session Handoff — Velvet Vixxen

<!-- media-composer-repair:start -->
## Media, Composer buttons and public route repair — 2026-10-19

Current work continues on `feature/0.8.1-site-ux-hero-library` from handoff commit `195699c12620e4803160b28f023764bebc421bb4`. The earlier installer implementation/CI below remains the verified starting baseline; it does not certify this new repair.

The actual application now has **46 physical public entry points covering all 87 registered URLs**, including upload/scene APIs, account aliases, booking, checkout, content pages and existing signed-media URLs. Their protected implementations remain under `public/routes`. This removes the missing-file dependency on rewrites that caused the reported upload JSON errors and failed scene saves. Upload/save/publish/restore handle non-JSON failures readably. Full Page Preview retains a useful error tab instead of closing it if either draft save fails.

The requested **Add Button** control adds separate live link layers with editable text, destinations, styles/colors, text size, corner radius and new-tab behavior. Buttons share the existing direct drag/free resize, numeric, keyboard, snapping, ordering, hide/lock, duplicate/delete, history and device layout controls. The public Hero/Footer renders keyboard-accessible links. Photo cutouts and the existing live text/button group remain independent. The server validates button data before writing; no database migration is needed.

The routing audit also repaired old cleanup behavior that would remove the new entry points. Current forwarding files survive; obsolete controllers and unknown sibling files move to a protected private archive with their bytes preserved. Member login/Requests/logout destinations and marketing post links, stored local calls to action, banners and analytics requests now retain a nested installation's base path. Existing external HTTP(S) destinations remain usable.

**Local verification has passed:** full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite; 127 button assertions; 90 installation-URL assertions; 371 repair-backup assertions; 566 public-route/path assertions; all seven existing HTTP suites; real packaged uploads at root/desktop and nested/mobile; and all 87 public routes before/after real owner cleanup at both installation paths. Negative controls reproduce missing entry points, the JSON parser error, the disappearing-preview cause, unsafe unvalidated buttons and the old cleanup removing 40 active entry points. Final application-browser execution, exact-source GitHub gates and saved customer downloads remain pending at this checkpoint.

The runtime-only installed-site repair contains **67 files: 60 PHP, five JavaScript and two CSS**. The fresh and upgrade ZIPs contain matching runtime bytes. Configuration, storage, uploads, customer records, docs/tests/workflows/screenshots and installer state are excluded from the small repair. The earlier eight-file Installer Database Repair remains separate and byte-identical. Source/tests/progress and issue #13 must be updated before final handoff. See `docs/MEDIA_COMPOSER_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-media-composer-repair.json`. No deployment, merge or 0.9.0 work is claimed.
<!-- media-composer-repair:end -->

## Installer continuation with existing databases — 2026-10-19

The newest executable source remains on `feature/0.8.1-site-ux-hero-library`. This continuation removes the blanket rejection of databases containing Velvet Vixxen tables. The actual installer now handles empty databases, unrelated existing tables, compatible partial schemas, complete schemas and missing migration history. It validates existing definitions and owner credentials, applies only missing work, and preserves existing records, settings, scenes, owner password hashes, installation identity and recoverable security keys. Historical migration bytes are unchanged.

A failed attempt keeps completed schema work and its pending configuration for retry. Pending configuration is not publicly installed until final verification succeeds, including a process interruption after the lock was written. Automatic recovery and installer retirement share the setup guard and cannot replace a pending checkpoint or move the installer while it is running. Existing completed installations remain protected; incompatible schema or missing original encryption keys produce specific preservation errors, never an instruction to erase the database.

The owner's additional layout correction removes the extra Folders rule/inset, aligns Server/Folders headings and check cards, and aligns Password/Confirm Password fields despite the strength meter. Completion clears the spinner and page scroll lock. Installer CSS/JavaScript have their own updated cache query. The runtime repair contains **eight files: six PHP files, one stylesheet and one JavaScript file**, including the earlier folder correction. The fresh and upgrade packages are rebuilt; the earlier one-file Folder Repair ZIP does not contain this database/layout continuation.

Local and GitHub verification are complete: the full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite passed; the actual packaged installer passed 922 assertions on MySQL 8.0.46; recovery passed 134 assertions; and real desktop/mobile browser installation, retry, layout and completion tests passed. The new SQL parser cases and original failing layout are retained or reproduced as meaningful regressions. Executable source, tests and workflow are uploaded as [`e5b04daa132521be69c943151f8112c7cd2017e8`](https://github.com/afterburn25/velvet_vixxen/commit/e5b04daa132521be69c943151f8112c7cd2017e8), tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`. All seven required jobs passed in [GitHub run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907), whose official conclusion is **completed / success**. This includes PHP 8.2.34/8.3.35 source suites, 922 actual-package MySQL installer assertions on each version, real installer desktop/mobile acceptance, seven HTTP suites, twelve application-browser suites and gated packaging. The downloaded GitHub ZIPs and manifest are byte-identical to the saved customer downloads. Matching repair, fresh-install, upgrade and separate checksum-manifest downloads were saved on 2026-10-19. See `docs/INSTALLER_DATABASE_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json` (repository-relative paths). Earlier folder/cache evidence remains historical in the test report and its release record. No 0.9.0 work or production deployment is included.

Updated: 2026-10-19. Repository: `afterburn25/velvet_vixxen`.

## Earlier verified continuation — before the installer correction

The **0.8.1 user-facing engineering continuation is implemented and verified** on `feature/0.8.1-site-ux-hero-library`. Runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c); complete GitHub CI: [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), **all six jobs passed**. PHP 8.2.34/8.3.35, JavaScript syntax, MySQL 8.0.46, installer/ownership/security, seven HTTP suites, twelve real Chromium suites and gated runtime packaging all passed. The library has 30 individual scenes and 30 thumbnails, with 90 successful published renders across desktop/tablet/mobile.

This is the verified sandbox development milestone. Payments remain sandbox-only, actual owner content must be supplied by the owner, and production hosting/live-service certification is not claimed. PR #15 contains the feature branch; inspect its current merge state separately. No 0.9.0 implementation is included.

Read `INCOMPLETE_WORK.md`, `SESSION_HANDOFF.md`, `PROJECT_STATUS.md`, `docs/ROADMAP.md`, `docs/TEST_REPORT.md` and `NEXT_SESSION_PROMPT.md` in full. Inspect the live branch, current commit, [PR #15](https://github.com/afterburn25/velvet_vixxen/pull/15), [issue #13](https://github.com/afterburn25/velvet_vixxen/issues/13), source-import issue #1 and release-gate issue #3. The original handoff and marketing branches were documentation-only. Do not replace the newer executable branch with those older records or assume main has been merged.

## Delivered scope

Composer direct dragging/extreme resizing/all-edge clipping and lower-edge crop; movable live text/button group; independent device layouts/background appearance; 30 individual luxury backgrounds and thumbnails; searchable library/drafts/publication/history; Booking Builder and immutable inquiry snapshots; shared sticky public navigation; About Me/editor; centered authentication/recovery pages; eight persistent Admin categories; merchandise/images/variants/stock/shipping/fulfillment; separate membership and direct PPV/rental checkout; manual Canva bridge; safe installed database updates.

The earlier Composer/Admin correction preserves Composer handle nodes/focus, makes the MySQL rollback fixture work without elevated privileges, corrects About spacing/empty details layout, and saves native Admin category changes before the next browser task. Its meaningful timing regression fails against the pre-fix script and passes the exact final CI engine.

## Earlier feature evidence and customer packages

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

Actual MySQL 8.0.46 CI verifies 14 migrations / 82 tables and the prior 12→14 installed-site upgrade. All seven HTTP and twelve actual Chromium browser suites pass in the same workflow. Local PHP 8.3.6/MariaDB 10.11.14/Chrome 155 results remain supplementary; MariaDB is never substituted for MySQL 8 evidence.

## Continue without losing work

The requested 0.8.1 implementation and acceptance checklist are complete. Start subsequent work from the live implementation branch and the owner's current instructions. Preserve source/tests/docs in GitHub as work lands. Inspect PR merge and artifact state before describing either; this handoff records a verified feature branch and packages, not an unobserved deployment. 0.9.0 has not been started.

Owner workflows are in `docs/VISUAL_COMPOSER.md`, `docs/BOOKING_BUILDER.md`, `docs/MERCHANDISE.md`, `docs/CANVA_WORKFLOW.md` and `docs/UPGRADING.md`. Existing database sites use System → Database Updates after a verified backup; never rerun the fresh installer over them. File-storage mode reports no SQL migration needed.

## Requirements to preserve

Approved neon-pink/magenta/black design; no new mockups or placeholder people; owner transparent photos remain separate; one-page installer; no installation key; 12-character minimum owner/member passwords; safe 0755/0775, never 0777; organized folders; runtime-only customer ZIPs; sandbox payments. Never commit credentials, customer/member/booking records, private conversations/attachments, identity records, payment secrets, raw license keys, signing private keys, logs, sessions, runtime state or private/paid media.

Test only marked disposable runtime copies/databases. Commands are in `docs/TESTING.md`. Never seed a demo in the source checkout or a real site. Upgrade packages omit config/storage/uploads/install. Recovery preserves valid active identity and unique/unknown backups; installed updates use reviewed plans, locks and operator backup acknowledgment.

Actual owner photos, biography/statistics and catalog content have not been invented. Historical advanced marketing automation/audiences/attribution/retention/MySQL-persistence claims remain unverified gaps in `docs/SOURCE_RECOVERY.md`. Production email delivery, live payments and representative hosting certification remain outside this sandbox milestone.
