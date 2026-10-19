# Nikki Wilder — The Velvet Vixxen

## Current Media/Composer and public-route continuation

The active repair fixes missing public entry points after folder reorganization, image-upload JSON errors, disappearing Full Page Preview tabs and failed publication. It also adds separate editable live button layers in Visual Composer. Read [the repair guide](docs/MEDIA_COMPOSER_REPAIR.md) and [current test/delivery status](docs/TEST_REPORT.md); all seven jobs in [the exact-source GitHub run](https://github.com/afterburn25/velvet_vixxen/actions/runs/39304801698) passed, and the saved downloads match its gated artifact byte for byte. The previous installer database/layout repair below remains included in the full distribution.

## 0.8.1 installer database and layout repair

For the already-uploaded unfinished installer, use [Installer Database Repair](docs/INSTALLER_DATABASE_REPAIR.md). Setup now preserves and continues compatible existing databases, including previous attempts with partial tables or missing migration history. Existing owners authenticate with their current login; missing setup work completes without resetting their data, keys, scenes or settings. Server/Folders checks and the owner password fields are aligned, and completed setup clears its loading state.

The runtime-only repair contains eight files, including the prior static-folder correction. Rebuilt fresh and upgrade ZIPs share the applicable corrected runtime bytes. [Current verification](docs/TEST_REPORT.md) and [package evidence](docs/releases/0.8.1-installer-database-repair.json) distinguish completed execution from pending CI or delivery. The older one-file folder repair is historical and does not include these changes.

## Current integration: 0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise

This tree now contains the executable application, database migrations, public assets, recovered engineering tests, package builder, and required GitHub Actions checks. It was recovered from the checksum-verified **0.8.1** fresh-install package, then reconciled with the separate **0.7.4** Visual Composer fixes. Application source is no longer deferred to a future import.

The continuation adds the expanded Visual Composer, 30 standalone luxury scenes, Booking Form Builder, About Me and owner editor, shared sticky public navigation, centered account pages, persistent admin categories, merchandise/stock/shipping/fulfillment, direct PPV checkout, and safe installed database updates. The repair also preserves installation identity and backups and produces reproducible fresh-install/upgrade distributions. The full engineering gate passed on runtime commit `ca52793` in [GitHub run 39213140477](https://github.com/afterburn25/velvet_vixxen/actions/runs/39213140477). Exact source, artifacts and fresh/upgrade package hashes are recorded in [`docs/TEST_REPORT.md`](docs/TEST_REPORT.md) and [`RELEASE_MANIFEST.txt`](RELEASE_MANIFEST.txt).

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
