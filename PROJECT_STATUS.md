# Project Status — Nikki Wilder / The Velvet Vixxen

## Installer continuation with existing databases — 2026-10-19

The newest executable source remains on `feature/0.8.1-site-ux-hero-library`. This continuation removes the blanket rejection of databases containing Velvet Vixxen tables. The actual installer now handles empty databases, unrelated existing tables, compatible partial schemas, complete schemas and missing migration history. It validates existing definitions and owner credentials, applies only missing work, and preserves existing records, settings, scenes, owner password hashes, installation identity and recoverable security keys. Historical migration bytes are unchanged.

A failed attempt keeps completed schema work and its pending configuration for retry. Pending configuration is not publicly installed until final verification succeeds, including a process interruption after the lock was written. Automatic recovery and installer retirement share the setup guard and cannot replace a pending checkpoint or move the installer while it is running. Existing completed installations remain protected; incompatible schema or missing original encryption keys produce specific preservation errors, never an instruction to erase the database.

The owner's additional layout correction removes the extra Folders rule/inset, aligns Server/Folders headings and check cards, and aligns Password/Confirm Password fields despite the strength meter. Completion clears the spinner and page scroll lock. Installer CSS/JavaScript have their own updated cache query. The runtime repair contains **eight files: six PHP files, one stylesheet and one JavaScript file**, including the earlier folder correction. The fresh and upgrade packages are rebuilt; the earlier one-file Folder Repair ZIP does not contain this database/layout continuation.

