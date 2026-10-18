# Changelog

## 0.7.3 — Installer Verification & Simplified Setup Hotfix

### Fixed

- Replaced fixed-path final configuration loading with cache-safe verification of the exact activated file bytes.
- Invalidated PHP stat and OPcache entries after atomic configuration replacement.
- Added a unique one-time configuration verification path to avoid stale shared-hosting OPcache/realpath state.
- Added exact checks for installed state, release version, installation ID, base URL, generated security keys, database reconnection, seeded site settings, and install lock.
- Replaced the generic final-verification failure with component-specific diagnostics.

### Installer interface

- Reduced the visible one-page installer to Database, Owner and Website, and Install.
- Collapsed technical server and folder details.
- Shows only warnings and failures instead of every successful directory probe.
- Defaults support and sender addresses to the owner email.
- Defaults outgoing email to Configure Later.
- Keeps currency, time zone, mail, and related advanced settings configurable after installation.

### Verification

- Passed 113 runtime PHP syntax checks and 6 public JavaScript syntax checks.
- Passed simplified-installer HTTP rendering and AJAX requirement recheck.
- Passed final configuration verification with CLI OPcache enabled and timestamp validation disabled.
- Passed verification after replacing the parent `/config` directory.
- Passed shared-hosting ownership mismatch simulation with 75 directories, 0 failures, and installer ready.
- Runtime-only ZIP: 186 files.
- SHA-256: `202cb028ff7b61918d718a69604fa0a82db51ada0a0152379af8ba3b401a9eb0`.

## 0.7.2 — Installer Email Validation Hotfix

- Normalized pasted/autofilled email addresses before validation.
- Stopped validating hidden From/SMTP fields when outgoing email is configured later.
- Accepted `red.pixie93@gmail.com` as owner, support, and sender email.

## 0.7.1 — Shared-Hosting Ownership Repair

- Added automatic ownership-safe replacement of panel-owned runtime folders when PHP can write in the site root but cannot write inside extracted folders.
- Added missing upload and add-on runtime directories.
- Removed development `/docs` and `/tests` checks from the customer installer.
- Added same-parent retirement fallback for a foreign-owned `/install` directory.

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
- Eleventh migration adding communication tables for a total of 64 declared tables

### Packaging

- Public install ZIP is runtime-only and excludes GitHub files, docs, tests, SDK source, screenshots, reports, and development-only scripts
- Operational billing and add-on task runners remain included

### Limitations

- Live local MySQL DDL execution remains CI-owned because PDO MySQL/MySQL is unavailable locally
- Direct interactive Chromium remains blocked by managed policy; Playwright remains CI-owned
- Payment processing remains sandbox-only

## 0.6.1 — Commercial Add-on Licensing & Remote Updates

- Added commercial license products, issuance, activation, site limits, domain binding, encrypted local keys, offline grace, Ed25519-signed API responses, release channels, protected downloads, remote-update review, and rollback
- Added tenth migration / 55 declared tables

## 0.6.0 — Add-on Framework

- Added validated add-on ZIP inspection and lifecycle management
- Added manifests, compatibility, permissions, dependencies, conflicts, checksums, signatures, migrations, backups, rollback, Safe Mode, routes, widgets, events, filters, services, settings, and tasks
- Added ninth migration / 48 declared tables

## 0.5.0 — Subscriptions & PPV Commerce

- Added Store, cart, checkout, subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, billing, refunds, signed webhooks, and commerce administration
- Added eighth migration / 44 declared tables

## 0.4.0 — Members & Entitlements

- Added registration, verification, authentication/recovery, device sessions, dashboard, My Library, plans, subscriptions, direct entitlements, unified access resolution, and synchronized media state
- Added seventh migration / 38 declared tables

## 0.3.0 — Media Center

- Added persistent music player, albums/playlists, photo galleries/lightbox, video library/player, protected media, scheduling, and media administration

## 0.2.2 — One-Page Browser Installer

- Replaced the wizard with one responsive installer page while preserving no install key, 12-character minimum, safe permissions, migrations, rollback, lock, and cleanup

## 0.2.0–0.2.1 — Design, Composer, Canva & Installer Foundation

- Added the approved homepage, Hero/Footer composer, Canva templates/import, owner login, age gate, browser installer, organized folders, and migration foundation
