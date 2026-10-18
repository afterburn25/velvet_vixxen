# Changelog

## 0.7.0 — Messaging, Booking & Member Requests

### Added

- Private member-to-owner message threads with protected attachments
- Read/unread counts, statuses, priorities, internal notes, and communication activity history
- Saved owner reply templates
- Content-request intake with category, details, budget range, requested date, quote, status, and owner/member replies
- Public and signed-in booking/appearance inquiry workflow
- Booking dates, city, venue/platform, budget, protected reference files, owner statuses, and internal notes
- In-app member notifications and mail-outbox integration
- Communication blocking and site-level communication settings
- Member dashboard communication summaries and request history
- Owner message, content-request, booking-request, and communication-settings administration
- Eleventh migration adding nine communication tables for a total of 64 declared tables
- Communication unit, feature, HTTP, security, and responsive interface coverage

### Packaging

- Public install ZIP is runtime-only and excludes GitHub files, docs, tests, SDK source, screenshots, reports, and development-only scripts
- Operational billing and add-on task runners remain included
- Runtime package contains 185 files and 112 PHP runtime files

### Testing

- Source/security suite passed
- Members, Commerce, Add-ons, Licensing/Updates, and Communications HTTP acceptance passed
- Extracted runtime-only ZIP passed integrity, SHA-256, syntax, clean-config, exclusion, and external acceptance verification

### Limitations

- Live local MySQL DDL execution remains CI-owned because PDO MySQL/MySQL is unavailable locally
- Direct interactive Chromium remains blocked by managed policy; Playwright remains CI-owned
- Payment processing remains sandbox-only

## 0.6.1 — Commercial Add-on Licensing & Remote Updates

- Added commercial license products, issuance, activation, site limits, domain binding, encrypted local keys, offline grace, Ed25519-signed API responses, stable/beta/development channels, protected releases, short-lived download tokens, remote update review, and rollback
- Added tenth migration / 55 declared tables
- Kept the licensing hub self-hosted and the payment gateway sandbox-only

## 0.6.0 — Add-on Framework

### Added

- Add-on ZIP upload and inspect-before-install workflow
- Versioned manifest schema, semantic versions, compatibility ranges, permissions, dependencies, conflicts, license metadata, and update channels
- SHA-256 checksums and optional Ed25519 package signatures
- Explicit acknowledgement for unsigned or valid-but-untrusted packages
- Path, duplicate-entry, symlink, file-count, size, private-key, environment-file, and blocked-content checks
- Install, activate, deactivate, update, rollback, preserve-data uninstall, and purge uninstall
- Add-on-owned migrations and migration registry
- Pre-update/pre-uninstall backups and failed-change restoration
- Boot-failure quarantine and global safe mode
- Admin pages/navigation, public routes, widgets, events, filters, services, namespaced settings, and scheduled tasks
- Core member/commerce events and `commerce.quote` filter
- Homepage widget slots and protected add-on route dispatcher
- Free, trial, and signed offline commercial-license foundations
- Add-on Manager UI, lifecycle logs, diagnostics, task runner, starter source/ZIP, developer guide, and schema guide
- Ninth migration, bringing the declared schema to 48 tables

### Security and recovery

- Invalid signatures and blocking package findings cannot be bypassed
- Extension APIs enforce declared permissions
- Failed boot removes registrations before quarantine
- Safe mode bypasses optional add-ons
- Failed updates restore prior files and roll back applicable migrations
- Private signing keys and environment files are blocked from distributable packages

### Limitations

- PHP add-ons are executable code and are not fully sandboxed on shared hosting
- The payment provider remains sandbox-only

## 0.5.0 — Subscriptions & PPV Commerce

- Added Store, cart, checkout, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, billing, refunds, signed webhooks, and commerce administration
- Added eighth migration / 44 declared tables
- Kept the bundled gateway sandbox-only

## 0.4.0 — Members & Entitlements

- Added registration, verification, login/recovery, device sessions, dashboard, My Library, plans, subscriptions, direct entitlements, unified access resolution, and synchronized media state
- Added seventh migration / 38 declared tables

## 0.3.0 — Media Center

- Added persistent music player, albums/playlists, photo galleries/lightbox, video library/player, protected media, scheduling, and media administration

## 0.2.2 — One-Page Browser Installer

- Replaced the wizard with one responsive installer page while preserving no install key, 12-character minimum, safe permissions, migrations, rollback, lock, and cleanup

## 0.2.0–0.2.1 — Design, Composer, Canva & Installer Foundation

- Added the approved homepage, Hero/Footer composer, Canva templates/import, owner login, age gate, browser installer, organized folders, and migration foundation