Local and GitHub verification are complete: the full PHP 8.3.6 source/JavaScript/security/ownership/OPcache/package suite passed; the actual packaged installer passed 922 assertions on MySQL 8.0.46; recovery passed 134 assertions; and real desktop/mobile browser installation, retry, layout and completion tests passed. The new SQL parser cases and original failing layout are retained or reproduced as meaningful regressions. Executable source, tests and workflow are uploaded as [`e5b04daa132521be69c943151f8112c7cd2017e8`](https://github.com/afterburn25/velvet_vixxen/commit/e5b04daa132521be69c943151f8112c7cd2017e8), tree `3cf10b7ac74f66a59e2a86ddc20a19b567f3f570`. All seven required jobs passed in [GitHub run 39294335907](https://github.com/afterburn25/velvet_vixxen/actions/runs/39294335907), whose official conclusion is **completed / success**. This includes PHP 8.2.34/8.3.35 source suites, 922 actual-package MySQL installer assertions on each version, real installer desktop/mobile acceptance, seven HTTP suites, twelve application-browser suites and gated packaging. The downloaded GitHub ZIPs and manifest are byte-identical to the saved customer downloads. Matching repair, fresh-install, upgrade and separate checksum-manifest downloads were saved on 2026-10-19. See `docs/INSTALLER_DATABASE_REPAIR.md`, `docs/TEST_REPORT.md` and `docs/releases/0.8.1-installer-database-repair.json` (repository-relative paths). Earlier folder/cache evidence remains historical in the test report and its release record. No 0.9.0 work or production deployment is included.

Repository: `afterburn25/velvet_vixxen`. Updated: 2026-10-19.

## Earlier verified implementation — before the installer correction

The **0.8.1 user-facing engineering continuation is implemented and verified** on `feature/0.8.1-site-ux-hero-library`. Runtime source: [`ca527935e0737f55b0b79ea8d88539e0c44bf97c`](https://github.com/afterburn25/velvet_vixxen/commit/ca527935e0737f55b0b79ea8d88539e0c44bf97c); complete GitHub CI: [39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477), **all six jobs passed**. PHP 8.2.34/8.3.35, JavaScript syntax, MySQL 8.0.46, installer/ownership/security, seven HTTP suites, twelve real Chromium suites and gated runtime packaging all passed. The library has 30 individual scenes and 30 thumbnails, with 90 successful published renders across desktop/tablet/mobile.

This is the verified sandbox development milestone. Payments remain sandbox-only, actual owner content must be supplied by the owner, and production hosting/live-service certification is not claimed. PR #15 contains the feature branch; inspect its current merge state separately. No 0.9.0 implementation is included.

Source recovery checkpoint [`43945af07c145c67db2f2883a4fa7f607215dc23`](https://github.com/afterburn25/velvet_vixxen/commit/43945af07c145c67db2f2883a4fa7f607215dc23) imported the checksum-verified 0.8.1 runtime and engineering tests. The historical input archive SHA-256 is `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`; its 211 runtime files/132 PHP files describe the recovered input, not the current distribution. Provenance remains in `docs/SOURCE_RECOVERY.md`.

## Executable 0.8.1 additions

| Area | Implemented behavior and evidence |
| --- | --- |
| Visual Composer | Continuous direct drag, aspect-preserving 0.5–400% resize, coordinates beyond every edge, reachable handles, public clipping, lower-edge crop, live text/button movement and resize, independent desktop/tablet/mobile layouts, background pan/focal/zoom/appearance, undo/redo, ordering, hide/lock, duplicate/delete, snapping, keyboard and numeric controls. Real browser controls pass. |
| Hero Library | 30 individual people-free luxury images plus 30 optimized thumbnails, categories/search/placement metadata, immediate draft preview, publish/version/rollback. Chromium decoded every asset and completed 90 published renders across three viewport widths. No owner-photo substitutes were added. |
| Booking Builder | Owner field CRUD, enable/disable/order, required/optional, seven field types, linked radios, checkbox minima, dropdowns, client/server email and phone validation, live phone formatting, immutable question/answer snapshots, preserved earlier inquiries. Source, HTTP, SQL and desktop/mobile browser tests pass. |
| Public site | Shared sticky navigation, About Me immediately after Booking, no Contact navigation entry, dedicated biography/statistics/photo/CTA page and owner editor, centered standalone account/recovery pages. Desktop/tablet/mobile interaction and layout tests pass. |
| Admin | Eight logical collapsible groups, persisted expansion, correct active highlight and current page after reload/return, usable mobile menu. Marketing now uses the shared admin shell. |
| Commerce | Merchandise products/images/descriptions/prices, variants/sizes/options, stock/status, per-item shipping, merchandise-only cart, private delivery addresses, sandbox checkout, receipts and owner fulfillment/tracking. Stale catalog edits cannot restore sold stock. Membership and direct PPV/rental flows stay separate; refunds/chargebacks and My Library revocation remain covered. |
| Installed updates | System → Database Updates and `app/Console/migrate.php` inspect/apply pending migrations after owner backup acknowledgment. Matching identity, reviewed plans and locking protect existing installations. Real 12→14 upgrade preserves records/configuration/keys/uploads; failures never invoke destructive automatic down migrations. |
| Canva | Saved-link/manual export/import explanation in the real admin. Imported Hero/Footer artwork updates backgrounds while cutouts and functional HTML remain separate. No API synchronization is claimed. |


The complete schema has **14 migrations / 82 declared tables**. Real MySQL 8 CI applied/reapplied/verified/rolled back the schema, passed 32 installed-upgrade checks and 12 booking checks, and exercised merchandise transactions. The installed-site updater preserves existing identity/data and never automatically runs destructive down SQL after failed DDL.

## Earlier feature validation and distribution

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

The final Admin regression verifies immediate refresh, explicit active-category collapse, keyboard focus, current-page highlight, owner/site scope and malformed preferences. Earlier CI failures and their verified corrections remain in `docs/TEST_REPORT.md`; no failed run is relabeled as a pass.

## Explicit remaining boundaries

Owner photographs, biography values and real merchandise catalog content must come from the owner; the application supplies working editors without inventing those details. Positioning/cropping is verified with geometric fixtures, not a claim that the owner's actual photos were placed.

Payments remain sandbox-only. Production campaign delivery, representative hosting certification and live payment-provider approval are not claimed. Recovered MarketingStore remains protected file-backed code whose runner prepares an outbox. Advanced audiences/expiration, scheduled scene processing, referral-to-order attribution, retention controls and MySQL marketing persistence described by historical documents remain unsupported implementation claims. They are recorded separately and do not authorize skipping this 0.8.1 continuation.
