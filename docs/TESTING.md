# Testing Policy and Commands

## Release gate

No release is complete until the applicable source, installer, security, database, HTTP, and browser tests pass. Documentation must distinguish tests actually run locally from tests merely configured for CI.

## Clean runtime state

Demo and HTTP tests create installed configuration, secrets, sessions, and a file-backed data store. Before source testing, packaging, or committing:

```bash
bash scripts/clean_runtime.sh
```

## Source suite

```bash
bash scripts/test.sh
```

This performs:

- PHP syntax validation
- public JavaScript syntax validation
- Python HTTP/Playwright test syntax validation
- package smoke tests
- installer foundation and one-page regression tests
- migration-manifest validation
- media, member, entitlement, and commerce unit tests
- Media Center, Members & Entitlements, and Commerce feature tests
- protected-media, member-security, and payment-webhook security tests
- organized-folder and repository-safety tests

## Installed-demo HTTP acceptance

```bash
bash scripts/test_http.sh
```

This creates a disposable file-backed demo and verifies real HTTP/form workflows for:

- registration, verification, login, dashboard, library, devices, and member state
- subscription plan checkout
- cancel-at-period-end and resume
- subscriber bundle pricing
- coupon application
- PPV and rental fulfillment
- successful and declined sandbox payments
- receipts and billing history
- owner commerce dashboards and order detail
- full refund and access revocation

Run `bash scripts/clean_runtime.sh` afterward.

## MySQL migration integration

Use a disposable, empty database whose name clearly indicates testing:

```bash
VELVET_TEST_DB_HOST=127.0.0.1 \
VELVET_TEST_DB_PORT=3306 \
VELVET_TEST_DB_NAME=velvet_vixxen_test \
VELVET_TEST_DB_USER=root \
VELVET_TEST_DB_PASSWORD=secret \
php tests/Database/migrations_mysql.php
```

The test refuses a non-test or non-empty database, applies all migrations, verifies required commerce/member/media tables and columns, rolls everything back, and requires the database to be empty afterward.

## Browser acceptance

Start the demo server and run all suites:

```bash
php scripts/create_demo.php
php -S 127.0.0.1:8765 -t .

python -m pip install playwright requests beautifulsoup4
python -m playwright install chromium

VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/media_center_playwright.py
VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/members_entitlements_playwright.py
VELVET_BASE_URL=http://127.0.0.1:8765 python tests/Browser/commerce_playwright.py
```

The commerce suite covers desktop/mobile Store rendering, browser registration/verification, subscription checkout, billing cancel/resume, subscriber bundle pricing, coupons, receipts, failed checkout, owner commerce pages, refund, and access revocation. `VELVET_SCREENSHOT_DIR` may be set to capture full-page screenshots. `VELVET_CHROMIUM` may point to an allowed browser executable. Do not bypass managed browser security policy merely to force a local pass.

## Provider sandbox acceptance

Before enabling any live payment adapter, test against that provider's official sandbox or test environment:

- hosted/tokenized checkout
- recurring subscription creation and renewal
- decline and processing states
- webhook signature verification and replay handling
- cancellation, retry, grace period, refund, partial refund, and chargeback
- purchase-to-entitlement creation and refund/chargeback revocation
- no raw card data in application storage or logs

## CI after source import

GitHub Actions must run:

- PHP 8.2 source suite
- PHP 8.3 source suite
- MySQL 8 migration/rollback integration
- installed-demo member and commerce HTTP acceptance
- Chromium Media Center acceptance
- Chromium Members & Entitlements acceptance
- Chromium Subscriptions & PPV Commerce acceptance

## Evidence rule

Record exact commands, environment, passing checks, failures, and known limitations in `docs/TEST_REPORT.md`. Never state that MySQL, browser, live payment, email, or production-host testing passed without direct evidence.
