# Project Status — Nikki Wilder / The Velvet Vixxen

Repository: `afterburn25/velvet_vixxen`. Updated: 2026-10-19.

## Current implementation

**0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise** is implemented on `feature/0.8.1-site-ux-hero-library` and undergoing the final combined release gates. Do not start 0.9.0.

The newest executable GitHub branch is the implementation branch. Source recovery checkpoint [`43945af07c145c67db2f2883a4fa7f607215dc23`](https://github.com/afterburn25/velvet_vixxen/commit/43945af07c145c67db2f2883a4fa7f607215dc23) imported the checksum-verified 0.8.1 runtime, engineering tests, safe recovery fixes and runtime-only packaging. [PR #15](https://github.com/afterburn25/velvet_vixxen/pull/15) tracks the implementation; [issue #13](https://github.com/afterburn25/velvet_vixxen/issues/13) tracks completion.

Recovered archive SHA-256: `a31574353d161aaf95d81ceb6aab57b09d9ada6e1d4f1adaf9192912d8c2bc87`. Its 211 runtime files/132 PHP files are the historical input, not the current source-file count. Full provenance: `docs/SOURCE_RECOVERY.md`.

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

There are now **14 versioned migrations declaring 82 tables**. Local MariaDB has applied/reapplied/rolled back the complete schema and exercised booking, merchandise and installed-update transactions. The feature checkpoint still requires its own MySQL 8 CI result; baseline MySQL 8 success is not substituted for it.

## Validation and distribution

`docs/TEST_REPORT.md` distinguishes focused local results, exact GitHub run results and remaining gates. Baseline GitHub run [39207173912](https://github.com/afterburn25/velvet_vixxen/actions/runs/39207173912) passed repository contracts, PHP 8.2, PHP 8.3, installed HTTP and MySQL 8 migrations. Its browser job stopped at a stale age-gate test setup, now fixed in source. That baseline run was not a complete pass.

Fresh-install and upgrade packages are built from the same engineering tree. The fresh ZIP starts uninstalled. The upgrade ZIP omits config, storage, uploads and install entirely. Customer ZIPs exclude docs, tests, SDK, workflows, screenshots, artwork provenance/masters, build tooling and private/runtime records. Final feature package hashes and green combined CI are still required before marking 0.8.1 complete.

## Explicit remaining boundaries

Owner photographs, biography values and real merchandise catalog content must come from the owner; the application supplies working editors without inventing those details. Positioning/cropping is verified with geometric fixtures, not a claim that the owner's actual photos were placed.

Payments remain sandbox-only. Production campaign delivery, representative hosting certification and live payment-provider approval are not claimed. Recovered MarketingStore remains protected file-backed code whose runner prepares an outbox. Advanced audiences/expiration, scheduled scene processing, referral-to-order attribution, retention controls and MySQL marketing persistence described by historical documents remain unsupported implementation claims. They are recorded separately and do not authorize skipping this 0.8.1 continuation.
