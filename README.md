# Nikki Wilder — The Velvet Vixxen

**Current completed runtime release: 0.7.1 — Ownership-Safe Installer Hotfix**

**Current development milestone: 0.8.0 — Marketing, Publishing & Analytics**

Velvet Vixxen is a full-stack PHP/MySQL creator platform using the approved neon-pink Nikki Wilder design. It combines a visual site composer, Canva-ready templates, music, photo galleries, video, member accounts, subscriptions, PPV commerce, commercial add-ons, licensing/updates, private messaging, content requests, and booking workflows.

## 0.7.1 baseline

0.7.1 preserves the complete 0.7.0 communication platform and fixes shared-hosting ownership mismatches where panel-created `0755` folders could not be written by PHP.

The installer can safely replace affected runtime directories with PHP-owned equivalents, preserve existing contents, create missing upload/storage/add-on paths, rerun real write tests, and retire `/install` without using `0777`.

Runtime package SHA-256:

```text
a5c311e2462b422246eb8282142e33c4157d91dab74bfdf029d42fb476a72d06
```

## 0.8.0 work

The working branch is:

```text
feature/0.8.0-marketing-publishing-analytics
```

Tracking issue: `#9`

Detailed plan: `docs/0.8.0_IMPLEMENTATION_PLAN.md`

Planned implementation includes:

- Blog posts, personal updates, announcements, categories, tags, revisions, previews, scheduling, expiration, SEO, and member/tier visibility
- Editorial calendar spanning posts, media, campaigns, banners, email, coupons, and Visual Composer scenes
- Campaign landing pages and timed promotional banners
- Newsletter lists, consent, segmentation, branded templates, scheduled campaigns, batching, unsubscribe, suppression, and delivery records
- Automated content, renewal, payment-failure, request, booking, and message-notification emails
- Link-in-bio page
- Referral and affiliate-attribution foundations
- Privacy-conscious traffic, conversion, subscription, PPV, media, booking, request, communication, and campaign analytics
- CSV reports and retention controls

The 0.8.0 executable build is not yet represented as complete merely because its specification and branch exist.

## Runtime-only installation ZIP

Customer-facing installation ZIPs contain only files required to install and operate the website. They intentionally exclude:

- `.git` and `.github`
- repository roadmaps, handoff documents, and GitHub Actions workflows
- `/docs` and `/tests`
- add-on SDK source
- screenshots and release reports
- test, demo, cleanup, and build scripts
- development-only SQL schema snapshots

Operational task runners required by the live site remain included.

## Locked installation requirements

- One-page browser installer
- No installation key
- Exact 12-character minimum owner/member passwords
- Safe `0755`/`0775` permissions; never `0777`
- Ownership-safe writable-directory repair
- Versioned database migrations
- Installation lock and `/install` deletion or secure retirement
- Organized folders rather than root-level file sprawl

## Repository truth boundary

Repository: `afterburn25/velvet_vixxen`

A clean runtime package and a GitHub branch are separate truth boundaries. Do not describe `main` or the 0.8.0 branch as a released executable milestone until source import, required tests, review, and release evidence are directly verified.

Never commit production credentials, customer/member records, private conversations, booking/request records, attachments, identity documents, payment secrets, raw license keys, signing private keys, runtime state, or private/paid media.
