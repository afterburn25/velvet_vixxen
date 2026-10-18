# Testing Guide

Testing is part of every milestone, not a final cleanup step.

## Complete local source suite

```bash
bash scripts/test.sh
```

The suite must cover:

- PHP syntax validation
- JavaScript syntax validation when Node.js is available
- Application smoke tests
- Installer foundation tests
- Single-page installer tests
- Migration-manifest tests
- Repository structure tests
- Secret and unsafe-permission scans

## MySQL migration test

Use a disposable, empty test database only:

```bash
VELVET_TEST_DB_HOST=127.0.0.1 \
VELVET_TEST_DB_PORT=3306 \
VELVET_TEST_DB_NAME=velvet_vixxen_test \
VELVET_TEST_DB_USER=root \
VELVET_TEST_DB_PASSWORD=secret \
php tests/Database/migrations_mysql.php
```

The integration test must:

1. Refuse to run unless the database is clearly named as a test database.
2. Refuse to run against a non-empty database.
3. Run every versioned migration.
4. Verify all required foundation tables.
5. Roll back the migrations it applied.
6. Verify the application tables were removed.

## Browser acceptance testing

Before every release, test at minimum:

- Installer at desktop and mobile widths
- Existing-install protection
- Owner login and logout
- Hero and footer draft, preview, publish, and restore
- Media upload validation
- Public homepage at desktop, tablet, and mobile widths
- Direct access denial for private folders

Media Center releases additionally require:

- Persistent audio playback across page navigation
- Playlist, queue, shuffle, repeat, seek, and volume controls
- Bulk gallery upload, sorting, preview boundaries, full-screen navigation, and mobile swipe
- Trailer/full-video separation, poster images, captions, playback resume, and protected URLs
- Administration create/edit/schedule/archive flows

Commerce releases additionally require:

- Subscription creation, renewal, cancellation, grace period, and failed-payment behavior
- PPV gallery and video purchases
- Rental expiration
- Subscriber discounts
- Refund and chargeback entitlement revocation
- Bundle and coupon calculations

## Test evidence

Record exact commands, environment, passing checks, failures, screenshots from the running build, and known limitations in `docs/TEST_REPORT.md`. Do not use the word “tested” without evidence.
