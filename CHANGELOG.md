# Changelog

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
- Online license activation, customer downloads, remote update feeds, and marketplace sales remain future milestones
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
