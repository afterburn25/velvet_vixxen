# Velvet Vixxen Roadmap

Status is based on verified implementation and test evidence, not intention.

## Completed

### 0.1.0–0.2.2 — Foundation, Design, Composer, Installer

- PHP/MySQL foundation and organized folders
- Approved responsive design
- Hero/Footer Visual Composer and Canva-ready templates
- Single-page browser installer, no key, 12-character minimum, safe permissions, migrations, install lock, and `/install` cleanup

### 0.3.0 — Media Center

- Music, persistent audio, albums, and playlists
- Photo galleries and full-screen viewer
- Video library/player, trailers, captions, and Continue Watching
- Protected previews/originals and signed delivery

### 0.4.0 — Members & Entitlements

- Registration, verification, authentication, recovery, and device sessions
- Plans, subscriptions, My Library, Favorites, and playback state
- Unified public/member/subscription/PPV/rental/bundle/gift access resolver

### 0.5.0 — Subscriptions & PPV Commerce

- Store, cart, sandbox checkout, receipts, and billing history
- Monthly/annual plans, PPV photo/video, rentals, bundles, and coupons
- Signed webhooks, refunds, chargebacks, and entitlement fulfillment/revocation

### 0.6.0 — Add-on Framework

- Protected ZIP staging, manifests, compatibility, permissions, dependencies, conflicts, checksums, and signatures
- Install/activate/deactivate/update/rollback/uninstall/purge
- Backups, migrations, failure rollback, Safe Mode, routes, widgets, events, filters, services, settings, and tasks

### 0.6.1 — Commercial Licensing & Remote Updates

- Commercial license products, issuance, activation, site limits, domain binding, encrypted local keys, outage grace, signed feeds, release channels, protected downloads, review, backup, and rollback

### 0.7.0–0.7.3 — Communications & Installer Hardening

- Private member-to-owner conversations and protected attachments
- Content requests with budgets, quotes, statuses, and replies
- Public/member booking and appearance inquiries
- Notifications, saved replies, communication blocks, owner queues, and settings
- Runtime-only public installation ZIP
- Ownership-safe writable-directory repair
- Normalized installer email validation
- Simplified installer and hardened final configuration verification
- Eleven migrations / 64 declared tables through the 0.7 line

### 0.8.0 — Marketing, Publishing & Analytics

- Blog, personal update, and announcement publishing
- Draft, scheduled, published, archived, and expiring states
- Public, registered-member, and subscriber visibility
- Categories, tags, SEO metadata, featured images, and featured placement
- Newsletter single/double opt-in, confirmation, unsubscribe, consent, suppression, tags, segments, campaigns, and delivery-state tracking
- Timed promotional banners and campaign landing pages
- Scheduled Hero/Footer Visual Composer scenes with lock-aware, idempotent processing
- Link-in-bio page and click tracking
- Referral codes, order attribution, and attributed-revenue foundations
- Privacy-conscious same-origin first-party analytics and foreign-origin rejection
- Marketing/Analytics administration, CSV exports, and retention controls
- Operational marketing runner
- Twelfth migration / 75 declared tables
- Runtime-only package with 203 files, 128 PHP files, and 7 public JavaScript files

## Next

### 0.9.0 — Compliance, Privacy & Operational Hardening

- Performer and collaborator records
- Identity/age-verification status and encrypted document references
- Model releases, content ownership, permitted uses, shoot dates, photographers, and media linkage
- Complaint, copyright, report, takedown, response, and appeal workflows
- Configurable age-assurance provider adapters
- Geographic restriction controls
- Administrator two-factor authentication
- Privileged-action confirmation and expanded audit logging
- Backup scheduling and retention
- Database/file backup verification and restore drills
- Health dashboard for database, mail, payment webhooks, scheduled tasks, media processing, storage, backups, and add-ons
- Recovery mode and failed-upgrade/add-on diagnostics
- Rate limiting, suspicious-access detection, device/session alerts, and security notifications
- Accessibility review and keyboard alternatives for drag-only actions
- Privacy review, data export/deletion, consent retention, and documented retention policies

### 1.0.0 — Production Release

- Approved live payment-provider adapter and merchant sandbox certification
- Production email-delivery validation
- Representative shared-hosting install and upgrade validation
- Full PHP 8.2/8.3, MySQL/MariaDB, HTTP, Playwright, security, accessibility, and performance matrix
- Backup/restore and failed-upgrade drills
- Browser/device review and complete operating documentation
- Signed runtime package, checksum, changelog, test evidence, and running-build screenshots

## Not complete in 0.8.0

- Complete engineering-source import and full GitHub CI matrix
- Live payment certification and automatic tax service
- Production email-delivery account or deliverability certification
- Public add-on marketplace or multi-tenant licensing SaaS
- Websocket chat, voice/video calling, or paid-message workflows
- Automatic booking contracts, deposits, or quote payments
- Production two-factor authentication
- Performer/collaborator compliance vault
- Complaint/takedown case management
- Age-assurance integration and geographic enforcement
- Scheduled backup/restore validation and full operational health dashboard
