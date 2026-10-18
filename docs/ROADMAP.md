# Velvet Vixxen Development Roadmap

## Complete milestones

### 0.2.2 — One-Page Browser Installer

- One responsive installer page
- No installation key
- 12-character owner-password minimum
- Safe permissions, migrations, lock, boot verification, `/install` cleanup, and organized folders

### 0.3.0 — Media Center

- Persistent music player, albums/playlists, photo galleries/viewer, video library/player, protected media, scheduling, and administration

### 0.4.0 — Members & Entitlements

- Registration, verification, login/recovery, device sessions, dashboard, My Library, plans, synchronized media state, and unified access resolution

### 0.5.0 — Subscriptions & PPV Commerce

- Sandbox payment-provider contract
- Store, cart, checkout, subscriptions, PPV photo/video, rentals, bundles, coupons, receipts, billing, refunds, webhooks, and administration

### 0.6.0 — Add-on Framework

- ZIP package inspection and validated manifest schema
- Core/PHP compatibility, permissions, dependencies, conflicts, checksums, and optional Ed25519 signatures
- Safe package-path/content rules and explicit unsigned-package acknowledgement
- Install, activate, deactivate, update, rollback, preserve-data uninstall, and purge uninstall
- Add-on-owned migrations and migration registry
- Admin pages/navigation, public routes, widgets, events, filters, services, settings, and scheduled tasks
- Pre-change backups, failed-change restoration, safe mode, boot-failure quarantine, and lifecycle logs
- Free, trial, and signed offline commercial-license foundations
- Starter source/ZIP, developer documentation, and lifecycle/security tests

## 0.6.1 — Commercial Licensing & Remote Updates

- Separate license/update service API
- Online activation/deactivation and installation/domain limits
- Trials, grace periods, renewal/update entitlement, and transfer/revocation workflows
- Customer download records and release history
- Signed remote update manifests/packages
- Stable, beta, and development channels
- Update caching and outage-tolerant behavior that does not break installed sites
- Security, privacy, lifecycle, and rollback tests

## 0.7.0 — Add-on Store

- Integrated catalog and product pages
- Customer purchase/download library
- One-time, recurring, annual, lifetime, multi-site, and agency licenses
- One-click installation after purchase
- Bundles, coupons, reviews, release notes, support links, and vendor/revenue reports

## 0.8.0 — Messaging, Booking, Marketing & Analytics

- Member messaging, content requests, blocking/reporting
- Booking and appearance inquiries
- Newsletter campaigns, referrals, promotions, landing pages, and conversion analytics

## 0.9.0 — Compliance, Protection & Operations

- Rights/release vault, ownership records, reporting/takedowns, geographic controls, age-assurance adapter
- Buyer-specific watermarks, suspicious-access monitoring, backups, restore, diagnostics, and upgrade rollback

## 1.0.0 — Production Release

- Cross-browser/device and accessibility testing
- Approved live payment-provider lifecycle validation
- Shared-host clean install/upgrade/rollback/backup/restore tests
- Commercial licensing/update-service production validation
- Production documentation and actual-build screenshots

## Completion rule

A roadmap item is complete only when implementation, migrations, tests, documentation, and evidence exist. A packaged milestone is not described as merged to `main` until its complete source is pushed, CI-tested, reviewed, and actually merged.
