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

### 0.7.0 — Messaging, Booking & Member Requests

- Private member-to-owner conversations
- Read/unread state, priorities, close/reopen/archive, attachments, and history
- Content requests with categories, custom questions, budgets, due dates, quotes, statuses, acceptance/cancellation, and owner notes
- Public/member booking inquiries with guest status links, dates, locations, quotes, confirmation/cancellation, and history
- Protected request attachments and authorization-aware delivery
- Saved replies, notifications, unread badges, contact blocks, moderation reports, and internal notes
- Admin queues, filters, category/reply management, and CSV exports
- Eleventh migration / 66 declared tables

## Next

### 0.8.0 — Marketing, Publishing & Analytics

- Blog/updates and editorial content calendar
- Newsletter segmentation and branded transactional/campaign templates
- New-content, renewal, payment-failure, request, and booking notifications
- Promotional banners, campaign pages, timed offers, and scheduled Visual Composer scenes
- Referral and affiliate foundations
- Link-in-bio page
- Privacy-conscious traffic, conversion, media, subscription, PPV, coupon, booking, request, and communication reporting
- CSV exports, consent records, and retention controls

### 0.9.0 — Compliance, Privacy & Operational Hardening

- Performer/collaborator release vault and content linkage
- Complaint, copyright, report, and takedown workflows
- Age-assurance adapters and geographic controls
- Two-factor authentication
- Enhanced audit logs and privileged-action confirmation
- Backup scheduling, restore validation, health dashboard, recovery mode, rate limiting, suspicious-access alerts, accessibility, and privacy review

### 1.0.0 — Production Release

- Approved live payment-provider adapter and merchant sandbox certification
- Shared-hosting installation/upgrade validation
- Full PHP/MySQL/MariaDB/HTTP/Playwright/security matrix
- Backup/restore and failed-upgrade drills
- Accessibility/browser review and complete operating documentation
- Signed package, checksum, changelog, test evidence, and running-build screenshots

## Not complete in 0.7.0

- Public add-on marketplace or multi-tenant licensing SaaS
- Live payment certification and automatic taxes
- Websocket chat, voice/video calling, paid-message workflows
- Automatic booking contracts, deposits, or quote payments
- Production two-factor authentication and compliance vault
