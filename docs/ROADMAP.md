# Velvet Vixxen Development Roadmap

## Repository-status rule

A feature may be complete in a tested release package before it is present on GitHub. Repository state must be described separately: nothing is authoritative on `main` until the source is pushed, CI passes, review is complete, and the merge actually occurs.

## 0.2.2 — Complete: One-Page Browser Installer

- One responsive installer page with all setup sections
- Exact 12-character owner-password minimum and no installation key
- Safe filesystem/permission handling, migrations, lock, boot verification, and `/install` cleanup
- Organized folders and protected private paths

## 0.3.0 — Complete: Media Center

- Persistent audio player, tracks, albums, playlists, queue, seek, volume, shuffle, repeat, and saved position
- Photo galleries with bulk upload, covers, ordering, previews, scheduling, access metadata, and full-screen viewer
- Videos with poster/trailer/full media, captions, scheduling, access metadata, integrated player, and Continue Watching
- Signed protected-media URLs, HTTP Range support, and private-original boundaries

## 0.4.0 — Complete: Members & Entitlements

- Registration, verification, login, recovery, reset, account security, and device sessions
- Member dashboard, My Library, Favorites, recent activity, and synchronized playback state
- Membership-plan administration and plan-content rules
- Unified access resolver for public, registered, subscription, PPV, rental, bundle, gift, complimentary, scheduled, and unlisted content

## 0.5.0 — Complete in Tested Package: Subscriptions & PPV Commerce

- Versioned payment-provider interface and sandbox adapter
- Store, cart, checkout, order confirmation, receipts, and member billing history
- Monthly/annual membership checkout
- PPV photo galleries and PPV videos
- Time-limited rentals
- Subscriber prices and PPV discounts
- Photo/video bundles
- Coupons, configurable tax/currency, and store controls
- Orders, transactions, receipt mail queue, idempotency, and automatic fulfillment
- Signed replay-safe paid/refund/chargeback webhooks
- Full refunds and entitlement/subscription revocation
- Cancel-at-period-end and resume controls
- Sandbox renewal runner that defers non-sandbox renewals to provider webhooks
- Owner dashboards for commerce, orders, coupons, bundles, provider settings, and refunds
- Unit, feature, HTTP, security, MySQL-CI, and Playwright-CI coverage

Production note: 0.5.0 contains a complete sandbox lifecycle, not a live processor integration. A live adapter must pass provider-specific hosted/tokenized checkout, recurring lifecycle, webhook, refund, chargeback, and failure-recovery tests before production use.

Repository note: complete 0.5.0 source import is tracked by issue #1; delivery/test gates are tracked by issue #5.

## 0.6.0 — Add-on Framework

- ZIP upload, manifest validation, signature/checksum validation, compatibility ranges, permission review, dependencies, and conflicts
- Install, activate, deactivate, update, rollback, and uninstall
- Versioned hooks/events, public/admin routes, navigation, widgets, migrations, scheduled jobs, and frontend blocks
- Media, Visual Composer, membership, entitlement, commerce, payment-provider, email, analytics, and storage extension points
- Pre-change backup, safe mode, broken-add-on quarantine, and diagnostics
- Developer starter add-on, manifest schema, SDK documentation, and lifecycle tests

## 0.7.0 — Add-on Licensing & Store

- One-time, recurring, annual, lifetime, trial, domain, multi-site, and agency licenses
- Customer downloads, activation limits, update entitlement, grace periods, invoices, and release channels
- Integrated add-on catalog, purchases, and one-click installation

## 0.8.0 — Messaging, Booking, Marketing & Analytics

- Private member messaging, content requests, blocking, and reporting
- Booking and appearance inquiries
- Newsletter templates, campaigns, referrals, promotions, landing pages, and conversion analytics

## 0.9.0 — Compliance, Protection & Operations

- Rights/release vault, ownership records, reporting, takedown workflow, geographic controls, and age-assurance adapter
- Buyer-specific watermarks, suspicious-access monitoring, backups, restore, diagnostics, and upgrade rollback

## 1.0.0 — Production Release

- Full cross-browser/device and accessibility testing
- Live approved payment-provider lifecycle and security validation
- Clean install, upgrade, rollback, backup, and restore tests on representative shared hosting
- Production documentation and screenshots from the actual running build

## Completion rule

A roadmap item is complete only when implementation, migrations, tests, documentation, and running-build evidence exist. Plans, schemas, placeholders, and mockups alone do not count.
