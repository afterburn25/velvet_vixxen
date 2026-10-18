# Test Report — 0.5.0 Subscriptions & PPV Commerce

## Clean local source suite

Commands:

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Result: **passed**

Verified:

- PHP syntax validation across 101 files
- JavaScript syntax validation across 6 public files
- Python syntax validation across 5 HTTP/Playwright files
- package smoke test
- installer foundation test
- one-page installer regression test
- migration manifest: 8 migrations / 44 declared tables
- media access and file-backed Media Store tests
- member authentication and Entitlement Resolver tests
- Subscriptions & PPV Commerce unit test
- Media Center, Members & Entitlements, and Commerce feature tests
- signed protected-media security test
- member-security test
- signed/replay-safe payment-webhook security test
- organized-folder test
- repository secret, runtime-state, and unsafe-permission scan

The commerce suite exercises quote totals, coupon allocation and limits, idempotent checkout, successful/declined/processing payments, PPV, rentals, subscriptions, subscriber pricing, bundles, full refunds, signed webhooks, duplicate webhooks, chargebacks, cancellation/resume, receipt mail, sandbox renewal, and safe deferral of non-sandbox renewals.

## Installed-demo HTTP acceptance

Command:

```bash
bash scripts/test_http.sh
```

Result: **passed**

Verified through real HTTP requests and forms:

- existing member login, dashboard, library, devices, and member-state API
- fresh member registration and email verification
- subscription purchase through sandbox checkout
- paid subscription in member billing
- cancel-at-period-end and resume-renewal controls
- subscriber-priced bundle
- coupon application and discounted total
- bundle purchase and library fulfillment
- receipt order number, line items, total, and footer
- time-limited gallery rental
- declined video checkout without entitlement fulfillment
- owner Commerce, Orders, Coupons, Bundles, and Payment Settings pages
- paid-order detail and full refund
- refunded order state in member billing
- revoked bundle access after refund

The passing server run produced no PHP warning or fatal-error output.

## Archive verification

The release ZIP was extracted into a separate clean directory. The complete non-database source suite and installed-demo HTTP suite both passed against that extracted archive. Runtime installation state was removed afterward. The packaged configuration remained uninstalled and contained no generated keys, credentials, sessions, logs, or customer data.

## Browser acceptance

Configured suites:

- `tests/Browser/media_center_playwright.py`
- `tests/Browser/members_entitlements_playwright.py`
- `tests/Browser/commerce_playwright.py`

All pass Python syntax validation and are configured for GitHub Actions with an automation-installed Chromium browser.

**Local interactive Chromium execution is not claimed.** Managed Chromium returned `net::ERR_BLOCKED_BY_ADMINISTRATOR` when navigating to the local server. The policy was not bypassed.

The release package includes static running-build render snapshots. Those images were generated from HTML fetched from the running application and rendered with Chromium `page.set_content()`. They document visual output but do not replace the interactive Playwright suites.

## MySQL integration

**Local live DDL execution is not claimed.** The packaging environment did not expose PDO MySQL or a MySQL/MariaDB server.

The configured MySQL 8 CI test must:

1. refuse a non-test or non-empty database;
2. apply all eight migrations;
3. verify 44 declared tables and required commerce/member/media schema;
4. roll back every migration;
5. require the disposable database to be empty afterward.

## Commerce security checks

- Checkout requires an active, verified member
- Checkout idempotency prevents duplicate orders
- Raw card numbers are not stored in orders, transactions, receipts, member records, or logs
- Declined and processing states do not unlock content
- Webhooks require a configured HMAC secret and valid signature
- Provider event IDs are unique/idempotent
- Unknown order events are recorded as ignored rather than fulfilled
- Webhook refunds do not invoke the provider refund endpoint a second time
- Full refunds and chargebacks revoke order-created entitlements
- Non-sandbox renewals defer to provider webhooks instead of being fabricated as paid
- Coupon usage and per-member limits are enforced
- Repository safety rejects runtime data, install locks, generated secrets, credential patterns, and mode `0777`

## CI gates after source import

- PHP 8.2 source tests
- PHP 8.3 source tests
- MySQL 8 migration/rollback integration
- installed-demo member and commerce HTTP acceptance
- Chromium Media Center acceptance
- Chromium Members & Entitlements acceptance
- Chromium Subscriptions & PPV Commerce acceptance

## Payment boundary

The included provider is the `sandbox` test gateway. No live processor connection, approval, production credential set, hosted checkout, or production payment pass is claimed. A live adapter requires provider-specific sandbox evidence before production use.
