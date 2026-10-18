# Project Status — Nikki Wilder / The Velvet Vixxen

## Current completed runtime release

**0.7.1 — Ownership-Safe Installer Hotfix**

0.7.1 supersedes 0.7.0 as the runtime baseline. It retains Messaging, Booking & Member Requests and fixes shared-hosting installations where panel-owned `0755` directories are readable by PHP but not writable.

The installer now repairs ownership mismatches for required runtime paths without using `0777`, preserves existing contents, creates missing upload/add-on/storage directories, and securely retires `/install` even when foreign ownership prevents ordinary deletion.

Runtime package SHA-256:

```text
a5c311e2462b422246eb8282142e33c4157d91dab74bfdf029d42fb476a72d06
```

## 0.8.0 development status

**0.8.0 — Marketing, Publishing & Analytics is in progress.**

- Working branch: `feature/0.8.0-marketing-publishing-analytics`
- Tracking issue: `#9`
- Implementation plan: `docs/0.8.0_IMPLEMENTATION_PLAN.md`

The architecture and acceptance requirements are now documented. The executable 0.8.0 implementation and tested runtime ZIP are not yet complete and must not be represented as released.

## Verified completed platform through 0.7.1

### Foundation and installer

- Approved responsive neon-pink design
- Hero/Footer Visual Composer and Canva-compatible workflow
- One-page browser installer
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe permission handling; no `0777`
- Ownership-safe replacement of foreign-owned writable runtime folders
- Versioned migrations, installation lock, boot verification, and `/install` cleanup/retirement
- Runtime-only customer installation ZIP policy

### Media, members, and commerce

- Music, photo galleries, video, and protected media
- Member registration, verification, recovery, device sessions, and dashboard
- Membership plans and unified entitlements
- Sandbox subscriptions, PPV galleries/videos, rentals, bundles, coupons, receipts, refunds, and signed webhooks

### Add-ons and licensing

- Add-on install/activate/deactivate/update/rollback/uninstall lifecycle
- Manifest, compatibility, permissions, dependencies, checksums, signatures, backups, migrations, Safe Mode, and tasks
- Commercial licensing, site activation, signed update feeds, release channels, and protected downloads

### Messaging, requests, and booking

- Private member-to-owner conversations
- Protected attachments
- Content requests with quotes and status workflow
- Public/member booking and appearance inquiries
- Member notifications, saved replies, blocks, owner queues, and communication settings

## 0.8.0 planned implementation

- Blog, personal updates, announcements, revisions, SEO metadata, scheduling, expiration, and tier visibility
- Unified editorial calendar for posts, media, campaigns, banners, coupons, and Visual Composer scenes
- Campaign landing pages and timed promotional banners
- Newsletter lists, consent, segmentation, templates, campaigns, batching, unsubscribe, suppression, and delivery logs
- Automated content, renewal, payment-failure, booking, request, and private-message notification emails
- Link-in-bio page
- Referral and affiliate attribution foundations
- Privacy-conscious first-party analytics, funnels, rollups, retention controls, and CSV reports

## Runtime-only ZIP policy

Customer installation ZIPs contain only files required to install and operate the live site. They exclude repository metadata, GitHub workflows, docs, tests, SDK source, roadmap/handoff files, screenshots, test reports, development schema snapshots, and build/test/cleanup scripts.

Operational runners required by the live site remain included.

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

The repository is the engineering, roadmap, test-evidence, decision, and session-handoff hub. A packaged runtime release and a repository branch are separate truth boundaries.

Never commit production credentials, customer/member records, identity documents, payment secrets, raw license keys, signing private keys, private messages/attachments, runtime state, or private/paid media.

## Current next actions

1. Implement the 0.8.0 migration and publishing/marketing services against the clean 0.7.1 source baseline.
2. Add public/admin routes, scheduled-task runner, email/consent controls, and analytics reporting.
3. Add source, HTTP, security, MySQL, and Playwright tests.
4. Build and verify a runtime-only 0.8.0 installation ZIP.
5. Record exact evidence and limitations before claiming release completion.
