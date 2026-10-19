# Project Status — Nikki Wilder / The Velvet Vixxen

## Installer folder correction in progress — 2026-10-19

The owner reported fresh-install readiness failures for nine missing image, font/icon and template folders after downloading the earlier 0.8.1 package. The manifest required unused empty scaffolding that the runtime-only ZIP intentionally does not contain. On panel-owned read-only asset trees, PHP cannot create those folders. The earlier same-owner installer fixtures did not cover this extracted-package ownership case; the prior green runs below do not certify it.

The current correction removes those nine unused entries and unused `database/seeds`, checks actual packaged application/asset directories for presence/readability without creating or chmodding them, and preserves the existing writable-runtime probes and limited ownership repair. Missing real application folders remain blocking errors. A one-file installer repair ZIP will let an already-uploaded unfinished installation resume without replacing configuration, storage, uploads or database data. The complete fresh package will also contain the correction.

Local PHP 8.3.6 source/JavaScript syntax/security/package suites passed. The new real foreign-owner extracted-ZIP regression reproduced exactly the nine original errors, and the corrected service passed repeated probes, allowed ownership repair, required-write failures, missing real assets and failed-repair preservation. Fresh PHP 8.2/8.3, MySQL 8 and HTTP/browser CI is pending on the corrective commit; do not claim that new run has passed until its results are observed. The 0.8.1 features and locked requirements remain the scope; no 0.9.0 work is authorized by this correction.

Repository: `afterburn25/velvet_vixxen`. Updated: 2026-10-19.

## Verified current implementation

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

## Validation and distribution

Both packages were built from the exact verified runtime commit. Their byte hashes match the gated GitHub CI build. The fresh package starts uninstalled; the upgrade omits config, storage, uploads and install. Neither contains repository docs, tests, SDK source, workflows, screenshots, artwork provenance/masters, installed records, credentials or private media. Exact package counts/hashes and artifact URLs are recorded in `RELEASE_MANIFEST.txt`, `docs/releases/0.8.1-site-ux.json` and `docs/TEST_REPORT.md`.

The final Admin regression verifies immediate refresh, explicit active-category collapse, keyboard focus, current-page highlight, owner/site scope and malformed preferences. Earlier CI failures and their verified corrections remain in `docs/TEST_REPORT.md`; no failed run is relabeled as a pass.

## Explicit remaining boundaries

Owner photographs, biography values and real merchandise catalog content must come from the owner; the application supplies working editors without inventing those details. Positioning/cropping is verified with geometric fixtures, not a claim that the owner's actual photos were placed.

Payments remain sandbox-only. Production campaign delivery, representative hosting certification and live payment-provider approval are not claimed. Recovered MarketingStore remains protected file-backed code whose runner prepares an outbox. Advanced audiences/expiration, scheduled scene processing, referral-to-order attribution, retention controls and MySQL marketing persistence described by historical documents remain unsupported implementation claims. They are recorded separately and do not authorize skipping this 0.8.1 continuation.
