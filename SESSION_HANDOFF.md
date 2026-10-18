# Session Handoff — Velvet Vixxen

## Current milestone

**0.5.0 — Subscriptions & PPV Commerce**

## Repository state

- Repository: `afterburn25/velvet_vixxen`
- Documentation branch: `main`
- Prepared delivery branch: `feature/0.5.0-subscriptions-ppv-commerce`
- Complete source import: **pending**, tracked by issue #1
- 0.5.0 delivery/test gates: issue #5
- Do not describe `main` as the executable 0.5.0 release until source import, CI, review, and merge are complete.

## Local/package source state

The completed local source was committed at:

```text
db04dd3 Document release screenshot evidence
7a3c1b1 Build 0.5.0 subscriptions and PPV commerce
866132d Baseline 0.4.0
```

The clean source was packaged as:

```text
Nikki-Wilder-Velvet-Vixxen-0.5.0.zip
SHA-256: 804fa2ff20d4f9f39822d32a3ce70f6f7ed72314767ffc367c835cbb9132d950
```

The package starts uninstalled and includes no database credentials, generated security keys, install lock, sessions, logs, runtime customer data, or live payment credentials.

## Implemented in 0.5.0

- Public Store, Cart, Checkout, Success, Receipt, and Member Billing pages
- Payment-provider interface and registry
- Working sandbox adapter with approved, declined, and processing outcomes
- Monthly/annual subscription checkout and fulfillment
- PPV photo-gallery and PPV-video purchases
- Time-limited rentals
- Subscriber pricing and plan PPV discounts
- Gallery/video bundles
- Scoped percentage/fixed coupons with dates, thresholds, use limits, and per-member limits
- Configurable currency, tax percentage, store state, and receipt footer
- Orders, line items, transactions, idempotency, billing history, and receipt outbox
- Signed replay-safe webhooks for paid, refunded, and chargeback events
- Full refunds and revocation of order-created access
- Cancel-at-period-end and resume-renewal controls
- Sandbox recurring-renewal runner
- Non-sandbox renewal deferral to provider webhooks; no fabricated live-provider payments
- Commerce, Orders, Coupons, Bundles, Payment Settings, and Refund admin sections
- Eighth migration; 44 declared application tables

## Tests actually run

### Clean source suite

```bash
bash scripts/clean_runtime.sh
bash scripts/test.sh
```

Result: passed.

Evidence:

- 101 PHP syntax checks
- 6 public JavaScript syntax checks
- 5 Python test syntax checks
- smoke, installer, one-page installer, migration-manifest, media, member, entitlement, commerce, protected-media, member-security, payment-webhook, structure, and repository-safety tests passed

### Installed-demo HTTP acceptance

```bash
bash scripts/test_http.sh
```

Result: passed.

The HTTP suite exercised registration/verification, subscription checkout, billing cancellation/resume, subscriber-priced bundle, coupon, receipt, gallery rental, declined checkout, owner commerce administration, full refund, refunded billing state, and entitlement revocation.

### Final archive verification

The ZIP was extracted into a separate clean directory. Both the source suite and HTTP suite passed against the extracted archive. Runtime state was removed again afterward.

## Tests not claimed locally

- Live MySQL DDL was not run because the packaging environment lacked PDO MySQL and a MySQL/MariaDB server.
- Interactive local Playwright was not run because managed Chromium blocked localhost with `net::ERR_BLOCKED_BY_ADMINISTRATOR`.
- CI has not yet run against the complete source because source import is pending.

Configured CI tests after import:

- PHP 8.2 and 8.3 source suites
- MySQL 8 migration apply/verify/rollback
- Installed-demo member and commerce HTTP suites
- Media Center, Members & Entitlements, and Commerce Playwright suites

## Important security/payment decisions

- The bundled payment provider is sandbox-only.
- Never describe a live processor as connected or production-ready without provider-specific evidence.
- Live adapters must use hosted/tokenized payment collection; raw card data must not be stored.
- Checkout and webhooks are idempotent.
- Failed/processing payments do not unlock content.
- Refunds and chargebacks revoke order-created access.
- Non-sandbox recurring renewals wait for signed provider webhooks.
- Never commit production credentials, customer data, identity records, payment secrets, private messages, runtime logs, or private/paid media.

## Exact next task

1. Import the clean 0.5.0 source tree into `feature/0.5.0-subscriptions-ppv-commerce`.
2. Run the full GitHub Actions matrix.
3. Correct any CI-only MySQL or Playwright findings.
4. Update this handoff with exact commit and workflow URLs.
5. Open/review/merge the pull request only after required checks are green.
6. Begin `0.6.0 — Add-on Framework` after the 0.5.0 source becomes authoritative in GitHub.
