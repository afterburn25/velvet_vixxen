# Velvet Vixxen Development Roadmap

## 0.2.2 — Complete: One-Page Browser Installer

- One responsive installer page with all setup sections
- Inline server/file, database, and optional email tests
- Live protected progress polling
- Safe file permissions and ownership diagnostics
- Exact 12-character owner-password minimum and no installation key
- Versioned database migrations
- Organized folders
- Installation cleanup and lock
- Media, commerce, member, PPV, and add-on schema foundations

## 0.3.0 — Media Center

- Unified media dashboard
- Persistent site-wide audio player
- Tracks, albums, playlists, cover art, queue, shuffle, repeat, and saved position
- Photo galleries, bulk upload, covers, sorting, previews, tags, scheduling, and full-screen viewer
- Video library, poster/trailer/full media, captions, Continue Watching, and integrated player
- Public/member/premium/PPV access-rule editor
- Processing status and protected-original storage
- Homepage featured music, gallery, and video sections
- Automated unit, integration, security, and browser acceptance tests

## 0.4.0 — Members & Entitlements

- Registration, verification, login, recovery, and account security
- Member dashboard and My Library
- Favorites, history, Continue Watching, and recently viewed
- Entitlement engine for plans, purchases, rentals, bundles, and complimentary access

## 0.5.0 — Subscriptions & PPV Commerce

- Recurring monthly and annual plans
- PPV photo galleries and videos
- Rentals, subscriber discounts, bundles, coupons, trials, and gifts
- Cart, checkout, receipts, billing history, refunds, chargebacks, and failed-payment recovery
- Replaceable payment-provider adapters that support the business category

## 0.6.0 — Add-on Framework

- ZIP upload, manifest validation, permission review, dependency/conflict checks
- Install, activate, deactivate, update, rollback, and uninstall
- Stable hooks/events, routes, admin menus, widgets, migrations, scheduled jobs, and frontend blocks
- Safe mode and broken-add-on quarantine

## 0.7.0 — Add-on Licensing & Store

- One-time, recurring, annual, lifetime, trial, domain, multi-site, and agency licenses
- Customer downloads, activation limits, update entitlement, grace periods, invoices, and release channels
- Integrated add-on catalog and one-click installation

## 0.8.0 — Messaging, Booking, Marketing & Analytics

- Private member messaging and content requests
- Booking and appearance inquiries
- Newsletter templates, campaigns, referral codes, promotional landing pages, and conversion analytics

## 0.9.0 — Compliance, Protection & Operations

- Rights/release vault, reporting, takedown workflow, geographic controls, and age-assurance adapter
- Signed media URLs, buyer-specific watermarks, access monitoring, backups, restore, and diagnostics

## 1.0.0 — Production Release

- Full cross-browser/device testing
- Payment lifecycle and security validation
- Clean install, upgrade, rollback, backup, and restore tests on representative shared hosting
- Production documentation and screenshots from the actual running build

## Completion rule

A roadmap item is complete only when its implementation, migrations, tests, documentation, and running-build verification are committed. Planning text and database placeholders do not count as completed user-facing features.
