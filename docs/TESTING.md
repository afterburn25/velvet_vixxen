# Testing — Velvet Vixxen 0.7.0

Testing is a release gate. A milestone is not complete merely because pages render or code exists.

## Clean source suite

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Current clean-tree evidence:

- PHP syntax: 160 files
- Public JavaScript syntax: 6 files
- Python HTTP/Playwright syntax: 11 files
- 11 migrations / 66 declared application tables
- Installer, media, member, entitlement, commerce, add-on, licensing, communication, structure, and repository-safety tests

## Installed-demo HTTP acceptance

```bash
VELVET_TEST_PORT=8766 bash scripts/test_http.sh
```

Five suites run:

```text
tests/Http/members_http.py
tests/Http/commerce_http.py
tests/Http/addon_http.py
tests/Http/addon_licensing_http.py
tests/Http/communications_http.py
```

The communications suite covers public booking, member inbox/thread reply, new conversations, content-request quote acceptance, booking confirmation, notifications, owner queues/replies/status/quote changes, moderation, authenticated CSV exports, and guest-token status.

## MySQL integration

```bash
VELVET_TEST_DB_HOST=127.0.0.1 \
VELVET_TEST_DB_PORT=3306 \
VELVET_TEST_DB_NAME=velvet_vixxen_test \
VELVET_TEST_DB_USER=root \
VELVET_TEST_DB_PASSWORD=secret \
php tests/Database/migrations_mysql.php
```

The test must refuse unsafe/non-empty databases, apply all eleven migrations, verify 66 tables and required columns, exercise an add-on-owned migration, roll everything back, remove the migration registry, and leave the database empty.

## Browser acceptance

Run after starting a fresh demo server:

```bash
python tests/Browser/media_center_playwright.py
python tests/Browser/members_entitlements_playwright.py
python tests/Browser/commerce_playwright.py
python tests/Browser/addon_framework_playwright.py
python tests/Browser/addon_licensing_playwright.py
python tests/Browser/messaging_booking_playwright.py
```

Set `VELVET_BASE_URL` to the local demo URL. The communications suite covers responsive booking, member messaging/requests/bookings/notifications, and owner queues.

Local Chromium navigation in the packaging environment returned `net::ERR_BLOCKED_BY_ADMINISTRATOR`; GitHub CI owns interactive evidence unless a later environment produces a direct successful run.

## Packaging verification

Before release:

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
bash scripts/test_http.sh
bash scripts/clean_runtime.sh
php tests/Security/repository_safety.php
```

Then extract the ZIP to a separate directory and rerun source tests, HTTP tests, cleanup, repository safety, and checksum verification. Confirm no generated config, install lock, sessions, logs, private attachments, conversations, request/booking records, guest tokens, signing keys, protected releases, installed add-ons, or customer data remain.

## Evidence rules

- Record exact commands/results in `docs/TEST_REPORT.md`.
- Screenshots support visual review but do not replace interactive acceptance.
- Unavailable environmental checks are documented rather than marked passed.
- Package and repository truth boundaries remain explicit until source import and CI are green.
