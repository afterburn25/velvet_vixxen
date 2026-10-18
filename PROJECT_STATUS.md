# Project Status — Nikki Wilder / The Velvet Vixxen

## Current packaged release

**0.5.0 — Subscriptions & PPV Commerce**

A clean, installable 0.5.0 package has been built and locally verified. It combines the approved design, Visual Composer, Canva workflow, Media Center, member accounts, unified entitlements, subscriptions, PPV photo galleries, PPV videos, rentals, bundles, coupons, receipts, refunds, signed webhooks, and commerce administration.

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

The GitHub repository is the communication and testing hub, but the complete executable 0.5.0 source tree is **not yet imported**. Therefore:

- `main` remains the last-known-good documentation branch, not the 0.5.0 executable release.
- `feature/0.5.0-subscriptions-ppv-commerce` is the prepared delivery branch.
- Issue #1 tracks complete source import and full CI activation.
- Issue #5 tracks the 0.5.0 delivery and release gates.
- Draft pull request #6 carries the current documentation handoff.
- Do not claim GitHub CI, MySQL integration, Playwright acceptance, a pull-request merge, or a live payment processor without direct evidence.

## Completed in the tested package

### Design, media, and editing

- Approved responsive neon-pink visual direction
- Hero and visual Footer layered composer with desktop/tablet/mobile arrangements
- Canva-compatible template/import workflow
- Music, photo-gallery, and video Media Center
- Persistent audio player, gallery viewer, video player, scheduling, protected delivery, and media administration

### One-page installer

- All setup on one responsive browser page
- No installation key
- Exact 12-character owner-password minimum
- Real write/read/rename/delete filesystem probes
- Safe `0755`/`0775` permission attempts; no `chmod 0777`
- Eight migrations declaring 44 application tables
- Installation lock, boot verification, and `/install` cleanup or secure retirement
- Organized application folders rather than root-level file sprawl

### Members and entitlements

- Registration, verification, login, recovery, reset, lockout, and device sessions
- Member dashboard, My Library, Favorites, playback history, profile/security settings, and device management
- Membership plans and plan-content rules
- Unified resolver for public, registered, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted access
- Server-backed favorites, progress, completion, recently viewed state, and view count

### 0.5.0 commerce

- Store, cart, checkout, success, receipt, and member billing pages
- Versioned payment-provider contract and working sandbox adapter
- Monthly/annual subscription checkout
- PPV photo galleries and PPV videos
- Time-limited rentals
- Subscriber prices and plan PPV discounts
- Photo/video bundles
- Coupons with scope, dates, thresholds, total-use, and per-member limits
- Configurable currency, tax percentage, store state, and receipt footer
- Orders, line items, transactions, idempotency, receipt mail queue, and billing history
- Automatic subscription and entitlement fulfillment
- Full refunds with order-created access revocation
- Signed replay-safe paid/refund/chargeback webhooks
- Cancel-at-period-end and resume-renewal controls
- Sandbox renewal processing and safe non-sandbox deferral to provider webhooks
- Commerce, Orders, Coupons, Bundles, Payment Settings, and Refund administration

## Verified local evidence

- 101 PHP files pass syntax validation
- 6 public JavaScript files pass syntax validation
- 5 Python test files pass syntax validation
- Clean non-database source suite passes
- Installed-demo member and commerce HTTP acceptance passes
- Migration manifest passes with 8 migrations and 44 declared tables
- Media, member, entitlement, commerce, protected-media, member-security, payment-webhook, structure, and repository-safety tests pass
- Release ZIP was extracted to a separate directory and retested successfully
- Packaged configuration is uninstalled and contains no generated keys, install lock, database credentials, sessions, logs, or runtime customer data

## Environmental limits

- Local MySQL/MariaDB and PDO MySQL were unavailable, so live DDL execution is not claimed. The repository contains a disposable MySQL 8 apply/verify/rollback test for CI after source import.
- Local interactive Chromium navigation returned `net::ERR_BLOCKED_BY_ADMINISTRATOR`. The policy was not bypassed. Playwright suites are configured for CI.
- 0.5.0 screenshots are static render snapshots fetched from the running local application and rendered with Chromium `page.set_content()`; they document real visual output but do not replace interactive browser acceptance.

## Payment boundary

The included gateway is sandbox-only. No live processor connection, merchant approval, production credentials, hosted checkout, or live payment pass is claimed. A live adapter must use hosted/tokenized collection and pass processor-specific lifecycle tests.

## Current next actions

1. Import the complete clean 0.5.0 source into the prepared feature branch.
2. Run PHP 8.2/8.3, MySQL 8, HTTP, and Playwright CI.
3. Review and merge only after all required checks are green.
4. Begin `0.6.0 — Add-on Framework` after the source-of-truth import is complete.

## Next milestone

**0.6.0 — Add-on Framework**

Planned scope: validated ZIP packages, manifests, permissions, dependencies, hooks/events, routes, migrations, install/activate/deactivate/update/uninstall, pre-change backups, safe mode, rollback, and developer SDK foundations.
