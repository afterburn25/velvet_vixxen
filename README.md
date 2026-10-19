# Nikki Wilder — The Velvet Vixxen

The authoritative application and engineering repository is `afterburn25/velvet_vixxen`. The website is a PHP/MySQL application for shared hosting, with the approved neon-pink design and a single-page browser installer.

## Current integration: 0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise

This tree now contains the executable application, database migrations, public assets, recovered engineering tests, package builder, and required GitHub Actions checks. It was recovered from the checksum-verified **0.8.1** fresh-install package, then reconciled with the separate **0.7.4** Visual Composer fixes. Application source is no longer deferred to a future import.

The continuation adds the expanded Visual Composer, 30 standalone luxury scenes, Booking Form Builder, About Me and owner editor, shared sticky public navigation, centered account pages, persistent admin categories, merchandise/stock/shipping/fulfillment, direct PPV checkout, and safe installed database updates. The repair also preserves installation identity and backups and produces reproducible fresh-install/upgrade distributions. Current validation and remaining gates are recorded in [`docs/TEST_REPORT.md`](docs/TEST_REPORT.md).

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

The payment adapter is sandbox-only. Marketing uses a protected file store and prepares an outbox; production campaign delivery and the previously proposed advanced automation are unfinished. Representative shared-hosting installation/upgrade verification remains a release gate. The application is not labelled a production-certified 1.0 release.

Never commit installed configuration, credentials, private customer/member/booking data, private messages, identity documents, generated keys, or private/paid media.
