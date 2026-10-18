# Nikki Wilder — The Velvet Vixxen

**Current completed runtime release: 0.8.0 — Marketing, Publishing & Analytics**

Velvet Vixxen is a full-stack PHP/MySQL creator platform using the approved neon-pink Nikki Wilder design. It combines a visual site composer, Canva-ready templates, music, photo galleries, video, member accounts, subscriptions, PPV commerce, commercial add-ons, licensing and updates, private communications, content requests, booking, publishing, email marketing, promotions, referrals, and privacy-conscious analytics.

## 0.8.0 additions

- Blog, personal update, and announcement publishing
- Draft, scheduled, published, archived, and expiring editorial states
- Public, registered-member, and subscriber visibility
- Categories, tags, SEO metadata, featured images, and featured placement
- Newsletter signup, signed confirmation, unsubscribe, consent, suppression, tags, and audience segmentation
- Newsletter campaign queues and delivery-state tracking
- Timed promotional banners and campaign landing pages
- Scheduled Hero and Footer Visual Composer scenes
- Link-in-bio page and click tracking
- Referral-code and attributed-revenue foundations
- Same-origin first-party analytics and foreign-origin rejection
- Marketing and Analytics administration with CSV exports and retention controls
- Twelfth migration, bringing the declared schema to 75 tables

## Runtime package

```text
Nikki-Wilder-Velvet-Vixxen-0.8.0.zip
SHA-256: ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993
```

Package profile:

- 203 runtime files
- 128 PHP runtime files
- 7 public JavaScript files
- 12 versioned migrations
- 75 declared application tables

## Runtime-only installation ZIP

The customer-facing ZIP contains only files required to install and operate the website. It intentionally excludes:

- `.git` and `.github`
- repository roadmaps, handoff documents, and GitHub Actions workflows
- `/docs` and `/tests`
- add-on SDK source
- screenshots and test reports
- test, demo, cleanup, and build tooling
- development-only SQL schema snapshots

Operational scripts required by the installed site remain included:

```text
scripts/run_billing.php
scripts/run_addon_tasks.php
scripts/run_marketing.php
```

## Locked installation requirements

- One simplified responsive browser installer page
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775` permissions; never `0777`
- Ownership-safe writable-directory repair
- Versioned database migrations
- Final configuration verification
- Installation lock and `/install` deletion or secure retirement
- Organized folders rather than root-level file sprawl

## Local release evidence

- 128 runtime PHP files passed syntax validation
- 7 public JavaScript files passed syntax validation
- Migration manifest passed: 12 migrations / 75 tables
- Marketing file-store suite passed
- Marketing HTTP acceptance passed
- Full-site HTTP smoke passed
- Installer smoke passed
- Final extracted ZIP passed integrity, checksum, runtime exclusions, clean-configuration, and security checks

Live local MySQL execution is not claimed because PDO MySQL/MySQL/MariaDB was unavailable. Full interactive Playwright acceptance remains a CI or representative-hosting gate. The bundled payment provider remains sandbox-only.

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

Delivery branch:

```text
feature/0.8.0-marketing-publishing-analytics
```

Tracking issue: `#9`

A clean runtime package and a GitHub branch are separate truth boundaries. Do not describe `main` or the delivery branch as the complete executable source release until the clean engineering-source import, required CI, review, and merge are directly verified.

Never commit production credentials, customer/member/subscriber records, private communications, booking/request records, attachments, identity documents, payment secrets, raw license keys, signing private keys, runtime state, or private/paid media.

## Next milestone

**0.9.0 — Compliance, Privacy & Operational Hardening**
