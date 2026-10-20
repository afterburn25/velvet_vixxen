# Nikki Wilder — The Velvet Vixxen

## Current organized-root repair — Build 20261019.6

**Build 20261019.5 is rejected:** its routing repair reintroduced 25 root folders and 46 forwarding PHP files contrary to the approved layout. Build **20261019.6** restores the exact original 13 Fresh / nine Upgrade root directories and four root files. Generated page/API URLs use the physical `route.php`; protected implementations remain under `public/routes`. The new owner page, `admin/repair.php`, verifies the uploaded runtime, refreshes this site's PHP and retires only exact known old forwarding files and empty folders while preserving custom items and installed data.

The correction is uploaded in [source commit e58f9edd](https://github.com/afterburn25/velvet_vixxen/commit/e58f9edd708fd42dee0e6c898170e67eb4a01a53). All seven jobs in [the final CI run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39328060458) passed against the identical complete source tree, including PHP 8.2/8.3, MySQL 8, actual rejected-package cleanup and desktop/mobile acceptance. The gated packages and all four saved replacement downloads were compared literally and match; saved readback was verified 2026-10-19 22:36:25 UTC. Read [the update instructions](docs/UPDATE_VERIFICATION.md) and [current test/delivery status](docs/TEST_REPORT.md). Earlier repair-run results are historical and did not enforce the approved folder requirement.

The repair retains working image uploads, Full Page Preview, publication and separately editable live button layers. The previous installer database/layout correction below remains included in the full distribution. The affected customer host has not been accessed or verified.

## 0.8.1 installer database and layout repair

For the already-uploaded unfinished installer, use [Installer Database Repair](docs/INSTALLER_DATABASE_REPAIR.md). Setup now preserves and continues compatible existing databases, including previous attempts with partial tables or missing migration history. Existing owners authenticate with their current login; missing setup work completes without resetting their data, keys, scenes or settings. Server/Folders checks and the owner password fields are aligned, and completed setup clears its loading state.

The runtime-only repair contains eight files, including the prior static-folder correction. Rebuilt fresh and upgrade ZIPs share the applicable corrected runtime bytes. [Current verification](docs/TEST_REPORT.md) and [package evidence](docs/releases/0.8.1-installer-database-repair.json) distinguish completed execution from pending CI or delivery. The older one-file folder repair is historical and does not include these changes.

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

The payment adapter is sandbox-only. Marketing uses a protected file store and prepares an outbox; production campaign delivery and the previously proposed advanced automation are unfinished. Representative shared-hosting installation/upgrade certification remains a production-release gate; disposable ownership/write/install regressions pass for this milestone. The application is not labelled a production-certified 1.0 release.

Never commit installed configuration, credentials, private customer/member/booking data, private messages, identity documents, generated keys, or private/paid media.
