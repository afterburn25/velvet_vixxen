# Velvet Vixxen Roadmap

Status is based on verified implementation and test evidence, not intention.

## Documented completed package milestones

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

### 0.7.0–0.7.4 — Communications, Installer, and Visual Composer Hardening

- Private conversations and protected attachments
- Content requests, booking and appearance inquiries, notifications, saved replies, owner queues, and settings
- Runtime-only public installation ZIP
- Ownership-safe writable-directory repair
- Normalized installer email validation
- Simplified installer and hardened final configuration verification
- Direct Visual Composer layer drag/resize foundations and media-path repairs

### 0.8.0 — Marketing, Publishing & Analytics

Documented packaged scope:

- Blog, personal update, and announcement publishing
- Draft, scheduled, published, archived, and expiring states
- Audience visibility, categories, tags, SEO metadata, featured images, and featured placement
- Newsletter consent, confirmation, unsubscribe, suppression, tags, segments, campaigns, and delivery-state tracking
- Timed promotional banners and campaign landing pages
- Scheduled Hero/Footer scene snapshots
- Link-in-bio and referral attribution
- Privacy-conscious same-origin first-party analytics
- Analytics/marketing administration, CSV exports, retention controls, and operational runner
- Twelve migrations / 75 declared tables

The complete executable 0.8.0 engineering-source import and full GitHub CI matrix remain unverified.

## Active continuation — incomplete

### 0.8.1 — Site UX, Hero Library, Booking Builder, Admin IA & Merchandise

Authoritative scope: `INCOMPLETE_WORK.md`  
Tracking issue: `#13`

- Recover/import and test the real executable baseline
- Complete Visual Composer off-canvas placement/clipping, extreme resize, movable live text/buttons, device-specific layouts, background pan, and broken-media repair
- Integrate at least 30 standalone people-free luxury Hero backgrounds
- Admin-configurable Booking Form Builder with required/optional text, textarea, email, phone, dropdown, radio, and checkbox fields
- Client/server validation and live `(337) 450-8598` phone formatting
- Sticky public navigation, Contact removal, dedicated About Me page/editor, and centered auth-page layouts
- Persistent collapsible admin categories and active-page state
- Real merchandise catalog/cart/checkout while subscriptions use membership checkout and PPV uses direct Buy Now/rental flows
- Clear Canva manual export/import workflow
- Full PHP/MySQL/HTTP/Playwright/security/runtime-package evidence

Do not begin 0.9.0 until 0.8.1 is verified or explicitly deferred.

## Later milestones

### 0.9.0 — Compliance, Privacy & Operational Hardening

- Performer and collaborator records
- Identity/age-verification status and encrypted document references
- Model releases, ownership, permitted uses, shoot dates, photographers, and media linkage
- Complaint, copyright, report, takedown, response, and appeal workflows
- Age-assurance provider adapters and geographic controls
- Administrator two-factor authentication
- Privileged-action confirmation and expanded audit logging
- Backup scheduling, retention, restore validation, and recovery mode
- Health dashboard for database, mail, webhooks, scheduled tasks, media, storage, backups, and add-ons
- Rate limiting, suspicious-access detection, device/session alerts, and security notifications
- Accessibility and privacy review, data export/deletion, and retention policies

### 1.0.0 — Production Release

- Approved live payment-provider adapter and merchant sandbox certification
- Production email-delivery validation
- Representative shared-hosting install and upgrade validation
- Full PHP 8.2/8.3, MySQL/MariaDB, HTTP, Playwright, security, accessibility, and performance matrix
- Backup/restore and failed-upgrade drills
- Browser/device review and complete operating documentation
- Signed runtime package, checksum, changelog, test evidence, and running-build screenshots

## Not complete at this handoff

- Complete executable engineering-source import for the latest package
- Full GitHub PHP/MySQL/Playwright CI matrix
- 0.8.1 user-facing continuation
- Live payment certification and automatic tax service
- Production email-delivery certification
- Public add-on marketplace or multi-tenant licensing SaaS
- Websocket chat, voice/video calling, or paid-message workflows
- Automatic booking contracts, deposits, or quote payments
- Production two-factor authentication
- Compliance vault, complaint/takedown case management, age assurance, geographic enforcement, backup/restore validation, and full operational health dashboard
