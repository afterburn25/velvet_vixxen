# Changelog

## 0.5.0 — Subscriptions & PPV Commerce

### Added

- Public Store for membership plans, PPV photo galleries, PPV videos, rentals, and bundles
- Session cart, coupon form, member-only checkout, success page, receipts, and billing history
- Versioned payment-provider interface, provider registry, and working sandbox adapter
- Sandbox approved, declined, and processing payment states
- Monthly/annual subscription checkout and fulfillment
- Permanent PPV gallery/video entitlements and time-limited rentals
- Subscriber-specific prices and plan PPV discounts
- Commerce bundles containing galleries and videos
- Coupon scope, percentage/fixed amount, dates, minimum subtotal, maximum discount, total-use limit, and per-member limit
- Configurable currency, tax percentage, store availability, and receipt footer
- Orders, line items, payment transactions, last-four/brand metadata, and idempotency keys
- Signed webhook endpoint with replay protection and paid/refund/chargeback handling
- Refund flow with entitlement revocation and related subscription cancellation
- Self-service cancel-at-period-end and resume-renewal controls
- Sandbox recurring-renewal runner with live-provider deferral rather than fabricated payments
- Owner Commerce, Orders, Coupons, Bundles, and Payment Settings administration pages
- Commerce unit, feature, HTTP, webhook-security, MySQL-schema, and Playwright browser tests
- Eighth migration adding payment providers, coupons, bundles, redemptions, webhook events, order commerce fields, fulfillment state, and payment metadata

### Security and integrity

- Raw card numbers are not persisted in orders, transactions, logs, receipts, or member profiles
- Webhook signatures require a configured secret; provider event IDs are idempotent
- Checkout requires an active, verified member and a unique checkout idempotency key
- Failed and processing payments do not unlock content
- Full refunds and chargebacks revoke order-created access
- Live-provider recurring renewals must be confirmed through provider webhooks

### Important limitation

- The included gateway is sandbox-only. No live payment processor is represented as connected, approved, or production-ready.

## 0.4.0 — Members & Entitlements

- Added registration, verified email activation, login/logout, recovery, password reset, lockout, device sessions, member dashboard, My Library, membership plans, subscription assignment, direct entitlements, Entitlement Resolver, and synchronized media state
- Added seventh migration / 38 declared tables

## 0.3.0 — Media Center

- Added persistent music player, albums/playlists, photo galleries and lightbox, video library/player, protected media delivery, scheduling, and Media Center administration

## 0.2.2 — One-Page Browser Installer

- Replaced the multi-screen wizard with one responsive installer page
- Preserved no install key, exact 12-character password minimum, safe permissions, migrations, rollback, lock, and installer cleanup

## 0.2.1 — Installer & Structure Hardening

- Added branded browser installer foundation, real server/filesystem diagnostics, organized protected folders, migration registry, and cleanup/recovery behavior

## 0.2.0 — Homepage, Visual Composer & Canva Templates

- Added approved responsive pink homepage, layered Hero/Footer composer, media library, Canva starter templates/import workflow, owner login, age gate, and audit foundation
