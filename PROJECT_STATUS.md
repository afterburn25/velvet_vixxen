# Project Status — Nikki Wilder / The Velvet Vixxen

## Current completed runtime release

**0.8.0 — Marketing, Publishing & Analytics**

A clean runtime-only 0.8.0 installation package has been built and locally verified. It preserves all prior platform capabilities and adds publishing, newsletter, promotions, scheduled Visual Composer scenes, link-in-bio, referral attribution, and privacy-conscious first-party analytics.

Runtime package:

```text
Nikki-Wilder-Velvet-Vixxen-0.8.0.zip
SHA-256: ffe5126052ae6363e404a41992119efa52f03060afa9326e7c6523c2920e3993
```

## Completed in 0.8.0

### Publishing and editorial workflow

- Blog, personal update, and announcement posts
- Draft, scheduled, published, archived, and expiring states
- Public, registered-member, and subscriber visibility
- Categories, tags, SEO metadata, featured images, and featured-post placement
- Public Blog/Updates index and post routes
- Marketing administration with real post creation and editing

### Newsletter and consent

- Newsletter signup with single- or double-opt-in behavior
- Signed confirmation and unsubscribe links
- Subscriber tags and segmentation
- Campaign drafts, recipient queue creation, and delivery-state tracking
- Consent records, suppression behavior, and retention controls
- Safe unsubscribe remains available even when public newsletter signup is disabled

### Promotions and presentation

- Timed promotional banners
- Campaign landing pages
- Link-in-bio page with ordered links and click accounting
- Referral codes, click/order attribution, and attributed revenue
- Scheduled Hero and Footer Visual Composer scene snapshots
- Lock-aware, idempotent scene publication and cancellation
- Operational marketing task runner: `scripts/run_marketing.php`

### Analytics

- Same-origin first-party analytics endpoint
- Foreign-origin request rejection
- Privacy-conscious event storage and summaries
- Marketing, referral, content, and conversion reporting foundations
- Analytics dashboard and CSV exports
- Configurable retention for analytics and consent records
- No private-message bodies, protected-media contents, raw card data, or identity documents are collected as analytics payloads

## Preserved platform behavior

- Simplified one-page browser installer
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775` permission handling; never `0777`
- Ownership-safe writable-directory repair
- Versioned migrations, final configuration verification, installation lock, and `/install` cleanup or secure retirement
- Approved responsive neon-pink design, Hero/Footer Visual Composer, and Canva-compatible workflow
- Music, photo galleries, video, protected media, members, entitlements, subscriptions, PPV, rentals, bundles, coupons, receipts, and refunds
- Add-on lifecycle, commercial licensing, signed updates, messaging, content requests, and booking workflows

## Package evidence

- Runtime files: 203
- Runtime PHP files: 128
- Public JavaScript files: 7
- Versioned migrations: 12
- Declared application tables: 75
- Runtime ZIP integrity and SHA-256: passed
- PHP and JavaScript syntax validation: passed
- Marketing file-store suite: passed
- Marketing HTTP acceptance: passed
- Full-site HTTP smoke: passed
- Installer smoke: passed
- Runtime exclusion, clean-configuration, and security checks: passed

The final ZIP starts uninstalled and contains no database credentials, generated security keys, installation lock, sessions, logs, customer data, subscriber data, private messages, or private/paid media.

## Runtime-only ZIP policy

Customer installation ZIPs contain only files required to install and operate the live site. They exclude repository metadata, GitHub workflows, docs, tests, SDK source, roadmap/handoff files, screenshots, test reports, development schema snapshots, and build/test/cleanup tooling. Operational billing, add-on-task, and marketing runners remain included.

## Environmental limits

- Local PHP was available, but PDO MySQL and a MySQL/MariaDB server were not. Live DDL apply/verify/rollback is not claimed locally.
- Direct interactive browser navigation is managed in the packaging environment. Screenshots were rendered from real fetched application HTML and assets, but full Playwright acceptance remains a CI or representative-hosting gate.
- The bundled payment provider remains sandbox-only.

## Repository truth boundary

- Repository: `afterburn25/velvet_vixxen`
- Delivery/documentation branch: `feature/0.8.0-marketing-publishing-analytics`
- Tracking issue: `#9`
- The packaged runtime release and repository source-import state remain separate truth boundaries.
- Do not claim complete executable source import, MySQL CI, Playwright CI, merge to `main`, tag, or GitHub release without direct evidence.
- Never commit production credentials, customer/member/subscriber records, private communications or attachments, identity documents, payment secrets, raw license keys, signing private keys, runtime state, or private/paid media.

## Next actions

1. Import the complete clean 0.8.0 engineering source separately from the runtime-only ZIP.
2. Run PHP 8.2/8.3, disposable MySQL 8, HTTP, repository-safety, and interactive Playwright CI.
3. Correct any CI-only findings and record exact evidence.
4. Merge only after the required gates are green.
5. Begin **0.9.0 — Compliance, Privacy & Operational Hardening**.
